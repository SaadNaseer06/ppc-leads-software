import type { Role } from "@/lib/roles";

export type Permission =
  | "assign_leads"
  | "create_leads"
  | "work_leads"
  | "view_chats"
  | "view_invoices"
  | "create_invoice"
  | "view_finance"
  | "view_reports"
  | "manage_websites"
  | "manage_users"
  | "view_team"
  | "manage_brands"
  | "export_leads";

/** The signed-in user, as App\Services\TeamDirectory::profile() shares it */
export type CurrentUser = {
  id: number;
  name: string;
  email: string;
  role: Role;
  role_label: string;
  brand_ids: number[];
  can: Record<Permission, boolean>;
};

export type LiveCounts = {
  new_leads: number;
  unassigned: number;
  my_new: number;
  active_chats: number;
};

/** Props every page receives (App\Http\Middleware\HandleInertiaRequests::share) */
export type SharedProps = {
  auth: { user: CurrentUser | null };
  app: { env: string; timezone: string };
  live: (LiveCounts & { server_time: string }) | null;
};
