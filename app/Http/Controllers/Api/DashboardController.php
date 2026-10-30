<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Website;
use App\Services\Dashboard;
use App\Services\LiveCounts;
use App\Services\TeamDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, Dashboard $dashboard): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'stats' => $dashboard->data($user, (int) $request->query('range', 30)),
            'recent_leads' => Lead::query()->visibleTo($user)
                ->where('status', '!=', LeadStatus::Spam->value)
                ->with(['website:id,name', 'assignee:id,name'])
                ->orderByDesc('created_at')->orderByDesc('id')->limit(7)
                ->get(['id', 'name', 'status', 'channel', 'created_at', 'website_id', 'assignee_id']),
            'recent_chats' => $user->canViewChats()
                ? Conversation::query()->visibleTo($user)->started()->with('website:id,name')
                    ->orderByDesc('last_message_at')->limit(5)->get()
                    ->map(fn (Conversation $c) => [
                        ...$c->only(['id', 'kind', 'status', 'last_message', 'last_message_at']),
                        'display_name' => $c->displayName(),
                        'website' => $c->website?->only(['id', 'name']),
                    ])
                : [],
        ]);
    }

    /** Polled by the dashboard shell every 10s so new leads and chats appear without a refresh */
    public function live(Request $request, LiveCounts $live): JsonResponse
    {
        $since = null;
        try {
            $since = $request->query('since') ? CarbonImmutable::parse($request->query('since'))->utc() : null;
        } catch (\Throwable) {
        }

        return response()->json([...$live->for($request->user(), $since), 'server_time' => now()->toIso8601String()])
            ->header('Cache-Control', 'no-store');
    }

    /** Options for pickers: websites, brands and (for admins) people who can receive leads */
    public function lookups(Request $request, TeamDirectory $team): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'websites' => Website::query()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'brand_id']),
            'brands' => Brand::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'assignees' => $user->canAssignLeads() ? $team->assignable($user) : [],
        ]);
    }
}
