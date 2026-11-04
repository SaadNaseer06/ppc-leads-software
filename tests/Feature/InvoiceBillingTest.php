<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PaymentTransaction;
use App\Services\LeadIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvoiceBillingTest extends TestCase
{
    use RefreshDatabase;

    private function lead(): Lead
    {
        $brand = $this->brand(['name' => 'Apex Roofing']);
        $site = $this->website($brand);

        return Lead::find(app(LeadIntake::class)->ingest($brand->id, $site, LeadSource::Form, ['name' => 'Ada Client', 'email' => 'ada@example.com'])['lead_id']);
    }

    /** An invoice for 150.00 and the admin who raised it */
    private function invoice(): Invoice
    {
        $lead = $this->lead();
        $this->actingAs($this->user(Role::Admin, [$lead->brand]));
        $id = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => [
            ['label' => 'Roof repair', 'quantity' => 1, 'unit_price' => 150],
        ]])->assertCreated()->json('id');

        return Invoice::find($id);
    }

    private function stripeSession(Invoice $invoice, array $overrides = []): array
    {
        return [
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.test/pay/cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => 15000,
            'metadata' => ['invoice_id' => (string) $invoice->id],
            ...$overrides,
        ];
    }

    private function stripeWebhook(array $event, ?string $signature = null)
    {
        $payload = json_encode($event);
        $time = time();
        $signature ??= hash_hmac('sha256', "{$time}.{$payload}", 'whsec_test');

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    public function test_an_invoice_is_priced_from_its_items_on_the_server(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->user(Role::Admin, [$lead->brand]));

        $response = $this->postJson('/api/invoices', [
            'lead_id' => $lead->id,
            'items' => [
                ['label' => 'Website design', 'quantity' => 2, 'unit_price' => 149.99],
                ['label' => 'Hosting', 'quantity' => 1.5, 'unit_price' => 20],
            ],
            'discount' => 19.98,
            'tax_rate' => 10,
            'due_date' => now()->addDays(14)->toDateString(),
            'description' => 'Thanks for your business',
            'amount' => 1, // ignored: the items decide the total
        ])->assertCreated();

        $invoice = Invoice::find($response->json('id'));
        $this->assertSame('329.98', $invoice->subtotal);
        $this->assertSame('19.98', $invoice->discount);
        $this->assertSame('31.00', $invoice->tax_amount);
        $this->assertSame('341.00', $invoice->amount);
        $this->assertSame(299.98, $invoice->line_items[0]['amount']);
        $this->assertCount(2, $invoice->items());
        $response->assertJsonPath('pay_url', url("/pay/{$invoice->pay_token}"));
        $this->assertSame('invoice', $lead->activities()->latest('id')->first()->type);
    }

    public function test_invalid_invoices_are_refused_with_a_clear_reason(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->user(Role::Admin, [$lead->brand]));
        $item = ['label' => 'Work', 'quantity' => 1, 'unit_price' => 100];

        $this->postJson('/api/invoices', ['lead_id' => $lead->id])->assertUnprocessable();
        $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => [['label' => '', 'quantity' => 1, 'unit_price' => 5]]])
            ->assertUnprocessable()->assertJsonPath('error', 'Every item needs a description');
        $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => [[...$item, 'quantity' => 0]]])
            ->assertUnprocessable()->assertJsonPath('error', 'Quantity must be greater than zero');
        $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => [$item], 'discount' => 100.01])
            ->assertUnprocessable()->assertJsonPath('error', "The discount can't be more than the subtotal");
        $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => [[...$item, 'unit_price' => 0]]])
            ->assertUnprocessable()->assertJsonPath('error', 'The invoice total must be greater than zero');
        $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => [$item], 'due_date' => now()->subDays(2)->toDateString()])
            ->assertUnprocessable()->assertJsonPath('error', "The due date can't be in the past");
        $this->assertSame(0, Invoice::count());
    }

    public function test_the_client_sees_the_invoice_on_a_private_link_without_signing_in(): void
    {
        $invoice = $this->invoice();
        $this->app['auth']->forgetGuards();

        $this->get("/pay/{$invoice->pay_token}")->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Apex Roofing')->assertSee($invoice->number)->assertSee('Roof repair')->assertSee('USD 150.00')
            ->assertSee('Please contact Apex Roofing');
        $this->get("/pay/{$invoice->pay_token}/pdf")->assertOk();

        // A wrong token shows nothing, and the token itself never appears in API data
        $this->get('/pay/notARealToken')->assertNotFound()->assertDontSee('Roof repair');
        $this->assertArrayNotHasKey('pay_token', $invoice->toArray());
        $this->assertSame(url("/pay/{$invoice->pay_token}"), $invoice->toArray()['pay_url']);
    }

    public function test_the_client_page_follows_the_invoice(): void
    {
        config(['ppc.payment_instructions' => "Bank: First National\nIBAN: 123"]);
        $invoice = $this->invoice();

        $this->get("/pay/{$invoice->pay_token}")->assertSee('How to pay')->assertSee('IBAN: 123');

        $this->patchJson("/api/invoices/{$invoice->id}", ['status' => 'cancelled'])->assertOk();
        $this->get("/pay/{$invoice->pay_token}")->assertSee('was cancelled')->assertDontSee('IBAN: 123');

        $this->patchJson("/api/invoices/{$invoice->id}", ['status' => 'sent'])->assertOk();
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 50, 'method' => 'cash'])->assertCreated();
        $this->get("/pay/{$invoice->pay_token}")->assertSee('Balance due')->assertSee('USD 100.00');

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 100, 'method' => 'cash'])->assertCreated();
        $this->get("/pay/{$invoice->pay_token}")->assertSee('paid in full');
    }

    public function test_marking_paid_records_the_money_and_a_paid_invoice_stays_paid(): void
    {
        $invoice = $this->invoice();

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 40, 'method' => 'cash'])->assertCreated();
        $this->patchJson("/api/invoices/{$invoice->id}", ['status' => 'paid'])->assertOk();
        $this->assertSame('150.00', number_format((float) $invoice->payments()->sum('amount'), 2));
        $this->assertSame('110.00', $invoice->payments()->latest('id')->first()->amount);
        $this->getJson('/api/reports/sales-by-brand')->assertJsonPath('reports.0.paid_amount', 150);

        // Cancelled and reopened: payments cover it, so it isn't "unpaid"
        $this->patchJson("/api/invoices/{$invoice->id}", ['status' => 'cancelled'])->assertOk();
        $this->patchJson("/api/invoices/{$invoice->id}", ['status' => 'sent'])->assertOk()->assertJsonPath('status', 'paid');
    }

    public function test_a_client_pays_by_card_through_stripe_checkout(): void
    {
        config(['services.stripe.secret' => 'sk_test_abc']);
        $invoice = $this->invoice();
        Http::fake([
            '*/v1/checkout/sessions' => Http::response($this->stripeSession($invoice)),
            '*/v1/checkout/sessions/*' => Http::response($this->stripeSession($invoice)),
        ]);

        $this->get("/pay/{$invoice->pay_token}")->assertSee('Pay USD 150.00')->assertSee('Secure card payment');

        $this->post("/pay/{$invoice->pay_token}/checkout")->assertRedirect('https://checkout.stripe.test/pay/cs_test_123');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $r['line_items'][0]['price_data']['unit_amount'] === 15000
            && $r['line_items'][0]['price_data']['currency'] === 'usd'
            && $r['customer_email'] === 'ada@example.com'
            && (int) $r['metadata']['invoice_id'] === $invoice->id
            && $r->hasHeader('Authorization', 'Bearer sk_test_abc'));

        // Back from Stripe: recorded once however many times the page is reloaded
        $this->get("/pay/{$invoice->pay_token}/return?session_id=cs_test_123")->assertRedirect("/pay/{$invoice->pay_token}");
        $this->get("/pay/{$invoice->pay_token}/return?session_id=cs_test_123");
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('stripe', PaymentTransaction::first()->method);
        $this->assertSame('payment', $invoice->lead->activities()->latest('id')->first()->type);
        $this->get("/pay/{$invoice->pay_token}")->assertSee('paid in full')->assertDontSee('Pay USD');
    }

    public function test_a_return_url_cannot_mark_an_invoice_paid_by_itself(): void
    {
        config(['services.stripe.secret' => 'sk_test_abc']);
        $invoice = $this->invoice();

        // Unpaid session, then a paid session belonging to a different invoice
        Http::fake(['*' => Http::sequence()
            ->push($this->stripeSession($invoice, ['payment_status' => 'unpaid']))
            ->push($this->stripeSession($invoice, ['metadata' => ['invoice_id' => '999999']]))
            ->push([], 404)]);

        $this->get("/pay/{$invoice->pay_token}/return?session_id=cs_x");
        $this->get("/pay/{$invoice->pay_token}/return?session_id=cs_x");
        $this->get("/pay/{$invoice->pay_token}/return?session_id=cs_forged");
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_when_stripe_fails_the_client_sees_a_clear_message(): void
    {
        config(['services.stripe.secret' => 'sk_test_abc']);
        $invoice = $this->invoice();
        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], 500)]);

        $this->post("/pay/{$invoice->pay_token}/checkout")->assertRedirect("/pay/{$invoice->pay_token}")
            ->assertSessionHas('pay_error');
        $this->followingRedirects()->post("/pay/{$invoice->pay_token}/checkout")->assertSee('Online payment is unavailable');
    }

    public function test_a_paid_or_cancelled_invoice_cannot_start_a_payment(): void
    {
        config(['services.stripe.secret' => 'sk_test_abc']);
        Http::fake();
        $invoice = $this->invoice();
        $this->patchJson("/api/invoices/{$invoice->id}", ['status' => 'cancelled'])->assertOk();

        $this->post("/pay/{$invoice->pay_token}/checkout")->assertRedirect("/pay/{$invoice->pay_token}");
        Http::assertNothingSent();
    }

    public function test_stripe_webhooks_must_be_signed_and_are_recorded_once(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $invoice = $this->invoice();
        $event = ['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession($invoice)]];

        $this->stripeWebhook($event, 'forged-signature')->assertStatus(400);
        $this->assertSame(0, PaymentTransaction::count());

        $this->stripeWebhook($event)->assertOk();
        $this->stripeWebhook($event)->assertOk();
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_an_old_stripe_signature_is_rejected(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $invoice = $this->invoice();
        $payload = json_encode(['id' => 'evt_2', 'type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession($invoice)]]);
        $time = time() - 3600;
        $signature = hash_hmac('sha256', "{$time}.{$payload}", 'whsec_test');

        $this->call('POST', '/api/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}"], $payload)->assertStatus(400);
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_a_sales_rep_gets_the_link_for_their_own_invoices_only(): void
    {
        $lead = $this->lead();
        $rep = $this->user(Role::FrontSales, [$lead->brand]);
        $lead->update(['assignee_id' => $rep->id]);
        $this->actingAs($rep);

        $id = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => [['label' => 'Work', 'quantity' => 1, 'unit_price' => 80]]])
            ->assertCreated()->json('id');
        $this->getJson('/api/invoices')->assertOk()->assertJsonPath('invoices.0.pay_url', Invoice::find($id)->pay_url);
        $this->getJson("/api/leads/{$lead->id}")->assertOk()->assertJsonPath('invoices.0.pay_url', Invoice::find($id)->pay_url);

        $this->actingAs($this->user(Role::FrontSales, [$lead->brand]));
        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(0, 'invoices');
    }

    public function test_the_pdf_says_how_to_pay_until_the_invoice_is_settled(): void
    {
        config(['ppc.payment_instructions' => "Bank transfer: <First> National\nIBAN PK00"]);
        $invoice = $this->invoice();
        $render = fn (Invoice $i) => view('pdf.invoice', ['invoice' => $i, 'items' => $i->items()])->render();

        $this->assertStringContainsString('Bank transfer: &lt;First&gt; National<br />', $render($invoice));

        $invoice->update(['status' => 'paid']);
        $this->assertStringNotContainsString('Bank transfer', $render($invoice->fresh()));
    }

    public function test_invoices_from_before_line_items_still_print(): void
    {
        $lead = $this->lead();
        $invoice = Invoice::createWithNumber([
            'lead_id' => $lead->id, 'brand_id' => $lead->brand_id, 'created_by_id' => $this->user(Role::Admin)->id,
            'amount' => 75, 'status' => 'sent', 'description' => 'Old style', 'line_items' => [['label' => 'Legacy', 'amount' => 75]],
        ]);

        $this->assertSame([['label' => 'Legacy', 'quantity' => 1.0, 'unit_price' => 75.0, 'amount' => 75.0]], $invoice->items());
        $this->get("/pay/{$invoice->pay_token}")->assertOk()->assertSee('Legacy');
    }
}
