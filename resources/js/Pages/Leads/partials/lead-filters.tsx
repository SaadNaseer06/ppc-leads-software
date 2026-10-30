import { useEffect, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import { Loader2, Search, X } from "lucide-react";
import {
  CHANNEL_LABELS,
  LeadSource,
  LeadStatus,
  SOURCE_LABELS,
  STATUS_LABELS,
  Channel,
} from "@/lib/roles";
import type { LeadFilters } from "@/lib/api-types";

type Option = { id: string | number; name: string };

const PARAM_FOR: Record<keyof LeadFilters, string> = {
  q: "q",
  status: "status",
  source: "source",
  channel: "channel",
  website: "website",
  brand: "brand",
  assignee: "assignee",
  from: "from",
  to: "to",
};

export function LeadFiltersBar({
  filters,
  pageSize,
  websites,
  brands,
  assignees,
}: {
  filters: LeadFilters;
  pageSize: number;
  websites: (Option & { brand_id: number })[];
  brands: Option[];
  assignees: Option[];
}) {
  const [pending, setPending] = useState(false);
  const [query, setQuery] = useState(filters.q ?? "");
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);

  function navigate(changes: Partial<LeadFilters> & { size?: number }) {
    const next = { ...filters, ...changes };
    const params = new URLSearchParams();
    for (const [key, param] of Object.entries(PARAM_FOR) as [keyof LeadFilters, string][]) {
      const value = next[key];
      if (value) params.set(param, value);
    }
    const size = changes.size ?? pageSize;
    if (size !== 50) params.set("size", String(size));
    router.visit(`/leads${params.toString() ? `?${params}` : ""}`, {
      replace: true,
      preserveState: true,
      preserveScroll: true,
      onStart: () => setPending(true),
      onFinish: () => setPending(false),
    });
  }

  function onSearch(value: string) {
    setQuery(value);
    if (debounce.current) clearTimeout(debounce.current);
    debounce.current = setTimeout(() => navigate({ q: value.trim() || undefined }), 350);
  }

  useEffect(() => () => {
    if (debounce.current) clearTimeout(debounce.current);
  }, []);

  const active = Object.entries(filters).some(([k, v]) => v && k !== "assignee");

  return (
    <div className="card p-3">
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-[220px] flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            className="input pl-9"
            type="search"
            placeholder="Search name, email, phone or company…"
            value={query}
            onChange={(e) => onSearch(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                if (debounce.current) clearTimeout(debounce.current);
                navigate({ q: query.trim() || undefined });
              }
            }}
            aria-label="Search leads"
          />
          {pending ? (
            <Loader2 className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-slate-400" />
          ) : null}
        </div>

        <Select
          label="Status"
          value={filters.status}
          onChange={(v) => navigate({ status: v })}
          options={Object.values(LeadStatus).map((s) => ({ id: s, name: STATUS_LABELS[s] }))}
          allLabel="Any status"
        />
        {brands.length > 1 ? (
          <Select
            label="Brand"
            value={filters.brand}
            // A website from another brand would make the list empty, so reset it
            onChange={(v) => navigate({ brand: v, website: undefined })}
            options={brands}
            allLabel="All brands"
          />
        ) : null}
        <Select
          label="Website"
          value={filters.website}
          onChange={(v) => navigate({ website: v })}
          options={filters.brand ? websites.filter((w) => String(w.brand_id) === filters.brand) : websites}
          allLabel="All websites"
        />
        <Select
          label="Channel"
          value={filters.channel}
          onChange={(v) => navigate({ channel: v })}
          options={Object.values(Channel).map((c) => ({ id: c, name: CHANNEL_LABELS[c] }))}
          allLabel="All channels"
        />
        <Select
          label="Source"
          value={filters.source}
          onChange={(v) => navigate({ source: v })}
          options={Object.values(LeadSource).map((s) => ({ id: s, name: SOURCE_LABELS[s] }))}
          allLabel="All sources"
        />
        {assignees.length ? (
          <Select
            label="Assignee"
            value={filters.assignee}
            onChange={(v) => navigate({ assignee: v })}
            options={[{ id: "unassigned", name: "Unassigned" }, { id: "me", name: "Me" }, ...assignees]}
            allLabel="Anyone"
          />
        ) : null}

        <div className="flex items-center gap-1">
          <input
            type="date"
            className="input w-[140px]"
            value={filters.from ?? ""}
            onChange={(e) => navigate({ from: e.target.value || undefined })}
            aria-label="Received from"
          />
          <span className="text-slate-400">–</span>
          <input
            type="date"
            className="input w-[140px]"
            value={filters.to ?? ""}
            onChange={(e) => navigate({ to: e.target.value || undefined })}
            aria-label="Received to"
          />
        </div>

        <select
          className="input w-auto"
          value={pageSize}
          onChange={(e) => navigate({ size: Number(e.target.value) })}
          aria-label="Rows per page"
        >
          {[25, 50, 100].map((n) => (
            <option key={n} value={n}>
              {n} / page
            </option>
          ))}
        </select>

        {active ? (
          <button
            type="button"
            className="btn-ghost btn-sm"
            onClick={() => {
              setQuery("");
              navigate({
                q: undefined,
                status: undefined,
                source: undefined,
                channel: undefined,
                website: undefined,
                brand: undefined,
                from: undefined,
                to: undefined,
              });
            }}
          >
            <X className="h-3.5 w-3.5" />
            Clear filters
          </button>
        ) : null}
      </div>
    </div>
  );
}

function Select({
  label,
  value,
  onChange,
  options,
  allLabel,
}: {
  label: string;
  value?: string;
  onChange: (value: string | undefined) => void;
  options: Option[];
  allLabel: string;
}) {
  return (
    <select
      className={`input w-auto max-w-[200px] ${value ? "border-teal-500 bg-teal-50/40" : ""}`}
      value={value ?? ""}
      onChange={(e) => onChange(e.target.value || undefined)}
      aria-label={label}
    >
      <option value="">{allLabel}</option>
      {options.map((o) => (
        <option key={o.id} value={o.id}>
          {o.name}
        </option>
      ))}
    </select>
  );
}
