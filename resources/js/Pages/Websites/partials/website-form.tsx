import { FormEvent, useState } from "react";
import { router } from "@inertiajs/react";
import { Plus } from "lucide-react";
import { Modal } from "@/components/ui/modal";
import { apiRequest, useToast } from "@/components/ui/toast";

type Brand = { id: number; name: string };

export type WebsiteFormValues = {
  id?: number;
  name: string;
  domain: string;
  brandId: number;
  tawkPropertyId: string;
  hasTawkSecret: boolean;
  active: boolean;
};

export function WebsiteForm({
  brands,
  initial,
  onDone,
  stacked,
}: {
  brands: Brand[];
  initial?: WebsiteFormValues;
  onDone?: (id: number) => void;
  /** Single column, for narrow containers such as a sidebar card */
  stacked?: boolean;
}) {
  const toast = useToast();
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const editing = Boolean(initial?.id);
  const columns = stacked ? "" : "sm:grid-cols-2";

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    const text = (key: string) => String(form.get(key) ?? "").trim();
    setSaving(true);
    setError("");
    const res = await apiRequest<{ id?: number }>(editing ? `/api/websites/${initial!.id}` : "/api/websites", {
      method: editing ? "PATCH" : "POST",
      body: {
        name: text("name"),
        domain: text("domain"),
        brand_id: Number(text("brandId")) || null,
        tawk_property_id: text("tawkPropertyId") || null,
        tawk_webhook_secret: text("tawkWebhookSecret") || null,
        active: form.get("active") === "on",
      },
    });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success(editing ? "Website settings saved" : "Website added");
    router.reload();
    onDone?.(res.data.id ?? initial!.id!);
  }

  return (
    <form onSubmit={onSubmit} className={`grid gap-4 ${columns}`}>
      <div>
        <label className="label" htmlFor="w-name">Website name *</label>
        <input id="w-name" className="input" name="name" defaultValue={initial?.name} placeholder="Acme Roofing — main site" required />
      </div>
      <div>
        <label className="label" htmlFor="w-domain">Domain *</label>
        <input id="w-domain" className="input" name="domain" defaultValue={initial?.domain} placeholder="acmeroofing.com" required />
      </div>
      <div className="sm:col-span-2">
        <label className="label" htmlFor="w-brand">Brand *</label>
        <select id="w-brand" className="input" name="brandId" defaultValue={initial?.brandId ?? ""} required>
          <option value="" disabled>Choose a brand</option>
          {brands.map((b) => (
            <option key={b.id} value={b.id}>{b.name}</option>
          ))}
        </select>
        <p className="hint">Leads from this website are visible to everyone with access to this brand.</p>
      </div>

      <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-4 sm:col-span-2">
        <p className="text-sm font-medium text-slate-800">Tawk.to (optional)</p>
        <p className="mt-0.5 text-xs text-slate-500">
          Link the Tawk.to property used on this website so chats and offline messages become leads.
        </p>
        <div className={`mt-3 grid gap-4 ${columns}`}>
          <div>
            <label className="label" htmlFor="w-tawk">Property ID</label>
            <input
              id="w-tawk"
              className="input font-mono text-xs"
              name="tawkPropertyId"
              defaultValue={initial?.tawkPropertyId}
              placeholder="e.g. 64f1c2a9b1d3e2001a7f0c11"
            />
          </div>
          <div>
            <label className="label" htmlFor="w-secret">Webhook secret key</label>
            <input
              id="w-secret"
              className="input font-mono text-xs"
              name="tawkWebhookSecret"
              type="password"
              autoComplete="off"
              placeholder={initial?.hasTawkSecret ? "•••••••• (saved — leave blank to keep)" : "Paste from Tawk.to"}
            />
          </div>
        </div>
      </div>

      <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
        <input type="checkbox" className="checkbox" name="active" defaultChecked={initial?.active ?? true} />
        Accept new leads from this website
      </label>

      {error ? <p className="text-sm text-red-600 sm:col-span-2">{error}</p> : null}
      <div className="flex justify-end gap-2 sm:col-span-2">
        <button type="submit" className="btn-primary" disabled={saving}>
          {saving ? "Saving…" : editing ? "Save changes" : "Add website"}
        </button>
      </div>
    </form>
  );
}

export function AddWebsiteButton({ brands }: { brands: Brand[] }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button type="button" className="btn-primary" onClick={() => setOpen(true)} disabled={brands.length === 0}>
        <Plus className="h-4 w-4" />
        Add website
      </button>
      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Add a website"
        description="You'll get an embed snippet and webhook URL to start capturing leads."
        wide
      >
        <WebsiteForm
          brands={brands}
          onDone={(id) => {
            setOpen(false);
            router.visit(`/websites/${id}`);
          }}
        />
      </Modal>
    </>
  );
}
