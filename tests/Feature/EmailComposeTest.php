<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\LeadEmailAttachment;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\EmailHtml;
use App\Services\LeadDeletion;
use App\Services\LeadIntake;
use App\Services\MailboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** Writing emails with formatting and files, and opening the files clients send */
class EmailComposeTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private User $rep;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(LeadEmailAttachment::DISK);
        $this->brand = $this->brand(['name' => 'Hub']);
        $this->rep = $this->user(Role::FrontSales, [$this->brand], ['name' => 'Riley']);
        Mailbox::create([
            'brand_id' => $this->brand->id, 'user_id' => $this->rep->id, 'address' => 'riley@hub.test', 'host' => 'mail.hub.test',
            'imap_port' => 993, 'smtp_port' => 465, 'password' => 'secret-pw',
        ]);
        $this->lead = Lead::find(app(LeadIntake::class)->ingest($this->brand->id, $this->website($this->brand), LeadSource::Form, [
            'name' => 'Grace', 'email' => 'grace@client.test',
        ])['lead_id']);
        $this->lead->update(['assignee_id' => $this->rep->id]);

        // Real messages are built, then "sent" into memory instead of over SMTP
        $this->partialMock(MailboxService::class, function (MockInterface $m) {
            $m->shouldAllowMockingProtectedMethods();
            $m->shouldReceive('mailer')->andReturn(Mail::mailer('array'));
            $m->shouldReceive('copyToSent')->andReturnNull();
        });
    }

    /** @return list<Email> */
    private function sent(): array
    {
        return array_values(array_map(fn ($m) => $m->getOriginalMessage(), iterator_to_array(Mail::mailer('array')->getSymfonyTransport()->messages())));
    }

    private function send(array $data)
    {
        return $this->post("/api/leads/{$this->lead->id}/emails", $data, ['Accept' => 'application/json']);
    }

    public function test_a_formatted_email_goes_out_as_html_and_text_with_its_files(): void
    {
        $this->actingAs($this->rep);
        $res = $this->send([
            'subject' => 'Your quote',
            'html' => '<p>Hi <strong>Grace</strong>,</p><ul><li><p>Editing</p></li><li><p>Cover</p></li></ul><p>See <a href="https://hub.test/plans" onclick="steal()">our plans</a><script>steal()</script><img src="https://track.test/p.gif"></p>',
            'files' => [UploadedFile::fake()->create('quote.pdf', 300, 'application/pdf'), UploadedFile::fake()->image('cover.png')],
        ])->assertCreated();

        $email = LeadEmail::with('attachments')->sole();
        $this->assertStringContainsString('<strong>Grace</strong>', $email->body_html);
        foreach (['script', 'onclick', 'track.test', '<img'] as $gone) {
            $this->assertStringNotContainsString($gone, $email->body_html);
        }
        $this->assertSame("Hi Grace,\n\n• Editing\n• Cover\n\nSee our plans (https://hub.test/plans)", $email->body);
        $this->assertSame(['quote.pdf', 'cover.png'], $email->attachments->pluck('name')->all());
        $this->assertSame('application/pdf', $email->attachments[0]->mime);
        foreach ($email->attachments as $file) {
            Storage::disk(LeadEmailAttachment::DISK)->assertExists($file->path);
        }
        $res->assertJsonPath('email.attachments.0.name', 'quote.pdf')->assertJsonPath('email.attachments.0.url', '/email-attachments/'.$email->attachments[0]->id);

        [$message] = $this->sent();
        $this->assertStringContainsString('<strong>Grace</strong>', $message->getHtmlBody());
        $this->assertStringContainsString('font-family', $message->getHtmlBody());
        $this->assertSame($email->body, $message->getTextBody());
        $this->assertSame(['quote.pdf', 'cover.png'], array_map(fn ($a) => $a->getFilename(), $message->getAttachments()));
        $this->assertSame('grace@client.test', $message->getTo()[0]->getAddress());
    }

    public function test_plain_text_still_works_and_files_alone_are_enough(): void
    {
        $this->actingAs($this->rep);
        $this->postJson("/api/leads/{$this->lead->id}/emails", ['subject' => 'Hello', 'body' => 'Just text'])->assertCreated();
        $this->send(['subject' => 'The file', 'html' => '<p></p>', 'files' => [UploadedFile::fake()->create('brief.docx', 20)]])->assertCreated();

        [$plain, $fileOnly] = $this->sent();
        $this->assertNull($plain->getHtmlBody());
        $this->assertSame('Just text', $plain->getTextBody());
        $this->assertNull(LeadEmail::where('subject', 'The file')->value('body_html'));
        $this->assertCount(1, $fileOnly->getAttachments());

        $this->send(['subject' => 'Empty', 'html' => '<p> </p>'])->assertStatus(422)->assertJsonPath('error', 'Write a message');
    }

    public function test_files_mail_providers_refuse_are_turned_away(): void
    {
        $this->actingAs($this->rep);
        $error = $this->send(['subject' => 'Tool', 'html' => '<p>Here</p>', 'files' => [UploadedFile::fake()->create('setup.exe', 10)]])
            ->assertStatus(422)->json('error');
        $this->assertStringContainsString('setup.exe', $error);
        $this->assertStringContainsString('Zip it', $error);

        $tooMany = array_map(fn ($i) => UploadedFile::fake()->create("f{$i}.pdf", 1), range(1, 11));
        $this->send(['subject' => 'Lots', 'html' => '<p>Here</p>', 'files' => $tooMany])->assertStatus(422);

        $this->assertSame(0, LeadEmail::count());
        $this->assertSame([], $this->sent());
        $this->assertSame([], Storage::disk(LeadEmailAttachment::DISK)->allFiles());
    }

    public function test_reps_see_the_formatted_email_with_client_details_masked(): void
    {
        $this->actingAs($this->rep);
        $this->send(['subject' => 'Details', 'html' => '<p>We have you as <a href="mailto:grace@client.test">grace@client.test</a>, phone 0300 1234567</p>'])->assertCreated();

        $html = $this->getJson("/api/leads/{$this->lead->id}")->json('emails.0.html');
        $this->assertStringNotContainsString('grace@client.test', $html);
        $this->assertStringNotContainsString('1234567', $html);
        $this->assertStringContainsString('<a href=', $html);

        $admin = $this->user(Role::SuperAdmin);
        $this->assertStringContainsString('1234567', $this->actingAs($admin)->getJson("/api/leads/{$this->lead->id}")->json('emails.0.html'));
    }

    public function test_attachments_open_only_for_people_who_can_see_the_lead(): void
    {
        $this->actingAs($this->rep);
        $this->send([
            'subject' => 'Files', 'html' => '<p>Two files</p>',
            'files' => [UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'), UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>')],
        ])->assertCreated();
        [$pdf, $page] = LeadEmailAttachment::orderBy('id')->get()->all();

        $this->get("/email-attachments/{$pdf->id}")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename=quote.pdf');
        // Anything that could run as a page on this site downloads instead
        $this->get("/email-attachments/{$page->id}")->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('Content-Disposition', 'attachment; filename=page.html');

        $other = $this->user(Role::FrontSales, [$this->brand]);
        $this->actingAs($other)->get("/email-attachments/{$pdf->id}")->assertNotFound();
        // Not allowed: back to the dashboard, as with any page the role can't use
        $this->actingAs($this->user(Role::Finance, [$this->brand]))->get("/email-attachments/{$pdf->id}")->assertRedirect('/dashboard');
    }

    public function test_deleting_the_lead_deletes_its_files(): void
    {
        $this->actingAs($this->rep);
        $this->send(['subject' => 'Files', 'html' => '<p>File</p>', 'files' => [UploadedFile::fake()->create('quote.pdf', 10)]])->assertCreated();
        $path = LeadEmailAttachment::value('path');

        app(LeadDeletion::class)->deleteOne($this->lead, $this->user(Role::SuperAdmin));

        Storage::disk(LeadEmailAttachment::DISK)->assertMissing($path);
        $this->assertSame(0, LeadEmailAttachment::count());
    }

    public function test_the_plain_text_version_reads_well(): void
    {
        $this->assertSame("1. First\n2. Second\n\nMail me@hub.test\nBye", EmailHtml::text(EmailHtml::clean('<ol><li><p>First</p></li><li><p>Second</p></li></ol><p>Mail <a href="mailto:me@hub.test">me@hub.test</a><br>Bye</p>')));
        $this->assertSame('', EmailHtml::text(EmailHtml::clean('<script>x</script><p></p>')));
        $this->assertSame('cover.png', LeadEmailAttachment::cleanName('C:\\fakepath\\cover.png'));
        $this->assertSame('a b.pdf', LeadEmailAttachment::cleanName("a\x00\"b.pdf"));
    }
}
