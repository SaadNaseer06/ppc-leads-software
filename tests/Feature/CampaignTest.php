<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Website;
use App\Services\LeadIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Which ad campaign a lead came from, and the names managers give campaigns */
class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = $this->brand(['name' => 'Roofs']);
        $this->site = $this->website($this->brand, ['domain' => 'roofs.test']);
    }

    /** What the site script sends for a form filled in after an ad click */
    private function submit(string $email, array $attribution): Lead
    {
        $this->call('POST', "/api/intake/{$this->site->public_key}", [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'], json_encode([
            'fields' => ['name' => 'Client', 'email' => $email],
            'attribution' => $attribution,
            'page' => ['url' => 'https://roofs.test/contact'],
        ]))->assertSuccessful();

        return Lead::where('email', $email)->sole();
    }

    public function test_google_ads_clicks_are_filed_under_their_campaign_id(): void
    {
        // Auto-tagging only: Google adds gclid and gad_campaignid, no UTM tags at all
        $lead = $this->submit('a@client.test', [
            'gclid' => 'Cj0KCQ', 'gad_source' => '1', 'gad_campaignid' => '21345678901',
            'landing_page' => 'https://roofs.test/?gad_source=1&gad_campaignid=21345678901&gclid=Cj0KCQ',
        ]);
        $this->assertSame('21345678901', $lead->campaign);
        $this->assertSame('ppc', $lead->channel);

        // An older visitor whose stored visit predates the script change: the landing page still has it
        $older = $this->submit('b@client.test', ['landing_page' => 'https://roofs.test/?gad_source=1&gad_campaignid=99887766554']);
        $this->assertSame('99887766554', $older->campaign);
        $this->assertSame('ppc', $older->channel);

        // Tagged by hand: the tag is the campaign; an unfilled {campaignid} placeholder is not an ID
        $tagged = $this->submit('c@client.test', ['utm_source' => 'bing', 'utm_medium' => 'cpc', 'utm_campaign' => 'Roof repair', 'utm_id' => '{campaignid}', 'msclkid' => 'm1']);
        $this->assertSame('Roof repair', $tagged->campaign);
    }

    public function test_a_chat_after_browsing_on_still_counts_for_the_ad(): void
    {
        $session = $this->postJson('/api/chat/session', [
            'public_key' => $this->site->public_key,
            'page_url' => 'https://roofs.test/services/gutters',
            'landing_page' => 'https://roofs.test/?gad_source=1&gad_campaignid=21345678901&gclid=Cj0',
            'referrer' => 'https://www.google.com/',
        ])->assertOk();
        $this->postJson('/api/chat/visitor', ['visitor_token' => $session['visitor_token'], 'email' => 'chat@client.test'])->assertOk();
        $this->postJson('/api/chat/messages', ['visitor_token' => $session['visitor_token'], 'body' => 'How much for gutters?'])->assertCreated();

        $this->actingAs($this->user(Role::Admin, [$this->brand]));
        $this->postJson('/api/chats/'.Conversation::sole()->id.'/convert')->assertOk();

        $lead = Lead::sole();
        $this->assertSame('21345678901', $lead->campaign);
        $this->assertSame('ppc', $lead->channel);
        $this->assertSame('gclid', $lead->click_id_type);
        $this->assertSame('https://roofs.test/services/gutters', $lead->page_url);
        $this->assertStringContainsString('gad_campaignid', $lead->landing_page);
    }

    public function test_a_returning_lead_picks_up_the_campaign_it_lacked(): void
    {
        $first = app(LeadIntake::class)->ingest($this->brand->id, $this->site, LeadSource::Form, ['email' => 'back@client.test']);
        $this->assertNull(Lead::find($first['lead_id'])->campaign);

        $this->submit('back@client.test', ['gclid' => 'g1', 'gad_campaignid' => '21345678901', 'utm_campaign' => 'Spring']);
        $lead = Lead::sole()->refresh();
        $this->assertSame('21345678901', $lead->campaign);
        $this->assertSame('Spring', $lead->utm_campaign);
        $this->assertSame('ppc', $lead->channel);
        $this->assertSame(2, $lead->submission_count);
    }

    public function test_managers_name_campaigns_and_everyone_sees_the_name(): void
    {
        $lead = $this->submit('a@client.test', ['gclid' => 'g', 'gad_campaignid' => '21345678901']);
        $this->submit('t@client.test', ['utm_campaign' => 'spring_sale']);
        $rep = $this->user(Role::FrontSales, [$this->brand]);
        $lead->update(['assignee_id' => $rep->id]);
        $manager = $this->user(Role::BrandManager, [$this->brand]);

        // Before naming, the ID still tells campaigns apart
        $this->actingAs($manager)->getJson('/api/leads')->assertOk()
            ->assertJsonPath('leads.1.campaign', 'Campaign 21345678901')
            ->assertJsonPath('leads.1.campaign_key', '21345678901');

        $this->putJson('/api/campaigns', ['brand_id' => $this->brand->id, 'campaign' => '21345678901', 'name' => '  Roof repair - Dallas '])
            ->assertOk()->assertJsonPath('name', 'Roof repair - Dallas');

        $this->getJson("/api/leads/{$lead->id}")->assertJsonPath('lead.campaign_label', 'Roof repair - Dallas')
            ->assertJsonPath('lead.campaign_platform', 'Google Ads');
        $this->actingAs($rep)->getJson('/api/leads')->assertJsonPath('leads.0.campaign', 'Roof repair - Dallas');

        // Filtering the list to one campaign
        $this->actingAs($manager)->getJson('/api/leads?campaign=21345678901')->assertJsonPath('total', 1)
            ->assertJsonPath('leads.0.id', $lead->id);

        $page = $this->getJson('/api/campaigns?days=30')->assertOk();
        $this->assertEqualsCanonicalizing(
            ['Roof repair - Dallas', 'spring_sale'],
            array_column($page->json('campaigns'), 'label'),
        );

        // An empty name clears it
        $this->putJson('/api/campaigns', ['brand_id' => $this->brand->id, 'campaign' => '21345678901', 'name' => ''])->assertOk();
        $this->getJson("/api/leads/{$lead->id}")->assertJsonPath('lead.campaign_label', 'Campaign 21345678901');
    }

    public function test_naming_is_limited_to_managers_of_the_brand_and_real_campaigns(): void
    {
        $this->submit('a@client.test', ['gad_campaignid' => '21345678901']);
        $body = ['brand_id' => $this->brand->id, 'campaign' => '21345678901', 'name' => 'Hijack'];

        $this->actingAs($this->user(Role::FrontSales, [$this->brand]))->putJson('/api/campaigns', $body)->assertForbidden();
        $this->actingAs($this->user(Role::FrontSales, [$this->brand]))->getJson('/api/campaigns')->assertForbidden();
        $this->actingAs($this->user(Role::BrandManager, [$this->brand(['name' => 'Other'])]))->putJson('/api/campaigns', $body)->assertNotFound();
        $this->actingAs($this->user(Role::BrandManager, [$this->brand]))
            ->putJson('/api/campaigns', [...$body, 'campaign' => '1111111111'])->assertNotFound();

        $this->assertDatabaseCount('ad_campaigns', 0);
        // Another brand's manager doesn't see this brand's campaigns
        $this->actingAs($this->user(Role::BrandManager, [$this->brand(['name' => 'Third'])]))->getJson('/api/campaigns')
            ->assertOk()->assertJsonCount(0, 'campaigns');
    }

    public function test_sales_reports_group_by_campaign_under_its_name(): void
    {
        $lead = $this->submit('a@client.test', ['gclid' => 'g', 'gad_campaignid' => '21345678901']);
        $admin = $this->user(Role::SuperAdmin);
        Invoice::create([
            'brand_id' => $this->brand->id, 'lead_id' => $lead->id, 'created_by_id' => $admin->id, 'number' => 'INV-1',
            'client_name' => 'Client', 'amount' => 500, 'subtotal' => 500, 'currency' => 'USD', 'status' => 'sent',
            'due_date' => now()->addWeek()->toDateString(), 'pay_token' => 'tok_'.str_repeat('a', 30),
        ]);
        $this->actingAs($admin)->putJson('/api/campaigns', ['brand_id' => $this->brand->id, 'campaign' => '21345678901', 'name' => 'Dallas roofs'])->assertOk();

        $this->getJson('/api/reports/sales')->assertOk()
            ->assertJsonPath('by_campaign.0.name', 'Dallas roofs')
            ->assertJsonPath('by_campaign.0.invoiced', 500);
    }
}
