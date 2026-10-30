<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "Lead counts changed" ping with no data: open dashboards refresh their badges when it
 * arrives instead of polling for them. Carrying nothing, it can't leak a lead to anyone.
 */
class LeadsChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param list<string> $channels */
    public function __construct(public array $channels) {}

    public function broadcastOn(): array
    {
        return array_map(fn (string $name) => new PrivateChannel($name), $this->channels);
    }

    public function broadcastAs(): string
    {
        return 'LeadsChanged';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
