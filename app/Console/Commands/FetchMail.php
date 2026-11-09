<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\BrandMailbox;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/** Collects replies from every brand's mailbox. Runs every minute from the one cPanel cron job. */
class FetchMail extends Command
{
    protected $signature = 'ppc:fetch-mail';

    protected $description = "File new emails from clients on their leads, from each brand's mailbox";

    public function handle(BrandMailbox $mailbox): int
    {
        Brand::whereNotNull('mail_host')->whereNotNull('mail_password')->each(function (Brand $brand) use ($mailbox) {
            try {
                $filed = $mailbox->fetch($brand);
                $this->line("{$brand->name}: {$filed} new");
            } catch (Throwable $e) {
                // One brand's broken mailbox never stops the others; the Email page shows what went wrong
                $brand->forceFill(['mail_checked_at' => now(), 'mail_error' => Str::limit($e->getMessage(), 480)])->save();
                $this->warn("{$brand->name}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
