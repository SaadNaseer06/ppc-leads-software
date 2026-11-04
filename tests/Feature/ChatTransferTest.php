<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\ChatMessageSent;
use App\Events\ChatTransferred;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\Lead;
use App\Services\NativeChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChatTransferTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Conversation, 1: string} a live chat and the visitor's token */
    private function chat(Brand $brand): array
    {
        $chat = app(NativeChatService::class);
        $session = $chat->session($this->website($brand), null, 'https://x.test/');
        $chat->updateVisitor($session['conversation'], 'Ada', 'ada@example.com');
        $chat->visitorMessage($session['conversation'], 'I need a quote');

        return [$session['conversation']->fresh(), $session['visitor_token']];
    }

    public function test_a_chat_agent_hands_a_chat_to_a_front_sales_rep(): void
    {
        Event::fake([ChatTransferred::class]);
        $brand = $this->brand();
        $agent = $this->user(Role::ChatAgent, [$brand], ['name' => 'Jamie']);
        $rep = $this->user(Role::FrontSales, [$brand], ['name' => 'Sam']);
        [$conversation, $token] = $this->chat($brand);

        // Not theirs yet
        $this->actingAs($rep);
        $this->getJson('/api/chats')->assertOk()->assertJsonCount(0, 'conversations');

        $this->actingAs($agent);
        $this->getJson("/api/chats?id={$conversation->id}")->assertOk()->assertJsonPath('front_sales.0.id', $rep->id);
        $this->postJson("/api/chats/{$conversation->id}/transfer", ['user_id' => $rep->id, 'note' => 'Wants pricing for 3 sites'])
            ->assertOk()->assertJsonPath('conversation.assigned_to', 'Sam');
        Event::assertDispatched(ChatTransferred::class, fn ($e) => $e->toUserId === $rep->id && $e->fromName === 'Jamie');

        // The rep now has it, with the history and the note, and can reply
        $this->actingAs($rep);
        $this->getJson("/api/chats?id={$conversation->id}")->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('selected.transfer.by', 'Jamie')
            ->assertJsonPath('selected.transfer.note', 'Wants pricing for 3 sites')
            ->assertJsonPath('selected.messages.0.body', 'I need a quote');
        $this->postJson("/api/chats/{$conversation->id}/messages", ['body' => 'Hi Ada, Sam here'])->assertCreated();
        $this->getJson('/api/live')->assertJsonPath('active_chats', 1);

        // The visitor sees who joined and the reply, but never the internal note
        $widget = $this->getJson("/api/chat/messages?visitor_token={$token}")->assertOk();
        $this->assertSame(['I need a quote', 'Sam has joined the chat', 'Hi Ada, Sam here'], array_column($widget->json('messages'), 'body'));
        $this->assertStringNotContainsString('Wants pricing', $widget->getContent());
        $event = new ChatMessageSent($conversation->messages()->latest('id')->first()->fresh(['conversation.website', 'conversation.lead', 'conversation.assignedAgent']));
        $this->assertStringNotContainsString('Wants pricing', json_encode($event->broadcastWith()));
    }

    public function test_the_lead_moves_with_the_chat(): void
    {
        $brand = $this->brand();
        $admin = $this->user(Role::Admin, [$brand]);
        $first = $this->user(Role::FrontSales, [$brand]);
        $second = $this->user(Role::FrontSales, [$brand]);
        [$conversation] = $this->chat($brand);

        // Handing over can convert the chat in the same step
        $this->actingAs($admin);
        $this->postJson("/api/chats/{$conversation->id}/transfer", ['user_id' => $first->id, 'convert' => true])->assertOk();
        $lead = Lead::find($conversation->fresh()->lead_id);
        $this->assertNotNull($lead->promoted_at);
        $this->assertSame($first->id, $lead->assignee_id);

        // Moving it on takes both away from the first rep
        $this->postJson("/api/chats/{$conversation->id}/transfer", ['user_id' => $second->id])->assertOk();
        $this->assertSame($second->id, $lead->fresh()->assignee_id);
        $this->assertSame('chat_transfer', $lead->activities()->where('type', 'assigned')->latest('id')->first()->data['reason']);
        $this->actingAs($first);
        $this->getJson('/api/chats')->assertOk()->assertJsonCount(0, 'conversations');
        $this->actingAs($second);
        $this->getJson('/api/chats')->assertOk()->assertJsonCount(1, 'conversations');
    }

    public function test_a_chat_can_go_without_becoming_a_lead(): void
    {
        $brand = $this->brand();
        $rep = $this->user(Role::FrontSales, [$brand]);
        [$conversation] = $this->chat($brand);

        $this->actingAs($this->user(Role::Admin, [$brand]));
        $this->postJson("/api/chats/{$conversation->id}/transfer", ['user_id' => $rep->id])->assertOk();
        $this->assertNull($conversation->fresh()->lead_id);
        $this->assertSame(0, Lead::count());
    }

    public function test_only_front_sales_of_the_brand_can_receive_and_only_inbox_staff_can_send(): void
    {
        $brand = $this->brand();
        $otherBrand = $this->brand();
        $agent = $this->user(Role::ChatAgent, [$brand]);
        $rep = $this->user(Role::FrontSales, [$brand]);
        [$conversation] = $this->chat($brand);

        $this->actingAs($agent);
        foreach ([
            $this->user(Role::FrontSales, [$otherBrand]),
            $this->user(Role::UpSales, [$brand]),
            $this->user(Role::Finance, [$brand]),
            $this->user(Role::FrontSales, [$brand], ['active' => false]),
        ] as $wrong) {
            $this->postJson("/api/chats/{$conversation->id}/transfer", ['user_id' => $wrong->id])->assertUnprocessable();
        }
        $this->assertNull($conversation->fresh()->transferred_at);

        $this->postJson("/api/chats/{$conversation->id}/transfer", ['user_id' => $rep->id])->assertOk();
        $this->postJson("/api/chats/{$conversation->id}/transfer", ['user_id' => $rep->id])
            ->assertUnprocessable()->assertJsonPath('error', "This chat is already with {$rep->name}.");

        // Reps can't pass chats on, and agents can't reach other brands' chats
        $this->actingAs($rep);
        $this->postJson("/api/chats/{$conversation->id}/transfer", ['user_id' => $rep->id])->assertForbidden();
        $this->actingAs($this->user(Role::ChatAgent, [$otherBrand]));
        $this->postJson("/api/chats/{$conversation->id}/transfer", ['user_id' => $rep->id])->assertNotFound();
    }

    public function test_a_rep_who_leaves_the_brand_stops_seeing_chats_sent_to_them(): void
    {
        $brand = $this->brand();
        $rep = $this->user(Role::FrontSales, [$brand]);
        [$conversation] = $this->chat($brand);
        $this->actingAs($this->user(Role::Admin, [$brand]));
        $this->postJson("/api/chats/{$conversation->id}/transfer", ['user_id' => $rep->id])->assertOk();

        $rep->brands()->detach();
        $this->assertSame(0, Conversation::query()->visibleTo($rep->fresh())->count());
    }
}
