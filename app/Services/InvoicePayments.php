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
     * Records a completed Stripe Checkout session. Stripe may report the same session through
     * the webhook and the client's return to the site, so a repeat changes nothing.
     *
     * @param  array<string, mixed>  $session
     */
    public function recordCheckout(array $session): bool
    {
        $invoiceId = $session['metadata']['invoice_id'] ?? null;
        if (! $invoiceId || ($session['payment_status'] ?? null) !== 'paid') {
            return false;
        }

        return DB::transaction(function () use ($session, $invoiceId) {
            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);
            if (! $invoice || $invoice->payments()->where('method', 'stripe')->where('external_ref', $session['id'])->exists()) {
                return false;
            }
            $amount = (int) $session['amount_total'] / StripeCheckout::unitsPerMajor($invoice->currency);
            $this->add($invoice, $amount, 'stripe', $session['id'], 'Paid online by the client');

            return true;
        });
    }
}
