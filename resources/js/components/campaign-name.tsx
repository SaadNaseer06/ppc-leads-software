import { useEffect, useRef, useState } from "react";
import { Check, Loader2, Pencil, X } from "lucide-react";
import { apiRequest, useToast } from "@/components/ui/toast";

/**
 * A campaign's name, which managers can set in place. Saving names it for the whole brand: every
 * lead from that campaign, the Campaigns page and the reports pick it up.
 */
export function CampaignName({
  brandId,
  campaign,
  name,
  label,
  canEdit,
  onSaved,
  children,
}: {
  brandId: number;
  campaign: string;
  /** The name someone gave it, if any */
  name: string | null;
  /** What shows when nobody has named it yet */
  label: string;
  canEdit: boolean;
  onSaved: (name: string | null) => void;
  /** How the name shows when not editing (e.g. a link); defaults to the label */
  children?: React.ReactNode;
}) {
  const toast = useToast();
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(name ?? "");
  const [saving, setSaving] = useState(false);
  const input = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (editing) input.current?.select();
  }, [editing]);

  async function save() {
    const next = value.trim();
    if (next === (name ?? "")) {
      setEditing(false);
      return;
    }
    setSaving(true);
    const res = await apiRequest<{ name: string | null }>("/api/campaigns", {
      method: "PUT",
      body: { brand_id: brandId, campaign, name: next || null },
    });
    setSaving(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    setEditing(false);
    toast.success(res.data.name ? `Campaign named "${res.data.name}"` : "Campaign name removed");
    onSaved(res.data.name);
  }

  if (editing) {
    return (
      <span className="inline-flex w-full max-w-[320px] items-center gap-1">
        <input
          ref={input}
          className="input h-8 min-w-0 flex-1 py-1 text-sm"
          value={value}
          maxLength={150}
          placeholder={label}
          aria-label="Campaign name"
          disabled={saving}
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              void save();
            }
            if (e.key === "Escape") {
              setValue(name ?? "");
              setEditing(false);
            }
          }}
        />
        <button type="button" className="btn-primary btn-sm h-8 px-2" onClick={() => void save()} disabled={saving} aria-label="Save name">
          {saving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}
        </button>
        <button
          type="button"
          className="btn-ghost btn-sm h-8 px-2"
          onClick={() => {
            setValue(name ?? "");
            setEditing(false);
          }}
          disabled={saving}
          aria-label="Cancel"
        >
          <X className="h-3.5 w-3.5" />
        </button>
      </span>
    );
  }

  return (
    <span className="inline-flex max-w-full items-center gap-1.5">
      <span className="min-w-0 truncate">{children ?? label}</span>
      {canEdit ? (
        <button
          type="button"
          // Always visible: phones have no hover to reveal it
          className="shrink-0 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-teal-700"
          onClick={() => {
            setValue(name ?? "");
            setEditing(true);
          }}
          title={name ? "Rename this campaign" : "Give this campaign a name"}
          aria-label={name ? "Rename campaign" : "Name campaign"}
        >
          <Pencil className="h-3.5 w-3.5" />
        </button>
      ) : null}
    </span>
  );
}
