import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import type { CurrentUser, LiveCounts } from "@/types";
import { ArrowUp } from "lucide-react";
import { getEcho } from "@/lib/echo";

// The server pings open dashboards through Pusher whenever lead counts change, so polling is
// only a fallback: every 10s until a ping has proven realtime works (30s once nothing has
// changed for a couple of minutes), then once a minute as a safety net. If a poll ever finds a
// change no ping announced, it goes back to the faster fallback.
const POLL_MS = 10_000;
const IDLE_POLL_MS = 30_000;
const IDLE_AFTER_MS = 120_000;
const SAFETY_POLL_MS = 60_000;

type LiveValue = {
  counts: LiveCounts;
  sync: () => void;
  /** Called whenever lead data changes; returns the unsubscribe function */
  subscribe: (onChange: () => void) => () => void;
};

const LiveContext = createContext<LiveValue>({
  counts: { new_leads: 0, unassigned: 0, my_new: 0, active_chats: 0 },
  sync: () => {},
  subscribe: () => () => {},
});

export function useLiveCounts() {
  return useContext(LiveContext).counts;
}

/** Re-poll now, e.g. right after assigning leads so the sidebar badge updates */
export function useLiveSync() {
  return useContext(LiveContext).sync;
}

/**
 * Screens that show leads (the list, the dashboard) reload the given page props when leads
 * change, at most once per `gapMs` so a burst of new leads costs one request. While such a
 * screen is open, the "new leads" pill stays hidden: the new leads are already on screen.
 */
export function useLiveReload(only: string[], gapMs: number, enabled = true, onReload?: () => void) {
  const { subscribe } = useContext(LiveContext);
  const last = useRef(0);
  const timer = useRef<number | null>(null);
  const onReloadRef = useRef(onReload);
  onReloadRef.current = onReload;
  const props = only.join(",");

  useEffect(() => {
    if (!enabled) return;
    const unsubscribe = subscribe(() => {
      if (timer.current !== null) return;
      timer.current = window.setTimeout(() => {
        timer.current = null;
        last.current = Date.now();
        onReloadRef.current?.();
        router.reload({ only: props.split(",") });
      }, Math.max(0, last.current + gapMs - Date.now()));
    });
    return () => {
      unsubscribe();
      if (timer.current !== null) window.clearTimeout(timer.current);
      timer.current = null;
    };
  }, [enabled, subscribe, props, gapMs]);
}

/** The ping channels this user may follow (routes/channels.php) */
function leadChannels(user: CurrentUser): string[] {
  const own = `leads.user.${user.id}`;
  if (user.can.manage_brands) return ["leads.all", own];
  if (user.role === "ADMIN" || user.role === "FINANCE") return [...user.brand_ids.map((id) => `leads.brand.${id}`), own];
  return [own];
}

/**
 * Keeps the sidebar badges and the "new leads" pill current. New leads show a pill
 * instead of reshuffling the page under the user's cursor.
 */
export function LiveProvider({
  initial,
  serverTime,
  user,
  realtime,
  children,
}: {
  initial: LiveCounts;
  serverTime: string;
  user: CurrentUser;
  realtime: { key: string; cluster: string } | null;
  children: React.ReactNode;
}) {
  const [counts, setCounts] = useState<LiveCounts>({ ...initial, new_leads: 0 });
  const since = useRef(serverTime);
  const baseTitle = useRef<string | null>(null);
  const lastPoll = useRef(Date.now());
  const lastChange = useRef(Date.now());
  const lastPing = useRef(0);
  const connected = useRef(false);
  const proven = useRef(false);
  const seen = useRef<string | null>(null);
  const pingTimer = useRef<number | null>(null);
  const screens = useRef(new Set<() => void>());
  const [watched, setWatched] = useState(false);

  const subscribe = useCallback((onChange: () => void) => {
    screens.current.add(onChange);
    setWatched(true);
    return () => {
      screens.current.delete(onChange);
      setWatched(screens.current.size > 0);
    };
  }, []);

  const poll = useCallback(async (fromTimer = false, fromPing = false) => {
    if (document.visibilityState !== "visible") return;
    lastPoll.current = Date.now();
    try {
      const res = await fetch(`/api/live?since=${encodeURIComponent(since.current)}`, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      });
      if (res.status === 401) {
        window.location.assign("/login");
        return;
      }
      if (!res.ok) return;
      const data = await res.json();
      // Lead counts only: a chat ending or going quiet changes active_chats without a ping
      const leads = `${data.new_leads}|${data.unassigned}|${data.my_new}`;
      const changed = seen.current !== null && leads !== seen.current;
      if (changed) {
        lastChange.current = Date.now();
        if (fromTimer && Date.now() - lastPing.current > 3000) proven.current = false;
      }
      // A screen that shows leads refreshes itself, so they no longer count as unseen
      const shown = screens.current.size > 0;
      if (shown && data.new_leads > 0) since.current = data.server_time;
      seen.current = shown ? `0|${data.unassigned}|${data.my_new}` : leads;
      setCounts({
        new_leads: shown ? 0 : data.new_leads,
        unassigned: data.unassigned,
        my_new: data.my_new,
        active_chats: data.active_chats,
      });
      // A ping can mean a change the counts don't show (a status change, a reassignment)
      if (changed || fromPing) for (const onChange of screens.current) onChange();
    } catch {
      // Offline or server restarting: try again on the next tick
    }
  }, []);

  useEffect(() => {
    const timer = setInterval(() => {
      const quiet = Date.now() - lastChange.current > IDLE_AFTER_MS;
      const every = proven.current ? SAFETY_POLL_MS : quiet ? IDLE_POLL_MS : POLL_MS;
      if (Date.now() - lastPoll.current >= every - 250) void poll(true);
    }, 2_000);
    const onVisible = () => {
      if (document.visibilityState === "visible") void poll();
    };
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      clearInterval(timer);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [poll]);

  const channelKey = leadChannels(user).join(",");
  useEffect(() => {
    if (!realtime?.key) return;
    const echo = getEcho(realtime.key, realtime.cluster);
    if (!echo) return;
    const connection = echo.connector.pusher.connection;
    const onState = () => {
      connected.current = connection.state === "connected";
      if (!connected.current) proven.current = false;
    };
    onState();
    connection.bind("state_change", onState);

    // Pings carry no data: a burst of them (a bulk action, a busy minute) causes one poll
    const onPing = () => {
      lastPing.current = Date.now();
      proven.current = connected.current;
      if (pingTimer.current !== null) return;
      pingTimer.current = window.setTimeout(() => {
        pingTimer.current = null;
        void poll(false, true);
      }, 500);
    };
    const names = channelKey.split(",");
    for (const name of names) echo.private(name).listen(".LeadsChanged", onPing);
    return () => {
      connection.unbind("state_change", onState);
      for (const name of names) echo.leave(name);
      if (pingTimer.current !== null) window.clearTimeout(pingTimer.current);
      pingTimer.current = null;
      proven.current = false;
    };
  }, [realtime?.key, realtime?.cluster, channelKey, poll]);

  useEffect(() => {
    if (baseTitle.current === null) baseTitle.current = document.title.replace(/^\(\d+\+?\)\s*/, "");
    document.title = counts.new_leads > 0 ? `(${counts.new_leads}) ${baseTitle.current}` : baseTitle.current;
  }, [counts.new_leads]);

  function showNew() {
    since.current = new Date().toISOString();
    setCounts((c) => ({ ...c, new_leads: 0 }));
    router.reload();
  }

  const value = useMemo(() => ({ counts, sync: () => void poll(), subscribe }), [counts, poll, subscribe]);

  return (
    <LiveContext.Provider value={value}>
      {children}
      {counts.new_leads > 0 && !watched ? (
        <div className="pointer-events-none fixed inset-x-0 top-4 z-50 flex justify-center px-4">
          <button
            type="button"
            onClick={showNew}
            className="pointer-events-auto inline-flex items-center gap-2 rounded-full bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-lg transition hover:bg-slate-800"
          >
            <ArrowUp className="h-4 w-4" />
            {counts.new_leads >= 100 ? "100+" : counts.new_leads} new lead{counts.new_leads === 1 ? "" : "s"} · Show
          </button>
        </div>
      ) : null}
    </LiveContext.Provider>
  );
}
