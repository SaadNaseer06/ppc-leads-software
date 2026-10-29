<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\WebhookEvent;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TawkWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = ['id' => 'prop123', 'name' => 'Lumen Dental'];

    private function send(array $payload, string $secret = 'tawk-secret', ?string $eventId = null): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/tawk', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TAWK_SIGNATURE' => hash_hmac('sha1', $body, $secret),
            'HTTP_X_HOOK_EVENT_ID' => $eventId,
        ]), $body);
    }

    private function linkedSite(): Website
    {
        return $this->website($this->brand(), ['tawk_property_id' => 'prop123', 'tawk_webhook_secret' => 'tawk-secret']);
    }

    public function test_chat_lifecycle_creates_and_enriches_a_lead(): void
    {
        $site = $this->linkedSite();
        $visitor = ['name' => 'V1729123456789012', 'city' => 'Austin', 'country' => 'US'];

        $start = [
            'event' => 'chat:start', 'chatId' => 'c1', 'time' => '2026-10-28T10:00:00Z',
            'referrer' => 'https://lumen.test/implants?gclid=TAWKGCLID&utm_campaign=implants',
            'message' => ['text' => 'How much are implants?', 'type' => 'msg', 'sender' => ['type' => 'visitor']],
            'visitor' => $visitor, 'property' => self::PROPERTY,
        ];
        $this->send($start, eventId: 'e1')->assertOk();
        $this->send($start, eventId: 'e1')->assertOk()->assertJson(['duplicate' => true]);

        $lead = Lead::sole();
        $this->assertSame(['Chat visitor (Austin)', 'chat', 'ppc', 'TAWKGCLID'], [$lead->name, $lead->source, $lead->channel, $lead->click_id]);
        $this->assertSame($site->id, $lead->website_id);

        $this->send(['event' => 'chat:end', 'chatId' => 'c1', 'time' => '2026-10-28T10:03:00Z',
            'visitor' => [...$visitor, 'name' => 'Maria Gonzalez', 'email' => 'Maria.G@example.com'], 'property' => self::PROPERTY], eventId: 'e2')->assertOk();
        $this->send(['event' => 'chat:transcript_created', 'time' => '2026-10-28T10:04:00Z', 'property' => self::PROPERTY, 'chat' => [
            'id' => 'c1', 'visitor' => $visitor, 'messages' => [
                ['sender' => ['t' => 'v'], 'type' => 'msg', 'msg' => 'How much are implants?', 'time' => '2026-10-28T10:00:05Z'],
                ['sender' => ['t' => 'a', 'n' => 'Nina'], 'type' => 'msg', 'msg' => 'From $1,800.', 'time' => '2026-10-28T10:01:00Z'],
                ['sender' => ['t' => 'v'], 'type' => 'msg', 'msg' => 'Thanks', 'time' => '2026-10-28T10:02:00Z',
                    'attchs' => [['type' => 'file', 'content' => ['file' => ['url' => 'https://x.test/a.png', 'name' => 'a.png']]]]],
            ],
        ]], eventId: 'e3')->assertOk();

        $lead->refresh();
        $this->assertSame(['Maria Gonzalez', 'maria.g@example.com'], [$lead->name, $lead->email]);
        $conversation = Conversation::sole();
        $this->assertSame(['ended', 3, 'c1'], [$conversation->status, $conversation->message_count, $conversation->external_id]);
        $this->assertStringContainsString('[Attachment: a.png]', $conversation->messages()->orderByDesc('sent_at')->value('body'));
        $this->assertSame(1, Lead::count());
    }

    public function test_offline_ticket_becomes_an_offline_message_lead(): void
    {
        $this->linkedSite();
        $this->send(['event' => 'ticket:create', 'time' => '2026-10-28T10:00:00Z', 'property' => self::PROPERTY,
            'requester' => ['name' => 'Peter Novak', 'email' => 'peter@example.com'],
            'ticket' => ['id' => 't1', 'humanId' => 4, 'subject' => 'Saturday?', 'message' => 'Any Saturday slots?']])->assertOk();

        $lead = Lead::sole();
        $this->assertSame(['Peter Novak', 'offline', "Saturday?\n\nAny Saturday slots?"], [$lead->name, $lead->source, $lead->message]);
    }

    public function test_bad_signature_is_rejected_and_logged(): void
    {
        $this->linkedSite();
        $this->send(['event' => 'chat:start', 'chatId' => 'x', 'property' => self::PROPERTY,
            'message' => ['text' => 'spoof', 'sender' => ['type' => 'visitor']]], 'wrong-secret', 'e9')->assertUnauthorized();

        $this->assertSame(0, Lead::count());
        $this->assertSame('rejected', WebhookEvent::sole()->status);
    }

    public function test_chats_from_an_unlinked_property_are_imported_when_it_is_linked(): void
    {
        config(['ppc.tawk_webhook_secret' => 'global-secret']);
        $brand = $this->brand();
        $site = $this->website($brand);
        $this->send(['event' => 'chat:start', 'chatId' => 'u1', 'time' => '2026-10-28T10:00:00Z', 'property' => ['id' => 'newprop'],
            'message' => ['text' => 'Do you clean gutters?', 'sender' => ['type' => 'visitor']], 'visitor' => ['city' => 'Dallas']], 'global-secret')->assertOk();
        $this->assertSame(0, Lead::count());

        $admin = $this->user(Role::Admin, [$brand]);
        $this->actingAs($admin)->patchJson("/api/websites/{$site->id}", ['tawk_property_id' => 'newprop'])->assertOk();

        $this->assertSame('Do you clean gutters?', Lead::sole()->message);
        $this->assertSame($site->id, Conversation::sole()->website_id);
    }
}
