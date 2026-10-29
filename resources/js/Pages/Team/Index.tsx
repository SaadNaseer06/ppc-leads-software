import { Head, usePage } from "@inertiajs/react";
import type { TeamData } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { Role } from "@/lib/roles";
import { formatNumber, formatRelative } from "@/lib/format";
import { PageHeader } from "@/components/ui/page-header";
import { Avatar } from "@/components/ui/avatar";
import { AddUserButton, EditUserButton } from "./partials/user-form";

export default function TeamIndex({ users, brands, roles: roleOptions }: TeamData) {
  const user = usePage<SharedProps>().props.auth.user!;
  const canEdit = user.can.manage_users;
  const now = new Date();

  return (
    <div className="space-y-5">
      <Head title="Team" />
      <PageHeader
        title="Team"
        description="People who can sign in, receive leads and work them. Each user only sees the brands they're given."
        actions={canEdit ? <AddUserButton brands={brands} roles={roleOptions} /> : null}
      />

      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="table">
            <thead>
              <tr>
                <th>User</th>
                <th>Role</th>
                <th>Brands</th>
                <th className="text-right">Open leads</th>
                <th className="text-right">New leads (30 days)</th>
                <th>Last sign-in</th>
                <th>Status</th>
                {canEdit ? <th /> : null}
              </tr>
            </thead>
            <tbody>
              {users.map((u) => {
                return (
                  <tr key={u.id} className={u.active ? "" : "opacity-60"}>
                    <td>
                      <div className="flex items-center gap-3">
                        <Avatar name={u.name} />
                        <div className="min-w-0">
                          <p className="truncate font-medium text-slate-900">
                            {u.name}
                            {u.id === user.id ? <span className="ml-1.5 text-xs font-normal text-slate-500">(you)</span> : null}
                          </p>
                          <p className="truncate text-xs text-slate-500">{u.email}</p>
                        </div>
                      </div>
                    </td>
                    <td className="whitespace-nowrap text-slate-700">{u.role_label}</td>
                    <td className="max-w-[240px]">
                      <div className="flex flex-wrap gap-1">
                        {u.role === Role.SUPER_ADMIN || u.role === Role.DEVELOPER ? (
                          <span className="badge">All brands</span>
                        ) : u.brands.length === 0 ? (
                          <span className="badge border-amber-200 bg-amber-50 text-amber-800">None</span>
                        ) : (
                          u.brands.map((b) => (
                            <span key={b.id} className="badge">
                              {b.name}
                            </span>
                          ))
                        )}
                      </div>
                    </td>
                    <td className="text-right font-medium tabular-nums">{formatNumber(u.open_leads)}</td>
                    <td className="text-right tabular-nums text-slate-600">{formatNumber(u.leads_30d)}</td>
                    <td className="whitespace-nowrap text-slate-500">
                      {u.last_login_at ? formatRelative(u.last_login_at, now) : "Never"}
                    </td>
                    <td>
                      {u.active ? (
                        <span className="badge badge-dot bg-emerald-50 text-emerald-700">Active</span>
                      ) : (
                        <span className="badge badge-dot">Deactivated</span>
                      )}
                    </td>
                    {canEdit ? (
                      <td className="text-right">
                        {u.editable ? (
                          <EditUserButton
                            brands={brands}
                            roles={
                              roleOptions.some((r) => r.id === u.role)
                                ? roleOptions
                                : [{ id: u.role, name: u.role_label }, ...roleOptions]
                            }
                            isSelf={u.id === user.id}
                            user={{
                              id: u.id,
                              name: u.name,
                              email: u.email,
                              role: u.role,
                              brandIds: u.brands.map((b) => b.id),
                              active: u.active,
                            }}
                          />
                        ) : null}
                      </td>
                    ) : null}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
