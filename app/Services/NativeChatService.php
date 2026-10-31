<?php

namespace App\Services;

use App\Enums\LeadSource;
use App\Events\ChatDeleted;
use App\Events\ChatMessageSent;
use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use App\Support\Attribution;
use App\Support\LeadText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * First-party live chat: session resume, visitor/agent messages, lead linking.
 */
class NativeChatService
{
    public function __construct(private LeadIntake $intake) {}

    /**
     * Create or resume a native conversation for a website visitor.
     *
     * @return array{conversation: Conversation, visitor_token: string, created: bool}
     */
    public function session(Website $website, ?string $visitorToken, ?string $pageUrl = null): array
    {
        if ($visitorToken) {
            $hash = $this->hashToken($visitorToken);
            $existing = Conversation::query()
                ->where('provider', 'native')
                ->where('website_id', $website->id)
                ->where('visitor_token', $hash)
                ->where('status', 'open')
                ->first();

            if ($existing) {
                if ($pageUrl) {
                    $existing->update(['page_url' => LeadText::clean($pageUrl, 2048) ?? $existing->page_url]);
                }

                return ['conversation' => $existing, 'visitor_token' => $visitorToken, 'created' => false];
            }
        }

        $token = Str::random(40);
        $now = CarbonImmutable::now();
        $conversation = Conversation::create([
            'provider' => 'native',
            'external_id' => 'native:'.Str::uuid()->toString(),
            'visitor_token' => $this->hashToken($token),
            'kind' => 'chat',
            'website_id' => $website->id,
            'brand_id' => $website->brand_id,
            'property_name' => $website->name,
            'page_url' => LeadText::clean($pageUrl, 2048),
            'status' => 'open',
            'message_count' => 0,
            'started_at' => $now,
            'last_message_at' => $now,
        ]);

        return ['conversation' => $conversation, 'visitor_token' => $token, 'created' => true];
    }

    public function findByVisitorToken(string $visitorToken): ?Conversation
    {
        return Conversation::query()
            ->where('provider', 'native')
            ->where('visitor_token', $this->hashToken($visitorToken))
            ->first();
    }

    public function updateVisitor(Conversation $conversation, ?string $name, ?string $email): Conversation
    {
        $cleanName = LeadText::clean($name);
        $cleanEmail = LeadText::email($email);
        if ($cleanName) {
            $conversation->visitor_name = $cleanName;
        }
        if ($cleanEmail) {
            $conversation->visitor_email = $cleanEmail;
        }
        $conversation->save();
        $this->syncLeadContact($conversation);

        return $conversation;
    }

    public function visitorMessage(Conversation $conversation, string $body, ?string $senderName = null): ConversationMessage
    {
        abort_unless($conversation->provider === 'native', 422, 'This conversation cannot accept widget messages.');
        abort_unless($conversation->status === 'open', 422, 'This chat has ended.');

        $text = LeadText::clean($body, 20_000);
        abort_unless($text !== null && $text !== '', 422, 'Message cannot be empty.');

        $at = CarbonImmutable::now();
        $message = $conversation->messages()->create([
            'sender_type' => 'visitor',
            'sender_name' => LeadText::clean($senderName) ?? Conversation::realVisitorName($conversation->visitor_name),
            'body' => $text,
            'sent_at' => $at,
        ]);

        $conversation->update([
            'last_message' => mb_substr($text, 0, 1000),
            'last_message_at' => $at,
            'message_count' => $conversation->messages()->count(),
        ]);

        if ($conversation->website) {
            $conversation->website->update(['last_chat_at' => $at]);
        }

        $this->ensureLead($conversation);
        $this->broadcast($message);

        return $message;
    }

    public function agentReply(Conversation $conversation, User $agent, string $body): ConversationMessage
    {
        abort_unless($conversation->provider === 'native', 422, 'Reply to Tawk.to chats from the Tawk.to dashboard. Only native chats can be answered here.');
        abort_unless($conversation->status === 'open', 422, 'This chat has ended.');

        $text = LeadText::clean($body, 20_000);
        abort_unless($text !== null && $text !== '', 422, 'Message cannot be empty.');

        $at = CarbonImmutable::now();
        $message = $conversation->messages()->create([
            'sender_type' => 'agent',
            'sender_name' => $agent->name,
            'body' => $text,
            'sent_at' => $at,
        ]);

        $conversation->update([
            'last_message' => mb_substr($text, 0, 1000),
            'last_message_at' => $at,
            'message_count' => $conversation->messages()->count(),
            'assigned_agent_id' => $conversation->assigned_agent_id ?? $agent->id,
        ]);

        $this->broadcast($message);

        return $message;
    }

    /**
     * Deletes chats with their messages; linked leads stay. Open inboxes and the visitor's
     * widget are told after the response.
     *
     * @param  list<int>  $ids
     * @return int how many were deleted
     */
    public function delete(User $actor, array $ids): int
    {
        $conversations = Conversation::query()->visibleTo($actor)->with('lead:id,assignee_id')
            ->whereIn('id', $ids)->get(['id', 'brand_id', 'lead_id']);
        if ($conversations->isEmpty()) {
            return 0;
        }

        // Messages cascade with their conversation
        Conversation::whereIn('id', $conversations->pluck('id'))->delete();
        ActivityLog::record($actor->id, 'chat.delete', 'Conversation',
            $conversations->count() === 1 ? $conversations->first()->id : null,
            ['ids' => $conversations->pluck('id')->all()]);

        defer(function () use ($conversations) {
            try {
                event(new ChatDeleted(
                    $conversations->pluck('id')->all(),
                    $conversations->pluck('brand_id')->filter()->unique()->values()->all(),
                    $conversations->map(fn ($c) => $c->lead?->assignee_id)->filter()->unique()->values()->all(),
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $conversations->count();
    }

    public function end(Conversation $conversation): Conversation
    {
        abort_unless($conversation->provider === 'native', 422, 'Only native chats can be ended here.');
        if ($conversation->status === 'ended') {
            return $conversation;
        }

        $at = CarbonImmutable::now();
        $conversation->update(['status' => 'ended', 'ended_at' => $at]);

        $system = $conversation->messages()->create([
            'sender_type' => 'system',
            'body' => 'Chat ended',
            'sent_at' => $at,
        ]);
        $conversation->update([
            'last_message' => 'Chat ended',
            'last_message_at' => $at,
            'message_count' => $conversation->messages()->count(),
        ]);

        $this->broadcast($system);

        return $conversation->fresh();
    }

    /**
     * Ends native chats nobody has written in for `ppc.active_chat_hours`, so visitors who
     * simply left don't stay "live" forever (and removes sessions that never got a message).
     * The inbox order (last_message_at) is kept.
     */
    public function closeIdle(): int
    {
        $closed = 0;
        Conversation::query()
            ->where('status', 'open')
            ->where('provider', 'native')
            ->where('last_message_at', '<', now()->subHours(config('ppc.active_chat_hours')))
            ->chunkById(200, function ($conversations) use (&$closed) {
                foreach ($conversations as $conversation) {
                    // The visitor opened the widget but never wrote: nothing worth keeping
                    if ($conversation->message_count === 0 && ! $conversation->lead_id) {
                        $conversation->delete();

                        continue;
                    }
                    $at = CarbonImmutable::now();
                    $conversation->messages()->create(['sender_type' => 'system', 'body' => 'Chat closed after inactivity', 'sent_at' => $at]);
                    $conversation->update(['status' => 'ended', 'ended_at' => $at, 'message_count' => $conversation->message_count + 1]);
                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * Creates (or links) a lead on the first visitor message — same pattern as Tawk.
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
        $result = $this->intake->ingest($website->brand_id, $website, LeadSource::Chat, [
            'name' => Conversation::realVisitorName($conversation->visitor_name)
                ?? ($conversation->city ? "Visitor from {$conversation->city}" : 'Website visitor'),
            'email' => $conversation->visitor_email,
            'message' => $firstMessage,
            'form_name' => 'Live chat',
            'attribution' => Attribution::merge(Attribution::fromUrl($conversation->page_url), ['page_url' => $conversation->page_url]),
            'extra_fields' => $location ? ['Location' => $location] : [],
        ]);

        $conversation->update(['lead_id' => $result['lead_id']]);

        return $result['lead_id'];
    }

    public function syncLeadContact(Conversation $conversation): void
    {
        $lead = $conversation->lead_id ? Lead::find($conversation->lead_id) : null;
        if (! $lead) {
            return;
        }

        $updates = [];
        if ($conversation->visitor_email && ! $lead->email) {
            $updates['email'] = $conversation->visitor_email;
        }
        if ($conversation->visitor_name && (str_starts_with($lead->name, 'Chat visitor') || str_starts_with($lead->name, 'Website visitor') || str_starts_with($lead->name, 'Visitor from '))) {
            $updates['name'] = Conversation::realVisitorName($conversation->visitor_name) ?? $lead->name;
        }
        if ($updates) {
            $name = $updates['name'] ?? $lead->name;
            $email = $updates['email'] ?? $lead->email;
            $updates['search_text'] = LeadText::searchText($name, $email, $lead->phone, $lead->company);
            $lead->update($updates);
        }
    }

    /**
     * Sent after the response, so Pusher latency never slows a visitor's or an agent's send.
     * Best-effort: the message is already saved, and polling delivers it if Pusher is down.
     */
    private function broadcast(ConversationMessage $message): void
    {
        defer(function () use ($message) {
            try {
                event(new ChatMessageSent($message->fresh(['conversation.website', 'conversation.lead'])));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    public function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    /** Verify a plaintext visitor token against the stored hash without relying on Hash::check timing. */
    public function tokenMatches(Conversation $conversation, string $visitorToken): bool
    {
        if (! $conversation->visitor_token) {
            return false;
        }

        return hash_equals($conversation->visitor_token, $this->hashToken($visitorToken));
    }
}
