import { lazy, Suspense, useEffect, useState } from "react";
import { Download, File, FileText, Image as ImageIcon, MoreHorizontal, Paperclip, Send } from "lucide-react";
import type { EmailAttachment, LeadEmailRow } from "@/lib/api-types";
import { Avatar } from "@/components/ui/avatar";
import { formatDate, formatDateTime, formatTime } from "@/lib/format";
import { emailSnippet, fileSize, splitQuoted, viewableFile } from "@/lib/email";

/** Who wrote an email, as people say it: the client by the lead's name, the team by the person who sent it */
function author(email: LeadEmailRow, leadName: string) {
  return email.direction === "in"
    ? { name: leadName, address: email.from_email, to: "you" }
    : { name: email.sender ?? email.from_name ?? "Your team", address: email.from_email, to: leadName };
}

function shortWhen(iso: string, now: Date) {
  const d = new Date(iso);
  return d.toDateString() === now.toDateString() ? formatTime(iso) : formatDate(iso);
}

/**
 * A conversation, oldest first, read like a mail app: the newest email open, earlier ones folded to a
 * line each, and the history quoted inside replies tucked behind "…".
 */
export function EmailThread({ emails, leadName, compact = false }: { emails: LeadEmailRow[]; leadName: string; compact?: boolean }) {
  const lastId = emails[emails.length - 1]?.id;
  const [open, setOpen] = useState<Set<number>>(() => new Set(lastId ? [lastId] : []));
  const now = new Date();

  // A new email arriving opens; the ones already read stay as they were
  useEffect(() => {
    if (lastId) setOpen((prev) => (prev.has(lastId) ? prev : new Set([...prev, lastId])));
  }, [lastId]);

  function toggle(id: number) {
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  return (
    <ol className="divide-y divide-slate-100">
      {emails.map((email) => {
        const who = author(email, leadName);
        if (!open.has(email.id)) {
          return (
            <li key={email.id}>
              <button
                type="button"
                onClick={() => toggle(email.id)}
                className={`flex w-full items-center gap-3 text-left transition hover:bg-slate-50 ${compact ? "px-4 py-2.5" : "px-5 py-3"}`}
              >
                <Avatar name={who.name} size="sm" />
                <span className={`shrink-0 truncate text-sm font-medium text-slate-800 ${compact ? "w-24" : "w-24 sm:w-40"}`}>{who.name}</span>
                <span className="min-w-0 flex-1 truncate text-sm text-slate-500">{emailSnippet(email.body) || (email.attachments.length ? "Attachment" : "")}</span>
                {email.attachments.length ? <Paperclip className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-label="Has attachments" /> : null}
                <time className="shrink-0 text-xs text-slate-400" dateTime={email.sent_at} title={formatDateTime(email.sent_at)}>
                  {shortWhen(email.sent_at, now)}
                </time>
              </button>
            </li>
          );
        }
        return (
          <li key={email.id}>
            <EmailMessage email={email} who={who} compact={compact} onCollapse={emails.length > 1 ? () => toggle(email.id) : undefined} />
          </li>
        );
      })}
    </ol>
  );
}

function EmailMessage({
  email,
  who,
  compact,
  onCollapse,
}: {
  email: LeadEmailRow;
  who: ReturnType<typeof author>;
  compact: boolean;
  onCollapse?: () => void;
}) {
  const { text, quoted } = splitQuoted(email.body);
  const [showQuoted, setShowQuoted] = useState(!text);

  return (
    <article className={compact ? "px-4 py-3.5" : "px-5 py-4"}>
      <header
        className={`flex items-start gap-3 ${onCollapse ? "cursor-pointer" : ""}`}
        onClick={onCollapse}
        role={onCollapse ? "button" : undefined}
        tabIndex={onCollapse ? 0 : undefined}
        onKeyDown={(e) => {
          if (onCollapse && (e.key === "Enter" || e.key === " ")) {
            e.preventDefault();
            onCollapse();
          }
        }}
      >
        <Avatar name={who.name} size={compact ? "sm" : "md"} />
        <div className="min-w-0 flex-1">
          <div className="flex items-baseline justify-between gap-3">
            <p className="flex min-w-0 items-center gap-1.5 text-sm font-semibold text-slate-900">
              <span className="truncate">{who.name}</span>
              {email.direction === "out" ? <span className="shrink-0 rounded bg-slate-100 px-1.5 py-px text-[10px] font-medium uppercase tracking-wide text-slate-500">Team</span> : null}
            </p>
            <time className="shrink-0 text-xs text-slate-500" dateTime={email.sent_at}>
              {formatDateTime(email.sent_at)}
            </time>
          </div>
          <p className="truncate text-xs text-slate-500">
            {who.address} <span className="text-slate-400">to {who.to}</span>
          </p>
        </div>
      </header>
      <div className={compact ? "mt-2.5" : "mt-3 pl-11"}>
        {email.html ? (
          // Cleaned on the server to bold, italic, underline, lists and links: nothing that can run
          <div className="email-body text-sm leading-relaxed text-slate-700" dangerouslySetInnerHTML={{ __html: email.html }} />
        ) : text ? (
          <p className="whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700">{text}</p>
        ) : null}
        {quoted && !email.html ? (
          <>
            <button
              type="button"
              onClick={() => setShowQuoted((v) => !v)}
              className="mt-2 inline-flex h-5 items-center rounded-md bg-slate-100 px-1.5 text-slate-500 transition hover:bg-slate-200 hover:text-slate-700"
              aria-expanded={showQuoted}
              aria-label={showQuoted ? "Hide earlier emails" : "Show earlier emails"}
              title={showQuoted ? "Hide earlier emails" : "Show earlier emails"}
            >
              <MoreHorizontal className="h-4 w-4" />
            </button>
            {showQuoted ? <p className="mt-2 whitespace-pre-wrap break-words border-l-2 border-slate-200 pl-3 text-xs leading-relaxed text-slate-500">{quoted}</p> : null}
          </>
        ) : null}
        {email.attachments.length ? <Attachments files={email.attachments} /> : null}
      </div>
    </article>
  );
}

const loadComposer = () => import("./email-composer");
const LazyComposer = lazy(loadComposer);

/**
 * Writing to a lead from the team's mailbox, with formatting and attachments. The editor is fetched the
 * first time someone writes, so pages that only show emails stay light.
 */
export function EmailComposer(props: {
  leadId: number;
  leadName: string;
  mailbox: string;
  replyTo: LeadEmailRow | null;
  onSent: () => void;
  onCancel: () => void;
  compact?: boolean;
}) {
  return (
    <Suspense fallback={<div className={props.compact ? "p-4" : "px-5 py-4"}><div className="skeleton h-[230px] rounded-xl" /></div>}>
      <LazyComposer {...props} />
    </Suspense>
  );
}

/** Files sent with an email: pictures and PDFs open in a new tab, anything else downloads */
function Attachments({ files }: { files: EmailAttachment[] }) {
  return (
    <ul className="mt-3 flex flex-wrap gap-2" aria-label="Attachments">
      {files.map((f) => {
        const view = viewableFile(f.mime);
        const Icon = f.mime.startsWith("image/") ? ImageIcon : f.mime === "application/pdf" ? FileText : File;
        return (
          <li key={f.id} className="max-w-full">
            <a
              href={f.url}
              {...(view ? { target: "_blank", rel: "noopener" } : { download: f.name })}
              className="group flex max-w-full items-center gap-2.5 rounded-lg border border-slate-200 bg-white py-2 pl-2.5 pr-3 transition hover:border-slate-300 hover:bg-slate-50"
              title={view ? `Open ${f.name}` : `Download ${f.name}`}
            >
              <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500 group-hover:bg-white">
                <Icon className="h-4 w-4" />
              </span>
              <span className="min-w-0">
                <span className="block max-w-[15rem] truncate text-xs font-medium text-slate-800">{f.name}</span>
                <span className="block text-[11px] text-slate-500">{fileSize(f.size)}</span>
              </span>
              <Download className="h-3.5 w-3.5 shrink-0 text-slate-400 opacity-0 transition group-hover:opacity-100" />
            </a>
          </li>
        );
      })}
    </ul>
  );
}

/** The closed reply box under a conversation: one click opens the composer */
export function ReplyPrompt({ leadName, onClick, compact = false }: { leadName: string; onClick: () => void; compact?: boolean }) {
  // Fetched in the background while the conversation is read, so the editor opens at once
  useEffect(() => {
    const timer = window.setTimeout(() => void loadComposer().catch(() => {}), 1500);
    return () => window.clearTimeout(timer);
  }, []);
  return (
    <div className={compact ? "p-4 pt-3" : "px-5 py-4"}>
      <button
        type="button"
        onClick={onClick}
        className="flex w-full items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-left text-sm text-slate-400 shadow-sm transition hover:border-slate-300 hover:text-slate-500"
      >
        <Send className="h-3.5 w-3.5" />
        Reply to {leadName}…
      </button>
    </div>
  );
}
