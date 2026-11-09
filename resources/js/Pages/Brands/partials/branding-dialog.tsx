import { ChangeEvent, FormEvent, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import { CheckCircle2, CircleAlert, Palette, Trash2, Upload } from "lucide-react";
import { Modal } from "@/components/ui/modal";
import { apiHeaders, apiRequest, useToast } from "@/components/ui/toast";
import type { BrandBranding, BrandsData } from "@/lib/api-types";

const DEFAULT_COLOR = "#0f766e";

/** Same rule as the invoice page: dark text on pale brand colours, white on the rest */
function textOn(hex: string) {
  const [r, g, b] = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;
  return 1.05 / (luminance + 0.05) >= (luminance + 0.05) / 0.0689 ? "#ffffff" : "#0f172a";
}

type Props = {
  brandId: number;
  brandName: string;
  branding: BrandBranding;
  billing: BrandsData["billing"];
  canEditDomain: boolean;
};

export function BrandingButton(props: Props) {
  const [open, setOpen] = useState(false);
  const color = props.branding.brand_color ?? DEFAULT_COLOR;

  return (
    <>
      <button type="button" className="btn-secondary btn-sm whitespace-nowrap" onClick={() => setOpen(true)}>
        <span className="h-3 w-3 rounded-full" style={{ background: color }} aria-hidden />
        Customize
      </button>
      <Modal
        open={open}
        onClose={() => setOpen(false)}
        wide
        title={`Invoice branding: ${props.brandName}`}
        description="How this brand appears to clients on invoices, payment pages and PDFs."
      >
        {open ? <BrandingForm {...props} onClose={() => setOpen(false)} /> : null}
      </Modal>
    </>
  );
}

function BrandingForm({ brandId, brandName, branding, billing, canEditDomain, onClose }: Props & { onClose: () => void }) {
  const toast = useToast();
  const fileInput = useRef<HTMLInputElement>(null);
  const [merchant, setMerchant] = useState(branding.merchant_name ?? "");
  const [color, setColor] = useState(branding.brand_color ?? DEFAULT_COLOR);
  const [email, setEmail] = useState(branding.billing_email ?? "");
  const [address, setAddress] = useState(branding.billing_address ?? "");
  const [domain, setDomain] = useState(branding.pay_domain ?? "");
  const [logo, setLogo] = useState(branding.logo_url);
  const [favicon, setFavicon] = useState(branding.favicon_url);
  const faviconInput = useRef<HTMLInputElement>(null);
  const [connected, setConnected] = useState(branding.domain_connected);
  const [savedDomain, setSavedDomain] = useState(branding.pay_domain ?? "");
  const [error, setError] = useState("");
  const [domainError, setDomainError] = useState("");
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [checking, setChecking] = useState(false);

  const validColor = /^#[0-9a-fA-F]{6}$/.test(color);
  const previewColor = validColor ? color : DEFAULT_COLOR;

  function save() {
    return apiRequest<{ branding: BrandBranding }>(`/api/admin/brands/${brandId}/branding`, {
      method: "PATCH",
      body: {
        merchant_name: merchant.trim() || null,
        brand_color: color === DEFAULT_COLOR && !branding.brand_color ? null : color,
        billing_email: email.trim() || null,
        billing_address: address.trim() || null,
        pay_domain: domain.trim() || null,
      },
    });
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    const res = await save();
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success("Invoice branding saved");
    router.reload();
    onClose();
  }

  // Logo and favicon upload the same way, each to its own address
  async function upload(kind: "logo" | "favicon", e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setUploading(true);
    setError("");
    const body = new FormData();
    body.append(kind, file);
    const res = await fetch(`/api/admin/brands/${brandId}/${kind}`, { method: "POST", headers: apiHeaders(false), credentials: "same-origin", body });
    const data = await res.json().catch(() => ({}));
    setUploading(false);
    if (!res.ok) {
      setError(data.error ?? `The ${kind} couldn't be uploaded.`);
      return;
    }
    if (kind === "logo") setLogo(data.branding.logo_url);
    else setFavicon(data.branding.favicon_url);
    router.reload();
  }

  async function remove(kind: "logo" | "favicon") {
    const res = await apiRequest(`/api/admin/brands/${brandId}/${kind}`, { method: "DELETE" });
    if (!res.ok) {
      setError(res.error);
      return;
    }
    if (kind === "logo") setLogo(null);
    else setFavicon(null);
    router.reload();
  }

  async function checkDomain() {
    setChecking(true);
    setDomainError("");
    // Checks what's typed, so a new domain is saved first
    const saved = await save();
    if (!saved.ok) {
      setChecking(false);
      setDomainError(saved.error);
      return;
    }
    setSavedDomain(saved.data.branding.pay_domain ?? "");
    setDomain(saved.data.branding.pay_domain ?? "");
    const res = await fetch(`/api/admin/brands/${brandId}/domain-check`, { method: "POST", headers: apiHeaders(false), credentials: "same-origin" });
    const data = await res.json().catch(() => ({}));
    setChecking(false);
    setConnected(res.ok);
    if (!res.ok) setDomainError(data.error ?? "The domain couldn't be checked.");
    router.reload();
  }

  const domainChanged = domain.trim().toLowerCase() !== savedDomain;

  return (
    <form onSubmit={onSubmit} className="space-y-5">
      <div className="overflow-hidden rounded-xl border border-slate-200">
        <div className="flex items-center gap-3 px-4 py-3" style={{ background: previewColor, color: textOn(previewColor) }}>
          {logo ? (
            <span className="flex h-9 max-w-[110px] items-center rounded-md bg-white px-1.5 py-1">
              <img src={logo} alt="" className="max-h-full max-w-full" />
            </span>
          ) : null}
          <span className="truncate text-sm font-semibold">{merchant.trim() || brandName}</span>
          <span className="ml-auto shrink-0 text-xs opacity-90">Preview</span>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <div>
          <span className="label">Logo</span>
          <div className="flex flex-wrap items-center gap-2">
            <button type="button" className="btn-secondary btn-sm" onClick={() => fileInput.current?.click()} disabled={uploading}>
              <Upload className="h-3.5 w-3.5" />
              {uploading ? "Uploading…" : logo ? "Replace logo" : "Upload logo"}
            </button>
            {logo ? (
              <button type="button" className="btn-ghost btn-sm text-red-600" onClick={() => void remove("logo")}>
                <Trash2 className="h-3.5 w-3.5" />
                Remove
              </button>
            ) : null}
            <input ref={fileInput} type="file" accept="image/png,image/jpeg" className="hidden" aria-label="Logo file" onChange={(e) => void upload("logo", e)} />
          </div>
          <p className="mt-1.5 text-xs text-slate-500">PNG or JPG, under 1 MB.</p>
        </div>
        <div>
          <span className="label">Favicon</span>
          <div className="flex flex-wrap items-center gap-2">
            {favicon ? <img src={favicon} alt="" className="h-6 w-6 rounded border border-slate-200 object-contain" /> : null}
            <button type="button" className="btn-secondary btn-sm" onClick={() => faviconInput.current?.click()} disabled={uploading}>
              <Upload className="h-3.5 w-3.5" />
              {favicon ? "Replace" : "Upload favicon"}
            </button>
            {favicon ? (
              <button type="button" className="btn-ghost btn-sm text-red-600" onClick={() => void remove("favicon")} aria-label="Remove favicon">
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            ) : null}
            <input ref={faviconInput} type="file" accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico" className="hidden" aria-label="Favicon file" onChange={(e) => void upload("favicon", e)} />
          </div>
          <p className="mt-1.5 text-xs text-slate-500">The tab icon on invoices. Square PNG or ICO. Uses the logo if empty.</p>
        </div>
        <div>
          <label className="label" htmlFor="brand-color">Brand colour</label>
          <div className="flex items-center gap-2">
            <input
              type="color"
              className="h-[38px] w-12 cursor-pointer rounded-lg border border-slate-300 bg-white p-1"
              value={previewColor}
              onChange={(e) => setColor(e.target.value)}
              aria-label="Pick a brand colour"
            />
            <input id="brand-color" className="input font-mono" value={color} onChange={(e) => setColor(e.target.value)} maxLength={7} placeholder="#0f766e" />
          </div>
          {!validColor ? <p className="mt-1.5 text-xs text-red-600">Use a colour code like #0f766e, or pick one from the swatch.</p> : null}
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="brand-merchant">Default merchant name</label>
          <input id="brand-merchant" className="input" value={merchant} onChange={(e) => setMerchant(e.target.value)} maxLength={120} placeholder={brandName} />
          <p className="mt-1.5 text-xs text-slate-500">Who clients are paying. Can be changed on each invoice.</p>
        </div>
        <div>
          <label className="label" htmlFor="brand-email">Billing email</label>
          <input id="brand-email" className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={191} placeholder="billing@yourbrand.com" />
        </div>
      </div>

      <div>
        <label className="label" htmlFor="brand-address">Business address</label>
        <textarea id="brand-address" className="input" rows={2} value={address} onChange={(e) => setAddress(e.target.value)} maxLength={500} placeholder="Shown under the merchant name on invoices" />
      </div>

      <div className="space-y-2 border-t border-slate-100 pt-4">
        <label className="label" htmlFor="brand-domain">Invoice domain</label>
        <div className="flex flex-wrap items-center gap-2">
          <input
            id="brand-domain"
            className="input min-w-[220px] flex-1"
            value={domain}
            onChange={(e) => {
              setDomain(e.target.value);
              setDomainError("");
            }}
            placeholder="pay.yourbrand.com"
            maxLength={191}
            disabled={!canEditDomain}
          />
          {canEditDomain && domain.trim() ? (
            <button type="button" className="btn-secondary" onClick={() => void checkDomain()} disabled={checking}>
              {checking ? "Checking…" : "Check connection"}
            </button>
          ) : null}
        </div>
        {domain.trim() && connected && !domainChanged ? (
          <p className="flex items-center gap-1.5 text-xs font-medium text-emerald-700">
            <CheckCircle2 className="h-3.5 w-3.5" />
            Connected. New invoice links use this domain.
          </p>
        ) : domain.trim() ? (
          <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-600">
            <p>
              To connect it, add a <strong>CNAME</strong> record for this domain pointing to <span className="font-mono">{billing.dns_target}</span>, add the domain in cPanel,
              then press Check connection. Links keep using the current address until then, so nothing breaks.
            </p>
          </div>
        ) : (
          <p className="text-xs text-slate-500">Optional. Lets clients see the brand's own address instead of this app's.</p>
        )}
        {domainError ? <p className="text-xs text-red-600">{domainError}</p> : null}
      </div>

      {!branding.card_payments ? (
        <p className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">
          <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          Card payments aren't on for this brand yet. A Super Admin can add its Stripe keys on the Payments page.
        </p>
      ) : null}

      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button type="submit" className="btn-primary" disabled={saving || !validColor}>
          <Palette className="h-4 w-4" />
          {saving ? "Saving…" : "Save branding"}
        </button>
      </div>
    </form>
  );
}
