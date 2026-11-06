<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;

/**
 * Card payments through Stripe, called over plain HTTP so there is nothing to install on
 * shared hosting. The card form is Stripe's own iframe: card numbers never touch this server.
 */
class StripePayments
{
    /** Currencies Stripe counts in whole units, not cents */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** A payment in these states can still be completed, so it is reused instead of started again */
    private const OPEN_STATUSES = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

    /** A signed webhook older than this is rejected, so a captured request can't be replayed */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function configured(): bool
    {
        return config('services.stripe.secret') && config('services.stripe.publishable');
    }

    public static function unitsPerMajor(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 1 : 100;
    }

    /** The invoice's balance in the smallest unit of its currency, as Stripe counts money */
    public static function amountDue(Invoice $invoice): int
    {
        return (int) round($invoice->balanceDue() * self::unitsPerMajor($invoice->currency));
    }

    /**
     * The payment to confirm for this invoice. One per invoice: two open tabs share it, so the
     * client can't be charged twice. Call with the invoice row locked.
     *
     * @return array<string, mixed> the PaymentIntent
     */
    public function intentFor(Invoice $invoice): array
    {
        $amount = self::amountDue($invoice);
        $intent = $invoice->stripe_intent_id ? $this->intent($invoice->stripe_intent_id) : null;

        if ($intent && in_array($intent['status'], self::OPEN_STATUSES, true)) {
            return (int) $intent['amount'] === $amount
                ? $intent
                : $this->client()->asForm()->post('/v1/payment_intents/'.rawurlencode($intent['id']), ['amount' => $amount])->throw()->json();
        }
        if ($intent && in_array($intent['status'], ['processing', 'succeeded'], true)) {
            return $intent;
        }

        $merchant = $invoice->merchantName();
        $created = $this->client()->asForm()->post('/v1/payment_intents', array_filter([
            'amount' => $amount,
            'currency' => strtolower($invoice->currency),
            'description' => "Invoice {$invoice->number} · {$merchant}",
            'receipt_email' => $invoice->lead?->email,
            'metadata' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'merchant' => $merchant],
        ]))->throw()->json();
        $invoice->update(['stripe_intent_id' => $created['id']]);

        return $created;
    }

    /** @return array<string, mixed>|null */
    public function intent(string $id): ?array
    {
        $response = $this->client()->get('/v1/payment_intents/'.rawurlencode($id));

        return $response->successful() ? $response->json() : null;
    }

    /** Checks a webhook really came from Stripe: "Stripe-Signature: t=...,v1=..." */
    public function validSignature(string $payload, ?string $header): bool
    {
        $secret = config('services.stripe.webhook_secret');
        if (! $secret || ! $header) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if (! $timestamp || abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function client()
    {
        return Http::baseUrl(config('services.stripe.api_base'))
            ->withToken((string) config('services.stripe.secret'))
            ->timeout(15);
    }
}
