import { FormEvent, useState } from "react";
import { router } from "@inertiajs/react";
import { Pencil, Trash2 } from "lucide-react";
import { LeadStatus, STATUS_LABELS } from "@/lib/roles";
import type { Assignee } from "@/lib/api-types";
import { useConfirm } from "@/components/ui/confirm";
import { Modal } from "@/components/ui/modal";
import { apiRequest, useToast } from "@/components/ui/toast";
import { useLiveSync } from "@/components/app-shell/live-provider";

export function LeadActions({
  leadId,
  leadName,
  status,
  assigneeId,
  assignees,
  canAssign,
  canDelete,
}: {
  leadId: number;
  leadName?: string;
  status: string;
  assigneeId: number | null;
  assignees: Assignee[];
  canAssign: boolean;
  canDelete?: boolean;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const syncLive = useLiveSync();
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [convertOpen, setConvertOpen] = useState(false);
  const [handoffUserId, setHandoffUserId] = useState("");
  const upSales = assignees.filter((a) => a.role === "UP_SALES");
  // An Up Sales rep converting their own lead keeps it
  const handoffKeepsMe = upSales.some((u) => u.id === assigneeId) && (!handoffUserId || Number(handoffUserId) === assigneeId);

  async function patch(body: Record<string, unknown>, message: string, reload = true) {
    setSaving(true);
    const res = await apiRequest(`/api/leads/${leadId}`, { method: "PATCH", body });
    setSaving(false);
    if (!res.ok) {
      toast.error(res.error);
      return false;
    }
    toast.success(message);
    syncLive();
    if (reload) router.reload();
    return true;
  }

  function onStatus(next: string) {
    if (next === LeadStatus.converted && upSales.length > 0) {
      setConvertOpen(true);
      return;
    }
    void patch({ status: next }, `Status changed to ${STATUS_LABELS[next as LeadStatus]}`);
  }

  return (
    <div className="flex flex-wrap items-end gap-3">
      <div>
        <label className="label" htmlFor="lead-status">Status</label>
        <select
          id="lead-status"
          className="input w-auto min-w-[150px]"
          value={status}
          disabled={saving}
          onChange={(e) => onStatus(e.target.value)}
        >
          {Object.values(LeadStatus).map((s) => (
            <option key={s} value={s}>{STATUS_LABELS[s]}</option>
          ))}
        </select>
      </div>
      {canAssign ? (
        <div>
          <label className="label" htmlFor="lead-assignee">Assigned to</label>
          <select
            id="lead-assignee"
            className="input w-auto min-w-[220px]"
            value={assigneeId ?? ""}
            disabled={saving}
            onChange={(e) => {
              const next = e.target.value ? Number(e.target.value) : null;
              const name = assignees.find((a) => a.id === next)?.name;
              void patch({ assignee_id: next }, next ? `Assigned to ${name}` : "Lead unassigned");
            }}
          >
            <option value="">Unassigned</option>
            {assigneeId && !assignees.some((a) => a.id === assigneeId) ? (
              <option value={assigneeId}>Current assignee (no longer eligible)</option>
            ) : null}
            {assignees.map((a) => (
              <option key={a.id} value={a.id}>
                {a.name} · {a.role_label} · {a.open_leads.toLocaleString("en-US")} open
              </option>
            ))}
          </select>
        </div>
      ) : null}

      {canDelete ? (
        <button
          type="button"
          className="btn-ghost text-red-600 hover:text-red-700"
          disabled={deleting || saving}
          onClick={async () => {
            const ok = await confirm({
              title: `Delete lead “${leadName ?? "this lead"}”?`,
              description: "This cannot be undone.",
              confirmLabel: "Delete lead",
              tone: "danger",
            });
            if (!ok) return;
            setDeleting(true);
            const res = await apiRequest(`/api/leads/${leadId}`, { method: "DELETE" });
            setDeleting(false);
            if (!res.ok) {
              toast.error(res.error);
              return;
            }
            toast.success("Lead deleted");
            syncLive();
            router.visit("/leads");
          }}
        >
          <Trash2 className="h-4 w-4" />
          {deleting ? "Deleting…" : "Delete lead"}
        </button>
      ) : null}

      <Modal
        open={convertOpen}
        onClose={() => setConvertOpen(false)}
        title="Mark as converted"
        description="Converted leads are handed to an Up Sales rep for onboarding and upsell."
      >
        <form
          className="space-y-4"
          onSubmit={async (e) => {
            e.preventDefault();
            // Sales reps lose access once the lead moves to Up Sales, so they go back to their list
            const leaving = !canAssign && !handoffKeepsMe;
            const ok = await patch(
              { status: LeadStatus.converted, handoff_user_id: handoffUserId ? Number(handoffUserId) : null },
              "Lead converted and handed off",
              !leaving
            );
            if (ok) setConvertOpen(false);
            if (ok && leaving) router.visit("/leads");
          }}
        >
          <div>
            <label className="label" htmlFor="handoff">Hand off to</label>
            <select id="handoff" className="input" value={handoffUserId} onChange={(e) => setHandoffUserId(e.target.value)}>
              <option value="">Next Up Sales rep in rotation</option>
              {upSales.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.name} · {u.open_leads.toLocaleString("en-US")} open
                </option>
              ))}
            </select>
          </div>
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={() => setConvertOpen(false)}>
              Cancel
            </button>
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? "Saving…" : "Convert lead"}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
}

export function EditContactButton({
  leadId,
  initial,
}: {
  leadId: number;
  initial: { name: string; email: string; phone: string; company: string };
}) {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    const body = Object.fromEntries(
      ["name", "email", "phone", "company"].map((k) => [k, String(form.get(k) ?? "").trim()])
    );
    setSaving(true);
    setError("");
    const res = await apiRequest(`/api/leads/${leadId}`, { method: "PATCH", body });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    setOpen(false);
    toast.success("Contact details saved");
    router.reload();
  }

  return (
    <>
      <button type="button" className="btn-ghost btn-sm" onClick={() => setOpen(true)}>
        <Pencil className="h-3.5 w-3.5" />
        Edit
      </button>
      <Modal open={open} onClose={() => setOpen(false)} title="Edit contact details">
        <form onSubmit={onSubmit} className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <label className="label" htmlFor="c-name">Full name</label>
            <input id="c-name" className="input" name="name" defaultValue={initial.name} required />
          </div>
          <div>
            <label className="label" htmlFor="c-email">Email</label>
            <input id="c-email" className="input" name="email" type="email" defaultValue={initial.email} />
          </div>
          <div>
            <label className="label" htmlFor="c-phone">Phone</label>
            <input id="c-phone" className="input" name="phone" defaultValue={initial.phone} />
          </div>
          <div className="sm:col-span-2">
            <label className="label" htmlFor="c-company">Company</label>
            <input id="c-company" className="input" name="company" defaultValue={initial.company} />
          </div>
          {error ? <p className="text-sm text-red-600 sm:col-span-2">{error}</p> : null}
          <div className="flex justify-end gap-2 sm:col-span-2">
            <button type="button" className="btn-secondary" onClick={() => setOpen(false)}>
              Cancel
            </button>
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? "Saving…" : "Save"}
            </button>
          </div>
        </form>
      </Modal>
    </>
  );
}
