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
            'last_email_at' => 'datetime',
            'email_unread' => 'boolean',
            'email_needs_reply' => 'boolean',
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

    public function emails(): HasMany
    {
        return $this->hasMany(LeadEmail::class);
    }

    /**
     * Moves the email conversation on: a client's email is unread and waiting for an answer; one from
     * the team answers it (and whoever answered has clearly read it).
     */
    public function recordEmail(string $direction): void
    {
        $inbound = $direction === 'in';
        $this->update([
            'last_activity_at' => now(),
            'last_email_at' => now(),
            'email_unread' => $inbound,
            'email_needs_reply' => $inbound,
        ]);
    }

    /** The lead's own rep reading it clears "unread"; while nobody owns the lead, anyone working it does */
    /** Leads left with no emails: no conversation, nothing unread, nothing to answer. Never touches updated_at. */
    public static function clearEmailThreads(array $ids): void
    {
        static::query()->toBase()->whereIn('id', $ids)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('lead_emails')->whereColumn('lead_emails.lead_id', 'leads.id'))
            ->update(['last_email_at' => null, 'email_unread' => false, 'email_needs_reply' => false]);
    }

    public function markEmailsReadBy(User $user): void
    {
        if ($this->email_unread && $user->canWorkLeads() && (int) ($this->assignee_id ?? $user->id) === $user->id) {
            // Reading isn't a change to the lead: no events, and updated_at stays put
            $this->newQuery()->toBase()->where('id', $this->id)->update(['email_unread' => false]);
            $this->setAttribute('email_unread', false)->syncOriginalAttribute('email_unread');
        }
    }

    /** Threads waiting on this person: on their own leads, or on leads nobody owns yet */
    public function scopeEmailUnreadFor(Builder $query, User $user): Builder
    {
        return $query->where('leads.email_unread', true)
            ->where(fn ($q) => $q->where('leads.assignee_id', $user->id)->orWhereNull('leads.assignee_id'));
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
