<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Tells the lead's rep a client has replied, on whatever screen they're on. Names only: no addresses. */
class LeadEmailReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Lead $lead, public int $userId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("leads.user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'LeadEmailReceived';
    }

    public function broadcastWith(): array
    {
        return ['lead_id' => $this->lead->id, 'name' => $this->lead->name];
    }
}
