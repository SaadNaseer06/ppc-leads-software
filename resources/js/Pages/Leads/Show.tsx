import { Head, Link, usePage } from "@inertiajs/react";
import { ArrowLeft, ExternalLink, Mail, MessageSquare, Phone } from "lucide-react";
import type { ChatMessage, LeadActivity, LeadDetail } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatDateTime, formatMoney, formatRelative, formatTime } from "@/lib/format";
import { CHANNEL_LABELS, labelFor, SOURCE_LABELS, STATUS_LABELS } from "@/lib/roles";
import { Avatar } from "@/components/ui/avatar";
import { ChannelBadge, SourceBadge, StatusBadge } from "@/components/ui/badges";
import { CopyButton } from "@/components/ui/copy-button";
import { LeadActions, EditContactButton } from "./partials/lead-actions";
import { NoteForm } from "./partials/note-form";
import { CreateInvoiceForm } from "./partials/create-invoice-form";

export default function LeadShow({ lead, activities, conversations, invoices, assignees }: LeadDetail) {
  const user = usePage<SharedProps>().props.auth.user!;
  const now = new Date();
  const canWork = user.can.work_leads;

  return (
    <div className="space-y-5">
      <Head title={lead.name} />
      <Link href="/leads" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-800">
        <ArrowLeft className="h-4 w-4" />
        Leads
      </Link>

      <div className="card p-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex min-w-0 items-start gap-4">
            <Avatar name={lead.name} size="lg" />
            <div className="min-w-0">
              <h1 className="truncate text-xl font-semibold tracking-tight text-slate-900">{lead.name}</h1>
              <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                <StatusBadge status={lead.status} />
                <SourceBadge source={lead.source} />
                <ChannelBadge channel={lead.channel} />
                {lead.submission_count > 1 ? (
                  <span className="badge">Submitted {lead.submission_count}×</span>
                ) : null}
              </div>
              <p className="mt-2 text-sm text-slate-500">
                Received {formatDateTime(lead.created_at)} via{" "}
                <span className="font-medium text-slate-700">{lead.website?.name ?? lead.brand.name}</span>
                {lead.form_name ? <> · {lead.form_name}</> : null}
              </p>
            </div>
          </div>
          {canWork ? (
            <LeadActions
              leadId={lead.id}
              status={lead.status}
              assigneeId={lead.assignee?.id ?? null}
              assignees={assignees}
              canAssign={user.can.assign_leads}
            />
          ) : null}
        </div>
      </div>

      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Enquiry</h2>
            </div>
            <div className="space-y-4 p-4">
              <p className="whitespace-pre-wrap text-sm leading-6 text-slate-700">
                {lead.message || <span className="text-slate-400">No message was included.</span>}
              </p>
              {Object.keys(lead.fields).length > 0 ? (
                <dl className="grid gap-x-6 gap-y-2 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-2">
                  {Object.entries(lead.fields).map(([key, value]) => (
                    <div key={key} className="min-w-0">
                      <dt className="text-xs font-medium text-slate-500">{key}</dt>
                      <dd className="break-words text-slate-800">{value}</dd>
                    </div>
                  ))}
                </dl>
              ) : null}
            </div>
          </section>

          {conversations.map((c) => (
            <section key={c.id} className="card">
              <div className="card-header">
                <h2 className="card-title flex items-center gap-2">
                  <MessageSquare className="h-4 w-4 text-slate-400" />
                  {c.kind === "ticket" ? "Offline message" : "Live chat"} · {formatDateTime(c.started_at)}
                </h2>
                {c.kind === "chat" ? (
                  <span className={`badge ${c.status === "open" ? "bg-emerald-50 text-emerald-700" : ""}`}>
                    {c.status === "open" ? "In progress" : "Ended"}
                  </span>
                ) : null}
              </div>
              <div className="max-h-[420px] space-y-3 overflow-y-auto bg-slate-50/60 p-4">
                {c.messages.length === 0 ? (
                  <p className="text-sm text-slate-500">The transcript will appear here once Tawk.to sends it.</p>
                ) : (
                  c.messages.map((m) => <ChatBubble key={m.id} message={m} />)
                )}
              </div>
            </section>
          ))}

          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Activity</h2>
              <span className="text-xs text-slate-500">Last activity {formatRelative(lead.last_activity_at, now)}</span>
            </div>
            <div className="p-4">
              {canWork ? <NoteForm leadId={lead.id} /> : null}
              <ol className="relative mt-5 space-y-5 border-l border-slate-200 pl-5">
                {activities.map((a) => (
                  <li key={a.id} className="relative">
                    <span className="absolute -left-[25px] top-1.5 h-2 w-2 rounded-full bg-slate-300 ring-4 ring-white" />
                    <ActivityLine activity={a} />
                    <p className="mt-0.5 text-xs text-slate-400" title={formatDateTime(a.created_at)}>
                      {formatRelative(a.created_at, now)}
                    </p>
                  </li>
                ))}
              </ol>
            </div>
          </section>
        </div>

        <div className="space-y-5">
          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Contact</h2>
              {canWork ? (
                <EditContactButton
                  leadId={lead.id}
                  initial={{ name: lead.name, email: lead.email ?? "", phone: lead.phone ?? "", company: lead.company ?? "" }}
                />
              ) : null}
            </div>
            <div className="space-y-3 p-4 text-sm">
              <ContactRow icon={<Mail className="h-4 w-4" />} value={lead.email} href={lead.email ? `mailto:${lead.email}` : null} />
              <ContactRow icon={<Phone className="h-4 w-4" />} value={lead.phone} href={lead.phone ? `tel:${lead.phone}` : null} />
              {lead.company ? <p className="text-slate-700">{lead.company}</p> : null}
            </div>
          </section>

          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Details</h2>
            </div>
            <dl className="divide-y divide-slate-100 text-sm">
              <Detail label="Status" value={labelFor(STATUS_LABELS, lead.status)} />
              <Detail label="Assignee" value={lead.assignee?.name ?? "Unassigned"} />
              <Detail label="Assigned" value={lead.assigned_at ? formatDateTime(lead.assigned_at) : null} />
              <Detail label="Website" value={lead.website ? `${lead.website.name} (${lead.website.domain})` : null} />
              <Detail label="Brand" value={lead.brand.name} />
              <Detail label="Source" value={labelFor(SOURCE_LABELS, lead.source)} />
            </dl>
          </section>

          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Ad attribution</h2>
              <ChannelBadge channel={lead.channel} />
            </div>
            <dl className="divide-y divide-slate-100 text-sm">
              <Detail label="Channel" value={labelFor(CHANNEL_LABELS, lead.channel)} />
              <Detail label="UTM source" value={lead.utm_source} />
              <Detail label="UTM medium" value={lead.utm_medium} />
              <Detail label="Campaign" value={lead.utm_campaign} />
              <Detail label="Keyword" value={lead.utm_term} />
              <Detail label="Ad content" value={lead.utm_content} />
              <Detail
                label={lead.click_id_type ? `Click ID (${lead.click_id_type})` : "Click ID"}
                value={lead.click_id}
                copy
              />
              <Detail label="Landing page" value={lead.landing_page} link />
              <Detail label="Form page" value={lead.page_url} link />
              <Detail label="Referrer" value={lead.referrer} link />
              <Detail label="Device" value={lead.device} />
              <Detail label="IP address" value={lead.ip_address} />
            </dl>
          </section>

          {user.can.create_invoice || invoices.length > 0 ? (
            <section className="card">
              <div className="card-header">
                <h2 className="card-title">Invoices</h2>
              </div>
              <div className="space-y-3 p-4">
                {invoices.length === 0 ? (
                  <p className="text-sm text-slate-500">No invoices yet.</p>
                ) : (
                  <ul className="space-y-2 text-sm">
                    {invoices.map((inv) => (
                      <li key={inv.id} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                        <div>
                          <p className="font-medium text-slate-800">{inv.number}</p>
                          <p className="text-xs text-slate-500">
                            {formatMoney(inv.amount, inv.currency)} · <span className="capitalize">{inv.status}</span>
                          </p>
                        </div>
                        <a className="btn-secondary btn-sm" href={`/api/invoices/${inv.id}/pdf`}>
                          PDF
                        </a>
                      </li>
                    ))}
                  </ul>
                )}
                {user.can.create_invoice ? <CreateInvoiceForm leadId={lead.id} /> : null}
              </div>
            </section>
          ) : null}
        </div>
      </div>
    </div>
  );
}

function ContactRow({ icon, value, href }: { icon: React.ReactNode; value: string | null; href: string | null }) {
  if (!value) {
    return (
      <p className="flex items-center gap-2 text-slate-400">
        {icon}
        Not provided
      </p>
    );
  }
  return (
    <div className="flex items-center justify-between gap-2">
      <a href={href ?? undefined} className="flex min-w-0 items-center gap-2 font-medium text-teal-700 hover:underline">
        <span className="text-slate-400">{icon}</span>
        <span className="truncate">{value}</span>
      </a>
      <CopyButton value={value} />
    </div>
  );
}

function Detail({ label, value, link, copy }: { label: string; value: string | null; link?: boolean; copy?: boolean }) {
  return (
    <div className="flex items-start justify-between gap-4 px-4 py-2.5">
      <dt className="shrink-0 text-slate-500">{label}</dt>
      <dd className="min-w-0 text-right text-slate-800">
        {!value ? (
          <span className="text-slate-400">—</span>
        ) : link && /^https?:\/\//i.test(value) ? (
          <a
            href={value}
            target="_blank"
            rel="noreferrer noopener"
            className="inline-flex max-w-full items-center gap-1 text-teal-700 hover:underline"
            title={value}
          >
            <span className="truncate">{value.replace(/^https?:\/\//, "")}</span>
            <ExternalLink className="h-3 w-3 shrink-0" />
          </a>
        ) : copy ? (
          <span className="block truncate font-mono text-xs" title={value}>
            {value}
          </span>
        ) : (
          <span className="break-words">{value}</span>
        )}
      </dd>
    </div>
  );
}

const REASONS: Record<string, string> = {
  auto_round_robin: "auto-assignment",
  converted_handoff: "conversion hand-off",
  user_deactivated: "user deactivated",
  bulk: "bulk action",
};

function ActivityLine({ activity }: { activity: LeadActivity }) {
  const d = (activity.data ?? {}) as Record<string, string | null | boolean | undefined>;
  const who = activity.actor ?? "System";
  const reason = typeof d.reason === "string" && REASONS[d.reason] ? ` (${REASONS[d.reason]})` : "";
  const strong = (text: string) => <span className="font-medium text-slate-900">{text}</span>;

  switch (activity.type) {
    case "created":
      return (
        <p className="text-sm text-slate-600">
          {activity.actor ? <>{strong(who)} created this lead</> : <>Lead received via {strong(labelFor(SOURCE_LABELS, String(d.source)))}</>}
        </p>
      );
    case "resubmitted":
      return (
        <div className="text-sm text-slate-600">
          <p>
            Contacted again via {strong(labelFor(SOURCE_LABELS, String(d.source)))}
            {d.reopened ? " — reopened from Lost" : ""}
          </p>
          {activity.body ? (
            <p className="mt-1.5 whitespace-pre-wrap rounded-lg bg-slate-50 px-3 py-2 text-slate-700">{activity.body}</p>
          ) : null}
        </div>
      );
    case "assigned":
      return (
        <p className="text-sm text-slate-600">
          {d.reason === "auto_round_robin" ? "Auto-assigned" : <>{strong(who)} assigned</>} to {strong(activity.to_user ?? "nobody")}
          {d.reason === "auto_round_robin" ? "" : reason}
        </p>
      );
    case "unassigned":
      return (
        <p className="text-sm text-slate-600">
          {strong(who)} unassigned {strong(activity.from_user ?? "nobody")}
          {reason}
        </p>
      );
    case "status":
      return (
        <p className="text-sm text-slate-600">
          {strong(who)} changed status from {labelFor(STATUS_LABELS, String(d.from))} to{" "}
          {strong(labelFor(STATUS_LABELS, String(d.to)))}
        </p>
      );
    case "note":
      return (
        <div className="text-sm">
          <p className="text-slate-600">{strong(who)} added a note</p>
          <p className="mt-1.5 whitespace-pre-wrap rounded-lg border border-amber-100 bg-amber-50/60 px-3 py-2 text-slate-800">
            {activity.body}
          </p>
        </div>
      );
    case "updated":
      return <p className="text-sm text-slate-600">{strong(who)} updated the contact details</p>;
    default:
      return <p className="text-sm text-slate-600">{activity.type}</p>;
  }
}

function ChatBubble({ message }: { message: ChatMessage }) {
  if (message.sender_type === "system") {
    return <p className="text-center text-xs text-slate-400">{message.body}</p>;
  }
  const visitor = message.sender_type === "visitor";
  return (
    <div className={`flex ${visitor ? "justify-start" : "justify-end"}`}>
      <div className="max-w-[80%]">
        <div
          className={`whitespace-pre-wrap break-words rounded-2xl px-3.5 py-2 text-sm ${
            visitor ? "rounded-bl-sm border border-slate-200 bg-white text-slate-800" : "rounded-br-sm bg-teal-700 text-white"
          }`}
        >
          {message.body}
        </div>
        <p className={`mt-1 text-[11px] text-slate-400 ${visitor ? "" : "text-right"}`}>
          {visitor ? "Visitor" : message.sender_name ?? "Agent"} · {formatTime(message.sent_at)}
        </p>
      </div>
    </div>
  );
}
