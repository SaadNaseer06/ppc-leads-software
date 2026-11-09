<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Services\StripePayments;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Each brand's merchant name and Stripe account. Whoever sets these decides where clients' money
 * goes, so it's Super Admin only, every change is logged, and the secret never comes back out.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(private StripePayments $stripe) {}

    /** The Payments page: every brand's merchant and Stripe account, and the server-wide fallback */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);

        return response()->json([
            'brands' => Brand::orderBy('name')->get()->map(fn (Brand $b) => $this->stripe->brandStatus($b, true))->values(),
            'server' => $this->stripe->status(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $brand = $this->brand($request, $id);
        $data = $request->validate([
            'merchant_name' => ['nullable', 'string', 'max:120'],
            'publishable_key' => ['required', 'string', 'max:255', 'starts_with:pk_'],
            'secret_key' => ['nullable', 'string', 'max:255', 'regex:/^(sk|rk)_/'],
            'webhook_secret' => ['nullable', 'string', 'max:255', 'starts_with:whsec_'],
        ], [
            'publishable_key.required' => 'Paste the publishable key from Stripe',
            'publishable_key.starts_with' => 'The publishable key starts with pk_',
            'secret_key.regex' => 'The secret key starts with sk_',
            'webhook_secret.starts_with' => 'The webhook signing secret starts with whsec_',
        ]);

        // Left blank means keep what's saved, so nobody has to paste it again to fix a name
        $secret = $data['secret_key'] ?? $brand->stripe_secret_key;
        if (! $secret) {
            return response()->json(['error' => 'Paste the secret key from Stripe'], 422);
        }
        if ($problem = $this->stripe->keyProblem($data['publishable_key'], $secret)) {
            return response()->json(['error' => $problem], 422);
        }

        try {
            $account = $this->stripe->checkSecretKey($secret);
        } catch (ConnectionException|RequestException $e) {
            report($e);

            return response()->json(['error' => "We couldn't reach Stripe to check that key. Nothing was saved. Please try again."], 503);
        }
        if (! $account['valid']) {
            return response()->json(['error' => "Stripe didn't accept that secret key. Copy it again from your Stripe dashboard."], 422);
        }

        $brand->update([
            'merchant_name' => $data['merchant_name'] ?? $brand->merchant_name,
            'stripe_publishable_key' => $data['publishable_key'],
            'stripe_secret_key' => $secret,
            'stripe_webhook_secret' => $data['webhook_secret'] ?? $brand->stripe_webhook_secret,
            'stripe_account_name' => $account['name'],
        ]);
        ActivityLog::record($request->user()->id, 'brand.stripe_connect', 'Brand', $brand->id, [
            'mode' => str_contains($data['publishable_key'], '_live_') ? 'live' : 'test',
            'account' => $account['name'],
        ]);

        return response()->json(['brand' => $this->stripe->brandStatus($brand, true)]);
    }

    /** Back to the server-wide keys, if there are any */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $brand = $this->brand($request, $id);
        $brand->update([
            'stripe_publishable_key' => null,
            'stripe_secret_key' => null,
            'stripe_webhook_secret' => null,
            'stripe_account_name' => null,
        ]);
        ActivityLog::record($request->user()->id, 'brand.stripe_disconnect', 'Brand', $brand->id);

        return response()->json(['brand' => $this->stripe->brandStatus($brand, true)]);
    }

    private function brand(Request $request, int $id): Brand
    {
        abort_unless($request->user()->canManagePayments(), 403);

        return Brand::findOrFail($id);
    }
}
