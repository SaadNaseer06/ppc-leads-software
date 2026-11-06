import { FormEvent, useEffect, useMemo, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import { CheckCircle2, CircleAlert, ExternalLink, Mail, Plus, X } from "lucide-react";
import { formatMoney } from "@/lib/format";
import { CopyButton } from "@/components/ui/copy-button";
import { Modal } from "@/components/ui/modal";
import { apiRequest } from "@/components/ui/toast";

type LeadBrand = { name: string; merchant_name?: string | null };
type LeadOption = { id: number; name: string; email: string | null; company: string | null; brand?: LeadBrand | null };
type Item = { key: number; label: string; quantity: string; unit_price: string };
type Created = {
  number: string;
  amount: string;
  currency: string;
  merchant_name: string;
  pay_url: string;
  card_payments: boolean;
  lead: { name: string; email: string | null };
};

const CURRENCY = "USD";
const round = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;
const number = (value: string) => {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
};

/** `lead` fixes who the invoice is for (from a lead's page); otherwise the dialog asks */
export function CreateInvoiceButton({ lead, compact }: { lead?: LeadOption; compact?: boolean }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button type="button" className={compact ? "btn-secondary btn-sm w-full" : "btn-primary"} onClick={() => setOpen(true)}>
        <Plus className="h-4 w-4" />
        Create invoice
      </button>
      <CreateInvoiceDialog open={open} onClose={() => setOpen(false)} lead={lead} />
    </>
  );
}

function CreateInvoiceDialog({ open, onClose, lead }: { open: boolean; onClose: () => void; lead?: LeadOption }) {
  const [created, setCreated] = useState<Created | null>(null);

  function close() {
    if (created) router.reload();
    setCreated(null);
    onClose();
  }

  return (
    <Modal
      open={open}
      onClose={close}
      wide
      title={created ? `Invoice ${created.number} is ready` : "Create invoice"}
      description={created ? undefined : "Add what you're charging for. A secure payment link is created for the client."}
    >
      {created ? <InvoiceReady invoice={created} onDone={close} /> : <InvoiceForm lead={lead} onCancel={close} onCreated={setCreated} />}
    </Modal>
  );
}

function InvoiceForm({ lead, onCancel, onCreated }: { lead?: LeadOption; onCancel: () => void; onCreated: (invoice: Created) => void }) {
  const nextKey = useRef(1);
  const [items, setItems] = useState<Item[]>([{ key: 0, label: "", quantity: "1", unit_price: "" }]);
  const [leadId, setLeadId] = useState<number | "">(lead?.id ?? "");
  const [pickedBrand, setPickedBrand] = useState<LeadBrand | null>(null);
  // Follows the client's brand until someone types their own
  const [typedMerchant, setTypedMerchant] = useState<string | null>(null);
  const [discount, setDiscount] = useState("");
  const [taxRate, setTaxRate] = useState("");
  const [dueDate, setDueDate] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  // What the client will be charged. The server works this out again; this is the preview.
  const totals = useMemo(() => {
    const subtotal = round(items.reduce((sum, i) => sum + round(number(i.quantity) * number(i.unit_price)), 0));
    const off = round(number(discount));
    const tax = round((subtotal - off) * (number(taxRate) / 100));
    return { subtotal, off, tax, total: round(subtotal - off + tax) };
  }, [items, discount, taxRate]);

  const brand = lead?.brand ?? pickedBrand;
  const merchant = typedMerchant ?? brand?.merchant_name ?? brand?.name ?? "";

  const update = (key: number, change: Partial<Item>) => setItems((all) => all.map((i) => (i.key === key ? { ...i, ...change } : i)));

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!leadId) {
      setError("Choose who this invoice is for.");
      return;
    }
    setSaving(true);
    setError("");
    const res = await apiRequest<Created>("/api/invoices", {
      method: "POST",
      body: {
        lead_id: Number(leadId),
        items: items.map((i) => ({ label: i.label.trim(), quantity: number(i.quantity), unit_price: number(i.unit_price) })),
        discount: number(discount) || null,
        tax_rate: number(taxRate) || null,
        due_date: dueDate || null,
        description: notes.trim() || null,
        merchant_name: merchant.trim() || null,
        currency: CURRENCY,
      },
    });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    onCreated(res.data);
  }

  return (
    <form onSubmit={onSubmit} className="space-y-5">
      {lead ? (
        <p className="text-sm text-slate-600">
          Billing <span className="font-medium text-slate-900">{lead.name}</span>
          {lead.email ? ` · ${lead.email}` : ""}
        </p>
      ) : (
        <LeadPicker
          value={leadId}
          onChange={(id, picked) => {
            setLeadId(id);
            setPickedBrand(picked?.brand ?? null);
          }}
        />
      )}

      <div>
        <label className="label" htmlFor="inv-merchant">Merchant name</label>
        <input
          id="inv-merchant"
          className="input"
          value={merchant}
          onChange={(e) => setTypedMerchant(e.target.value)}
          maxLength={120}
          placeholder={brand ? brand.name : "Choose the client first"}
        />
        <p className="mt-1.5 text-xs text-slate-500">Shown to the client as who they're paying.</p>
      </div>

      <div>
        <div className="mb-1.5 hidden grid-cols-[1fr_72px_112px_96px_32px] gap-2 text-xs font-medium text-slate-500 sm:grid">
          <span>Description</span>
          <span>Qty</span>
          <span>Unit price</span>
          <span className="text-right">Amount</span>
          <span />
        </div>
        <div className="space-y-2">
          {items.map((item, index) => (
            <div key={item.key} className="grid grid-cols-[1fr_72px_32px] items-center gap-2 sm:grid-cols-[1fr_72px_112px_96px_32px]">
              <input
                className="input col-span-3 sm:col-span-1"
                placeholder="What is this for?"
                value={item.label}
                onChange={(e) => update(item.key, { label: e.target.value })}
                aria-label={`Item ${index + 1} description`}
                maxLength={191}
                required
                autoFocus={index === 0 && Boolean(lead)}
              />
              <input
                className="input"
                type="number"
                min="0.01"
                step="any"
                value={item.quantity}
                onChange={(e) => update(item.key, { quantity: e.target.value })}
                aria-label={`Item ${index + 1} quantity`}
                required
              />
              <input
                className="input col-start-1 row-start-3 sm:col-start-auto sm:row-start-auto"
                type="number"
                min="0"
                step="0.01"
                placeholder="0.00"
                value={item.unit_price}
                onChange={(e) => update(item.key, { unit_price: e.target.value })}
                aria-label={`Item ${index + 1} unit price`}
                required
              />
              <span className="hidden text-right text-sm tabular-nums text-slate-700 sm:block">
                {formatMoney(round(number(item.quantity) * number(item.unit_price)), CURRENCY)}
              </span>
              <button
                type="button"
                className="btn-ghost p-1.5 text-slate-400 hover:text-red-600 disabled:opacity-30"
                onClick={() => setItems((all) => all.filter((i) => i.key !== item.key))}
                disabled={items.length === 1}
                aria-label={`Remove item ${index + 1}`}
              >
                <X className="h-4 w-4" />
              </button>
            </div>
          ))}
        </div>
        <button
          type="button"
          className="btn-ghost btn-sm mt-2"
          onClick={() => setItems((all) => [...all, { key: nextKey.current++, label: "", quantity: "1", unit_price: "" }])}
          disabled={items.length >= 50}
        >
          <Plus className="h-3.5 w-3.5" />
          Add item
        </button>
      </div>

      <div className="grid gap-5 border-t border-slate-100 pt-4 sm:grid-cols-2">
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label" htmlFor="inv-discount">Discount ({CURRENCY})</label>
              <input id="inv-discount" className="input" type="number" min="0" step="0.01" placeholder="0.00" value={discount} onChange={(e) => setDiscount(e.target.value)} />
            </div>
            <div>
              <label className="label" htmlFor="inv-tax">Tax (%)</label>
              <input id="inv-tax" className="input" type="number" min="0" max="100" step="0.01" placeholder="0" value={taxRate} onChange={(e) => setTaxRate(e.target.value)} />
            </div>
          </div>
          <div>
            <label className="label" htmlFor="inv-due">Due date</label>
            <input id="inv-due" className="input" type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </div>
        </div>
        <dl className="space-y-1.5 self-end text-sm">
          <Line label="Subtotal" value={formatMoney(totals.subtotal, CURRENCY)} />
          {totals.off > 0 ? <Line label="Discount" value={`-${formatMoney(totals.off, CURRENCY)}`} /> : null}
          {totals.tax > 0 ? <Line label={`Tax (${number(taxRate)}%)`} value={formatMoney(totals.tax, CURRENCY)} /> : null}
          <div className="flex justify-between gap-4 border-t border-slate-200 pt-2 text-base font-semibold text-slate-900">
            <dt>Total</dt>
            <dd className="tabular-nums">{formatMoney(totals.total, CURRENCY)}</dd>
          </div>
        </dl>
      </div>

      <div>
        <label className="label" htmlFor="inv-notes">Notes for the client</label>
        <textarea id="inv-notes" className="input" rows={2} maxLength={5000} placeholder="Optional: shown on the invoice" value={notes} onChange={(e) => setNotes(e.target.value)} />
      </div>

      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className="btn-secondary" onClick={onCancel}>
          Cancel
        </button>
        <button type="submit" className="btn-primary" disabled={saving}>
          {saving ? "Creating…" : "Create invoice"}
        </button>
      </div>
    </form>
  );
}

function Line({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4 text-slate-600">
      <dt>{label}</dt>
      <dd className="tabular-nums">{value}</dd>
    </div>
  );
}

function LeadPicker({ value, onChange }: { value: number | ""; onChange: (id: number | "", lead?: LeadOption) => void }) {
  const [q, setQ] = useState("");
  const [leads, setLeads] = useState<LeadOption[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    let cancelled = false;
    const timer = window.setTimeout(async () => {
      setLoading(true);
      const params = new URLSearchParams({ size: "25" });
      if (q.trim()) params.set("q", q.trim());
      const res = await fetch(`/api/leads?${params}`, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
      });
      const data = await res.json().catch(() => ({ leads: [] }));
      if (cancelled) return;
      setLeads(Array.isArray(data.leads) ? data.leads : []);
      setLoading(false);
    }, 250);
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [q]);

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <div>
        <label className="label" htmlFor="inv-search">Find the client</label>
        <input id="inv-search" className="input" placeholder="Name, email, company…" value={q} onChange={(e) => setQ(e.target.value)} autoFocus />
      </div>
      <div>
        <label className="label" htmlFor="inv-lead">Client *</label>
        <select id="inv-lead" className="input" value={value} onChange={(e) => {
            const id = e.target.value ? Number(e.target.value) : "";
            onChange(id, leads.find((l) => l.id === id));
          }} required>
          <option value="">{loading ? "Loading…" : "Select a lead"}</option>
          {leads.map((l) => (
            <option key={l.id} value={l.id}>
              {l.name}
              {l.email ? ` · ${l.email}` : ""}
              {l.company ? ` · ${l.company}` : ""}
            </option>
          ))}
        </select>
      </div>
    </div>
  );
}

/** Straight after creating: the link is what the rep came for */
function InvoiceReady({ invoice, onDone }: { invoice: Created; onDone: () => void }) {
  const subject = encodeURIComponent(`Invoice ${invoice.number}`);
  const body = encodeURIComponent(
    `Hi ${invoice.lead.name},\n\nHere is your invoice for ${formatMoney(invoice.amount, invoice.currency)}. You can view and pay it here:\n${invoice.pay_url}\n\nThank you.`
  );

  return (
    <div className="space-y-4">
      <div className="flex items-start gap-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
        <p>
          {formatMoney(invoice.amount, invoice.currency)} for {invoice.lead.name}, payable to {invoice.merchant_name}. Send them this link to view and pay the invoice.
        </p>
      </div>
      {!invoice.card_payments ? (
        <p className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">
          <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          Card payments aren't switched on yet, so this client can view the invoice but can't pay it by card.
        </p>
      ) : null}
      <div>
        <label className="label" htmlFor="pay-link">Payment link</label>
        <input id="pay-link" className="input font-mono text-xs" value={invoice.pay_url} readOnly onFocus={(e) => e.target.select()} />
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <CopyButton value={invoice.pay_url} label="Copy link" />
        <a className="btn-secondary btn-sm" href={invoice.pay_url} target="_blank" rel="noreferrer noopener">
          <ExternalLink className="h-3.5 w-3.5" />
          Preview
        </a>
        <a className="btn-secondary btn-sm" href={`mailto:${invoice.lead.email ?? ""}?subject=${subject}&body=${body}`}>
          <Mail className="h-3.5 w-3.5" />
          Email client
        </a>
        <button type="button" className="btn-primary btn-sm ml-auto" onClick={onDone}>
          Done
        </button>
      </div>
    </div>
  );
}
