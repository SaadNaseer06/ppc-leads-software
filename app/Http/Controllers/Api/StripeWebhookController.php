<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\InvoicePayments;
use App\Services\StripeCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Stripe confirms a card payment here, even if the client closed the tab before returning */
class StripeWebhookController extends Controller
{
    private const PAID_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];

    public function __invoke(Request $request, StripeCheckout $stripe, InvoicePayments $payments): JsonResponse
    {
        $payload = $request->getContent();
        if (! $stripe->validSignature($payload, $request->header('Stripe-Signature'))) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = json_decode($payload, true);
        $type = (string) ($event['type'] ?? '');
        $recorded = in_array($type, self::PAID_EVENTS, true) && $payments->recordCheckout($event['data']['object'] ?? []);

        WebhookEvent::firstOrCreate(
            ['provider' => 'stripe', 'event_id' => $event['id'] ?? null],
            ['event' => mb_substr($type, 0, 60), 'status' => $recorded ? 'processed' : 'ignored'],
        );

        return response()->json(['ok' => true]);
    }
}
