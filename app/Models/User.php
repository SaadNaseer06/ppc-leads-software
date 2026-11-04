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

    /** Admins and Brand Managers run their brands; everything they do is scoped to those brands */
    public function managesBrands(): bool
    {
        return $this->hasGlobalAccess() || $this->role === Role::Admin || $this->role === Role::BrandManager;
    }

    public function canManageWebsites(): bool
    {
        return $this->managesBrands();
    }

    public function canAssignLeads(): bool
    {
        return $this->managesBrands();
    }

    /** Give a brand its Brand Manager (Super Admin, and Admins on their own brands) */
    public function canAssignBrandManagers(): bool
    {
        return $this->canManageUsers();
    }

    /** See who works the brands (Brand Managers read-only: they can't add or edit users) */
    public function canViewTeam(): bool
    {
        return $this->canManageUsers() || $this->role === Role::Developer || $this->role === Role::BrandManager;
    }

    /** Permanently remove a lead (Super Admin / Admin only) */
    public function canDeleteLeads(): bool
    {
        return $this->canAssignLeads();
    }

    /**
     * Sees every chat in their brands (not only chats tied to their assigned leads).
     * Chat Agents live here: they staff the inbox across the brand.
     */
    public function canOverseeBrandChats(): bool
    {
        return $this->canAssignLeads() || $this->role === Role::ChatAgent;
    }

    /** Remove chats, e.g. test conversations (brand overseers) */
    public function canDeleteChats(): bool
    {
        return $this->canOverseeBrandChats();
    }

    /** Promote a chat onto the Leads page (Admins and Chat Agents) */
    public function canConvertChatsToLeads(): bool
    {
        return $this->managesBrands() || $this->role === Role::ChatAgent;
    }

    public function canCreateLeads(): bool
    {
        return $this->canAssignLeads() || $this->isSales();
    }

    public function canWorkLeads(): bool
    {
        return ! in_array($this->role, [Role::Finance, Role::ChatAgent], true);
    }

    public function canViewChats(): bool
    {
        return $this->role !== Role::Finance;
    }

    public function canViewReports(): bool
    {
        return $this->managesBrands() || $this->role === Role::Finance;
    }

    public function canViewFinance(): bool
    {
        return $this->hasGlobalAccess() || $this->role === Role::Finance;
    }

    public function canCreateInvoice(): bool
    {
        return $this->isSales() || $this->managesBrands();
    }

    public function canViewInvoices(): bool
    {
        return $this->canCreateInvoice() || $this->role === Role::Finance;
    }

    /** Record payments and mark invoices paid or cancelled (Admins and Brand Managers on their brands) */
    public function canManageInvoices(): bool
    {
        return $this->managesBrands() || $this->role === Role::Finance;
    }

    public function canAccessBrand(int $brandId): bool
    {
        return $this->hasGlobalAccess() || in_array($brandId, $this->brandIds(), true);
    }
}
