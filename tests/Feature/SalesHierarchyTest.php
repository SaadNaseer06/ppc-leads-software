<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\User;
use App\Services\InvoicePayments;
use App\Services\LeadIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Front Sales closes the deal and collects the first payment. Up Sales only takes the client, and
 * sees their invoices, once that invoice is paid.
 */
class SalesHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private User $manager;

    private User $front;

    private User $up;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = $this->brand();
        $this->manager = $this->user(Role::Admin, [$this->brand]);
        $this->front = $this->user(Role::FrontSales, [$this->brand]);
        $this->up = $this->user(Role::UpSales, [$this->brand]);
    }

    private function lead(?User $assignee = null): Lead
    {
        $site = $this->website($this->brand);
        $lead = Lead::find(app(LeadIntake::class)->ingest($this->brand->id, $site, LeadSource::Form, ['email' => fake()->unique()->safeEmail()])['lead_id']);
        $lead->update(['assignee_id' => ($assignee ?? $this->front)->id]);

        return $lead->fresh();
    }

    /** An invoice raised by the manager, as usually happens */
    private function invoice(Lead $lead, ?User $by = null): Invoice
    {
        $this->actingAs($by ?? $this->manager);
        $id = $this->postJson('/api/invoices', ['lead_id' => $lead->id, 'items' => [['label' => 'Package', 'quantity' => 1, 'unit_price' => 500]]])
            ->assertCreated()->json('id');

        return Invoice::find($id);
    }

    private function seenBy(User $user): array
    {
        $this->actingAs($user);

        return array_column($this->getJson('/api/invoices')->assertOk()->json('invoices'), 'number');
    }

    public function test_the_assigned_front_sales_rep_sees_the_unpaid_invoice(): void
    {
        $invoice = $this->invoice($this->lead());
        $otherFront = $this->user(Role::FrontSales, [$this->brand]);

        $this->assertSame([$invoice->number], $this->seenBy($this->front), 'Raised by the manager, on their lead');
        $this->assertSame([], $this->seenBy($otherFront));

        $this->actingAs($this->front);
        $this->get("/api/invoices/{$invoice->id}/pdf")->assertOk();
    }

    public function test_up_sales_never_sees_an_unpaid_invoice_until_it_is_paid(): void
    {
        $lead = $this->lead($this->up);
        $invoice = $this->invoice($lead);

        $this->assertSame([], $this->seenBy($this->up));
        $this->actingAs($this->up);
        $this->get("/api/invoices/{$invoice->id}/pdf")->assertNotFound();
        $this->getJson("/api/leads/{$lead->id}")->assertOk()->assertJsonCount(0, 'invoices');

        $this->actingAs($this->manager);
        $this->patchJson("/api/invoices/{$invoice->id}", ['status' => 'paid'])->assertOk();

        $this->assertSame([$invoice->number], $this->seenBy($this->up));
        $this->actingAs($this->up);
        $this->getJson("/api/leads/{$lead->id}")->assertJsonCount(1, 'invoices');
    }

    public function test_up_sales_still_sees_invoices_they_raise_themselves(): void
    {
        $lead = $this->lead($this->up);
        $upsell = $this->invoice($lead, $this->up);

        $this->assertSame([$upsell->number], $this->seenBy($this->up));
    }

    public function test_a_lead_with_an_unpaid_invoice_cant_be_given_to_up_sales(): void
    {
        $lead = $this->lead();
        $invoice = $this->invoice($lead);
        $this->actingAs($this->manager);

        $this->patchJson("/api/leads/{$lead->id}", ['assignee_id' => $this->up->id])
            ->assertUnprocessable()->assertJsonPath('error', 'This lead has an unpaid invoice, so it stays with Front Sales until the invoice is paid.');
        $this->assertSame($this->front->id, $lead->fresh()->assignee_id);

        // Another Front Sales rep is fine
        $otherFront = $this->user(Role::FrontSales, [$this->brand]);
        $this->patchJson("/api/leads/{$lead->id}", ['assignee_id' => $otherFront->id])->assertOk();

        // Once paid, Up Sales can have it
        $this->patchJson("/api/invoices/{$invoice->id}", ['status' => 'paid'])->assertOk();
        $this->patchJson("/api/leads/{$lead->id}", ['assignee_id' => $this->up->id])->assertOk();
    }

    public function test_bulk_assign_refuses_the_whole_batch_if_any_lead_is_unpaid(): void
    {
        $clear = $this->lead();
        $owing = $this->lead();
        $this->invoice($owing);
        $this->actingAs($this->manager);

        $this->postJson('/api/leads/bulk', ['action' => 'assign', 'ids' => [$clear->id, $owing->id], 'assignee_id' => $this->up->id])
            ->assertUnprocessable()->assertJsonPath('error', fn ($e) => str_contains($e, '1 of the selected leads have unpaid invoices'));

        $this->assertSame($this->front->id, $clear->fresh()->assignee_id, 'Nothing was reassigned');
        $this->assertSame($this->front->id, $owing->fresh()->assignee_id);
    }

    public function test_up_sales_isnt_offered_for_an_unpaid_lead(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->manager);
        $this->assertContains($this->up->id, array_column($this->getJson("/api/leads/{$lead->id}")->json('assignees'), 'id'));

        $this->invoice($lead);
        $this->actingAs($this->manager);
        $page = $this->getJson("/api/leads/{$lead->id}")->assertJsonPath('unpaid_invoice', true);
        $this->assertNotContains($this->up->id, array_column($page->json('assignees'), 'id'));
        $this->assertContains($this->front->id, array_column($page->json('assignees'), 'id'));
    }

    public function test_converting_keeps_an_unpaid_lead_with_front_sales_then_hands_it_on_when_paid(): void
    {
        $lead = $this->lead();
        $invoice = $this->invoice($lead);
        $this->actingAs($this->manager);

        $this->patchJson("/api/leads/{$lead->id}", ['status' => 'converted'])->assertOk();
        $this->assertSame('converted', $lead->fresh()->status);
        $this->assertSame($this->front->id, $lead->fresh()->assignee_id, 'Stays with Front Sales while unpaid');

        // Paid by card through Stripe, recorded by the webhook
        app(InvoicePayments::class)->recordIntent(['id' => 'pi_1', 'status' => 'succeeded', 'amount_received' => 50000, 'metadata' => ['invoice_id' => (string) $invoice->id]]);

        $this->assertSame($this->up->id, $lead->fresh()->assignee_id, 'Moves on to Up Sales once paid');
        $this->assertSame('paid_handoff', $lead->activities()->where('type', 'assigned')->latest('id')->first()->data['reason']);
    }

    public function test_a_part_payment_doesnt_hand_the_lead_on(): void
    {
        $lead = $this->lead();
        $invoice = $this->invoice($lead);
        $this->actingAs($this->manager);
        $this->patchJson("/api/leads/{$lead->id}", ['status' => 'converted'])->assertOk();

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 200, 'method' => 'cash'])->assertCreated();
        $this->assertSame($this->front->id, $lead->fresh()->assignee_id);

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => 300, 'method' => 'cash'])->assertCreated();
        $this->assertSame($this->up->id, $lead->fresh()->assignee_id);
    }

    public function test_every_unpaid_invoice_must_be_settled_first(): void
    {
        $lead = $this->lead();
        $first = $this->invoice($lead);
        $this->invoice($lead);
        $this->actingAs($this->manager);
        $this->patchJson("/api/leads/{$lead->id}", ['status' => 'converted'])->assertOk();

        $this->patchJson("/api/invoices/{$first->id}", ['status' => 'paid'])->assertOk();

        $this->assertSame($this->front->id, $lead->fresh()->assignee_id, 'The second invoice is still unpaid');
    }

    public function test_an_explicit_hand_off_to_up_sales_is_refused_and_changes_nothing(): void
    {
        $lead = $this->lead();
        $this->invoice($lead);
        $this->actingAs($this->manager);

        $this->patchJson("/api/leads/{$lead->id}", ['status' => 'converted', 'handoff_user_id' => $this->up->id])->assertUnprocessable();

        $this->assertNotSame('converted', $lead->fresh()->status);
        $this->assertSame($this->front->id, $lead->fresh()->assignee_id);
    }

    public function test_bulk_converting_hands_on_only_the_paid_leads(): void
    {
        $clear = $this->lead();
        $owing = $this->lead();
        $this->invoice($owing);
        $this->actingAs($this->manager);

        $this->postJson('/api/leads/bulk', ['action' => 'status', 'ids' => [$clear->id, $owing->id], 'status' => 'converted'])->assertOk();

        $this->assertSame($this->up->id, $clear->fresh()->assignee_id);
        $this->assertSame($this->front->id, $owing->fresh()->assignee_id);
    }

    public function test_cancelled_or_deleted_invoices_dont_hold_a_lead_back(): void
    {
        $lead = $this->lead();
        $cancelled = $this->invoice($lead);
        $deleted = $this->invoice($lead);
        $this->actingAs($this->manager);
        $this->patchJson("/api/invoices/{$cancelled->id}", ['status' => 'cancelled'])->assertOk();
        $this->deleteJson("/api/invoices/{$deleted->id}")->assertOk();

        $this->patchJson("/api/leads/{$lead->id}", ['assignee_id' => $this->up->id])->assertOk();
    }
}
