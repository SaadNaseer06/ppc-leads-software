<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

class Invoice extends Model
{
    public const STATUSES = ['sent', 'paid', 'cancelled'];

    public const PAYMENT_METHODS = ['bank_transfer', 'card', 'cash', 'cheque', 'paypal', 'stripe', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'line_items' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasGlobalAccess() || $user->role === Role::Finance) {
            return $query;
        }
        if ($user->role === Role::Admin) {
            return $query->whereIn('brand_id', $user->brandIds());
        }

        return $query->where('created_by_id', $user->id);
    }

    /** Creates the invoice with the next INV-#### number, retrying if two requests race */
    public static function createWithNumber(array $attributes): self
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = self::query()->orderByDesc('id')->value('number');
            $next = ((int) preg_replace('/\D/', '', (string) $last) ?: 1000) + 1 + $attempt;
            try {
                return self::create([...$attributes, 'number' => "INV-{$next}"]);
            } catch (UniqueConstraintViolationException) {
                // another request took this number; try the next one
            }
        }
        throw new \RuntimeException('Could not allocate an invoice number');
    }
}
