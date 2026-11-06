import { useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import { CircleAlert, CreditCard, FileText } from "lucide-react";
import type { BillingStatus, InvoicesData } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatCalendarDay, formatDate, formatMoney } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { apiRequest, useToast } from "@/components/ui/toast";
import { CreateInvoiceButton } from "./Invoices/partials/create-invoice-dialog";
import { InvoiceActions, InvoiceStatusBadge, balanceDue } from "./Invoices/partials/invoice-actions";
import { PaymentSettingsDialog } from "./Invoices/partials/payment-settings-dialog";

export default function Invoices({ invoices, limit: LIMIT, brands, billing }: InvoicesData) {
  const user = usePage<SharedProps>().props.auth.user!;
  const [settingsOpen, setSettingsOpen] = useState(false);
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
        actions={
          <div className="flex flex-wrap gap-2">
            {billing?.can_configure ? (
              <button type="button" className="btn-secondary" onClick={() => setSettingsOpen(true)}>
                <CreditCard className="h-4 w-4" />
                Payment settings
              </button>
            ) : null}
            {createButton}
          </div>
        }
      />

      {billing ? <CardPaymentsNotice billing={billing} canReload={user.can.manage_brands} onSetUp={() => setSettingsOpen(true)} /> : null}
      {billing?.can_configure ? <PaymentSettingsDialog open={settingsOpen} onClose={() => setSettingsOpen(false)} brands={billing.brands} /> : null}

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
                    <td className="max-w-[200px] truncate">
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

const nameList = (names: string[]) => (names.length > 3 ? `${names.length} brands` : names.join(", ").replace(/, ([^,]*)$/, " and $1"));

/** Says exactly which brands can't take cards and why, with the way to fix it right there */
function CardPaymentsNotice({ billing, canReload, onSetUp }: { billing: BillingStatus; canReload: boolean; onSetUp: () => void }) {
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

  const off = billing.brands.filter((b) => !b.card_payments).map((b) => b.name);
  const noWebhook = billing.brands.filter((b) => b.card_payments && !b.webhook).map((b) => b.name);

  let message: string | null = null;
  if (billing.problem) {
    message = `Check the keys in the server's .env. ${billing.problem}`;
  } else if (off.length > 0) {
    message = billing.needs_reload
      ? `${nameList(off)} can't take card payments yet. The server's .env has Stripe keys, but the app is running on settings saved before they were added.`
      : `${nameList(off)} can't take card payments yet.${billing.can_configure ? "" : " Ask a Super Admin to add the Stripe keys under Payment settings."}`;
  } else if (noWebhook.length > 0) {
    message = `Card payments are on, but ${nameList(noWebhook)} has no webhook. A payment won't be recorded if the client closes the page straight after paying.`;
  }
  if (!message) return null;

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      <CircleAlert className="h-4 w-4 shrink-0" />
      <p className="min-w-0 flex-1 basis-64">{message}</p>
      {billing.needs_reload && off.length > 0 && canReload ? (
        <button type="button" className="btn-secondary btn-sm" onClick={() => void reload()} disabled={reloading}>
          {reloading ? "Reloading…" : "Reload settings"}
        </button>
      ) : null}
      {billing.can_configure ? (
        <button type="button" className="btn-primary btn-sm" onClick={onSetUp}>
          {off.length > 0 ? "Set up card payments" : "Add webhook"}
        </button>
      ) : null}
    </div>
  );
}
