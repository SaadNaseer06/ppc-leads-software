<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Services\ContactMask;
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
        $query = Conversation::query()->visibleTo($user)->started()
            ->with(['website:id,name', 'lead:id,name,assignee_id', 'assignedAgent:id,name']);
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
                ->with(['website:id,name,domain', 'lead.assignee:id,name', 'assignedAgent:id,name', 'transferredBy:id,name'])
                ->find($selectedId)
            : null;
        // The newest 500 messages, oldest first
        $selected?->setRelation('messages', $selected->messages()
            ->orderByDesc('sent_at')->orderByDesc('id')->limit(500)->get()->reverse()->values());

        $assignees = ($user->canAssignLeads() && $selected?->brand_id)
            ? $this->team->assignable($user, $selected->brand_id, ! ($selected->lead_id && Lead::idsWithUnpaidInvoice([(int) $selected->lead_id])))
            : [];

        $openCount = Conversation::query()->visibleTo($user)->live()->count();

        // Chat agents and reps see visitors' email addresses masked, like every other contact detail
        $mask = ! $user->seesFullContacts();
        $hide = fn (array $row) => $mask && array_key_exists('visitor_email', $row) ? [...$row, 'visitor_email' => ContactMask::email($row['visitor_email'])] : $row;

        return response()->json([
            'conversations' => $rows->map(fn (Conversation $c) => $hide($this->row($c))),
            'has_more' => $hasMore,
            'selected' => $selected ? $hide([
                ...$this->row($selected),
                ...$selected->only(['visitor_name', 'visitor_email', 'city', 'country', 'subject', 'started_at', 'website_id', 'brand_id', 'page_url']),
                'messages' => $selected->messages->map->only(['id', 'sender_type', 'sender_name', 'body', 'sent_at']),
                'lead' => $selected->lead && $selected->lead->isInInbox() ? [
                    ...$selected->lead->only(['id', 'name', 'status', 'assignee_id']),
                    'assignee' => $selected->lead->assignee?->name,
                    'assignee_id' => $selected->lead->assignee_id,
                    'promoted' => true,
                ] : null,
                // Admins and Chat Agents can promote an unconverted chat onto the Leads page
                'can_convert' => $user->canConvertChatsToLeads() && ! $selected->lead?->isInInbox(),
                'transfer' => $selected->transferred_at ? [
                    'by' => $selected->transferredBy?->name,
                    'to_id' => $selected->assigned_agent_id,
                    'to' => $selected->assignedAgent?->name,
                    'at' => $selected->transferred_at,
                    'note' => $selected->transfer_note,
                ] : null,
            ]) : null,
            'assignees' => $assignees,
            // Who this chat can be handed to
            'front_sales' => ($user->canOverseeBrandChats() && $selected?->brand_id) ? $this->team->frontSales($selected->brand_id) : [],
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

    /** Move a chat onto the Leads page (Admins and Chat Agents) */
    public function convert(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canConvertChatsToLeads(), 403);

        $conversation = Conversation::query()->visibleTo($user)->findOrFail($id);
        $lead = $this->native->convertToLead($conversation, $user)->load('assignee:id,name');

        return response()->json([
            'ok' => true,
            'lead' => [
                'id' => $lead->id,
                'name' => $lead->name,
                'status' => $lead->status,
                'assignee_id' => $lead->assignee_id,
                'assignee' => $lead->assignee?->name,
                'promoted' => true,
            ],
        ]);
    }

    /** Hand a chat to a Front Sales rep (whoever staffs the brand's inbox) */
    public function transfer(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canOverseeBrandChats(), 403);
        $conversation = Conversation::query()->visibleTo($user)->with('lead')->findOrFail($id);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'convert' => ['sometimes', 'boolean'],
        ], ['user_id.required' => 'Choose a Front Sales rep']);

        $to = User::findOrFail($data['user_id']);
        $conversation = $this->native->transfer($conversation, $to, $user, $data['note'] ?? null, (bool) ($data['convert'] ?? false));

        return response()->json(['ok' => true, 'conversation' => $this->row($conversation)]);
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
