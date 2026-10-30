<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Cheap, index-backed, capped counters for the sidebar badges and the new-lead pill */
class LiveCounts
{
    public function for(User $user, ?CarbonInterface $since): array
    {
        return [
            'new_leads' => $since
                ? $this->capped(Lead::query()->visibleTo($user)->where('created_at', '>', $since)->where('status', '!=', LeadStatus::Spam->value), 100)
                : 0,
            'unassigned' => $user->canAssignLeads()
                ? $this->capped(Lead::query()->visibleTo($user)->whereNull('assignee_id')->where('status', LeadStatus::New->value), 1000)
                : 0,
            'my_new' => $this->capped(Lead::where('assignee_id', $user->id)->where('status', LeadStatus::New->value), 1000),
            'active_chats' => $user->canViewChats()
                ? $this->capped(Conversation::query()->visibleTo($user)->live(), 1000)
                : 0,
        ];
    }

    private function capped(Builder $query, int $cap): int
    {
        return DB::query()->fromSub($query->select($query->getModel()->getQualifiedKeyName())->limit($cap), 't')->count();
    }
}
