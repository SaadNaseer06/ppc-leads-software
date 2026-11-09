import { FormEvent, useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { CircleAlert, Inbox, Plus, ShieldAlert, Unplug } from "lucide-react";
import type { MailboxesData, MailboxRow } from "@/lib/api-types";
import { formatRelative } from "@/lib/format";
import { useConfirm } from "@/components/ui/confirm";
import { Modal } from "@/components/ui/modal";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { apiRequest, useToast } from "@/components/ui/toast";

/** Mailboxes on each brand's domain: one per person (riley@brand.com), and optionally one shared by the brand */
export default function EmailIndex({ mailboxes, brands, people }: MailboxesData) {
  const [editing, setEditing] = useState<MailboxRow | "new" | null>(null);
  const now = new Date();

  return (
    <div className="space-y-5">
      <Head title="Email" />
      <PageHeader
        title="Email"
        description="Connect each person's mailbox on the brand's domain, such as riley@yourbrand.com from cPanel. They email leads from the lead page, and clients' replies are filed on the lead within a minute."
        actions={
          <button type="button" className="btn-primary" onClick={() => setEditing("new")}>
            <Plus className="h-4 w-4" />
            Add mailbox
          </button>
        }
      />

      <p className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
        Keep the passwords yourself. A rep who knows their mailbox password can open webmail directly and see clients' full email addresses there, which the app keeps masked.
      </p>

      <div className="card overflow-hidden">
        {mailboxes.length === 0 ? (
          <EmptyState
            icon={<Inbox className="h-5 w-5" />}
            title="No mailboxes yet"
            description="Add one for each rep, on each brand they work."
            action={
              <button type="button" className="btn-primary btn-sm" onClick={() => setEditing("new")}>
                Add mailbox
              </button>
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Brand</th>
                  <th>Person</th>
                  <th>Address</th>
                  <th>Last checked</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {mailboxes.map((m) => (
                  <tr key={m.id}>
                    <td className="whitespace-nowrap font-medium text-slate-900">{m.brand}</td>
                    <td className="whitespace-nowrap">{m.owner ?? <span className="text-slate-500">Shared by the brand</span>}</td>
                    <td>{m.address}</td>
                    <td className="whitespace-nowrap text-slate-600">{m.checked_at ? formatRelative(m.checked_at, now) : <span className="text-slate-400">—</span>}</td>
                    <td className="max-w-[260px]">
                      {m.error ? (
                        <span className="flex items-start gap-1.5 text-xs text-red-700" title={m.error}>
                          <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                          <span className="line-clamp-2">{m.error}</span>
                        </span>
                      ) : (
                        <span className="badge badge-dot border-emerald-100 bg-emerald-50 text-emerald-700">Connected</span>
                      )}
                    </td>
                    <td className="text-right">
                      <button type="button" className="btn-secondary btn-sm" onClick={() => setEditing(m)}>
                        Edit
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        wide
        title={editing === "new" ? "Add a mailbox" : editing ? `Mailbox: ${editing.address}` : "Mailbox"}
        description="We log in to check the details before saving."
      >
        {editing ? (
          <MailboxForm key={editing === "new" ? "new" : editing.id} mailbox={editing === "new" ? null : editing} brands={brands} people={people} taken={mailboxes} onClose={() => setEditing(null)} />
        ) : null}
      </Modal>
    </div>
  );
}

function MailboxForm({
  mailbox,
  brands,
  people,
  taken,
  onClose,
}: {
  mailbox: MailboxRow | null;
  brands: MailboxesData["brands"];
  people: MailboxesData["people"];
  taken: MailboxRow[];
  onClose: () => void;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const [brandId, setBrandId] = useState<number | "">(mailbox?.brand_id ?? (brands.length === 1 ? brands[0].id : ""));
  const [owner, setOwner] = useState<string>(mailbox ? String(mailbox.user_id ?? "shared") : "");
  const [address, setAddress] = useState(mailbox?.address ?? "");
  const [fromName, setFromName] = useState(mailbox?.from_name ?? "");
  const [host, setHost] = useState(mailbox?.host ?? "");
  const [imapPort, setImapPort] = useState(String(mailbox?.imap_port ?? 993));
  const [smtpPort, setSmtpPort] = useState(String(mailbox?.smtp_port ?? 465));
  const [username, setUsername] = useState(mailbox?.username ?? "");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  // People on the chosen brand who don't have a mailbox there yet
  const choices = useMemo(() => {
    if (!brandId) return [];
    const used = new Set(taken.filter((m) => m.brand_id === brandId).map((m) => String(m.user_id ?? "shared")));
    return [
      ...(used.has("shared") ? [] : [{ value: "shared", label: "Shared by the whole brand" }]),
      ...people.filter((p) => p.brand_ids.includes(brandId) && !used.has(String(p.id))).map((p) => ({ value: String(p.id), label: `${p.name} · ${p.role}` })),
    ];
  }, [brandId, people, taken]);

  // cPanel's mail server is usually mail.<the domain>
  function onAddress(value: string) {
    setAddress(value);
    const domain = value.split("@")[1];
    if (domain && (!host || host === `mail.${address.split("@")[1] ?? ""}`)) setHost(`mail.${domain}`);
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!mailbox && (!brandId || !owner)) {
      setError("Choose the brand and who the mailbox belongs to.");
      return;
    }
    setSaving(true);
    setError("");
    const res = await apiRequest(mailbox ? `/api/admin/mailboxes/${mailbox.id}` : "/api/admin/mailboxes", {
      method: mailbox ? "PATCH" : "POST",
      body: {
        ...(mailbox ? {} : { brand_id: brandId, user_id: owner === "shared" ? null : Number(owner) }),
        address: address.trim(),
        from_name: fromName.trim() || null,
        host: host.trim(),
        imap_port: Number(imapPort),
        smtp_port: Number(smtpPort),
        username: username.trim() || null,
        password: password || null,
      },
    });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success(`${address.trim()} is connected`);
    onClose();
    router.reload();
  }

  async function disconnect() {
    if (!mailbox) return;
    const ok = await confirm({
      title: `Disconnect ${mailbox.address}?`,
      description: "Emails can't be sent from it in the app, and its replies stop being filed. Emails already on leads are kept.",
      confirmLabel: "Disconnect",
      tone: "danger",
    });
    if (!ok) return;
    const res = await apiRequest(`/api/admin/mailboxes/${mailbox.id}`, { method: "DELETE" });
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success("Mailbox disconnected");
    onClose();
    router.reload();
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" autoComplete="off">
      {mailbox ? (
        <p className="text-sm text-slate-600">
          {mailbox.brand} · {mailbox.owner ?? "Shared by the brand"}
        </p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="mb-brand">Brand *</label>
            <select
              id="mb-brand"
              className="input"
              value={brandId}
              onChange={(e) => {
                setBrandId(e.target.value ? Number(e.target.value) : "");
                setOwner("");
              }}
              required
            >
              <option value="">Select a brand</option>
              {brands.map((b) => (
                <option key={b.id} value={b.id}>{b.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label" htmlFor="mb-owner">Belongs to *</label>
            <select id="mb-owner" className="input" value={owner} onChange={(e) => setOwner(e.target.value)} disabled={!brandId} required>
              <option value="">{brandId ? "Select a person" : "Choose the brand first"}</option>
              {choices.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
          </div>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="mb-address">Mailbox address *</label>
          <input id="mb-address" className="input" type="email" value={address} onChange={(e) => onAddress(e.target.value)} placeholder="riley@yourbrand.com" required />
        </div>
        <div>
          <label className="label" htmlFor="mb-from">Sender name</label>
          <input id="mb-from" className="input" value={fromName} onChange={(e) => setFromName(e.target.value)} placeholder="Their name · the brand" maxLength={120} />
        </div>
        <div>
          <label className="label" htmlFor="mb-password">Password {mailbox ? "" : "*"}</label>
          <input
            id="mb-password"
            className="input"
            type="password"
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder={mailbox ? "Saved. Leave blank to keep it." : "The mailbox password"}
            required={!mailbox}
          />
        </div>
        <div>
          <label className="label" htmlFor="mb-user">Username</label>
          <input id="mb-user" className="input" value={username} onChange={(e) => setUsername(e.target.value)} placeholder="Same as the address" />
        </div>
      </div>
      <div className="grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-3">
        <div>
          <label className="label" htmlFor="mb-host">Mail server *</label>
          <input id="mb-host" className="input" value={host} onChange={(e) => setHost(e.target.value)} placeholder="mail.yourbrand.com" required />
        </div>
        <div>
          <label className="label" htmlFor="mb-imap">Incoming (IMAP) port</label>
          <select id="mb-imap" className="input" value={imapPort} onChange={(e) => setImapPort(e.target.value)}>
            <option value="993">993 (SSL, recommended)</option>
            <option value="143">143 (STARTTLS)</option>
          </select>
        </div>
        <div>
          <label className="label" htmlFor="mb-smtp">Outgoing (SMTP) port</label>
          <select id="mb-smtp" className="input" value={smtpPort} onChange={(e) => setSmtpPort(e.target.value)}>
            <option value="465">465 (SSL, recommended)</option>
            <option value="587">587 (STARTTLS)</option>
            <option value="25">25</option>
          </select>
        </div>
      </div>
      <p className="text-xs leading-relaxed text-slate-500">
        In cPanel, these are under <strong>Email Accounts → Connect Devices</strong>. Mail already in the inbox is left alone: only new replies from leads are filed. The password is
        stored encrypted and can't be read back.
      </p>

      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      <div className="flex flex-wrap items-center gap-2">
        {mailbox ? (
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
            {saving ? "Checking the mailbox…" : "Save and connect"}
          </button>
        </div>
      </div>
    </form>
  );
}
