<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A mailbox on a brand's domain: one person's (riley@brand.com), or the brand's shared one (sales@) when user_id is null */
class Mailbox extends Model
{
    protected $guarded = ['id'];

    /** Whoever holds the password reads and sends as this address: it never leaves the server */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'checked_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cpanelAccount(): BelongsTo
    {
        return $this->belongsTo(CpanelAccount::class);
    }

    /**
     * The address a person sends from for a brand: their own, or else the brand's shared one.
     */
    public static function forSender(int $brandId, ?User $user): ?self
    {
        return self::where('brand_id', $brandId)
            ->where(fn ($q) => $q->whereNull('user_id')->when($user, fn ($w) => $w->orWhere('user_id', $user->id)))
            // Their own first
            ->orderByRaw('user_id IS NULL')
            ->first();
    }
}
