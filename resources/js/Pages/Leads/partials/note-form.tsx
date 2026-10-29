import { FormEvent, useState } from "react";
import { router } from "@inertiajs/react";
import { apiRequest, useToast } from "@/components/ui/toast";

export function NoteForm({ leadId }: { leadId: number }) {
  const toast = useToast();
  const [body, setBody] = useState("");
  const [saving, setSaving] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!body.trim()) return;
    setSaving(true);
    const res = await apiRequest(`/api/leads/${leadId}/notes`, { method: "POST", body: { body } });
    setSaving(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    setBody("");
    router.reload();
  }

  return (
    <form onSubmit={onSubmit} className="space-y-2">
      <textarea
        className="input"
        rows={3}
        placeholder="Log a call, follow-up or anything the team should know…"
        value={body}
        onChange={(e) => setBody(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) void onSubmit(e);
        }}
        aria-label="Add a note"
      />
      <div className="flex items-center justify-between">
        <p className="text-xs text-slate-400">Ctrl + Enter to save</p>
        <button type="submit" className="btn-primary btn-sm" disabled={saving || !body.trim()}>
          {saving ? "Saving…" : "Add note"}
        </button>
      </div>
    </form>
  );
}
