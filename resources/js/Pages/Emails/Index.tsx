import { FormEvent, useEffect, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import { ArrowLeft, ExternalLink, Inbox, MailOpen, PenSquare, Search } from "lucide-react";
import type { EmailInboxData, EmailThreadDetail, EmailThreadRow } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatDate, formatTime } from "@/lib/format";
import { Avatar } from "@/components/ui/avatar";
import { StatusBadge } from "@/components/ui/badges";
import { PageHeader } from "@/components/ui/page-header";
import { useLiveReload } from "@/components/app-shell/live-provider";
import { EmailComposer, EmailThread, ReplyPrompt } from "@/components/email/email-thread";

type Params = Record<string, string | undefined>;
type Filter = "all" | "unread" | "needs_reply";

const RELOAD = { only: ["inbox", "query"], preserveState: true, preserveScroll: true };

function href(params: Params, next: Params) {
  const merged = { ...params, ...next };
  const qs = new URLSearchParams(Object.entries(merged).filter((e): e is [string, string] => Boolean(e[1])));
  const s = qs.toString();
  return s ? `/emails?${s}` : "/emails";
}

function when(iso: string, now: Date) {
  const d = new Date(iso);
  if (d.toDateString() === now.toDateString()) return formatTime(iso);
  return formatDate(iso);
}

/** Every email conversation with the person's leads, newest first, with the reading pane beside it */
export default function EmailsIndex({ inbox, query: params }: { inbox: EmailInboxData; query: Params }) {
  const user = usePage<SharedProps>().props.auth.user!;
  const filter: Filter = params.filter === "unread" || params.filter === "needs_reply" ? params.filter : "all";
  const selected = inbox.selected;
  const [search, setSearch] = useState(params.q ?? "");
  const now = new Date();

  // New emails arrive in the list (and in the open conversation) without a refresh
  useLiveReload(["inbox"], 3000);

  // Typing filters the list after a short pause
  useEffect(() => {
    if ((params.q ?? "") === search.trim()) return;
    const t = window.setTimeout(() => router.get(href(params, { q: search.trim() || undefined, before: undefined, id: undefined }), {}, RELOAD), 350);
    return () => window.clearTimeout(t);
  }, [search]); // eslint-disable-line react-hooks/exhaustive-deps

  const tabs: { key: Filter; label: string; count?: number }[] = [
    { key: "all", label: "All" },
    { key: "unread", label: "Unread", count: inbox.counts.unread },
    { key: "needs_reply", label: "Needs reply", count: inbox.counts.needs_reply },
  ];
  const filtered = Boolean(params.q || params.brand_id || filter !== "all");

  return (
    <div className="space-y-5">
      <Head title="Emails" />
      <PageHeader
        title="Emails"
        description={
          user.can.manage_payments
            ? "Every email conversation with your leads. Clients' replies arrive here within a minute."
            : "Your email conversations with your leads. Clients' replies arrive here within a minute."
        }
        actions={
          user.can.manage_payments ? (
            <Link href="/email" className="btn-secondary">
              Mailboxes
            </Link>
          ) : null
        }
      />

      <div className="card grid grid-cols-[minmax(0,1fr)] overflow-hidden lg:h-[calc(100vh-200px)] lg:min-h-[560px] lg:grid-cols-[380px_minmax(0,1fr)]">
        {/* The list */}
        <div className={`flex min-h-0 flex-col border-slate-200 bg-slate-50/40 lg:border-r ${selected ? "hidden lg:flex" : ""}`}>
          <div className="space-y-2.5 border-b border-slate-200 px-3 py-3">
            <form className="relative" onSubmit={(e: FormEvent) => e.preventDefault()} role="search">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input className="input pl-9" placeholder="Search by lead name" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search conversations" />
            </form>
            <div className="flex items-center gap-2">
              <div className="flex min-w-0 flex-1 gap-1 rounded-lg bg-slate-100 p-0.5" role="tablist" aria-label="Show">
                {tabs.map((t) => {
                  const active = filter === t.key;
                  return (
                    <Link
                      key={t.key}
                      href={href(params, { filter: t.key === "all" ? undefined : t.key, before: undefined })}
                      {...RELOAD}
                      role="tab"
                      aria-selected={active}
                      className={`flex flex-1 items-center justify-center gap-1.5 whitespace-nowrap rounded-md px-2 py-1.5 text-xs font-medium transition ${
                        active ? "bg-white text-slate-900 shadow-sm" : "text-slate-500 hover:text-slate-800"
                      }`}
                    >
                      {t.label}
                      {t.count ? (
                        <span className={`rounded-full px-1.5 text-[10px] font-semibold ${active ? "bg-teal-600 text-white" : "bg-slate-200 text-slate-600"}`}>
                          {t.count >= 1000 ? "999+" : t.count}
                        </span>
                      ) : null}
                    </Link>
                  );
                })}
              </div>
              {inbox.brands.length > 1 ? (
                <select
                  className="input w-auto max-w-[130px] py-1.5 text-xs"
                  value={params.brand_id ?? ""}
                  onChange={(e) => router.get(href(params, { brand_id: e.target.value || undefined, before: undefined }), {}, RELOAD)}
                  aria-label="Brand"
                >
                  <option value="">All brands</option>
                  {inbox.brands.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </select>
              ) : null}
            </div>
          </div>

          {inbox.threads.length === 0 ? (
            <div className="flex flex-1 flex-col items-center justify-center px-6 py-14 text-center">
              <span className="flex h-11 w-11 items-center justify-center rounded-full bg-white text-slate-400 shadow-sm ring-1 ring-slate-200">
                <Inbox className="h-5 w-5" />
              </span>
              <p className="mt-3 text-sm font-medium text-slate-800">{filtered ? "Nothing here" : "No emails yet"}</p>
              <p className="mt-1 max-w-[260px] text-xs leading-relaxed text-slate-500">
                {filtered
                  ? filter === "unread"
                    ? "You've read everything."
                    : filter === "needs_reply"
                      ? "Every client has an answer."
                      : "No conversation matches."
                  : "Email a lead from their page and the conversation shows up here, with the client's replies."}
              </p>
            </div>
          ) : (
            <ul className="min-h-0 flex-1 overflow-y-auto" aria-label="Conversations">
              {inbox.threads.map((t) => (
                <ThreadRow key={t.lead_id} thread={t} active={t.lead_id === selected?.lead.id} href={href(params, { id: String(t.lead_id) })} now={now} />
              ))}
              {inbox.has_more ? (
                <li className="p-2 text-center">
                  <Link className="btn-ghost btn-sm" href={href(params, { before: inbox.threads[inbox.threads.length - 1].last_email_at })} {...RELOAD}>
                    Older conversations
                  </Link>
                </li>
              ) : null}
            </ul>
          )}
        </div>

        {/* The reading pane */}
        <div className={`flex min-h-0 flex-col bg-white ${selected ? "" : "hidden lg:flex"}`}>
          {selected ? (
            <Conversation key={selected.lead.id} thread={selected} back={href(params, { id: undefined })} canSend={user.can.work_leads} />
          ) : (
            <div className="flex flex-1 flex-col items-center justify-center px-6 text-center">
              <span className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                <MailOpen className="h-6 w-6" />
              </span>
              <p className="mt-3 text-sm font-medium text-slate-800">Pick a conversation</p>
              <p className="mt-1 text-xs text-slate-500">It opens here, with a reply box underneath.</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function ThreadRow({ thread: t, active, href: to, now }: { thread: EmailThreadRow; active: boolean; href: string; now: Date }) {
  const who = t.direction === "out" ? `${t.sender ?? "You"}: ` : "";
  return (
    <li className="border-b border-slate-100">
      <Link
        href={to}
        {...RELOAD}
        className={`relative flex gap-3 py-3 pl-4 pr-4 transition ${active ? "bg-white shadow-[inset_3px_0_0_0_#0d9488]" : "hover:bg-white/80"}`}
      >
        {t.unread ? <span className="absolute left-1.5 top-[26px] h-1.5 w-1.5 rounded-full bg-teal-600" aria-label="Unread" /> : null}
        <Avatar name={t.name} />
        <div className="min-w-0 flex-1">
          <div className="flex items-baseline justify-between gap-2">
            <p className={`truncate text-sm ${t.unread ? "font-semibold text-slate-900" : "font-medium text-slate-800"}`}>
              {t.name}
              {t.count > 1 ? <span className="ml-1 text-xs font-normal text-slate-400">{t.count}</span> : null}
            </p>
            <time className={`shrink-0 text-[11px] ${t.unread ? "font-semibold text-teal-700" : "text-slate-400"}`} dateTime={t.last_email_at}>
              {when(t.last_email_at, now)}
            </time>
          </div>
          <p className={`mt-0.5 truncate text-[13px] ${t.unread ? "font-medium text-slate-800" : "text-slate-700"}`}>{t.subject}</p>
          <p className="mt-0.5 line-clamp-1 text-xs text-slate-500">
            {who}
            {t.snippet || "(no text)"}
          </p>
          <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
            {t.needs_reply ? <span className="rounded bg-amber-50 px-1.5 py-0.5 font-medium text-amber-700 ring-1 ring-inset ring-amber-200/70">Needs reply</span> : null}
            {t.brand ? <span className="truncate">{t.brand}</span> : null}
            {t.assignee ? <span className="truncate text-slate-400">· {t.assignee}</span> : null}
          </div>
        </div>
      </Link>
    </li>
  );
}

function Conversation({ thread, back, canSend }: { thread: EmailThreadDetail; back: string; canSend: boolean }) {
  const [composing, setComposing] = useState<"reply" | "new" | null>(null);
  const { lead, emails, mailbox } = thread;
  const latest = emails[emails.length - 1] ?? null;
  const subject = latest ? latest.subject.replace(/^(re:\s*)+/i, "") : "";
  const blocked = !mailbox
    ? "You don't have a mailbox on this brand yet. A Super Admin can create one on the Mailboxes page."
    : !thread.has_email
      ? "This lead has no email address."
      : null;

  function sent() {
    setComposing(null);
    router.reload({ only: ["inbox"] });
  }

  return (
    <>
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
        {/* At least this wide, so on a phone the buttons drop below the name instead of squeezing it out */}
        <div className="flex min-w-[240px] flex-1 items-start gap-3">
          <Link href={back} {...RELOAD} className="btn-ghost -ml-2 p-1.5 lg:hidden" aria-label="Back to the list">
            <ArrowLeft className="h-4 w-4" />
          </Link>
          <Avatar name={lead.name} size="lg" />
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="truncate text-base font-semibold text-slate-900">{lead.name}</h2>
              <StatusBadge status={lead.status} />
            </div>
            <p className="mt-0.5 truncate text-xs text-slate-500">
              {[lead.email, lead.brand, lead.assignee ? `Assigned to ${lead.assignee}` : "Unassigned"].filter(Boolean).join(" · ")}
            </p>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {canSend && !blocked && composing === null ? (
            <button type="button" className="btn-secondary btn-sm" onClick={() => setComposing("new")}>
              <PenSquare className="h-3.5 w-3.5" />
              New email
            </button>
          ) : null}
          <Link href={`/leads/${lead.id}`} className="btn-secondary btn-sm">
            <ExternalLink className="h-3.5 w-3.5" />
            Open lead
          </Link>
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto">
        {subject ? (
          <h3 className="px-5 pb-1 pt-4 text-lg font-semibold tracking-tight text-slate-900">
            {subject}
            <span className="ml-2 align-middle text-xs font-normal text-slate-400">
              {emails.length} email{emails.length === 1 ? "" : "s"}
            </span>
          </h3>
        ) : null}
        <EmailThread emails={emails} leadName={lead.name} />

        {canSend && mailbox && !blocked ? (
          <div className="border-t border-slate-100">
            {composing ? (
              <EmailComposer
                leadId={lead.id}
                leadName={lead.name}
                mailbox={mailbox}
                replyTo={composing === "reply" ? latest : null}
                onSent={sent}
                onCancel={() => setComposing(null)}
              />
            ) : latest ? (
              <ReplyPrompt leadName={lead.name} onClick={() => setComposing("reply")} />
            ) : null}
          </div>
        ) : blocked && canSend ? (
          <p className="border-t border-slate-100 px-5 py-4 text-sm text-slate-500">{blocked}</p>
        ) : null}
      </div>
    </>
  );
}
