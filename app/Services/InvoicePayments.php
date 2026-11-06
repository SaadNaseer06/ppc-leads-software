<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/** The one place money gets recorded against an invoice, by hand or from the client's card */
class InvoicePayments
{
    /**
     * Adds a payment and marks the invoice paid once payments cover it. Call inside a
     * transaction with the invoice row locked.
     *
     * @return float total paid so far
     */
    public function add(Invoice $invoice, float $amount, string $method, ?string $reference = null, ?string $notes = null): float
    {
        $invoice->payments()->create([
            'amount' => $amount,
            'method' => $method,
            'external_ref' => $reference,
            'notes' => $notes,
            'recorded_at' => now(),
        ]);

        $paid = round((float) $invoice->payments()->sum('amount'), 2);
        $paidInFull = $paid >= (float) $invoice->amount;
        if ($paidInFull && $invoice->status !== 'paid') {
            $invoice->update(['status' => 'paid']);
        }

        $invoice->lead?->activities()->create([
            'type' => 'payment',
            'data' => [
                'number' => $invoice->number,
                'amount' => $amount,
                'currency' => $invoice->currency,
                'method' => $method,
                'paid_in_full' => $paidInFull,
            ],
        ]);

        return $paid;
    }

    /**
     * Records a completed Stripe payment. Stripe may report the same payment through the
     * webhook and the client's return to the site, so a repeat changes nothing.
     *
     * @param  array<string, mixed>  $intent
     * @param  int|null  $brandId  when set, only this brand's invoices can be paid by it
     */
    public function recordIntent(array $intent, ?int $brandId = null): bool
    {
        $invoiceId = $intent['metadata']['invoice_id'] ?? null;
        if (! $invoiceId || ($intent['status'] ?? null) !== 'succeeded') {
            return false;
        }

        return DB::transaction(function () use ($intent, $invoiceId, $brandId) {
            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);
            if (! $invoice || ($brandId && $invoice->brand_id !== $brandId) || $invoice->payments()->where('method', 'stripe')->where('external_ref', $intent['id'])->exists()) {
                return false;
            }
            $amount = (int) $intent['amount_received'] / StripePayments::unitsPerMajor($invoice->currency);
            $this->add($invoice, $amount, 'stripe', $intent['id'], 'Paid online by the client');

            return true;
        });
    }
}
