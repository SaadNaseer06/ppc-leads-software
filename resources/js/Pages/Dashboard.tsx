import { Head, Link, usePage } from "@inertiajs/react";
import { ArrowDownRight, ArrowUpRight, MessageSquare } from "lucide-react";
import type { DashboardData } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatDayLabel, formatNumber, formatPercent, formatRelative, hourOfDay } from "@/lib/format";
import { labelFor, STATUS_LABELS } from "@/lib/roles";
import { PageHeader } from "@/components/ui/page-header";
import { ChannelBadge, StatusBadge } from "@/components/ui/badges";
import { Avatar } from "@/components/ui/avatar";
import { LeadsTrendChart } from "@/components/charts/leads-trend-chart";
import { useLiveReload } from "@/components/app-shell/live-provider";

const RANGES = [7, 30, 90] as const;

export default function Dashboard({ dashboard, range }: { dashboard: DashboardData; range: number }) {
  const user = usePage<SharedProps>().props.auth.user!;
  const canAssign = user.can.assign_leads;
  const { stats: data, recent_leads: recentLeads, recent_chats: recentChats } = dashboard;
  // Numbers and latest leads follow new leads live; the heavier totals refresh at most every 30s
  useLiveReload(["dashboard"], 30_000);

  const now = new Date();
  const delta = data.previous > 0 ? (data.total - data.previous) / data.previous : null;
  const maxWebsite = Math.max(1, ...data.websites.map((w) => w.count));
  const statusTotal = Math.max(1, data.statuses.reduce((s, r) => s + r.count, 0));
  const hour = hourOfDay(now);
  const salutation = hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening";

  return (
    <div className="space-y-6">
      <Head title="Dashboard" />
      <PageHeader
        title={`${salutation}, ${user.name.split(" ")[0]}`}
        description={
          canAssign
            ? "Here's how your PPC websites are performing and what needs assigning."
            : "Here's your pipeline and the latest leads assigned to you."
        }
        actions={
          <div className="flex rounded-lg border border-slate-200 bg-white p-0.5 text-sm">
            {RANGES.map((r) => (
              <Link
                key={r}
                href={r === 30 ? "/dashboard" : `/dashboard?range=${r}`}
                className={`rounded-md px-3 py-1.5 font-medium ${
                  r === range ? "bg-slate-900 text-white" : "text-slate-600 hover:bg-slate-100"
                }`}
              >
                {r} days
              </Link>
            ))}
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile
          label={`Leads, last ${range} days`}
          value={formatNumber(data.total)}
          footer={
            delta === null ? (
              <span className="text-slate-500">No data for the previous {range} days</span>
            ) : (
              <span className={`inline-flex items-center gap-1 ${delta >= 0 ? "text-emerald-700" : "text-red-700"}`}>
                {delta >= 0 ? <ArrowUpRight className="h-3.5 w-3.5" /> : <ArrowDownRight className="h-3.5 w-3.5" />}
                {formatPercent(Math.abs(delta))} vs previous {range} days
              </span>
            )
          }
        />
        {canAssign ? (
          <StatTile
            label="Waiting to be assigned"
            value={formatNumber(data.unassigned)}
            href="/leads?assignee=unassigned&status=new"
            footer={<span className="text-slate-500">New leads with no owner</span>}
            highlight={data.unassigned > 0}
          />
        ) : (
          <StatTile
            label="My open leads"
            value={formatNumber(data.my_open)}
            href="/leads?assignee=me"
            footer={<span className="text-slate-500">New, contacted or qualified</span>}
          />
        )}
        <StatTile
          label="From PPC ads"
          value={data.total ? formatPercent(data.ppc / data.total) : "—"}
          href="/leads?channel=ppc"
          footer={<span className="text-slate-500">{formatNumber(data.ppc)} leads with a paid click or CPC campaign</span>}
        />
        <StatTile
          label="Conversion rate"
          value={data.total ? formatPercent(data.converted / data.total) : "—"}
          footer={
            <span className="text-slate-500">
              {formatNumber(data.converted)} converted · {formatNumber(data.today)} new today
            </span>
          }
        />
      </div>

      <div className="grid gap-5 xl:grid-cols-3">
        <section className="card xl:col-span-2">
          <div className="card-header">
            <div>
              <h2 className="card-title">Leads per day</h2>
              <p className="text-xs text-slate-500">All websites, chats and offline messages · spam excluded</p>
            </div>
          </div>
          <div className="p-4">
            <LeadsTrendChart data={data.series.map((d) => ({ ...d, label: formatDayLabel(d.day) }))} />
          </div>
        </section>

        <section className="card">
          <div className="card-header">
            <h2 className="card-title">Pipeline</h2>
            <span className="text-xs text-slate-500">Leads received in the last {range} days</span>
          </div>
          <ul className="space-y-3 p-4">
            {data.statuses.map((s) => (
              <li key={s.status}>
                <Link href={`/leads?status=${s.status}`} className="group block">
                  <div className="flex items-center justify-between text-sm">
                    <StatusBadge status={s.status} />
                    <span className="tabular-nums text-slate-700 group-hover:text-slate-900">
                      {formatNumber(s.count)}{" "}
                      <span className="text-xs text-slate-400">({formatPercent(s.count / statusTotal)})</span>
                    </span>
                  </div>
                  <div className="mt-1.5 h-1.5 rounded-full bg-slate-100">
                    <div
                      className="h-full rounded-full bg-teal-600"
                      style={{ width: `${(s.count / statusTotal) * 100}%` }}
                      aria-label={`${labelFor(STATUS_LABELS, s.status)}: ${s.count}`}
                    />
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      </div>

      <div className="grid gap-5 xl:grid-cols-3">
        <section className="card">
          <div className="card-header">
            <h2 className="card-title">Leads by website</h2>
            {user.can.manage_websites ? (
              <Link href="/websites" className="text-xs font-medium text-teal-700 hover:underline">
                Manage
              </Link>
            ) : null}
          </div>
          {data.websites.length === 0 ? (
            <p className="p-4 text-sm text-slate-500">No leads in this period.</p>
          ) : (
            <ul className="space-y-3 p-4">
              {data.websites.map((w) => (
                <li key={w.id ?? "none"}>
                  <Link href={w.id ? `/leads?website=${w.id}` : "/leads"} className="group block">
                    <div className="flex items-center justify-between gap-3 text-sm">
                      <span className="truncate text-slate-700 group-hover:text-slate-900">{w.name}</span>
                      <span className="tabular-nums font-medium text-slate-900">{formatNumber(w.count)}</span>
                    </div>
                    <div className="mt-1.5 h-2 rounded-r bg-slate-100">
                      <div className="h-full rounded-r bg-teal-600" style={{ width: `${(w.count / maxWebsite) * 100}%` }} />
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="card xl:col-span-2">
          <div className="card-header">
            <h2 className="card-title">Latest leads</h2>
            <Link href="/leads" className="text-xs font-medium text-teal-700 hover:underline">
              View all
            </Link>
          </div>
          {recentLeads.length === 0 ? (
            <p className="p-4 text-sm text-slate-500">No leads yet.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {recentLeads.map((l) => (
                <li key={l.id}>
                  <Link href={`/leads/${l.id}`} className="flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50">
                    <Avatar name={l.name} size="sm" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-slate-900">{l.name}</p>
                      <p className="truncate text-xs text-slate-500">
                        {l.website?.name ?? "—"} · {l.assignee?.name ?? "Unassigned"}
                      </p>
                    </div>
                    <div className="hidden sm:block">
                      <ChannelBadge channel={l.channel} />
                    </div>
                    <StatusBadge status={l.status} />
                    <span className="w-16 shrink-0 text-right text-xs text-slate-400">{formatRelative(l.created_at, now)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <div className="grid gap-5 xl:grid-cols-2">
        {user.can.view_chats ? (
          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Latest chats</h2>
              <Link href="/chats" className="text-xs font-medium text-teal-700 hover:underline">
                Open inbox
              </Link>
            </div>
            {recentChats.length === 0 ? (
              <p className="p-4 text-sm text-slate-500">No Tawk.to conversations yet.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {recentChats.map((c) => (
                  <li key={c.id}>
                    <Link href={`/chats?id=${c.id}`} className="flex items-start gap-3 px-4 py-2.5 hover:bg-slate-50">
                      <MessageSquare className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-slate-900">
                          {c.display_name}
                          <span className="ml-1.5 text-xs font-normal text-slate-500">
                            {c.website?.name ?? "Unlinked property"}
                            {c.kind === "ticket" ? " · Offline message" : ""}
                          </span>
                        </p>
                        <p className="truncate text-sm text-slate-600">{c.last_message ?? "—"}</p>
                      </div>
                      <span className="shrink-0 text-xs text-slate-400">{formatRelative(c.last_message_at, now)}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </section>
        ) : null}

        {canAssign ? (
          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Team, last {range} days</h2>
              <Link href="/team" className="text-xs font-medium text-teal-700 hover:underline">
                Manage team
              </Link>
            </div>
            {data.team.length === 0 ? (
              <p className="p-4 text-sm text-slate-500">No leads have been assigned in this period.</p>
            ) : (
              <table className="table">
                <thead>
                  <tr>
                    <th>Rep</th>
                    <th className="text-right">Leads</th>
                    <th className="text-right">Converted</th>
                    <th className="text-right">Rate</th>
                  </tr>
                </thead>
                <tbody>
                  {data.team.map((t) => (
                    <tr key={t.id}>
                      <td>
                        <Link href={`/leads?assignee=${t.id}`} className="inline-flex items-center gap-2 text-slate-800 hover:text-teal-700">
                          <Avatar name={t.name} size="sm" />
                          {t.name}
                        </Link>
                      </td>
                      <td className="text-right tabular-nums">{formatNumber(t.assigned)}</td>
                      <td className="text-right tabular-nums">{formatNumber(t.converted)}</td>
                      <td className="text-right tabular-nums text-slate-600">{formatPercent(t.converted / t.assigned)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </section>
        ) : null}
      </div>
    </div>
  );
}

function StatTile({
  label,
  value,
  footer,
  href,
  highlight,
}: {
  label: string;
  value: string;
  footer?: React.ReactNode;
  href?: string;
  highlight?: boolean;
}) {
  const body = (
    <>
      <p className="text-sm font-medium text-slate-500">{label}</p>
      <p className={`mt-2 text-3xl font-semibold tracking-tight ${highlight ? "text-amber-700" : "text-slate-900"}`}>{value}</p>
      {footer ? <p className="mt-2 text-xs">{footer}</p> : null}
    </>
  );
  return href ? (
    <Link href={href} className="card block p-5 transition hover:border-slate-300 hover:shadow-sm">
      {body}
    </Link>
  ) : (
    <div className="card p-5">{body}</div>
  );
}
