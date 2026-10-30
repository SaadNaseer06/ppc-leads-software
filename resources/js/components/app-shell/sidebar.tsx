import { useState } from "react";
import { Link, router, usePage } from "@inertiajs/react";
import {
  BarChart3,
  Building2,
  FileText,
  Globe,
  LayoutDashboard,
  LogOut,
  Menu,
  MessageSquare,
  Users,
  UserCog,
  Wallet,
  X,
} from "lucide-react";
import type { NavItem, NavKey } from "@/lib/rbac";
import { BrandMark } from "@/components/brand-mark";
import { Avatar } from "@/components/ui/avatar";
import { useLiveCounts } from "./live-provider";

const ICONS: Record<NavKey, React.ComponentType<{ className?: string }>> = {
  dashboard: LayoutDashboard,
  leads: Users,
  chats: MessageSquare,
  invoices: FileText,
  finance: Wallet,
  reports: BarChart3,
  websites: Globe,
  team: UserCog,
  brands: Building2,
};

type Props = {
  items: NavItem[];
  user: { name: string; email: string; roleLabel: string };
  canAssign: boolean;
  env: string;
};

export function Sidebar({ items, user, canAssign, env }: Props) {
  const pathname = usePage().url.split("?")[0];
  // The mobile drawer remembers the page it was opened on, so navigating closes it
  const [openOn, setOpenOn] = useState<string | null>(null);
  const open = openOn === pathname;
  const setOpen = (value: boolean) => setOpenOn(value ? pathname : null);

  return (
    <>
      <div className="sticky top-0 z-30 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
        <Brand dark={false} />
        <button type="button" className="btn-ghost p-2" onClick={() => setOpen(true)} aria-label="Open menu">
          <Menu className="h-5 w-5" />
        </button>
      </div>

      {open ? (
        <div className="fixed inset-0 z-40 bg-slate-900/50 lg:hidden" onClick={() => setOpen(false)} />
      ) : null}

      <aside
        className={`fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-sidebar text-slate-300 transition-transform lg:translate-x-0 ${
          open ? "translate-x-0" : "-translate-x-full"
        }`}
      >
        <div className="flex items-center justify-between px-5 py-5">
          <Brand dark />
          <button
            type="button"
            className="rounded-md p-1.5 text-slate-400 hover:bg-white/10 lg:hidden"
            onClick={() => setOpen(false)}
            aria-label="Close menu"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <nav className="flex-1 space-y-6 overflow-y-auto px-3 pb-4">
          <NavSection items={items.filter((i) => i.section === "main")} pathname={pathname} canAssign={canAssign} />
          {items.some((i) => i.section === "admin") ? (
            <div>
              <p className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                Administration
              </p>
              <NavSection items={items.filter((i) => i.section === "admin")} pathname={pathname} canAssign={canAssign} />
            </div>
          ) : null}
        </nav>

        <div className="border-t border-white/10 p-3">
          {env !== "production" ? (
            <p className="mb-2 rounded-md bg-amber-400/10 px-3 py-1.5 text-center text-[11px] font-medium uppercase tracking-wide text-amber-300">
              {env} environment
            </p>
          ) : null}
          <div className="flex items-center gap-3 rounded-lg px-2 py-2">
            <Avatar name={user.name} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-white">{user.name}</p>
              <p className="truncate text-xs text-slate-400">{user.roleLabel}</p>
            </div>
            <button
              type="button"
              onClick={() => router.post("/logout")}
              className="rounded-md p-2 text-slate-400 hover:bg-white/10 hover:text-white"
              aria-label="Sign out"
              title="Sign out"
            >
              <LogOut className="h-4 w-4" />
            </button>
          </div>
        </div>
      </aside>
    </>
  );
}

function Brand({ dark }: { dark: boolean }) {
  return (
    <Link href="/dashboard" className="flex items-center gap-2.5">
      <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-600 text-white">
        <BrandMark className="h-4 w-4" />
      </span>
      <span className={`text-[15px] font-semibold tracking-tight ${dark ? "text-white" : "text-slate-900"}`}>
        PPC Leads
      </span>
    </Link>
  );
}

function NavSection({ items, pathname, canAssign }: { items: NavItem[]; pathname: string; canAssign: boolean }) {
  const live = useLiveCounts();
  const badges: Partial<Record<NavKey, number>> = {
    leads: canAssign ? live.unassigned : live.my_new,
    chats: live.active_chats,
  };

  return (
    <ul className="space-y-0.5">
      {items.map((item) => {
        const Icon = ICONS[item.key];
        const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
        const badge = badges[item.key];
        return (
          <li key={item.key}>
            <Link
              href={item.href}
              className={`group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ${
                active ? "bg-white/10 text-white" : "text-slate-400 hover:bg-sidebar-hover hover:text-slate-100"
              }`}
              style={!active ? undefined : { boxShadow: "inset 2px 0 0 #14b8a6" }}
            >
              <Icon className={`h-[18px] w-[18px] ${active ? "text-teal-400" : "text-slate-500 group-hover:text-slate-300"}`} />
              <span className="flex-1">{item.label}</span>
              {badge ? (
                <span
                  className={`min-w-[1.25rem] rounded-full px-1.5 py-0.5 text-center text-[11px] font-semibold ${
                    item.key === "chats" ? "bg-emerald-500/20 text-emerald-300" : "bg-teal-500/20 text-teal-300"
                  }`}
                  title={item.key === "leads" ? (canAssign ? "Unassigned new leads" : "Your new leads") : "Active chats"}
                >
                  {badge >= 1000 ? "999+" : badge}
                </span>
              ) : null}
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
