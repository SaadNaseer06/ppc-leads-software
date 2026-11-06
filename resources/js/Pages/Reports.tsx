import { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { ArrowDownRight, ArrowUpRight, BarChart3, Download, Loader2, X } from "lucide-react";
import type { SalesBreakdownRow, SalesFilters, SalesReportData } from "@/lib/api-types";
import { formatDayLabel, formatMoney, formatMonthLabel, formatNumber, formatPercent } from "@/lib/format";
import { CHANNEL_LABELS, labelFor } from "@/lib/roles";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { SalesTrendChart } from "@/components/charts/sales-trend-chart";

const STATUS_NAMES: Record<string, string> = { paid: "Paid", unpaid: "Unpaid", overdue: "Overdue", cancelled: "Cancelled" };

/** Calendar dates as YYYY-MM-DD, without timezone surprises */
function ymd(d: Date) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

function presets() {
  const today = new Date();
  const y = today.getFullYear();
  const m = today.getMonth();
  const daysAgo = (n: number) => new Date(y, m, today.getDate() - n);
  return [
    { label: "Last 7 days", from: ymd(daysAgo(6)), to: ymd(today) },
    { label: "Last 30 days", from: ymd(daysAgo(29)), to: ymd(today) },
    { label: "Last 90 days", from: ymd(daysAgo(89)), to: ymd(today) },
    { label: "This month", from: ymd(new Date(y, m, 1)), to: ymd(today) },
    { label: "Last month", from: ymd(new Date(y, m - 1, 1)), to: ymd(new Date(y, m, 0)) },
    { label: "This year", from: ymd(new Date(y, 0, 1)), to: ymd(today) },
  ];
}

export default function Reports(report: SalesReportData) {
  const { filters: f, options, totals, previous, leads, series, granularity } = report;
  const [pending, setPending] = useState(false);

  function query(changes: Partial<SalesFilters>) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries({ ...f, ...changes })) {
      if (value !== null && value !== undefined && value !== "") params.set(key, String(value));
    }
    return params;
  }

  function apply(changes: Partial<SalesFilters>) {
    router.visit(`/reports?${query(changes)}`, {
      replace: true,
      preserveState: true,
      preserveScroll: true,
      onStart: () => setPending(true),
      onFinish: () => setPending(false),
    });
  }

  const ranges = presets();
  const activeRange = ranges.find((r) => r.from === f.from && r.to === f.to)?.label ?? "custom";
  const narrowed = Boolean(f.brand || f.website || f.rep || f.channel || f.status);
  const websites = f.brand ? options.websites.filter((w) => w.brand_id === f.brand) : options.websites;
  const collectionRate = totals.invoiced > 0 ? totals.collected / totals.invoiced : 0;
  const conversion = leads.received > 0 ? leads.converted / leads.received : 0;
  const chart = series.map((s) => ({ ...s, label: granularity === "day" ? formatDayLabel(s.bucket) : formatMonthLabel(s.bucket) }));

  return (
    <div className="space-y-5">
      <Head title="Sales analytics" />
      <PageHeader
        title="Sales analytics"
        description="Based on invoices raised in the selected period. Everything below follows your filters."
        actions={
          <a className="btn-secondary" href={`/api/reports/sales/export?${query({})}`}>
            <Download className="h-4 w-4" />
            Export CSV
          </a>
        }
      />

      <div className="card p-3">
        <div className="flex flex-wrap items-center gap-2">
          <select
            className="input w-auto"
            value={activeRange}
            aria-label="Period"
            onChange={(e) => {
              const range = ranges.find((r) => r.label === e.target.value);
              if (range) apply({ from: range.from, to: range.to });
            }}
          >
            {ranges.map((r) => (
              <option key={r.label} value={r.label}>
                {r.label}
              </option>
            ))}
            <option value="custom" disabled>
              Custom dates
            </option>
          </select>
          <div className="flex items-center gap-1">
            <input type="date" className="input w-[140px]" value={f.from} max={f.to} aria-label="From" onChange={(e) => e.target.value && apply({ from: e.target.value })} />
            <span className="text-slate-400">–</span>
            <input type="date" className="input w-[140px]" value={f.to} min={f.from} aria-label="To" onChange={(e) => e.target.value && apply({ to: e.target.value })} />
          </div>
          {options.brands.length > 1 ? (
            <Filter
              label="Brand"
              all="All brands"
              value={f.brand}
              options={options.brands}
              onChange={(v) => apply({ brand: v ? Number(v) : null, website: null })}
            />
          ) : null}
          <Filter label="Website" all="All websites" value={f.website} options={websites} onChange={(v) => apply({ website: v ? Number(v) : null })} />
          <Filter label="Sales rep" all="All reps" value={f.rep} options={options.reps} onChange={(v) => apply({ rep: v ? Number(v) : null })} />
          <Filter
            label="Channel"
            all="All channels"
            value={f.channel}
            options={options.channels.map((c) => ({ id: c, name: labelFor(CHANNEL_LABELS, c) }))}
            onChange={(v) => apply({ channel: v || null })}
          />
          <Filter
            label="Invoice status"
            all="Any status"
            value={f.status}
            options={options.statuses.map((s) => ({ id: s, name: STATUS_NAMES[s] ?? s }))}
            onChange={(v) => apply({ status: v || null })}
          />
          {narrowed ? (
            <button type="button" className="btn-ghost btn-sm" onClick={() => apply({ brand: null, website: null, rep: null, channel: null, status: null })}>
              <X className="h-3.5 w-3.5" />
              Clear filters
            </button>
          ) : null}
          {pending ? <Loader2 className="h-4 w-4 animate-spin text-slate-400" aria-label="Updating" /> : null}
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Tile label="Invoiced" value={formatMoney(totals.invoiced)} note={totals.invoices > 0 ? `${formatNumber(totals.invoices)} invoice${totals.invoices === 1 ? "" : "s"}, ${formatMoney(totals.average)} average` : "No invoices"} change={[totals.invoiced, previous.invoiced]} />
        <Tile label="Collected" value={formatMoney(totals.collected)} note={`${formatPercent(collectionRate)} of invoiced`} change={[totals.collected, previous.collected]} />
        <Tile
          label="Outstanding"
          value={formatMoney(totals.outstanding)}
          note={totals.overdue > 0 ? `${formatMoney(totals.overdue)} overdue` : "Nothing overdue"}
          alert={totals.overdue > 0}
        />
        <Tile label="Lead conversion" value={formatPercent(conversion)} note={`${formatNumber(leads.converted)} of ${formatNumber(leads.received)} leads converted`} />
      </div>

      {totals.invoices === 0 ? (
        <div className="card">
          <EmptyState
            icon={<BarChart3 className="h-5 w-5" />}
            title="No sales match these filters"
            description="Try a longer period or clear a filter."
          />
        </div>
      ) : (
        <>
          <section className="card">
            <div className="card-header">
              <div>
                <h2 className="card-title">Sales by {granularity === "day" ? "day" : "month"}</h2>
                <p className="text-xs text-slate-500">Each column is what was invoiced: how much has come in, and how much is still owed</p>
              </div>
            </div>
            <div className="p-4">
              <SalesTrendChart data={chart} />
            </div>
          </section>

          <div className="grid gap-5 xl:grid-cols-2">
            <Breakdown
              title="By brand"
              first="Brand"
              rows={report.by_brand}
              hideWhen={options.brands.length < 2}
              href={(r) => `/reports?${query({ brand: Number(r.id), website: null })}`}
            />
            <Breakdown title="By sales rep" first="Sales rep" rows={report.by_rep} href={(r) => `/reports?${query({ rep: Number(r.id) })}`} />
            <Breakdown
              title="By channel"
              first="Channel"
              rows={report.by_channel}
              name={(r) => (r.name ? labelFor(CHANNEL_LABELS, r.name) : "Direct (no lead)")}
              href={(r) => (r.id ? `/reports?${query({ channel: String(r.id) })}` : undefined)}
            />
            <Breakdown title="Top campaigns" first="Campaign" rows={report.by_campaign} emptyHint="No invoices came from a tagged campaign in this view." />
          </div>
        </>
      )}
    </div>
  );
}

function Filter({
  label,
  all,
  value,
  options,
  onChange,
}: {
  label: string;
  all: string;
  value: string | number | null;
  options: { id: string | number; name: string }[];
  onChange: (value: string) => void;
}) {
  return (
    <select
      className={`input w-auto max-w-[190px] ${value ? "border-teal-500 bg-teal-50/40" : ""}`}
      value={value ?? ""}
      onChange={(e) => onChange(e.target.value)}
      aria-label={label}
    >
      <option value="">{all}</option>
      {options.map((o) => (
        <option key={o.id} value={o.id}>
          {o.name}
        </option>
      ))}
    </select>
  );
}

function Tile({ label, value, note, change, alert }: { label: string; value: string; note: string; change?: [number, number]; alert?: boolean }) {
  const delta = change && change[1] > 0 ? (change[0] - change[1]) / change[1] : null;
  return (
    <div className="card p-5">
      <p className="text-sm text-slate-500">{label}</p>
      <p className="mt-1.5 text-2xl font-semibold tracking-tight text-slate-900 tabular-nums">{value}</p>
      <p className={`mt-1 text-xs ${alert ? "font-medium text-red-700" : "text-slate-500"}`}>{note}</p>
      {delta !== null ? (
        <p className={`mt-1 inline-flex items-center gap-1 text-xs ${delta >= 0 ? "text-emerald-700" : "text-red-700"}`}>
          {delta >= 0 ? <ArrowUpRight className="h-3.5 w-3.5" /> : <ArrowDownRight className="h-3.5 w-3.5" />}
          {formatPercent(Math.abs(delta))} vs previous period
        </p>
      ) : null}
    </div>
  );
}

function Breakdown({
  title,
  first,
  rows,
  href,
  name,
  hideWhen,
  emptyHint,
}: {
  title: string;
  first: string;
  rows: SalesBreakdownRow[];
  href?: (row: SalesBreakdownRow) => string | undefined;
  name?: (row: SalesBreakdownRow) => string;
  hideWhen?: boolean;
  emptyHint?: string;
}) {
  if (hideWhen) return null;
  return (
    <section className="card overflow-hidden">
      <div className="card-header">
        <h2 className="card-title">{title}</h2>
      </div>
      {rows.length === 0 ? (
        <p className="p-4 text-sm text-slate-500">{emptyHint ?? "Nothing to show."}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="table">
            <thead>
              <tr>
                <th>{first}</th>
                <th className="text-right">Invoiced</th>
                <th className="text-right">Collected</th>
                <th className="text-right">Outstanding</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => {
                const label = name ? name(r) : r.name;
                const link = href?.(r);
                return (
                  <tr key={r.id ?? "none"}>
                    <td className="max-w-[220px] truncate" title={label}>
                      {link ? (
                        <Link href={link} preserveScroll className="font-medium text-teal-700 hover:underline">
                          {label}
                        </Link>
                      ) : (
                        <span className="font-medium text-slate-800">{label}</span>
                      )}
                      <p className="text-xs text-slate-500">
                        {formatNumber(r.invoices)} invoice{r.invoices === 1 ? "" : "s"}
                      </p>
                    </td>
                    <td className="text-right tabular-nums">{formatMoney(r.invoiced)}</td>
                    <td className="text-right tabular-nums">{formatMoney(r.collected)}</td>
                    <td className="text-right tabular-nums">{formatMoney(r.outstanding)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
