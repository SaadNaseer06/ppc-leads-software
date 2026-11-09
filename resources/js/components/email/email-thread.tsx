import { FormEvent, KeyboardEvent, useEffect, useRef, useState } from "react";
import { MoreHorizontal, Send } from "lucide-react";
import type { LeadEmailRow } from "@/lib/api-types";
import { Avatar } from "@/components/ui/avatar";
import { apiRequest, useToast } from "@/components/ui/toast";
import { formatDate, formatDateTime, formatTime } from "@/lib/format";
import { emailSnippet, replySubject, splitQuoted } from "@/lib/email";

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
                <span className="min-w-0 flex-1 truncate text-sm text-slate-500">{emailSnippet(email.body)}</span>
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
        {text ? <p className="whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700">{text}</p> : null}
        {quoted ? (
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
      </div>
    </article>
  );
}

/**
 * Writing to a lead from the team's mailbox. A reply keeps the conversation's subject and threads under
 * the email it answers, so it lands in the same conversation in the client's mail app.
 */
export function EmailComposer({
  leadId,
  leadName,
  mailbox,
  replyTo,
  onSent,
  onCancel,
  compact = false,
}: {
  leadId: number;
  leadName: string;
  mailbox: string;
  replyTo: LeadEmailRow | null;
  onSent: () => void;
  onCancel: () => void;
  compact?: boolean;
}) {
  const toast = useToast();
  const [subject, setSubject] = useState(replyTo ? replySubject(replyTo.subject) : "");
  const [body, setBody] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");
  const area = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (replyTo) area.current?.focus();
  }, [replyTo]);

  // Grows with the message, up to a point
  useEffect(() => {
    const el = area.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.min(el.scrollHeight, 360)}px`;
  }, [body]);

  async function send(e?: FormEvent) {
    e?.preventDefault();
    if (sending) return;
    if (!subject.trim() || !body.trim()) {
      setError(!subject.trim() ? "Add a subject" : "Write a message");
      return;
    }
    setSending(true);
    setError("");
    const res = await apiRequest(`/api/leads/${leadId}/emails`, { method: "POST", body: { subject: subject.trim(), body, reply_to_id: replyTo?.id ?? null } });
    setSending(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success(`Email sent to ${leadName}`);
    setBody("");
    onSent();
  }

  function onKeyDown(e: KeyboardEvent) {
    if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      void send();
    }
    if (e.key === "Escape" && !body) onCancel();
  }

  return (
    <form onSubmit={send} className={compact ? "p-4" : "px-5 py-4"} onKeyDown={onKeyDown}>
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition focus-within:border-teal-500 focus-within:ring-2 focus-within:ring-teal-500/15">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 border-b border-slate-100 px-3.5 py-2 text-xs text-slate-500">
          <span>
            From <span className="font-medium text-slate-700">{mailbox}</span>
          </span>
          <span>
            To <span className="font-medium text-slate-700">{leadName}</span>
          </span>
        </div>
        {replyTo ? (
          <p className="truncate border-b border-slate-100 px-3.5 py-2 text-xs text-slate-500" title={subject}>
            {subject}
          </p>
        ) : (
          <input
            className="block w-full border-0 border-b border-slate-100 px-3.5 py-2.5 text-sm font-medium text-slate-900 placeholder:font-normal placeholder:text-slate-400 focus:outline-none focus:ring-0"
            placeholder="Subject"
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
            maxLength={200}
            aria-label="Subject"
            autoFocus
          />
        )}
        <textarea
          ref={area}
          className="block min-h-[120px] w-full resize-none border-0 px-3.5 py-3 text-sm leading-relaxed text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0"
          placeholder={replyTo ? `Reply to ${leadName}…` : `Write to ${leadName}…`}
          value={body}
          onChange={(e) => setBody(e.target.value)}
          maxLength={20000}
          aria-label="Message"
        />
        <div className="flex items-center gap-2 border-t border-slate-100 bg-slate-50/60 px-3 py-2">
          <span className="hidden text-[11px] text-slate-400 sm:inline">Ctrl + Enter to send</span>
          <div className="ml-auto flex gap-2">
            <button type="button" className="btn-ghost btn-sm" onClick={onCancel}>
              Discard
            </button>
            <button type="submit" className="btn-primary btn-sm" disabled={sending}>
              <Send className="h-3.5 w-3.5" />
              {sending ? "Sending…" : "Send"}
            </button>
          </div>
        </div>
      </div>
      {error ? <p className="mt-2 text-sm text-red-600">{error}</p> : null}
    </form>
  );
}

/** The closed reply box under a conversation: one click opens the composer */
export function ReplyPrompt({ leadName, onClick, compact = false }: { leadName: string; onClick: () => void; compact?: boolean }) {
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
