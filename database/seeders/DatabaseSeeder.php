<?php

namespace Database\Seeders;

use App\Enums\Channel;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use App\Support\LeadText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Demo data for development and staging. Every account's password is "password123". */
class DatabaseSeeder extends Seeder
{
    private const FIRST = ['Olivia', 'Liam', 'Emma', 'Noah', 'Ava', 'Mason', 'Sophia', 'Ethan', 'Mia', 'Lucas', 'Amelia', 'Aiden', 'Harper', 'Elijah', 'Ella', 'James', 'Aria', 'Daniel', 'Zara', 'Omar', 'Priya', 'Hassan', 'Grace', 'Mateo'];

    private const LAST = ['Smith', 'Johnson', 'Brown', 'Garcia', 'Miller', 'Davis', 'Martinez', 'Lopez', 'Wilson', 'Anderson', 'Thomas', 'Moore', 'Clark', 'Lewis', 'Walker', 'Hall', 'Young', 'King', 'Wright', 'Scott', 'Ahmed', 'Chen', 'Nguyen', 'Kaur'];

    private const SITES = [
        [
            'brand' => 'apex', 'name' => 'Apex Roofing', 'domain' => 'apexroofing.test', 'tawk' => '64f1c2a9b1d3e2001a7f0c11',
            'campaigns' => [['roof-repair-search', 'roof repair near me'], ['roof-replacement', 'new roof cost'], ['storm-damage', 'storm damage roof']],
            'messages' => ['Need a quote for a roof replacement, 2 storey house.', "Leak in the ceiling after last night's storm, can someone come out this week?", 'How much for a full inspection?', 'Looking to replace shingles on a garage roof.'],
            'chats' => [
                ['Hi, do you service the north side of town?', 'Hi! Yes we do. What do you need help with?', 'Roof is leaking near the chimney.', 'Sorry to hear that. Can I get your email so we can book an inspection?', "Sure, it's below."],
                ['How long does a full roof replacement take?', 'Most homes take 2–3 days. Would you like a free estimate?', 'Yes, sometime next week.'],
            ],
        ],
        [
            'brand' => 'apex', 'name' => 'Apex Plumbing', 'domain' => 'apexplumbing.test', 'tawk' => null,
            'campaigns' => [['emergency-plumber', 'emergency plumber'], ['water-heater', 'water heater installation'], ['drain-cleaning', 'blocked drain']],
            'messages' => ['Water heater stopped working, need it fixed ASAP.', 'Kitchen sink is blocked and backing up.', 'Quote for re-piping a bathroom please.', 'Do you do weekend call-outs?'],
            'chats' => [],
        ],
        [
            'brand' => 'lumen', 'name' => 'Lumen Dental', 'domain' => 'lumendental.test', 'tawk' => '65a7d0e4c9f8b1002b3e9d42',
            'campaigns' => [['dental-implants', 'dental implants cost'], ['invisalign', 'invisalign near me'], ['teeth-whitening', 'teeth whitening price']],
            'messages' => ['Interested in implants, do you offer payment plans?', 'Can I book a consultation for Invisalign?', 'What does whitening cost?', 'Do you accept new patients on Saturdays?'],
            'chats' => [
                ['Hello, how much is a consultation?', 'Hi there! Initial consultations are free. Would you like to book one?', 'Yes please, next week if possible.'],
                ['Are you open on weekends?', "We're open Saturdays 9–2. Anything I can help with today?", 'Just wanted a price on whitening.'],
            ],
        ],
    ];

    public function run(): void
    {
        mt_srand(42); // the demo data looks the same on every reset
        $now = CarbonImmutable::now();
        $pick = fn (array $items) => $items[mt_rand(0, count($items) - 1)];
        $chance = fn (float $p) => mt_rand() / mt_getrandmax() < $p;

        $apex = Brand::create(['name' => 'Apex Home Services', 'slug' => 'apex-home', 'auto_assign_enabled' => true]);
        $lumen = Brand::create(['name' => 'Lumen Dental', 'slug' => 'lumen-dental', 'auto_assign_enabled' => false]);
        $brands = ['apex' => $apex, 'lumen' => $lumen];

        $password = Hash::make('password123');
        $user = function (string $email, string $name, Role $role, array $brandIds = [], ?int $adminId = null) use ($password, $chance, $now) {
            $u = User::create([
                'email' => $email, 'name' => $name, 'password' => $password, 'role' => $role, 'admin_id' => $adminId,
                'last_login_at' => $chance(0.8) ? $now->subMinutes(mt_rand(5, 5 * 1440)) : null,
            ]);
            $u->brands()->sync($brandIds);

            return $u;
        };
        $user('superadmin@ppc.local', 'Alex Morgan', Role::SuperAdmin);
        $admin = $user('admin@ppc.local', 'Jordan Lee', Role::Admin, [$apex->id, $lumen->id]);
        $front1 = $user('front1@ppc.local', 'Sam Rivera', Role::FrontSales, [$apex->id], $admin->id);
        $front2 = $user('front2@ppc.local', 'Taylor Brooks', Role::FrontSales, [$apex->id, $lumen->id], $admin->id);
        $front3 = $user('front3@ppc.local', 'Riley Chen', Role::FrontSales, [$lumen->id], $admin->id);
        $upSales = $user('upsales@ppc.local', 'Casey Patel', Role::UpSales, [$apex->id, $lumen->id], $admin->id);
        $user('finance@ppc.local', 'Morgan Ellis', Role::Finance, [$apex->id, $lumen->id]);
        $user('dev@ppc.local', 'Dev Account', Role::Developer);
        $repsFor = [$apex->id => [$front1->id, $front2->id], $lumen->id => [$front2->id, $front3->id]];

        $websites = [];
        foreach (self::SITES as $site) {
            $websites[] = [$site, Website::create([
                'brand_id' => $brands[$site['brand']]->id,
                'name' => $site['name'],
                'domain' => $site['domain'],
                'public_key' => Website::generatePublicKey(),
                'tawk_property_id' => $site['tawk'],
                'tawk_webhook_secret' => $site['tawk'] ? 'demo-tawk-secret' : null,
            ])];
        }

        // ~400 leads over the last 45 days, busier on recent weekdays
        $converted = [];
        for ($day = 44; $day >= 0; $day--) {
            $date = $now->subDays($day);
            $perDay = (int) round(($date->isWeekend() ? 3 : 6) + mt_rand(0, 40) / 10 + (44 - $day) / 15);
            for ($i = 0; $i < $perDay; $i++) {
                [$site, $website] = $pick($websites);
                $first = $pick(self::FIRST);
                $last = $pick(self::LAST);
                $name = "{$first} {$last}";
                $email = $chance(0.9) ? strtolower("{$first}.{$last}".mt_rand(10, 99).'@example.com') : null;
                $phone = $chance(0.75) ? sprintf('+1 (555) %03d-%04d', mt_rand(100, 999), mt_rand(1000, 9999)) : null;
                $createdAt = $date->subMinutes(mt_rand(0, 600));
                $ageDays = $createdAt->diffInDays($now);

                $channel = $pick([Channel::Ppc, Channel::Ppc, Channel::Ppc, Channel::Ppc, Channel::Organic, Channel::Direct, Channel::Referral, Channel::Social]);
                [$campaign, $term] = $pick($site['campaigns']);
                $paid = $channel === Channel::Ppc;
                $gclid = $paid ? 'Cj0K'.Str::random(12) : null;

                $status = $ageDays < 1
                    ? LeadStatus::New
                    : $pick([LeadStatus::New, LeadStatus::Contacted, LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Converted, LeadStatus::Lost, ...($chance(0.15) ? [LeadStatus::Spam] : [])]);
                $assigneeId = $status === LeadStatus::Converted ? $upSales->id
                    : (($status !== LeadStatus::New || $chance(0.45)) ? $pick($repsFor[$website->brand_id]) : null);
                $source = $chance(0.12) ? LeadSource::Manual : LeadSource::Form;

                $lead = Lead::create([
                    'brand_id' => $website->brand_id,
                    'website_id' => $website->id,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'phone_key' => LeadText::phoneKey($phone),
                    'message' => $pick($site['messages']),
                    'source' => $source->value,
                    'channel' => $channel->value,
                    'status' => $status->value,
                    'assignee_id' => $assigneeId,
                    'assigned_at' => $assigneeId ? $createdAt->addMinutes(20) : null,
                    'utm_source' => $paid ? 'google' : null,
                    'utm_medium' => $paid ? 'cpc' : null,
                    'utm_campaign' => $paid ? $campaign : null,
                    'utm_term' => $paid ? $term : null,
                    'click_id' => $gclid,
                    'click_id_type' => $paid ? 'gclid' : null,
                    'landing_page' => "https://{$site['domain']}/{$campaign}".($paid ? "?utm_source=google&utm_medium=cpc&utm_campaign={$campaign}&gclid={$gclid}" : ''),
                    'page_url' => "https://{$site['domain']}/contact",
                    'referrer' => match ($channel) {
                        Channel::Organic => 'https://www.google.com/', Channel::Social => 'https://www.facebook.com/', default => null
                    },
                    'form_name' => $source === LeadSource::Form ? $pick(['Contact form', 'Free quote', 'Book a consultation']) : null,
                    'user_agent' => $pick([
                        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
                        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
                        'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Mobile Safari/537.36',
                    ]),
                    'submission_count' => $chance(0.08) ? 2 : 1,
                    'search_text' => LeadText::searchText($name, $email, $phone),
                    'last_activity_at' => $createdAt->addHours(4),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $activities = [['type' => 'created', 'data' => ['source' => $source->value, 'website_id' => $website->id], 'created_at' => $createdAt]];
                if ($assigneeId) {
                    $activities[] = ['type' => 'assigned', 'actor_id' => $admin->id, 'data' => ['from_user_id' => null, 'to_user_id' => $assigneeId, 'reason' => 'manual'], 'created_at' => $createdAt->addMinutes(20)];
                }
                if ($status !== LeadStatus::New) {
                    $activities[] = ['type' => 'status', 'actor_id' => $assigneeId, 'data' => ['from' => 'new', 'to' => $status->value], 'created_at' => $createdAt->addHours(3)];
                }
                if ($status === LeadStatus::Contacted && $chance(0.6)) {
                    $activities[] = ['type' => 'note', 'actor_id' => $assigneeId ?? $admin->id, 'created_at' => $createdAt->addHours(4),
                        'body' => $pick(['Called, left voicemail. Will try again tomorrow.', 'Spoke briefly — wants a quote by email.', 'Booked a site visit for Thursday 10am.', 'Sent pricing sheet, follow up Monday.'])];
                }
                $lead->activities()->createMany($activities);

                if ($status === LeadStatus::Converted) {
                    $converted[] = $lead;
                }
            }
        }

        // Tawk.to chats and offline messages, linked to leads
        $tawkSites = array_values(array_filter($websites, fn ($w) => $w[1]->tawk_property_id));
        for ($i = 0; $i < 9; $i++) {
            [$site, $website] = $pick($tawkSites);
            $script = $pick($site['chats']);
            $startedAt = $now->subMinutes((int) (($i * 7 + mt_rand(0, 60) / 10) * 60));
            $named = $chance(0.5);
            $visitorName = $named ? $pick(self::FIRST).' '.$pick(self::LAST) : 'V'.(1700000000000 + mt_rand(0, 999999999));
            $visitorEmail = $named && $chance(0.7) ? strtolower(explode(' ', $visitorName)[0]).'@example.com' : null;
            $city = $pick(['Austin', 'Denver', 'Phoenix', 'Seattle', 'Atlanta']);
            $kind = $i % 4 === 3 ? 'ticket' : 'chat';
            $leadName = $named ? $visitorName : "Chat visitor ({$city})";

            $lead = Lead::create([
                'brand_id' => $website->brand_id,
                'website_id' => $website->id,
                'name' => $leadName,
                'email' => $visitorEmail,
                'message' => $script[0],
                'source' => ($kind === 'ticket' ? LeadSource::Offline : LeadSource::Chat)->value,
                'channel' => ($chance(0.6) ? Channel::Ppc : Channel::Unknown)->value,
                'status' => ($i < 3 ? LeadStatus::New : $pick([LeadStatus::Contacted, LeadStatus::Qualified]))->value,
                'assignee_id' => $i < 2 ? null : $pick($repsFor[$website->brand_id]),
                'assigned_at' => $i < 2 ? null : $startedAt,
                'form_name' => $kind === 'ticket' ? 'Tawk.to offline form' : 'Tawk.to live chat',
                'page_url' => "https://{$website->domain}/",
                'metadata' => ['fields' => ['Location' => "{$city}, US"]],
                'search_text' => LeadText::searchText($leadName, $visitorEmail, null),
                'last_activity_at' => $startedAt,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);
            $lead->activities()->create(['type' => 'created', 'data' => ['source' => $lead->source], 'created_at' => $startedAt]);

            $messages = array_map(fn ($body, $idx) => [
                'sender_type' => $idx % 2 === 0 ? 'visitor' : 'agent',
                'sender_name' => $idx % 2 === 0 ? null : 'Support',
                'body' => $body,
                'sent_at' => $startedAt->addSeconds($idx * 45),
            ], $kind === 'ticket' ? [$script[0]] : $script, array_keys($kind === 'ticket' ? [$script[0]] : $script));
            $lastMessage = end($messages);

            Conversation::create([
                'external_id' => $kind === 'ticket' ? "ticket:demo-{$i}" : "demo-chat-{$i}",
                'kind' => $kind,
                'property_id' => $website->tawk_property_id,
                'property_name' => $website->name,
                'website_id' => $website->id,
                'brand_id' => $website->brand_id,
                'lead_id' => $lead->id,
                'visitor_name' => $visitorName,
                'visitor_email' => $visitorEmail,
                'city' => $city,
                'country' => 'US',
                'page_url' => "https://{$website->domain}/",
                'subject' => $kind === 'ticket' ? 'Question from website' : null,
                'status' => $i === 0 && $kind === 'chat' ? 'open' : 'ended',
                'started_at' => $startedAt,
                'ended_at' => $i === 0 ? null : $lastMessage['sent_at'],
                'last_message_at' => $lastMessage['sent_at'],
                'last_message' => $lastMessage['body'],
                'message_count' => count($messages),
            ])->messages()->createMany($messages);
        }

        // A chat from a Tawk property nobody has linked yet (shows the setup prompt)
        $at = $now->subHours(26);
        Conversation::create([
            'external_id' => 'demo-unlinked-1', 'kind' => 'chat', 'property_id' => '66b2e9f0a1c4d7003c5f8e77',
            'property_name' => 'Apex Gutters (new site)', 'visitor_name' => 'V1729000000001', 'city' => 'Dallas', 'country' => 'US',
            'status' => 'ended', 'started_at' => $at, 'last_message_at' => $at, 'last_message' => 'Do you clean gutters too?', 'message_count' => 1,
        ])->messages()->create(['sender_type' => 'visitor', 'body' => 'Do you clean gutters too?', 'sent_at' => $at]);

        // Normally kept up to date by intake; the seeder writes leads directly
        foreach ($websites as [, $website]) {
            $website->update([
                'last_lead_at' => Lead::where('website_id', $website->id)->where('source', LeadSource::Form->value)->max('created_at'),
                'last_chat_at' => Conversation::where('website_id', $website->id)->max('last_message_at'),
            ]);
        }

        // Invoices for some converted leads
        foreach (array_slice($converted, 0, 12) as $i => $lead) {
            $amount = mt_rand(800, 6800);
            $invoice = Invoice::create([
                'number' => 'INV-'.(1001 + $i),
                'lead_id' => $lead->id,
                'brand_id' => $lead->brand_id,
                'created_by_id' => $upSales->id,
                'amount' => $amount,
                'status' => $chance(0.5) ? 'paid' : 'sent',
                'description' => 'Services as quoted',
                'line_items' => [['label' => 'Services as quoted', 'amount' => $amount]],
                'created_at' => $lead->created_at->addDays(2),
                'updated_at' => $lead->created_at->addDays(2),
            ]);
            if ($invoice->status === 'paid') {
                $invoice->payments()->create(['amount' => $amount, 'method' => $pick(['bank_transfer', 'card', 'check']), 'external_ref' => 'TXN-'.(2001 + $i)]);
            }
        }

        $this->command?->info('Seeded '.Lead::count().' leads, '.Website::count().' websites, '.Conversation::count().' conversations.');
        $this->command?->info('Demo password for every account: password123');
        foreach ($websites as [, $website]) {
            $this->command?->line(sprintf('  %-14s key: %s', $website->name, $website->public_key));
        }
    }
}
