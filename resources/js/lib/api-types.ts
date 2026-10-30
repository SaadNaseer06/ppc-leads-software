// Shapes returned by the Laravel API (backend/app/Http/Controllers/Api).
// Timestamps are ISO 8601 strings in UTC.

export type Ref = { id: number; name: string };

export type Assignee = { id: number; name: string; role: string; role_label: string; open_leads: number };

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

export type Lookups = { websites: (Ref & { brand_id: number })[]; assignees: Assignee[] };

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
  invoices: { id: number; number: string; amount: string; currency: string; status: string; created_at: string }[];
  assignees: Assignee[];
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
  website: Ref | null;
  can_reply: boolean;
};

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
        } | null;
      })
    | null;
  assignees: Assignee[];
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
  }[];
};

export type InvoiceRow = {
  id: number;
  number: string;
  amount: string;
  currency: string;
  status: string;
  created_at: string;
  brand: Ref;
  lead: Ref;
  created_by: Ref;
};

export type FinanceData = {
  transactions: {
    id: number;
    amount: string;
    method: string;
    external_ref: string | null;
    recorded_at: string;
    invoice: { id: number; number: string; currency: string; brand: Ref; lead: Ref };
  }[];
  invoices: { id: number; number: string; amount: string; currency: string; status: string; brand: Ref }[];
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
};
