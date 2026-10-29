import { Head, Link } from "@inertiajs/react";
import { FileText } from "lucide-react";
import type { InvoiceRow } from "@/lib/api-types";
import { formatDate, formatMoney } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";

export default function Invoices({ invoices, limit: LIMIT }: { invoices: InvoiceRow[]; limit: number }) {
  return (
    <div className="space-y-5">
      <Head title="Invoices" />
      <PageHeader
        title="Invoices"
        description={`Sales users see the invoices they created; Finance and Super Admin see all. Showing the latest ${LIMIT}.`}
      />

      <div className="card overflow-hidden">
        {invoices.length === 0 ? (
          <EmptyState
            icon={<FileText className="h-5 w-5" />}
            title="No invoices yet"
            description="Create an invoice from a lead's page once the deal is agreed."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Number</th>
                  <th>Lead</th>
                  <th>Brand</th>
                  <th className="text-right">Amount</th>
                  <th>Status</th>
                  <th>Created by</th>
                  <th>Date</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {invoices.map((inv) => (
                  <tr key={inv.id}>
                    <td className="font-medium text-slate-900">{inv.number}</td>
                    <td>
                      <Link href={`/leads/${inv.lead.id}`} className="text-teal-700 hover:underline">
                        {inv.lead.name}
                      </Link>
                    </td>
                    <td>{inv.brand.name}</td>
                    <td className="text-right tabular-nums">{formatMoney(inv.amount, inv.currency)}</td>
                    <td className="capitalize">{inv.status}</td>
                    <td>{inv.created_by.name}</td>
                    <td className="whitespace-nowrap">{formatDate(inv.created_at)}</td>
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
      </div>
    </div>
  );
}
