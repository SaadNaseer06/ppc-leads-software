import { FormEvent, useState } from "react";
import { router } from "@inertiajs/react";
import { apiRequest, useToast } from "@/components/ui/toast";

export function CreateInvoiceForm({ leadId }: { leadId: number }) {
  const toast = useToast();
  const [amount, setAmount] = useState("");
  const [description, setDescription] = useState("");
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setLoading(true);
    const res = await apiRequest<{ number: string }>("/api/invoices", {
      method: "POST",
      body: { lead_id: leadId, amount: Number(amount), currency: "USD", description: description || null },
    });
    setLoading(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    toast.success(`Invoice ${res.data.number} created`);
    setAmount("");
    setDescription("");
    router.reload();
  }

  return (
    <form onSubmit={onSubmit} className="space-y-2 border-t border-slate-100 pt-3">
      <p className="text-xs font-medium text-slate-600">New invoice (USD)</p>
      <div className="flex gap-2">
        <input
          className="input w-28"
          type="number"
          min="1"
          step="0.01"
          placeholder="Amount"
          value={amount}
          onChange={(e) => setAmount(e.target.value)}
          required
          aria-label="Invoice amount"
        />
        <input
          className="input"
          placeholder="Description"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          aria-label="Invoice description"
        />
      </div>
      <button type="submit" className="btn-secondary btn-sm w-full" disabled={loading}>
        {loading ? "Creating…" : "Create invoice"}
      </button>
    </form>
  );
}
