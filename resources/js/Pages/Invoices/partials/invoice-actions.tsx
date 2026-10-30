import { FormEvent, useState } from "react";
import { router } from "@inertiajs/react";
import { Ban, CheckCircle2, CircleDollarSign, RotateCcw } from "lucide-react";
import { formatMoney } from "@/lib/format";
import { useConfirm } from "@/components/ui/confirm";
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

export function InvoiceStatusBadge({ status, partlyPaid }: { status: string; partlyPaid?: boolean }) {
  if (status === "sent" && partlyPaid) {
    return <span className="badge badge-dot border-amber-100 bg-amber-50 text-amber-800">Part paid</span>;
  }
  return (
    <span className={`badge badge-dot capitalize ${STATUS_STYLES[status] ?? ""}`}>
      {status === "sent" ? "Unpaid" : status}
    </span>
  );
}

type InvoiceForActions = { id: number; number: string; amount: string; currency: string; status: string; paid_total: string | null };

/** Balance still due, in the invoice currency */
export function balanceDue(invoice: { amount: string; paid_total: string | null }) {
  return Math.max(0, Math.round((Number(invoice.amount) - Number(invoice.paid_total ?? 0)) * 100) / 100);
}

export function InvoiceActions({ invoice }: { invoice: InvoiceForActions }) {
  const toast = useToast();
  const confirm = useConfirm();
  const [paying, setPaying] = useState(false);
  const [busy, setBusy] = useState(false);
  const balance = balanceDue(invoice);

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

  if (invoice.status === "cancelled") {
    return (
      <button
        type="button"
        className="btn-ghost btn-sm"
        disabled={busy}
        onClick={() => setStatus("sent", `${invoice.number} reopened`)}
        title="Reopen this invoice"
      >
        <RotateCcw className="h-3.5 w-3.5" />
        Reopen
      </button>
    );
  }

  return (
    <div className="flex items-center justify-end gap-1">
      {invoice.status !== "paid" && balance > 0 ? (
        <button type="button" className="btn-secondary btn-sm" onClick={() => setPaying(true)} disabled={busy}>
          <CircleDollarSign className="h-3.5 w-3.5" />
          Record payment
        </button>
      ) : null}
      {invoice.status !== "paid" ? (
        <button
          type="button"
          className="btn-ghost btn-sm"
          disabled={busy}
          title="Mark as paid without recording a payment"
          onClick={async () => {
            const ok = await confirm({
              title: `Mark ${invoice.number} as paid?`,
              description:
                balance > 0
                  ? `No payment is recorded for the remaining ${formatMoney(balance, invoice.currency)}. Record a payment instead if you want it in the transactions list.`
                  : "The invoice will show as paid.",
              confirmLabel: "Mark as paid",
              tone: "default",
            });
            if (ok) void setStatus("paid", `${invoice.number} marked as paid`);
          }}
        >
          <CheckCircle2 className="h-3.5 w-3.5" />
          Paid
        </button>
      ) : null}
      <button
        type="button"
        className="btn-ghost btn-sm text-slate-500 hover:text-red-600"
        disabled={busy}
        title="Cancel invoice"
        aria-label={`Cancel ${invoice.number}`}
        onClick={async () => {
          const ok = await confirm({
            title: `Cancel ${invoice.number}?`,
            description: "Cancelled invoices are left out of invoiced and collected totals. You can reopen it later.",
            confirmLabel: "Cancel invoice",
            tone: "danger",
          });
          if (ok) void setStatus("cancelled", `${invoice.number} cancelled`);
        }}
      >
        <Ban className="h-3.5 w-3.5" />
      </button>

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
