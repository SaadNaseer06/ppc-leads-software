<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\TawkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tawk.to retries for up to 12 hours on any non-2xx response and resends the
 * same X-Hook-Event-Id each time, which is used to skip duplicates.
 */
class TawkWebhookController extends Controller
{
    public function __invoke(Request $request, TawkService $tawk): JsonResponse
    {
        $raw = $request->getContent();
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'Invalid JSON'], 400);
        }

        $eventId = $request->header('X-Hook-Event-Id');
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : 'unknown';
        $propertyId = is_string($payload['property']['id'] ?? null) ? $payload['property']['id'] : null;

        if ($eventId && WebhookEvent::where('provider', 'tawk')->where('event_id', $eventId)
            ->whereIn('status', ['processed', 'ignored'])->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $website = $tawk->resolveWebsite($propertyId, is_string($payload['domain'] ?? null) ? $payload['domain'] : null);
        $secrets = array_values(array_filter([$website?->tawk_webhook_secret, config('ppc.tawk_webhook_secret')]));

        if (! $tawk->verifySignature($raw, $request->header('X-Tawk-Signature'), $secrets)) {
            // Only log rejections for known properties, so random traffic can't fill the table
            if ($website) {
                $this->record($eventId, $event, $propertyId, 'rejected', $secrets ? 'Signature mismatch' : 'No webhook secret configured');
            }

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        try {
            $status = $tawk->handle($payload, $website?->active ? $website : null);
            $this->record($eventId, $event, $propertyId, $status, $website ? null : 'Tawk property is not linked to a website yet');
            Log::info('Tawk webhook', ['event' => $event, 'property_id' => $propertyId, 'website_id' => $website?->id]);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            report($e);
            $this->record($eventId, $event, $propertyId, 'failed', $e->getMessage(), $payload);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    private function record(?string $eventId, string $event, ?string $propertyId, string $status, ?string $error = null, ?array $payload = null): void
    {
        try {
            $values = [
                'event' => mb_substr($event, 0, 60),
                'property_id' => $propertyId,
                'status' => $status,
                'error' => $error,
                'payload' => $payload,
            ];
            $eventId
                ? WebhookEvent::updateOrCreate(['provider' => 'tawk', 'event_id' => $eventId], $values)
                : WebhookEvent::create(['provider' => 'tawk', ...$values]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
