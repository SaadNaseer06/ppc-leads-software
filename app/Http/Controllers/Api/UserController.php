<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The Team page: people who sign in, receive leads and work them */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->canViewTeam(), 403);

        $users = User::with('brands:id,name')
            ->when($me->role === Role::Admin, fn ($q) => $q->where(fn ($w) => $w->whereKey($me->id)->orWhere('admin_id', $me->id)))
            ->when($me->role === Role::BrandManager, fn ($q) => $q->whereHas('brands', fn ($b) => $b->whereIn('brands.id', $me->brandIds())))
            ->orderByDesc('active')->orderBy('name')->get();
        $ids = $users->pluck('id');

        // Reps can work several brands. A manager sees their work in the managed brands, nothing else.
        $scope = $me->role === Role::BrandManager ? $me->brandIds() : null;
        $inScope = fn ($q) => $q->inInbox()->when($scope !== null, fn ($s) => $s->whereIn('brand_id', $scope));

        $open = Lead::whereIn('assignee_id', $ids)->whereIn('status', LeadStatus::open())->tap($inScope)
            ->groupBy('assignee_id')->selectRaw('assignee_id, COUNT(*) AS n')->pluck('n', 'assignee_id');
        // By created_at, not assigned_at: answered from the (assignee_id, created_at, ...) index alone
        $recent = Lead::whereIn('assignee_id', $ids)->where('created_at', '>=', now()->subDays(30))->tap($inScope)
            ->groupBy('assignee_id')->selectRaw('assignee_id, COUNT(*) AS n')->pluck('n', 'assignee_id');

        $grantable = match (true) {
            $me->role === Role::Admin => Role::adminGrantable(),
            $me->canManageUsers() => Role::cases(),
            default => [],
        };

        return response()->json([
            'users' => $users->map(fn (User $u) => [
                ...$u->only(['id', 'name', 'email', 'active', 'admin_id', 'last_login_at']),
                'role' => $u->role->value,
                'role_label' => $u->role->label(),
                'brands' => $u->brands->when($scope !== null, fn ($b) => $b->whereIn('id', $scope))->map->only(['id', 'name'])->values(),
                'open_leads' => (int) ($open[$u->id] ?? 0),
                'leads_30d' => (int) ($recent[$u->id] ?? 0),
                'editable' => $me->canManageUsers() && ($me->role !== Role::Admin || $u->admin_id === $me->id || $u->id === $me->id),
            ]),
            'brands' => Brand::query()->visibleTo($me)->orderBy('name')->get(['id', 'name']),
            'roles' => array_map(fn (Role $r) => ['id' => $r->value, 'name' => $r->label()], $grantable),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->canManageUsers(), 403);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role' => ['required', Rule::in(Role::values())],
            'brand_ids' => ['array'],
            'brand_ids.*' => ['integer', 'exists:brands,id'],
            'admin_id' => ['nullable', 'integer', 'exists:users,id'],
        ], ['email.unique' => 'A user with that email already exists', 'password.min' => 'Password must be at least 8 characters']);

        $isAdmin = $me->role === Role::Admin;
        $role = Role::from($data['role']);
        if ($isAdmin && ! in_array($role, Role::adminGrantable(), true)) {
            return response()->json(['error' => 'Admins can add Front Sales, Up Sales and Finance users only'], 403);
        }
        $brandIds = $data['brand_ids'] ?? [];
        if ($isAdmin) {
            $brandIds = array_values(array_intersect($brandIds, $me->brandIds()));
        }

        $user = DB::transaction(function () use ($data, $role, $isAdmin, $me, $brandIds) {
            $user = User::create([
                'email' => strtolower($data['email']),
                'name' => $data['name'],
                'password' => $data['password'],
                'role' => $role,
                'admin_id' => $isAdmin ? $me->id : ($data['admin_id'] ?? null),
            ]);
            $user->brands()->sync($brandIds);

            return $user;
        });
        ActivityLog::record($me->id, 'user.create', 'User', $user->id, ['email' => $user->email, 'role' => $role->value]);

        return response()->json(['id' => $user->id], 201);
    }

    public function update(Request $request, int $id, LeadAssignment $assignment): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->canManageUsers(), 403);
        $target = User::findOrFail($id);

        $isAdmin = $me->role === Role::Admin;
        $isSelf = $target->id === $me->id;
        abort_if($isAdmin && $target->admin_id !== $me->id && ! $isSelf, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'role' => ['sometimes', Rule::in(Role::values())],
            'brand_ids' => ['sometimes', 'array'],
            'brand_ids.*' => ['integer', 'exists:brands,id'],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:100'],
            'admin_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ], ['password.min' => 'Password must be at least 8 characters']);

        if ($isSelf && (isset($data['role']) || ($data['active'] ?? true) === false)) {
            throw ValidationException::withMessages(['role' => "You can't change your own role or deactivate yourself"]);
        }
        if ($isAdmin && isset($data['role']) && ! in_array(Role::from($data['role']), Role::adminGrantable(), true)) {
            return response()->json(['error' => 'Admins can assign Front Sales, Up Sales and Finance roles only'], 403);
        }

        // Nobody widens their own access: self-service is limited to name and password
        $brandIds = $isSelf && ! $me->hasGlobalAccess() ? null : ($data['brand_ids'] ?? null);
        if ($brandIds !== null && $isAdmin) {
            // Admins only control the brands they belong to; leave the rest untouched
            $outside = array_diff($target->brandIds(), $me->brandIds());
            $brandIds = array_values(array_unique([...$outside, ...array_intersect($brandIds, $me->brandIds())]));
        }

        DB::transaction(function () use ($target, $data, $brandIds, $isAdmin) {
            $target->fill(array_filter([
                'name' => $data['name'] ?? null,
                'role' => isset($data['role']) ? Role::from($data['role']) : null,
                'active' => $data['active'] ?? null,
                'password' => ($data['password'] ?? null) ?: null,
            ], fn ($v) => $v !== null));
            if (! $isAdmin && array_key_exists('admin_id', $data)) {
                $target->admin_id = $data['admin_id'];
            }
            $target->save();
            if ($brandIds !== null) {
                $target->brands()->sync($brandIds);
            }
        });

        $released = 0;
        if (($data['active'] ?? true) === false) {
            $target->tokens()->delete();
            $released = $assignment->releaseOpenLeads($target, $me->id);
        }
        ActivityLog::record($me->id, 'user.update', 'User', $id, [
            'fields' => array_keys(array_diff_key($data, ['password' => 1])),
            'password_reset' => ! empty($data['password']),
            'released_leads' => $released,
        ]);

        return response()->json(['ok' => true, 'released_leads' => $released]);
    }
}
