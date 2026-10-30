import { FormEvent, useState } from "react";
import { router } from "@inertiajs/react";
import { Pencil, UserPlus } from "lucide-react";
import { useConfirm } from "@/components/ui/confirm";
import { Modal } from "@/components/ui/modal";
import { apiRequest, useToast } from "@/components/ui/toast";

type Option = { id: string | number; name: string };

export type UserFormValues = {
  id: number;
  name: string;
  email: string;
  role: string;
  brandIds: number[];
  active: boolean;
};

function UserForm({
  brands,
  roles,
  initial,
  isSelf,
  onDone,
}: {
  brands: Option[];
  roles: Option[];
  initial?: UserFormValues;
  isSelf?: boolean;
  onDone: () => void;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const editing = Boolean(initial);

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    const password = String(form.get("password") ?? "");
    const body: Record<string, unknown> = {
      name: String(form.get("name") ?? "").trim(),
      ...(isSelf
        ? {}
        : {
            brand_ids: form.getAll("brandIds").map(Number),
            role: String(form.get("role")),
            active: editing ? form.get("active") === "on" : undefined,
          }),
      ...(password ? { password } : {}),
    };
    if (!editing) body.email = String(form.get("email") ?? "").trim();

    if (editing && initial!.active && body.active === false) {
      const ok = await confirm({
        title: `Deactivate ${initial!.name}?`,
        description: "They will be signed out and their open leads will move back to the unassigned queue.",
        confirmLabel: "Deactivate",
        tone: "danger",
      });
      if (!ok) return;
    }

    setSaving(true);
    setError("");
    const res = await apiRequest<{ released_leads?: number }>(
      editing ? `/api/admin/users/${initial!.id}` : "/api/admin/users",
      { method: editing ? "PATCH" : "POST", body }
    );
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    const released = res.data.released_leads ?? 0;
    toast.success(
      editing
        ? released
          ? `Saved. ${released} open lead${released === 1 ? "" : "s"} moved to the unassigned queue.`
          : "User updated"
        : "User added. Share their email and password with them."
    );
    router.reload();
    onDone();
  }

  return (
    <form onSubmit={onSubmit} className="grid gap-4 sm:grid-cols-2">
      <div>
        <label className="label" htmlFor="u-name">Full name *</label>
        <input id="u-name" className="input" name="name" defaultValue={initial?.name} required />
      </div>
      <div>
        <label className="label" htmlFor="u-email">Email *</label>
        <input
          id="u-email"
          className="input"
          name="email"
          type="email"
          defaultValue={initial?.email}
          disabled={editing}
          required={!editing}
        />
      </div>
      <div>
        <label className="label" htmlFor="u-role">Role *</label>
        <select id="u-role" className="input" name="role" defaultValue={initial?.role ?? "FRONT_SALES"} disabled={isSelf}>
          {roles.map((r) => (
            <option key={r.id} value={r.id}>{r.name}</option>
          ))}
        </select>
      </div>
      <div>
        <label className="label" htmlFor="u-password">{editing ? "Reset password" : "Password *"}</label>
        <input
          id="u-password"
          className="input"
          name="password"
          type="password"
          minLength={8}
          autoComplete="new-password"
          placeholder={editing ? "Leave blank to keep current" : "At least 8 characters"}
          required={!editing}
        />
      </div>
      <fieldset className="sm:col-span-2" hidden={isSelf}>
        <legend className="label">Brand access</legend>
        {brands.length === 0 ? (
          <p className="text-sm text-slate-500">No brands available.</p>
        ) : (
          <div className="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-2">
            {brands.map((b) => (
              <label key={b.id} className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="checkbox"
                  name="brandIds"
                  value={b.id}
                  defaultChecked={initial?.brandIds.includes(Number(b.id))}
                />
                {b.name}
              </label>
            ))}
          </div>
        )}
        <p className="hint">Users only see leads, chats and websites for the brands ticked here.</p>
      </fieldset>
      {editing && !isSelf ? (
        <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
          <input type="checkbox" className="checkbox" name="active" defaultChecked={initial?.active} />
          Active (can sign in and receive leads)
        </label>
      ) : null}
      {error ? <p className="text-sm text-red-600 sm:col-span-2">{error}</p> : null}
      <div className="flex justify-end gap-2 sm:col-span-2">
        <button type="button" className="btn-secondary" onClick={onDone}>
          Cancel
        </button>
        <button type="submit" className="btn-primary" disabled={saving}>
          {saving ? "Saving…" : editing ? "Save changes" : "Add user"}
        </button>
      </div>
    </form>
  );
}

export function AddUserButton({ brands, roles }: { brands: Option[]; roles: Option[] }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button type="button" className="btn-primary" onClick={() => setOpen(true)}>
        <UserPlus className="h-4 w-4" />
        Add user
      </button>
      <Modal open={open} onClose={() => setOpen(false)} title="Add a team member" wide>
        <UserForm brands={brands} roles={roles} onDone={() => setOpen(false)} />
      </Modal>
    </>
  );
}

export function EditUserButton({
  brands,
  roles,
  user,
  isSelf,
}: {
  brands: Option[];
  roles: Option[];
  user: UserFormValues;
  isSelf: boolean;
}) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button type="button" className="btn-ghost btn-sm" onClick={() => setOpen(true)}>
        <Pencil className="h-3.5 w-3.5" />
        Edit
      </button>
      <Modal open={open} onClose={() => setOpen(false)} title={`Edit ${user.name}`} wide>
        <UserForm brands={brands} roles={roles} initial={user} isSelf={isSelf} onDone={() => setOpen(false)} />
      </Modal>
    </>
  );
}
