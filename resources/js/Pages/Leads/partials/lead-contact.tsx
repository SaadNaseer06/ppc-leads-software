import { FormEvent, useState } from "react";
import { router } from "@inertiajs/react";
import { ArrowDownLeft, ArrowUpRight, Eye, Mail, Phone, Reply, Send } from "lucide-react";
import type { LeadEmailRow } from "@/lib/api-types";
import { formatDateTime } from "@/lib/format";
import { CopyButton } from "@/components/ui/copy-button";
import { apiRequest, useToast } from "@/components/ui/toast";

type Contact = { email: string | null; phone: string | null };

/**
 * A lead's email and phone. Sales reps see them masked and reveal them one lead at a time:
 * each reveal is logged for managers and counts towards a daily limit.
 */
export function ContactCard({ leadId, initial, masked, company }: { leadId: number; initial: Contact; masked: boolean; company: string | null }) {
  const toast = useToast();
  const [contact, setContact] = useState<Contact>(initial);
  const [revealed, setRevealed] = useState(!masked);
  const [busy, setBusy] = useState(false);

  async function reveal() {
    setBusy(true);
    const res = await apiRequest<Contact>(`/api/leads/${leadId}/reveal`, { method: "POST", body: {} });
    setBusy(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    setContact(res.data);
    setRevealed(true);
  }

  return (
    <div className="space-y-3 p-4 text-sm">
      <Row icon={<Mail className="h-4 w-4" />} value={contact.email} href={revealed && contact.email ? `mailto:${contact.email}` : null} copy={revealed} />
      <Row icon={<Phone className="h-4 w-4" />} value={contact.phone} href={revealed && contact.phone ? `tel:${contact.phone}` : null} copy={revealed} />
      {company ? <p className="text-slate-700">{company}</p> : null}
      {!revealed && (initial.email || initial.phone) ? (
        <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
          <button type="button" className="btn-secondary btn-sm" onClick={() => void reveal()} disabled={busy}>
            <Eye className="h-3.5 w-3.5" />
            {busy ? "Revealing…" : "Reveal contact"}
          </button>
          <span className="text-xs text-slate-500">Logged for your manager. Email them from this page without revealing.</span>
        </div>
      ) : null}
    </div>
  );
}

function Row({ icon, value, href, copy }: { icon: React.ReactNode; value: string | null; href: string | null; copy: boolean }) {
  if (!value) {
    return (
      <p className="flex items-center gap-2 text-slate-400">
        {icon}
        Not provided
      </p>
    );
  }
  const text = (
    <>
      <span className="text-slate-400">{icon}</span>
      <span className="truncate">{value}</span>
    </>
  );
  return (
    <div className="flex items-center justify-between gap-2">
      {href ? (
        <a href={href} className="flex min-w-0 items-center gap-2 font-medium text-teal-700 hover:underline">
          {text}
        </a>
      ) : (
        <span className="flex min-w-0 items-center gap-2 font-medium text-slate-700">{text}</span>
      )}
      {copy ? <CopyButton value={value} /> : null}
    </div>
  );
}

/** Emails with the lead, sent from and received into the brand's shared mailbox */
export function EmailsCard({
  leadId,
  emails,
  mailbox,
  hasEmail,
  canSend,
}: {
  leadId: number;
  emails: LeadEmailRow[];
  mailbox: string | null;
  hasEmail: boolean;
  canSend: boolean;
}) {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [replyTo, setReplyTo] = useState<LeadEmailRow | null>(null);
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");
  const [expanded, setExpanded] = useState<number | null>(emails[0]?.id ?? null);

  function compose(reply: LeadEmailRow | null) {
    setReplyTo(reply);
    setSubject(reply ? (/^re:/i.test(reply.subject) ? reply.subject : `Re: ${reply.subject}`) : "");
    setBody("");
    setError("");
    setOpen(true);
  }

  async function send(e: FormEvent) {
    e.preventDefault();
    setSending(true);
    setError("");
    const res = await apiRequest(`/api/leads/${leadId}/emails`, { method: "POST", body: { subject, body, reply_to_id: replyTo?.id ?? null } });
    setSending(false);
    if (!res.ok) {
      setError(res.error);
      return;
    }
    toast.success("Email sent");
    setOpen(false);
    router.reload();
  }

  const blocked = !mailbox ? "You don't have a mailbox on this brand yet. A Super Admin can connect one on the Email page." : !hasEmail ? "This lead has no email address." : null;

  return (
    <section className="card">
      <div className="card-header">
        <h2 className="card-title">Emails</h2>
        {canSend && !blocked && !open ? (
          <button type="button" className="btn-secondary btn-sm" onClick={() => compose(null)}>
            <Send className="h-3.5 w-3.5" />
            New email
          </button>
        ) : null}
      </div>

      {open ? (
        <form onSubmit={send} className="space-y-3 border-b border-slate-100 p-4">
          <p className="text-xs text-slate-500">
            From <span className="font-medium text-slate-700">{mailbox}</span>
            {replyTo ? <> · replying to “{replyTo.subject}”</> : null}
          </p>
          <input className="input" placeholder="Subject" value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={200} required aria-label="Subject" autoFocus={!replyTo} />
          <textarea className="input" rows={6} placeholder="Write your message…" value={body} onChange={(e) => setBody(e.target.value)} maxLength={20000} required aria-label="Message" autoFocus={Boolean(replyTo)} />
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary btn-sm" onClick={() => setOpen(false)}>
              Cancel
            </button>
            <button type="submit" className="btn-primary btn-sm" disabled={sending}>
              <Send className="h-3.5 w-3.5" />
              {sending ? "Sending…" : "Send"}
            </button>
          </div>
        </form>
      ) : null}

      {emails.length === 0 ? (
        <p className="p-4 text-sm text-slate-500">{blocked ?? "No emails yet. Replies from the client show up here automatically."}</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {emails.map((email) => {
            const inbound = email.direction === "in";
            const isOpen = expanded === email.id;
            return (
              <li key={email.id} className="px-4 py-3">
                <button type="button" className="flex w-full items-start gap-3 text-left" onClick={() => setExpanded(isOpen ? null : email.id)} aria-expanded={isOpen}>
                  <span className={`mt-0.5 rounded-full p-1 ${inbound ? "bg-teal-50 text-teal-700" : "bg-slate-100 text-slate-500"}`}>
                    {inbound ? <ArrowDownLeft className="h-3.5 w-3.5" /> : <ArrowUpRight className="h-3.5 w-3.5" />}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium text-slate-900">{email.subject}</span>
                    <span className="block truncate text-xs text-slate-500">
                      {inbound ? `From ${email.from_name || email.from_email}` : `Sent by ${email.sender ?? email.from_name ?? "the team"}`} · {formatDateTime(email.sent_at)}
                    </span>
                  </span>
                </button>
                {isOpen ? (
                  <div className="mt-2 pl-9">
                    <p className="whitespace-pre-wrap break-words rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{email.body}</p>
                    {inbound && canSend && !blocked ? (
                      <button type="button" className="btn-ghost btn-sm mt-1" onClick={() => compose(email)}>
                        <Reply className="h-3.5 w-3.5" />
                        Reply
                      </button>
                    ) : null}
                  </div>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
