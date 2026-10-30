import { useEffect, useRef, useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import { Download } from "lucide-react";
import type { LeadsPage, Lookups } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatDateTime, formatNumber, formatRelative } from "@/lib/format";
import { PageHeader } from "@/components/ui/page-header";
import { LeadFiltersBar } from "./partials/lead-filters";
import { LeadsTable, type LeadRowView } from "./partials/leads-table";
import { CreateLeadButton } from "./partials/create-lead-dialog";
import { useLiveReload } from "@/components/app-shell/live-provider";

const PASSED_PARAMS = ["q", "status", "source", "channel", "website", "brand", "assignee", "from", "to", "size"];

export default function LeadsIndex({ page, lookups, query }: { page: LeadsPage; lookups: Lookups; query: Record<string, string> }) {
  const user = usePage<SharedProps>().props.auth.user!;
  const one = (key: string) => query[key] || undefined;

  // Keep current filters when paging; drop the cursor when filters change
  const baseQuery = new URLSearchParams();
  for (const key of PASSED_PARAMS) {
    const value = one(key);
    if (value) baseQuery.set(key, value);
  }
  const filters = page.filters;
  const pageSize = [25, 50, 100].includes(Number(one("size"))) ? Number(one("size")) : 50;
  const canAssign = user.can.assign_leads;

  // The newest page updates itself as leads arrive or change (older pages keep the "new
  // leads" pill, so rows don't shift while someone works through them)
  const [fresh, setFresh] = useState<Set<number>>(new Set());
  const known = useRef(new Set(page.leads.map((l) => l.id)));
  const liveRefresh = useRef(false);
  useLiveReload(["page"], 5_000, !one("cursor"), () => {
    liveRefresh.current = true;
  });
  useEffect(() => {
    const ids = page.leads.map((l) => l.id);
    const added = liveRefresh.current ? ids.filter((id) => !known.current.has(id)) : [];
    liveRefresh.current = false;
    known.current = new Set(ids);
    if (added.length === 0) return;
    setFresh(new Set(added));
    const timer = window.setTimeout(() => setFresh(new Set()), 6_000);
    return () => window.clearTimeout(timer);
  }, [page.leads]);

  const now = new Date();
  const rows: LeadRowView[] = page.leads.map((l) => ({
    id: l.id,
    name: l.name,
    email: l.email,
    phone: l.phone,
    company: l.company,
    status: l.status,
    source: l.source,
    channel: l.channel,
    campaign: l.campaign,
    websiteName: l.website?.name ?? l.brand?.name ?? "—",
    brandId: l.brand?.id ?? null,
    assigneeName: l.assignee?.name ?? null,
    submissionCount: l.submission_count,
    received: formatRelative(l.created_at, now),
    receivedFull: formatDateTime(l.created_at),
  }));

  const pageHref = (cursor: string, dir: "next" | "prev") => {
    const q = new URLSearchParams(baseQuery);
    q.set("cursor", cursor);
    q.set("dir", dir);
    return `/leads?${q}`;
  };
  const exportQuery = new URLSearchParams(baseQuery);
  exportQuery.delete("size");

  const tabs = [
    { label: "All leads", assignee: undefined },
    ...(canAssign ? [{ label: "Unassigned", assignee: "unassigned" }] : []),
    { label: "Assigned to me", assignee: "me" },
  ];

  return (
    <div className="space-y-5">
      <Head title="Leads" />
      <PageHeader
        title="Leads"
        description={
          <>
            {formatNumber(page.total)}
            {page.total_capped ? "+" : ""} {page.total === 1 ? "lead" : "leads"} match the current view
          </>
        }
        actions={
          <>
            {user.can.export_leads ? (
              <a className="btn-secondary" href={`/api/leads/export?${exportQuery}`}>
                <Download className="h-4 w-4" />
                Export CSV
              </a>
            ) : null}
            {user.can.create_leads ? (
              <CreateLeadButton websites={lookups.websites} assignees={lookups.assignees} canAssign={canAssign} />
            ) : null}
          </>
        }
      />

      <div className="flex gap-1 border-b border-slate-200">
        {tabs.map((tab) => {
          const q = new URLSearchParams(baseQuery);
          q.delete("assignee");
          if (tab.assignee) q.set("assignee", tab.assignee);
          const active = filters.assignee === tab.assignee;
          return (
            <Link
              key={tab.label}
              href={`/leads${q.toString() ? `?${q}` : ""}`}
              className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium transition ${
                active
                  ? "border-teal-600 text-teal-700"
                  : "border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700"
              }`}
            >
              {tab.label}
            </Link>
          );
        })}
      </div>

      <LeadFiltersBar
        filters={filters}
        pageSize={pageSize}
        websites={lookups.websites}
        brands={lookups.brands}
        assignees={canAssign ? lookups.assignees.map((a) => ({ id: String(a.id), name: a.name })) : []}
      />

      <LeadsTable
        rows={rows}
        assignees={lookups.assignees}
        canAssign={canAssign}
        canChangeStatus={user.can.work_leads}
        canDelete={user.can.delete_leads}
        canManageWebsites={user.can.manage_websites}
        emptyFiltered={Object.values(filters).some(Boolean)}
        freshIds={fresh}
      />

      {page.prev_cursor || page.next_cursor ? (
        <div className="flex items-center justify-end gap-2">
          {page.prev_cursor ? (
            <Link className="btn-secondary btn-sm" href={pageHref(page.prev_cursor, "prev")}>
              ← Newer
            </Link>
          ) : (
            <span className="btn-secondary btn-sm pointer-events-none opacity-50">← Newer</span>
          )}
          {page.next_cursor ? (
            <Link className="btn-secondary btn-sm" href={pageHref(page.next_cursor, "next")}>
              Older →
            </Link>
          ) : (
            <span className="btn-secondary btn-sm pointer-events-none opacity-50">Older →</span>
          )}
        </div>
      ) : null}
    </div>
  );
}
