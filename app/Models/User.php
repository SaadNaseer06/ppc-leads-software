<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property Role $role
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'active', 'admin_id', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    /** Mirrors the column default, so a freshly created user isn't read as deactivated */
    protected $attributes = ['active' => true];

    /** @var list<int>|null */
    private ?array $brandIdCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
        ];
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assignee_id');
    }

    /** @return list<int> */
    public function brandIds(): array
    {
        return $this->brandIdCache ??= $this->brands()->pluck('brands.id')->map(fn ($id) => (int) $id)->all();
    }

    // --- Permissions --------------------------------------------------------

    public function hasGlobalAccess(): bool
    {
        return $this->role->hasGlobalAccess();
    }

    public function isSales(): bool
    {
        return $this->role->isSales();
    }

    public function canManageUsers(): bool
    {
        return $this->role === Role::SuperAdmin || $this->role === Role::Admin;
    }

    public function canManageBrands(): bool
    {
        return $this->hasGlobalAccess();
    }

    public function canManageWebsites(): bool
    {
        return $this->hasGlobalAccess() || $this->role === Role::Admin;
    }

    public function canAssignLeads(): bool
    {
        return $this->hasGlobalAccess() || $this->role === Role::Admin;
    }

    /** Permanently remove a lead (Super Admin / Admin only) */
    public function canDeleteLeads(): bool
    {
        return $this->canAssignLeads();
    }

    /** Remove chats, e.g. test conversations (Admins only within their brands) */
    public function canDeleteChats(): bool
    {
        return $this->canAssignLeads();
    }

    public function canCreateLeads(): bool
    {
        return $this->canAssignLeads() || $this->isSales();
    }

    public function canWorkLeads(): bool
    {
        return $this->role !== Role::Finance;
    }

    public function canViewChats(): bool
    {
        return $this->role !== Role::Finance;
    }

    public function canViewReports(): bool
    {
        return $this->hasGlobalAccess() || in_array($this->role, [Role::Admin, Role::Finance], true);
    }

    public function canViewFinance(): bool
    {
        return $this->hasGlobalAccess() || $this->role === Role::Finance;
    }

    public function canCreateInvoice(): bool
    {
        return $this->isSales() || $this->hasGlobalAccess() || $this->role === Role::Admin;
    }

    public function canViewInvoices(): bool
    {
        return $this->canCreateInvoice() || in_array($this->role, [Role::Finance, Role::Admin], true);
    }

    /** Record payments and mark invoices paid or cancelled (Admins only on their brands' invoices) */
    public function canManageInvoices(): bool
    {
        return $this->hasGlobalAccess() || in_array($this->role, [Role::Finance, Role::Admin], true);
    }

    public function canAccessBrand(int $brandId): bool
    {
        return $this->hasGlobalAccess() || in_array($brandId, $this->brandIds(), true);
    }
}
