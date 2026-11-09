import { Head, Link } from "@inertiajs/react";
import { Eye } from "lucide-react";
import type { ContactLogData } from "@/lib/api-types";
import { formatDateTime } from "@/lib/format";
import { EmptyState, PageHeader } from "@/components/ui/page-header";

/** Who saw which client's full contact details, for managers to keep an eye on */
export default function ContactLog({ limit, people, recent }: ContactLogData) {
  return (
    <div className="space-y-5">
      <Head title="Contact log" />
      <PageHeader
        title="Contact log"
        description={`Sales reps see clients' email and phone masked, and reveal them one lead at a time. Each reveal is recorded here. The daily limit is ${limit} leads per person.`}
      />

      <section className="card overflow-hidden">
        <div className="card-header">
          <h2 className="card-title">By person, last 7 days</h2>
        </div>
        {people.length === 0 ? (
          <EmptyState icon={<Eye className="h-5 w-5" />} title="No contacts revealed this week" description="Reveals by sales reps and chat agents show up here." />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Person</th>
                  <th className="text-right">Today</th>
                  <th className="text-right">Last 7 days</th>
                  <th className="text-right">Hit the limit</th>
                </tr>
              </thead>
              <tbody>
                {people.map((p) => (
                  <tr key={p.id}>
                    <td>
                      <span className="font-medium text-slate-900">{p.name}</span>
                      {p.role ? <span className="block text-xs text-slate-500">{p.role}</span> : null}
                    </td>
                    <td className={`text-right tabular-nums ${p.today >= limit ? "font-semibold text-red-700" : ""}`}>
                      {p.today} / {limit}
                    </td>
                    <td className="text-right tabular-nums">{p.week}</td>
                    <td className={`text-right tabular-nums ${p.limit_hits > 0 ? "font-semibold text-red-700" : "text-slate-400"}`}>
                      {p.limit_hits > 0 ? `${p.limit_hits} time${p.limit_hits === 1 ? "" : "s"}` : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="card overflow-hidden">
        <div className="card-header">
          <h2 className="card-title">Latest reveals</h2>
        </div>
        {recent.length === 0 ? (
          <p className="p-4 text-sm text-slate-500">Nothing yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Person</th>
                  <th>Lead</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                {recent.map((r) => (
                  <tr key={r.id}>
                    <td className="whitespace-nowrap">{formatDateTime(r.at)}</td>
                    <td>{r.user ?? "A former user"}</td>
                    <td>
                      {r.lead ? (
                        <Link href={`/leads/${r.lead.id}`} className="text-teal-700 hover:underline">
                          {r.lead.name}
                        </Link>
                      ) : (
                        <span className="text-slate-400">Deleted lead</span>
                      )}
                    </td>
                    <td className="text-slate-600">{r.reason ?? <span className="text-slate-400">—</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
