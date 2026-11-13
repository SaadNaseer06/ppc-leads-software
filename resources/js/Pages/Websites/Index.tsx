import { Head, Link, usePage } from "@inertiajs/react";
import { Globe } from "lucide-react";
import type { WebsitesData } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatNumber, formatRelative } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { AddWebsiteButton } from "./partials/website-form";
import { DeleteWebsiteButton } from "./partials/website-actions";

export default function WebsitesIndex({ websites, brands }: WebsitesData) {
  const user = usePage<SharedProps>().props.auth.user!;
  const now = new Date();

  return (
    <div className="space-y-5">
      <Head title="Websites" />
      <PageHeader
        title="Websites"
        description="Every website you run PPC traffic to. Each one gets its own lead-capture key and chat widget."
        actions={<AddWebsiteButton brands={brands} />}
      />

      <div className="card overflow-hidden">
        {websites.length === 0 ? (
          <EmptyState
            icon={<Globe className="h-5 w-5" />}
            title="No websites yet"
            description={
              brands.length === 0
                ? "Create a brand first, then add the websites that belong to it."
                : "Add your first website to get an embed snippet and start capturing PPC leads."
            }
            action={
              brands.length === 0 && user.can.manage_brands ? (
                <Link href="/brands" className="btn-secondary">
                  Create a brand
                </Link>
              ) : null
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Website</th>
                  <th>Brand</th>
                  <th className="text-right">Leads (30 days)</th>
                  <th>Last lead</th>
                  <th>Status</th>
                  <th className="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {websites.map((w) => (
                  <tr key={w.id}>
                    <td>
                      <Link href={`/websites/${w.id}`} className="font-medium text-slate-900 hover:text-teal-700">
                        {w.name}
                      </Link>
                      <p className="text-xs text-slate-500">{w.domain}</p>
                    </td>
                    <td className="text-slate-700">{w.brand.name}</td>
                    <td className="text-right font-medium tabular-nums text-slate-900">
                      {formatNumber(w.leads_30d)}
                    </td>
                    <td className="text-slate-500">
                      {w.last_lead_at || w.last_chat_at
                        ? formatRelative(
                            new Date(Math.max(Date.parse(w.last_lead_at ?? "0") || 0, Date.parse(w.last_chat_at ?? "0") || 0)),
                            now
                          )
                        : "Never"}
                    </td>
                    <td>
                      {w.active ? (
                        <span className="badge badge-dot bg-emerald-50 text-emerald-700">Active</span>
                      ) : (
                        <span className="badge badge-dot">Paused</span>
                      )}
                    </td>
                    <td className="text-right">
                      <div className="flex justify-end gap-2">
                        <Link href={`/websites/${w.id}`} className="btn-secondary btn-sm">
                          Open
                        </Link>
                        {user.can.manage_websites ? (
                          <DeleteWebsiteButton websiteId={w.id} websiteName={w.name} />
                        ) : null}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
