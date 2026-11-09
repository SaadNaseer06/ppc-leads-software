<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Merchant name and Stripe keys entered in the app, per brand */
class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_brandsecret1234';

    private const WEBHOOK = 'whsec_brandwebhook';

    private function connected(array $attributes = []): Brand
    {
        return $this->brand([
            'stripe_publishable_key' => 'pk_test_brand',
            'stripe_secret_key' => self::SECRET,
            'stripe_webhook_secret' => self::WEBHOOK,
            ...$attributes,
        ]);
    }

    private function invoice(Brand $brand): Invoice
    {
        $this->actingAs($this->user(Role::SuperAdmin));
        $id = $this->postJson('/api/invoices', [
            'brand_id' => $brand->id,
            'client_name' => 'Ada Client',
            'client_email' => 'ada@client.test',
            'items' => [['label' => 'Design', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->json('id');

        return Invoice::find($id);
    }

    private function save(Brand $brand, array $input = [])
    {
        return $this->patchJson("/api/admin/brands/{$brand->id}/payments", [
            'merchant_name' => 'Hub LLC',
            'publishable_key' => 'pk_test_abc',
            'secret_key' => self::SECRET,
            'webhook_secret' => self::WEBHOOK,
            ...$input,
        ]);
    }

    private function webhook(string $path, array $event, string $secret)
    {
        $payload = json_encode($event);
        $time = time();
        $signature = hash_hmac('sha256', "{$time}.{$payload}", $secret);

        return $this->call('POST', $path, [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}", 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_a_super_admin_connects_a_brand_from_the_app(): void
    {
        Http::fake(['*/v1/account' => Http::response(['business_profile' => ['name' => 'Hub Publishing Inc']])]);
        $brand = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->save($brand)->assertOk()
            ->assertJsonPath('brand.card_payments', true)
            ->assertJsonPath('brand.source', 'own')
            ->assertJsonPath('brand.mode', 'test')
            ->assertJsonPath('brand.account_name', 'Hub Publishing Inc');

        $brand->refresh();
        $this->assertSame('Hub LLC', $brand->merchant_name);
        $this->assertSame(self::SECRET, $brand->stripe_secret_key);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer '.self::SECRET));
    }

    public function test_only_a_super_admin_can_touch_the_keys(): void
    {
        Http::fake();
        $brand = $this->brand();

        foreach ([Role::Developer, Role::Admin, Role::BrandManager, Role::Finance, Role::FrontSales] as $role) {
            $this->actingAs($this->user($role, [$brand]));
            $this->save($brand)->assertForbidden();
            $this->deleteJson("/api/admin/brands/{$brand->id}/payments")->assertForbidden();
        }
        Http::assertNothingSent();
        $this->assertNull($brand->fresh()->stripe_publishable_key);
    }

    public function test_secrets_never_leave_the_server(): void
    {
        $brand = $this->connected();
        $invoice = $this->invoice($brand);

        $stored = DB::table('brands')->where('id', $brand->id)->first();
        $this->assertStringNotContainsString(self::SECRET, $stored->stripe_secret_key, 'Stored encrypted');
        $this->assertStringNotContainsString(self::WEBHOOK, $stored->stripe_webhook_secret, 'Stored encrypted');

        $this->assertArrayNotHasKey('stripe_secret_key', $brand->toArray());

        foreach (['/api/invoices', '/api/admin/brands', '/api/lookups', "/api/invoices/{$invoice->id}/pdf"] as $url) {
            $body = $this->get($url, ['Accept' => 'application/json'])->assertOk()->getContent();
            $this->assertStringNotContainsString(self::SECRET, $body, $url);
            $this->assertStringNotContainsString(self::WEBHOOK, $body, $url);
        }
        $this->assertStringNotContainsString(self::SECRET, $this->get("/pay/{$invoice->pay_token}")->getContent());

        // The owner sees enough to recognise it, and no more
        $this->getJson('/api/invoices')->assertJsonPath('billing.brands.0.secret_hint', '…1234');
    }

    public function test_the_activity_log_never_holds_a_key(): void
    {
        Http::fake(['*/v1/account' => Http::response(['business_profile' => ['name' => 'Hub']])]);
        $brand = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));
        $this->save($brand)->assertOk();

        $log = ActivityLog::where('action', 'brand.stripe_connect')->firstOrFail();

        $this->assertStringNotContainsString('sk_test', json_encode($log->toArray()));
    }

    public function test_other_managers_only_learn_whether_cards_are_on(): void
    {
        $brand = $this->connected();
        $this->actingAs($this->user(Role::Admin, [$brand]));

        $billing = $this->getJson('/api/invoices')->assertOk()->json('billing');

        $this->assertFalse($billing['can_configure']);
        $this->assertTrue($billing['brands'][0]['card_payments']);
        $this->assertArrayNotHasKey('secret_hint', $billing['brands'][0]);
        $this->assertArrayNotHasKey('publishable_key', $billing['brands'][0]);
    }

    public function test_keys_stripe_rejects_are_not_saved(): void
    {
        $brand = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));

        Http::fake(['*/v1/account' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);
        $this->save($brand)->assertUnprocessable()->assertJsonPath('error', "Stripe didn't accept that secret key. Copy it again from your Stripe dashboard.");

        $this->assertNull($brand->fresh()->stripe_secret_key);
    }

    public function test_when_stripe_is_down_nothing_is_saved(): void
    {
        $brand = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));
        Http::fake(['*/v1/account' => Http::response([], 500)]);

        $this->save($brand)->assertStatus(503);
        $this->assertNull($brand->fresh()->stripe_secret_key);
    }

    public function test_mistakes_are_caught_before_asking_stripe(): void
    {
        Http::fake();
        $brand = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->save($brand, ['publishable_key' => 'sk_test_swapped'])->assertUnprocessable();
        $this->save($brand, ['secret_key' => 'pk_test_swapped'])->assertUnprocessable();
        $this->save($brand, ['webhook_secret' => 'nope'])->assertUnprocessable();
        $this->save($brand, ['publishable_key' => 'pk_live_abc'])->assertUnprocessable()->assertJsonPath('error', 'One Stripe key is a test key and the other is live. Both must be from the same mode.');
        $this->save($brand, ['secret_key' => null])->assertUnprocessable()->assertJsonPath('error', 'Paste the secret key from Stripe');

        Http::assertNothingSent();
    }

    public function test_a_saved_secret_can_be_kept_while_changing_the_rest(): void
    {
        Http::fake(['*/v1/account' => Http::response(['business_profile' => ['name' => 'Hub']])]);
        $brand = $this->connected();
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->save($brand, ['merchant_name' => 'Renamed LLC', 'secret_key' => null, 'webhook_secret' => null])->assertOk();

        $brand->refresh();
        $this->assertSame('Renamed LLC', $brand->merchant_name);
        $this->assertSame(self::SECRET, $brand->stripe_secret_key);
        $this->assertSame(self::WEBHOOK, $brand->stripe_webhook_secret);
    }

    public function test_each_brand_is_paid_through_its_own_account(): void
    {
        $brand = $this->connected();
        $other = $this->connected(['stripe_publishable_key' => 'pk_test_other', 'stripe_secret_key' => 'sk_test_other']);
        $invoice = $this->invoice($brand);
        $otherInvoice = $this->invoice($other);
        Http::fake(['*/v1/payment_intents' => Http::response(['id' => 'pi_1', 'client_secret' => 'pi_1_secret', 'status' => 'requires_payment_method', 'amount' => 10000])]);

        $this->get("/pay/{$invoice->pay_token}")->assertSee('pk_test_brand')->assertDontSee('pk_test_other')->assertSee('Test mode');

        $this->postJson("/pay/{$invoice->pay_token}/intent")->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer '.self::SECRET));

        $this->postJson("/pay/{$otherInvoice->pay_token}/intent")->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer sk_test_other'));
    }

    public function test_a_brand_without_keys_uses_the_server_wide_ones_or_stays_off(): void
    {
        $brand = $this->brand();
        $invoice = $this->invoice($brand);

        $this->get("/pay/{$invoice->pay_token}")->assertDontSee('js.stripe.com');
        $this->getJson('/api/invoices')->assertJsonPath('billing.brands.0.card_payments', false)->assertJsonPath('billing.brands.0.source', null);

        config(['services.stripe.secret' => 'sk_test_env', 'services.stripe.publishable' => 'pk_test_env']);
        $this->get("/pay/{$invoice->pay_token}")->assertSee('pk_test_env');
        $this->getJson('/api/invoices')->assertJsonPath('billing.brands.0.source', 'env');
    }

    public function test_live_keys_show_no_test_note(): void
    {
        $invoice = $this->invoice($this->connected(['stripe_publishable_key' => 'pk_live_brand', 'stripe_secret_key' => 'sk_live_brand']));

        $this->get("/pay/{$invoice->pay_token}")->assertSee('pk_live_brand')->assertDontSee('Test mode');
    }

    public function test_a_brand_webhook_is_signed_with_its_own_secret(): void
    {
        $brand = $this->connected();
        $invoice = $this->invoice($brand);
        $event = ['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'pi_1', 'status' => 'succeeded', 'amount_received' => 10000, 'metadata' => ['invoice_id' => (string) $invoice->id],
        ]]];
        $path = "/api/webhooks/stripe/{$brand->id}";

        $this->webhook($path, $event, 'whsec_wrong')->assertStatus(400);
        $this->assertSame(0, PaymentTransaction::count());

        $this->webhook($path, $event, self::WEBHOOK)->assertOk();
        $this->webhook($path, $event, self::WEBHOOK)->assertOk();
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_one_brand_can_never_mark_another_brands_invoice_paid(): void
    {
        $brand = $this->connected();
        $other = $this->connected(['stripe_webhook_secret' => 'whsec_other']);
        $invoice = $this->invoice($brand);
        $event = ['id' => 'evt_2', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'pi_2', 'status' => 'succeeded', 'amount_received' => 10000, 'metadata' => ['invoice_id' => (string) $invoice->id],
        ]]];

        $this->webhook("/api/webhooks/stripe/{$other->id}", $event, 'whsec_other')->assertOk();

        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_a_brand_with_no_webhook_secret_accepts_nothing(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_env']);
        $brand = $this->brand();

        $this->webhook("/api/webhooks/stripe/{$brand->id}", ['id' => 'evt_3', 'type' => 'x'], 'whsec_env')->assertStatus(400);
        $this->webhook('/api/webhooks/stripe/999999', ['id' => 'evt_4', 'type' => 'x'], 'whsec_env')->assertNotFound();
    }

    public function test_the_payments_page_is_for_super_admins_only(): void
    {
        $brand = $this->connected();
        $this->brand(['name' => 'Second']);

        foreach ([Role::Developer, Role::Admin, Role::BrandManager, Role::Finance] as $role) {
            $this->actingAs($this->user($role, [$brand]), 'web');
            $this->get('/payments')->assertRedirect('/dashboard');
            $this->actingAs($this->user($role, [$brand]));
            $this->getJson('/api/admin/payments')->assertForbidden();
        }

        $owner = $this->user(Role::SuperAdmin);
        $this->actingAs($owner, 'web');
        $page = $this->get('/payments')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::SECRET, $page);
        $this->assertStringNotContainsString(self::WEBHOOK, $page);

        $this->actingAs($owner);
        $this->getJson('/api/admin/payments')->assertOk()
            ->assertJsonCount(2, 'brands')
            ->assertJsonPath('brands.0.card_payments', true)
            ->assertJsonPath('brands.0.secret_hint', '…1234')
            ->assertJsonPath('brands.1.card_payments', false);
        $this->getJson('/api/me')->assertJsonPath('user.can.manage_payments', true);
    }

    public function test_disconnecting_stops_card_payments(): void
    {
        $brand = $this->connected();
        $invoice = $this->invoice($brand);

        $this->deleteJson("/api/admin/brands/{$brand->id}/payments")->assertOk()->assertJsonPath('brand.card_payments', false);

        $this->assertNull($brand->fresh()->stripe_secret_key);
        $this->get("/pay/{$invoice->pay_token}")->assertDontSee('js.stripe.com');
    }
}
