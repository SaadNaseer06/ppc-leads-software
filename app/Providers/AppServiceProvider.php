<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Limits live in the cache store, so they are shared by every app instance
        RateLimiter::for('login', fn (Request $request) => Limit::perMinutes(15, 10)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip())
            ->response(function (Request $request) {
                $message = 'Too many sign-in attempts. Try again in a few minutes.';

                // The sign-in page shows it under the form; API clients get JSON
                return $request->is('api/*')
                    ? response()->json(['error' => $message], 429)
                    : back()->withErrors(['email' => $message]);
            }));

        RateLimiter::for('intake', fn (Request $request) => Limit::perMinutes(10, 20)
            ->by($request->route('key').'|'.$request->ip())
            ->response(fn () => response()->json(['ok' => false, 'error' => 'Too many submissions. Please try again later.'], 429)));
    }
}
