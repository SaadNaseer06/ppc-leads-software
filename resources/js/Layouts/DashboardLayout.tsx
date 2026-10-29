import type { ReactNode } from "react";
import { usePage } from "@inertiajs/react";
import { navItemsFor } from "@/lib/rbac";
import { Sidebar } from "@/components/app-shell/sidebar";
import { LiveProvider } from "@/components/app-shell/live-provider";
import type { SharedProps } from "@/types";

/** Persistent shell: stays mounted between pages, so the live poller keeps running */
export default function DashboardLayout({ children }: { children: ReactNode }) {
  const { auth, app, live } = usePage<SharedProps>().props;
  const user = auth.user;
  if (!user || !live) return <>{children}</>;

  return (
    <LiveProvider initial={live} serverTime={live.server_time}>
      <div className="min-h-screen lg:pl-64">
        <Sidebar
          items={navItemsFor(user)}
          user={{ name: user.name, email: user.email, roleLabel: user.role_label }}
          canAssign={user.can.assign_leads}
          env={app.env}
        />
        {app.env === "production" && user.role === "DEVELOPER" ? (
          <div className="border-b border-amber-200 bg-amber-50 px-6 py-2 text-sm text-amber-900">
            You are signed in as a Developer on production data. Use the staging environment for testing.
          </div>
        ) : null}
        <main className="mx-auto w-full max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">{children}</main>
      </div>
    </LiveProvider>
  );
}
