import { Head } from "@inertiajs/react";
import type { BrandReport } from "@/lib/api-types";
import { formatMoney, formatNumber, formatPercent } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";

export default function Reports({ reports }: { reports: BrandReport[] }) {
  return (
    <div className="space-y-5">
      <Head title="Reports" />
      <PageHeader
        title="Sales by brand"
        description="All-time totals. Invoice totals exclude cancelled invoices. Super Admin and Finance see every brand."
      />

      {reports.length === 0 ? (
        <div className="card">
          <EmptyState title="No brands available for your role" />
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {reports.map((r) => (
            <div key={r.brand_id} className="card p-5">
              <h2 className="text-base font-semibold text-slate-900">{r.brand_name}</h2>
              <dl className="mt-4 space-y-2.5 text-sm">
                <Row label="Leads" value={formatNumber(r.lead_count)} />
                <Row
                  label="Converted"
                  value={`${formatNumber(r.converted_count)}${r.lead_count ? ` (${formatPercent(r.converted_count / r.lead_count)})` : ""}`}
                />
                <Row label="Invoices" value={formatNumber(r.invoice_count)} />
                <Row label="Invoiced" value={formatMoney(r.total_amount)} strong />
                <Row label="Marked paid" value={formatMoney(r.paid_amount)} />
              </dl>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-slate-500">{label}</dt>
      <dd className={`tabular-nums ${strong ? "font-semibold text-slate-900" : "font-medium text-slate-800"}`}>{value}</dd>
    </div>
  );
}
