// Shapes returned by the Laravel API (backend/app/Http/Controllers/Api).
// Timestamps are ISO 8601 strings in UTC.

export type Ref = { id: number; name: string };

export type Assignee = {
  id: number;
  name: string;
  role: string;
  role_label: string;
  open_leads: number;
  /** Brands they work; leads can only go to people in the lead's brand */
  brand_ids: number[];
};

export type LeadRow = {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  company: string | null;
  status: string;
  source: string;
  channel: string;
  campaign: string | null;
  submission_count: number;
  created_at: string;
  website: Ref | null;
  brand: Ref | null;
  assignee: Ref | null;
};

export type LeadFilters = {
  q?: string;
  status?: string;
  source?: string;
  channel?: string;
  website?: string;
  brand?: string;
  /** "unassigned" | "me" | a user id */
  assignee?: string;
  from?: string;
  to?: string;
};

export type LeadsPage = {
  leads: LeadRow[];
  next_cursor: string | null;
  prev_cursor: string | null;
  total: number;
  total_capped: boolean;
  filters: LeadFilters;
};

export type Lookups = { websites: (Ref & { brand_id: number })[]; brands: Ref[]; assignees: Assignee[] };

export type ChatMessage = { id: number; sender_type: string; sender_name: string | null; body: string; sent_at: string };

export type LeadActivity = {
  id: number;
  type: string;
  body: string | null;
  data: Record<string, unknown>;
  actor: string | null;
  to_user: string | null;
  from_user: string | null;
  created_at: string;
};

export type LeadDetail = {
  lead: {
    /** Email, phone and enquiry are masked for this viewer until revealed */
    contact_masked?: boolean;
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    company: string | null;
    message: string | null;
    source: string;
    channel: string;
    status: string;
    utm_source: string | null;
    utm_medium: string | null;
    utm_campaign: string | null;
    utm_term: string | null;
    utm_content: string | null;
    click_id: string | null;
    click_id_type: string | null;
    landing_page: string | null;
    page_url: string | null;
    referrer: string | null;
    form_name: string | null;
    ip_address: string | null;
    submission_count: number;
    assigned_at: string | null;
    last_activity_at: string;
    created_at: string;
    fields: Record<string, string>;
    device: string | null;
    brand: Ref;
    website: (Ref & { domain: string }) | null;
    assignee: (Ref & { email: string }) | null;
  };
  activities: LeadActivity[];
  conversations: { id: number; kind: string; status: string; subject: string | null; started_at: string; messages: ChatMessage[] }[];
  invoices: {
    id: number;
    number: string;
    amount: string;
    currency: string;
    status: string;
    due_date: string | null;
    is_overdue: boolean;
    pay_url: string | null;
    created_at: string;
  }[];
  assignees: Assignee[];
  /** An invoice still to be paid keeps the lead with Front Sales */
  unpaid_invoice: boolean;
  emails: LeadEmailRow[];
  /** The address this person sends from on the lead's brand, if they have one */
  mailbox: string | null;
};

export type DashboardData = {
  stats: {
    range: number;
    total: number;
    previous: number;
    today: number;
    converted: number;
    ppc: number;
    unassigned: number;
    my_open: number;
    series: { day: string; count: number }[];
    websites: { id: number | null; name: string; count: number }[];
    statuses: { status: string; count: number }[];
    team: { id: number; name: string; assigned: number; converted: number }[];
  };
  recent_leads: { id: number; name: string; status: string; channel: string; created_at: string; website: Ref | null; assignee: Ref | null }[];
  recent_chats: {
    id: number;
    kind: string;
    status: string;
    display_name: string;
    last_message: string | null;
    last_message_at: string;
    website: Ref | null;
  }[];
};

export type ConversationRow = {
  id: number;
  kind: string;
  provider: string;
  status: string;
  display_name: string;
  visitor_email: string | null;
  is_live: boolean;
  last_message: string | null;
  last_message_at: string;
  message_count: number;
  property_name: string | null;
  /** Who holds the chat: the rep it was sent to, or whoever answered first */
  assigned_to: string | null;
  website: Ref | null;
  can_reply: boolean;
};

export type FrontSalesRep = { id: number; name: string; open_leads: number };

export type ChatsData = {
  conversations: ConversationRow[];
  has_more: boolean;
  selected:
    | (ConversationRow & {
        visitor_name: string | null;
        visitor_email: string | null;
        city: string | null;
        country: string | null;
        subject: string | null;
        started_at: string;
        website_id: number | null;
        brand_id: number | null;
        page_url: string | null;
        messages: ChatMessage[];
        lead: {
          id: number;
          name: string;
          status: string;
          assignee: string | null;
          assignee_id: number | null;
          promoted?: boolean;
        } | null;
        can_convert?: boolean;
        transfer: { by: string | null; to_id: number | null; to: string | null; at: string; note: string | null } | null;
      })
    | null;
  assignees: Assignee[];
  front_sales: FrontSalesRep[];
  open_count: number;
  unlinked: number;
  pusher: { key: string | null; cluster: string } | null;
};

export type WebsiteListItem = {
  id: number;
  name: string;
  domain: string;
  active: boolean;
  tawk_property_id: string | null;
  last_lead_at: string | null;
  last_chat_at: string | null;
  brand: Ref;
  has_tawk_secret: boolean;
  leads_30d: number;
};

export type WebsitesData = {
  websites: WebsiteListItem[];
  brands: Ref[];
  unlinked_properties: { property_id: string; property_name: string | null; conversations: number; last_message_at: string | null }[];
};

export type WebsiteDetail = {
  website: {
    id: number;
    name: string;
    domain: string;
    brand_id: number;
    active: boolean;
    chat_enabled: boolean;
    public_key: string;
    tawk_property_id: string | null;
    last_lead_at: string | null;
    last_chat_at: string | null;
    brand: Ref;
    has_tawk_secret: boolean;
    embed_snippet: string;
    chat_snippet: string;
    intake_url: string;
    tawk_webhook_url: string;
  };
  leads_30d: number;
  recent_leads: { id: number; name: string; status: string; source: string; created_at: string }[];
  events: { id: number; event: string; status: string; error: string | null; created_at: string }[];
  brands: Ref[];
};

export type TeamData = {
  users: {
    id: number;
    name: string;
    email: string;
    active: boolean;
    admin_id: number | null;
    last_login_at: string | null;
    role: string;
    role_label: string;
    brands: Ref[];
    open_leads: number;
    leads_30d: number;
    editable: boolean;
  }[];
  brands: Ref[];
  roles: { id: string; name: string }[];
};

export type BrandBranding = {
  merchant_name: string | null;
  brand_color: string | null;
  billing_email: string | null;
  billing_address: string | null;
  pay_domain: string | null;
  logo_url: string | null;
  favicon_url: string | null;
  domain_connected: boolean;
  card_payments?: boolean;
};

export type BrandsData = {
  brands: {
    id: number;
    name: string;
    slug: string;
    auto_assign_enabled: boolean;
    websites_count: number;
    users_count: number;
    waiting: number;
    front_sales_reps: number;
    managers: Ref[];
    branding: BrandBranding;
  }[];
  /** Brand Managers this user may appoint */
  manager_options: Ref[];
  billing: { dns_target: string };
};

export type InvoiceRow = {
  id: number;
  number: string;
  amount: string;
  currency: string;
  status: string;
  /** Sum of recorded payments */
  paid_total: string | null;
  due_date: string | null;
  is_overdue: boolean;
  /** The page the client opens to view and pay */
  pay_url: string | null;
  created_at: string;
  client_name: string | null;
  client_email: string | null;
  brand: Ref;
  /** Empty for an invoice raised without a lead */
  lead: Ref | null;
  created_by: Ref;
};

export type BrandOption = Ref & { merchant_name: string | null };

/** One brand's card payments. Everything after `webhook` only reaches a Super Admin. */
export type BrandPayments = {
  id: number;
  name: string;
  card_payments: boolean;
  webhook: boolean;
  merchant_name?: string | null;
  /** Its own keys, the server-wide .env keys, or none */
  source?: "own" | "env" | null;
  mode?: "test" | "live" | null;
  account_name?: string | null;
  publishable_key?: string | null;
  secret_hint?: string | null;
  webhook_url?: string;
};

/** Why card payments are on or off. Names only, never key values. */
export type BillingStatus = {
  can_configure: boolean;
  brands: BrandPayments[];
  card_payments: boolean;
  mode: "test" | "live" | null;
  missing: string[];
  needs_reload: boolean;
  problem: string | null;
  webhook: boolean;
};

export type PaymentsData = {
  brands: BrandPayments[];
  /** The .env fallback: names and yes/no only */
  server: Omit<BillingStatus, "can_configure" | "brands">;
};

export type InvoicesData = { invoices: InvoiceRow[]; limit: number; brands: BrandOption[]; billing: BillingStatus | null };

export type FinanceData = {
  transactions: {
    id: number;
    amount: string;
    method: string;
    external_ref: string | null;
    notes: string | null;
    recorded_at: string;
    invoice: { id: number; number: string; currency: string; client_name: string | null; brand: Ref; lead: Ref | null };
  }[];
  invoices: {
    id: number;
    number: string;
    amount: string;
    currency: string;
    status: string;
    paid_total: string | null;
    due_date: string | null;
    is_overdue: boolean;
    pay_url: string | null;
    created_at: string;
    client_name: string | null;
    client_email: string | null;
    brand: Ref;
    lead: Ref | null;
  }[];
  limit: number;
};

export type BrandReport = {
  brand_id: number;
  brand_name: string;
  lead_count: number;
  converted_count: number;
  invoice_count: number;
  total_amount: number;
  paid_amount: number;
  outstanding_amount: number;
};

export type SalesFilters = {
  from: string;
  to: string;
  brand: number | null;
  website: number | null;
  rep: number | null;
  channel: string | null;
  status: string | null;
};

export type SalesBreakdownRow = {
  id: number | string;
  name: string;
  invoices: number;
  invoiced: number;
  collected: number;
  outstanding: number;
};

export type SalesReportData = {
  filters: SalesFilters;
  options: {
    brands: Ref[];
    websites: (Ref & { brand_id: number })[];
    reps: Ref[];
    channels: string[];
    statuses: string[];
  };
  totals: {
    invoices: number;
    paid_invoices: number;
    invoiced: number;
    collected: number;
    outstanding: number;
    overdue: number;
    average: number;
  };
  previous: { invoiced: number; collected: number };
  leads: { received: number; converted: number };
  granularity: "day" | "month";
  series: { bucket: string; invoiced: number; collected: number; outstanding: number }[];
  by_brand: SalesBreakdownRow[];
  by_rep: SalesBreakdownRow[];
  by_channel: SalesBreakdownRow[];
  by_campaign: SalesBreakdownRow[];
};

export type LeadEmailRow = {
  id: number;
  direction: "in" | "out";
  from_email: string;
  from_name: string | null;
  to_email: string;
  subject: string;
  body: string;
  /** The formatted version, cleaned on the server to bold, italic, underline, lists and links */
  html: string | null;
  sent_at: string;
  /** Who on the team sent it */
  sender: string | null;
  attachments: EmailAttachment[];
};

export type EmailAttachment = {
  id: number;
  name: string;
  mime: string;
  size: number;
  url: string;
};

export type EmailThreadRow = {
  lead_id: number;
  name: string;
  brand: string | null;
  assignee: string | null;
  status: string;
  last_email_at: string;
  /** The client's latest email hasn't been opened by the lead's rep */
  unread: boolean;
  /** The client spoke last */
  needs_reply: boolean;
  subject: string;
  snippet: string;
  direction: "in" | "out" | null;
  sender: string | null;
  count: number;
};

export type EmailThreadDetail = {
  lead: { id: number; name: string; email: string | null; brand: string | null; assignee: string | null; status: string; needs_reply: boolean };
  /** Oldest first */
  emails: LeadEmailRow[];
  /** The address the viewer sends from on this lead's brand */
  mailbox: string | null;
  has_email: boolean;
};

export type EmailInboxData = {
  threads: EmailThreadRow[];
  has_more: boolean;
  counts: { unread: number; needs_reply: number };
  brands: Ref[];
  selected: EmailThreadDetail | null;
};

export type MailboxRow = {
  id: number;
  brand_id: number;
  /** Empty for the brand's shared mailbox */
  user_id: number | null;
  brand: string;
  owner: string | null;
  address: string;
  from_name: string | null;
  host: string;
  imap_port: number;
  smtp_port: number;
  username: string | null;
  checked_at: string | null;
  error: string | null;
  /** Created by the app in cPanel: its password is known only to the app */
  managed: boolean;
};

export type CpanelAccountRow = { id: number; host: string; username: string; checked_at: string | null; domains: string[] };

export type MailboxesData = {
  mailboxes: MailboxRow[];
  brands: Ref[];
  cpanel_accounts: CpanelAccountRow[];
  people: { id: number; name: string; role: string; brand_ids: number[] }[];
};

export type ContactLogData = {
  limit: number;
  people: { id: number; name: string; role: string | null; today: number; week: number; limit_hits: number }[];
  recent: { id: number; user: string | null; lead: Ref | null; reason: string | null; at: string }[];
};
