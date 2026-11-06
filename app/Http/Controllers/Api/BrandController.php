<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use App\Services\StripePayments;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Brands & routing: brands, their websites/team counts and round-robin auto-assign */
class BrandController extends Controller
{
    public function index(Request $request, StripePayments $stripe): JsonResponse
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
        $managers = DB::table('brand_user')->join('users', 'users.id', '=', 'brand_user.user_id')
            ->whereIn('brand_user.brand_id', $ids)->where('users.active', true)->where('users.role', Role::BrandManager->value)
            ->orderBy('users.name')->get(['brand_user.brand_id', 'users.id', 'users.name'])->groupBy('brand_id');

        return response()->json([
            'brands' => $brands->map(fn (Brand $b) => [
                ...$b->only(['id', 'name', 'slug', 'auto_assign_enabled', 'websites_count', 'users_count']),
                'waiting' => (int) ($waiting[$b->id] ?? 0),
                'front_sales_reps' => (int) ($reps[$b->id] ?? 0),
                'managers' => ($managers[$b->id] ?? collect())->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
                'branding' => [...$b->branding(), 'card_payments' => $stripe->configured($b)],
            ]),
            'billing' => [
                'dns_target' => parse_url((string) config('app.url'), PHP_URL_HOST),
            ],
            'manager_options' => $user->canAssignBrandManagers()
                ? $this->managerOptions($user)->map->only(['id', 'name'])->values()
                : [],
        ]);
    }

    /** Give a brand its manager, or none. A brand has one: whoever held it loses the brand. */
    public function setManager(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canAssignBrandManagers(), 403);
        $brand = Brand::query()->visibleTo($user)->findOrFail($id);
        $data = $request->validate(['user_id' => ['present', 'nullable', 'integer']]);

        $manager = null;
        if ($data['user_id'] !== null) {
            $manager = $this->managerOptions($user)->firstWhere('id', $data['user_id'])
                ?? throw ValidationException::withMessages(['user_id' => 'Choose an active Brand Manager from your team.']);
        }

        DB::transaction(function () use ($brand, $manager) {
            $current = $brand->users()->where('users.role', Role::BrandManager->value)->pluck('users.id');
            $brand->users()->detach($current->reject(fn ($id) => (int) $id === $manager?->id)->all());
            if ($manager) {
                $brand->users()->syncWithoutDetaching([$manager->id]);
            }
        });
        ActivityLog::record($user->id, 'brand.set_manager', 'Brand', $brand->id, ['manager_id' => $manager?->id]);

        return response()->json(['ok' => true]);
    }

    /** Brand Managers this user may appoint: everyone for a Super Admin, their own people for an Admin */
    private function managerOptions(User $user): Collection
    {
        return User::where('role', Role::BrandManager->value)->where('active', true)
            ->when($user->role === Role::Admin, fn ($q) => $q->where(fn ($w) => $w->where('admin_id', $user->id)
                ->orWhereHas('brands', fn ($b) => $b->whereIn('brands.id', $user->brandIds()))))
            ->orderBy('name')->get(['id', 'name']);
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
     * Super Admin / Developer only. Removes the brand and its websites, leads, chats and
     * invoices. Team memberships for the brand are cleared; users themselves are kept.
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
        // Small batches: one huge DELETE would lock the leads table for everyone and outlive
        // shared hosting's time limit. If it is interrupted, deleting again picks up the rest.
        @set_time_limit(0);
        ignore_user_abort(true);
        $inBatches = function ($query) {
            while ((clone $query)->limit(1000)->delete() > 0) {
            }
        };
        // Invoices block lead deletes (restrictOnDelete); their payments cascade
        // Through the table so deleted invoices go too: the brand can't be removed while any row remains
        $inBatches(DB::table('invoices')->where('brand_id', $brand->id));
        // Chat messages cascade with their conversation
        $inBatches(Conversation::where('brand_id', $brand->id));
        // Lead activities cascade
        $inBatches(Lead::where('brand_id', $brand->id));
        DB::transaction(function () use ($brand) {
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
