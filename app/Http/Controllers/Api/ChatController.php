<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\NativeChatService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    private const PAGE = 30;

    public function __construct(private NativeChatService $native) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canViewChats(), 403);

        $kind = in_array($request->query('kind'), ['chat', 'ticket'], true) ? $request->query('kind') : null;
        $query = Conversation::query()->visibleTo($user)->with('website:id,name');
        if ($kind) {
            $query->where('kind', $kind);
        }
        try {
            if ($before = $request->query('before')) {
                $query->where('last_message_at', '<', CarbonImmutable::parse($before)->utc());
            }
        } catch (\Throwable) {
        }

        $rows = $query->orderByDesc('last_message_at')->orderByDesc('id')->limit(self::PAGE + 1)->get();
        $hasMore = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);

        $selectedId = $request->query('id') ?? $rows->first()?->id;
        $selected = $selectedId
            ? Conversation::query()->visibleTo($user)
                ->with(['messages' => fn ($q) => $q->orderBy('sent_at')->limit(500), 'website:id,name,domain', 'lead.assignee:id,name'])
                ->find($selectedId)
            : null;

        return response()->json([
            'conversations' => $rows->map(fn (Conversation $c) => $this->row($c)),
            'has_more' => $hasMore,
            'selected' => $selected ? [
                ...$this->row($selected),
                ...$selected->only(['visitor_email', 'city', 'country', 'subject', 'started_at', 'website_id']),
                'messages' => $selected->messages->map->only(['id', 'sender_type', 'sender_name', 'body', 'sent_at']),
                'lead' => $selected->lead ? [
                    ...$selected->lead->only(['id', 'name', 'status']),
                    'assignee' => $selected->lead->assignee?->name,
                ] : null,
            ] : null,
            'unlinked' => $user->hasGlobalAccess() ? Conversation::whereNull('website_id')->count() : 0,
            'pusher' => [
                'key' => config('broadcasting.connections.pusher.key'),
                'cluster' => config('broadcasting.connections.pusher.options.cluster') ?: 'mt1',
            ],
        ]);
    }

    public function storeMessage(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canViewChats(), 403);

        $conversation = Conversation::query()->visibleTo($user)->findOrFail($id);
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        $message = $this->native->agentReply($conversation, $user, $data['body']);

        return response()->json([
            'message' => $message->only(['id', 'sender_type', 'sender_name', 'body', 'sent_at']),
        ], 201);
    }

    public function end(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canViewChats(), 403);

        $conversation = Conversation::query()->visibleTo($user)->findOrFail($id);
        $conversation = $this->native->end($conversation);

        return response()->json([
            'ok' => true,
            'status' => $conversation->status,
            'ended_at' => $conversation->ended_at,
        ]);
    }

    private function row(Conversation $c): array
    {
        return [
            ...$c->only(['id', 'kind', 'provider', 'status', 'last_message', 'last_message_at', 'message_count', 'property_name']),
            'display_name' => $c->displayName(),
            'website' => $c->website?->only(['id', 'name']),
            'can_reply' => $c->provider === 'native' && $c->status === 'open',
        ];
    }
}
