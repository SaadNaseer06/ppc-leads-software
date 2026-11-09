import { useState } from "react";
import { Link, router } from "@inertiajs/react";
import { Eye, Mail, Maximize2, PenSquare, Phone } from "lucide-react";
import type { LeadEmailRow } from "@/lib/api-types";
import { EmailComposer, EmailThread, ReplyPrompt } from "@/components/email/email-thread";
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

/**
 * The lead's email conversation, as in a mail app: the latest emails with the newest open, a reply box
 * underneath, and the whole conversation one click away on the Emails page.
 */
export function EmailsCard({
  leadId,
  leadName,
  emails,
  mailbox,
  hasEmail,
  canSend,
}: {
  leadId: number;
  leadName: string;
  /** Newest first */
  emails: LeadEmailRow[];
  mailbox: string | null;
  hasEmail: boolean;
  canSend: boolean;
}) {
  const [composing, setComposing] = useState<"new" | "reply" | null>(null);
  const thread = [...emails].reverse();
  const shown = thread.slice(-3);
  const earlier = thread.length - shown.length;
  const latest = thread[thread.length - 1] ?? null;
  const blocked = !mailbox ? "You don't have a mailbox on this brand yet. A Super Admin can create one on the Mailboxes page." : !hasEmail ? "This lead has no email address." : null;
  const ready = canSend && !blocked && mailbox;

  function sent() {
    setComposing(null);
    router.reload({ only: ["emails", "activities", "lead"] });
  }

  return (
    <section className="card overflow-hidden">
      <div className="card-header">
        <h2 className="card-title flex items-center gap-2">
          Emails
          {emails.length ? <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{emails.length}</span> : null}
        </h2>
        <div className="flex items-center gap-1">
          {emails.length ? (
            <Link href={`/emails?id=${leadId}`} className="btn-ghost btn-sm" title="Open the whole conversation">
              <Maximize2 className="h-3.5 w-3.5" />
              <span className="sr-only sm:not-sr-only">Open</span>
            </Link>
          ) : null}
          {ready && composing === null ? (
            <button type="button" className="btn-secondary btn-sm" onClick={() => setComposing("new")}>
              <PenSquare className="h-3.5 w-3.5" />
              New email
            </button>
          ) : null}
        </div>
      </div>

      {composing === "new" && mailbox ? (
        <div className="border-b border-slate-100 bg-slate-50/50">
          <EmailComposer compact leadId={leadId} leadName={leadName} mailbox={mailbox} replyTo={null} onSent={sent} onCancel={() => setComposing(null)} />
        </div>
      ) : null}

      {emails.length === 0 ? (
        composing ? null : (
          <div className="flex flex-col items-center px-6 py-7 text-center">
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-500">
              <Mail className="h-5 w-5" />
            </span>
            <p className="mt-3 text-sm font-medium text-slate-800">No emails yet</p>
            <p className="mt-1 max-w-xs text-xs leading-relaxed text-slate-500">
              {blocked ?? (canSend ? "Send the first one from your brand address. The client's replies show up here." : "Emails with this lead show up here.")}
            </p>
          </div>
        )
      ) : (
        <>
          {earlier > 0 ? (
            <Link href={`/emails?id=${leadId}`} className="block border-b border-slate-100 bg-slate-50/60 px-4 py-2 text-xs font-medium text-teal-700 hover:bg-slate-100/70">
              {earlier} earlier email{earlier === 1 ? "" : "s"} · see the whole conversation
            </Link>
          ) : null}
          <EmailThread emails={shown} leadName={leadName} compact />
          {ready && latest ? (
            <div className="border-t border-slate-100">
              {composing === "reply" ? (
                <EmailComposer compact leadId={leadId} leadName={leadName} mailbox={mailbox} replyTo={latest} onSent={sent} onCancel={() => setComposing(null)} />
              ) : composing === null ? (
                <ReplyPrompt compact leadName={leadName} onClick={() => setComposing("reply")} />
              ) : null}
            </div>
          ) : blocked && canSend ? (
            <p className="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">{blocked}</p>
          ) : null}
        </>
      )}
    </section>
  );
}
