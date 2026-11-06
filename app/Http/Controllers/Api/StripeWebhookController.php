<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\WebhookEvent;
use App\Services\InvoicePayments;
use App\Services\StripePayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Stripe confirms a card payment here, even if the client closed the tab before returning */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripePayments $stripe, InvoicePayments $payments): JsonResponse
    {
        // Each brand with its own Stripe account has its own address, signed with its own secret
        $brand = $request->route('brand') ? Brand::findOrFail($request->route('brand')) : null;
        $secret = $brand ? $brand->stripe_webhook_secret : config('services.stripe.webhook_secret');

        $payload = $request->getContent();
        if (! $stripe->validSignature($payload, $request->header('Stripe-Signature'), $secret)) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = json_decode($payload, true);
        $type = (string) ($event['type'] ?? '');
        $recorded = $type === 'payment_intent.succeeded' && $payments->recordIntent($event['data']['object'] ?? [], $brand?->id);

        WebhookEvent::firstOrCreate(
            ['provider' => 'stripe', 'event_id' => $event['id'] ?? null],
            ['event' => mb_substr($type, 0, 60), 'status' => $recorded ? 'processed' : 'ignored'],
        );

        return response()->json(['ok' => true]);
    }
}
