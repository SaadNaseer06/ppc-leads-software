import { Head, Link, usePage } from "@inertiajs/react";
import { FileText, Wallet } from "lucide-react";
import type { FinanceData } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatDate, formatMoney } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { BulkDownloadButton } from "./partials/bulk-download-button";
import { InvoiceActions, InvoiceStatusBadge, PAYMENT_METHODS, balanceDue } from "../Invoices/partials/invoice-actions";

export default function FinanceIndex({ transactions, invoices, limit: LIMIT }: FinanceData) {
  const user = usePage<SharedProps>().props.auth.user!;

  return (
    <div className="space-y-5">
      <Head title="Finance" />
      <PageHeader
        title="Finance"
        description={`Record payments, settle invoices and download PDFs. Showing the latest ${LIMIT} of each.`}
        actions={<BulkDownloadButton invoiceIds={invoices.map((i) => i.id)} />}
      />

      <section className="card overflow-hidden">
        <div className="card-header">
          <h2 className="card-title">Invoices</h2>
        </div>
        {invoices.length === 0 ? (
          <EmptyState icon={<FileText className="h-5 w-5" />} title="No invoices yet" description="Invoices created by sales appear here." />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Number</th>
                  <th>Lead</th>
                  <th>Brand</th>
                  <th className="text-right">Amount</th>
                  <th className="text-right">Balance due</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {invoices.map((inv) => (
                  <tr key={inv.id}>
                    <td className="whitespace-nowrap font-medium text-slate-900">{inv.number}</td>
                    <td className="max-w-[220px] truncate">
                      <Link href={`/leads/${inv.lead.id}`} className="text-teal-700 hover:underline" title={inv.lead.name}>
                        {inv.lead.name}
                      </Link>
                    </td>
                    <td className="whitespace-nowrap">{inv.brand.name}</td>
                    <td className="text-right tabular-nums">{formatMoney(inv.amount, inv.currency)}</td>
                    <td className="text-right tabular-nums text-slate-600">
                      {inv.status === "cancelled" ? "—" : formatMoney(inv.status === "paid" ? 0 : balanceDue(inv), inv.currency)}
                    </td>
                    <td>
                      <InvoiceStatusBadge status={inv.status} partlyPaid={Number(inv.paid_total ?? 0) > 0} />
                    </td>
                    <td className="whitespace-nowrap">{formatDate(inv.created_at)}</td>
                    <td className="text-right">
                      <div className="flex items-center justify-end gap-1">
                        {user.can.manage_invoices ? <InvoiceActions invoice={inv} /> : null}
                        <a className="btn-ghost btn-sm" href={`/api/invoices/${inv.id}/pdf`} title="Download PDF">
                          PDF
                        </a>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="card overflow-hidden">
        <div className="card-header">
          <h2 className="card-title">Payments received</h2>
        </div>
        {transactions.length === 0 ? (
          <EmptyState
            icon={<Wallet className="h-5 w-5" />}
            title="No payments recorded yet"
            description="Use “Record payment” on an invoice when money comes in."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Invoice</th>
                  <th>Brand</th>
                  <th>Lead</th>
                  <th className="text-right">Amount</th>
                  <th>Method</th>
                  <th>Reference</th>
                </tr>
              </thead>
              <tbody>
                {transactions.map((tx) => (
                  <tr key={tx.id}>
                    <td className="whitespace-nowrap">{formatDate(tx.recorded_at)}</td>
                    <td className="font-medium text-slate-900">{tx.invoice.number}</td>
                    <td>{tx.invoice.brand.name}</td>
                    <td>{tx.invoice.lead.name}</td>
                    <td className="text-right tabular-nums">{formatMoney(tx.amount, tx.invoice.currency)}</td>
                    <td>{PAYMENT_METHODS[tx.method] ?? tx.method.replaceAll("_", " ")}</td>
                    <td className="max-w-[260px]">
                      <span className="block truncate" title={tx.notes ?? undefined}>
                        {tx.external_ref ?? <span className="text-slate-400">—</span>}
                      </span>
                      {tx.notes ? <span className="block truncate text-xs text-slate-500">{tx.notes}</span> : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
