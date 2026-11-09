<?php

namespace App\Services;

use App\Events\LeadEmailReceived;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\User;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

/**
 * A brand's shared mailbox (for example on cPanel webmail): emails go out over SMTP, and every
 * minute the inbox is checked over IMAP for replies, which are filed on the lead they came from.
 */
class BrandMailbox
{
    /** At most this many new emails are read per check, so one run stays short on shared hosting */
    private const BATCH = 50;

    private const TIMEOUT = 20;

    public function __construct(private LiveSignal $live) {}

    public function configured(?Brand $brand): bool
    {
        return (bool) ($brand?->mail_address && $brand->mail_host && $brand->mail_password);
    }

    /**
     * Logs in to both servers, so a typo is caught before anyone relies on the mailbox.
     *
     * @return string|null why it didn't work, or null when both logins succeeded
     */
    public function problem(Brand $brand): ?string
    {
        try {
            $this->mailer($brand)->getSymfonyTransport()->start();
        } catch (Throwable $e) {
            return "Sending failed: couldn't log in to {$brand->mail_host} on port {$brand->mail_smtp_port}. Check the password and the SMTP port. (".Str::limit($e->getMessage(), 140).')';
        }
        try {
            $client = $this->imap($brand)->connect();
            $client->disconnect();
        } catch (Throwable $e) {
            return "Receiving failed: couldn't log in to {$brand->mail_host} on port {$brand->mail_imap_port}. Check the IMAP port. (".Str::limit($e->getMessage(), 140).')';
        }

        return null;
    }

    /** From now on: mail already in the inbox is left alone, only new replies are collected */
    public function startFromNow(Brand $brand): void
    {
        $client = $this->imap($brand)->connect();
        $status = $client->getFolder('INBOX')->examine();
        $client->disconnect();

        $brand->forceFill([
            'mail_uid_validity' => $status['uidvalidity'] ?? null,
            'mail_last_uid' => max(0, (int) ($status['uidnext'] ?? 1) - 1),
            'mail_checked_at' => now(),
            'mail_error' => null,
        ])->save();
    }

    /** Sends from the brand's address, and files it on the lead when there is one */
    public function send(Brand $brand, string $to, ?string $toName, ?Lead $lead, ?User $sender, string $subject, string $body, ?string $inReplyTo = null): ?LeadEmail
    {
        // Header injection: a subject is one line, whatever was pasted into it
        $subject = trim(preg_replace('/[\r\n\t]+/', ' ', $subject));
        $from = $brand->mail_from_name ?: $brand->merchantName();
        // Set here rather than read back after sending: what the mail server reports is its own queue ID
        // (e.g. "1abc-23XY" on cPanel), not the Message-ID the client replies to
        $messageId = bin2hex(random_bytes(16)).'@'.Str::after((string) $brand->mail_address, '@');

        $sent = $this->mailer($brand)->raw($body, function ($message) use ($to, $toName, $brand, $from, $subject, $inReplyTo, $messageId) {
            $message->to($to, $toName)->from($brand->mail_address, $from)->subject($subject);
            $headers = $message->getSymfonyMessage()->getHeaders();
            $headers->addIdHeader('Message-ID', $messageId);
            if ($inReplyTo) {
                $headers->addIdHeader('In-Reply-To', $inReplyTo);
                $headers->addIdHeader('References', $inReplyTo);
            }
        });

        // So it also shows in webmail's Sent folder, like any other email the brand sends
        if ($sent) {
            rescue(fn () => $this->copyToSent($brand, $sent->toString()), report: false);
        }

        if (! $lead) {
            return null;
        }

        $email = LeadEmail::create([
            'lead_id' => $lead->id,
            'brand_id' => $brand->id,
            'user_id' => $sender?->id,
            'direction' => 'out',
            'from_email' => $brand->mail_address,
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
        $lead->update(['last_activity_at' => now()]);

        return $email;
    }

    /** @return int how many new emails were filed on leads */
    public function fetch(Brand $brand): int
    {
        $client = $this->imap($brand)->connect();
        try {
            $inbox = $client->getFolder('INBOX');
            $status = $inbox->examine();
            $validity = (int) ($status['uidvalidity'] ?? 0);
            // The server renumbered the mailbox: start again from its current end, rather than re-reading everything
            if ($brand->mail_uid_validity && $validity && $validity !== (int) $brand->mail_uid_validity) {
                $brand->forceFill(['mail_uid_validity' => $validity, 'mail_last_uid' => max(0, (int) ($status['uidnext'] ?? 1) - 1)])->save();

                return 0;
            }

            $last = (int) $brand->mail_last_uid;
            // "n:*" always matches the newest message, even when nothing is new, so UIDs are checked again below
            $messages = $inbox->query()->where('UID', ($last + 1).':*')->leaveUnread()->setFetchOrder('asc')->limit(self::BATCH)->get();

            $filed = 0;
            foreach ($messages as $message) {
                $uid = (int) $message->getUid();
                if ($uid <= $last) {
                    continue;
                }
                $filed += $this->file($brand, $message) ? 1 : 0;
                $last = max($last, $uid);
            }

            $brand->forceFill(['mail_last_uid' => $last, 'mail_uid_validity' => $validity ?: $brand->mail_uid_validity, 'mail_checked_at' => now(), 'mail_error' => null])->save();

            return $filed;
        } finally {
            rescue(fn () => $client->disconnect(), report: false);
        }
    }

    /** Files an incoming email on the brand's lead with that address. Mail from anyone else stays in webmail only. */
    private function file(Brand $brand, $message): bool
    {
        $from = $message->getFrom()->first();
        $address = strtolower(trim((string) ($from?->mail ?? '')));
        if ($address === '' || $address === strtolower((string) $brand->mail_address)) {
            return false;
        }

        $lead = Lead::where('brand_id', $brand->id)->where('email', $address)->latest('id')->first();
        if (! $lead) {
            return false;
        }

        $messageId = trim((string) $message->getMessageId(), '<> ');
        if ($messageId !== '' && LeadEmail::where('brand_id', $brand->id)->where('message_id', $messageId)->exists()) {
            return false;
        }

        $body = $message->hasTextBody() ? $message->getTextBody() : trim(html_entity_decode(strip_tags((string) $message->getHTMLBody())));
        $date = rescue(fn () => $message->getDate()->toDate(), null, false);

        LeadEmail::create([
            'lead_id' => $lead->id,
            'brand_id' => $brand->id,
            'direction' => 'in',
            'from_email' => $address,
            'from_name' => Str::limit((string) ($from?->personal ?? ''), 180, '') ?: null,
            'to_email' => $brand->mail_address,
            'subject' => Str::limit(trim((string) $message->getSubject()) ?: '(no subject)', 250, ''),
            // Plain text only: whatever HTML a sender puts in an email is never run in the app
            'body' => Str::limit(trim($body), 60000, ''),
            'message_id' => $messageId ?: null,
            'in_reply_to' => trim((string) $message->getInReplyTo(), '<> ') ?: null,
            'sent_at' => $date ?? now(),
            'created_at' => now(),
        ]);
        $lead->activities()->create(['type' => 'email_in', 'data' => ['subject' => Str::limit((string) $message->getSubject(), 180)]]);
        $lead->update(['last_activity_at' => now()]);
        $this->live->leads($lead->brand_id, $lead->assignee_id);
        if ($lead->assignee_id) {
            rescue(fn () => event(new LeadEmailReceived($lead, (int) $lead->assignee_id)), report: false);
        }

        return true;
    }

    private function copyToSent(Brand $brand, string $raw): void
    {
        $client = $this->imap($brand)->connect();
        try {
            $sent = collect($client->getFolders(false))->first(fn ($folder) => preg_match('/(^|[.\/])sent( items| mail)?$/i', $folder->path));
            $sent?->appendMessage($raw, ['\\Seen']);
        } finally {
            rescue(fn () => $client->disconnect(), report: false);
        }
    }

    private function mailer(Brand $brand): Mailer
    {
        $port = (int) ($brand->mail_smtp_port ?: 465);

        return Mail::build([
            'transport' => 'smtp',
            // 465 talks TLS from the start; 587 and 25 upgrade with STARTTLS
            'scheme' => $port === 465 ? 'smtps' : 'smtp',
            'host' => $brand->mail_host,
            'port' => $port,
            'username' => $brand->mail_username ?: $brand->mail_address,
            'password' => $brand->mail_password,
            'timeout' => self::TIMEOUT,
            'verify_peer' => config('ppc.mail_verify_certificates'),
        ]);
    }

    private function imap(Brand $brand): Client
    {
        $port = (int) ($brand->mail_imap_port ?: 993);

        return (new ClientManager([
            'options' => [
                // Search and fetch by UID, and read without marking anything as read in webmail
                'sequence' => IMAP::ST_UID,
                'fetch' => IMAP::FT_PEEK,
                'soft_fail' => true,
            ],
        ]))->make([
            'host' => $brand->mail_host,
            'port' => $port,
            'encryption' => $port === 993 ? 'ssl' : 'starttls',
            'validate_cert' => config('ppc.mail_verify_certificates'),
            'username' => $brand->mail_username ?: $brand->mail_address,
            'password' => $brand->mail_password,
            'protocol' => 'imap',
            'timeout' => self::TIMEOUT,
        ]);
    }
}
