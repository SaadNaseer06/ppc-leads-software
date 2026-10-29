<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Brands & routing: brands, their websites/team counts and round-robin auto-assign */
class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canAssignLeads(), 403);

        $brands = Brand::query()->visibleTo($user)->withCount(['websites', 'users'])->orderBy('name')->get();
        $ids = $brands->pluck('id');

        $waiting = Lead::whereIn('brand_id', $ids)->whereNull('assignee_id')->where('status', LeadStatus::New->value)
            ->groupBy('brand_id')->selectRaw('brand_id, COUNT(*) AS n')->pluck('n', 'brand_id');
        $reps = DB::table('brand_user')->join('users', 'users.id', '=', 'brand_user.user_id')
            ->whereIn('brand_user.brand_id', $ids)->where('users.active', true)->where('users.role', Role::FrontSales->value)
            ->groupBy('brand_user.brand_id')->selectRaw('brand_user.brand_id, COUNT(*) AS n')->pluck('n', 'brand_id');

        return response()->json([
            'brands' => $brands->map(fn (Brand $b) => [
                ...$b->only(['id', 'name', 'slug', 'auto_assign_enabled', 'websites_count', 'users_count']),
                'waiting' => (int) ($waiting[$b->id] ?? 0),
                'front_sales_reps' => (int) ($reps[$b->id] ?? 0),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageBrands(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('brands', 'name')],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('brands', 'slug')],
            'auto_assign_enabled' => ['sometimes', 'boolean'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and dashes',
            'name.unique' => 'A brand with that name already exists',
            'slug.unique' => 'A brand with that slug already exists',
        ]);

        $brand = Brand::create($data);
        ActivityLog::record($user->id, 'brand.create', 'Brand', $brand->id, ['name' => $brand->name]);

        return response()->json(['id' => $brand->id], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canAssignLeads(), 403);
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'auto_assign_enabled' => ['required', 'boolean'],
        ]);
        abort_unless($user->canAccessBrand($data['brand_id']), 403);

        Brand::findOrFail($data['brand_id'])->update(['auto_assign_enabled' => $data['auto_assign_enabled']]);
        ActivityLog::record($user->id, 'brand.auto_assign_toggle', 'Brand', $data['brand_id'], ['enabled' => $data['auto_assign_enabled']]);

        return response()->json(['ok' => true]);
    }
}
