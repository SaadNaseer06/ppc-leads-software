<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PaymentTransaction;
use App\Services\LeadIntake;
use App\Services\StripePayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Invoices with no lead, deleting invoices, and telling the team why card payments are off */
class InvoiceDirectTest extends TestCase
{
    use RefreshDatabase;

    private array $items = [['label' => 'Design', 'quantity' => 1, 'unit_price' => 100]];

    private function lead(Brand $brand, array $input = []): Lead
    {
        $site = $this->website($brand);

        return Lead::find(app(LeadIntake::class)->ingest($brand->id, $site, LeadSource::Form, ['email' => fake()->unique()->safeEmail(), ...$input])['lead_id']);
    }

    private function direct(Brand $brand, array $input = []): Invoice
    {
        $id = $this->postJson('/api/invoices', ['brand_id' => $brand->id, 'client_name' => 'Walk-in Client', 'items' => $this->items, ...$input])
            ->assertCreated()->json('id');

        return Invoice::find($id);
    }

    public function test_an_invoice_needs_no_lead(): void
    {
        $brand = $this->brand(['name' => 'Hub', 'merchant_name' => 'Hub LLC']);
        $this->actingAs($this->user(Role::Admin, [$brand]));

        $invoice = $this->direct($brand, [
            'client_name' => 'Ada Client', 'client_email' => 'ada@client.test', 'client_company' => 'Ada Co',
            'client_phone' => '+1 555 0100', 'client_address' => '1 Main St',
        ]);

        $this->assertNull($invoice->lead_id);
        $this->assertSame('Hub LLC', $invoice->merchant_name);
        $this->assertSame(1, Invoice::count());

        foreach (['Ada Client', 'ada@client.test', 'Ada Co', '+1 555 0100', '1 Main St'] as $detail) {
            $this->get("/pay/{$invoice->pay_token}")->assertOk()->assertSee($detail);
        }
        $this->get("/pay/{$invoice->pay_token}/pdf")->assertOk();
        $this->getJson('/api/invoices')->assertOk()->assertJsonPath('invoices.0.lead', null)->assertJsonPath('invoices.0.client_name', 'Ada Client');
    }

    public function test_one_brand_is_automatic_and_several_must_be_chosen(): void
    {
        $brand = $this->brand();
        $other = $this->brand();
        $input = ['client_name' => 'Ada', 'items' => $this->items];

        $this->actingAs($this->user(Role::FrontSales, [$brand]));
        $id = $this->postJson('/api/invoices', $input)->assertCreated()->json('id');
        $this->assertSame($brand->id, Invoice::find($id)->brand_id);
        $this->getJson('/api/invoices')->assertJsonCount(1, 'brands');

        $this->actingAs($this->user(Role::Admin, [$brand, $other]));
        $this->postJson('/api/invoices', $input)->assertUnprocessable()->assertJsonPath('error', 'Choose which brand this invoice is from');
        $this->getJson('/api/invoices')->assertJsonCount(2, 'brands');
        $this->postJson('/api/invoices', [...$input, 'brand_id' => $other->id])->assertCreated();
    }

    public function test_nobody_invoices_for_a_brand_they_dont_have(): void
    {
        $brand = $this->brand();
        $other = $this->brand();
        $this->actingAs($this->user(Role::FrontSales, [$brand]));

        $this->postJson('/api/invoices', ['brand_id' => $other->id, 'client_name' => 'Ada', 'items' => $this->items])->assertForbidden();
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_direct_invoice_needs_a_client_name(): void
    {
        $brand = $this->brand();
        $this->actingAs($this->user(Role::Admin, [$brand]));

        $this->postJson('/api/invoices', ['brand_id' => $brand->id, 'items' => $this->items])
            ->assertUnprocessable()->assertJsonPath('error', "Enter the client's name");
    }

    public function test_an_issued_invoice_keeps_who_it_billed(): void
    {
        $brand = $this->brand();
        $lead = $this->lead($brand, ['name' => 'Website visitor']);
        $this->actingAs($this->user(Role::Admin, [$brand]));

        // The form lets the rep put the real name on a lead's invoice
        $id = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'client_name' => 'Grace Hopper', 'items' => $this->items])->assertCreated()->json('id');
        $invoice = Invoice::find($id);
        $lead->update(['name' => 'Renamed', 'email' => 'new@client.test']);

        $this->assertSame('Grace Hopper', $invoice->fresh()->clientName());
        $this->get("/pay/{$invoice->pay_token}")->assertSee('Grace Hopper')->assertDontSee('Renamed');

        // Blank details fall back to the lead
        $id = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => $this->items])->assertCreated()->json('id');
        $this->assertSame('Renamed', Invoice::find($id)->client_name);
        $this->assertSame('new@client.test', Invoice::find($id)->client_email);
    }

    public function test_direct_invoices_count_in_the_reports(): void
    {
        $brand = $this->brand();
        $this->actingAs($this->user(Role::Admin, [$brand]));
        $this->direct($brand);
        $invoice = Invoice::first();
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 40, 'method' => 'cash'])->assertCreated();

        $report = $this->getJson('/api/reports/sales')->assertOk();
        $report->assertJsonPath('totals.invoices', 1)->assertJsonPath('by_channel.0.id', null);
        $this->assertEquals(100, $report->json('totals.invoiced'));
        $this->assertEquals(40, $report->json('totals.collected'));

        $this->assertStringContainsString('Walk-in Client', $this->get('/api/reports/sales/export')->streamedContent());

        $this->actingAs($this->user(Role::Finance));
        $this->getJson('/api/finance')->assertOk()->assertJsonPath('transactions.0.invoice.client_name', 'Walk-in Client');
    }

    public function test_the_people_who_run_a_brand_can_delete_its_invoices(): void
    {
        $brand = $this->brand();
        $other = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));
        $make = fn () => $this->direct($brand);
        $first = $make();
        $second = $make();
        $third = $make();

        $this->actingAs($this->user(Role::Finance));
        $this->deleteJson("/api/invoices/{$first->id}")->assertForbidden();
        $this->actingAs($this->user(Role::FrontSales, [$brand]));
        $this->deleteJson("/api/invoices/{$first->id}")->assertForbidden();
        $this->actingAs($this->user(Role::Admin, [$other]));
        $this->deleteJson("/api/invoices/{$first->id}")->assertNotFound();
        $this->assertSame(3, Invoice::count());

        $this->actingAs($this->user(Role::Admin, [$brand]));
        $this->deleteJson("/api/invoices/{$first->id}")->assertOk();
        $this->actingAs($this->user(Role::BrandManager, [$brand]));
        $this->deleteJson("/api/invoices/{$second->id}")->assertOk();
        $this->actingAs($this->user(Role::SuperAdmin));
        $this->deleteJson("/api/invoices/{$third->id}")->assertOk();
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_deleted_invoice_is_gone_for_the_client_and_the_books(): void
    {
        $brand = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));
        $invoice = $this->direct($brand);
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 100, 'method' => 'cash'])->assertCreated();

        // Money was recorded, so it takes a deliberate confirmation
        $this->deleteJson("/api/invoices/{$invoice->id}")->assertUnprocessable()->assertJsonPath('requires_force', true);
        $this->assertSame(1, Invoice::count());

        $this->deleteJson("/api/invoices/{$invoice->id}?force=1")->assertOk();

        $this->get("/pay/{$invoice->pay_token}")->assertNotFound();
        $this->get("/pay/{$invoice->pay_token}/pdf")->assertNotFound();
        $this->getJson('/api/invoices')->assertJsonCount(0, 'invoices');
        $this->getJson('/api/finance')->assertJsonCount(0, 'transactions')->assertJsonCount(0, 'invoices');
        $this->getJson('/api/reports/sales')->assertJsonPath('totals.invoices', 0);
        $this->getJson('/api/reports/sales-by-brand')->assertJsonPath('reports.0.paid_amount', 0);

        // Still on record for an audit
        $this->assertNotNull(Invoice::withTrashed()->find($invoice->id));
        $this->assertSame(1, PaymentTransaction::count());
    }

    public function test_a_number_is_never_issued_twice(): void
    {
        $brand = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));
        $deleted = $this->direct($brand);
        $this->deleteJson("/api/invoices/{$deleted->id}")->assertOk();

        $this->assertNotSame($deleted->number, $this->direct($brand)->number);
    }

    public function test_deleting_or_cancelling_stops_an_open_card_payment(): void
    {
        config(['services.stripe.secret' => 'sk_test_abc', 'services.stripe.publishable' => 'pk_test_abc']);
        $brand = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));
        Http::fake(['*/v1/payment_intents/*' => fn (HttpRequest $r) => Http::response(['id' => basename($r->url()), 'status' => 'requires_payment_method'])]);

        $deleted = $this->direct($brand);
        $deleted->update(['stripe_intent_id' => 'pi_1']);
        $this->deleteJson("/api/invoices/{$deleted->id}")->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/payment_intents/pi_1/cancel'));

        $cancelled = $this->direct($brand);
        $cancelled->update(['stripe_intent_id' => 'pi_2']);
        $this->patchJson("/api/invoices/{$cancelled->id}", ['status' => 'cancelled'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/payment_intents/pi_2/cancel'));
    }

    public function test_stripe_being_down_never_blocks_a_delete(): void
    {
        config(['services.stripe.secret' => 'sk_test_abc', 'services.stripe.publishable' => 'pk_test_abc']);
        $brand = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));
        $invoice = $this->direct($brand);
        $invoice->update(['stripe_intent_id' => 'pi_1']);
        Http::fake(['*' => Http::response([], 500)]);

        $this->deleteJson("/api/invoices/{$invoice->id}")->assertOk();
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_lead_and_a_brand_can_still_be_deleted_after_their_invoice(): void
    {
        $brand = $this->brand();
        $lead = $this->lead($brand);
        $this->actingAs($this->user(Role::SuperAdmin));
        $id = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => $this->items])->assertCreated()->json('id');

        $this->deleteJson("/api/invoices/{$id}")->assertOk();
        $this->assertSame('invoice_deleted', $lead->activities()->latest('id')->first()->type);

        $this->deleteJson("/api/leads/{$lead->id}")->assertOk();
        $this->assertNull(Lead::find($lead->id));

        $this->deleteJson("/api/admin/brands/{$brand->id}?force=1")->assertOk();
        $this->assertNull(Brand::find($brand->id));
        $this->assertSame(0, Invoice::withTrashed()->count());
    }

    private function envFile(string $contents): void
    {
        $dir = sys_get_temp_dir().'/ppc-env-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/.env", $contents);
        $this->app->useEnvironmentPath($dir);
    }

    public function test_the_team_is_told_exactly_why_cards_are_off(): void
    {
        $stripe = app(StripePayments::class);
        config(['services.stripe.secret' => null, 'services.stripe.publishable' => null, 'services.stripe.webhook_secret' => null]);

        // Nothing set anywhere
        $this->envFile('APP_NAME=x');
        $status = $stripe->status();
        $this->assertFalse($status['card_payments']);
        $this->assertFalse($status['needs_reload']);
        $this->assertContains('STRIPE_PUBLISHABLE_KEY', $status['missing']);
        $this->assertContains('STRIPE_SECRET_KEY', $status['missing']);

        // Only the secret key added: the publishable one is what's missing
        $this->envFile("STRIPE_SECRET_KEY=sk_test_x\n");
        $this->assertContains('STRIPE_PUBLISHABLE_KEY', $stripe->status()['missing']);
        $this->assertNotContains('STRIPE_SECRET_KEY', $stripe->status()['missing']);

        // Keys in .env, but the running app has old cached settings
        $this->envFile("STRIPE_SECRET_KEY=sk_test_x\nSTRIPE_PUBLISHABLE_KEY=pk_test_x\n");
        $this->assertTrue($stripe->status()['needs_reload']);

        // Swapped, and mixed test and live keys, would only ever fail for the client
        config(['services.stripe.secret' => 'pk_test_x', 'services.stripe.publishable' => 'sk_test_x']);
        $this->assertFalse($stripe->configured());
        $this->assertStringContainsString('swapped', $stripe->status()['problem']);

        config(['services.stripe.secret' => 'sk_live_x', 'services.stripe.publishable' => 'pk_test_x']);
        $this->assertFalse($stripe->configured());
        $this->assertStringContainsString('same mode', $stripe->status()['problem']);

        config(['services.stripe.secret' => 'sk_live_x', 'services.stripe.publishable' => 'pk_live_x', 'services.stripe.webhook_secret' => 'whsec_x']);
        $this->envFile("STRIPE_SECRET_KEY=sk_live_x\nSTRIPE_PUBLISHABLE_KEY=pk_live_x\nSTRIPE_WEBHOOK_SECRET=whsec_x\n");
        $status = $stripe->status();
        $this->assertTrue($status['card_payments']);
        $this->assertSame('live', $status['mode']);
        $this->assertFalse($status['needs_reload']);
    }

    public function test_key_values_never_reach_the_browser(): void
    {
        config(['services.stripe.secret' => 'sk_test_supersecret', 'services.stripe.publishable' => 'pk_test_abc', 'services.stripe.webhook_secret' => 'whsec_hidden']);
        $brand = $this->brand();
        $this->actingAs($this->user(Role::Admin, [$brand]));

        $body = $this->getJson('/api/invoices')->assertOk()->assertJsonPath('billing.card_payments', true)->getContent();

        $this->assertStringNotContainsString('supersecret', $body);
        $this->assertStringNotContainsString('whsec_hidden', $body);
    }

    public function test_only_a_super_admin_reloads_the_settings_and_only_when_needed(): void
    {
        config(['services.stripe.secret' => null, 'services.stripe.publishable' => null]);
        Artisan::shouldReceive('call')->once()->with('config:cache')->andReturn(0);

        $this->actingAs($this->user(Role::Admin, [$this->brand()]));
        $this->postJson('/api/billing/reload-settings')->assertForbidden();

        $this->actingAs($this->user(Role::SuperAdmin));
        $this->envFile('APP_NAME=x');
        $this->postJson('/api/billing/reload-settings')->assertUnprocessable();

        $this->envFile("STRIPE_SECRET_KEY=sk_test_x\nSTRIPE_PUBLISHABLE_KEY=pk_test_x\n");
        $this->postJson('/api/billing/reload-settings')->assertOk();
    }
}
