<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Dashboard sign-in with a session cookie */
class SessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', ['showDemo' => ! app()->isProduction()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $credentials['email'] = strtolower(trim($credentials['email']));
        if (! Auth::attempt([...$credentials, 'active' => true])) {
            throw ValidationException::withMessages(['email' => "That email and password don't match an active account."]);
        }

        $request->session()->regenerate();
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();
        Log::info('User signed in', ['user_id' => $user->id, 'role' => $user->role->value]);

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
