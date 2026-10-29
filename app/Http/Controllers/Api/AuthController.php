<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeamDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /** Exchanges email + password for an API token (used by the dashboard's server) */
    public function login(Request $request, TeamDirectory $team): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower(trim($data['email'])))->first();
        if (! $user || ! $user->active || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['error' => "That email and password don't match an active account."], 401);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('dashboard', ['*'], now()->addMinutes(config('sanctum.expiration')));
        Log::info('User signed in', ['user_id' => $user->id, 'role' => $user->role->value]);

        return response()->json(['token' => $token->plainTextToken, 'user' => $team->profile($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request, TeamDirectory $team): JsonResponse
    {
        return response()->json(['user' => $team->profile($request->user())]);
    }
}
