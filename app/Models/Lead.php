<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'assigned_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'promoted_at' => 'datetime',
            'submission_count' => 'integer',
        ];
    }

    /** Form / manual / converted chat leads that belong on the Leads page */
    public function scopeInInbox(Builder $query): Builder
    {
        return $query->whereNotNull('leads.promoted_at');
    }

    public function isInInbox(): bool
    {
        return $this->promoted_at !== null;
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Leads with an invoice still waiting to be paid. Those stay with Front Sales: Up Sales only
     * takes a client once the first deal is paid for.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function idsWithUnpaidInvoice(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Invoice::whereIn('lead_id', $ids)->where('status', 'sent')->distinct()->pluck('lead_id')->map(fn ($id) => (int) $id)->all();
    }

    public function hasUnpaidInvoice(): bool
    {
        return self::idsWithUnpaidInvoice([$this->id]) !== [];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Leads the user may see: everything, their brands, or only their own */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasGlobalAccess()) {
            return $query;
        }
        $query->whereIn('leads.brand_id', $user->brandIds());
        // Chat Agents see the brand too: they open lead cards from chats
        if (! $user->role->seesWholeBrand()) {
            $query->where('leads.assignee_id', $user->id);
        }

        return $query;
    }

    /**
     * The same rule as a raw SQL condition on `leads`, for hand-written aggregate queries.
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function visibilitySql(User $user): array
    {
        if ($user->hasGlobalAccess()) {
            return ['1 = 1', []];
        }
        $brands = $user->brandIds();
        if ($brands === []) {
            return ['1 = 0', []];
        }
        $sql = 'brand_id IN ('.implode(',', array_fill(0, count($brands), '?')).')';
        if (! $user->role->seesWholeBrand()) {
            return ["{$sql} AND assignee_id = ?", [...$brands, $user->id]];
        }

        return [$sql, $brands];
    }
}
