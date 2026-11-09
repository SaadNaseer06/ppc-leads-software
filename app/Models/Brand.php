<?php

namespace App\Models;

use App\Enums\Role;
use App\Services\BrandTheme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Brand extends Model
{
    protected $fillable = [
        'name', 'slug', 'auto_assign_enabled', 'round_robin_last_user_id',
        'merchant_name', 'brand_color', 'logo_path', 'favicon_path', 'billing_email', 'billing_address', 'pay_domain', 'pay_domain_verified_at',
        'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret', 'stripe_account_name',
    ];

    /** A brand can end up in a JSON response through a relation: these must never go with it */
    protected $hidden = ['stripe_secret_key', 'stripe_webhook_secret', 'mail_password'];

    /** @var array<int, string>|null Connected invoice domains by brand, read once per request */
    private static ?array $payHosts = null;

    protected function casts(): array
    {
        return [
            'auto_assign_enabled' => 'boolean',
            'pay_domain_verified_at' => 'datetime',
            'stripe_secret_key' => 'encrypted',
            'stripe_webhook_secret' => 'encrypted',
            'mail_password' => 'encrypted',
            'mail_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::$payHosts = null);
        static::deleted(function (Brand $brand) {
            self::$payHosts = null;
            $brand->forgetLogo();
            $brand->forgetFavicon();
        });
    }

    /** The brand's connected domain for invoice links. Lists of invoices share one query. */
    public static function payHost(int $brandId): ?string
    {
        self::$payHosts ??= self::whereNotNull('pay_domain_verified_at')->pluck('pay_domain', 'id')->all();

        return self::$payHosts[$brandId] ?? null;
    }

    /** Who the client is paying, unless an invoice names someone else */
    public function merchantName(): string
    {
        return $this->merchant_name ?: $this->name;
    }

    /** @return array{accent: string, on_accent: string, ink: string, soft: string} */
    public function theme(): array
    {
        return BrandTheme::from($this->brand_color);
    }

    /** Relative, so it loads on the app's address and the brand's own domain alike */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? "/{$this->logo_path}" : null;
    }

    /** PDFs can't fetch files, so the logo goes in as data */
    public function logoDataUri(): ?string
    {
        $disk = Storage::disk('brand_logos');
        $file = $this->logo_path ? basename($this->logo_path) : null;
        if (! $file || ! $disk->exists($file)) {
            return null;
        }
        $type = str_ends_with($file, '.png') ? 'png' : 'jpeg';

        return "data:image/{$type};base64,".base64_encode($disk->get($file));
    }

    /** The browser-tab icon on its invoice pages: its own favicon, or else its logo */
    public function faviconUrl(): ?string
    {
        return $this->favicon_path ? "/{$this->favicon_path}" : $this->logoUrl();
    }

    public function forgetFavicon(): void
    {
        if ($this->favicon_path) {
            Storage::disk('brand_logos')->delete(basename($this->favicon_path));
        }
    }

    public function forgetLogo(): void
    {
        if ($this->logo_path) {
            Storage::disk('brand_logos')->delete(basename($this->logo_path));
        }
    }

    /** @return array<string, mixed> what the Brands page edits */
    public function branding(): array
    {
        return [
            ...$this->only(['merchant_name', 'brand_color', 'billing_email', 'billing_address', 'pay_domain']),
            'logo_url' => $this->logoUrl(),
            'favicon_url' => $this->favicon_path ? "/{$this->favicon_path}" : null,
            'domain_connected' => $this->pay_domain_verified_at !== null,
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** Brands the user may see or pick from (Finance reports on every brand) */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasGlobalAccess() || $user->role === Role::Finance) {
            return $query;
        }

        return $query->whereIn('id', $user->brandIds());
    }
}
