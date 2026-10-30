<?php

namespace App\Events;

use App\Models\ConversationMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired for every new chat message so the widget and dashboard update in realtime via Pusher. */
class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Characters of a message sent through Pusher (escaped Unicode can take 6 bytes each) */
    private const MAX_BODY = 1000;

    public function __construct(public ConversationMessage $message) {}

    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;
        $assigneeId = $conversation?->lead?->assignee_id;

        return array_values(array_filter([
            new PrivateChannel('chat.'.$this->message->conversation_id),
            $conversation?->brand_id ? new PrivateChannel('chats.'.$conversation->brand_id) : null,
            // Super Admin / Developer inboxes (they belong to no brand)
            new PrivateChannel('chats.all'),
            // The sales rep whose lead this chat is
            $assigneeId ? new PrivateChannel('chats.user.'.$assigneeId) : null,
        ]));
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }

    public function broadcastWith(): array
    {
        $conversation = $this->message->conversation;
        // Pusher rejects events over 10 KB: long messages are cut and clients fetch the full text
        $truncated = mb_strlen($this->message->body) > self::MAX_BODY;
        $row = $conversation?->toInboxRow();
        if ($row) {
            $row['last_message'] = mb_substr((string) $row['last_message'], 0, 200);
        }

        return [
            'conversation_id' => $this->message->conversation_id,
            // The widget closes its composer as soon as an agent ends the chat
            'status' => $conversation?->status,
            'truncated' => $truncated,
            'message' => [
                'id' => $this->message->id,
                'sender_type' => $this->message->sender_type,
                'sender_name' => $this->message->sender_name,
                'body' => $truncated ? mb_substr($this->message->body, 0, self::MAX_BODY) : $this->message->body,
                'sent_at' => $this->message->sent_at?->toIso8601String(),
            ],
            // Open inboxes update this row in place instead of re-downloading the whole list
            'conversation' => $row,
        ];
    }
}
