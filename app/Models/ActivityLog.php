<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** Audit trail: assignments, exports, user and website changes */
class ActivityLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** Kept for 90 days (pruned daily by the scheduler) */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(90));
    }

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public static function record(?int $actorId, string $action, string $entity, int|string|null $entityId = null, array $details = []): void
    {
        try {
            self::create([
                'actor_id' => $actorId,
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId !== null ? (string) $entityId : null,
                'details' => $details ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e); // auditing must never break the action itself
        }
    }
}
