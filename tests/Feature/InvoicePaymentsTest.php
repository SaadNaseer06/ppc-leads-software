<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PaymentTransaction;
use App\Models\Website;
use App\Services\LeadIntake;
use App\Services\NativeChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function lead(Website $site, array $input = []): Lead
    {
        $id = app(LeadIntake::class)->ingest($site->brand_id, $site, LeadSource::Form, ['email' => fake()->unique()->safeEmail(), ...$input])['lead_id'];

        return Lead::find($id);
    }

    public function test_finance_records_part_and_full_payments(): void
    {
        $brand = $this->brand();
        $site = $this->website($brand);
        $rep = $this->user(Role::FrontSales, [$brand]);
        $lead = $this->lead($site, ['assignee_id' => $rep->id]);

        $this->actingAs($rep);
        $id = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'amount' => 1000])->assertCreated()->json('id');
        // Sales create invoices but don't settle them
        $this->postJson("/api/invoices/{$id}/payments", ['amount' => 10, 'method' => 'cash'])->assertForbidden();
        $this->patchJson("/api/invoices/{$id}", ['status' => 'paid'])->assertForbidden();

        $this->actingAs($this->user(Role::Finance));
        $this->postJson("/api/invoices/{$id}/payments", ['amount' => 400, 'method' => 'bank_transfer', 'external_ref' => 'TX-1'])
            ->assertCreated()->assertJsonPath('status', 'sent');
        $this->postJson("/api/invoices/{$id}/payments", ['amount' => 600.01, 'method' => 'card'])
            ->assertUnprocessable()->assertJsonPath('error', 'That is more than the balance due (600.00 USD).');
        $this->postJson("/api/invoices/{$id}/payments", ['amount' => 600, 'method' => 'bitcoin'])->assertUnprocessable();
        $this->postJson("/api/invoices/{$id}/payments", ['amount' => 600, 'method' => 'card'])
            ->assertCreated()->assertJsonPath('status', 'paid')->assertJsonPath('paid_total', 1000);
        $this->postJson("/api/invoices/{$id}/payments", ['amount' => 1, 'method' => 'card'])
            ->assertUnprocessable()->assertJsonPath('error', 'This invoice is already paid in full.');

        $this->getJson('/api/invoices')->assertOk()->assertJsonPath('invoices.0.paid_total', '1000.00');
        $this->getJson('/api/finance')->assertOk()->assertJsonCount(2, 'transactions');
        $this->getJson('/api/reports/sales-by-brand')->assertOk()
            ->assertJsonPath('reports.0.paid_amount', 1000)
            ->assertJsonPath('reports.0.outstanding_amount', 0);
        $this->get("/api/invoices/{$id}/pdf")->assertOk();
    }

    public function test_cancelled_invoices_take_no_payments_and_leave_the_totals(): void
    {
        $brand = $this->brand();
        $other = $this->brand();
        $site = $this->website($brand);
        $admin = $this->user(Role::Admin, [$brand]);
        $outsider = $this->user(Role::Admin, [$other]);
        $lead = $this->lead($site);

        $this->actingAs($admin);
        $id = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'amount' => 250])->assertCreated()->json('id');
        $this->postJson("/api/invoices/{$id}/payments", ['amount' => 50, 'method' => 'cash'])->assertCreated();
        $this->getJson('/api/reports/sales-by-brand')->assertOk()
            ->assertJsonPath('reports.0.total_amount', 250)
            ->assertJsonPath('reports.0.outstanding_amount', 200);

        // Another brand's admin can't see or touch it
        $this->actingAs($outsider);
        $this->patchJson("/api/invoices/{$id}", ['status' => 'cancelled'])->assertNotFound();

        $this->actingAs($admin);
        $this->patchJson("/api/invoices/{$id}", ['status' => 'cancelled'])->assertOk();
        $this->postJson("/api/invoices/{$id}/payments", ['amount' => 10, 'method' => 'cash'])->assertUnprocessable();
        $this->getJson('/api/reports/sales-by-brand')->assertOk()
            ->assertJsonPath('reports.0.total_amount', 0)
            ->assertJsonPath('reports.0.paid_amount', 0)
            ->assertJsonPath('reports.0.outstanding_amount', 0);

        $this->patchJson("/api/invoices/{$id}", ['status' => 'sent'])->assertOk();
        $this->assertSame('sent', Invoice::find($id)->status);
    }

    public function test_force_deleting_a_brand_removes_only_its_data(): void
    {
        $doomed = $this->brand();
        $kept = $this->brand();
        $doomedSite = $this->website($doomed);
        $keptSite = $this->website($kept);
        $rep = $this->user(Role::FrontSales, [$doomed, $kept]);
        $lead = $this->lead($doomedSite);
        $keptLead = $this->lead($keptSite);
        $chat = app(NativeChatService::class);
        $chat->visitorMessage($chat->session($doomedSite, null)['conversation'], 'Hi');
        $chat->visitorMessage($chat->session($keptSite, null)['conversation'], 'Hello');

        $this->actingAs($this->user(Role::SuperAdmin));
        $invoice = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'amount' => 100])->json('id');
        $this->postJson("/api/invoices/{$invoice}/payments", ['amount' => 40, 'method' => 'cash'])->assertCreated();

        $this->deleteJson("/api/admin/brands/{$doomed->id}")->assertUnprocessable()
            ->assertJsonPath('requires_force', true)->assertJsonPath('leads', 2);
        $this->deleteJson("/api/admin/brands/{$doomed->id}?force=1")->assertOk();

        $this->assertNull($doomed->fresh());
        $this->assertSame(0, Lead::where('brand_id', $doomed->id)->count());
        $this->assertSame(0, Conversation::where('brand_id', $doomed->id)->count());
        $this->assertSame(0, Conversation::whereNull('brand_id')->count());
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame([$kept->id], $rep->fresh()->brandIds());
        // The other brand is untouched (its chat lead included)
        $this->assertNotNull($keptLead->fresh());
        $this->assertSame(2, Lead::where('brand_id', $kept->id)->count());
        $this->getJson('/api/chats')->assertOk()->assertJsonCount(1, 'conversations')->assertJsonPath('unlinked', 0);
    }

    public function test_converted_leads_rotate_between_up_sales_reps_single_and_bulk(): void
    {
        $brand = $this->brand();
        $site = $this->website($brand);
        $admin = $this->user(Role::Admin, [$brand]);
        $upA = $this->user(Role::UpSales, [$brand]);
        $upB = $this->user(Role::UpSales, [$brand]);
        $rep = $this->user(Role::FrontSales, [$brand]);
        $leads = collect(range(1, 4))->map(fn () => $this->lead($site, ['assignee_id' => $rep->id]));

        $this->actingAs($rep);
        $this->patchJson("/api/leads/{$leads[0]->id}", ['status' => 'converted'])->assertOk();
        $this->patchJson("/api/leads/{$leads[1]->id}", ['status' => 'converted'])->assertOk();
        $this->assertSame([$upA->id, $upB->id], [$leads[0]->fresh()->assignee_id, $leads[1]->fresh()->assignee_id]);

        $this->actingAs($admin);
        $this->postJson('/api/leads/bulk', ['action' => 'status', 'ids' => [$leads[2]->id, $leads[3]->id], 'status' => 'converted'])
            ->assertOk()->assertJsonPath('updated', 2);
        $this->assertEqualsCanonicalizing([$upA->id, $upB->id], [$leads[2]->fresh()->assignee_id, $leads[3]->fresh()->assignee_id]);
        $this->assertSame('converted_handoff', $leads[2]->activities()->where('type', 'assigned')->latest('id')->first()->data['reason']);

        // An Up Sales rep converting a lead they already hold keeps it
        $held = $this->lead($site, ['assignee_id' => $upB->id]);
        $this->actingAs($upB);
        $this->patchJson("/api/leads/{$held->id}", ['status' => 'converted'])->assertOk();
        $this->assertSame($upB->id, $held->fresh()->assignee_id);
    }
}
