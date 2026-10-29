<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Website extends Model
{
    protected $fillable = [
        'brand_id', 'name', 'domain', 'public_key', 'tawk_property_id', 'tawk_webhook_secret',
        'active', 'last_lead_at', 'last_chat_at',
    ];

    protected $hidden = ['tawk_webhook_secret'];

    protected function casts(): array
    {
        return [
            'tawk_webhook_secret' => 'encrypted',
            'active' => 'boolean',
            'last_lead_at' => 'datetime',
            'last_chat_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasGlobalAccess() ? $query : $query->whereIn('brand_id', $user->brandIds());
    }

    public static function generatePublicKey(): string
    {
        return 'pk_'.Str::random(24);
    }

    /** "https://www.Example.com/contact" -> "example.com" */
    public static function normalizeDomain(string $input): ?string
    {
        $input = strtolower(trim($input));
        if ($input === '') {
            return null;
        }
        $host = parse_url(preg_match('#^https?://#', $input) ? $input : "https://{$input}", PHP_URL_HOST);
        if (! is_string($host) || ! str_contains($host, '.')) {
            return null;
        }

        return preg_replace('/^www\./', '', $host);
    }

    public function embedSnippet(): string
    {
        return '<script src="'.url('/ppc-leads.js').'" data-site="'.$this->public_key.'" async></script>';
    }

    public function intakeUrl(): string
    {
        return url("/api/intake/{$this->public_key}");
    }
}
