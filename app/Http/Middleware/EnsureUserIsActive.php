<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A deactivated user loses access on their very next request, not when the session ends */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->active) {
            $user->tokens()->delete();
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }

            return $request->expectsJson()
                ? response()->json(['error' => 'Your account has been deactivated.'], 401)
                : redirect('/login');
        }

        return $next($request);
    }
}
