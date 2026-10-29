<?php

namespace App\Services;

use App\Enums\LeadSource;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Website;
use App\Support\Attribution;
use App\Support\LeadText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tawk.to webhooks: chat:start, chat:end, chat:transcript_created, ticket:create.
 * Payload shapes: https://developer.tawk.to/webhooks/
 */
class TawkService
{
    public function __construct(private LeadIntake $intake) {}

    /** Tawk signs the raw body with HMAC-SHA1 (hex) using the webhook's secret key */
    public function verifySignature(string $rawBody, ?string $signature, array $secrets): bool
    {
        if (! $signature) {
            return false;
        }
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha1', $rawBody, $secret), strtolower(trim($signature)))) {
                return true;
            }
        }

        return false;
    }

    public function resolveWebsite(?string $propertyId, ?string $domain): ?Website
    {
        if ($propertyId && ($site = Website::where('tawk_property_id', $propertyId)->first())) {
            return $site;
        }
        $host = $domain ? preg_replace('/^www\./', '', strtolower($domain)) : null;

        return $host
            ? Website::whereNull('tawk_property_id')->whereIn('domain', [$host, "www.{$host}"])->first()
            : null;
    }

    /** @return 'processed'|'ignored' */
    public function handle(array $payload, ?Website $website): string
    {
        return match ($payload['event'] ?? null) {
            'chat:start' => $this->chatStart($payload, $website),
            'chat:end' => $this->chatEnd($payload, $website),
            'chat:transcript_created' => $this->transcript($payload, $website),
            'ticket:create' => $this->ticket($payload, $website),
            default => 'ignored',
        };
    }

    private function chatStart(array $p, ?Website $website): string
    {
        $chatId = LeadText::clean($p['chatId'] ?? null);
        if (! $chatId) {
            return 'ignored';
        }
        $at = $this->time($p['time'] ?? null);
        $text = LeadText::clean($p['message']['text'] ?? null, 20_000) ?? '(chat started)';

        $conversation = Conversation::firstOrCreate(
            ['provider' => 'tawk', 'external_id' => $chatId],
            [
                'kind' => 'chat',
                ...$this->siteFields($website, $p['property'] ?? []),
                ...$this->visitorFields($p['visitor'] ?? []),
                'page_url' => LeadText::clean($p['referrer'] ?? null, 2048),
                'last_message' => $text,
                'message_count' => 1,
                'started_at' => $at,
                'last_message_at' => $at,
            ],
        );
        if ($conversation->wasRecentlyCreated) {
            $conversation->messages()->create([
                'sender_type' => $p['message']['sender']['type'] ?? 'visitor',
                'body' => $text,
                'sent_at' => $at,
            ]);
        }

        $this->ensureLead($conversation);

        return 'processed';
    }

    private function chatEnd(array $p, ?Website $website): string
    {
        $chatId = LeadText::clean($p['chatId'] ?? null);
        if (! $chatId) {
            return 'ignored';
        }
        $at = $this->time($p['time'] ?? null);
        $visitor = $this->visitorFields($p['visitor'] ?? []);

        $conversation = Conversation::firstOrNew(['provider' => 'tawk', 'external_id' => $chatId]);
        if (! $conversation->exists) {
            $conversation->fill([
                'kind' => 'chat',
                ...$this->siteFields($website, $p['property'] ?? []),
                ...$visitor,
                'page_url' => LeadText::clean($p['referrer'] ?? null, 2048),
                'started_at' => $at,
                'last_message_at' => $at,
            ]);
        }
        $conversation->fill(['status' => 'ended', 'ended_at' => $at]);
        $this->rememberVisitor($conversation, $visitor);
        $conversation->save();

        $this->syncLeadContact($conversation);

        return 'processed';
    }

    private function transcript(array $p, ?Website $website): string
    {
        $chatId = LeadText::clean($p['chat']['id'] ?? null);
        if (! $chatId) {
            return 'ignored';
        }

        $messages = array_map(function (array $m) {
            $files = array_values(array_filter(array_map(
                fn ($a) => isset($a['content']['file']['url'])
                    ? '[Attachment: '.($a['content']['file']['name'] ?? 'file').'] '.$a['content']['file']['url']
                    : null,
                $m['attchs'] ?? [],
            )));

            return [
                'sender_type' => match ($m['sender']['t'] ?? null) {
                    'v' => 'visitor', 'a' => 'agent', default => 'system'
                },
                'sender_name' => LeadText::clean($m['sender']['n'] ?? null),
                'body' => implode("\n", array_filter([LeadText::clean($m['msg'] ?? null, 20_000), ...$files])) ?: '(empty)',
                'sent_at' => $this->time($m['time'] ?? null),
            ];
        }, array_filter($p['chat']['messages'] ?? [], 'is_array'));

        $at = $this->time($p['time'] ?? null);
        $last = end($messages) ?: null;
        $visitor = $this->visitorFields($p['chat']['visitor'] ?? []);

        $conversation = Conversation::firstOrNew(['provider' => 'tawk', 'external_id' => $chatId]);
        if (! $conversation->exists) {
            $conversation->fill([
                'kind' => 'chat',
                ...$this->siteFields($website, $p['property'] ?? []),
                ...$visitor,
                'page_url' => LeadText::clean($p['referrer'] ?? null, 2048),
                'started_at' => $messages[0]['sent_at'] ?? $at,
                'ended_at' => $last['sent_at'] ?? $at,
            ]);
        }
        $this->rememberVisitor($conversation, $visitor);
        $conversation->fill([
            'status' => 'ended',
            'message_count' => count($messages),
            'last_message' => $last ? mb_substr($last['body'], 0, 1000) : null,
            'last_message_at' => $last['sent_at'] ?? $at,
        ]);

        // The transcript is authoritative: replace whatever chat:start stored
        DB::transaction(function () use ($conversation, $messages) {
            $conversation->save();
            $conversation->messages()->delete();
            $conversation->messages()->createMany($messages);
        });

        $this->ensureLead($conversation);
        $this->syncLeadContact($conversation);

        return 'processed';
    }

    private function ticket(array $p, ?Website $website): string
    {
        $ticketId = LeadText::clean($p['ticket']['id'] ?? null);
        if (! $ticketId) {
            return 'ignored';
        }
        $at = $this->time($p['time'] ?? null);
        $subject = LeadText::clean($p['ticket']['subject'] ?? null, 255);
        $body = LeadText::clean($p['ticket']['message'] ?? null, 20_000) ?? $subject ?? '(no message)';

        $conversation = Conversation::firstOrCreate(
            ['provider' => 'tawk', 'external_id' => "ticket:{$ticketId}"],
            [
                'kind' => 'ticket',
                ...$this->siteFields($website, $p['property'] ?? []),
                ...$this->visitorFields(['name' => $p['requester']['name'] ?? null, 'email' => $p['requester']['email'] ?? null]),
                'subject' => $subject,
                'last_message' => mb_substr($body, 0, 1000),
                'message_count' => 1,
                'started_at' => $at,
                'last_message_at' => $at,
            ],
        );
        if ($conversation->wasRecentlyCreated) {
            $conversation->messages()->create([
                'sender_type' => 'visitor',
                'sender_name' => LeadText::clean($p['requester']['name'] ?? null),
                'body' => $body,
                'sent_at' => $at,
            ]);
        }

        $this->ensureLead($conversation);

        return 'processed';
    }

    /**
     * Creates (or links to an existing) lead once the conversation belongs to a
     * website and the visitor has actually said something.
     */
    public function ensureLead(Conversation $conversation): ?int
    {
        $conversation->refresh();
        if ($conversation->lead_id || ! $conversation->website_id) {
            return $conversation->lead_id;
        }
        $firstMessage = $conversation->messages()->where('sender_type', 'visitor')->orderBy('sent_at')->value('body');
        $website = $conversation->website;
        if ($firstMessage === null || ! $website) {
            return null;
        }

        $location = implode(', ', array_filter([$conversation->city, $conversation->country]));
        $result = $this->intake->ingest($website->brand_id, $website, $conversation->kind === 'ticket' ? LeadSource::Offline : LeadSource::Chat, [
            'name' => Conversation::realVisitorName($conversation->visitor_name)
                ?? ($conversation->city ? "Chat visitor ({$conversation->city})" : 'Chat visitor'),
            'email' => $conversation->visitor_email,
            'message' => $conversation->subject ? "{$conversation->subject}\n\n{$firstMessage}" : $firstMessage,
            'form_name' => $conversation->kind === 'ticket' ? 'Tawk.to offline form' : 'Tawk.to live chat',
            'attribution' => Attribution::merge(Attribution::fromUrl($conversation->page_url), ['page_url' => $conversation->page_url]),
            'extra_fields' => $location ? ['Location' => $location] : [],
        ]);

        $conversation->update(['lead_id' => $result['lead_id']]);

        return $result['lead_id'];
    }

    /** Visitors often share their email mid-chat; copy it onto the lead if it has none */
    private function syncLeadContact(Conversation $conversation): void
    {
        $lead = $conversation->lead_id ? Lead::find($conversation->lead_id) : null;
        if (! $lead || ! $conversation->visitor_email || $lead->email) {
            return;
        }
        $name = str_starts_with($lead->name, 'Chat visitor')
            ? (Conversation::realVisitorName($conversation->visitor_name) ?? $lead->name)
            : $lead->name;

        $lead->update([
            'email' => $conversation->visitor_email,
            'name' => $name,
            'search_text' => LeadText::searchText($name, $conversation->visitor_email, $lead->phone, $lead->company),
        ]);
    }

    /**
     * After a Tawk property is linked to a website, attach chats that arrived
     * before the link existed and create their leads.
     */
    public function linkConversations(Website $website): int
    {
        if (! $website->tawk_property_id) {
            return 0;
        }
        Conversation::where('property_id', $website->tawk_property_id)
            ->whereNull('website_id')
            ->update(['website_id' => $website->id, 'brand_id' => $website->brand_id]);

        $pending = Conversation::where('website_id', $website->id)->whereNull('lead_id')->limit(500)->get();
        foreach ($pending as $conversation) {
            $this->ensureLead($conversation);
        }

        return $pending->count();
    }

    private function rememberVisitor(Conversation $conversation, array $visitor): void
    {
        if ($visitor['visitor_email']) {
            $conversation->visitor_email = $visitor['visitor_email'];
        }
        if (Conversation::realVisitorName($visitor['visitor_name'])) {
            $conversation->visitor_name = $visitor['visitor_name'];
        }
    }

    private function siteFields(?Website $website, array $property): array
    {
        return [
            'property_id' => LeadText::clean($property['id'] ?? null, 64),
            'property_name' => LeadText::clean($property['name'] ?? null),
            'website_id' => $website?->id,
            'brand_id' => $website?->brand_id,
        ];
    }

    private function visitorFields(array $visitor): array
    {
        return [
            'visitor_name' => LeadText::clean($visitor['name'] ?? null),
            'visitor_email' => LeadText::email($visitor['email'] ?? null),
            'city' => LeadText::clean($visitor['city'] ?? null, 120),
            'country' => LeadText::clean($visitor['country'] ?? null, 8),
        ];
    }

    private function time(?string $value): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::parse($value)->utc() : CarbonImmutable::now();
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }
}
