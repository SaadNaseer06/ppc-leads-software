import {
  CHANNEL_LABELS,
  labelFor,
  SOURCE_LABELS,
  STATUS_LABELS,
} from "@/lib/roles";

const STATUS_STYLES: Record<string, string> = {
  new: "bg-blue-50 text-blue-700 border-blue-100",
  contacted: "bg-amber-50 text-amber-800 border-amber-100",
  qualified: "bg-violet-50 text-violet-700 border-violet-100",
  converted: "bg-emerald-50 text-emerald-700 border-emerald-100",
  lost: "bg-slate-100 text-slate-600 border-slate-200",
  spam: "bg-red-50 text-red-700 border-red-100",
};

const CHANNEL_STYLES: Record<string, string> = {
  ppc: "bg-teal-50 text-teal-800 border-teal-100",
  organic: "bg-green-50 text-green-700 border-green-100",
  social: "bg-pink-50 text-pink-700 border-pink-100",
  referral: "bg-indigo-50 text-indigo-700 border-indigo-100",
  direct: "bg-slate-100 text-slate-700 border-slate-200",
  unknown: "bg-slate-50 text-slate-500 border-slate-200",
};

export function StatusBadge({ status }: { status: string }) {
  return (
    <span className={`badge badge-dot ${STATUS_STYLES[status] ?? ""}`}>
      {labelFor(STATUS_LABELS, status)}
    </span>
  );
}

export function ChannelBadge({ channel }: { channel: string }) {
  return (
    <span className={`badge ${CHANNEL_STYLES[channel] ?? ""}`}>
      {labelFor(CHANNEL_LABELS, channel)}
    </span>
  );
}

export function SourceBadge({ source }: { source: string }) {
  return <span className="badge">{labelFor(SOURCE_LABELS, source)}</span>;
}
