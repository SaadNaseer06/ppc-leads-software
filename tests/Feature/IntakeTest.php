<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_embed_script_payload_creates_a_ppc_lead_with_attribution(): void
    {
        $site = $this->website($this->brand());

        $this->call('POST', "/api/intake/{$site->public_key}", [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'], json_encode([
            'fields' => [
                'your-name' => 'Grace Hopper', 'your-email' => 'Grace.Hopper@Example.com', 'your-tel' => '+1 (555) 222-3333',
                'your-message' => 'Need a roof quote', '_wpcf7' => '12', 'card-number' => '4111111111111111', 'service' => 'Roof repair',
            ],
            'attribution' => [
                'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'roof-repair', 'utm_term' => 'roof leak',
                'gclid' => 'TESTGCLID', 'landing_page' => 'https://x.test/lp?gclid=TESTGCLID', 'referrer' => 'https://www.google.com/',
            ],
            'page' => ['url' => 'https://x.test/contact'],
            'form' => ['name' => 'Quote form'],
        ]))->assertCreated()->assertJson(['ok' => true, 'duplicate' => false]);

        $lead = Lead::sole();
        $this->assertSame('Grace Hopper', $lead->name);
        $this->assertSame('grace.hopper@example.com', $lead->email);
        $this->assertSame('5552223333', $lead->phone_key);
        $this->assertSame('ppc', $lead->channel);
        $this->assertSame('gclid', $lead->click_id_type);
        $this->assertSame('TESTGCLID', $lead->click_id);
        $this->assertSame('roof-repair', $lead->utm_campaign);
        $this->assertSame('Quote form', $lead->form_name);
        $this->assertSame(['service' => 'Roof repair'], $lead->metadata['fields']);
        $this->assertNotNull($site->fresh()->last_lead_at);
    }

    public function test_form_encoded_and_elementor_field_names_are_mapped(): void
    {
        $site = $this->website($this->brand());

        $this->post("/api/intake/{$site->public_key}", [
            'first_name' => 'Alan', 'last_name' => 'Turing', 'email' => 'alan@example.org', 'comments' => 'Leaking gutter',
            'utm_source' => 'bing', 'utm_medium' => 'cpc', 'msclkid' => 'MS123',
        ])->assertCreated();
        $this->post("/api/intake/{$site->public_key}", [
            'fields' => ['name' => ['value' => 'Ada Lovelace'], 'email' => ['value' => 'ada@example.net'], 'message' => ['value' => 'Consult']],
        ])->assertCreated();

        $alan = Lead::where('email', 'alan@example.org')->sole();
        $this->assertSame(['Alan Turing', 'Leaking gutter', 'msclkid', 'ppc'], [$alan->name, $alan->message, $alan->click_id_type, $alan->channel]);
        $this->assertSame('Ada Lovelace', Lead::where('email', 'ada@example.net')->value('name'));
    }

    public function test_repeat_submission_updates_the_existing_lead_and_reopens_lost_leads(): void
    {
        $site = $this->website($this->brand());
        $url = "/api/intake/{$site->public_key}";

        $first = $this->postJson($url, ['name' => 'Grace', 'email' => 'grace@example.com'])->assertCreated()->json('lead_id');
        Lead::whereKey($first)->update(['status' => 'lost']);
        $this->postJson($url, ['name' => 'Grace H', 'email' => 'GRACE@example.com', 'message' => 'Following up'])
            ->assertOk()->assertJson(['duplicate' => true, 'lead_id' => $first]);

        $lead = Lead::sole();
        $this->assertSame(2, $lead->submission_count);
        $this->assertSame('new', $lead->status);
        $this->assertSame('Following up', $lead->activities()->where('type', 'resubmitted')->value('body'));
    }

    public function test_rejections_spam_and_redirects(): void
    {
        $site = $this->website($this->brand());
        $url = "/api/intake/{$site->public_key}";

        $this->postJson('/api/intake/pk_nope', ['email' => 'a@b.co'])->assertNotFound();
        $this->postJson($url, ['name' => 'Only a name'])->assertStatus(422);
        $this->postJson($url, ['name' => 'Bot', 'email' => 'bot@spam.com', '_gotcha' => 'filled'])->assertOk();
        $this->post($url, ['name' => 'Redirect', 'email' => 'r@example.com', '_redirect' => 'https://x.test/thanks'])
            ->assertStatus(303)->assertRedirect('https://x.test/thanks');
        $this->assertSame(['r@example.com'], Lead::pluck('email')->all());

        $site->update(['active' => false]);
        $this->postJson($url, ['email' => 'paused@example.com'])->assertForbidden();
    }

    public function test_auto_assign_rotates_round_robin_between_front_sales_reps(): void
    {
        $brand = $this->brand(['auto_assign_enabled' => true]);
        $site = $this->website($brand);
        $a = $this->user(Role::FrontSales, [$brand]);
        $b = $this->user(Role::FrontSales, [$brand]);
        $this->user(Role::FrontSales, [$brand], ['active' => false]);
        $this->user(Role::FrontSales, [$this->brand()]);

        foreach (range(1, 6) as $i) {
            $this->postJson("/api/intake/{$site->public_key}", ['email' => "rr{$i}@example.com"])->assertCreated();
        }

        $this->assertSame([$a->id, $b->id, $a->id, $b->id, $a->id, $b->id], Lead::orderBy('id')->pluck('assignee_id')->all());
    }
}
