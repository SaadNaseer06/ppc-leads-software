import type { CurrentUser } from "@/types";

// Who may do what is decided by the Laravel API (User::can* methods) and sent
// as `user.can`; the dashboard only uses it to decide what to show.

export type NavKey =
  | "dashboard"
  | "leads"
  | "chats"
  | "invoices"
  | "finance"
  | "reports"
  | "websites"
  | "team"
  | "brands"
  | "payments"
  | "email"
  | "contact_log";

export type NavItem = { key: NavKey; href: string; label: string; section: "main" | "admin" };

export function navItemsFor(user: CurrentUser): NavItem[] {
  const items: NavItem[] = [
    { key: "dashboard", href: "/dashboard", label: "Dashboard", section: "main" },
  ];
  const can = user.can;

  // Chat agents staff the inbox; everyone else keeps the leads list
  if (user.role !== "CHAT_AGENT") {
    items.push({ key: "leads", href: "/leads", label: "Leads", section: "main" });
  }
  if (can.view_chats) items.push({ key: "chats", href: "/chats", label: "Chats", section: "main" });
  if (can.view_invoices) items.push({ key: "invoices", href: "/invoices", label: "Invoices", section: "main" });
  if (can.view_finance) items.push({ key: "finance", href: "/finance", label: "Finance", section: "main" });
  if (can.view_reports) items.push({ key: "reports", href: "/reports", label: "Reports", section: "main" });
  if (can.manage_websites) items.push({ key: "websites", href: "/websites", label: "Websites", section: "admin" });
  if (can.view_team) items.push({ key: "team", href: "/team", label: "Team", section: "admin" });
  if (can.assign_leads) items.push({ key: "brands", href: "/brands", label: "Brands & routing", section: "admin" });
  if (can.manage_payments) items.push({ key: "payments", href: "/payments", label: "Payments", section: "admin" });
  if (can.manage_payments) items.push({ key: "email", href: "/email", label: "Email", section: "admin" });
  if (can.view_team) items.push({ key: "contact_log", href: "/contact-log", label: "Contact log", section: "admin" });

  return items;
}
