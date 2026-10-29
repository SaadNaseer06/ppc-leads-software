import { Head, Link, usePage } from "@inertiajs/react";
import { Building2 } from "lucide-react";
import type { BrandsData } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatNumber } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { AutoAssignToggle, CreateBrandButton, DeleteBrandButton } from "./partials/brand-controls";

export default function BrandsIndex({ brands }: BrandsData) {
  const user = usePage<SharedProps>().props.auth.user!;

  return (
    <div className="space-y-5">
      <Head title="Brands & routing" />
      <PageHeader
        title="Brands & routing"
        description="Brands group websites and the team that works their leads. Turn on auto-assign to rotate new leads across Front Sales reps automatically."
        actions={user.can.manage_brands ? <CreateBrandButton /> : null}
      />

      <div className="card overflow-hidden">
        {brands.length === 0 ? (
          <EmptyState icon={<Building2 className="h-5 w-5" />} title="No brands yet" description="Create a brand to start adding websites." />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Brand</th>
                  <th className="text-right">Websites</th>
                  <th className="text-right">Team</th>
                  <th className="text-right">Waiting to be assigned</th>
                  <th>Auto-assign (round-robin)</th>
                  {user.can.manage_brands ? <th className="text-right">Actions</th> : null}
                </tr>
              </thead>
              <tbody>
                {brands.map((b) => {
                  const reps = b.front_sales_reps;
                  const waiting = b.waiting;
                  return (
                    <tr key={b.id}>
                      <td>
                        <p className="font-medium text-slate-900">{b.name}</p>
                        <p className="font-mono text-xs text-slate-500">{b.slug}</p>
                      </td>
                      <td className="text-right tabular-nums">
                        <Link href="/websites" className="text-teal-700 hover:underline">
                          {b.websites_count}
                        </Link>
                      </td>
                      <td className="text-right tabular-nums">{b.users_count}</td>
                      <td className="text-right tabular-nums">
                        {waiting > 0 ? (
                          <Link href={`/leads?brand=${b.id}&assignee=unassigned`} className="font-medium text-amber-700 hover:underline">
                            {formatNumber(waiting)}
                          </Link>
                        ) : (
                          <span className="text-slate-400">0</span>
                        )}
                      </td>
                      <td>
                        <div className="flex items-center gap-3">
                          <AutoAssignToggle brandId={b.id} enabled={b.auto_assign_enabled} eligible={reps} />
                          <span className="text-xs text-slate-500">
                            {reps} active Front Sales rep{reps === 1 ? "" : "s"}
                          </span>
                        </div>
                      </td>
                      {user.can.manage_brands ? (
                        <td className="text-right">
                          <DeleteBrandButton brandId={b.id} brandName={b.name} />
                        </td>
                      ) : null}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
