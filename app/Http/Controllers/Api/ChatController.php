<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\NativeChatService;
use App\Services\TeamDirectory;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    private const PAGE = 30;

    public function __construct(
        private NativeChatService $native,
        private TeamDirectory $team,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canViewChats(), 403);

        $kind = in_array($request->query('kind'), ['chat', 'ticket'], true) ? $request->query('kind') : null;
        $query = Conversation::query()->visibleTo($user)->started()->with(['website:id,name', 'lead:id,name,assignee_id']);
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
            ? Conversation::query()->visibleTo($user)->with(['website:id,name,domain', 'lead.assignee:id,name'])->find($selectedId)
            : null;
        // The newest 500 messages, oldest first
        $selected?->setRelation('messages', $selected->messages()
            ->orderByDesc('sent_at')->orderByDesc('id')->limit(500)->get()->reverse()->values());

        $assignees = ($user->canAssignLeads() && $selected?->brand_id)
            ? $this->team->assignable($user, $selected->brand_id)
            : [];

        $openCount = Conversation::query()->visibleTo($user)->live()->count();

        return response()->json([
            'conversations' => $rows->map(fn (Conversation $c) => $this->row($c)),
            'has_more' => $hasMore,
            'selected' => $selected ? [
                ...$this->row($selected),
                ...$selected->only(['visitor_name', 'visitor_email', 'city', 'country', 'subject', 'started_at', 'website_id', 'brand_id', 'page_url']),
                'messages' => $selected->messages->map->only(['id', 'sender_type', 'sender_name', 'body', 'sent_at']),
                'lead' => $selected->lead ? [
                    ...$selected->lead->only(['id', 'name', 'status', 'assignee_id']),
                    'assignee' => $selected->lead->assignee?->name,
                    'assignee_id' => $selected->lead->assignee_id,
                ] : null,
            ] : null,
            'assignees' => $assignees,
            'open_count' => $openCount,
            // Tawk.to chats from a property no website is linked to yet
            'unlinked' => $user->hasGlobalAccess()
                ? Conversation::whereNull('website_id')->whereNotNull('property_id')->count()
                : 0,
            'pusher' => Realtime::config(),
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

    /** Admins and Super Admins remove chats (one or a selection); linked leads stay */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canDeleteChats(), 403);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $deleted = $this->native->delete($user, $data['ids']);
        if ($deleted === 0) {
            return response()->json(['error' => 'None of the selected chats are available'], 404);
        }

        return response()->json(['deleted' => $deleted]);
    }

    private function row(Conversation $c): array
    {
        return $c->toInboxRow();
    }
}
