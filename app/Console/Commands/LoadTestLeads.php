<?php

namespace App\Console\Commands;

use App\Enums\Channel;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\User;
use App\Models\Website;
use App\Support\LeadText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Inserts N extra leads spread over the last 12 months, to test how the app behaves at scale */
class LoadTestLeads extends Command
{
    protected $signature = 'leads:load-test {count=100000 : How many leads to add}';

    protected $description = 'Add load-test leads (development/staging only)';

    private const BATCH = 2000;

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to load test data into production.');

            return self::FAILURE;
        }
        $websites = Website::get(['id', 'brand_id', 'domain']);
        if ($websites->isEmpty()) {
            $this->error('No websites found. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }
        $reps = User::with('brands:id')->where('active', true)
            ->whereIn('role', [Role::FrontSales->value, Role::UpSales->value])->get();
        $repsFor = $websites->pluck('brand_id')->unique()->mapWithKeys(fn ($b) => [
            $b => $reps->filter(fn ($u) => $u->brands->contains('id', $b))->pluck('id')->all(),
        ]);

        $first = ['Olivia', 'Liam', 'Emma', 'Noah', 'Ava', 'Mason', 'Sophia', 'Ethan', 'Mia', 'Lucas', 'Amelia', 'Aiden', 'Grace', 'Mateo', 'Leo', 'Nora', 'Ivy', 'Felix', 'Zara', 'Omar'];
        $last = ['Smith', 'Johnson', 'Brown', 'Garcia', 'Miller', 'Davis', 'Martinez', 'Lopez', 'Wilson', 'Anderson', 'Moore', 'Clark', 'Lewis', 'Walker', 'Ahmed', 'Chen', 'Nguyen', 'Kaur', 'Rossi', 'Silva'];
        $statuses = [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Converted, LeadStatus::Lost, LeadStatus::Lost, LeadStatus::Spam];
        $channels = [Channel::Ppc, Channel::Ppc, Channel::Ppc, Channel::Organic, Channel::Direct, Channel::Referral, Channel::Social];

        $total = (int) $this->argument('count');
        $start = microtime(true);
        $bar = $this->output->createProgressBar($total);

        for ($done = 0; $done < $total; $done += self::BATCH) {
            $rows = [];
            for ($i = 0; $i < min(self::BATCH, $total - $done); $i++) {
                $site = $websites->random();
                $name = $first[array_rand($first)].' '.$last[array_rand($last)];
                $n = $done + $i;
                $email = strtolower(str_replace(' ', '.', $name).".{$n}@loadtest.example");
                $phone = '+1555'.str_pad((string) (1_000_000 + $n % 9_000_000), 7, '0', STR_PAD_LEFT);
                $created = now()->subSeconds(random_int(0, 365 * 86400));
                $status = $statuses[array_rand($statuses)];
                $channel = $channels[array_rand($channels)];
                $pool = $repsFor[$site->brand_id] ?: [null];
                $assignee = $status === LeadStatus::New && random_int(0, 1) ? null : $pool[array_rand($pool)];
                $paid = $channel === Channel::Ppc;

                $rows[] = [
                    'brand_id' => $site->brand_id,
                    'website_id' => $site->id,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'phone_key' => LeadText::phoneKey($phone),
                    'message' => 'Load test enquiry',
                    'source' => random_int(1, 100) <= 85 ? LeadSource::Form->value : LeadSource::Chat->value,
                    'channel' => $channel->value,
                    'status' => $status->value,
                    'assignee_id' => $assignee,
                    'assigned_at' => $assignee ? $created : null,
                    'utm_source' => $paid ? 'google' : null,
                    'utm_medium' => $paid ? 'cpc' : null,
                    'utm_campaign' => $paid ? ['brand', 'generic-search', 'competitor', 'retargeting'][array_rand([0, 1, 2, 3])] : null,
                    'click_id' => $paid ? "loadtest-{$n}" : null,
                    'click_id_type' => $paid ? 'gclid' : null,
                    'landing_page' => "https://{$site->domain}/",
                    'submission_count' => 1,
                    'search_text' => LeadText::searchText($name, $email, $phone),
                    'last_activity_at' => $created,
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }
            DB::table('leads')->insert($rows);
            $bar->advance(count($rows));
        }

        $bar->finish();
        $this->newLine();
        $this->info(sprintf('Inserted %s leads in %.1fs', number_format($total), microtime(true) - $start));

        return self::SUCCESS;
    }
}
