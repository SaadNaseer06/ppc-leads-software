import { useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import { CircleAlert, FileText } from "lucide-react";
import type { BillingStatus, InvoicesData } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatCalendarDay, formatDate, formatMoney } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { apiRequest, useToast } from "@/components/ui/toast";
import { CreateInvoiceButton } from "./Invoices/partials/create-invoice-dialog";
import { InvoiceActions, InvoiceStatusBadge, balanceDue } from "./Invoices/partials/invoice-actions";

export default function Invoices({ invoices, limit: LIMIT, brands, billing }: InvoicesData) {
  const user = usePage<SharedProps>().props.auth.user!;
  const createButton = user.can.create_invoice ? <CreateInvoiceButton brands={brands} /> : null;

  return (
    <div className="space-y-5">
      <Head title="Invoices" />
      <PageHeader
        title="Invoices"
        description={
          user.can.manage_invoices
            ? `Create invoices, record payments and track what is still due. Showing the latest ${LIMIT}.`
            : `Invoices you have created. Showing the latest ${LIMIT}.`
        }
        actions={createButton}
      />

      {billing ? <CardPaymentsNotice billing={billing} canReload={user.can.manage_brands} /> : null}

      <div className="card overflow-hidden">
        {invoices.length === 0 ? (
          <EmptyState icon={<FileText className="h-5 w-5" />} title="No invoices yet" description="Create an invoice for a lead or a new client." action={createButton} />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Invoice</th>
                  <th>Client</th>
                  <th className="text-right">Amount</th>
                  <th className="text-right">Balance due</th>
                  <th>Status</th>
                  <th>Issued</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {invoices.map((inv) => (
                  <tr key={inv.id}>
                    <td className="whitespace-nowrap">
                      <span className="font-medium text-slate-900">{inv.number}</span>
                      <span className="block text-xs text-slate-500">{inv.brand.name}</span>
                    </td>
                    <td className="max-w-[240px] truncate">
                      {inv.lead ? (
                        <Link href={`/leads/${inv.lead.id}`} className="text-teal-700 hover:underline" title={inv.client_name ?? inv.lead.name}>
                          {inv.client_name ?? inv.lead.name}
                        </Link>
                      ) : (
                        <span className="text-slate-800" title={inv.client_name ?? undefined}>
                          {inv.client_name}
                        </span>
                      )}
                      {inv.client_email ? <span className="block truncate text-xs text-slate-500">{inv.client_email}</span> : null}
                    </td>
                    <td className="text-right tabular-nums">{formatMoney(inv.amount, inv.currency)}</td>
                    <td className="text-right tabular-nums text-slate-600">
                      {inv.status === "cancelled" ? "—" : formatMoney(inv.status === "paid" ? 0 : balanceDue(inv), inv.currency)}
                    </td>
                    <td>
                      <InvoiceStatusBadge status={inv.status} partlyPaid={Number(inv.paid_total ?? 0) > 0} overdue={inv.is_overdue} />
                    </td>
                    <td className="whitespace-nowrap">
                      {formatDate(inv.created_at)}
                      {inv.due_date ? <span className="block text-xs text-slate-500">Due {formatCalendarDay(inv.due_date)}</span> : null}
                    </td>
                    <td className="text-right">
                      <InvoiceActions invoice={inv} />
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

/** Says exactly why clients can't pay by card, so nobody has to guess */
function CardPaymentsNotice({ billing, canReload }: { billing: BillingStatus; canReload: boolean }) {
  const toast = useToast();
  const [reloading, setReloading] = useState(false);

  async function reload() {
    setReloading(true);
    const res = await apiRequest("/api/billing/reload-settings", { method: "POST" });
    if (!res.ok) {
      setReloading(false);
      toast.error(res.error);
      return;
    }
    window.location.reload();
  }

  let message: string | null = null;
  if (billing.needs_reload) {
    message = "Your Stripe keys are in the server's .env, but the app is still running on settings saved before you added them.";
  } else if (billing.problem) {
    message = billing.problem;
  } else if (billing.missing.length > 0 && !billing.card_payments) {
    message = `Clients can't pay by card yet. Add ${billing.missing.join(" and ")} to the server's .env.`;
  } else if (!billing.card_payments) {
    message = "Clients can't pay by card yet.";
  } else if (!billing.webhook) {
    message = "Card payments are on, but STRIPE_WEBHOOK_SECRET isn't set. A payment won't be recorded if the client closes the page straight after paying.";
  }
  if (!message) return null;

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      <CircleAlert className="h-4 w-4 shrink-0" />
      <p className="min-w-0 flex-1 basis-64">{message}</p>
      {billing.needs_reload ? (
        canReload ? (
          <button type="button" className="btn-secondary btn-sm" onClick={() => void reload()} disabled={reloading}>
            {reloading ? "Reloading…" : "Reload settings"}
          </button>
        ) : (
          <span className="text-xs">Ask a Super Admin to reload the settings.</span>
        )
      ) : null}
    </div>
  );
}
