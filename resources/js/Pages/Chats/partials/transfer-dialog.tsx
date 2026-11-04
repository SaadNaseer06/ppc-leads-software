import { FormEvent, useEffect, useState } from "react";
import { Link } from "@inertiajs/react";
import { Send } from "lucide-react";
import type { FrontSalesRep } from "@/lib/api-types";
import { Modal } from "@/components/ui/modal";
import { apiRequest, useToast } from "@/components/ui/toast";

/** Hands a chat to a Front Sales rep; a linked lead moves with it */
export function TransferDialog({
  open,
  onClose,
  onDone,
  conversationId,
  visitorName,
  reps,
  currentRepId,
  hasLead,
  canConvert,
  canAddReps,
}: {
  open: boolean;
  onClose: () => void;
  onDone: () => void;
  conversationId: number;
  visitorName: string;
  reps: FrontSalesRep[];
  currentRepId: number | null;
  hasLead: boolean;
  canConvert: boolean;
  canAddReps: boolean;
}) {
  const toast = useToast();
  const [repId, setRepId] = useState("");
  const [note, setNote] = useState("");
  const [convert, setConvert] = useState(true);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const choices = reps.filter((r) => r.id !== currentRepId);

  useEffect(() => {
    if (!open) return;
    // The reps come least busy first, so the first one is the sensible suggestion
    setRepId(choices[0] ? String(choices[0].id) : "");
    setNote("");
    setConvert(true);
    setError("");
  }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    const res = await apiRequest(`/api/chats/${conversationId}/transfer`, {
      method: "POST",
      body: { user_id: Number(repId), note: note.trim() || null, convert: canConvert && convert },
    });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success(`Chat sent to ${choices.find((r) => String(r.id) === repId)?.name ?? "the rep"}`);
    onClose();
    onDone();
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Send chat to Front Sales"
      description={`${visitorName} and the full conversation move to their inbox, and they can reply straight away.`}
    >
      {choices.length === 0 ? (
        <div className="space-y-3 text-sm text-slate-600">
          <p>{reps.length ? "This chat is already with the only Front Sales rep on this brand." : "No active Front Sales reps work this brand yet."}</p>
          {canAddReps && reps.length === 0 ? (
            <Link href="/team" className="btn-secondary btn-sm">
              Add a rep on the Team page
            </Link>
          ) : null}
        </div>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4">
          <div>
            <label className="label" htmlFor="transfer-rep">Send to *</label>
            <select id="transfer-rep" className="input" value={repId} onChange={(e) => setRepId(e.target.value)} required>
              {choices.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name} · {r.open_leads.toLocaleString("en-US")} open leads
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="label" htmlFor="transfer-note">Note for the rep</label>
            <textarea
              id="transfer-note"
              className="input"
              rows={3}
              maxLength={500}
              placeholder="What does the visitor need? What have you told them?"
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
            <p className="hint">Internal only. The visitor never sees this.</p>
          </div>
          {canConvert ? (
            <label className="flex items-start gap-2 text-sm text-slate-700">
              <input type="checkbox" className="checkbox mt-0.5" checked={convert} onChange={(e) => setConvert(e.target.checked)} />
              <span>Also add to the Leads page, assigned to them</span>
            </label>
          ) : hasLead ? (
            <p className="text-sm text-slate-500">The lead moves to them along with the chat.</p>
          ) : null}
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={onClose}>
              Cancel
            </button>
            <button type="submit" className="btn-primary" disabled={saving || !repId}>
              <Send className="h-4 w-4" />
              {saving ? "Sending…" : "Send chat"}
            </button>
          </div>
        </form>
      )}
    </Modal>
  );
}
