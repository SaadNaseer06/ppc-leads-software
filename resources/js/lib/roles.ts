// Value lists and display labels. The rules about who may do what live in the
// Laravel API (App\Enums, App\Models\User::can*).

export const Role = {
  SUPER_ADMIN: "SUPER_ADMIN",
  ADMIN: "ADMIN",
  FRONT_SALES: "FRONT_SALES",
  UP_SALES: "UP_SALES",
  FINANCE: "FINANCE",
  DEVELOPER: "DEVELOPER",
} as const;

export type Role = (typeof Role)[keyof typeof Role];

export const LeadSource = {
  form: "form",
  chat: "chat",
  offline: "offline",
  manual: "manual",
} as const;

export type LeadSource = (typeof LeadSource)[keyof typeof LeadSource];

export const SOURCE_LABELS: Record<LeadSource, string> = {
  form: "Web form",
  chat: "Live chat",
  offline: "Offline message",
  manual: "Manual",
};

export const LeadStatus = {
  new: "new",
  contacted: "contacted",
  qualified: "qualified",
  converted: "converted",
  lost: "lost",
  spam: "spam",
} as const;

export type LeadStatus = (typeof LeadStatus)[keyof typeof LeadStatus];

export const STATUS_LABELS: Record<LeadStatus, string> = {
  new: "New",
  contacted: "Contacted",
  qualified: "Qualified",
  converted: "Converted",
  lost: "Lost",
  spam: "Spam",
};

export const Channel = {
  ppc: "ppc",
  organic: "organic",
  social: "social",
  referral: "referral",
  direct: "direct",
  unknown: "unknown",
} as const;

export type Channel = (typeof Channel)[keyof typeof Channel];

export const CHANNEL_LABELS: Record<Channel, string> = {
  ppc: "PPC",
  organic: "Organic",
  social: "Social",
  referral: "Referral",
  direct: "Direct",
  unknown: "Unknown",
};

export function labelFor<T extends string>(labels: Record<T, string>, value: string) {
  return labels[value as T] ?? value;
}
