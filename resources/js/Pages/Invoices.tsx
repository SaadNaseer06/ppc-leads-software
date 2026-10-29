import { Head, Link, usePage } from "@inertiajs/react";
import { FileText } from "lucide-react";
import type { InvoiceRow } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatDate, formatMoney } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { CreateInvoiceButton } from "./Invoices/partials/create-invoice-dialog";

export default function Invoices({ invoices, limit: LIMIT }: { invoices: InvoiceRow[]; limit: number }) {
  const user = usePage<SharedProps>().props.auth.user!;

  return (
    <div className="space-y-5">
      <Head title="Invoices" />
      <PageHeader
        title="Invoices"
        description={`Sales and Admin create invoices; Finance and Super Admin see all. Showing the latest ${LIMIT}.`}
        actions={user.can.create_invoice ? <CreateInvoiceButton /> : null}
      />

      <div className="card overflow-hidden">
        {invoices.length === 0 ? (
          <EmptyState
            icon={<FileText className="h-5 w-5" />}
            title="No invoices yet"
            description="Create an invoice from this page or from a lead once the deal is agreed."
            action={user.can.create_invoice ? <CreateInvoiceButton /> : null}
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
