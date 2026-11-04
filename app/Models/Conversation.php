<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A native or Tawk.to live chat / offline message (ticket) */
class Conversation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['visitor_token'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'transferred_at' => 'datetime',
            'last_message_at' => 'datetime',
            'message_count' => 'integer',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasGlobalAccess()) {
            return $query;
        }
        // Admins, Brand Managers and Chat Agents staff every conversation in their brands
        if ($user->canOverseeBrandChats()) {
            return $query->whereIn('brand_id', $user->brandIds());
        }
        // Reps see chats handed to them, and chats of the leads they own
        if ($user->isSales()) {
            return $query->where(fn (Builder $w) => $w
                ->where(fn (Builder $q) => $q->where('assigned_agent_id', $user->id)->whereIn('brand_id', $user->brandIds()))
                ->orWhereHas('lead', fn (Builder $q) => $q->where('assignee_id', $user->id)));
        }

        return $query->whereRaw('1 = 0');
    }

    /** Widget sessions appear once the visitor writes; just opening the chat bubble isn't a conversation */
    public function scopeStarted(Builder $query): Builder
    {
        return $query->where('conversations.message_count', '>', 0);
    }

    /** Chats still in progress: open and with a message in the last `ppc.active_chat_hours` */
    public function scopeLive(Builder $query): Builder
    {
        return $query->started()->where('conversations.status', 'open')
            ->where('conversations.last_message_at', '>', now()->subHours(config('ppc.active_chat_hours')))
            ->where('conversations.kind', 'chat');
    }

    public function isLive(): bool
    {
        return $this->status === 'open' && $this->kind === 'chat' && $this->message_count > 0
            && $this->last_message_at?->gt(now()->subHours(config('ppc.active_chat_hours')));
    }

    /** One inbox row: the Chats list, and the realtime event that updates it in place */
    public function toInboxRow(): array
    {
        return [
            ...$this->only(['id', 'kind', 'provider', 'status', 'last_message', 'last_message_at', 'message_count', 'property_name']),
            'display_name' => $this->displayName(),
            'visitor_email' => $this->visitor_email,
            'is_live' => $this->isLive(),
            'assigned_to' => $this->assignedAgent?->name,
            'website' => $this->website?->only(['id', 'name']),
            'can_reply' => $this->provider === 'native' && $this->status === 'open',
        ];
    }

    /** Tawk names anonymous visitors like "V1561719148780935" */
    public static function realVisitorName(?string $name): ?string
    {
        $name = $name !== null ? trim($name) : null;

        return $name && ! preg_match('/^V\d{6,}$/', $name) ? $name : null;
    }

    public function displayName(): string
    {
        if ($named = self::realVisitorName($this->visitor_name)) {
            return $named;
        }
        $leadName = $this->relationLoaded('lead') ? $this->lead?->name : null;
        if ($leadName && ! str_starts_with($leadName, 'Chat visitor') && strcasecmp($leadName, 'Anonymous visitor') !== 0) {
            return $leadName;
        }

        return $this->city ? "Visitor from {$this->city}" : 'Website visitor';
    }
}
