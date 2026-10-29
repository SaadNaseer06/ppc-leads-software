<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Website;
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

    /**
     * Super Admin / Developer only. Removes the brand and its websites, leads and invoices.
     * Team memberships for the brand are cleared; users themselves are kept.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageBrands(), 403);

        $brand = Brand::query()->visibleTo($user)->findOrFail($id);
        $websites = $brand->websites()->count();
        $leads = $brand->leads()->count();
        $invoices = Invoice::where('brand_id', $brand->id)->count();

        if (! $request->boolean('force') && ($websites > 0 || $leads > 0 || $invoices > 0)) {
            return response()->json([
                'error' => 'This brand still has related data. Confirm force delete to remove it and everything under it.',
                'websites' => $websites,
                'leads' => $leads,
                'invoices' => $invoices,
                'requires_force' => true,
            ], 422);
        }

        $name = $brand->name;
        DB::transaction(function () use ($brand) {
            // Invoices block lead/brand deletes (restrictOnDelete)
            Invoice::where('brand_id', $brand->id)->delete();
            // Lead activities cascade; conversations.lead_id / brand_id null on delete
            Lead::where('brand_id', $brand->id)->delete();
            // Websites block brand deletes (restrictOnDelete)
            Website::where('brand_id', $brand->id)->delete();
            $brand->users()->detach();
            $brand->delete();
        });

        ActivityLog::record($user->id, 'brand.delete', 'Brand', $id, [
            'name' => $name,
            'websites' => $websites,
            'leads' => $leads,
            'invoices' => $invoices,
        ]);

        return response()->json(['ok' => true]);
    }
}
