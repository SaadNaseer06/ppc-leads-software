<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\NativeChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pusher\Pusher;

class NativeChatController extends Controller
{
    public function __construct(private NativeChatService $chat) {}

    /** Create or resume a visitor chat session for a website public key. */
    public function session(Request $request): JsonResponse
    {
        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:64'],
            'visitor_token' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $website = Website::where('public_key', $data['public_key'])->first();
        abort_unless($website, 404, 'Unknown website key');
        abort_unless($website->active, 403, 'This website is not accepting chats');
        abort_unless($website->chat_enabled, 403, 'Live chat is disabled for this website');

        $result = $this->chat->session(
            $website,
            $data['visitor_token'] ?? null,
            $data['page_url'] ?? null,
        );

        $conversation = $result['conversation'];
        // The newest 200 messages, oldest first
        $conversation->setRelation('messages', $conversation->messages()
            ->orderByDesc('sent_at')->orderByDesc('id')->limit(200)->get()->reverse()->values());

        return response()->json([
            'conversation_id' => $conversation->id,
            'visitor_token' => $result['visitor_token'],
            'status' => $conversation->status,
            'visitor_name' => $conversation->visitor_name,
            'visitor_email' => $conversation->visitor_email,
            'created' => $result['created'],
            'messages' => $conversation->messages->map->only(['id', 'sender_type', 'sender_name', 'body', 'sent_at']),
            'pusher' => $this->pusherPublicConfig(),
        ]);
    }

    /** Optional pre-chat / mid-chat name and email. */
    public function visitor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_token' => ['required', 'string', 'max:80'],
            'name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
        ]);

        $conversation = $this->chat->findByVisitorToken($data['visitor_token']);
        abort_unless($conversation, 404, 'Chat session not found');
        abort_unless($conversation->status === 'open', 422, 'This chat has ended');

        $conversation = $this->chat->updateVisitor(
            $conversation,
            $data['name'] ?? null,
            $data['email'] ?? null,
        );

        return response()->json([
            'ok' => true,
            'visitor_name' => $conversation->visitor_name,
            'visitor_email' => $conversation->visitor_email,
        ]);
    }

    /** Visitor sends a message. */
    public function storeMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_token' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $conversation = $this->chat->findByVisitorToken($data['visitor_token']);
        abort_unless($conversation, 404, 'Chat session not found');

        $message = $this->chat->visitorMessage($conversation, $data['body']);

        return response()->json([
            'message' => $message->only(['id', 'sender_type', 'sender_name', 'body', 'sent_at']),
        ], 201);
    }

    /** Poll for new messages when Pusher is unavailable. */
    public function messages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_token' => ['required', 'string', 'max:80'],
            'since_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $conversation = $this->chat->findByVisitorToken($data['visitor_token']);
        abort_unless($conversation, 404, 'Chat session not found');

        $since = (int) ($data['since_id'] ?? 0);
        $messages = $conversation->messages()
            ->when($since > 0, fn ($q) => $q->where('id', '>', $since))
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'sender_type', 'sender_name', 'body', 'sent_at']);

        return response()->json([
            'status' => $conversation->status,
            'messages' => $messages,
        ]);
    }

    /**
     * Authorize a visitor for private-chat.{id} using their visitor token
     * (dashboard users use /broadcasting/auth via Sanctum instead).
     */
    public function auth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_token' => ['required', 'string', 'max:80'],
            'socket_id' => ['required', 'string', 'max:64'],
            'channel_name' => ['required', 'string', 'max:191'],
        ]);

        $conversation = $this->chat->findByVisitorToken($data['visitor_token']);
        abort_unless($conversation, 403, 'Unauthorized');

        $expected = 'private-chat.'.$conversation->id;
        abort_unless($data['channel_name'] === $expected, 403, 'Unauthorized');

        $pusher = $this->pusherClient();
        abort_unless($pusher, 503, 'Realtime chat is not configured');

        $auth = $pusher->authorizeChannel($data['channel_name'], $data['socket_id']);

        return response()->json(json_decode($auth, true));
    }

    private function pusherPublicConfig(): ?array
    {
        $key = config('broadcasting.connections.pusher.key');
        if (! $key || config('broadcasting.default') === 'null' || config('broadcasting.default') === 'log') {
            // Still return key/cluster when set so the widget can try; poll works either way
            if (! $key) {
                return null;
            }
        }

        return [
            'key' => $key,
            'cluster' => config('broadcasting.connections.pusher.options.cluster') ?: 'mt1',
            'auth_endpoint' => url('/api/chat/auth'),
        ];
    }

    private function pusherClient(): ?Pusher
    {
        $cfg = config('broadcasting.connections.pusher');
        if (! ($cfg['key'] ?? null) || ! ($cfg['secret'] ?? null) || ! ($cfg['app_id'] ?? null)) {
            return null;
        }

        return new Pusher(
            $cfg['key'],
            $cfg['secret'],
            $cfg['app_id'],
            $cfg['options'] ?? [],
        );
    }
}
