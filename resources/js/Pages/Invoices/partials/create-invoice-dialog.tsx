import { FormEvent, useEffect, useState } from "react";
import { router } from "@inertiajs/react";
import { Plus } from "lucide-react";
import { Modal } from "@/components/ui/modal";
import { apiRequest, useToast } from "@/components/ui/toast";

type LeadOption = { id: number; name: string; email: string | null; company: string | null };

export function CreateInvoiceButton() {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button type="button" className="btn-primary" onClick={() => setOpen(true)}>
        <Plus className="h-4 w-4" />
        Create invoice
      </button>
      <CreateInvoiceDialog open={open} onClose={() => setOpen(false)} />
    </>
  );
}

function CreateInvoiceDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const [q, setQ] = useState("");
  const [leads, setLeads] = useState<LeadOption[]>([]);
  const [leadId, setLeadId] = useState<number | "">("");
  const [amount, setAmount] = useState("");
  const [description, setDescription] = useState("");
  const [loadingLeads, setLoadingLeads] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    const timer = window.setTimeout(async () => {
      setLoadingLeads(true);
      const params = new URLSearchParams({ size: "30" });
      if (q.trim()) params.set("q", q.trim());
      const res = await fetch(`/api/leads?${params}`, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
      });
      const data = await res.json().catch(() => ({ leads: [] }));
      if (cancelled) return;
      const raw = data.leads;
      const rows: unknown[] = Array.isArray(raw) ? raw : Array.isArray(raw?.data) ? raw.data : [];
      setLeads(
        rows.map((row) => {
          const l = row as { id: number; name: string; email?: string | null; company?: string | null };
          return {
            id: l.id,
            name: l.name,
            email: l.email ?? null,
            company: l.company ?? null,
          };
        })
      );
      setLoadingLeads(false);
    }, 250);
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [open, q]);

  useEffect(() => {
    if (!open) {
      setQ("");
      setLeadId("");
      setAmount("");
      setDescription("");
    }
  }, [open]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!leadId) {
      toast.error("Choose a lead");
      return;
    }
    setSaving(true);
    const res = await apiRequest<{ number: string }>("/api/invoices", {
      method: "POST",
      body: {
        lead_id: Number(leadId),
        amount: Number(amount),
        currency: "USD",
        description: description || null,
      },
    });
    setSaving(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    toast.success(`Invoice ${res.data.number} created`);
    onClose();
    router.reload();
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Create invoice"
      description="Pick a lead, enter the amount, and the invoice is saved for Finance to download."
    >
      <form onSubmit={onSubmit} className="space-y-3">
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-600">Search leads</label>
          <input
            className="input"
            placeholder="Name, email, company…"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-600">Lead</label>
          <select
            className="input"
            value={leadId}
            onChange={(e) => setLeadId(e.target.value ? Number(e.target.value) : "")}
            required
          >
            <option value="">{loadingLeads ? "Loading…" : "Select a lead"}</option>
            {leads.map((l) => (
              <option key={l.id} value={l.id}>
                {l.name}
                {l.email ? ` · ${l.email}` : ""}
                {l.company ? ` · ${l.company}` : ""}
              </option>
            ))}
          </select>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Amount (USD)</label>
            <input
              className="input"
              type="number"
              min="1"
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              required
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Description</label>
            <input
              className="input"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Optional"
            />
          </div>
        </div>
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" className="btn-secondary" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className="btn-primary" disabled={saving}>
            {saving ? "Creating…" : "Create invoice"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
