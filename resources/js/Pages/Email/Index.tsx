import { FormEvent, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { CircleAlert, Inbox, Unplug } from "lucide-react";
import type { MailboxesData, MailboxRow } from "@/lib/api-types";
import { formatRelative } from "@/lib/format";
import { useConfirm } from "@/components/ui/confirm";
import { Modal } from "@/components/ui/modal";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { apiRequest, useToast } from "@/components/ui/toast";

/** Each brand's shared mailbox, for example sales@ on cPanel webmail */
export default function EmailIndex({ brands }: MailboxesData) {
  const [editing, setEditing] = useState<MailboxRow | null>(null);
  const now = new Date();

  return (
    <div className="space-y-5">
      <Head title="Email" />
      <PageHeader
        title="Email"
        description="Connect each brand's mailbox, such as sales@ from cPanel webmail. The team emails leads from the lead page, and clients' replies are filed on the lead within a minute."
      />

      <div className="card overflow-hidden">
        {brands.length === 0 ? (
          <EmptyState icon={<Inbox className="h-5 w-5" />} title="No brands yet" description="Create a brand under Brands & routing first." />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Brand</th>
                  <th>Mailbox</th>
                  <th>Server</th>
                  <th>Last checked</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {brands.map((b) => (
                  <tr key={b.id}>
                    <td className="whitespace-nowrap font-medium text-slate-900">{b.name}</td>
                    <td>{b.address ?? <span className="text-slate-400">None</span>}</td>
                    <td className="text-slate-600">{b.host ?? <span className="text-slate-400">—</span>}</td>
                    <td className="whitespace-nowrap text-slate-600">{b.checked_at ? formatRelative(b.checked_at, now) : <span className="text-slate-400">—</span>}</td>
                    <td className="max-w-[260px]">
                      {!b.connected ? (
                        <span className="badge badge-dot border-slate-200 bg-slate-100 text-slate-600">Not connected</span>
                      ) : b.error ? (
                        <span className="flex items-start gap-1.5 text-xs text-red-700" title={b.error}>
                          <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                          <span className="line-clamp-2">{b.error}</span>
                        </span>
                      ) : (
                        <span className="badge badge-dot border-emerald-100 bg-emerald-50 text-emerald-700">Connected</span>
                      )}
                    </td>
                    <td className="text-right">
                      <button type="button" className={b.connected ? "btn-secondary btn-sm" : "btn-primary btn-sm"} onClick={() => setEditing(b)}>
                        {b.connected ? "Edit" : "Connect"}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal open={editing !== null} onClose={() => setEditing(null)} wide title={editing ? `Mailbox: ${editing.name}` : "Mailbox"} description="We log in to check the details before saving.">
        {editing ? <MailboxForm key={editing.id} brand={editing} onClose={() => setEditing(null)} /> : null}
      </Modal>
    </div>
  );
}

function MailboxForm({ brand, onClose }: { brand: MailboxRow; onClose: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const [address, setAddress] = useState(brand.address ?? "");
  const [fromName, setFromName] = useState(brand.from_name ?? "");
  const [host, setHost] = useState(brand.host ?? "");
  const [imapPort, setImapPort] = useState(String(brand.imap_port ?? 993));
  const [smtpPort, setSmtpPort] = useState(String(brand.smtp_port ?? 465));
  const [username, setUsername] = useState(brand.username ?? "");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  // cPanel's mail server is usually mail.<the domain>
  function onAddress(value: string) {
    setAddress(value);
    const domain = value.split("@")[1];
    if (domain && (!host || host === `mail.${address.split("@")[1] ?? ""}`)) setHost(`mail.${domain}`);
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    const res = await apiRequest(`/api/admin/brands/${brand.id}/mailbox`, {
      method: "PATCH",
      body: {
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
    toast.success(`${brand.name}'s mailbox is connected`);
    onClose();
    router.reload();
  }

  async function disconnect() {
    const ok = await confirm({
      title: `Disconnect ${brand.name}'s mailbox?`,
      description: "The team can't email leads from the app, and replies stop being filed. Emails already on leads are kept.",
      confirmLabel: "Disconnect",
      tone: "danger",
    });
    if (!ok) return;
    const res = await apiRequest(`/api/admin/brands/${brand.id}/mailbox`, { method: "DELETE" });
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
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="mb-address">Mailbox address *</label>
          <input id="mb-address" className="input" type="email" value={address} onChange={(e) => onAddress(e.target.value)} placeholder="sales@yourbrand.com" required />
        </div>
        <div>
          <label className="label" htmlFor="mb-from">Sender name</label>
          <input id="mb-from" className="input" value={fromName} onChange={(e) => setFromName(e.target.value)} placeholder={brand.name} maxLength={120} />
        </div>
        <div>
          <label className="label" htmlFor="mb-password">Password {brand.connected ? "" : "*"}</label>
          <input
            id="mb-password"
            className="input"
            type="password"
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder={brand.connected ? "Saved. Leave blank to keep it." : "The mailbox password"}
            required={!brand.connected}
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
        {brand.connected ? (
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
