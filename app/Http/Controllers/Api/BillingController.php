<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\StripePayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/** Lets the team see why card payments are off, and fix the commonest cause without a terminal */
class BillingController extends Controller
{
    /** The app keeps settings cached, so keys added to .env do nothing until the cache is rebuilt */
    public function reloadSettings(Request $request, StripePayments $stripe): JsonResponse
    {
        abort_unless($request->user()->canManageBrands(), 403);
        abort_unless($stripe->status()['needs_reload'], 422, 'The settings are already up to date.');

        try {
            Artisan::call('config:cache');
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => "The settings couldn't be reloaded here. Run php artisan optimize on the server."], 500);
        }
        ActivityLog::record($request->user()->id, 'billing.reload_settings', 'Invoice');

        return response()->json(['ok' => true]);
    }
}
