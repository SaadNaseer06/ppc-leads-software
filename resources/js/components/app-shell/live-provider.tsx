import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import type { LiveCounts } from "@/types";
import { ArrowUp } from "lucide-react";

const POLL_MS = 20_000;
const LiveContext = createContext<{ counts: LiveCounts; sync: () => void }>({
  counts: { new_leads: 0, unassigned: 0, my_new: 0, active_chats: 0 },
  sync: () => {},
});

export function useLiveCounts() {
  return useContext(LiveContext).counts;
}

/** Re-poll now, e.g. right after assigning leads so the sidebar badge updates */
export function useLiveSync() {
  return useContext(LiveContext).sync;
}

/**
 * Polls /api/live while the tab is visible. New leads show a "refresh" pill
 * instead of reshuffling the page under the user's cursor.
 */
export function LiveProvider({
  initial,
  serverTime,
  children,
}: {
  initial: LiveCounts;
  serverTime: string;
  children: React.ReactNode;
}) {
  const [counts, setCounts] = useState<LiveCounts>({ ...initial, new_leads: 0 });
  const since = useRef(serverTime);
  const baseTitle = useRef<string | null>(null);

  const poll = useCallback(async () => {
    if (document.visibilityState !== "visible") return;
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
      setCounts({
        new_leads: data.new_leads,
        unassigned: data.unassigned,
        my_new: data.my_new,
        active_chats: data.active_chats,
      });
    } catch {
      // Offline or server restarting: try again on the next tick
    }
  }, []);

  useEffect(() => {
    const timer = setInterval(poll, POLL_MS);
    const onVisible = () => {
      if (document.visibilityState === "visible") void poll();
    };
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      clearInterval(timer);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [poll]);

  useEffect(() => {
    if (baseTitle.current === null) baseTitle.current = document.title.replace(/^\(\d+\+?\)\s*/, "");
    document.title = counts.new_leads > 0 ? `(${counts.new_leads}) ${baseTitle.current}` : baseTitle.current;
  }, [counts.new_leads]);

  function showNew() {
    since.current = new Date().toISOString();
    setCounts((c) => ({ ...c, new_leads: 0 }));
    router.reload();
  }

  const value = useMemo(() => ({ counts, sync: () => void poll() }), [counts, poll]);

  return (
    <LiveContext.Provider value={value}>
      {children}
      {counts.new_leads > 0 ? (
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
