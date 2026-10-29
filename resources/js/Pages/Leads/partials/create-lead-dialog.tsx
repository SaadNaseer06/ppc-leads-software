import { FormEvent, useState } from "react";
import { router } from "@inertiajs/react";
import { Plus } from "lucide-react";
import { Modal } from "@/components/ui/modal";
import { apiRequest, useToast } from "@/components/ui/toast";
import type { Assignee, Ref } from "@/lib/api-types";

export function CreateLeadButton({
  websites,
  assignees,
  canAssign,
}: {
  websites: Ref[];
  assignees: Assignee[];
  canAssign: boolean;
}) {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    const value = (key: string) => String(form.get(key) ?? "").trim() || null;
    setSaving(true);
    setError("");
    const res = await apiRequest<{ lead_id: number }>("/api/leads", {
      method: "POST",
      body: {
        name: value("name"),
        email: value("email"),
        phone: value("phone"),
        company: value("company"),
        message: value("message"),
        website_id: Number(value("websiteId")) || null,
        assignee_id: Number(value("assigneeId")) || null,
      },
    });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    setOpen(false);
    toast.success("Lead created");
    router.visit(`/leads/${res.data.lead_id}`);
  }

  return (
    <>
      <button type="button" className="btn-primary" onClick={() => setOpen(true)} disabled={websites.length === 0}>
        <Plus className="h-4 w-4" />
        New lead
      </button>
      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Add a lead"
        description="For phone calls, walk-ins and other leads that didn't come through a website."
      >
        <form onSubmit={onSubmit} className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <label className="label" htmlFor="lead-name">Full name *</label>
            <input id="lead-name" className="input" name="name" required autoFocus />
          </div>
          <div>
            <label className="label" htmlFor="lead-email">Email</label>
            <input id="lead-email" className="input" name="email" type="email" />
          </div>
          <div>
            <label className="label" htmlFor="lead-phone">Phone</label>
            <input id="lead-phone" className="input" name="phone" type="tel" />
          </div>
          <div>
            <label className="label" htmlFor="lead-company">Company</label>
            <input id="lead-company" className="input" name="company" />
          </div>
          <div>
            <label className="label" htmlFor="lead-website">Website *</label>
            <select id="lead-website" className="input" name="websiteId" required defaultValue="">
              <option value="" disabled>Choose a website</option>
              {websites.map((w) => (
                <option key={w.id} value={w.id}>{w.name}</option>
              ))}
            </select>
          </div>
          {canAssign ? (
            <div className="sm:col-span-2">
              <label className="label" htmlFor="lead-assignee">Assign to</label>
              <select id="lead-assignee" className="input" name="assigneeId" defaultValue="">
                <option value="">Leave unassigned (or auto-assign if enabled)</option>
                {assignees.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name} · {a.role_label} · {a.open_leads.toLocaleString("en-US")} open
                  </option>
                ))}
              </select>
            </div>
          ) : null}
          <div className="sm:col-span-2">
            <label className="label" htmlFor="lead-message">Notes from the enquiry</label>
            <textarea id="lead-message" className="input" name="message" rows={3} />
          </div>
          {error ? <p className="text-sm text-red-600 sm:col-span-2">{error}</p> : null}
          <div className="flex justify-end gap-2 sm:col-span-2">
            <button type="button" className="btn-secondary" onClick={() => setOpen(false)}>
              Cancel
            </button>
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? "Saving…" : "Create lead"}
            </button>
          </div>
        </form>
      </Modal>
    </>
  );
}
