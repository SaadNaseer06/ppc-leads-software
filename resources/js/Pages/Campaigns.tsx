import { useEffect, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { Info, Megaphone } from "lucide-react";
import type { CampaignRow, CampaignsData } from "@/lib/api-types";
import { formatDateTime, formatNumber, formatPercent, formatRelative } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { CampaignName } from "@/components/campaign-name";

const PERIOD_LABELS: Record<number, string> = { 7: "Last 7 days", 30: "Last 30 days", 90: "Last 90 days", 365: "Last 12 months" };

/** Which ad campaigns bring leads, with the names the team knows them by */
export default function Campaigns(props: CampaignsData) {
  const { days, from, brand, periods, brands, truncated } = props;
  // Names change in place; the list itself comes from the server
  const [campaigns, setCampaigns] = useState(props.campaigns);
  useEffect(() => setCampaigns(props.campaigns), [props.campaigns]);

  const now = new Date();
  const unnamed = campaigns.filter((c) => c.is_id && !c.name).length;
  const totalLeads = campaigns.reduce((sum, c) => sum + c.leads, 0);
  const showBrand = brands.length > 1 && !brand;

  function visit(changes: { days?: number; brand?: number | null }) {
    const next = { days, brand, ...changes };
    const q = new URLSearchParams();
    if (next.days !== 30) q.set("days", String(next.days));
    if (next.brand) q.set("brand", String(next.brand));
    router.visit(`/campaigns${q.toString() ? `?${q}` : ""}`, { preserveScroll: true, replace: true });
  }

  function rename(row: CampaignRow, name: string | null) {
    setCampaigns((list) =>
      list.map((c) =>
        c.brand_id === row.brand_id && c.campaign === row.campaign
          ? { ...c, name, label: name ?? c.tag ?? (c.is_id ? `Campaign ${c.campaign}` : c.campaign) }
          : c
      )
    );
  }

  const leadsHref = (c: CampaignRow) =>
    `/leads?${new URLSearchParams({ campaign: c.campaign, brand: String(c.brand_id), from })}`;

  return (
    <div className="space-y-5">
      <Head title="Campaigns" />
      <PageHeader
        title="Campaigns"
        description="The ad campaigns your leads came from. Name a campaign once and every lead from it shows that name."
        actions={
          <div className="flex flex-wrap gap-2">
            {brands.length > 1 ? (
              <select
                className={`input w-auto max-w-[200px] ${brand ? "border-teal-500 bg-teal-50/40" : ""}`}
                value={brand ?? ""}
                onChange={(e) => visit({ brand: e.target.value ? Number(e.target.value) : null })}
                aria-label="Brand"
              >
                <option value="">All brands</option>
                {brands.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </select>
            ) : null}
            <select className="input w-auto" value={days} onChange={(e) => visit({ days: Number(e.target.value) })} aria-label="Period">
              {periods.map((p) => (
                <option key={p} value={p}>
                  {PERIOD_LABELS[p] ?? `Last ${p} days`}
                </option>
              ))}
            </select>
          </div>
        }
      />

      {unnamed > 0 ? (
        <div className="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <Info className="mt-0.5 h-4 w-4 shrink-0" />
          <p>
            <span className="font-medium">
              {unnamed === 1 ? "1 campaign shows only its ID." : `${unnamed} campaigns show only their ID.`}
            </span>{" "}
            Google sends the campaign's ID with each ad click, not its name. To find the name, open Google Ads &gt;
            Campaigns, add the <span className="font-medium">Campaign ID</span> column, then click the pencil next to the
            ID here and type the name.
          </p>
        </div>
      ) : null}

      <section className="card overflow-hidden">
        <div className="card-header">
          <h2 className="card-title">
            {campaigns.length === 0
              ? PERIOD_LABELS[days]
              : `${formatNumber(campaigns.length)} ${campaigns.length === 1 ? "campaign" : "campaigns"} · ${formatNumber(totalLeads)} ${totalLeads === 1 ? "lead" : "leads"}`}
          </h2>
        </div>
        {campaigns.length === 0 ? (
          <EmptyState
            icon={<Megaphone className="h-5 w-5" />}
            title="No leads from ad campaigns in this period"
            description="Leads show their campaign when they arrive from an ad on a website with the PPC Leads script. Google Ads does this by itself. For other platforms, add utm_campaign to the ad's URL."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Campaign</th>
                  {showBrand ? <th>Brand</th> : null}
                  <th className="text-right">Leads</th>
                  <th className="text-right">Converted</th>
                  <th className="whitespace-nowrap">Last lead</th>
                </tr>
              </thead>
              <tbody>
                {campaigns.map((c) => (
                  <tr key={`${c.brand_id}|${c.campaign}`}>
                    <td className="min-w-[220px] max-w-[380px]">
                      <span className={`block font-medium ${c.name || !c.is_id ? "text-slate-900" : "text-slate-500"}`}>
                        <CampaignName
                          brandId={c.brand_id}
                          campaign={c.campaign}
                          name={c.name}
                          label={c.label}
                          canEdit
                          onSaved={(name) => rename(c, name)}
                        />
                      </span>
                      <span className="block truncate text-xs text-slate-500">
                        {[c.platform, c.is_id ? `ID ${c.campaign}` : null, c.tag && c.tag !== c.label ? `Tag "${c.tag}"` : null]
                          .filter(Boolean)
                          .join(" · ") || "UTM tag"}
                      </span>
                    </td>
                    {showBrand ? <td className="whitespace-nowrap text-slate-700">{c.brand ?? "—"}</td> : null}
                    <td className="text-right tabular-nums">
                      <Link href={leadsHref(c)} className="font-medium text-teal-700 hover:underline" title="Show these leads">
                        {formatNumber(c.leads)}
                      </Link>
                    </td>
                    <td className="whitespace-nowrap text-right tabular-nums">
                      {c.converted > 0 ? (
                        <>
                          {formatNumber(c.converted)}
                          <span className="ml-1 text-xs text-slate-500">({formatPercent(c.converted / c.leads)})</span>
                        </>
                      ) : (
                        <span className="text-slate-400">0</span>
                      )}
                    </td>
                    <td className="whitespace-nowrap text-slate-600" title={formatDateTime(c.last_lead_at)}>
                      {formatRelative(c.last_lead_at, now)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {truncated ? (
          <p className="border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500">
            Showing the {formatNumber(campaigns.length)} campaigns with the most leads. Pick a brand or a shorter period to see the rest.
          </p>
        ) : null}
      </section>
    </div>
  );
}
