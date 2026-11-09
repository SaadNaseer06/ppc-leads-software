<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Events\ChatMessageSent;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\ContactReveal;
use App\Models\ConversationMessage;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadIntake;
use App\Services\NativeChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sales reps and chat agents see clients' contact details masked, and reveal them one lead at a time */
class ContactPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'grace.hopper@navy.example.com';

    private const PHONE = '+1 (555) 010-4821';

    private Brand $brand;

    private User $rep;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = $this->brand();
        $this->rep = $this->user(Role::FrontSales, [$this->brand]);
        $this->manager = $this->user(Role::Admin, [$this->brand]);
    }

    private function lead(array $input = [], ?User $assignee = null): Lead
    {
        $site = $this->website($this->brand);
        $lead = Lead::find(app(LeadIntake::class)->ingest($this->brand->id, $site, LeadSource::Form, [
            'name' => 'Grace Hopper', 'email' => self::EMAIL, 'phone' => self::PHONE,
            'message' => 'Call me on 0300 1234567 or write to grace.backup@example.com', ...$input,
        ])['lead_id']);
        $lead->update(['assignee_id' => ($assignee ?? $this->rep)->id]);

        return $lead->fresh();
    }

    private function assertNoContact(string $body): void
    {
        foreach ([self::EMAIL, '555) 010-4821', '1234567', 'grace.backup@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_reps_see_contacts_masked_everywhere_in_the_api(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->rep);

        $list = $this->getJson('/api/leads')->assertOk();
        $list->assertJsonPath('leads.0.email', 'g•••@navy.example.com')->assertJsonPath('leads.0.phone', '+• (•••) •••-•821');
        $this->assertNoContact($list->getContent());

        $page = $this->getJson("/api/leads/{$lead->id}")->assertOk()->assertJsonPath('lead.contact_masked', true);
        $this->assertNoContact($page->getContent());
        $this->assertStringContainsString('Call me on', $page->json('lead.message'));

        $this->getJson('/api/me')->assertJsonPath('user.can.see_contacts', false);
    }

    public function test_managers_see_everything(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->manager);

        $this->getJson('/api/leads')->assertJsonPath('leads.0.email', self::EMAIL);
        $this->getJson("/api/leads/{$lead->id}")->assertJsonPath('lead.phone', self::PHONE)->assertJsonPath('lead.contact_masked', false);
        $this->getJson('/api/me')->assertJsonPath('user.can.see_contacts', true);
    }

    public function test_form_fields_a_website_sent_are_masked_too(): void
    {
        $lead = $this->lead();
        $lead->update(['metadata' => ['fields' => ['whatsapp' => '+44 7700 900123', 'notes' => 'email me at x.y@corp.example']]]);
        $this->actingAs($this->rep);

        $body = $this->getJson("/api/leads/{$lead->id}")->getContent();

        $this->assertStringNotContainsString('7700 900123', $body);
        $this->assertStringNotContainsString('x.y@corp.example', $body);
    }

    public function test_a_reveal_shows_the_details_and_is_logged(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/reveal", ['reason' => 'Calling back'])->assertOk()
            ->assertExactJson(['email' => self::EMAIL, 'phone' => self::PHONE]);

        $reveal = ContactReveal::sole();
        $this->assertSame([$this->rep->id, $lead->id, 'Calling back'], [$reveal->user_id, $reveal->lead_id, $reveal->reason]);
    }

    public function test_reps_can_only_reveal_their_own_leads(): void
    {
        $other = $this->user(Role::FrontSales, [$this->brand]);
        $lead = $this->lead([], $other);
        $this->actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/reveal")->assertNotFound();
        $this->assertSame(0, ContactReveal::count());
    }

    public function test_the_daily_limit_counts_leads_not_clicks(): void
    {
        config(['ppc.contact_reveal_daily_limit' => 2]);
        [$a, $b, $c] = [$this->lead(), $this->lead(['email' => 'b@example.com', 'phone' => '+1 555 010 2000']), $this->lead(['email' => 'c@example.com', 'phone' => '+1 555 010 3000'])];
        $this->actingAs($this->rep);

        $this->postJson("/api/leads/{$a->id}/reveal")->assertOk();
        $this->postJson("/api/leads/{$a->id}/reveal")->assertOk();
        $this->postJson("/api/leads/{$b->id}/reveal")->assertOk();

        $this->postJson("/api/leads/{$c->id}/reveal")->assertStatus(429)
            ->assertJsonPath('error', "You've seen the contact details of 2 leads today, the daily limit. Ask a manager if you need more.");
        // Leads already seen today stay open
        $this->postJson("/api/leads/{$a->id}/reveal")->assertOk();
        $this->assertSame(1, ActivityLog::where('action', 'contact.reveal_limit')->count());

        // A new day starts fresh
        $this->travel(1)->days();
        $this->postJson("/api/leads/{$c->id}/reveal")->assertOk();
    }

    public function test_a_masked_form_never_overwrites_the_real_details(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['name' => 'Grace B. Hopper', 'email' => 'g•••@navy.example.com', 'phone' => '+• (•••) •••-•821'])->assertOk();

        $lead->refresh();
        $this->assertSame('Grace B. Hopper', $lead->name);
        $this->assertSame(self::EMAIL, $lead->email);
        $this->assertSame(self::PHONE, $lead->phone);

        // A real new address is still saved
        $this->patchJson("/api/leads/{$lead->id}", ['email' => 'new@example.com'])->assertOk();
        $this->assertSame('new@example.com', $lead->fresh()->email);
    }

    public function test_invoices_mask_the_client_and_still_bill_the_real_address(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->rep);

        // The form was filled from the masked lead
        $id = $this->postJson('/api/invoices', [
            'lead_id' => $lead->id, 'client_email' => 'g•••@navy.example.com', 'client_phone' => '+• (•••) •••-•821',
            'items' => [['label' => 'Package', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->json('id');

        $this->assertSame(self::EMAIL, Invoice::find($id)->client_email);
        $this->assertNoContact($this->getJson('/api/invoices')->getContent());

        $this->actingAs($this->manager);
        $this->getJson('/api/invoices')->assertJsonPath('invoices.0.client_email', self::EMAIL);
    }

    public function test_chat_agents_see_visitor_emails_masked_even_live(): void
    {
        $chat = app(NativeChatService::class);
        $session = $chat->session($this->website($this->brand), null, 'https://x.test/');
        $chat->updateVisitor($session['conversation'], 'Ada', 'ada.lovelace@example.com');
        $chat->visitorMessage($session['conversation'], 'Hi');

        $this->actingAs($this->user(Role::ChatAgent, [$this->brand]));
        $page = $this->getJson('/api/chats?id='.$session['conversation']->id)->assertOk();
        $this->assertStringNotContainsString('ada.lovelace@example.com', $page->getContent());
        $this->assertSame('a•••@example.com', $page->json('selected.visitor_email'));

        // The live event goes to every agent's screen, so it never carries the full address
        $event = new ChatMessageSent(ConversationMessage::latest('id')->first());
        $this->assertStringNotContainsString('ada.lovelace@example.com', json_encode($event->broadcastWith()));
    }

    public function test_managers_see_the_contact_log_for_their_brands(): void
    {
        config(['ppc.contact_reveal_daily_limit' => 1]);
        [$a, $b] = [$this->lead(), $this->lead(['email' => 'b@example.com', 'phone' => '+1 555 010 2000'])];
        $this->actingAs($this->rep);
        $this->postJson("/api/leads/{$a->id}/reveal", ['reason' => 'Follow-up'])->assertOk();
        $this->postJson("/api/leads/{$b->id}/reveal")->assertStatus(429);

        $this->getJson('/api/contact-log')->assertForbidden();

        $this->actingAs($this->manager);
        $this->getJson('/api/contact-log')->assertOk()
            ->assertJsonPath('people.0.name', $this->rep->name)
            ->assertJsonPath('people.0.today', 1)
            ->assertJsonPath('people.0.limit_hits', 1)
            ->assertJsonPath('recent.0.reason', 'Follow-up');

        $this->actingAs($this->user(Role::Admin, [$this->brand()]));
        $this->getJson('/api/contact-log')->assertOk()->assertJsonCount(0, 'people');
    }
}
