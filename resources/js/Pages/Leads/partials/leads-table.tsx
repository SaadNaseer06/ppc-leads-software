import { useState, useTransition } from "react";
import { Link, router } from "@inertiajs/react";
import { Inbox, Loader2, Repeat2, UserPlus, X } from "lucide-react";
import { LeadStatus, STATUS_LABELS } from "@/lib/roles";
import type { Assignee } from "@/lib/api-types";
import { ChannelBadge, SourceBadge, StatusBadge } from "@/components/ui/badges";
import { Avatar } from "@/components/ui/avatar";
import { EmptyState } from "@/components/ui/page-header";
import { apiRequest, useToast } from "@/components/ui/toast";
import { useLiveSync } from "@/components/app-shell/live-provider";

export type LeadRowView = {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  company: string | null;
  status: string;
  source: string;
  channel: string;
  campaign: string | null;
  websiteName: string;
  assigneeName: string | null;
  submissionCount: number;
  received: string;
  receivedFull: string;
};

export function LeadsTable({
  rows,
  assignees,
  canAssign,
  canChangeStatus,
  emptyFiltered,
}: {
  rows: LeadRowView[];
  assignees: Assignee[];
  canAssign: boolean;
  canChangeStatus: boolean;
  emptyFiltered: boolean;
}) {
  const toast = useToast();
  const syncLive = useLiveSync();
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [assignTo, setAssignTo] = useState("");
  const [status, setStatus] = useState("");
  const [busy, setBusy] = useState(false);
  const [, startTransition] = useTransition();

  const selectable = canAssign || canChangeStatus;
  // Drop selections that are no longer on this page (after paging or filtering)
  const visibleSelected = new Set(rows.filter((r) => selected.has(r.id)).map((r) => r.id));
  const allSelected = rows.length > 0 && visibleSelected.size === rows.length;

  function toggle(id: number) {
    const next = new Set(visibleSelected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSelected(next);
  }

  async function runBulk(body: Record<string, unknown>, success: (n: number) => string) {
    setBusy(true);
    const res = await apiRequest<{ updated: number }>("/api/leads/bulk", {
      method: "POST",
      body: { ...body, ids: [...visibleSelected] },
    });
    setBusy(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    toast.success(success(res.data.updated));
    setSelected(new Set());
    setAssignTo("");
    setStatus("");
    syncLive();
    startTransition(() => router.reload());
  }

  if (rows.length === 0) {
    return (
      <div className="card">
        <EmptyState
          icon={<Inbox className="h-5 w-5" />}
          title={emptyFiltered ? "No leads match these filters" : "No leads yet"}
          description={
            emptyFiltered
              ? "Try widening the date range or clearing a filter."
              : "Connect a website or Tawk.to property and new leads will appear here automatically."
          }
          action={
            emptyFiltered ? null : (
              <Link href="/websites" className="btn-secondary">
                Set up a website
              </Link>
            )
          }
        />
      </div>
    );
  }

  return (
    <div className="card overflow-hidden">
      {visibleSelected.size > 0 ? (
        <div className="flex flex-wrap items-center gap-3 border-b border-teal-100 bg-teal-50/60 px-4 py-2.5">
          <span className="text-sm font-medium text-teal-900">{visibleSelected.size} selected</span>
          {canAssign ? (
            <div className="flex items-center gap-2">
              <select
                className="input w-auto py-1.5"
                value={assignTo}
                onChange={(e) => setAssignTo(e.target.value)}
                aria-label="Assign selected leads to"
              >
                <option value="">Assign to…</option>
                <option value="__none__">Unassign</option>
                {assignees.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name} · {a.role_label} · {a.open_leads.toLocaleString("en-US")} open
                  </option>
                ))}
              </select>
              <button
                type="button"
                className="btn-primary btn-sm"
                disabled={!assignTo || busy}
                onClick={() =>
                  runBulk(
                    { action: "assign", assignee_id: assignTo === "__none__" ? null : Number(assignTo) },
                    (n) => (n ? `${n} lead${n === 1 ? "" : "s"} reassigned` : "Already assigned to that person")
                  )
                }
              >
                {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <UserPlus className="h-3.5 w-3.5" />}
                Assign
              </button>
            </div>
          ) : null}
          {canChangeStatus ? (
            <div className="flex items-center gap-2">
              <select
                className="input w-auto py-1.5"
                value={status}
                onChange={(e) => setStatus(e.target.value)}
                aria-label="Set status of selected leads"
              >
                <option value="">Set status…</option>
                {Object.values(LeadStatus).map((s) => (
                  <option key={s} value={s}>
                    {STATUS_LABELS[s]}
                  </option>
                ))}
              </select>
              <button
                type="button"
                className="btn-secondary btn-sm"
                disabled={!status || busy}
                onClick={() =>
                  runBulk({ action: "status", status }, (n) => `${n} lead${n === 1 ? "" : "s"} updated`)
                }
              >
                Apply
              </button>
            </div>
          ) : null}
          <button type="button" className="btn-ghost btn-sm ml-auto" onClick={() => setSelected(new Set())}>
            <X className="h-3.5 w-3.5" />
            Clear
          </button>
        </div>
      ) : null}

      <div className="overflow-x-auto">
        <table className="table">
          <thead>
            <tr>
              {selectable ? (
                <th className="w-10">
                  <input
                    type="checkbox"
                    className="checkbox"
                    checked={allSelected}
                    onChange={() => setSelected(allSelected ? new Set() : new Set(rows.map((r) => r.id)))}
                    aria-label="Select all leads on this page"
                  />
                </th>
              ) : null}
              <th>Lead</th>
              <th>Website</th>
              <th>Source</th>
              <th>Campaign</th>
              <th>Status</th>
              <th>Assignee</th>
              <th className="text-right">Received</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((lead) => (
              <tr
                key={lead.id}
                data-selected={visibleSelected.has(lead.id)}
                className="cursor-pointer"
                onClick={(e) => {
                  if ((e.target as HTMLElement).closest("input,a,button")) return;
                  router.visit(`/leads/${lead.id}`);
                }}
              >
                {selectable ? (
                  <td>
                    <input
                      type="checkbox"
                      className="checkbox"
                      checked={visibleSelected.has(lead.id)}
                      onChange={() => toggle(lead.id)}
                      aria-label={`Select ${lead.name}`}
                    />
                  </td>
                ) : null}
                <td className="max-w-[280px]">
                  <Link href={`/leads/${lead.id}`} className="flex items-center gap-1.5 font-medium text-slate-900 hover:text-teal-700">
                    <span className="truncate">{lead.name}</span>
                    {lead.submissionCount > 1 ? (
                      <span
                        className="inline-flex items-center gap-0.5 rounded bg-slate-100 px-1 text-[11px] font-medium text-slate-600"
                        title={`Submitted ${lead.submissionCount} times`}
                      >
                        <Repeat2 className="h-3 w-3" />
                        {lead.submissionCount}
                      </span>
                    ) : null}
                  </Link>
                  <p className="truncate text-xs text-slate-500">
                    {[lead.email, lead.phone].filter(Boolean).join(" · ") || lead.company || "No contact details"}
                  </p>
                </td>
                <td className="whitespace-nowrap text-slate-700">{lead.websiteName}</td>
                <td>
                  <div className="flex gap-1">
                    <SourceBadge source={lead.source} />
                    <ChannelBadge channel={lead.channel} />
                  </div>
                </td>
                <td className="max-w-[180px] truncate text-slate-600" title={lead.campaign ?? undefined}>
                  {lead.campaign ?? <span className="text-slate-400">—</span>}
                </td>
                <td>
                  <StatusBadge status={lead.status} />
                </td>
                <td className="whitespace-nowrap">
                  {lead.assigneeName ? (
                    <span className="inline-flex items-center gap-2 text-slate-700">
                      <Avatar name={lead.assigneeName} size="sm" />
                      {lead.assigneeName}
                    </span>
                  ) : (
                    <span className="badge border-amber-200 bg-amber-50 text-amber-800">Unassigned</span>
                  )}
                </td>
                <td className="whitespace-nowrap text-right text-slate-500" title={lead.receivedFull}>
                  {lead.received}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
