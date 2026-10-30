import { FormEvent, useCallback, useEffect, useRef, useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import { ArrowLeft, ExternalLink, MessageSquare, MessageSquareOff, Trash2, TriangleAlert } from "lucide-react";
import type { Assignee, ChatMessage, ChatsData, ConversationRow } from "@/lib/api-types";
import type { SharedProps } from "@/types";
import { formatDateTime, formatRelative, formatTime } from "@/lib/format";
import { PageHeader, EmptyState } from "@/components/ui/page-header";
import { StatusBadge } from "@/components/ui/badges";
import { Avatar } from "@/components/ui/avatar";
import { useConfirm } from "@/components/ui/confirm";
import { apiHeaders, apiRequest, useToast } from "@/components/ui/toast";
import { useLiveSync } from "@/components/app-shell/live-provider";
import { getEcho } from "@/lib/echo";

const FILTERS = [
  { key: "all", label: "All" },
  { key: "chat", label: "Live chats" },
  { key: "ticket", label: "Offline messages" },
] as const;

const POLL_MS = 3000;

type SelectedChat = NonNullable<ChatsData["selected"]>;

export default function Chats({ chats: initial, query: params }: { chats: ChatsData; query: Record<string, string | undefined> }) {
  const user = usePage<SharedProps>().props.auth.user!;
  const kind = params.kind === "chat" || params.kind === "ticket" ? params.kind : undefined;
  const toast = useToast();
  const confirm = useConfirm();
  const syncLive = useLiveSync();

  const [list, setList] = useState<ConversationRow[]>(initial.conversations);
  const [hasMore, setHasMore] = useState(initial.has_more);
  const [selected, setSelected] = useState<SelectedChat | null>(initial.selected);
  const [assignees, setAssignees] = useState<Assignee[]>(initial.assignees ?? []);
  const [unlinked, setUnlinked] = useState(initial.unlinked);
  const [pusher, setPusher] = useState(initial.pusher);
  const [messages, setMessages] = useState<ChatMessage[]>(initial.selected?.messages ?? []);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
  const [ending, setEnding] = useState(false);
  const [assigning, setAssigning] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);
  const selectedIdRef = useRef<number | null>(initial.selected?.id ?? null);
  const [now, setNow] = useState(() => new Date());

  selectedIdRef.current = selected?.id ?? null;

  const applyPayload = useCallback((data: ChatsData, opts?: { replaceMessages?: boolean }) => {
    setList(data.conversations);
    setHasMore(data.has_more);
    setUnlinked(data.unlinked);
    setAssignees(data.assignees ?? []);
    if (data.pusher) setPusher(data.pusher);

    const next = data.selected;
    setSelected(next);
    if (!next) {
      setMessages([]);
      return;
    }
    setMessages((prev) => {
      if (opts?.replaceMessages) return next.messages;
      const overlap = prev.some((m) => next.messages.some((n) => n.id === m.id));
      if (!overlap) return next.messages;
      const byId = new Map<number, ChatMessage>();
      for (const m of prev) byId.set(m.id, m);
      for (const m of next.messages) byId.set(m.id, m);
      return [...byId.values()].sort((a, b) => a.id - b.id);
    });
  }, []);

  // Inertia navigation (filter / select another chat) replaces the page props
  useEffect(() => {
    applyPayload(initial, { replaceMessages: true });
    setDraft("");
  }, [initial, applyPayload]);

  const fetchChats = useCallback(async () => {
    if (document.visibilityState !== "visible") return;
    const q = new URLSearchParams();
    if (kind) q.set("kind", kind);
    if (params.id) q.set("id", params.id);
    if (params.before) q.set("before", params.before);
    try {
      const res = await fetch(`/api/chats?${q}`, {
        headers: { ...apiHeaders(false), Accept: "application/json" },
        credentials: "same-origin",
      });
      if (res.status === 401) {
        window.location.assign("/login");
        return;
      }
      if (!res.ok) return;
      const data = (await res.json()) as ChatsData;
      applyPayload(data);
      setNow(new Date());
    } catch {
      // keep last good snapshot
    }
  }, [kind, params.id, params.before, applyPayload]);

  useEffect(() => {
    const timer = setInterval(() => void fetchChats(), POLL_MS);
    const onVisible = () => {
      if (document.visibilityState === "visible") void fetchChats();
    };
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      clearInterval(timer);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [fetchChats]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages.length]);

  // Instant message append via Pusher when configured
  useEffect(() => {
    if (!selected?.id || !pusher?.key) return;
    const echo = getEcho(pusher.key, pusher.cluster);
    if (!echo) return;

    const channelName = `chat.${selected.id}`;
    const channel = echo.private(channelName);
    const handler = (payload: { message?: ChatMessage }) => {
      if (!payload?.message) return;
      setMessages((prev) => (prev.some((m) => m.id === payload.message!.id) ? prev : [...prev, payload.message!]));
      void fetchChats();
    };
    channel.listen(".ChatMessageSent", handler);

    return () => {
      channel.stopListening(".ChatMessageSent", handler);
      echo.leave(channelName);
    };
  }, [selected?.id, pusher?.key, pusher?.cluster, fetchChats]);

  // Brand inbox channel: refresh list when any chat on the user's brands gets a message
  useEffect(() => {
    if (!pusher?.key || user.brand_ids.length === 0) return;
    const echo = getEcho(pusher.key, pusher.cluster);
    if (!echo) return;

    const brandIds = user.brand_ids;
    const handler = () => void fetchChats();
    for (const brandId of brandIds) {
      echo.private(`chats.${brandId}`).listen(".ChatMessageSent", handler);
    }
    return () => {
      for (const brandId of brandIds) {
        echo.leave(`chats.${brandId}`);
      }
    };
  }, [pusher?.key, pusher?.cluster, user.brand_ids, fetchChats]);

  const query = (extra: Record<string, string | undefined>) => {
    const q = new URLSearchParams();
    for (const [k, v] of Object.entries({ kind, ...extra })) if (v) q.set(k, v);
    return q.toString() ? `/chats?${q}` : "/chats";
  };

  async function sendReply(e: FormEvent) {
    e.preventDefault();
    if (!selected || !draft.trim() || sending) return;
    setSending(true);
    const res = await apiRequest<{ message: ChatMessage }>(`/api/chats/${selected.id}/messages`, {
      method: "POST",
      body: { body: draft.trim() },
    });
    setSending(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    setMessages((prev) => (prev.some((m) => m.id === res.data.message.id) ? prev : [...prev, res.data.message]));
    setDraft("");
    void fetchChats();
  }

  async function endChat() {
    if (!selected || ending) return;
    const ok = await confirm({
      title: "End this chat?",
      description: "The visitor will no longer be able to send messages in this conversation.",
      confirmLabel: "End chat",
      tone: "danger",
    });
    if (!ok) return;
    setEnding(true);
    const res = await apiRequest(`/api/chats/${selected.id}/end`, { method: "POST" });
    setEnding(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    toast.success("Chat ended");
    void fetchChats();
  }

  async function assignLead(assigneeId: number | null) {
    if (!selected?.lead || assigning) return;
    setAssigning(true);
    const name = assignees.find((a) => a.id === assigneeId)?.name;
    const res = await apiRequest(`/api/leads/${selected.lead.id}`, {
      method: "PATCH",
      body: { assignee_id: assigneeId },
    });
    setAssigning(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    toast.success(assigneeId ? `Assigned to ${name}` : "Lead unassigned");
    syncLive();
    void fetchChats();
  }

  async function deleteLead() {
    if (!selected?.lead || deleting) return;
    const ok = await confirm({
      title: `Delete lead “${selected.lead.name}”?`,
      description: "This cannot be undone. The chat transcript stays as history.",
      confirmLabel: "Delete lead",
      tone: "danger",
    });
    if (!ok) return;
    setDeleting(true);
    const res = await apiRequest(`/api/leads/${selected.lead.id}`, { method: "DELETE" });
    setDeleting(false);
    if (!res.ok) {
      toast.error(res.error);
      return;
    }
    toast.success("Lead deleted");
    syncLive();
    void fetchChats();
  }

  const canReply = Boolean(selected?.can_reply);
  const isNative = selected?.provider === "native";
  const canAssign = user.can.assign_leads && Boolean(selected?.lead);
  const canDelete = user.can.delete_leads && Boolean(selected?.lead);

  return (
    <div className="space-y-5">
      <Head title="Chats" />
      <PageHeader
        title="Chats"
        description="Live chats from your website widget. Reply here — older Tawk.to threads stay as read-only history."
      />

      {unlinked > 0 ? (
        <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
          <p>
            {unlinked} conversation{unlinked === 1 ? "" : "s"} came from a Tawk.to property that isn&apos;t linked to a
            website, so no leads were created for {unlinked === 1 ? "it" : "them"}.{" "}
            <Link href="/websites" className="font-medium underline">
              Link the property on the Websites page
            </Link>{" "}
            and they&apos;ll be imported.
          </p>
        </div>
      ) : null}

      <div className="flex gap-1 border-b border-slate-200">
        {FILTERS.map((f) => {
          const active = (kind ?? "all") === f.key;
          return (
            <Link
              key={f.key}
              href={f.key === "all" ? "/chats" : `/chats?kind=${f.key}`}
              className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${
                active ? "border-teal-600 text-teal-700" : "border-transparent text-slate-500 hover:text-slate-700"
              }`}
            >
              {f.label}
            </Link>
          );
        })}
      </div>

      {list.length === 0 ? (
        <div className="card">
          <EmptyState
            icon={<MessageSquareOff className="h-5 w-5" />}
            title="No conversations yet"
            description="Add the chat widget snippet on a website. Visitor messages appear here so you can reply from this dashboard."
            action={
              <Link href="/websites" className="btn-secondary">
                Set up chat widget
              </Link>
            }
          />
        </div>
      ) : (
        <div className="card grid overflow-hidden lg:h-[calc(100vh-260px)] lg:min-h-[520px] lg:grid-cols-[340px_1fr]">
          <div className={`flex min-h-0 flex-col border-slate-200 lg:border-r ${params.id ? "hidden lg:flex" : ""}`}>
            <ul className="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
              {list.map((c) => {
                const active = c.id === selected?.id;
                return (
                  <li key={c.id}>
                    <Link
                      href={query({ id: String(c.id), before: params.before })}
                      className={`flex gap-3 px-4 py-3 transition ${active ? "bg-teal-50/70" : "hover:bg-slate-50"}`}
                    >
                      <Avatar name={c.display_name} />
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                          <p className="truncate text-sm font-medium text-slate-900">{c.display_name}</p>
                          <span className="shrink-0 text-xs text-slate-400">{formatRelative(c.last_message_at, now)}</span>
                        </div>
                        <p className="truncate text-xs text-slate-500">
                          {c.website?.name ?? c.property_name ?? "Unlinked property"}
                          {c.kind === "ticket" ? " · Offline message" : ""}
                          {c.provider === "tawk" ? " · Tawk" : ""}
                        </p>
                        <p className="mt-0.5 line-clamp-1 text-sm text-slate-600">{c.last_message ?? "—"}</p>
                      </div>
                      {c.status === "open" && c.kind === "chat" ? (
                        <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-emerald-500" title="In progress" />
                      ) : null}
                    </Link>
                  </li>
                );
              })}
            </ul>
            {hasMore ? (
              <div className="border-t border-slate-100 p-2 text-center">
                <Link className="btn-ghost btn-sm" href={query({ before: list[list.length - 1].last_message_at })}>
                  Load older conversations
                </Link>
              </div>
            ) : null}
          </div>

          <div className={`flex min-h-0 flex-col ${params.id ? "" : "hidden lg:flex"}`}>
            {selected ? (
              <>
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
                  <div className="flex min-w-0 items-center gap-3">
                    <Link href={query({ before: params.before })} className="btn-ghost p-1.5 lg:hidden" aria-label="Back">
                      <ArrowLeft className="h-4 w-4" />
                    </Link>
                    <div className="min-w-0">
                      <p className="truncate font-medium text-slate-900">{selected.display_name}</p>
                      <p className="truncate text-xs text-slate-500">
                        {[selected.visitor_email, [selected.city, selected.country].filter(Boolean).join(", ")]
                          .filter(Boolean)
                          .join(" · ") || "No contact details shared"}
                        {" · "}
                        {formatDateTime(selected.started_at)}
                      </p>
                    </div>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    {isNative && selected.status === "open" ? (
                      <button type="button" className="btn-ghost btn-sm" onClick={endChat} disabled={ending}>
                        {ending ? "Ending…" : "End chat"}
                      </button>
                    ) : null}
                    {canDelete ? (
                      <button type="button" className="btn-ghost btn-sm text-red-600 hover:text-red-700" onClick={deleteLead} disabled={deleting}>
                        <Trash2 className="h-3.5 w-3.5" />
                        {deleting ? "Deleting…" : "Delete lead"}
                      </button>
                    ) : null}
                    {selected.lead ? (
                      <Link href={`/leads/${selected.lead.id}`} className="btn-secondary btn-sm">
                        <ExternalLink className="h-3.5 w-3.5" />
                        Open lead
                      </Link>
                    ) : null}
                  </div>
                </div>

                {selected.lead ? (
                  <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-slate-100 bg-slate-50/70 px-5 py-2 text-xs text-slate-600">
                    <span>
                      Lead: <span className="font-medium text-slate-800">{selected.lead.name}</span>
                    </span>
                    <StatusBadge status={selected.lead.status} />
                    {canAssign ? (
                      <label className="flex items-center gap-2">
                        <span className="text-slate-500">Assign</span>
                        <select
                          className="input h-8 min-w-[200px] py-1 text-xs"
                          value={selected.lead.assignee_id ?? ""}
                          disabled={assigning}
                          onChange={(e) => void assignLead(e.target.value ? Number(e.target.value) : null)}
                        >
                          <option value="">Unassigned</option>
                          {selected.lead.assignee_id && !assignees.some((a) => a.id === selected.lead!.assignee_id) ? (
                            <option value={selected.lead.assignee_id}>{selected.lead.assignee ?? "Current assignee"}</option>
                          ) : null}
                          {assignees.map((a) => (
                            <option key={a.id} value={a.id}>
                              {a.name} · {a.role_label} · {a.open_leads.toLocaleString("en-US")} open
                            </option>
                          ))}
                        </select>
                      </label>
                    ) : (
                      <span>
                        Assigned to{" "}
                        <span className="font-medium text-slate-800">{selected.lead.assignee ?? "nobody yet"}</span>
                      </span>
                    )}
                  </div>
                ) : (
                  <div className="border-b border-slate-100 bg-amber-50/60 px-5 py-2 text-xs text-amber-900">
                    {selected.website_id
                      ? "No lead yet — one is created as soon as the visitor sends a message."
                      : "This conversation isn't linked to a website yet, so no lead was created."}
                  </div>
                )}

                {selected.provider === "tawk" ? (
                  <div className="border-b border-slate-100 bg-slate-50 px-5 py-2 text-xs text-slate-600">
                    Read-only Tawk.to history. New chats use the PPC Leads widget — reply here for those.
                  </div>
                ) : null}

                <div className="min-h-0 flex-1 space-y-3 overflow-y-auto bg-slate-50/60 p-5">
                  {selected.subject ? (
                    <p className="text-center text-xs font-medium text-slate-500">Subject: {selected.subject}</p>
                  ) : null}
                  {messages.length === 0 ? (
                    <p className="text-center text-sm text-slate-500">
                      {isNative ? "Waiting for the visitor to send a message." : "No messages in this transcript yet."}
                    </p>
                  ) : (
                    messages.map((m) =>
                      m.sender_type === "system" ? (
                        <p key={m.id} className="text-center text-xs text-slate-400">
                          {m.body}
                        </p>
                      ) : (
                        <div key={m.id} className={`flex ${m.sender_type === "visitor" ? "justify-start" : "justify-end"}`}>
                          <div className="max-w-[75%]">
                            <div
                              className={`whitespace-pre-wrap break-words rounded-2xl px-3.5 py-2 text-sm ${
                                m.sender_type === "visitor"
                                  ? "rounded-bl-sm border border-slate-200 bg-white text-slate-800"
                                  : "rounded-br-sm bg-teal-700 text-white"
                              }`}
                            >
                              {m.body}
                            </div>
                            <p className={`mt-1 text-[11px] text-slate-400 ${m.sender_type === "visitor" ? "" : "text-right"}`}>
                              {m.sender_type === "visitor" ? selected.display_name : m.sender_name ?? "Agent"} ·{" "}
                              {formatTime(m.sent_at)}
                            </p>
                          </div>
                        </div>
                      )
                    )
                  )}
                  <div ref={bottomRef} />
                </div>

                {canReply ? (
                  <form onSubmit={sendReply} className="flex items-end gap-2 border-t border-slate-200 bg-white px-4 py-3">
                    <textarea
                      className="input min-h-[44px] flex-1 resize-none"
                      rows={2}
                      placeholder="Type a reply…"
                      value={draft}
                      onChange={(e) => setDraft(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === "Enter" && !e.shiftKey) {
                          e.preventDefault();
                          void sendReply(e as unknown as FormEvent);
                        }
                      }}
                    />
                    <button type="submit" className="btn-primary" disabled={sending || !draft.trim()}>
                      {sending ? "Sending…" : "Send"}
                    </button>
                  </form>
                ) : (
                  <div className="flex items-center justify-between gap-3 border-t border-slate-200 px-5 py-2.5 text-xs text-slate-500">
                    <span className="flex items-center gap-1.5">
                      <MessageSquare className="h-3.5 w-3.5" />
                      {selected.message_count} message{selected.message_count === 1 ? "" : "s"}
                      {selected.provider === "tawk"
                        ? " · Reply from your Tawk.to dashboard"
                        : selected.status === "ended"
                          ? " · Chat ended"
                          : ""}
                    </span>
                    {selected.provider === "tawk" ? (
                      <a
                        href="https://dashboard.tawk.to/"
                        target="_blank"
                        rel="noreferrer noopener"
                        className="font-medium text-teal-700 hover:underline"
                      >
                        Open Tawk.to
                      </a>
                    ) : null}
                  </div>
                )}
              </>
            ) : (
              <EmptyState title="Select a conversation" />
            )}
          </div>
        </div>
      )}
    </div>
  );
}
