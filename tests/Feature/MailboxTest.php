<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\LeadIntake;
use App\Services\MailboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/** Mailboxes on each brand's domain, one per person: set up by a Super Admin, used without seeing addresses */
class MailboxTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'mailbox-secret-pw';

    private Brand $brand;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = $this->brand(['name' => 'Hub']);
        $this->rep = $this->user(Role::FrontSales, [$this->brand], ['name' => 'Riley']);
    }

    private function mailbox(?User $owner, string $address): Mailbox
    {
        return Mailbox::create([
            'brand_id' => $this->brand->id, 'user_id' => $owner?->id, 'address' => $address, 'host' => 'mail.hub.test',
            'imap_port' => 993, 'smtp_port' => 465, 'password' => self::PASSWORD,
        ]);
    }

    private function lead(): Lead
    {
        $lead = Lead::find(app(LeadIntake::class)->ingest($this->brand->id, $this->website($this->brand), LeadSource::Form, [
            'name' => 'Grace', 'email' => 'grace@client.test',
        ])['lead_id']);
        $lead->update(['assignee_id' => $this->rep->id]);

        return $lead->fresh();
    }

    private function add(array $input = [])
    {
        return $this->postJson('/api/admin/mailboxes', [
            'brand_id' => $this->brand->id, 'user_id' => $this->rep->id, 'address' => 'riley@hub.test', 'host' => 'mail.hub.test',
            'imap_port' => 993, 'smtp_port' => 465, 'password' => self::PASSWORD, ...$input,
        ]);
    }

    private function loginsWork(): void
    {
        $this->mock(MailboxService::class, function (MockInterface $m) {
            $m->shouldReceive('problem')->andReturn(null);
            $m->shouldReceive('startFromNow');
        });
    }

    public function test_a_super_admin_gives_a_rep_their_own_mailbox(): void
    {
        $this->loginsWork();
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->add()->assertCreated()->assertJsonPath('mailbox.owner', 'Riley')->assertJsonPath('mailbox.address', 'riley@hub.test');

        $stored = DB::table('mailboxes')->value('password');
        $this->assertStringNotContainsString(self::PASSWORD, $stored, 'Stored encrypted');
        $this->assertSame(self::PASSWORD, Mailbox::sole()->password);
        $this->assertStringNotContainsString(self::PASSWORD, $this->getJson('/api/admin/mailboxes')->getContent());
    }

    public function test_one_mailbox_per_person_per_brand_and_only_for_their_brands(): void
    {
        $this->loginsWork();
        $this->actingAs($this->user(Role::SuperAdmin));
        $this->add()->assertCreated();

        $this->add(['address' => 'riley2@hub.test'])->assertUnprocessable()->assertJsonPath('error', 'Riley already has a mailbox on Hub. Edit that one instead.');
        $this->add(['user_id' => null, 'address' => 'riley@hub.test'])->assertUnprocessable();

        $outsider = $this->user(Role::FrontSales, [$this->brand()], ['name' => 'Sam']);
        $this->add(['user_id' => $outsider->id, 'address' => 'sam@hub.test'])->assertUnprocessable()
            ->assertJsonPath('error', "Sam doesn't work on Hub. Add them to the brand on the Team page first.");

        // The brand's shared mailbox sits alongside
        $this->add(['user_id' => null, 'address' => 'sales@hub.test'])->assertCreated()->assertJsonPath('mailbox.owner', null);
    }

    public function test_settings_that_dont_log_in_are_never_saved(): void
    {
        $this->mock(MailboxService::class, fn (MockInterface $m) => $m->shouldReceive('problem')->andReturn("Sending failed: couldn't log in."));
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->add()->assertUnprocessable()->assertJsonPath('error', "Sending failed: couldn't log in.");
        $this->assertSame(0, Mailbox::count());
    }

    public function test_only_a_super_admin_manages_mailboxes(): void
    {
        foreach ([Role::Admin, Role::BrandManager, Role::Developer, Role::FrontSales] as $role) {
            $this->actingAs($this->user($role, [$this->brand]));
            $this->getJson('/api/admin/mailboxes')->assertForbidden();
            $this->add()->assertForbidden();
        }
    }

    public function test_each_person_sends_from_their_own_address_or_else_the_brands(): void
    {
        $own = $this->mailbox($this->rep, 'riley@hub.test');
        $shared = $this->mailbox(null, 'sales@hub.test');
        $other = $this->user(Role::FrontSales, [$this->brand]);

        $this->assertTrue(Mailbox::forSender($this->brand->id, $this->rep)->is($own));
        $this->assertTrue(Mailbox::forSender($this->brand->id, $other)->is($shared));

        $shared->delete();
        $this->assertNull(Mailbox::forSender($this->brand->id, $other));
        $this->assertNull(Mailbox::forSender($this->brand()->id, $this->rep), "Riley's mailbox is only for Hub");
    }

    public function test_the_lead_page_shows_which_address_the_rep_sends_from(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->rep);
        $this->getJson("/api/leads/{$lead->id}")->assertJsonPath('mailbox', null);

        $this->mailbox($this->rep, 'riley@hub.test');
        $this->getJson("/api/leads/{$lead->id}")->assertJsonPath('mailbox', 'riley@hub.test');
    }

    public function test_a_rep_emails_a_lead_from_their_mailbox_without_seeing_the_address(): void
    {
        $own = $this->mailbox($this->rep, 'riley@hub.test');
        $lead = $this->lead();
        $this->mock(MailboxService::class, function (MockInterface $m) use ($lead, $own) {
            $m->shouldReceive('send')->once()
                ->withArgs(fn ($mailbox, $to, $name, $forLead, $sender, $subject) => $mailbox->is($own) && $to === 'grace@client.test' && $forLead->is($lead) && $subject === 'Your quote')
                ->andReturnUsing(fn () => LeadEmail::create([
                    'lead_id' => $lead->id, 'brand_id' => $lead->brand_id, 'user_id' => $this->rep->id, 'mailbox_id' => $own->id, 'direction' => 'out',
                    'from_email' => 'riley@hub.test', 'to_email' => 'grace@client.test', 'subject' => 'Your quote', 'body' => 'Hi', 'sent_at' => now(),
                ]));
        });
        $this->actingAs($this->rep);

        $sent = $this->postJson("/api/leads/{$lead->id}/emails", ['subject' => 'Your quote', 'body' => 'Hi'])->assertCreated();
        $this->assertStringNotContainsString('grace@client.test', $sent->getContent());

        $page = $this->getJson("/api/leads/{$lead->id}")->assertJsonPath('emails.0.subject', 'Your quote');
        $this->assertStringNotContainsString('grace@client.test', $page->getContent());
    }

    public function test_sending_needs_a_mailbox_and_an_address(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->rep);
        $this->postJson("/api/leads/{$lead->id}/emails", ['subject' => 'Hi', 'body' => 'Hi'])->assertUnprocessable()
            ->assertJsonPath('error', "You don't have a mailbox on Hub yet. A Super Admin can connect one on the Mailboxes page.");

        $this->mailbox($this->rep, 'riley@hub.test');
        $lead->update(['email' => null]);
        $this->postJson("/api/leads/{$lead->id}/emails", ['subject' => 'Hi', 'body' => 'Hi'])->assertUnprocessable();

        $this->actingAs($this->user(Role::FrontSales, [$this->brand]));
        $this->postJson("/api/leads/{$lead->id}/emails", ['subject' => 'Hi', 'body' => 'Hi'])->assertNotFound();
    }

    public function test_an_invoice_is_emailed_from_the_senders_mailbox(): void
    {
        $manager = $this->user(Role::Admin, [$this->brand]);
        $mine = $this->mailbox($manager, 'jordan@hub.test');
        $lead = $this->lead();
        $this->actingAs($manager);
        $id = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => [['label' => 'Package', 'quantity' => 1, 'unit_price' => 100]]])
            ->assertCreated()->assertJsonPath('mailbox', true)->json('id');
        $invoice = Invoice::find($id);

        $this->mock(MailboxService::class, function (MockInterface $m) use ($invoice, $mine) {
            $m->shouldReceive('send')->once()
                ->withArgs(fn ($mailbox, $to, $name, $lead, $sender, $subject, $body) => $mailbox->is($mine) && $to === 'grace@client.test' && str_contains($body, $invoice->pay_url))
                ->andReturnNull();
        });

        $this->postJson("/api/invoices/{$id}/email")->assertOk();
    }

    public function test_a_broken_mailbox_is_reported_and_never_stops_the_others(): void
    {
        $broken = $this->mailbox($this->rep, 'riley@hub.test');
        $working = $this->mailbox(null, 'sales@hub.test');
        $this->mock(MailboxService::class, function (MockInterface $m) use ($broken, $working) {
            $m->shouldReceive('fetch')->once()->withArgs(fn (Mailbox $b) => $b->is($broken))->andThrow(new RuntimeException('Authentication failed'));
            $m->shouldReceive('fetch')->once()->withArgs(fn (Mailbox $b) => $b->is($working))->andReturn(2);
        });

        $this->artisan('ppc:fetch-mail')->assertSuccessful();

        $this->assertSame('Authentication failed', $broken->fresh()->error);
        $this->assertNull($working->fresh()->error);
    }

    public function test_a_mailbox_never_leaks_its_password(): void
    {
        $this->assertArrayNotHasKey('password', $this->mailbox($this->rep, 'riley@hub.test')->toArray());
    }

    public function test_a_rep_leaving_takes_their_mailbox_with_them(): void
    {
        $this->mailbox($this->rep, 'riley@hub.test');

        $this->rep->delete();

        $this->assertSame(0, Mailbox::count());
    }
}
