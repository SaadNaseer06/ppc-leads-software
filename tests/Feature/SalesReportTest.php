<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use App\Services\LeadIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Brand $apex;

    private Brand $lumen;

    private Website $apexSite;

    private Website $lumenSite;

    private User $sam;

    private User $riley;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apex = $this->brand(['name' => 'Apex']);
        $this->lumen = $this->brand(['name' => 'Lumen']);
        $this->apexSite = $this->website($this->apex);
        $this->lumenSite = $this->website($this->lumen);
        $this->sam = $this->user(Role::FrontSales, [$this->apex], ['name' => 'Sam']);
        $this->riley = $this->user(Role::FrontSales, [$this->lumen], ['name' => 'Riley']);

        // Apex, last week: one paid in full, one part paid
        $this->sale($this->apexSite, $this->sam, 1000, ['days' => 3, 'paid' => 1000, 'channel' => 'ppc', 'campaign' => 'roof-repair']);
        $this->sale($this->apexSite, $this->sam, 400, ['days' => 5, 'paid' => 100, 'channel' => 'organic']);
        // Lumen: unpaid and overdue, plus a cancelled one that must not count
        $this->sale($this->lumenSite, $this->riley, 600, ['days' => 4, 'channel' => 'ppc', 'campaign' => 'implants', 'due' => now()->subDays(1)->toDateString()]);
        $this->sale($this->lumenSite, $this->riley, 900, ['days' => 2, 'cancelled' => true]);
        // Outside the default 30 days
        $this->sale($this->apexSite, $this->sam, 5000, ['days' => 45, 'paid' => 5000]);
    }

    private function sale(Website $site, User $rep, float $amount, array $o): Invoice
    {
        $lead = Lead::find(app(LeadIntake::class)->ingest($site->brand_id, $site, LeadSource::Form, [
            'email' => fake()->unique()->safeEmail(),
            'assignee_id' => $rep->id,
        ])['lead_id']);
        $lead->update(['channel' => $o['channel'] ?? 'direct', 'utm_campaign' => $o['campaign'] ?? null, 'campaign' => $o['campaign'] ?? null]);

        $invoice = Invoice::createWithNumber([
            ...Invoice::priced([['label' => 'Work', 'quantity' => 1, 'unit_price' => $amount]], 0, 0),
            'lead_id' => $lead->id, 'brand_id' => $site->brand_id, 'created_by_id' => $rep->id,
            'status' => ($o['cancelled'] ?? false) ? 'cancelled' : (($o['paid'] ?? 0) >= $amount ? 'paid' : 'sent'),
            'due_date' => $o['due'] ?? null,
        ]);
        Invoice::whereKey($invoice->id)->update(['created_at' => now()->subDays($o['days'])]);
        if ($o['paid'] ?? false) {
            $invoice->payments()->create(['amount' => $o['paid'], 'method' => 'card', 'recorded_at' => now()]);
        }

        return $invoice;
    }

    private function report(User $user, array $filters = [])
    {
        Cache::flush();
        $this->actingAs($user);

        return $this->getJson('/api/reports/sales?'.http_build_query($filters))->assertOk();
    }

    public function test_the_numbers_add_up(): void
    {
        $report = $this->report($this->user(Role::SuperAdmin));

        // 1000 + 400 + 600 (the cancelled invoice and the older one are out)
        $report->assertJsonPath('totals.invoices', 3)
            ->assertJsonPath('totals.paid_invoices', 1)
            ->assertJsonPath('totals.invoiced', 2000)
            ->assertJsonPath('totals.collected', 1100)
            ->assertJsonPath('totals.outstanding', 900)
            ->assertJsonPath('totals.overdue', 600)
            ->assertJsonPath('totals.average', 666.67)
            ->assertJsonPath('granularity', 'day');

        // Chart and breakdowns all come back to the totals
        $sum = fn (string $list, string $key) => array_sum(array_column($report->json($list), $key));
        foreach (['series', 'by_brand', 'by_rep', 'by_channel'] as $list) {
            $this->assertEquals(2000, $sum($list, 'invoiced'), $list);
            $this->assertEquals(1100, $sum($list, 'collected'), $list);
            $this->assertEquals(900, $sum($list, 'outstanding'), $list);
        }
        $this->assertCount(30, $report->json('series'));
        $this->assertSame('Apex', $report->json('by_brand.0.name'));
        $this->assertEquals(1400, $report->json('by_brand.0.invoiced'));
        $this->assertSame('Riley', $report->json('by_rep.1.name'));
        $this->assertSame(['roof-repair', 'implants'], array_column($report->json('by_campaign'), 'name'));
    }

    public function test_filters_narrow_every_figure(): void
    {
        $boss = $this->user(Role::SuperAdmin);

        $this->report($boss, ['brand' => (string) $this->apex->id])->assertJsonPath('totals.invoiced', 1400)->assertJsonCount(1, 'by_brand');
        $this->report($boss, ['website' => (string) $this->lumenSite->id])->assertJsonPath('totals.invoiced', 600);
        $this->report($boss, ['rep' => (string) $this->riley->id])->assertJsonPath('totals.invoiced', 600)->assertJsonCount(1, 'by_rep');
        $this->report($boss, ['channel' => 'ppc'])->assertJsonPath('totals.invoiced', 1600)->assertJsonPath('totals.collected', 1000);
        $this->report($boss, ['status' => 'paid'])->assertJsonPath('totals.invoiced', 1000);
        $this->report($boss, ['status' => 'unpaid'])->assertJsonPath('totals.invoiced', 1000);
        $this->report($boss, ['status' => 'overdue'])->assertJsonPath('totals.invoiced', 600);
        $this->report($boss, ['status' => 'cancelled'])->assertJsonPath('totals.invoiced', 900);

        // Filters combine, and a date range picks up the older sale
        $this->report($boss, ['brand' => (string) $this->apex->id, 'channel' => 'organic'])->assertJsonPath('totals.invoiced', 400);
        $older = ['from' => now()->subDays(50)->toDateString(), 'to' => now()->subDays(40)->toDateString()];
        $this->report($boss, $older)->assertJsonPath('totals.invoiced', 5000)->assertJsonPath('totals.collected', 5000);
    }

    public function test_it_compares_with_the_period_before_and_shows_lead_conversion(): void
    {
        Lead::query()->where('brand_id', $this->apex->id)->limit(1)->update(['status' => 'converted']);

        $report = $this->report($this->user(Role::SuperAdmin), ['from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString()]);
        $report->assertJsonPath('previous.invoiced', 5000);
        $report->assertJsonPath('leads.received', 5);
        $report->assertJsonPath('leads.converted', 1);
    }

    public function test_a_long_period_is_charted_by_month(): void
    {
        $report = $this->report($this->user(Role::SuperAdmin), ['from' => now()->subDays(100)->toDateString(), 'to' => now()->toDateString()]);

        $report->assertJsonPath('granularity', 'month');
        $this->assertLessThanOrEqual(5, count($report->json('series')));
        $this->assertEquals(7000, array_sum(array_column($report->json('series'), 'invoiced')));
    }

    public function test_people_only_analyse_their_own_brands(): void
    {
        $admin = $this->user(Role::Admin, [$this->apex]);
        $this->report($admin)->assertJsonPath('totals.invoiced', 1400)->assertJsonCount(1, 'options.brands');
        // Asking for another brand finds nothing rather than leaking it
        $this->report($admin, ['brand' => (string) $this->lumen->id])->assertJsonPath('totals.invoiced', 0)->assertJsonPath('leads.received', 0);

        $this->report($this->user(Role::BrandManager, [$this->lumen]))->assertJsonPath('totals.invoiced', 600);
        $this->report($this->user(Role::Finance))->assertJsonPath('totals.invoiced', 2000);

        $this->actingAs($this->sam);
        $this->getJson('/api/reports/sales')->assertForbidden();
        $this->get('/api/reports/sales/export')->assertForbidden();
    }

    public function test_bad_filters_fall_back_to_the_default_view(): void
    {
        $report = $this->report($this->user(Role::SuperAdmin), ['from' => 'nonsense', 'brand' => 'x; drop table', 'status' => 'weird', 'channel' => 'nope']);
        $report->assertJsonPath('totals.invoiced', 2000)->assertJsonPath('filters.brand', null)->assertJsonPath('filters.status', null);

        // Dates the wrong way round are swapped, not an empty report
        $this->report($this->user(Role::SuperAdmin), ['from' => now()->toDateString(), 'to' => now()->subDays(29)->toDateString()])
            ->assertJsonPath('totals.invoiced', 2000);
    }

    public function test_the_csv_lists_the_filtered_invoices(): void
    {
        $this->actingAs($this->user(Role::Admin, [$this->apex]));
        $csv = $this->get('/api/reports/sales/export')->assertOk()->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines); // header + the admin's two invoices in the period
        $this->assertStringContainsString('Invoice,Brand,Client,"Sales rep"', $lines[0]);
        $this->assertStringContainsString('Apex', $lines[1]);
        $this->assertStringContainsString('roof-repair', $csv);
        $this->assertStringNotContainsString('Lumen', $csv);
    }

    public function test_an_invoice_marked_paid_without_a_payment_counts_as_collected(): void
    {
        Invoice::where('status', 'sent')->where('amount', 600)->update(['status' => 'paid']);

        $this->report($this->user(Role::SuperAdmin))->assertJsonPath('totals.collected', 1700);
    }
}
