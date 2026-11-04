<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class Invoice extends Model
{
    public const STATUSES = ['sent', 'paid', 'cancelled'];

    public const PAYMENT_METHODS = ['bank_transfer', 'card', 'cash', 'cheque', 'paypal', 'stripe', 'other'];

    protected $guarded = ['id'];

    /** Only the finished link leaves the server */
    protected $hidden = ['pay_token'];

    protected $appends = ['pay_url', 'is_overdue'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_items' => 'array',
            'due_date' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            $invoice->pay_token ??= Str::random(40);
        });
    }

    /** The page the client opens to view and pay this invoice */
    public function getPayUrlAttribute(): ?string
    {
        return $this->pay_token ? url("/pay/{$this->pay_token}") : null;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'sent' && $this->due_date?->lt(now(config('ppc.report_timezone'))->startOfDay()) === true;
    }

    public function paidTotal(): float
    {
        return round((float) ($this->paid_total ?? $this->payments()->sum('amount')), 2);
    }

    public function balanceDue(): float
    {
        return max(0, round((float) $this->amount - $this->paidTotal(), 2));
    }

    /**
     * Line items with quantities, ready to print. Invoices from before line items were
     * added hold one row with just a label and an amount.
     *
     * @return list<array{label: string, quantity: float, unit_price: float, amount: float}>
     */
    public function items(): array
    {
        $items = $this->line_items ?: [['label' => $this->description ?: 'Services', 'amount' => (float) $this->amount]];

        return array_map(fn (array $item) => [
            'label' => $item['label'],
            'quantity' => (float) ($item['quantity'] ?? 1),
            'unit_price' => (float) ($item['unit_price'] ?? $item['amount']),
            'amount' => (float) $item['amount'],
        ], $items);
    }

    /**
     * Works out an invoice from its items: the server's sums are the ones that count.
     *
     * @param  list<array{label: string, quantity: float|int|string, unit_price: float|int|string}>  $items
     * @return array{line_items: list<array<string, mixed>>, subtotal: float, discount: float, tax_rate: float, tax_amount: float, amount: float}
     */
    public static function priced(array $items, float $discount, float $taxRate): array
    {
        $lines = array_map(fn (array $item) => [
            'label' => trim($item['label']),
            'quantity' => (float) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'amount' => round((float) $item['quantity'] * (float) $item['unit_price'], 2),
        ], $items);

        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $discount = round($discount, 2);
        $tax = round(($subtotal - $discount) * $taxRate / 100, 2);

        return [
            'line_items' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax_rate' => round($taxRate, 2),
            'tax_amount' => $tax,
            'amount' => round($subtotal - $discount + $tax, 2),
        ];
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
        if ($user->managesBrands()) {
            return $query->whereIn('invoices.brand_id', $user->brandIds());
        }

        return $query->where('invoices.created_by_id', $user->id);
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
