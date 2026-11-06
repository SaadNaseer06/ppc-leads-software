<?php

namespace App\Services;

use App\Models\Brand;
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

    /** Whether this brand's clients can pay by card. With no brand: the server-wide keys in .env. */
    public function configured(?Brand $brand = null): bool
    {
        $account = $this->account($brand);

        return $account['secret'] && $account['publishable'] && $this->keyProblem($account['publishable'], $account['secret']) === null;
    }

    /**
     * A brand's own Stripe account, or the server-wide keys if it has none.
     *
     * @return array{publishable: ?string, secret: ?string, webhook: ?string, own: bool}
     */
    public function account(?Brand $brand = null): array
    {
        if ($brand?->stripe_secret_key) {
            return [
                'publishable' => $brand->stripe_publishable_key,
                'secret' => $brand->stripe_secret_key,
                'webhook' => $brand->stripe_webhook_secret,
                'own' => true,
            ];
        }

        return [
            'publishable' => config('services.stripe.publishable'),
            'secret' => config('services.stripe.secret'),
            'webhook' => config('services.stripe.webhook_secret'),
            'own' => false,
        ];
    }

    /**
     * What the Invoices page shows about a brand. Details go to the people who can change them,
     * and never include a secret: only its last four characters.
     *
     * @return array<string, mixed>
     */
    public function brandStatus(Brand $brand, bool $details): array
    {
        $account = $this->account($brand);
        $on = $this->configured($brand);
        $status = ['id' => $brand->id, 'name' => $brand->name, 'card_payments' => $on, 'webhook' => (bool) $account['webhook']];
        if (! $details) {
            return $status;
        }

        return [
            ...$status,
            'merchant_name' => $brand->merchant_name,
            'source' => $account['own'] ? 'own' : ($on ? 'env' : null),
            'mode' => $on ? (str_contains((string) $account['publishable'], '_live_') ? 'live' : 'test') : null,
            'account_name' => $account['own'] ? $brand->stripe_account_name : null,
            'publishable_key' => $account['own'] ? $brand->stripe_publishable_key : null,
            'secret_hint' => $account['own'] ? '…'.substr((string) $brand->stripe_secret_key, -4) : null,
            'webhook_url' => url("/api/webhooks/stripe/{$brand->id}"),
        ];
    }

    /**
     * Asks Stripe whether a secret key works, before anyone relies on it.
     *
     * @return array{valid: bool, name: ?string}
     */
    public function checkSecretKey(string $secret): array
    {
        $response = $this->client(null, $secret)->get('/v1/account');

        // A restricted key may not read the account, but Stripe still recognised it
        if ($response->status() === 403) {
            return ['valid' => true, 'name' => null];
        }
        if ($response->status() === 401) {
            return ['valid' => false, 'name' => null];
        }
        $response->throw();

        return ['valid' => true, 'name' => $response->json('business_profile.name') ?: $response->json('settings.dashboard.display_name')];
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
            'problem' => $this->keyProblem((string) config('services.stripe.publishable'), (string) config('services.stripe.secret')),
            'webhook' => (bool) config('services.stripe.webhook_secret'),
        ];
    }

    /** A swapped or mixed pair would show a card form that can only fail */
    public function keyProblem(string $publishable, string $secret): ?string
    {
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
        $brand = $invoice->brand;
        $intent = $invoice->stripe_intent_id ? $this->intent($invoice->stripe_intent_id, $brand) : null;

        if ($intent && in_array($intent['status'], self::OPEN_STATUSES, true)) {
            return (int) $intent['amount'] === $amount
                ? $intent
                : $this->client($brand)->asForm()->post('/v1/payment_intents/'.rawurlencode($intent['id']), ['amount' => $amount])->throw()->json();
        }
        if ($intent && in_array($intent['status'], ['processing', 'succeeded'], true)) {
            return $intent;
        }

        $merchant = $invoice->merchantName();
        $created = $this->client($brand)->asForm()->post('/v1/payment_intents', array_filter([
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
        $brand = $invoice->brand;
        if (! $invoice->stripe_intent_id || ! $this->configured($brand)) {
            return;
        }
        rescue(function () use ($invoice, $brand) {
            $intent = $this->intent($invoice->stripe_intent_id, $brand);
            if ($intent && in_array($intent['status'], self::OPEN_STATUSES, true)) {
                $this->client($brand)->asForm()->post('/v1/payment_intents/'.rawurlencode($intent['id']).'/cancel')->throw();
            }
        });
    }

    /** @return array<string, mixed>|null */
    public function intent(string $id, ?Brand $brand = null): ?array
    {
        $response = $this->client($brand)->get('/v1/payment_intents/'.rawurlencode($id));

        return $response->successful() ? $response->json() : null;
    }

    /** Checks a webhook really came from Stripe: "Stripe-Signature: t=...,v1=..." */
    public function validSignature(string $payload, ?string $header, ?string $secret): bool
    {
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

    private function client(?Brand $brand = null, ?string $secret = null)
    {
        return Http::baseUrl(config('services.stripe.api_base'))
            ->withToken((string) ($secret ?? $this->account($brand)['secret']))
            ->timeout(15);
    }
}
