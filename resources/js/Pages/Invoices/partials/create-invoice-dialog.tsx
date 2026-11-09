import { FormEvent, useEffect, useMemo, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import { CheckCircle2, CircleAlert, ExternalLink, Mail, Plus, X } from "lucide-react";
import { formatMoney } from "@/lib/format";
import type { BrandOption } from "@/lib/api-types";
import { CopyButton } from "@/components/ui/copy-button";
import { Modal } from "@/components/ui/modal";
import { apiRequest, useToast } from "@/components/ui/toast";

type LeadBrand = { name: string; merchant_name?: string | null };
type LeadOption = { id: number; name: string; email: string | null; phone?: string | null; company: string | null; brand?: LeadBrand | null };
type Client = { name: string; email: string; phone: string; company: string; address: string };
type Item = { key: number; label: string; quantity: string; unit_price: string };
type Created = {
  id: number;
  number: string;
  amount: string;
  currency: string;
  merchant_name: string;
  pay_url: string;
  card_payments: boolean;
  mailbox: boolean;
  client: { name: string; email: string | null };
};

const CURRENCY = "USD";
const clientFrom = (lead?: LeadOption | null): Client => ({
  name: lead?.name ?? "",
  email: lead?.email ?? "",
  phone: lead?.phone ?? "",
  company: lead?.company ?? "",
  address: "",
});
const round = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;
const number = (value: string) => {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
};

/** `lead` fixes who the invoice is for (from a lead's page). `brands` are what a new client can be billed under. */
export function CreateInvoiceButton({ lead, compact, brands = [] }: { lead?: LeadOption; compact?: boolean; brands?: BrandOption[] }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button type="button" className={compact ? "btn-secondary btn-sm w-full" : "btn-primary"} onClick={() => setOpen(true)}>
        <Plus className="h-4 w-4" />
        Create invoice
      </button>
      <CreateInvoiceDialog open={open} onClose={() => setOpen(false)} lead={lead} brands={brands} />
    </>
  );
}

function CreateInvoiceDialog({ open, onClose, lead, brands }: { open: boolean; onClose: () => void; lead?: LeadOption; brands: BrandOption[] }) {
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
      {created ? <InvoiceReady invoice={created} onDone={close} /> : open ? <InvoiceForm lead={lead} brands={brands} onCancel={close} onCreated={setCreated} /> : null}
    </Modal>
  );
}

function InvoiceForm({
  lead,
  brands,
  onCancel,
  onCreated,
}: {
  lead?: LeadOption;
  brands: BrandOption[];
  onCancel: () => void;
  onCreated: (invoice: Created) => void;
}) {
  const nextKey = useRef(1);
  const [items, setItems] = useState<Item[]>([{ key: 0, label: "", quantity: "1", unit_price: "" }]);
  const [billing, setBilling] = useState<"new" | "lead">("new");
  const [picked, setPicked] = useState<LeadOption | null>(null);
  const [brandId, setBrandId] = useState<number | "">(brands.length === 1 ? brands[0].id : "");
  const [client, setClient] = useState<Client>(clientFrom(lead));
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

  // A lead brings its own brand; a new client is billed under the one chosen here
  const linked = lead ?? (billing === "lead" ? picked : null);
  const brand: LeadBrand | null = linked ? (linked.brand ?? null) : (brands.find((b) => b.id === brandId) ?? null);
  const merchant = typedMerchant ?? brand?.merchant_name ?? brand?.name ?? "";
  const setField = (field: keyof Client, value: string) => setClient((c) => ({ ...c, [field]: value }));

  const update = (key: number, change: Partial<Item>) => setItems((all) => all.map((i) => (i.key === key ? { ...i, ...change } : i)));

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (billing === "lead" && !linked) {
      setError("Choose the lead this invoice is for.");
      return;
    }
    if (!linked && !brandId) {
      setError("Choose which brand this invoice is from.");
      return;
    }
    setSaving(true);
    setError("");
    const res = await apiRequest<Created>("/api/invoices", {
      method: "POST",
      body: {
        lead_id: linked?.id ?? null,
        brand_id: linked ? null : brandId || null,
        client_name: client.name.trim() || null,
        client_email: client.email.trim() || null,
        client_phone: client.phone.trim() || null,
        client_company: client.company.trim() || null,
        client_address: client.address.trim() || null,
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
      {lead ? null : (
        <div className="inline-flex rounded-lg bg-slate-100 p-0.5" role="group" aria-label="Who is this invoice for?">
          {(
            [
              ["new", "New client"],
              ["lead", "Existing lead"],
            ] as const
          ).map(([value, label]) => (
            <button
              key={value}
              type="button"
              aria-pressed={billing === value}
              className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${billing === value ? "bg-white text-slate-900 shadow-sm" : "text-slate-600 hover:text-slate-900"}`}
              onClick={() => {
                setBilling(value);
                setClient(clientFrom(value === "lead" ? picked : null));
                setError("");
              }}
            >
              {label}
            </button>
          ))}
        </div>
      )}

      {billing === "lead" && !lead ? (
        <LeadPicker
          value={picked?.id ?? ""}
          onChange={(found) => {
            setPicked(found ?? null);
            setClient(clientFrom(found));
          }}
        />
      ) : null}

      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="inv-client">Client name *</label>
          <input id="inv-client" className="input" value={client.name} onChange={(e) => setField("name", e.target.value)} maxLength={191} required autoFocus={billing === "new" && !lead} />
        </div>
        <div>
          <label className="label" htmlFor="inv-email">Client email</label>
          <input id="inv-email" className="input" type="email" value={client.email} onChange={(e) => setField("email", e.target.value)} maxLength={191} />
        </div>
        <div>
          <label className="label" htmlFor="inv-company">Company</label>
          <input id="inv-company" className="input" value={client.company} onChange={(e) => setField("company", e.target.value)} maxLength={191} />
        </div>
        <div>
          <label className="label" htmlFor="inv-phone">Phone</label>
          <input id="inv-phone" className="input" value={client.phone} onChange={(e) => setField("phone", e.target.value)} maxLength={50} />
        </div>
        <div className="sm:col-span-2">
          <label className="label" htmlFor="inv-address">Billing address</label>
          <input id="inv-address" className="input" value={client.address} onChange={(e) => setField("address", e.target.value)} maxLength={500} />
        </div>
      </div>

      <div className="grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="inv-brand">Brand</label>
          {!linked && brands.length > 1 ? (
            <select id="inv-brand" className="input" value={brandId} onChange={(e) => setBrandId(e.target.value ? Number(e.target.value) : "")} required>
              <option value="">Select a brand</option>
              {brands.map((b) => (
                <option key={b.id} value={b.id}>{b.name}</option>
              ))}
            </select>
          ) : (
            <p id="inv-brand" className="flex h-[38px] items-center rounded-lg bg-slate-50 px-3 text-sm text-slate-700">
              {linked ? (brand?.name ?? "The lead's brand") : (brands[0]?.name ?? "No brand assigned to you yet")}
            </p>
          )}
        </div>
        <div>
          <label className="label" htmlFor="inv-merchant">Merchant name</label>
          <input
            id="inv-merchant"
            className="input"
            value={merchant}
            onChange={(e) => setTypedMerchant(e.target.value)}
            maxLength={120}
            placeholder={brand ? brand.name : "Follows the brand"}
          />
          <p className="mt-1.5 text-xs text-slate-500">Shown to the client as who they're paying.</p>
        </div>
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

function LeadPicker({ value, onChange }: { value: number | ""; onChange: (lead?: LeadOption) => void }) {
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
        <label className="label" htmlFor="inv-lead">Lead *</label>
        <select id="inv-lead" className="input" value={value} onChange={(e) => onChange(leads.find((l) => l.id === Number(e.target.value)))} required>
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
  const subject = encodeURIComponent(`Invoice ${invoice.number} from ${invoice.merchant_name}`);
  const body = encodeURIComponent(
    `Hi ${invoice.client.name},\n\nHere is your invoice for ${formatMoney(invoice.amount, invoice.currency)}. You can view and pay it here:\n${invoice.pay_url}\n\nThank you,\n${invoice.merchant_name}`
  );

  return (
    <div className="space-y-4">
      <div className="flex items-start gap-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
        <p>
          {formatMoney(invoice.amount, invoice.currency)} for {invoice.client.name}, payable to {invoice.merchant_name}. Send them this link to view and pay the invoice.
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
        {invoice.mailbox && invoice.client.email ? (
          <EmailClientButton invoiceId={invoice.id} />
        ) : invoice.client.email && !invoice.client.email.includes("•") ? (
          <a className="btn-secondary btn-sm" href={`mailto:${invoice.client.email}?subject=${subject}&body=${body}`}>
            <Mail className="h-3.5 w-3.5" />
            Email client
          </a>
        ) : null}
        <button type="button" className="btn-primary btn-sm ml-auto" onClick={onDone}>
          Done
        </button>
      </div>
    </div>
  );
}

/** Sends the pay link from the brand's mailbox: the address never has to leave the server */
export function EmailClientButton({ invoiceId, label = "Email client" }: { invoiceId: number; label?: string }) {
  const toast = useToast();
  const [state, setState] = useState<"idle" | "sending" | "sent">("idle");

  async function send() {
    setState("sending");
    const res = await apiRequest(`/api/invoices/${invoiceId}/email`, { method: "POST", body: {} });
    if (!res.ok) {
      setState("idle");
      toast.error(res.error);
      return;
    }
    setState("sent");
    toast.success("Invoice emailed to the client");
  }

  return (
    <button type="button" className="btn-secondary btn-sm" onClick={() => void send()} disabled={state !== "idle"}>
      <Mail className="h-3.5 w-3.5" />
      {state === "sending" ? "Sending…" : state === "sent" ? "Sent" : label}
    </button>
  );
}
