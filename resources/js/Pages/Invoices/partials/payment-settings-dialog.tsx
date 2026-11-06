import { FormEvent, useState } from "react";
import { router } from "@inertiajs/react";
import { CheckCircle2, Unplug } from "lucide-react";
import type { BrandPayments } from "@/lib/api-types";
import { useConfirm } from "@/components/ui/confirm";
import { CopyButton } from "@/components/ui/copy-button";
import { Modal } from "@/components/ui/modal";
import { apiRequest, useToast } from "@/components/ui/toast";

export function PaymentSettingsDialog({ open, onClose, brands }: { open: boolean; onClose: () => void; brands: BrandPayments[] }) {
  // Start on the first brand that still needs setting up
  const [brandId, setBrandId] = useState<number | null>(null);
  const selected = brands.find((b) => b.id === brandId) ?? brands.find((b) => !b.card_payments) ?? brands[0];

  return (
    <Modal open={open} onClose={onClose} wide title="Payment settings" description="Who clients pay, and the Stripe account that receives the money.">
      {open && selected ? (
        <div className="space-y-4">
          {brands.length > 1 ? (
            <div>
              <label className="label" htmlFor="pay-brand">Brand</label>
              <select id="pay-brand" className="input" value={selected.id} onChange={(e) => setBrandId(Number(e.target.value))}>
                {brands.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                    {b.card_payments ? " (connected)" : ""}
                  </option>
                ))}
              </select>
            </div>
          ) : null}
          <AccountForm key={selected.id} brand={selected} onClose={onClose} />
        </div>
      ) : null}
    </Modal>
  );
}

function AccountForm({ brand, onClose }: { brand: BrandPayments; onClose: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const own = brand.source === "own";
  const [merchant, setMerchant] = useState(brand.merchant_name ?? "");
  const [publishable, setPublishable] = useState(own ? (brand.publishable_key ?? "") : "");
  const [secret, setSecret] = useState("");
  const [webhook, setWebhook] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    const res = await apiRequest<{ brand: BrandPayments }>(`/api/admin/brands/${brand.id}/payments`, {
      method: "PATCH",
      body: {
        merchant_name: merchant.trim() || null,
        publishable_key: publishable.trim(),
        secret_key: secret.trim() || null,
        webhook_secret: webhook.trim() || null,
      },
    });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success(`Card payments are on for ${brand.name}`);
    onClose();
    router.reload();
  }

  async function disconnect() {
    const ok = await confirm({
      title: `Disconnect Stripe from ${brand.name}?`,
      description: "Its clients won't be able to pay by card until keys are added again. Payments already taken aren't affected.",
      confirmLabel: "Disconnect",
      tone: "danger",
    });
    if (!ok) return;
    const res = await apiRequest(`/api/admin/brands/${brand.id}/payments`, { method: "DELETE" });
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success("Stripe disconnected");
    onClose();
    router.reload();
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" autoComplete="off">
      {own && brand.card_payments ? (
        <p className="flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
          <CheckCircle2 className="h-4 w-4 shrink-0" />
          <span>
            Connected{brand.account_name ? ` to ${brand.account_name}` : ""} in {brand.mode === "live" ? "live mode: real cards are charged" : "test mode: no real money is taken"}.
          </span>
        </p>
      ) : brand.source === "env" ? (
        <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">Using the keys in the server's .env. Add keys here to give this brand its own Stripe account.</p>
      ) : null}

      <div>
        <label className="label" htmlFor="pay-merchant">Merchant name</label>
        <input id="pay-merchant" className="input" value={merchant} onChange={(e) => setMerchant(e.target.value)} maxLength={120} placeholder={brand.name} />
        <p className="mt-1.5 text-xs text-slate-500">Who clients see they're paying. Can still be changed on each invoice.</p>
      </div>

      <div>
        <label className="label" htmlFor="pay-publishable">Publishable key *</label>
        <input id="pay-publishable" className="input font-mono text-xs" value={publishable} onChange={(e) => setPublishable(e.target.value)} placeholder="pk_live_… or pk_test_…" spellCheck={false} required />
      </div>

      <div>
        <label className="label" htmlFor="pay-secret">Secret key {own ? "" : "*"}</label>
        <input
          id="pay-secret"
          className="input font-mono text-xs"
          type="password"
          autoComplete="new-password"
          value={secret}
          onChange={(e) => setSecret(e.target.value)}
          placeholder={own && brand.secret_hint ? `Saved (${brand.secret_hint}). Leave blank to keep it.` : "sk_live_… or sk_test_…"}
          required={!own}
        />
        <p className="mt-1.5 text-xs text-slate-500">
          Both keys are under{" "}
          <a className="font-medium text-teal-700 hover:underline" href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noreferrer noopener">
            Developers → API keys
          </a>{" "}
          in Stripe. The secret key is stored encrypted and can't be read back.
        </p>
      </div>

      <div className="space-y-2 border-t border-slate-100 pt-4">
        <label className="label" htmlFor="pay-webhook">Webhook signing secret (recommended)</label>
        <p className="text-xs leading-relaxed text-slate-500">
          Records a payment even if the client closes the page straight after paying. In Stripe, go to <strong>Developers → Webhooks</strong>, add an endpoint with the
          address below, select <span className="font-mono">payment_intent.succeeded</span>, then paste its signing secret here.
        </p>
        <div className="flex items-center gap-2">
          <input className="input font-mono text-xs" value={brand.webhook_url ?? ""} readOnly aria-label="Webhook address" onFocus={(e) => e.target.select()} />
          <CopyButton value={brand.webhook_url ?? ""} label="Copy" />
        </div>
        <input
          id="pay-webhook"
          className="input font-mono text-xs"
          type="password"
          autoComplete="new-password"
          value={webhook}
          onChange={(e) => setWebhook(e.target.value)}
          placeholder={brand.webhook && own ? "Saved. Leave blank to keep it." : "whsec_…"}
        />
      </div>

      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      <div className="flex flex-wrap items-center gap-2">
        {own ? (
          <button type="button" className="btn-ghost btn-sm text-red-600" onClick={() => void disconnect()}>
            <Unplug className="h-3.5 w-3.5" />
            Disconnect
          </button>
        ) : null}
        <div className="ml-auto flex gap-2">
          <button type="button" className="btn-secondary" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className="btn-primary" disabled={saving}>
            {saving ? "Checking with Stripe…" : "Save and connect"}
          </button>
        </div>
      </div>
    </form>
  );
}
