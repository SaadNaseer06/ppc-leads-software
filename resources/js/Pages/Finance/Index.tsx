import { Head } from "@inertiajs/react";
import type { FinanceData } from "@/lib/api-types";
import { formatDate, formatMoney } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { BulkDownloadButton } from "./partials/bulk-download-button";

export default function FinanceIndex({ transactions, invoices, limit: LIMIT }: FinanceData) {
  return (
    <div className="space-y-5">
      <Head title="Finance" />
      <PageHeader
        title="Finance"
        description={`Payment transactions and invoice downloads (view-only). Showing the latest ${LIMIT} of each.`}
        actions={<BulkDownloadButton invoiceIds={invoices.map((i) => i.id)} />}
      />

      <section className="card overflow-hidden">
        <div className="card-header">
          <h2 className="card-title">Payment transactions</h2>
        </div>
        {transactions.length === 0 ? (
          <EmptyState title="No payment transactions recorded" />
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
                    <td>{tx.invoice.number}</td>
                    <td>{tx.invoice.brand.name}</td>
                    <td>{tx.invoice.lead.name}</td>
                    <td className="text-right tabular-nums">{formatMoney(tx.amount, tx.invoice.currency)}</td>
                    <td className="capitalize">{tx.method.replaceAll("_", " ")}</td>
                    <td>{tx.external_ref ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="card overflow-hidden">
        <div className="card-header">
          <h2 className="card-title">Invoices</h2>
        </div>
        {invoices.length === 0 ? (
          <EmptyState title="No invoices yet" />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Number</th>
                  <th>Brand</th>
                  <th className="text-right">Amount</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {invoices.map((inv) => (
                  <tr key={inv.id}>
                    <td>{inv.number}</td>
                    <td>{inv.brand.name}</td>
                    <td className="text-right tabular-nums">{formatMoney(inv.amount, inv.currency)}</td>
                    <td className="capitalize">{inv.status}</td>
                    <td className="text-right">
                      <a className="btn-secondary btn-sm" href={`/api/invoices/${inv.id}/pdf`}>
                        PDF
                      </a>
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
