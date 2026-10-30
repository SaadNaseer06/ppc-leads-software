<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Conversation;
use App\Services\NativeChatService;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class NativeChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_chat_creates_a_lead_and_survives_a_realtime_outage(): void
    {
        // Pusher down: broadcasting throws, yet the visitor and the agent can still talk
        Broadcast::extend('down', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new BroadcastException('Pusher error: cURL error 7.');
            }
        });
        config(['broadcasting.default' => 'down', 'broadcasting.connections.down' => ['driver' => 'down']]);

        $brand = $this->brand();
        $site = $this->website($brand);
        $token = $this->postJson('/api/chat/session', ['public_key' => $site->public_key, 'page_url' => 'https://x.test/?gclid=abc'])
            ->assertOk()->json('visitor_token');
        $this->postJson('/api/chat/visitor', ['visitor_token' => $token, 'name' => 'Ada', 'email' => 'ada@example.com'])->assertOk();
        $this->postJson('/api/chat/messages', ['visitor_token' => $token, 'body' => 'Hello?'])->assertCreated();

        $conversation = Conversation::firstOrFail();
        $this->assertSame('Ada', $conversation->lead->name);
        $this->assertSame('ppc', $conversation->lead->channel);

        $this->actingAs($this->user(Role::Admin, [$brand]));
        $this->postJson("/api/chats/{$conversation->id}/messages", ['body' => 'Hi Ada!'])->assertCreated();
        $this->getJson("/api/chat/messages?visitor_token={$token}")->assertOk()->assertJsonCount(2, 'messages');
        $this->getJson('/api/chats')->assertOk()->assertJsonPath('open_count', 1)->assertJsonPath('conversations.0.is_live', true);
    }

    public function test_idle_chats_are_closed_and_stop_counting_as_live(): void
    {
        $brand = $this->brand();
        $site = $this->website($brand);
        $chat = app(NativeChatService::class);
        $idle = $chat->session($site, null)['conversation'];
        $active = $chat->session($site, null)['conversation'];
        $chat->visitorMessage($idle, 'Anyone there?');
        $chat->visitorMessage($active, 'Hi');
        // Visitors who only opened the chat bubble
        $empty = $chat->session($site, null)['conversation'];
        $emptyIdle = $chat->session($site, null)['conversation'];
        $hoursAgo = now()->subHours(config('ppc.active_chat_hours') + 1);
        $idle->update(['last_message_at' => $hoursAgo]);
        $emptyIdle->update(['last_message_at' => $hoursAgo]);

        $this->actingAs($this->user(Role::Admin, [$brand]));
        // Even before the cleanup runs, a quiet chat isn't "live", and empty sessions aren't listed
        $this->getJson('/api/chats')->assertOk()->assertJsonPath('open_count', 1)->assertJsonCount(2, 'conversations');
        $this->getJson('/api/live')->assertOk()->assertJsonPath('active_chats', 1);

        $this->artisan('chats:close-idle')->expectsOutput('Closed 1 idle chat(s).')->assertSuccessful();
        $this->assertSame('ended', $idle->fresh()->status);
        $this->assertSame('open', $active->fresh()->status);
        $this->assertNull($emptyIdle->fresh());
        $this->assertNotNull($empty->fresh());
        $this->assertSame('Chat closed after inactivity', $idle->messages()->latest('id')->value('body'));
        // The inbox keeps its order: closing isn't new activity
        $this->assertTrue($idle->fresh()->last_message_at->lt(now()->subHours(config('ppc.active_chat_hours'))));
    }

    public function test_only_people_who_see_every_brand_chat_may_follow_the_inbox_feed(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'key',
            'broadcasting.connections.pusher.secret' => 'secret',
            'broadcasting.connections.pusher.app_id' => '1',
        ]);
        // Channels register on the broadcaster active at boot (null in tests), so add them to Pusher's
        require base_path('routes/channels.php');
        $brand = $this->brand();
        $auth = fn (string $channel) => $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

        $this->actingAs($this->user(Role::FrontSales, [$brand]));
        $auth("private-chats.{$brand->id}")->assertForbidden();
        $auth('private-chats.all')->assertForbidden();

        $this->actingAs($this->user(Role::Admin, [$brand]));
        $auth("private-chats.{$brand->id}")->assertOk();
        $auth('private-chats.all')->assertForbidden();

        $this->actingAs($this->user(Role::SuperAdmin));
        $auth('private-chats.all')->assertOk();
    }
}
