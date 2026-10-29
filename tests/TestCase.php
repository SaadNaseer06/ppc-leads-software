<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Brand;
use App\Models\User;
use App\Models\Website;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** API routes authenticate with Sanctum, so sign test users in through it */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        return parent::actingAs($user, $guard ?? 'sanctum');
    }

    protected function brand(array $attributes = []): Brand
    {
        $name = $attributes['name'] ?? 'Brand '.Str::random(6);

        return Brand::create(['name' => $name, 'slug' => Str::slug($name), ...$attributes]);
    }

    protected function website(Brand $brand, array $attributes = []): Website
    {
        return Website::create([
            'brand_id' => $brand->id,
            'name' => 'Site '.Str::random(4),
            'domain' => 'site-'.Str::lower(Str::random(6)).'.test',
            'public_key' => Website::generatePublicKey(),
            ...$attributes,
        ]);
    }

    /** @param list<Brand> $brands */
    protected function user(Role $role, array $brands = [], array $attributes = []): User
    {
        $user = User::create([
            'name' => $attributes['name'] ?? $role->label().' '.Str::random(4),
            'email' => Str::lower(Str::random(10)).'@test.local',
            'password' => 'password123',
            'role' => $role,
            ...$attributes,
        ]);
        $user->brands()->sync(array_map(fn (Brand $b) => $b->id, $brands));

        return $user;
    }
}
