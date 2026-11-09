<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\User;
use App\Services\BrandMailbox;
use App\Services\LeadIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/** Each brand's shared mailbox: set up by a Super Admin, used by the team without seeing addresses */
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
        $this->rep = $this->user(Role::FrontSales, [$this->brand]);
    }

    private function connect(): void
    {
        $this->brand->forceFill([
            'mail_address' => 'sales@hub.test', 'mail_host' => 'mail.hub.test', 'mail_imap_port' => 993,
            'mail_smtp_port' => 465, 'mail_password' => self::PASSWORD,
        ])->save();
    }

    private function lead(): Lead
    {
        $lead = Lead::find(app(LeadIntake::class)->ingest($this->brand->id, $this->website($this->brand), LeadSource::Form, [
            'name' => 'Grace', 'email' => 'grace@client.test',
        ])['lead_id']);
        $lead->update(['assignee_id' => $this->rep->id]);

        return $lead->fresh();
    }

    private function save(array $input = [])
    {
        return $this->patchJson("/api/admin/brands/{$this->brand->id}/mailbox", [
            'address' => 'sales@hub.test', 'host' => 'mail.hub.test', 'imap_port' => 993, 'smtp_port' => 465, 'password' => self::PASSWORD, ...$input,
        ]);
    }

    public function test_a_super_admin_connects_a_mailbox_once_both_logins_work(): void
    {
        $this->mock(BrandMailbox::class, function (MockInterface $m) {
            $m->shouldReceive('problem')->once()->andReturn(null);
            $m->shouldReceive('startFromNow')->once();
            $m->shouldReceive('configured')->andReturn(true);
        });
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->save()->assertOk()->assertJsonPath('brand.connected', true)->assertJsonPath('brand.address', 'sales@hub.test');

        $stored = DB::table('brands')->where('id', $this->brand->id)->value('mail_password');
        $this->assertStringNotContainsString(self::PASSWORD, $stored, 'Stored encrypted');
        $this->assertSame(self::PASSWORD, $this->brand->fresh()->mail_password);
        $this->assertStringNotContainsString(self::PASSWORD, $this->getJson('/api/admin/mailboxes')->getContent());
    }

    public function test_settings_that_dont_log_in_are_never_saved(): void
    {
        $this->mock(BrandMailbox::class, fn (MockInterface $m) => $m->shouldReceive('problem')->andReturn('Sending failed: couldn\'t log in.'));
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->save()->assertUnprocessable()->assertJsonPath('error', "Sending failed: couldn't log in.");
        $this->assertNull($this->brand->fresh()->mail_password);
    }

    public function test_only_a_super_admin_manages_mailboxes(): void
    {
        foreach ([Role::Admin, Role::BrandManager, Role::Developer, Role::FrontSales] as $role) {
            $this->actingAs($this->user($role, [$this->brand]));
            $this->getJson('/api/admin/mailboxes')->assertForbidden();
            $this->save()->assertForbidden();
        }
    }

    public function test_a_rep_emails_a_lead_without_ever_seeing_the_address(): void
    {
        $this->connect();
        $lead = $this->lead();
        $this->mock(BrandMailbox::class, function (MockInterface $m) use ($lead) {
            $m->shouldReceive('configured')->andReturn(true);
            $m->shouldReceive('send')->once()
                ->withArgs(fn ($brand, $to, $name, $forLead, $sender, $subject, $body) => $to === 'grace@client.test' && $forLead->is($lead) && $subject === 'Your quote')
                ->andReturnUsing(fn () => LeadEmail::create([
                    'lead_id' => $lead->id, 'brand_id' => $lead->brand_id, 'user_id' => $this->rep->id, 'direction' => 'out',
                    'from_email' => 'sales@hub.test', 'to_email' => 'grace@client.test', 'subject' => 'Your quote', 'body' => 'Hi', 'sent_at' => now(),
                ]));
        });
        $this->actingAs($this->rep);

        $sent = $this->postJson("/api/leads/{$lead->id}/emails", ['subject' => 'Your quote', 'body' => 'Hi'])->assertCreated();
        $this->assertStringNotContainsString('grace@client.test', $sent->getContent());

        $page = $this->getJson("/api/leads/{$lead->id}")->assertJsonPath('mailbox', true)->assertJsonPath('emails.0.subject', 'Your quote');
        $this->assertStringNotContainsString('grace@client.test', $page->getContent());
    }

    public function test_the_lead_page_knows_when_a_mailbox_is_connected(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->rep);
        $this->getJson("/api/leads/{$lead->id}")->assertJsonPath('mailbox', false);

        $this->connect();
        $this->getJson("/api/leads/{$lead->id}")->assertJsonPath('mailbox', true);
    }

    public function test_sending_needs_a_mailbox_and_an_address(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->rep);
        $this->postJson("/api/leads/{$lead->id}/emails", ['subject' => 'Hi', 'body' => 'Hi'])->assertUnprocessable()
            ->assertJsonPath('error', 'Hub has no mailbox connected yet. A Super Admin can connect one on the Email page.');

        $this->connect();
        $lead->update(['email' => null]);
        $this->postJson("/api/leads/{$lead->id}/emails", ['subject' => 'Hi', 'body' => 'Hi'])->assertUnprocessable();

        $other = $this->user(Role::FrontSales, [$this->brand]);
        $this->actingAs($other);
        $this->postJson("/api/leads/{$lead->id}/emails", ['subject' => 'Hi', 'body' => 'Hi'])->assertNotFound();
    }

    public function test_an_invoice_is_emailed_from_the_brand_mailbox(): void
    {
        $this->connect();
        $lead = $this->lead();
        $this->actingAs($this->user(Role::Admin, [$this->brand]));
        $id = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => [['label' => 'Package', 'quantity' => 1, 'unit_price' => 100]]])
            ->assertCreated()->assertJsonPath('mailbox', true)->json('id');
        $invoice = Invoice::find($id);

        $this->mock(BrandMailbox::class, function (MockInterface $m) use ($invoice) {
            $m->shouldReceive('configured')->andReturn(true);
            $m->shouldReceive('send')->once()
                ->withArgs(fn ($brand, $to, $name, $lead, $sender, $subject, $body) => $to === 'grace@client.test' && str_contains($body, $invoice->pay_url) && str_contains($subject, $invoice->number))
                ->andReturnNull();
        });

        $this->postJson("/api/invoices/{$id}/email")->assertOk();
    }

    public function test_a_broken_mailbox_is_reported_and_never_stops_the_others(): void
    {
        $this->connect();
        $other = $this->brand();
        $other->forceFill(['mail_address' => 'a@b.test', 'mail_host' => 'mail.b.test', 'mail_password' => 'x'])->save();
        $this->mock(BrandMailbox::class, function (MockInterface $m) use ($other) {
            $m->shouldReceive('fetch')->once()->withArgs(fn (Brand $b) => $b->is($this->brand))->andThrow(new RuntimeException('Authentication failed'));
            $m->shouldReceive('fetch')->once()->withArgs(fn (Brand $b) => $b->is($other))->andReturn(2);
        });

        $this->artisan('ppc:fetch-mail')->assertSuccessful();

        $this->assertSame('Authentication failed', $this->brand->fresh()->mail_error);
        $this->assertNull($other->fresh()->mail_error);
    }

    public function test_the_brand_never_leaks_the_mail_password(): void
    {
        $this->connect();

        $this->assertArrayNotHasKey('mail_password', $this->brand->fresh()->toArray());
    }
}
