<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Services\BrandTheme;
use App\Services\LeadIntake;
use App\Services\PayDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function lead(Brand $brand): Lead
    {
        $site = $this->website($brand);

        return Lead::find(app(LeadIntake::class)->ingest($brand->id, $site, LeadSource::Form, ['email' => fake()->unique()->safeEmail(), 'name' => 'Ada Client'])['lead_id']);
    }

    private function invoice(Brand $brand, array $input = []): Invoice
    {
        $this->actingAs($this->user(Role::SuperAdmin));
        $id = $this->postJson('/api/invoices', [
            'lead_id' => $this->lead($brand)->id,
            'items' => [['label' => 'Design', 'quantity' => 1, 'unit_price' => 100]],
            ...$input,
        ])->assertCreated()->json('id');

        return Invoice::find($id);
    }

    private function connect(Brand $brand, string $domain): void
    {
        $brand->update(['pay_domain' => $domain, 'pay_domain_verified_at' => now()]);
    }

    public function test_the_merchant_is_chosen_per_invoice_and_defaults_from_the_brand(): void
    {
        $brand = $this->brand(['name' => 'Creative Hub', 'merchant_name' => 'Creative Hub LLC']);

        $this->assertSame('Creative Hub LLC', $this->invoice($brand)->merchant_name);

        $named = $this->invoice($brand, ['merchant_name' => 'Hub Publishing Ltd']);
        $this->assertSame('Hub Publishing Ltd', $named->merchant_name);
        $this->get("/pay/{$named->pay_token}")->assertSee('Hub Publishing Ltd')->assertDontSee('Creative Hub LLC');

        // Issued invoices keep who they were billed by
        $brand->update(['merchant_name' => 'Renamed Inc']);
        $this->assertSame('Hub Publishing Ltd', $named->fresh()->merchantName());
    }

    public function test_the_invoice_form_can_fill_in_the_brands_merchant(): void
    {
        $brand = $this->brand(['merchant_name' => 'Creative Hub LLC']);
        $lead = $this->lead($brand);
        $this->actingAs($this->user(Role::SuperAdmin));

        $this->getJson('/api/leads')->assertOk()->assertJsonPath('leads.0.brand.merchant_name', 'Creative Hub LLC');
        $this->getJson("/api/leads/{$lead->id}")->assertOk()->assertJsonPath('lead.brand.merchant_name', 'Creative Hub LLC');
    }

    public function test_invoices_from_before_merchants_show_the_brand(): void
    {
        $brand = $this->brand(['name' => 'Old Brand']);
        $invoice = $this->invoice($brand);
        $invoice->update(['merchant_name' => null]);

        $this->get("/pay/{$invoice->pay_token}")->assertOk()->assertSee('Old Brand');
    }

    public function test_the_page_wears_the_brand(): void
    {
        Storage::fake('brand_logos');
        $brand = $this->brand(['brand_color' => '#123456', 'billing_email' => 'billing@hub.test', 'billing_address' => '1 Main St']);
        $invoice = $this->invoice($brand);

        $this->actingAs($this->user(Role::SuperAdmin));
        $this->post("/api/admin/brands/{$brand->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)], ['Accept' => 'application/json'])->assertCreated();
        $path = $brand->fresh()->logo_path;

        $this->get("/pay/{$invoice->pay_token}")->assertOk()
            ->assertSee('--accent: #123456', false)->assertSee("/{$path}", false)->assertSee('billing@hub.test')->assertSee('1 Main St');
    }

    public function test_any_brand_colour_stays_readable(): void
    {
        $this->assertSame('#0f172a', BrandTheme::from('#ffe600')['on_accent'], 'Dark text on a yellow brand');
        $this->assertSame('#ffffff', BrandTheme::from('#123456')['on_accent'], 'White text on a dark brand');
        $this->assertNotSame('#ffe600', BrandTheme::from('#ffe600')['ink'], 'Yellow is too pale to use as text on white');
        $this->assertSame('#0f766e', BrandTheme::from(null)['accent']);
    }

    public function test_only_real_images_are_accepted_as_logos(): void
    {
        Storage::fake('brand_logos');
        $brand = $this->brand();
        $this->actingAs($this->user(Role::SuperAdmin));
        $upload = fn ($file) => $this->post("/api/admin/brands/{$brand->id}/logo", ['logo' => $file], ['Accept' => 'application/json']);

        $upload(UploadedFile::fake()->createWithContent('logo.svg', '<svg onload="alert(1)"/>'))->assertUnprocessable();
        $upload(UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;'))->assertUnprocessable();
        $upload(UploadedFile::fake()->image('big.png')->size(2000))->assertUnprocessable();
        $this->assertNull($brand->fresh()->logo_path);

        // Replacing a logo removes the old file
        $upload(UploadedFile::fake()->image('a.png'))->assertCreated();
        $first = basename($brand->fresh()->logo_path);
        $upload(UploadedFile::fake()->image('b.jpg'))->assertCreated();
        Storage::disk('brand_logos')->assertMissing($first);
        $this->assertCount(1, Storage::disk('brand_logos')->files());

        $this->deleteJson("/api/admin/brands/{$brand->id}/logo")->assertOk();
        $this->assertNull($brand->fresh()->logo_path);
        $this->assertCount(0, Storage::disk('brand_logos')->files());
    }

    public function test_a_manager_brands_their_own_brand_but_not_its_domain(): void
    {
        $brand = $this->brand();
        $other = $this->brand();
        $this->actingAs($this->user(Role::BrandManager, [$brand]));

        $this->patchJson("/api/admin/brands/{$brand->id}/branding", ['merchant_name' => 'Hub LLC', 'brand_color' => '#112233'])->assertOk();
        $this->assertSame('Hub LLC', $brand->fresh()->merchant_name);

        $this->patchJson("/api/admin/brands/{$other->id}/branding", ['merchant_name' => 'Nope'])->assertNotFound();
        $this->patchJson("/api/admin/brands/{$brand->id}/branding", ['pay_domain' => 'pay.hub.test'])->assertForbidden();
        $this->postJson("/api/admin/brands/{$brand->id}/domain-check")->assertForbidden();

        $this->actingAs($this->user(Role::FrontSales, [$brand]));
        $this->patchJson("/api/admin/brands/{$brand->id}/branding", ['merchant_name' => 'Nope'])->assertForbidden();
    }

    public function test_branding_input_is_checked(): void
    {
        $brand = $this->brand();
        $taken = $this->brand(['pay_domain' => 'pay.taken.com']);
        $this->actingAs($this->user(Role::SuperAdmin));
        $save = fn (array $input) => $this->patchJson("/api/admin/brands/{$brand->id}/branding", $input);

        $save(['brand_color' => 'red'])->assertUnprocessable();
        $save(['brand_color' => '#12345'])->assertUnprocessable();
        $save(['billing_email' => 'not-an-email'])->assertUnprocessable();
        $save(['pay_domain' => 'localhost'])->assertUnprocessable();
        $save(['pay_domain' => '127.0.0.1'])->assertUnprocessable();
        $save(['pay_domain' => 'pay.taken.com'])->assertUnprocessable();
        $save(['pay_domain' => parse_url(config('app.url'), PHP_URL_HOST)])->assertUnprocessable();

        $save(['pay_domain' => 'https://Pay.Hub.com/invoices'])->assertOk();
        $this->assertSame('pay.hub.com', $brand->fresh()->pay_domain);
        $this->assertSame('pay.taken.com', $taken->fresh()->pay_domain);
    }

    public function test_links_only_move_to_a_domain_once_it_really_reaches_this_app(): void
    {
        $brand = $this->brand();
        $invoice = $this->invoice($brand);
        $before = url("/pay/{$invoice->pay_token}");
        $this->patchJson("/api/admin/brands/{$brand->id}/branding", ['pay_domain' => 'pay.hub.test'])->assertOk();
        $check = fn () => $this->postJson("/api/admin/brands/{$brand->id}/domain-check");

        $answers = [
            fn () => throw new ConnectionException('no route'),
            fn () => Http::response('some other site'),
            fn () => Http::response(app(PayDomain::class)->proof('pay.hub.test')),
        ];
        Http::fake(function () use (&$answers) {
            return array_shift($answers)();
        });

        // DNS not ready
        $check()->assertUnprocessable()->assertJsonPath('error', fn ($e) => str_contains($e, "couldn't reach"));
        $this->assertSame($before, $invoice->fresh()->pay_url);

        // Pointing at someone else's server
        $check()->assertUnprocessable()->assertJsonPath('error', fn ($e) => str_contains($e, "doesn't point to this app"));
        $this->assertSame($before, $invoice->fresh()->pay_url);

        // Really this app
        $check()->assertOk()->assertJsonPath('branding.domain_connected', true);
        $this->assertSame("https://pay.hub.test/pay/{$invoice->pay_token}", $invoice->fresh()->pay_url);

        // A new domain starts unconnected again
        $this->patchJson("/api/admin/brands/{$brand->id}/branding", ['pay_domain' => 'pay.other.test'])->assertOk();
        $this->assertSame($before, $invoice->fresh()->pay_url);
    }

    public function test_the_proof_only_matches_the_domain_it_was_made_for(): void
    {
        $proof = $this->get('http://pay.hub.test/pay-domain-check')->assertOk()->getContent();

        $this->assertSame(app(PayDomain::class)->proof('pay.hub.test'), $proof);
        $this->assertNotSame(app(PayDomain::class)->proof('pay.other.test'), $proof);
    }

    public function test_a_brand_domain_shows_invoices_and_never_the_dashboard(): void
    {
        $brand = $this->brand();
        $other = $this->brand();
        $invoice = $this->invoice($brand);
        $elsewhere = $this->invoice($other);
        $this->connect($brand, 'pay.hub.test');

        $this->get("http://pay.hub.test/pay/{$invoice->pay_token}")->assertOk();
        $this->get("http://pay.hub.test/pay/{$invoice->pay_token}/pdf")->assertOk();

        // Another brand's invoice doesn't exist here
        $this->get("http://pay.hub.test/pay/{$elsewhere->pay_token}")->assertNotFound();

        foreach (['/', '/login', '/dashboard', '/api/me'] as $path) {
            $this->get("http://pay.hub.test{$path}")->assertNotFound();
        }

        // Links sent before the domain was connected keep working
        $this->get("/pay/{$invoice->pay_token}")->assertOk();
    }

    public function test_the_brands_page_carries_branding_for_managers(): void
    {
        $brand = $this->brand(['brand_color' => '#123456']);
        $this->actingAs($this->user(Role::BrandManager, [$brand]));

        $this->getJson('/api/admin/brands')->assertOk()
            ->assertJsonPath('brands.0.branding.brand_color', '#123456')
            ->assertJsonPath('billing.card_payments', false);
    }

    public function test_the_pdf_is_branded(): void
    {
        Storage::fake('brand_logos');
        Storage::disk('brand_logos')->put('1-logo.png', 'png-bytes');
        $brand = $this->brand(['brand_color' => '#123456', 'logo_path' => 'brand-logos/1-logo.png', 'billing_address' => "1 Main St\nLahore"]);
        $invoice = $this->invoice($brand, ['merchant_name' => 'Hub LLC']);

        $html = view('pdf.invoice', ['invoice' => $invoice, 'items' => $invoice->items()])->render();

        $this->assertStringContainsString('Hub LLC', $html);
        $this->assertStringContainsString('#123456', $html);
        $this->assertStringContainsString('data:image/png;base64,'.base64_encode('png-bytes'), $html);
        $this->assertStringContainsString('1 Main St<br />', $html);
    }
}
