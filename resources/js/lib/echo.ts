import Echo from "laravel-echo";
import Pusher from "pusher-js";
import type { ChannelAuthorizationCallback } from "pusher-js";
import { apiHeaders } from "@/components/ui/toast";

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: Echo<"pusher">;
  }
}

window.Pusher = Pusher;

let echo: Echo<"pusher"> | null = null;

/** Shared Laravel Echo client for dashboard realtime (private channels via Sanctum). */
export function getEcho(key: string | null | undefined, cluster: string | null | undefined): Echo<"pusher"> | null {
  if (!key) return null;
  if (echo) return echo;

  echo = new Echo({
    broadcaster: "pusher",
    key,
    cluster: cluster || "mt1",
    forceTLS: true,
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: ChannelAuthorizationCallback) => {
        fetch("/broadcasting/auth", {
          method: "POST",
          credentials: "same-origin",
          headers: apiHeaders(true),
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        })
          .then(async (res) => {
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
              callback(new Error(data.error || "Broadcast auth failed"), null);
              return;
            }
            callback(null, data);
          })
          .catch((err: Error) => callback(err, null));
      },
    }),
  });

  window.Echo = echo;
  return echo;
}
