/** Dates are shown in the business timezone (REPORT_TIMEZONE), set once at start-up. */
let timezone = "UTC";
const formatters = new Map<string, Intl.DateTimeFormat>();

export function setTimezone(tz: string) {
  timezone = tz || "UTC";
  formatters.clear();
}

function fmt(key: string, options: Intl.DateTimeFormatOptions) {
  let f = formatters.get(key);
  if (!f) {
    f = new Intl.DateTimeFormat("en-US", { timeZone: timezone, ...options });
    formatters.set(key, f);
  }
  return f;
}

/** API timestamps arrive as ISO strings */
export type DateLike = Date | string;

function toDate(d: DateLike) {
  return typeof d === "string" ? new Date(d) : d;
}

export function formatDate(d: DateLike) {
  return fmt("date", { month: "short", day: "numeric", year: "numeric" }).format(toDate(d));
}

export function formatDateTime(d: DateLike) {
  return fmt("datetime", { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" }).format(
    toDate(d)
  );
}

export function formatTime(d: DateLike) {
  return fmt("time", { hour: "numeric", minute: "2-digit" }).format(toDate(d));
}

/** Hour of the day (0–23) in the business timezone */
export function hourOfDay(d: DateLike = new Date()) {
  return Number(fmt("hour", { hour: "numeric", hourCycle: "h23" }).format(toDate(d)));
}

export function formatRelative(d: DateLike, now = new Date()) {
  const seconds = Math.round((now.getTime() - toDate(d).getTime()) / 1000);
  if (seconds < 45) return "just now";
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.round(hours / 24);
  if (days === 1) return "yesterday";
  if (days < 7) return `${days}d ago`;
  return formatDate(d);
}

const numberFmt = new Intl.NumberFormat("en-US");

export function formatNumber(n: number) {
  return numberFmt.format(n);
}

export function formatMoney(amount: number | string, currency = "USD") {
  return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(Number(amount));
}

export function formatPercent(value: number) {
  return `${(value * 100).toFixed(value > 0 && value < 0.1 ? 1 : 0)}%`;
}

/** "Oct 3" for an API day string such as "2026-10-03" (already in the business timezone) */
export function formatDayLabel(day: string) {
  return new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", timeZone: "UTC" }).format(
    new Date(`${day}T00:00:00Z`)
  );
}
