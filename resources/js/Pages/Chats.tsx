import { FormEvent, useCallback, useEffect, useRef, useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import {
  ArrowLeft,
  CircleDot,
  ExternalLink,
  Globe2,
  Mail,
  MapPin,
  MessageSquare,
  MessageSquareOff,
  Trash2,
  TriangleAlert,
} from "lucide-react";
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

// Without realtime the inbox polls every 5s. With Pusher connected, events trigger the
// refreshes and polling is only a slow safety net (sales reps have no inbox channel, so
// theirs is shorter): keeps the server quiet on shared hosting with many agents online.
const POLL_MS = 5_000;
const SAFETY_POLL_MS = 30_000;
const REP_SAFETY_POLL_MS = 10_000;

type SelectedChat = NonNullable<ChatsData["selected"]>;

function sameDay(a: string, b: string) {
  return new Date(a).toDateString() === new Date(b).toDateString();
}

function dayLabel(iso: string, now: Date) {
  const d = new Date(iso);
  const today = now.toDateString();
  const yesterday = new Date(now);
  yesterday.setDate(yesterday.getDate() - 1);
  if (d.toDateString() === today) return "Today";
  if (d.toDateString() === yesterday.toDateString()) return "Yesterday";
  return formatDateTime(iso).split(",")[0] ?? formatDateTime(iso);
}

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
  const [openCount, setOpenCount] = useState(initial.open_count ?? 0);
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
  const lastFetch = useRef(Date.now());
  const realtime = useRef(false);
  const refreshTimer = useRef<number | null>(null);
  // Admins follow their brands' inbox feeds, Super Admin / Developer the global one
  const inboxChannels = !user.can.assign_leads
    ? ""
    : user.can.manage_brands
      ? "chats.all"
      : user.brand_ids.map((id) => `chats.${id}`).join(",");

  selectedIdRef.current = selected?.id ?? null;

  const applyPayload = useCallback((data: ChatsData, opts?: { replaceMessages?: boolean }) => {
    setList(data.conversations);
    setHasMore(data.has_more);
    setUnlinked(data.unlinked);
    setOpenCount(data.open_count ?? 0);
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

  useEffect(() => {
    applyPayload(initial, { replaceMessages: true });
    setDraft("");
  }, [initial, applyPayload]);

  const fetchChats = useCallback(async () => {
    if (document.visibilityState !== "visible") return;
    lastFetch.current = Date.now();
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

  // Many messages arriving together cause one refresh, not one per message
  const refreshSoon = useCallback(() => {
    if (refreshTimer.current !== null) return;
    refreshTimer.current = window.setTimeout(() => {
      refreshTimer.current = null;
      void fetchChats();
    }, 400);
  }, [fetchChats]);

  useEffect(() => () => {
    if (refreshTimer.current !== null) window.clearTimeout(refreshTimer.current);
  }, []);

  useEffect(() => {
    const timer = setInterval(() => {
      const every = !realtime.current ? POLL_MS : inboxChannels ? SAFETY_POLL_MS : REP_SAFETY_POLL_MS;
      if (Date.now() - lastFetch.current >= every - 250) void fetchChats();
    }, POLL_MS);
    const onVisible = () => {
      if (document.visibilityState === "visible") void fetchChats();
    };
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      clearInterval(timer);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [fetchChats, inboxChannels]);

  // Track whether Pusher is actually connected; if it drops, polling speeds back up
  useEffect(() => {
    if (!pusher?.key) return;
    const connection = getEcho(pusher.key, pusher.cluster)?.connector.pusher.connection;
    if (!connection) return;
    const update = () => {
      realtime.current = connection.state === "connected";
    };
    update();
    connection.bind("state_change", update);
    return () => {
      connection.unbind("state_change", update);
      realtime.current = false;
    };
  }, [pusher?.key, pusher?.cluster]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages.length]);

  useEffect(() => {
    if (!selected?.id || !pusher?.key) return;
    const echo = getEcho(pusher.key, pusher.cluster);
    if (!echo) return;

    const channelName = `chat.${selected.id}`;
    const channel = echo.private(channelName);
    const handler = (payload: { message?: ChatMessage }) => {
      if (!payload?.message) return;
      setMessages((prev) => (prev.some((m) => m.id === payload.message!.id) ? prev : [...prev, payload.message!]));
      refreshSoon();
    };
    channel.listen(".ChatMessageSent", handler);

    return () => {
      channel.stopListening(".ChatMessageSent", handler);
      echo.leave(channelName);
    };
  }, [selected?.id, pusher?.key, pusher?.cluster, refreshSoon]);

  useEffect(() => {
    if (!pusher?.key || !inboxChannels) return;
    const echo = getEcho(pusher.key, pusher.cluster);
    if (!echo) return;

    const names = inboxChannels.split(",");
    const handler = () => refreshSoon();
    for (const name of names) {
      echo.private(name).listen(".ChatMessageSent", handler);
    }
    return () => {
      for (const name of names) {
        echo.leave(name);
      }
    };
  }, [pusher?.key, pusher?.cluster, inboxChannels, refreshSoon]);

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
      description: "The lead is moved to the deleted archive. The chat transcript stays as history.",
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
  const location = selected ? [selected.city, selected.country].filter(Boolean).join(", ") : "";

  return (
    <div className="space-y-5">
      <Head title="Chats" />
      <PageHeader
        title="Chats"
        description="Reply to website visitors in real time. Older Tawk.to threads stay available as read-only history."
        actions={
          openCount > 0 ? (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200/80">
              <CircleDot className="h-3.5 w-3.5" />
              {openCount} live
            </span>
          ) : null
        }
      />

      {unlinked > 0 ? (
        <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
          <p>
            {unlinked} conversation{unlinked === 1 ? "" : "s"} came from a Tawk.to property that isn&apos;t linked to a
            website.{" "}
            <Link href="/websites" className="font-medium underline">
              Link the property
            </Link>{" "}
            to import them as leads.
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
            description={
              user.can.manage_websites
                ? "Add the chat widget snippet on a website. Visitor messages appear here so you can reply from this dashboard."
                : "Chats from visitors whose leads are assigned to you will appear here."
            }
            action={
              user.can.manage_websites ? (
                <Link href="/websites" className="btn-secondary">
                  Set up chat widget
                </Link>
              ) : null
            }
          />
        </div>
      ) : (
        <div className="card grid overflow-hidden lg:h-[calc(100vh-250px)] lg:min-h-[560px] lg:grid-cols-[360px_1fr]">
          <div className={`flex min-h-0 flex-col border-slate-200 bg-slate-50/40 lg:border-r ${params.id ? "hidden lg:flex" : ""}`}>
            <div className="border-b border-slate-200 px-4 py-3">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Inbox</p>
              <p className="mt-0.5 text-sm text-slate-600">
                {list.length} conversation{list.length === 1 ? "" : "s"}
                {openCount > 0 ? ` · ${openCount} live` : ""}
              </p>
            </div>
            <ul className="min-h-0 flex-1 overflow-y-auto">
              {list.map((c) => {
                const active = c.id === selected?.id;
                return (
                  <li key={c.id} className="border-b border-slate-100">
                    <Link
                      href={query({ id: String(c.id), before: params.before })}
                      className={`flex gap-3 px-4 py-3.5 transition ${
                        active ? "bg-white shadow-[inset_3px_0_0_0_#0d9488]" : "hover:bg-white/80"
                      }`}
                    >
                      <div className="relative shrink-0">
                        <Avatar name={c.display_name} />
                        {c.is_live ? (
                          <span className="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-500" />
                        ) : null}
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                          <p className="truncate text-sm font-semibold text-slate-900">{c.display_name}</p>
                          <span className="shrink-0 text-[11px] text-slate-400">{formatRelative(c.last_message_at, now)}</span>
                        </div>
                        <div className="mt-0.5 flex items-center gap-1.5 text-[11px] text-slate-500">
                          <span className="truncate">{c.website?.name ?? c.property_name ?? "Unlinked"}</span>
                          {c.provider === "tawk" ? <span className="rounded bg-slate-200/80 px-1.5 py-0.5 font-medium">Tawk</span> : null}
                          {c.is_live ? (
                            <span className="rounded bg-emerald-50 px-1.5 py-0.5 font-medium text-emerald-700">Live</span>
                          ) : c.status === "ended" ? (
                            <span className="rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-500">Ended</span>
                          ) : null}
                        </div>
                        <p className="mt-1 line-clamp-1 text-sm text-slate-600">{c.last_message ?? "No messages yet"}</p>
                      </div>
                    </Link>
                  </li>
                );
              })}
            </ul>
            {hasMore ? (
              <div className="border-t border-slate-200 bg-white p-2 text-center">
                <Link className="btn-ghost btn-sm" href={query({ before: list[list.length - 1].last_message_at })}>
                  Load older conversations
                </Link>
              </div>
            ) : null}
          </div>

          <div className={`flex min-h-0 flex-col bg-white ${params.id ? "" : "hidden lg:flex"}`}>
            {selected ? (
              <>
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                  <div className="flex min-w-0 items-start gap-3">
                    <Link href={query({ before: params.before })} className="btn-ghost p-1.5 lg:hidden" aria-label="Back">
                      <ArrowLeft className="h-4 w-4" />
                    </Link>
                    <Avatar name={selected.display_name} size="lg" />
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <h2 className="truncate text-base font-semibold text-slate-900">{selected.display_name}</h2>
                        {selected.is_live ? (
                          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                            Live
                          </span>
                        ) : (
                          <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                            {selected.status === "ended" ? "Ended" : selected.status}
                          </span>
                        )}
                      </div>
                      <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                        {selected.visitor_email ? (
                          <span className="inline-flex items-center gap-1">
                            <Mail className="h-3 w-3" />
                            {selected.visitor_email}
                          </span>
                        ) : (
                          <span className="text-slate-400">No email shared yet</span>
                        )}
                        {location ? (
                          <span className="inline-flex items-center gap-1">
                            <MapPin className="h-3 w-3" />
                            {location}
                          </span>
                        ) : null}
                        {selected.website?.name ? (
                          <span className="inline-flex items-center gap-1">
                            <Globe2 className="h-3 w-3" />
                            {selected.website.name}
                          </span>
                        ) : null}
                      </div>
                      <p className="mt-1 text-[11px] text-slate-400">Started {formatDateTime(selected.started_at)}</p>
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
                  <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-slate-100 bg-slate-50/80 px-5 py-2.5 text-xs text-slate-600">
                    <span>
                      Lead <span className="font-semibold text-slate-800">{selected.lead.name}</span>
                    </span>
                    <StatusBadge status={selected.lead.status} />
                    {canAssign ? (
                      <label className="flex items-center gap-2">
                        <span className="text-slate-500">Assign</span>
                        <select
                          className="input h-8 min-w-[210px] py-1 text-xs"
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
                        Assigned to <span className="font-semibold text-slate-800">{selected.lead.assignee ?? "nobody yet"}</span>
                      </span>
                    )}
                  </div>
                ) : (
                  <div className="border-b border-slate-100 bg-amber-50/70 px-5 py-2.5 text-xs text-amber-900">
                    {selected.website_id
                      ? "No lead yet — one is created as soon as the visitor sends a message."
                      : "This conversation isn't linked to a website yet, so no lead was created."}
                  </div>
                )}

                {selected.provider === "tawk" ? (
                  <div className="border-b border-slate-100 bg-slate-50 px-5 py-2 text-xs text-slate-600">
                    Read-only Tawk.to history. Reply to new chats here with the PPC Leads widget.
                  </div>
                ) : null}

                <div className="min-h-0 flex-1 space-y-4 overflow-x-hidden overflow-y-auto bg-[linear-gradient(180deg,#f8fafc_0%,#f1f5f9_100%)] px-5 py-5">
                  {selected.subject ? (
                    <p className="text-center text-xs font-medium text-slate-500">Subject: {selected.subject}</p>
                  ) : null}
                  {messages.length === 0 ? (
                    <div className="flex h-full min-h-[180px] flex-col items-center justify-center text-center">
                      <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-white text-slate-400 shadow-sm ring-1 ring-slate-200">
                        <MessageSquare className="h-5 w-5" />
                      </div>
                      <p className="text-sm font-medium text-slate-700">
                        {isNative ? "Waiting for the visitor" : "No messages yet"}
                      </p>
                      <p className="mt-1 max-w-xs text-xs text-slate-500">
                        {isNative
                          ? "When they send a message, it will appear here instantly so you can reply."
                          : "The transcript will show here when available."}
                      </p>
                    </div>
                  ) : (
                    messages.map((m, idx) => {
                      const prev = messages[idx - 1];
                      const showDay = !prev || !sameDay(prev.sent_at, m.sent_at);
                      if (m.sender_type === "system") {
                        return (
                          <div key={m.id}>
                            {showDay ? (
                              <p className="mb-3 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                {dayLabel(m.sent_at, now)}
                              </p>
                            ) : null}
                            <p className="text-center text-xs text-slate-400">{m.body}</p>
                          </div>
                        );
                      }
                      const mine = m.sender_type !== "visitor";
                      return (
                        <div key={m.id}>
                          {showDay ? (
                            <p className="mb-3 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                              {dayLabel(m.sent_at, now)}
                            </p>
                          ) : null}
                          <div className={`flex min-w-0 gap-2 ${mine ? "justify-end" : "justify-start"}`}>
                            {!mine ? <Avatar name={selected.display_name} /> : null}
                            <div className={`flex min-w-0 max-w-[min(75%,28rem)] flex-col ${mine ? "items-end" : "items-start"}`}>
                              <div
                                className={`max-w-full overflow-hidden whitespace-pre-wrap break-all [overflow-wrap:anywhere] px-3.5 py-2.5 text-sm leading-relaxed shadow-sm ${
                                  mine
                                    ? "rounded-2xl rounded-br-md bg-teal-700 text-white"
                                    : "rounded-2xl rounded-bl-md border border-slate-200 bg-white text-slate-800"
                                }`}
                              >
                                {m.body}
                              </div>
                              <p className={`mt-1 text-[11px] text-slate-400 ${mine ? "text-right" : ""}`}>
                                {mine ? m.sender_name ?? "You" : selected.display_name} · {formatTime(m.sent_at)}
                              </p>
                            </div>
                            {mine ? <Avatar name={m.sender_name ?? user.name} /> : null}
                          </div>
                        </div>
                      );
                    })
                  )}
                  <div ref={bottomRef} />
                </div>

                {canReply ? (
                  <form onSubmit={sendReply} className="border-t border-slate-200 bg-white px-4 py-3">
                    <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-2 focus-within:border-teal-500 focus-within:bg-white focus-within:ring-2 focus-within:ring-teal-500/15">
                      <textarea
                        className="min-h-[52px] w-full resize-none border-0 bg-transparent px-2 py-1.5 text-sm text-slate-800 outline-none placeholder:text-slate-400"
                        rows={2}
                        placeholder="Write a professional reply…"
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === "Enter" && !e.shiftKey) {
                            e.preventDefault();
                            void sendReply(e as unknown as FormEvent);
                          }
                        }}
                      />
                      <div className="flex items-center justify-between gap-2 px-1 pb-0.5">
                        <p className="text-[11px] text-slate-400">Enter to send · Shift+Enter for a new line</p>
                        <button type="submit" className="btn-primary btn-sm" disabled={sending || !draft.trim()}>
                          {sending ? "Sending…" : "Send reply"}
                        </button>
                      </div>
                    </div>
                  </form>
                ) : (
                  <div className="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-500">
                    <span className="flex items-center gap-1.5">
                      <MessageSquare className="h-3.5 w-3.5" />
                      {selected.message_count} message{selected.message_count === 1 ? "" : "s"}
                      {selected.provider === "tawk"
                        ? " · Reply from Tawk.to"
                        : selected.status === "ended"
                          ? " · This chat has ended"
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
              <EmptyState title="Select a conversation" description="Pick a chat from the inbox to view the transcript and reply." />
            )}
          </div>
        </div>
      )}
    </div>
  );
}
