import { FormEvent, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { Ban, CheckCircle2, CircleDollarSign, Download, ExternalLink, Mail, RotateCcw, Trash2 } from "lucide-react";
import type { SharedProps } from "@/types";
import { formatMoney } from "@/lib/format";
import { ActionMenu } from "@/components/ui/action-menu";
import { useConfirm } from "@/components/ui/confirm";
import { CopyButton } from "@/components/ui/copy-button";
import { Modal } from "@/components/ui/modal";
import { apiRequest, useToast } from "@/components/ui/toast";

export const PAYMENT_METHODS: Record<string, string> = {
  bank_transfer: "Bank transfer",
  card: "Card",
  cash: "Cash",
  cheque: "Cheque",
  paypal: "PayPal",
  stripe: "Stripe",
  other: "Other",
};

const STATUS_STYLES: Record<string, string> = {
  sent: "bg-blue-50 text-blue-700 border-blue-100",
  paid: "bg-emerald-50 text-emerald-700 border-emerald-100",
  cancelled: "bg-slate-100 text-slate-500 border-slate-200",
  draft: "bg-slate-50 text-slate-600 border-slate-200",
};

export function InvoiceStatusBadge({ status, partlyPaid, overdue }: { status: string; partlyPaid?: boolean; overdue?: boolean }) {
  if (overdue) {
    return <span className="badge badge-dot border-red-100 bg-red-50 text-red-700">Overdue</span>;
  }
  if (status === "sent" && partlyPaid) {
    return <span className="badge badge-dot border-amber-100 bg-amber-50 text-amber-800">Part paid</span>;
  }
  return (
    <span className={`badge badge-dot capitalize ${STATUS_STYLES[status] ?? ""}`}>
      {status === "sent" ? "Unpaid" : status}
    </span>
  );
}

type InvoiceForActions = {
  id: number;
  number: string;
  amount: string;
  currency: string;
  status: string;
  paid_total: string | null;
  pay_url: string | null;
};

/** Balance still due, in the invoice currency */
export function balanceDue(invoice: { amount: string; paid_total: string | null }) {
  return Math.max(0, Math.round((Number(invoice.amount) - Number(invoice.paid_total ?? 0)) * 100) / 100);
}

export function InvoiceActions({ invoice }: { invoice: InvoiceForActions }) {
  const can = usePage<SharedProps>().props.auth.user!.can;
  const toast = useToast();
  const confirm = useConfirm();
  const [paying, setPaying] = useState(false);
  const [busy, setBusy] = useState(false);
  const balance = balanceDue(invoice);
  const paid = Number(invoice.paid_total ?? 0) > 0;
  const open = invoice.status !== "paid" && invoice.status !== "cancelled";
  const icon = "h-3.5 w-3.5";

  async function setStatus(status: string, message: string) {
    setBusy(true);
    const res = await apiRequest(`/api/invoices/${invoice.id}`, { method: "PATCH", body: { status } });
    setBusy(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    toast.success(message);
    router.reload();
  }

  async function emailClient() {
    setBusy(true);
    const res = await apiRequest(`/api/invoices/${invoice.id}/email`, { method: "POST", body: {} });
    setBusy(false);
    if (res.ok) toast.success(`${invoice.number} emailed to the client`);
    else toast.error(res.error);
  }

  async function markPaid() {
    const ok = await confirm({
      title: `Mark ${invoice.number} as paid?`,
      description:
        balance > 0
          ? `The remaining ${formatMoney(balance, invoice.currency)} is recorded as received. Use “Record payment” instead to add the method and reference.`
          : "The invoice will show as paid.",
      confirmLabel: "Mark as paid",
      tone: "default",
    });
    if (ok) void setStatus("paid", `${invoice.number} marked as paid`);
  }

  async function cancelInvoice() {
    const ok = await confirm({
      title: `Cancel ${invoice.number}?`,
      description: "Cancelled invoices are left out of invoiced and collected totals, and the client can no longer pay. You can reopen it later.",
      confirmLabel: "Cancel invoice",
      tone: "danger",
    });
    if (ok) void setStatus("cancelled", `${invoice.number} cancelled`);
  }

  async function deleteInvoice() {
    const ok = await confirm(
      paid
        ? {
            title: `Delete ${invoice.number}?`,
            description: `This invoice has ${formatMoney(invoice.paid_total ?? 0, invoice.currency)} in payments. They'll be removed from your totals and reports, and the client's link will stop working. Money already taken stays in your Stripe or bank account.`,
            confirmLabel: "Delete invoice",
            tone: "danger",
            requireText: "DELETE",
            requireTextLabel: "Type DELETE to confirm",
          }
        : {
            title: `Delete ${invoice.number}?`,
            description: "It disappears from invoices and reports, and the client's link stops working.",
            confirmLabel: "Delete invoice",
            tone: "danger",
          }
    );
    if (!ok) return;

    setBusy(true);
    const res = await apiRequest(`/api/invoices/${invoice.id}${paid ? "?force=1" : ""}`, { method: "DELETE" });
    setBusy(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    toast.success(`${invoice.number} deleted`);
    router.reload();
  }

  return (
    <div className="flex items-center justify-end gap-1.5">
      {can.manage_invoices && open && balance > 0 ? (
        <button type="button" className="btn-secondary btn-sm whitespace-nowrap" onClick={() => setPaying(true)} disabled={busy}>
          <CircleDollarSign className={icon} />
          Record payment
        </button>
      ) : null}
      {invoice.pay_url && invoice.status !== "cancelled" ? <CopyButton value={invoice.pay_url} label="Copy link" /> : null}
      <ActionMenu
        label={`More actions for ${invoice.number}`}
        groups={[
          [
            ...(invoice.pay_url && invoice.status !== "cancelled"
              ? [{ label: "View as client", icon: <ExternalLink className={icon} />, onSelect: () => void window.open(invoice.pay_url!, "_blank", "noopener") }]
              : []),
            { label: "Download PDF", icon: <Download className={icon} />, onSelect: () => window.location.assign(`/api/invoices/${invoice.id}/pdf`) },
            ...(open ? [{ label: "Email to client", icon: <Mail className={icon} />, onSelect: () => void emailClient(), disabled: busy }] : []),
          ],
          can.manage_invoices
            ? [
                ...(open ? [{ label: "Mark as paid", icon: <CheckCircle2 className={icon} />, onSelect: () => void markPaid(), disabled: busy }] : []),
                ...(open ? [{ label: "Cancel invoice", icon: <Ban className={icon} />, onSelect: () => void cancelInvoice(), disabled: busy }] : []),
                ...(invoice.status === "cancelled"
                  ? [{ label: "Reopen invoice", icon: <RotateCcw className={icon} />, onSelect: () => void setStatus("sent", `${invoice.number} reopened`), disabled: busy }]
                  : []),
              ]
            : [],
          can.delete_invoices ? [{ label: "Delete invoice", icon: <Trash2 className={icon} />, onSelect: () => void deleteInvoice(), danger: true, disabled: busy }] : [],
        ]}
      />

      <RecordPaymentDialog open={paying} onClose={() => setPaying(false)} invoice={invoice} balance={balance} />
    </div>
  );
}

function RecordPaymentDialog({
  open,
  onClose,
  invoice,
  balance,
}: {
  open: boolean;
  onClose: () => void;
  invoice: InvoiceForActions;
  balance: number;
}) {
  const toast = useToast();
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    const value = (key: string) => String(form.get(key) ?? "").trim() || null;
    setSaving(true);
    setError("");
    const res = await apiRequest<{ status: string }>(`/api/invoices/${invoice.id}/payments`, {
      method: "POST",
      body: {
        amount: Number(value("amount")),
        method: value("method"),
        external_ref: value("external_ref"),
        notes: value("notes"),
      },
    });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    onClose();
    toast.success(res.data.status === "paid" ? `${invoice.number} is now paid in full` : "Payment recorded");
    router.reload();
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`Record payment · ${invoice.number}`}
      description={`Balance due ${formatMoney(balance, invoice.currency)} of ${formatMoney(invoice.amount, invoice.currency)}`}
    >
      <form onSubmit={onSubmit} className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="pay-amount">Amount ({invoice.currency}) *</label>
          <input
            id="pay-amount"
            className="input"
            name="amount"
            type="number"
            min="0.01"
            max={balance}
            step="0.01"
            defaultValue={balance.toFixed(2)}
            required
            autoFocus
          />
        </div>
        <div>
          <label className="label" htmlFor="pay-method">Method *</label>
          <select id="pay-method" className="input" name="method" defaultValue="bank_transfer" required>
            {Object.entries(PAYMENT_METHODS).map(([id, label]) => (
              <option key={id} value={id}>{label}</option>
            ))}
          </select>
        </div>
        <div className="sm:col-span-2">
          <label className="label" htmlFor="pay-ref">Reference</label>
          <input id="pay-ref" className="input" name="external_ref" placeholder="Transaction ID, cheque number…" maxLength={191} />
        </div>
        <div className="sm:col-span-2">
          <label className="label" htmlFor="pay-notes">Notes</label>
          <textarea id="pay-notes" className="input" name="notes" rows={2} maxLength={2000} />
        </div>
        {error ? <p className="text-sm text-red-600 sm:col-span-2">{error}</p> : null}
        <div className="flex justify-end gap-2 sm:col-span-2">
          <button type="button" className="btn-secondary" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className="btn-primary" disabled={saving}>
            {saving ? "Saving…" : "Record payment"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
