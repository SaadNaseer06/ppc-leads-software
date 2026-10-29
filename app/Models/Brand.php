<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    protected $fillable = ['name', 'slug', 'auto_assign_enabled', 'round_robin_last_user_id'];

    protected function casts(): array
    {
        return ['auto_assign_enabled' => 'boolean'];
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
