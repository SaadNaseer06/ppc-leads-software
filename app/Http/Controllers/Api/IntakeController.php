<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadSource;
use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\LeadIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public lead intake. Called from visitors' browsers (embed script, plain HTML
 * forms) and from servers (WordPress/Elementor webhooks, Zapier, custom code).
 */
class IntakeController extends Controller
{
    private const MAX_BODY_BYTES = 100_000;

    public function store(Request $request, string $key, LeadIntake $intake): JsonResponse|RedirectResponse
    {
        $website = Website::where('public_key', $key)->first();
        if (! $website) {
            return response()->json(['ok' => false, 'error' => 'Unknown website key'], 404);
        }
        if (! $website->active) {
            return response()->json(['ok' => false, 'error' => 'Lead capture is paused for this website'], 403);
        }

        $body = $this->body($request);
        if ($body === null) {
            return response()->json(['ok' => false, 'error' => 'Could not read the submission body'], 400);
        }

        // Plain HTML forms can post here directly and send the visitor to a thank-you page
        $redirect = $this->safeRedirect($body['_redirect'] ?? $body['_next'] ?? null);
        unset($body['_redirect'], $body['_next']);
        $done = fn (int $status, array $payload) => $redirect ? redirect()->away($redirect, 303) : response()->json($payload, $status);

        $submission = $intake->parseSubmission($body);
        if ($submission['is_spam']) {
            Log::info('Intake honeypot triggered', ['website_id' => $website->id, 'ip' => $request->ip()]);

            return $done(200, ['ok' => true]);
        }
        if (! $submission['email'] && ! $submission['phone'] && ! $submission['message']) {
            return response()->json(['ok' => false, 'error' => 'A submission needs at least an email, phone or message'], 422);
        }

        $result = $intake->ingest($website->brand_id, $website, LeadSource::Form, [
            ...$submission,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $done($result['duplicate'] ? 200 : 201, [
            'ok' => true,
            'lead_id' => $result['lead_id'],
            'duplicate' => $result['duplicate'],
        ]);
    }

    /** JSON, form-encoded, multipart, or JSON sent as text/plain by navigator.sendBeacon */
    private function body(Request $request): ?array
    {
        $type = (string) $request->header('Content-Type');
        if (str_contains($type, 'multipart/form-data') || str_contains($type, 'application/x-www-form-urlencoded')) {
            return $request->except(array_keys($request->allFiles()));
        }

        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return null;
        }
        $json = json_decode($raw, true);
        if (is_array($json) && ! array_is_list($json)) {
            return $json;
        }
        if (str_contains($raw, '=')) {
            parse_str($raw, $parsed);

            return $parsed;
        }

        return null;
    }

    private function safeRedirect(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }
}
