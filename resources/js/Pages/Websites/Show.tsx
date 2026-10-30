import { Head, Link } from "@inertiajs/react";
import { ArrowLeft, Code2, MessageSquare, Webhook } from "lucide-react";
import type { WebsiteDetail } from "@/lib/api-types";
import { formatDateTime, formatNumber, formatRelative } from "@/lib/format";
import { PageHeader } from "@/components/ui/page-header";
import { StatusBadge } from "@/components/ui/badges";
import { CopyButton } from "@/components/ui/copy-button";
import { WebsiteForm } from "./partials/website-form";
import { DeleteWebsiteButton, RotateKeyButton, SendTestLeadButton } from "./partials/website-actions";

const EVENT_STYLES: Record<string, string> = {
  processed: "bg-emerald-50 text-emerald-700",
  ignored: "bg-slate-100 text-slate-600",
  rejected: "bg-red-50 text-red-700",
  failed: "bg-red-50 text-red-700",
};

export default function WebsiteShow(data: WebsiteDetail) {
  const { website, brands, events } = data;
  const leads30d = data.leads_30d;
  const recentLeads = data.recent_leads;
  const now = new Date();
  const snippet = website.embed_snippet;
  const endpoint = website.intake_url;
  const curl = `curl -X POST ${endpoint} \\
  -H "Content-Type: application/json" \\
  -d '{"name":"Jane Doe","email":"jane@example.com","phone":"+1 555 010 2030","message":"I need a quote","utm_source":"google","utm_medium":"cpc","utm_campaign":"spring-sale","gclid":"EAIaIQ..."}'`;
  const htmlForm = `<form action="${endpoint}" method="POST">
  <input name="name" required>
  <input name="email" type="email">
  <input name="phone">
  <textarea name="message"></textarea>
  <input type="hidden" name="_redirect" value="https://${website.domain}/thank-you">
  <button>Send</button>
</form>`;

  return (
    <div className="space-y-5">
      <Head title={website.name} />
      <Link href="/websites" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800">
        <ArrowLeft className="h-4 w-4" />
        Websites
      </Link>

      <PageHeader
        title={website.name}
        description={
          <>
            {website.domain} · {website.brand.name} ·{" "}
            {website.active ? "Accepting leads" : <span className="font-medium text-amber-700">Paused — new leads are rejected</span>}
          </>
        }
        actions={<SendTestLeadButton endpoint={endpoint} disabled={!website.active} />}
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="Leads in the last 30 days" value={formatNumber(leads30d)} />
        <Stat label="Last form lead" value={website.last_lead_at ? formatRelative(website.last_lead_at, now) : "Never"} />
        <Stat label="Last live chat" value={website.last_chat_at ? formatRelative(website.last_chat_at, now) : "Never"} />
      </div>

      <div className="grid gap-5 xl:grid-cols-3">
        <div className="space-y-5 xl:col-span-2">
          <section className="card">
            <div className="card-header">
              <h2 className="card-title flex items-center gap-2">
                <MessageSquare className="h-4 w-4 text-slate-400" />
                Live chat widget
              </h2>
              {website.chat_enabled ? (
                <span className="badge bg-emerald-50 text-emerald-700">Enabled</span>
              ) : (
                <span className="badge">Disabled</span>
              )}
              <CopyButton value={website.chat_snippet} label="Copy snippet" />
            </div>
            <div className="space-y-3 p-4 text-sm text-slate-600">
              <p>
                Paste this before <code className="rounded bg-slate-100 px-1">&lt;/body&gt;</code> on {website.domain} to
                show the chat bubble. Visitors message your team; agents reply from the{" "}
                <Link href="/chats" className="font-medium text-teal-700 hover:underline">
                  Chats
                </Link>{" "}
                page. Requires Pusher keys in the server <code className="rounded bg-slate-100 px-1">.env</code> for
                realtime (polling still works without them).
              </p>
              <Code value={website.chat_snippet} />
              <p className="text-xs text-slate-500">
                Toggle &quot;Enable live chat widget&quot; in settings if you need to pause the bubble without removing the
                snippet.
              </p>
            </div>
          </section>

          <section className="card">
            <div className="card-header">
              <h2 className="card-title flex items-center gap-2">
                <Code2 className="h-4 w-4 text-slate-400" />
                Option 1 · Form embed script (recommended)
              </h2>
              <CopyButton value={snippet} label="Copy snippet" />
            </div>
            <div className="space-y-3 p-4 text-sm text-slate-600">
              <p>
                Paste this before <code className="rounded bg-slate-100 px-1">&lt;/body&gt;</code> on every page of{" "}
                {website.domain} (in WordPress, use a header/footer plugin or your theme&apos;s footer). It captures every
                form submission automatically — Contact Form 7, Elementor, WPForms, Gravity Forms, custom HTML — and
                records the Google Ads click ID (gclid), UTM campaign, keyword and landing page, even if the visitor
                browses several pages before enquiring.
              </p>
              <Code value={snippet} />
              <p className="text-xs text-slate-500">
                Add <code className="rounded bg-slate-100 px-1">data-ppc-ignore</code> to any form you don&apos;t want
                captured. Login, sign-up and search forms are skipped automatically.
              </p>
            </div>
          </section>

          <section className="card">
            <div className="card-header">
              <h2 className="card-title flex items-center gap-2">
                <Webhook className="h-4 w-4 text-slate-400" />
                Option 2 · Webhook / API
              </h2>
              <CopyButton value={endpoint} label="Copy URL" />
            </div>
            <div className="space-y-3 p-4 text-sm text-slate-600">
              <p>
                Point a form plugin&apos;s webhook action (Elementor Pro, WPForms, Gravity Forms, Zapier, Make, Unbounce,
                your own backend) at this URL. JSON, form-encoded and multipart bodies are accepted, and common field
                names such as <em>your-name</em>, <em>first_name</em>/<em>last_name</em>, <em>tel</em> or{" "}
                <em>comments</em> are mapped automatically. Any other fields are kept on the lead.
              </p>
              <Code value={endpoint} />
              <details className="rounded-lg border border-slate-200">
                <summary className="cursor-pointer px-3 py-2 text-sm font-medium text-slate-700">Example request</summary>
                <div className="border-t border-slate-200 p-3">
                  <Code value={curl} />
                </div>
              </details>
              <details className="rounded-lg border border-slate-200">
                <summary className="cursor-pointer px-3 py-2 text-sm font-medium text-slate-700">
                  Plain HTML form (no JavaScript)
                </summary>
                <div className="border-t border-slate-200 p-3">
                  <Code value={htmlForm} />
                </div>
              </details>
            </div>
          </section>

          <details className="card">
            <summary className="card-header cursor-pointer list-none">
              <h2 className="card-title flex items-center gap-2">
                <MessageSquare className="h-4 w-4 text-slate-400" />
                Legacy · Tawk.to (read-only history)
              </h2>
              {website.tawk_property_id ? (
                <span className="badge bg-emerald-50 text-emerald-700">Linked</span>
              ) : (
                <span className="badge">Not linked</span>
              )}
            </summary>
            <div className="space-y-4 border-t border-slate-100 p-4 text-sm text-slate-600">
              <p className="text-xs text-slate-500">
                New traffic should use the PPC Leads chat widget above. Keep Tawk linked only if you still need
                historical chats or offline tickets.
              </p>
              <ol className="list-decimal space-y-2 pl-5">
                <li>
                  In Tawk.to open <strong>Administration → Settings → Webhooks</strong> and create a webhook.
                </li>
                <li>
                  Set the endpoint URL to:
                  <div className="mt-1.5 flex items-center gap-2">
                    <code className="min-w-0 flex-1 truncate rounded bg-slate-100 px-2 py-1.5 text-xs">{website.tawk_webhook_url}</code>
                    <CopyButton value={website.tawk_webhook_url} />
                  </div>
                </li>
                <li>
                  Tick <strong>Chat Start</strong>, <strong>Chat End</strong>, <strong>Chat Transcript Created</strong> and{" "}
                  <strong>New Ticket</strong>.
                </li>
                <li>
                  Copy the webhook&apos;s <strong>secret key</strong> and the <strong>property ID</strong> into the settings
                  below.
                </li>
              </ol>
              {website.tawk_property_id ? (
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Recent deliveries</p>
                  {events.length === 0 ? (
                    <p className="rounded-lg bg-slate-50 px-3 py-2 text-slate-500">
                      Nothing received yet from Tawk.to for this property.
                    </p>
                  ) : (
                    <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                      {events.map((e) => (
                        <li key={e.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                          <span className="font-mono text-xs text-slate-700">{e.event}</span>
                          <span className="flex items-center gap-2">
                            {e.error ? <span className="text-xs text-red-600">{e.error}</span> : null}
                            <span className={`badge ${EVENT_STYLES[e.status] ?? ""}`}>{e.status}</span>
                            <span className="text-xs text-slate-400" title={formatDateTime(e.created_at)}>
                              {formatRelative(e.created_at, now)}
                            </span>
                          </span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              ) : null}
            </div>
          </details>
        </div>

        <div className="space-y-5">
          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Recent leads</h2>
              <Link href={`/leads?website=${website.id}`} className="text-xs font-medium text-teal-700 hover:underline">
                View all
              </Link>
            </div>
            {recentLeads.length === 0 ? (
              <p className="p-4 text-sm text-slate-500">No leads yet. Use “Send test lead” to check the setup.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {recentLeads.map((l) => (
                  <li key={l.id}>
                    <Link href={`/leads/${l.id}`} className="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-slate-50">
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-medium text-slate-900">{l.name}</span>
                        <span className="text-xs text-slate-500">{formatRelative(l.created_at, now)}</span>
                      </span>
                      <StatusBadge status={l.status} />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Settings</h2>
            </div>
            <div className="p-4">
              <WebsiteForm
                stacked
                brands={brands}
                initial={{
                  id: website.id,
                  name: website.name,
                  domain: website.domain,
                  brandId: website.brand_id,
                  tawkPropertyId: website.tawk_property_id ?? "",
                  hasTawkSecret: website.has_tawk_secret,
                  active: website.active,
                  chatEnabled: website.chat_enabled,
                }}
              />
            </div>
          </section>

          <section className="card border-red-100">
            <div className="card-header">
              <h2 className="card-title">Website key</h2>
            </div>
            <div className="space-y-3 p-4 text-sm text-slate-600">
              <code className="block truncate rounded bg-slate-100 px-2 py-1.5 text-xs">{website.public_key}</code>
              <p className="text-xs text-slate-500">
                If the key is abused by spammers, generate a new one. The old snippet and webhook URL stop working
                immediately, so update the website straight after.
              </p>
              <RotateKeyButton websiteId={website.id} />
            </div>
          </section>

          <section className="card border-red-200">
            <div className="card-header">
              <h2 className="card-title text-red-700">Danger zone</h2>
            </div>
            <div className="space-y-3 p-4 text-sm text-slate-600">
              <p className="text-xs text-slate-500">
                Remove this website from the dashboard. Past leads are kept and unlinked; new capture for this domain
                stops until you add the site again.
              </p>
              <DeleteWebsiteButton websiteId={website.id} websiteName={website.name} />
            </div>
          </section>
        </div>
      </div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="card px-4 py-3">
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className="mt-1 text-lg font-semibold text-slate-900">{value}</p>
    </div>
  );
}

function Code({ value }: { value: string }) {
  return (
    <pre className="overflow-x-auto whitespace-pre-wrap break-all rounded-lg bg-slate-900 px-3 py-2.5 font-mono text-xs leading-5 text-slate-100">
      {value}
    </pre>
  );
}
