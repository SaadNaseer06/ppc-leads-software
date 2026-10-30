<?php

namespace App\Http\Middleware;

use App\Services\LiveCounts;
use App\Services\TeamDirectory;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** The root template loaded on the first page visit */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props every page receives. Closures are only evaluated when needed, so a
     * partial reload that skips them costs no queries.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $user ? app(TeamDirectory::class)->profile($user) : null,
            ],
            'app' => [
                'env' => app()->environment('production') ? 'production' : (app()->environment('staging') ? 'staging' : 'development'),
                'timezone' => config('ppc.report_timezone'),
                // Pusher settings for live badges and chats (null: the server doesn't broadcast)
                'realtime' => Realtime::config(),
            ],
            // Starting values for the sidebar badges; the browser polls /api/live after that
            'live' => fn () => $user
                ? [...app(LiveCounts::class)->for($user, null), 'server_time' => now()->toIso8601String()]
                : null,
        ];
    }
}
