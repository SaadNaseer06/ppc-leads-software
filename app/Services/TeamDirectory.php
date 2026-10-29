<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\User;

class TeamDirectory
{
    /**
     * Active users who can receive leads in the brands this user manages (or in
     * one brand), with their current open-lead workload.
     *
     * @return list<array{id: int, name: string, role: string, role_label: string, open_leads: int}>
     */
    public function assignable(User $user, ?int $brandId = null): array
    {
        $query = User::where('active', true)
            ->whereIn('role', array_map(fn (Role $r) => $r->value, Role::assignable()))
            ->orderBy('name');

        if ($brandId !== null) {
            $query->whereHas('brands', fn ($q) => $q->where('brands.id', $brandId));
        } elseif (! $user->hasGlobalAccess()) {
            $query->whereHas('brands', fn ($q) => $q->whereIn('brands.id', $user->brandIds()));
        }

        $users = $query->get(['id', 'name', 'role']);
        if ($users->isEmpty()) {
            return [];
        }

        $open = Lead::whereIn('assignee_id', $users->pluck('id'))
            ->whereIn('status', LeadStatus::open())
            ->groupBy('assignee_id')
            ->selectRaw('assignee_id, COUNT(*) AS n')
            ->pluck('n', 'assignee_id');

        return $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'role' => $u->role->value,
            'role_label' => $u->role->label(),
            'open_leads' => (int) ($open[$u->id] ?? 0),
        ])->all();
    }

    /** Session payload: who the user is and what the dashboard may show them */
    public function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'brand_ids' => $user->brandIds(),
            'can' => [
                'assign_leads' => $user->canAssignLeads(),
                'create_leads' => $user->canCreateLeads(),
                'work_leads' => $user->canWorkLeads(),
                'view_chats' => $user->canViewChats(),
                'view_invoices' => $user->canViewInvoices(),
                'create_invoice' => $user->canCreateInvoice(),
                'view_finance' => $user->canViewFinance(),
                'view_reports' => $user->canViewReports(),
                'manage_websites' => $user->canManageWebsites(),
                'manage_users' => $user->canManageUsers(),
                'view_team' => $user->canManageUsers() || $user->role === Role::Developer,
                'manage_brands' => $user->canManageBrands(),
                'export_leads' => $user->canAssignLeads(),
            ],
        ];
    }
}
