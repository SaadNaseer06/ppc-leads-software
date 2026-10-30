<?php

namespace App\Services;

use App\Events\LeadsChanged;

/**
 * Collects whose lead counts changed during a request and pings their dashboards once,
 * after the response has been sent: Pusher latency never slows an intake or an assignment,
 * and a bulk action over 500 leads still sends a single event.
 */
class LiveSignal
{
    /** @var array<int, true> */
    private array $brands = [];

    /** @var array<int, true> */
    private array $users = [];

    private bool $scheduled = false;

    /**
     * @param  int|list<int|null>|null  $brandIds  brands whose admins see the change
     * @param  int|list<int|null>|null  $userIds  assignees whose own counts changed
     */
    public function leads(int|array|null $brandIds, int|array|null $userIds = null): void
    {
        foreach ((array) $brandIds as $id) {
            if ($id) {
                $this->brands[(int) $id] = true;
            }
        }
        foreach ((array) $userIds as $id) {
            if ($id) {
                $this->users[(int) $id] = true;
            }
        }
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;

        defer(function () {
            $channels = [
                'leads.all',
                ...array_map(fn (int $id) => "leads.brand.{$id}", array_keys($this->brands)),
                ...array_map(fn (int $id) => "leads.user.{$id}", array_keys($this->users)),
            ];
            $this->brands = $this->users = [];
            $this->scheduled = false;
            try {
                event(new LeadsChanged($channels));
            } catch (\Throwable $e) {
                report($e); // dashboards fall back to polling; the lead itself is saved
            }
        });
    }
}
