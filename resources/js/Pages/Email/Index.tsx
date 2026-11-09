import { FormEvent, useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { CircleAlert, Inbox, KeyRound, Link2, Plus, RefreshCw, Server, ShieldCheck, Trash2, Unplug } from "lucide-react";
import type { CpanelAccountRow, MailboxesData, MailboxRow } from "@/lib/api-types";
import { formatRelative } from "@/lib/format";
import { useConfirm } from "@/components/ui/confirm";
import { Modal } from "@/components/ui/modal";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { apiRequest, useToast } from "@/components/ui/toast";

type Dialog = { kind: "create" } | { kind: "connect" } | { kind: "edit"; mailbox: MailboxRow } | { kind: "account" } | null;

/** Mailboxes on each brand's domain: created in cPanel from here, or connected by hand when the domain isn't on cPanel */
export default function EmailIndex({ mailboxes, brands, people, cpanel_accounts: accounts }: MailboxesData) {
  const [dialog, setDialog] = useState<Dialog>(null);
  const now = new Date();
  const domains = useMemo(() => [...new Set(accounts.flatMap((a) => a.domains))].sort(), [accounts]);

  return (
    <div className="space-y-5">
      <Head title="Mailboxes" />
      <PageHeader
        title="Mailboxes"
        description="Each rep emails leads from their own address on the brand's domain, and clients' replies are filed on the lead within a minute."
        actions={
          <div className="flex flex-wrap gap-2">
            <button type="button" className="btn-secondary" onClick={() => setDialog({ kind: "connect" })}>
              <Link2 className="h-4 w-4" />
              Connect existing
            </button>
            <button type="button" className="btn-primary" onClick={() => setDialog({ kind: "create" })} disabled={domains.length === 0} title={domains.length === 0 ? "Add a cPanel account first" : undefined}>
              <Plus className="h-4 w-4" />
              Create mailbox
            </button>
          </div>
        }
      />

      <p className="flex items-start gap-2 rounded-xl border border-teal-100 bg-teal-50/70 px-4 py-3 text-sm text-teal-950">
        <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" />
        Mailboxes created here get a long random password that nobody sees, so reps can't open webmail and read clients' addresses there. For mailboxes you connect by hand, keep
        the password to yourself.
      </p>

      <AccountsCard accounts={accounts} onAdd={() => setDialog({ kind: "account" })} />

      <div className="card overflow-hidden">
        <div className="card-header">
          <h2 className="card-title">Mailboxes</h2>
        </div>
        {mailboxes.length === 0 ? (
          <EmptyState
            icon={<Inbox className="h-5 w-5" />}
            title="No mailboxes yet"
            description={domains.length ? "Create one for each rep, on each brand they work." : "Add a cPanel account to create mailboxes from here, or connect an existing one."}
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
                    <td>
                      {m.address}
                      <span className="block text-xs text-slate-500">{m.managed ? "Created from the app" : "Connected by hand"}</span>
                    </td>
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
                      <button type="button" className="btn-secondary btn-sm" onClick={() => setDialog({ kind: "edit", mailbox: m })}>
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
        open={dialog !== null}
        onClose={() => setDialog(null)}
        wide
        title={
          dialog?.kind === "create"
            ? "Create a mailbox"
            : dialog?.kind === "connect"
              ? "Connect an existing mailbox"
              : dialog?.kind === "account"
                ? "Add a cPanel account"
                : dialog?.kind === "edit"
                  ? `Mailbox: ${dialog.mailbox.address}`
                  : "Mailbox"
        }
        description={dialog?.kind === "account" ? "So mailboxes can be created on the domains it hosts." : dialog?.kind === "create" ? "It's created in cPanel and connected in one go." : "We log in to check the details before saving."}
      >
        {dialog?.kind === "create" ? <CreateForm brands={brands} people={people} taken={mailboxes} domains={domains} onClose={() => setDialog(null)} /> : null}
        {dialog?.kind === "account" ? <AccountForm onClose={() => setDialog(null)} /> : null}
        {dialog?.kind === "connect" || dialog?.kind === "edit" ? (
          <MailboxForm key={dialog.kind === "edit" ? dialog.mailbox.id : "new"} mailbox={dialog.kind === "edit" ? dialog.mailbox : null} brands={brands} people={people} taken={mailboxes} onClose={() => setDialog(null)} />
        ) : null}
      </Modal>
    </div>
  );
}

/** People on the brand who don't have a mailbox there yet, plus the brand's shared one if it's free */
function ownerChoices(brandId: number | "", people: MailboxesData["people"], taken: MailboxRow[]) {
  if (!brandId) return [];
  const used = new Set(taken.filter((m) => m.brand_id === brandId).map((m) => String(m.user_id ?? "shared")));
  return [
    ...(used.has("shared") ? [] : [{ value: "shared", label: "Shared by the whole brand" }]),
    ...people.filter((p) => p.brand_ids.includes(brandId) && !used.has(String(p.id))).map((p) => ({ value: String(p.id), label: `${p.name} · ${p.role}` })),
  ];
}

function BrandAndOwner({
  brands,
  choices,
  brandId,
  owner,
  onBrand,
  onOwner,
}: {
  brands: MailboxesData["brands"];
  choices: { value: string; label: string }[];
  brandId: number | "";
  owner: string;
  onBrand: (id: number | "") => void;
  onOwner: (value: string) => void;
}) {
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <div>
        <label className="label" htmlFor="mb-brand">Brand *</label>
        <select id="mb-brand" className="input" value={brandId} onChange={(e) => onBrand(e.target.value ? Number(e.target.value) : "")} required>
          <option value="">Select a brand</option>
          {brands.map((b) => (
            <option key={b.id} value={b.id}>{b.name}</option>
          ))}
        </select>
      </div>
      <div>
        <label className="label" htmlFor="mb-owner">Belongs to *</label>
        <select id="mb-owner" className="input" value={owner} onChange={(e) => onOwner(e.target.value)} disabled={!brandId} required>
          <option value="">{brandId ? "Select a person" : "Choose the brand first"}</option>
          {choices.map((c) => (
            <option key={c.value} value={c.value}>{c.label}</option>
          ))}
        </select>
      </div>
    </div>
  );
}

function CreateForm({
  brands,
  people,
  taken,
  domains,
  onClose,
}: {
  brands: MailboxesData["brands"];
  people: MailboxesData["people"];
  taken: MailboxRow[];
  domains: string[];
  onClose: () => void;
}) {
  const toast = useToast();
  const [brandId, setBrandId] = useState<number | "">(brands.length === 1 ? brands[0].id : "");
  const [owner, setOwner] = useState("");
  const [local, setLocal] = useState("");
  const [domain, setDomain] = useState(domains.length === 1 ? domains[0] : "");
  const [fromName, setFromName] = useState("");
  const [quota, setQuota] = useState("1024");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const choices = useMemo(() => ownerChoices(brandId, people, taken), [brandId, people, taken]);

  // The address starts as the person's first name, or "sales" for a shared mailbox
  function onOwner(value: string) {
    setOwner(value);
    if (local) return;
    const person = people.find((p) => String(p.id) === value);
    setLocal(value === "shared" ? "sales" : (person?.name.split(" ")[0] ?? "").toLowerCase().replace(/[^a-z0-9._-]/g, ""));
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    const res = await apiRequest<{ warning: string | null }>("/api/admin/mailboxes/create-in-cpanel", {
      method: "POST",
      body: { brand_id: brandId, user_id: owner === "shared" ? null : Number(owner), local_part: local.trim(), domain, from_name: fromName.trim() || null, quota: Number(quota) },
    });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    if (res.data.warning) toast.error(`Created, but it can't be logged in to yet: it will be retried. ${res.data.warning}`);
    else toast.success(`${local.trim()}@${domain} is created and connected`);
    onClose();
    router.reload();
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" autoComplete="off">
      <BrandAndOwner
        brands={brands}
        choices={choices}
        brandId={brandId}
        owner={owner}
        onBrand={(id) => {
          setBrandId(id);
          setOwner("");
        }}
        onOwner={onOwner}
      />
      <div>
        <label className="label" htmlFor="mb-local">Address *</label>
        <div className="flex items-center gap-2">
          <input id="mb-local" className="input min-w-0 flex-1" value={local} onChange={(e) => setLocal(e.target.value)} placeholder="riley" maxLength={64} required />
          <span className="text-slate-500">@</span>
          <select className="input min-w-0 flex-1" value={domain} onChange={(e) => setDomain(e.target.value)} aria-label="Domain" required>
            <option value="">Select a domain</option>
            {domains.map((d) => (
              <option key={d} value={d}>{d}</option>
            ))}
          </select>
        </div>
        <p className="mt-1.5 text-xs text-slate-500">Domains come from the connected cPanel accounts. Don't see one? Refresh its account above after adding the domain in cPanel.</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="mb-from">Sender name</label>
          <input id="mb-from" className="input" value={fromName} onChange={(e) => setFromName(e.target.value)} placeholder="Their name · the brand" maxLength={120} />
        </div>
        <div>
          <label className="label" htmlFor="mb-quota">Mailbox size</label>
          <select id="mb-quota" className="input" value={quota} onChange={(e) => setQuota(e.target.value)}>
            <option value="512">512 MB</option>
            <option value="1024">1 GB</option>
            <option value="2048">2 GB</option>
            <option value="5120">5 GB</option>
          </select>
        </div>
      </div>
      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button type="submit" className="btn-primary" disabled={saving}>
          {saving ? "Creating in cPanel…" : "Create and connect"}
        </button>
      </div>
    </form>
  );
}

function AccountsCard({ accounts, onAdd }: { accounts: CpanelAccountRow[]; onAdd: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const [busy, setBusy] = useState<number | null>(null);

  async function refresh(account: CpanelAccountRow) {
    setBusy(account.id);
    const res = await apiRequest(`/api/admin/cpanel-accounts/${account.id}/refresh`, { method: "POST", body: {} });
    setBusy(null);
    if (!res.ok) toast.error(res.error);
    else router.reload();
  }

  async function remove(account: CpanelAccountRow) {
    const ok = await confirm({
      title: `Remove ${account.username} on ${account.host}?`,
      description: "The app forgets its API token. Mailboxes it created stay connected and keep working, but can't be reset or deleted from here any more.",
      confirmLabel: "Remove",
      tone: "danger",
    });
    if (!ok) return;
    const res = await apiRequest(`/api/admin/cpanel-accounts/${account.id}`, { method: "DELETE" });
    if (!res.ok) toast.error(res.error);
    else router.reload();
  }

  return (
    <section className="card overflow-hidden">
      <div className="card-header">
        <h2 className="card-title">cPanel accounts</h2>
        <button type="button" className="btn-secondary btn-sm" onClick={onAdd}>
          <Plus className="h-3.5 w-3.5" />
          Add cPanel account
        </button>
      </div>
      {accounts.length === 0 ? (
        <p className="p-4 text-sm text-slate-500">
          None yet. Add the cPanel account that hosts each brand's domain, and mailboxes can be created from here. Brands hosted elsewhere can still have mailboxes connected by hand.
        </p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {accounts.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
              <Server className="h-4 w-4 shrink-0 text-slate-400" />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-slate-900">
                  {a.username} <span className="font-normal text-slate-500">on {a.host}</span>
                </p>
                <p className="truncate text-xs text-slate-500" title={a.domains.join(", ")}>
                  {a.domains.length ? a.domains.join(", ") : "No mail domains"}
                </p>
              </div>
              <button type="button" className="btn-ghost btn-sm" onClick={() => void refresh(a)} disabled={busy === a.id}>
                <RefreshCw className="h-3.5 w-3.5" />
                {busy === a.id ? "Checking…" : "Refresh domains"}
              </button>
              <button type="button" className="btn-ghost btn-sm text-red-600" onClick={() => void remove(a)} aria-label={`Remove ${a.host}`}>
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function AccountForm({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const [host, setHost] = useState("");
  const [username, setUsername] = useState("");
  const [token, setToken] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError("");
    const res = await apiRequest<{ account: CpanelAccountRow }>("/api/admin/cpanel-accounts", {
      method: "POST",
      body: { host: host.trim(), username: username.trim(), token: token.trim() },
    });
    setSaving(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success(`Connected: ${res.data.account.domains.length} mail domain${res.data.account.domains.length === 1 ? "" : "s"} found`);
    onClose();
    router.reload();
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" autoComplete="off">
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="cp-host">cPanel server *</label>
          <input id="cp-host" className="input" value={host} onChange={(e) => setHost(e.target.value)} placeholder="server1.yourhost.com" required />
        </div>
        <div>
          <label className="label" htmlFor="cp-user">cPanel username *</label>
          <input id="cp-user" className="input" value={username} onChange={(e) => setUsername(e.target.value)} required />
        </div>
      </div>
      <div>
        <label className="label" htmlFor="cp-token">API token *</label>
        <input id="cp-token" className="input font-mono text-xs" type="password" autoComplete="new-password" value={token} onChange={(e) => setToken(e.target.value)} required />
        <p className="mt-1.5 text-xs leading-relaxed text-slate-500">
          In cPanel: <strong>Security → Manage API Tokens → Create</strong>. Give it an expiry date if you like. A cPanel token can manage the whole account, so it's stored encrypted, only
          Super Admins can add it, and it's never shown again.
        </p>
      </div>
      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button type="submit" className="btn-primary" disabled={saving}>
          {saving ? "Checking with cPanel…" : "Connect account"}
        </button>
      </div>
    </form>
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
  const choices = useMemo(() => ownerChoices(brandId, people, taken), [brandId, people, taken]);
  const managed = Boolean(mailbox?.managed);

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

  async function resetPassword() {
    if (!mailbox) return;
    const ok = await confirm({
      title: `Give ${mailbox.address} a new password?`,
      description: "A new random password is set in cPanel and in the app at once. Use this if you think the old one got out.",
      confirmLabel: "Reset password",
      tone: "default",
    });
    if (!ok) return;
    const res = await apiRequest(`/api/admin/mailboxes/${mailbox.id}/reset-password`, { method: "POST", body: {} });
    if (!res.ok) setError(res.error);
    else toast.success("Password reset");
  }

  async function remove(alsoInCpanel: boolean) {
    if (!mailbox) return;
    const ok = await confirm(
      alsoInCpanel
        ? {
            title: `Delete ${mailbox.address} in cPanel?`,
            description: "The mailbox and every email in it are deleted from the server for good. Emails already on leads in the app are kept.",
            confirmLabel: "Delete mailbox",
            tone: "danger",
            requireText: "DELETE",
            requireTextLabel: "Type DELETE to confirm",
          }
        : {
            title: `Disconnect ${mailbox.address}?`,
            description: "It stays in cPanel with all its emails, but the app stops sending from it and filing its replies. Emails already on leads are kept.",
            confirmLabel: "Disconnect",
            tone: "danger",
          }
    );
    if (!ok) return;
    const res = await apiRequest(`/api/admin/mailboxes/${mailbox.id}${alsoInCpanel ? "?delete_from_cpanel=1" : ""}`, { method: "DELETE" });
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success(alsoInCpanel ? "Mailbox deleted" : "Mailbox disconnected");
    onClose();
    router.reload();
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" autoComplete="off">
      {mailbox ? (
        <p className="text-sm text-slate-600">
          {mailbox.brand} · {mailbox.owner ?? "Shared by the brand"}
          {managed ? " · created from the app" : ""}
        </p>
      ) : (
        <BrandAndOwner
          brands={brands}
          choices={choices}
          brandId={brandId}
          owner={owner}
          onBrand={(id) => {
            setBrandId(id);
            setOwner("");
          }}
          onOwner={setOwner}
        />
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="mb-address">Mailbox address *</label>
          <input id="mb-address" className="input" type="email" value={address} onChange={(e) => onAddress(e.target.value)} placeholder="riley@yourbrand.com" required disabled={managed} />
        </div>
        <div>
          <label className="label" htmlFor="mb-from">Sender name</label>
          <input id="mb-from" className="input" value={fromName} onChange={(e) => setFromName(e.target.value)} placeholder="Their name · the brand" maxLength={120} />
        </div>
        {managed ? null : (
          <>
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
          </>
        )}
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
      {managed ? null : (
        <p className="text-xs leading-relaxed text-slate-500">
          In cPanel, these are under <strong>Email Accounts → Connect Devices</strong>. Mail already in the inbox is left alone: only new replies from leads are filed. The password is
          stored encrypted and can't be read back.
        </p>
      )}

      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      <div className="flex flex-wrap items-center gap-2">
        {mailbox ? (
          <>
            <button type="button" className="btn-ghost btn-sm text-red-600" onClick={() => void remove(false)}>
              <Unplug className="h-3.5 w-3.5" />
              Disconnect
            </button>
            {managed ? (
              <>
                <button type="button" className="btn-ghost btn-sm text-red-600" onClick={() => void remove(true)}>
                  <Trash2 className="h-3.5 w-3.5" />
                  Delete in cPanel
                </button>
                <button type="button" className="btn-ghost btn-sm" onClick={() => void resetPassword()}>
                  <KeyRound className="h-3.5 w-3.5" />
                  Reset password
                </button>
              </>
            ) : null}
          </>
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
