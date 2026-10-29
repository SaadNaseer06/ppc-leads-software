import { Head, Link, usePage } from "@inertiajs/react";
import { CircleCheck, CircleDashed, Globe, TriangleAlert } from "lucide-react";
import type { WebsitesData } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatNumber, formatRelative } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { AddWebsiteButton } from "./partials/website-form";
import { DeleteWebsiteButton } from "./partials/website-actions";

export default function WebsitesIndex({ websites, brands, unlinked_properties: unlinked }: WebsitesData) {
  const user = usePage<SharedProps>().props.auth.user!;
  const now = new Date();

  return (
    <div className="space-y-5">
      <Head title="Websites" />
      <PageHeader
        title="Websites"
        description="Every website you run PPC traffic to. Each one gets its own lead-capture key and Tawk.to link."
        actions={<AddWebsiteButton brands={brands} />}
      />

      {unlinked.length > 0 ? (
        <div className="card border-amber-200">
          <div className="flex items-start gap-3 px-4 py-3">
            <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
            <div className="text-sm">
              <p className="font-medium text-slate-900">Tawk.to properties waiting to be linked</p>
              <p className="mt-0.5 text-slate-600">
                Chats are arriving from these properties but no website uses their ID yet. Paste the ID into a website&apos;s
                settings and the chats will be imported as leads.
              </p>
              <ul className="mt-2 space-y-1">
                {unlinked.map((p) => (
                  <li key={p.property_id} className="flex flex-wrap items-center gap-2">
                    <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs">{p.property_id}</code>
                    <span className="text-slate-600">
                      {p.property_name ?? "Unnamed property"} · {p.conversations} conversation{p.conversations === 1 ? "" : "s"}
                      {p.last_message_at ? ` · last ${formatRelative(p.last_message_at, now)}` : ""}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      ) : null}

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
                  <th>Tawk.to</th>
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
                    <td>
                      {w.tawk_property_id ? (
                        <span className="inline-flex items-center gap-1.5 text-sm text-emerald-700">
                          <CircleCheck className="h-4 w-4" />
                          Linked
                          {!w.has_tawk_secret ? (
                            <span className="badge border-amber-200 bg-amber-50 text-amber-800">No secret</span>
                          ) : null}
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 text-sm text-slate-400">
                          <CircleDashed className="h-4 w-4" />
                          Not linked
                        </span>
                      )}
                    </td>
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
