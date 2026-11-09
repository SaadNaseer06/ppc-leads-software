<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\MailboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/** Collects clients' replies from every connected mailbox. Runs every minute from the one cPanel cron job. */
class FetchMail extends Command
{
    protected $signature = 'ppc:fetch-mail';

    protected $description = 'File new emails from clients on their leads, from every connected mailbox';

    public function handle(MailboxService $mail): int
    {
        Mailbox::query()->orderBy('id')->each(function (Mailbox $mailbox) use ($mail) {
            try {
                $this->line("{$mailbox->address}: {$mail->fetch($mailbox)} new");
            } catch (Throwable $e) {
                // One broken mailbox never stops the others; the Email page shows what went wrong
                $mailbox->forceFill(['checked_at' => now(), 'error' => Str::limit($e->getMessage(), 480)])->save();
                $this->warn("{$mailbox->address}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
