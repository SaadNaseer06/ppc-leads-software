<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;

/**
 * Card payments through Stripe's hosted Checkout page, called over plain HTTP: nothing to
 * install on shared hosting, and card details never touch this server.
 */
class StripeCheckout
{
    /** Currencies Stripe counts in whole units, not cents */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** A signed webhook older than this is rejected, so a captured request can't be replayed */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function configured(): bool
    {
        return (bool) config('services.stripe.secret');
    }

    public static function unitsPerMajor(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 1 : 100;
    }

    /**
     * Starts a payment for the invoice's balance.
     *
     * @return array<string, mixed> the Checkout session; send the client to its `url`
     */
    public function createSession(Invoice $invoice): array
    {
        $lead = $invoice->lead;

        return $this->client()->asForm()->post('/v1/checkout/sessions', array_filter([
            'mode' => 'payment',
            'success_url' => url("/pay/{$invoice->pay_token}/return").'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => url("/pay/{$invoice->pay_token}"),
            'client_reference_id' => $invoice->number,
            'customer_email' => $lead?->email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($invoice->currency),
                    'unit_amount' => (int) round($invoice->balanceDue() * self::unitsPerMajor($invoice->currency)),
                    'product_data' => ['name' => "Invoice {$invoice->number} · {$invoice->brand->name}"],
                ],
            ]],
            'metadata' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number],
        ]))->throw()->json();
    }

    /** @return array<string, mixed>|null */
    public function session(string $id): ?array
    {
        $response = $this->client()->get('/v1/checkout/sessions/'.rawurlencode($id));

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
