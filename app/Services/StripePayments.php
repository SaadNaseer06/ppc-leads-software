<?php

namespace App\Services;

use App\Models\Invoice;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Http;
use Throwable;

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

    /** Each setting and the .env line that fills it */
    private const SETTINGS = [
        'STRIPE_PUBLISHABLE_KEY' => 'services.stripe.publishable',
        'STRIPE_SECRET_KEY' => 'services.stripe.secret',
        'STRIPE_WEBHOOK_SECRET' => 'services.stripe.webhook_secret',
    ];

    public function configured(): bool
    {
        return $this->keyProblem() === null
            && config('services.stripe.secret') && config('services.stripe.publishable');
    }

    /**
     * Why card payments are on or off, for the people who can fix it. Names and yes/no only:
     * key values never leave the server.
     *
     * @return array{card_payments: bool, mode: ?string, missing: list<string>, needs_reload: bool, problem: ?string, webhook: bool}
     */
    public function status(): array
    {
        $file = $this->envFile();
        $unset = array_keys(array_filter(self::SETTINGS, fn ($key) => ! config($key)));

        return [
            'card_payments' => $this->configured(),
            'mode' => str_contains((string) config('services.stripe.publishable'), '_live_') ? 'live' : (config('services.stripe.publishable') ? 'test' : null),
            'missing' => array_values(array_filter($unset, fn ($name) => empty($file[$name]))),
            // In .env, but not what the running app has: it is still on settings cached before the edit
            'needs_reload' => collect(self::SETTINGS)->contains(fn ($key, $name) => ! empty($file[$name]) && $file[$name] !== config($key)),
            'problem' => $this->keyProblem(),
            'webhook' => (bool) config('services.stripe.webhook_secret'),
        ];
    }

    /** A swapped or mixed pair would show a card form that can only fail */
    private function keyProblem(): ?string
    {
        $publishable = (string) config('services.stripe.publishable');
        $secret = (string) config('services.stripe.secret');

        if ($publishable !== '' && ! str_starts_with($publishable, 'pk_')) {
            return 'STRIPE_PUBLISHABLE_KEY should start with pk_. Check the two keys are not swapped.';
        }
        if ($secret !== '' && ! preg_match('/^(sk|rk)_/', $secret)) {
            return 'STRIPE_SECRET_KEY should start with sk_. Check the two keys are not swapped.';
        }
        if ($publishable !== '' && $secret !== '' && str_contains($publishable, '_live_') !== str_contains($secret, '_live_')) {
            return 'One Stripe key is a test key and the other is live. Both must be from the same mode.';
        }

        return null;
    }

    /** @return array<string, string|null> */
    private function envFile(): array
    {
        try {
            return Dotenv::parse((string) @file_get_contents(app()->environmentFilePath()));
        } catch (Throwable) {
            return [];
        }
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
            'receipt_email' => $invoice->client_email,
            'metadata' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'merchant' => $merchant],
        ]))->throw()->json();
        $invoice->update(['stripe_intent_id' => $created['id']]);

        return $created;
    }

    /** After deleting or cancelling, a tab left open must not still be able to charge the client */
    public function cancelOpenIntent(Invoice $invoice): void
    {
        if (! $invoice->stripe_intent_id || ! $this->configured()) {
            return;
        }
        rescue(function () use ($invoice) {
            $intent = $this->intent($invoice->stripe_intent_id);
            if ($intent && in_array($intent['status'], self::OPEN_STATUSES, true)) {
                $this->client()->asForm()->post('/v1/payment_intents/'.rawurlencode($intent['id']).'/cancel')->throw();
            }
        });
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
