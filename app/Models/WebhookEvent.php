<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** Raw inbound webhook log: idempotency for retries and integration debugging */
class WebhookEvent extends Model
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
        return ['payload' => 'array'];
    }
}
