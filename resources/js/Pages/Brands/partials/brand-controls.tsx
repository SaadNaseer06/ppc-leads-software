import { FormEvent, useState } from "react";
import { router } from "@inertiajs/react";
import { Plus } from "lucide-react";
import { Modal } from "@/components/ui/modal";
import { apiRequest, useToast } from "@/components/ui/toast";

export function AutoAssignToggle({ brandId, enabled, eligible }: { brandId: number; enabled: boolean; eligible: number }) {
  const toast = useToast();
  const [value, setValue] = useState(enabled);
  const [busy, setBusy] = useState(false);

  async function toggle() {
    const next = !value;
    setBusy(true);
    const res = await apiRequest("/api/admin/brands", { method: "PATCH", body: { brand_id: brandId, auto_assign_enabled: next } });
    setBusy(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    setValue(next);
    toast.success(
      next
        ? eligible > 0
          ? `Auto-assign on: new leads rotate across ${eligible} Front Sales rep${eligible === 1 ? "" : "s"}`
          : "Auto-assign on, but no active Front Sales reps have this brand yet"
        : "Auto-assign off: new leads wait in the unassigned queue"
    );
    router.reload();
  }

  return (
    <button
      type="button"
      role="switch"
      aria-checked={value}
      onClick={toggle}
      disabled={busy}
      className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition ${
        value ? "bg-teal-600" : "bg-slate-300"
      } ${busy ? "opacity-60" : ""}`}
    >
      <span className="sr-only">Auto-assign new leads</span>
      <span
        className={`inline-block h-5 w-5 rounded-full bg-white shadow transition ${value ? "translate-x-5" : "translate-x-0.5"}`}
      />
    </button>
  );
}

export function CreateBrandButton() {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [slug, setSlug] = useState("");
  const [slugTouched, setSlugTouched] = useState(false);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    setSaving(true);
    setError("");
    const res = await apiRequest("/api/admin/brands", {
      method: "POST",
      body: {
        name: String(form.get("name") ?? "").trim(),
        slug,
        auto_assign_enabled: form.get("autoAssignEnabled") === "on",
      },
    });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success("Brand created. Add its websites next.");
    setOpen(false);
    setSlug("");
    setSlugTouched(false);
    router.reload();
  }

  return (
    <>
      <button type="button" className="btn-primary" onClick={() => setOpen(true)}>
        <Plus className="h-4 w-4" />
        New brand
      </button>
      <Modal open={open} onClose={() => setOpen(false)} title="Create a brand" description="A brand groups websites, leads and the team that works them.">
        <form onSubmit={onSubmit} className="space-y-4">
          <div>
            <label className="label" htmlFor="b-name">Brand name *</label>
            <input
              id="b-name"
              className="input"
              name="name"
              required
              onChange={(e) => {
                if (!slugTouched) {
                  setSlug(e.target.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""));
                }
              }}
            />
          </div>
          <div>
            <label className="label" htmlFor="b-slug">Slug *</label>
            <input
              id="b-slug"
              className="input font-mono text-xs"
              value={slug}
              onChange={(e) => {
                setSlugTouched(true);
                setSlug(e.target.value);
              }}
              pattern="[a-z0-9-]+"
              required
            />
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="checkbox" name="autoAssignEnabled" />
            Auto-assign new leads round-robin to Front Sales
          </label>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={() => setOpen(false)}>
              Cancel
            </button>
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? "Saving…" : "Create brand"}
            </button>
          </div>
        </form>
      </Modal>
    </>
  );
}
