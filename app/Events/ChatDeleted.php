<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Chats were deleted: open inboxes drop them, and visitors' widgets offer a new chat.
 * One event for a whole selection, so deleting 50 test chats is one Pusher call.
 */
class ChatDeleted implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<int>  $conversationIds
     * @param  list<int>  $brandIds
     * @param  list<int>  $assigneeIds
     */
    public function __construct(public array $conversationIds, public array $brandIds, public array $assigneeIds) {}

    public function broadcastOn(): array
    {
        return [
            ...array_map(fn (int $id) => new PrivateChannel('chat.'.$id), $this->conversationIds),
            ...array_map(fn (int $id) => new PrivateChannel('chats.'.$id), $this->brandIds),
            new PrivateChannel('chats.all'),
            ...array_map(fn (int $id) => new PrivateChannel('chats.user.'.$id), $this->assigneeIds),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ChatDeleted';
    }

    public function broadcastWith(): array
    {
        return ['conversation_ids' => $this->conversationIds];
    }
}
