<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A chat was handed to a sales rep: it lands in their inbox and they get a notice on any screen. */
class ChatTransferred implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param array<string, mixed> $row the conversation's inbox row */
    public function __construct(public int $toUserId, public array $row, public string $fromName) {}

    public function broadcastOn(): array
    {
        // leads.user is open on every screen; chats.user only while the Chats page is
        return [new PrivateChannel('chats.user.'.$this->toUserId), new PrivateChannel('leads.user.'.$this->toUserId)];
    }

    public function broadcastAs(): string
    {
        return 'ChatTransferred';
    }

    public function broadcastWith(): array
    {
        return ['conversation' => $this->row, 'from' => $this->fromName];
    }
}
