<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Http\Controllers\Api\EmailInboxController;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\User;
use App\Services\LeadIntake;
use App\Services\MailboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Emails inbox: one conversation per lead, unread for the lead's rep until they open it */
class EmailInboxTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = $this->brand(['name' => 'Hub']);
        $this->rep = $this->user(Role::FrontSales, [$this->brand], ['name' => 'Riley']);
    }

    private function lead(string $name, string $email, ?User $assignee = null): Lead
    {
        $lead = Lead::find(app(LeadIntake::class)->ingest($this->brand->id, $this->website($this->brand), LeadSource::Form, [
            'name' => $name, 'email' => $email,
        ])['lead_id']);
        $lead->update(['assignee_id' => $assignee?->id]);

        return $lead->fresh();
    }

    private function email(Lead $lead, string $direction, string $body, ?User $by = null, string $subject = 'Your quote'): LeadEmail
    {
        $email = LeadEmail::create([
            'lead_id' => $lead->id, 'brand_id' => $lead->brand_id, 'user_id' => $by?->id, 'direction' => $direction,
            'from_email' => $direction === 'in' ? $lead->email : 'riley@hub.test', 'to_email' => $direction === 'in' ? 'riley@hub.test' : $lead->email,
            'subject' => $subject, 'body' => $body, 'sent_at' => now(), 'created_at' => now(),
        ]);
        $lead->recordEmail($direction);
        $this->travel(1)->seconds();

        return $email;
    }

    public function test_a_rep_sees_only_their_own_conversations_newest_first_and_masked(): void
    {
        $grace = $this->lead('Grace', 'grace@client.test', $this->rep);
        $omar = $this->lead('Omar', 'omar@client.test', $this->rep);
        $other = $this->lead('Not yours', 'x@client.test', $this->user(Role::FrontSales, [$this->brand]));
        $this->lead('No emails', 'none@client.test', $this->rep);
        $this->email($grace, 'out', 'Here is your quote', $this->rep);
        $this->email($other, 'in', 'Hello');
        $this->email($omar, 'in', "Call me on 0300 1234567 or omar.home@mail.test\n\nOn Tue, Nov 10, 2026 at 3:19 AM Riley <riley@hub.test> wrote:\n> Here is your quote");
        $this->actingAs($this->rep);

        $res = $this->getJson('/api/emails')->assertOk()
            ->assertJsonCount(2, 'threads')
            ->assertJsonPath('threads.0.name', 'Omar')
            ->assertJsonPath('threads.0.unread', true)
            ->assertJsonPath('threads.0.needs_reply', true)
            ->assertJsonPath('threads.1.name', 'Grace')
            ->assertJsonPath('threads.1.direction', 'out')
            ->assertJsonPath('threads.1.sender', 'Riley');

        $snippet = $res->json('threads.0.snippet');
        $this->assertStringNotContainsString('wrote:', $snippet, 'quoted history is left out of the list');
        $this->assertStringNotContainsString('0300 1234567', $snippet);
        $this->assertStringNotContainsString('omar.home@mail.test', $snippet);
        $this->assertStringNotContainsString('omar@client.test', $res->getContent());
    }

    public function test_opening_a_conversation_marks_it_read_only_for_the_leads_own_rep(): void
    {
        $grace = $this->lead('Grace', 'grace@client.test', $this->rep);
        $this->email($grace, 'in', 'Hello');
        $admin = $this->user(Role::Admin, [$this->brand]);

        // Someone else looking doesn't mark it read for the rep
        $this->actingAs($admin)->getJson("/api/emails/{$grace->id}")->assertOk()->assertJsonPath('lead.name', 'Grace');
        $this->assertTrue($grace->fresh()->email_unread);

        $this->actingAs($this->rep)->getJson("/api/emails?id={$grace->id}")->assertOk()
            ->assertJsonPath('selected.emails.0.body', 'Hello')
            ->assertJsonPath('threads.0.unread', false);
        $this->assertFalse($grace->fresh()->email_unread);
        $this->assertTrue($grace->fresh()->email_needs_reply, 'still waiting for an answer');
    }

    public function test_opening_the_lead_page_marks_it_read_too_and_reading_never_touches_the_lead(): void
    {
        $grace = $this->lead('Grace', 'grace@client.test', $this->rep);
        $this->email($grace, 'in', 'Hello');
        $updated = $grace->fresh()->updated_at;
        $this->travel(5)->minutes();

        $this->actingAs($this->rep)->getJson("/api/leads/{$grace->id}")->assertOk();

        $this->assertFalse($grace->fresh()->email_unread);
        $this->assertEquals($updated, $grace->fresh()->updated_at);
    }

    public function test_an_unowned_leads_email_is_read_by_whoever_opens_it(): void
    {
        $lead = $this->lead('Grace', 'grace@client.test');
        $this->email($lead, 'in', 'Hello');
        $admin = $this->user(Role::Admin, [$this->brand]);

        $this->actingAs($admin)->getJson('/api/live')->assertJsonPath('unread_emails', 1);
        $this->actingAs($admin)->getJson("/api/emails/{$lead->id}")->assertOk();

        $this->assertFalse($lead->fresh()->email_unread);
        $this->actingAs($admin)->getJson('/api/live')->assertJsonPath('unread_emails', 0);
    }

    public function test_replying_answers_the_conversation_and_the_filters_follow(): void
    {
        $grace = $this->lead('Grace', 'grace@client.test', $this->rep);
        $omar = $this->lead('Omar', 'omar@client.test', $this->rep);
        $this->email($grace, 'in', 'Hello');
        $this->email($omar, 'in', 'Hi there');
        $this->actingAs($this->rep);

        $this->getJson('/api/live')->assertJsonPath('unread_emails', 2);
        $this->getJson('/api/emails?filter=needs_reply')->assertJsonCount(2, 'threads')->assertJsonPath('counts.needs_reply', 2);

        $this->email($omar, 'out', 'Thanks Omar', $this->rep);

        $this->getJson('/api/live')->assertJsonPath('unread_emails', 1);
        $this->getJson('/api/emails?filter=needs_reply')->assertJsonCount(1, 'threads')->assertJsonPath('threads.0.name', 'Grace');
        $this->getJson('/api/emails?filter=unread')->assertJsonCount(1, 'threads')->assertJsonPath('counts.unread', 1);
        $this->getJson('/api/emails?q=oma')->assertJsonCount(1, 'threads')->assertJsonPath('threads.0.name', 'Omar')->assertJsonPath('threads.0.count', 2);
    }

    public function test_a_rep_cant_open_someone_elses_conversation(): void
    {
        $other = $this->lead('Not yours', 'x@client.test', $this->user(Role::FrontSales, [$this->brand]));
        $this->email($other, 'in', 'Hello');

        $this->actingAs($this->rep)->getJson("/api/emails/{$other->id}")->assertNotFound();
        $this->actingAs($this->rep)->getJson("/api/emails?id={$other->id}")->assertOk()->assertJsonPath('selected', null);
        $this->assertTrue($other->fresh()->email_unread);
    }

    public function test_people_who_dont_work_leads_have_no_inbox(): void
    {
        foreach ([Role::Finance, Role::ChatAgent] as $role) {
            $this->actingAs($this->user($role, [$this->brand]))->getJson('/api/emails')->assertForbidden();
        }
    }

    public function test_mail_in_the_wrong_charset_is_filed_as_readable_text(): void
    {
        // Latin-1 / Windows-1252 bytes in an email that claims UTF-8: the database would refuse them
        $this->assertSame('Alex · Hub – café', MailboxService::utf8("Alex \xB7 Hub \x96 caf\xE9"));
        $this->assertSame('Already fine ✓ é', MailboxService::utf8('Already fine ✓ é'));
        $this->assertSame('ab', MailboxService::utf8("a\0b"));
    }

    public function test_the_list_shows_what_was_written_not_the_quoted_history(): void
    {
        $this->assertSame('Sounds good, call me at 5.', EmailInboxController::withoutQuote("Sounds good,\ncall me at 5.\n\nOn Tue, Nov 10, 2026 at 3:19 AM Alex Morgan · Hub <\nalex@hub.test> wrote:\n\n> Maxime natus\n>"));
        $this->assertSame('Yes please', EmailInboxController::withoutQuote("Yes please\n\n-----Original Message-----\nFrom: Alex"));
        $this->assertSame('No quote here', EmailInboxController::withoutQuote('No quote here'));
    }
}
