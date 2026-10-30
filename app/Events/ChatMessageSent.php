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

    public function __construct(public ConversationMessage $message) {}

    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;

        return array_values(array_filter([
            new PrivateChannel('chat.'.$this->message->conversation_id),
            $conversation?->brand_id ? new PrivateChannel('chats.'.$conversation->brand_id) : null,
        ]));
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->message->conversation_id,
            'message' => [
                'id' => $this->message->id,
                'sender_type' => $this->message->sender_type,
                'sender_name' => $this->message->sender_name,
                'body' => $this->message->body,
                'sent_at' => $this->message->sent_at?->toIso8601String(),
            ],
        ];
    }
}
