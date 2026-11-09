<?php

namespace App\Services;

use App\Events\LeadEmailReceived;
use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

/**
 * Mailboxes on a brand's domain (for example on cPanel webmail): emails go out over SMTP, and every
 * minute each inbox is checked over IMAP for replies, which are filed on the lead they came from.
 */
class MailboxService
{
    /** At most this many new emails are read per mailbox per check, so one run stays short on shared hosting */
    private const BATCH = 50;

    private const TIMEOUT = 20;

    public function __construct(private LiveSignal $live) {}

    /**
     * Logs in to both servers, so a typo is caught before anyone relies on the mailbox.
     *
     * @return string|null why it didn't work, or null when both logins succeeded
     */
    public function problem(Mailbox $mailbox): ?string
    {
        try {
            $this->mailer($mailbox)->getSymfonyTransport()->start();
        } catch (Throwable $e) {
            return "Sending failed: couldn't log in to {$mailbox->host} on port {$mailbox->smtp_port}. Check the password and the SMTP port. (".Str::limit($e->getMessage(), 140).')';
        }
        try {
            $this->imap($mailbox)->connect()->disconnect();
        } catch (Throwable $e) {
            return "Receiving failed: couldn't log in to {$mailbox->host} on port {$mailbox->imap_port}. Check the IMAP port. (".Str::limit($e->getMessage(), 140).')';
        }

        return null;
    }

    /** From now on: mail already in the inbox is left alone, only new replies are collected */
    public function startFromNow(Mailbox $mailbox): void
    {
        $client = $this->imap($mailbox)->connect();
        $status = $client->getFolder('INBOX')->examine();
        $client->disconnect();

        $mailbox->forceFill([
            'uid_validity' => $status['uidvalidity'] ?? null,
            'last_uid' => max(0, (int) ($status['uidnext'] ?? 1) - 1),
            'checked_at' => now(),
            'error' => null,
        ])->save();
    }

    /** Sends from the mailbox, and files it on the lead when there is one */
    public function send(Mailbox $mailbox, string $to, ?string $toName, ?Lead $lead, ?User $sender, string $subject, string $body, ?string $inReplyTo = null): ?LeadEmail
    {
        $brand = $mailbox->brand;
        // Header injection: a subject is one line, whatever was pasted into it
        $subject = trim(preg_replace('/[\r\n\t]+/', ' ', $subject));
        $from = $mailbox->from_name ?: ($mailbox->user ? "{$mailbox->user->name} · {$brand->merchantName()}" : $brand->merchantName());
        // Set here rather than read back after sending: what the mail server reports is its own queue ID
        // (e.g. "1abc-23XY" on cPanel), not the Message-ID the client replies to
        $messageId = bin2hex(random_bytes(16)).'@'.Str::after($mailbox->address, '@');

        $sent = $this->mailer($mailbox)->raw($body, function ($message) use ($to, $toName, $mailbox, $from, $subject, $inReplyTo, $messageId) {
            $message->to($to, $toName)->from($mailbox->address, $from)->subject($subject);
            $headers = $message->getSymfonyMessage()->getHeaders();
            $headers->addIdHeader('Message-ID', $messageId);
            if ($inReplyTo) {
                $headers->addIdHeader('In-Reply-To', $inReplyTo);
                $headers->addIdHeader('References', $inReplyTo);
            }
        });

        // So it also shows in webmail's Sent folder, like any other email from this address
        if ($sent) {
            rescue(fn () => $this->copyToSent($mailbox, $sent->toString()), report: false);
        }

        if (! $lead) {
            return null;
        }

        $email = LeadEmail::create([
            'lead_id' => $lead->id,
            'brand_id' => $brand->id,
            'user_id' => $sender?->id,
            'mailbox_id' => $mailbox->id,
            'direction' => 'out',
            'from_email' => $mailbox->address,
            'from_name' => $from,
            'to_email' => $to,
            'subject' => $subject,
            'body' => $body,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'sent_at' => now(),
            'created_at' => now(),
        ]);
        $lead->activities()->create(['actor_id' => $sender?->id, 'type' => 'email_out', 'data' => ['subject' => $subject]]);
        $lead->recordEmail('out');

        return $email;
    }

    /** @return int how many new emails were filed on leads */
    public function fetch(Mailbox $mailbox): int
    {
        $client = $this->imap($mailbox)->connect();
        try {
            $inbox = $client->getFolder('INBOX');
            $status = $inbox->examine();
            $validity = (int) ($status['uidvalidity'] ?? 0);
            // The server renumbered the mailbox: start again from its current end, rather than re-reading everything
            if ($mailbox->uid_validity && $validity && $validity !== (int) $mailbox->uid_validity) {
                $mailbox->forceFill(['uid_validity' => $validity, 'last_uid' => max(0, (int) ($status['uidnext'] ?? 1) - 1)])->save();

                return 0;
            }

            $last = (int) $mailbox->last_uid;
            // Only what's new is asked for. "n:*" always matches the newest message, even when nothing is new,
            // so UIDs are checked again below.
            $messages = $inbox->query()->where('UID', ($last + 1).':*')->leaveUnread()->setFetchOrder('asc')->limit(self::BATCH)->get();

            $filed = 0;
            foreach ($messages as $message) {
                $uid = (int) $message->getUid();
                if ($uid <= $last) {
                    continue;
                }
                try {
                    $filed += $this->file($mailbox, $message) ? 1 : 0;
                } catch (Throwable $e) {
                    // One email the app can't file never holds up every reply after it: it's reported and
                    // stays in webmail, and the mailbox moves on
                    report($e);
                }
                $last = max($last, $uid);
            }

            $mailbox->forceFill(['last_uid' => $last, 'uid_validity' => $validity ?: $mailbox->uid_validity, 'checked_at' => now(), 'error' => null])->save();

            return $filed;
        } finally {
            rescue(fn () => $client->disconnect(), report: false);
        }
    }

    /**
     * Files an incoming email on the brand's lead with that address, whichever of the brand's mailboxes it
     * reached. Mail from anyone else stays in webmail only.
     */
    private function file(Mailbox $mailbox, $message): bool
    {
        $from = $message->getFrom()->first();
        $address = strtolower(trim((string) ($from?->mail ?? '')));
        // Mail between the team's own addresses is never a client's
        if ($address === '' || Mailbox::where('address', $address)->exists()) {
            return false;
        }

        $lead = Lead::where('brand_id', $mailbox->brand_id)->where('email', $address)->latest('id')->first();
        if (! $lead) {
            return false;
        }

        // A client who wrote to two of the team's addresses is filed once
        $messageId = trim((string) $message->getMessageId(), '<> ');
        if ($messageId !== '' && LeadEmail::where('brand_id', $mailbox->brand_id)->where('message_id', $messageId)->exists()) {
            return false;
        }

        $body = self::utf8($message->hasTextBody() ? $message->getTextBody() : trim(html_entity_decode(strip_tags((string) $message->getHTMLBody()))));
        $date = rescue(fn () => $message->getDate()->toDate(), null, false);

        LeadEmail::create([
            'lead_id' => $lead->id,
            'brand_id' => $mailbox->brand_id,
            'mailbox_id' => $mailbox->id,
            'direction' => 'in',
            'from_email' => $address,
            'from_name' => Str::limit(self::utf8((string) ($from?->personal ?? '')), 180, '') ?: null,
            'to_email' => $mailbox->address,
            'subject' => Str::limit(trim(self::utf8((string) $message->getSubject())) ?: '(no subject)', 250, ''),
            // Plain text only: whatever HTML a sender puts in an email is never run in the app
            'body' => Str::limit(trim($body), 60000, ''),
            'message_id' => $messageId ?: null,
            'in_reply_to' => trim((string) $message->getInReplyTo(), '<> ') ?: null,
            'sent_at' => $date ?? now(),
            'created_at' => now(),
        ]);
        $lead->activities()->create(['type' => 'email_in', 'data' => ['subject' => Str::limit(self::utf8((string) $message->getSubject()), 180)]]);
        $lead->recordEmail('in');
        $this->live->leads($lead->brand_id, $lead->assignee_id);
        // The lead's rep hears about it, whichever address the client wrote to
        if ($lead->assignee_id) {
            rescue(fn () => event(new LeadEmailReceived($lead, (int) $lead->assignee_id)), report: false);
        }

        return true;
    }

    /**
     * Text as valid UTF-8. Mail that says one charset and is written in another (old mail apps, Outlook's
     * Windows-1252) would otherwise be refused by the database.
     */
    public static function utf8(string $text): string
    {
        $text = str_replace(chr(0), '', $text);

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    private function copyToSent(Mailbox $mailbox, string $raw): void
    {
        $client = $this->imap($mailbox)->connect();
        try {
            $sent = collect($client->getFolders(false))->first(fn ($folder) => preg_match('/(^|[.\/])sent( items| mail)?$/i', $folder->path));
            $sent?->appendMessage($raw, ['\\Seen']);
        } finally {
            rescue(fn () => $client->disconnect(), report: false);
        }
    }

    private function mailer(Mailbox $mailbox): Mailer
    {
        $port = (int) ($mailbox->smtp_port ?: 465);

        return Mail::build([
            'transport' => 'smtp',
            // 465 talks TLS from the start; 587 and 25 upgrade with STARTTLS
            'scheme' => $port === 465 ? 'smtps' : 'smtp',
            'host' => $mailbox->host,
            'port' => $port,
            'username' => $mailbox->username ?: $mailbox->address,
            'password' => $mailbox->password,
            'timeout' => self::TIMEOUT,
            'verify_peer' => config('ppc.mail_verify_certificates'),
            // Say hello as the brand's domain, not "[127.0.0.1]": spam filters read it in the headers
            'local_domain' => Str::after($mailbox->address, '@'),
        ]);
    }

    private function imap(Mailbox $mailbox): Client
    {
        $port = (int) ($mailbox->imap_port ?: 993);

        return (new ClientManager([
            'options' => [
                // Search and fetch by UID, and read without marking anything as read in webmail
                'sequence' => IMAP::ST_UID,
                'fetch' => IMAP::FT_PEEK,
                'soft_fail' => true,
            ],
        ]))->make([
            'host' => $mailbox->host,
            'port' => $port,
            'encryption' => $port === 993 ? 'ssl' : 'starttls',
            'validate_cert' => config('ppc.mail_verify_certificates'),
            'username' => $mailbox->username ?: $mailbox->address,
            'password' => $mailbox->password,
            'protocol' => 'imap',
            'timeout' => self::TIMEOUT,
        ]);
    }
}
