import { Head, Link, usePage } from "@inertiajs/react";
import { FileText } from "lucide-react";
import type { InvoiceRow } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatDate, formatMoney } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { CreateInvoiceButton } from "./Invoices/partials/create-invoice-dialog";
import { InvoiceActions, InvoiceStatusBadge, balanceDue } from "./Invoices/partials/invoice-actions";

export default function Invoices({ invoices, limit: LIMIT }: { invoices: InvoiceRow[]; limit: number }) {
  const user = usePage<SharedProps>().props.auth.user!;

  return (
    <div className="space-y-5">
      <Head title="Invoices" />
      <PageHeader
        title="Invoices"
        description={
          user.can.manage_invoices
            ? `Create invoices, record payments and track what is still due. Showing the latest ${LIMIT}.`
            : `Invoices you have created for your leads. Showing the latest ${LIMIT}.`
        }
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
                  <th className="text-right">Balance due</th>
                  <th>Status</th>
                  <th>Created by</th>
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
                    <td className="whitespace-nowrap">{inv.created_by.name}</td>
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
      </div>
    </div>
  );
}
