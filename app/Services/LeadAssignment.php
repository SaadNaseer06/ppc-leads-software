<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadAssignment
{
    /**
     * Assigns (or unassigns, when $toUserId is null) one or many leads in a single
     * transaction and records a timeline entry for every lead that changed.
     *
     * @param  list<int>  $leadIds
     * @return int number of leads that changed hands
     */
    public function assign(array $leadIds, ?int $toUserId, ?int $actorId, string $reason = 'manual'): int
    {
        $leads = Lead::whereIn('id', $leadIds)->get(['id', 'brand_id', 'assignee_id']);
        if ($leads->isEmpty()) {
            throw new AssignmentException('No leads found');
        }

        if ($toUserId !== null) {
            $assignee = User::find($toUserId);
            if (! $assignee || ! $assignee->active) {
                throw new AssignmentException('Assignee not found or inactive');
            }
            if (! in_array($assignee->role, Role::assignable(), true) && ! $assignee->hasGlobalAccess()) {
                throw new AssignmentException("This user's role cannot receive leads");
            }
            if (! $assignee->hasGlobalAccess()) {
                $allowed = $assignee->brandIds();
                if ($leads->contains(fn (Lead $l) => ! in_array((int) $l->brand_id, $allowed, true))) {
                    throw new AssignmentException("Assignee does not have access to every selected lead's brand");
                }
            }
        }

        $changed = $leads->filter(fn (Lead $l) => $l->assignee_id === null ? $toUserId !== null : (int) $l->assignee_id !== $toUserId);
        if ($changed->isEmpty()) {
            return 0;
        }

        $now = now();
        DB::transaction(function () use ($changed, $toUserId, $actorId, $reason, $now) {
            Lead::whereIn('id', $changed->pluck('id'))->update([
                'assignee_id' => $toUserId,
                'assigned_at' => $toUserId ? $now : null,
                'last_activity_at' => $now,
            ]);
            DB::table('lead_activities')->insert($changed->map(fn (Lead $l) => [
                'lead_id' => $l->id,
                'actor_id' => $actorId,
                'type' => $toUserId ? 'assigned' : 'unassigned',
                'data' => json_encode(['from_user_id' => $l->assignee_id, 'to_user_id' => $toUserId, 'reason' => $reason]),
                'created_at' => $now,
            ])->values()->all());
        });

        ActivityLog::record($actorId, $changed->count() > 1 ? 'lead.bulk_assign' : 'lead.assign', 'Lead',
            $changed->count() === 1 ? $changed->first()->id : null,
            ['to_user_id' => $toUserId, 'count' => $changed->count(), 'reason' => $reason]);

        return $changed->count();
    }

    /**
     * Round-robin among active Front Sales users with access to the brand. The
     * brand row is locked (SELECT ... FOR UPDATE) so concurrent intakes never hand
     * two leads to the same person out of turn; deadlocks are retried.
     */
    public function autoAssign(Lead $lead): ?int
    {
        return DB::transaction(function () use ($lead) {
            $brand = Brand::whereKey($lead->brand_id)->lockForUpdate()->first(['id', 'round_robin_last_user_id']);
            if (! $brand) {
                return null;
            }

            $eligible = User::where('active', true)
                ->where('role', Role::FrontSales->value)
                ->whereHas('brands', fn ($q) => $q->where('brands.id', $brand->id))
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($eligible === []) {
                return null;
            }

            $last = array_search((int) $brand->round_robin_last_user_id, $eligible, true);
            $next = $eligible[$last === false ? 0 : ($last + 1) % count($eligible)];

            $brand->update(['round_robin_last_user_id' => $next]);
            Lead::whereKey($lead->id)->update(['assignee_id' => $next, 'assigned_at' => now()]);
            $lead->activities()->create([
                'type' => 'assigned',
                'data' => ['from_user_id' => null, 'to_user_id' => $next, 'reason' => 'auto_round_robin'],
            ]);

            return $next;
        }, attempts: 5);
    }

    /** @param list<int> $leadIds */
    public function bulkStatus(array $leadIds, LeadStatus $status, int $actorId): int
    {
        $leads = Lead::whereIn('id', $leadIds)->where('status', '!=', $status->value)->get(['id', 'status']);
        if ($leads->isEmpty()) {
            return 0;
        }

        $now = now();
        DB::transaction(function () use ($leads, $status, $actorId, $now) {
            Lead::whereIn('id', $leads->pluck('id'))->update(['status' => $status->value, 'last_activity_at' => $now]);
            DB::table('lead_activities')->insert($leads->map(fn (Lead $l) => [
                'lead_id' => $l->id,
                'actor_id' => $actorId,
                'type' => 'status',
                'data' => json_encode(['from' => $l->status, 'to' => $status->value]),
                'created_at' => $now,
            ])->values()->all());
        });
        ActivityLog::record($actorId, 'lead.bulk_status', 'Lead', null, ['status' => $status->value, 'count' => $leads->count()]);

        return $leads->count();
    }

    /** Status change with a timeline entry. Converting hands the lead to Up Sales. */
    public function changeStatus(Lead $lead, LeadStatus $status, int $actorId, ?int $handoffUserId = null): void
    {
        if ($lead->status === $status->value) {
            return;
        }

        DB::transaction(function () use ($lead, $status, $actorId) {
            $from = $lead->status;
            $lead->update(['status' => $status->value, 'last_activity_at' => now()]);
            $lead->activities()->create([
                'actor_id' => $actorId,
                'type' => 'status',
                'data' => ['from' => $from, 'to' => $status->value],
            ]);
        });

        if ($status === LeadStatus::Converted) {
            $toUserId = $handoffUserId ?? User::where('active', true)
                ->where('role', Role::UpSales->value)
                ->whereHas('brands', fn ($q) => $q->where('brands.id', $lead->brand_id))
                ->orderBy('id')
                ->value('id');
            if ($toUserId) {
                $this->assign([$lead->id], (int) $toUserId, $actorId, 'converted_handoff');
            }
        }
    }

    /** Open leads of a deactivated user go back to the unassigned queue */
    public function releaseOpenLeads(User $user, int $actorId): int
    {
        $released = 0;
        while (true) {
            $ids = Lead::where('assignee_id', $user->id)
                ->whereIn('status', LeadStatus::open())
                ->limit(1000)
                ->pluck('id')
                ->all();
            if ($ids === []) {
                return $released;
            }
            $released += $this->assign($ids, null, $actorId, 'user_deactivated');
        }
    }
}
