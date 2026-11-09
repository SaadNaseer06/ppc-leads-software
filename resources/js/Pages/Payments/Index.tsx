import { useState } from "react";
import { Head } from "@inertiajs/react";
import { CircleAlert, CreditCard } from "lucide-react";
import type { BrandPayments, PaymentsData } from "@/lib/api-types";
import { EmptyState, PageHeader } from "@/components/ui/page-header";
import { Modal } from "@/components/ui/modal";
import { AccountForm } from "./partials/account-form";

/** Every brand's merchant and the Stripe account its clients' money goes to */
export default function PaymentsIndex({ brands, server }: PaymentsData) {
  const [editing, setEditing] = useState<BrandPayments | null>(null);
  const live = brands.filter((b) => b.card_payments).length;

  return (
    <div className="space-y-5">
      <Head title="Payments" />
      <PageHeader
        title="Payments"
        description="Who each brand's clients pay, and the Stripe account that receives the money. Keys are checked with Stripe before they're saved and kept encrypted."
      />

      {server.needs_reload || server.problem ? (
        <p className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <CircleAlert className="mt-0.5 h-4 w-4 shrink-0" />
          {server.problem
            ? `The Stripe keys in the server's .env have a problem: ${server.problem}`
            : "The server's .env has Stripe keys the app hasn't loaded yet. Reload the settings from the Invoices page, or set the keys for each brand here instead."}
        </p>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="Brands taking cards" value={`${live} of ${brands.length}`} />
        <Stat label="In live mode" value={String(brands.filter((b) => b.mode === "live").length)} />
        <Stat label="Without a webhook" value={String(brands.filter((b) => b.card_payments && !b.webhook).length)} />
      </div>

      <div className="card overflow-hidden">
        {brands.length === 0 ? (
          <EmptyState icon={<CreditCard className="h-5 w-5" />} title="No brands yet" description="Create a brand under Brands & routing first." />
        ) : (
          <div className="overflow-x-auto">
            <table className="table">
              <thead>
                <tr>
                  <th>Brand</th>
                  <th>Merchant name</th>
                  <th>Stripe account</th>
                  <th>Mode</th>
                  <th>Webhook</th>
                  <th>Card payments</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {brands.map((b) => (
                  <tr key={b.id}>
                    <td className="whitespace-nowrap font-medium text-slate-900">{b.name}</td>
                    <td className="max-w-[220px] truncate">{b.merchant_name ?? <span className="text-slate-400">Same as brand</span>}</td>
                    <td className="max-w-[220px] truncate">
                      {b.source === "own" ? (
                        <>
                          {b.account_name ?? "Connected"}
                          <span className="block font-mono text-xs text-slate-500">{b.secret_hint}</span>
                        </>
                      ) : b.source === "env" ? (
                        <span className="text-slate-600">Server .env keys</span>
                      ) : (
                        <span className="text-slate-400">None</span>
                      )}
                    </td>
                    <td>
                      {b.mode ? (
                        <span className={`badge ${b.mode === "live" ? "border-emerald-100 bg-emerald-50 text-emerald-700" : "border-amber-100 bg-amber-50 text-amber-800"}`}>
                          {b.mode === "live" ? "Live" : "Test"}
                        </span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td>{b.card_payments ? (b.webhook ? "Set" : <span className="text-amber-700">Missing</span>) : <span className="text-slate-400">—</span>}</td>
                    <td>
                      <span className={`badge badge-dot ${b.card_payments ? "border-emerald-100 bg-emerald-50 text-emerald-700" : "border-slate-200 bg-slate-100 text-slate-600"}`}>
                        {b.card_payments ? "On" : "Off"}
                      </span>
                    </td>
                    <td className="text-right">
                      <button type="button" className={b.source === "own" ? "btn-secondary btn-sm" : "btn-primary btn-sm"} onClick={() => setEditing(b)}>
                        {b.source === "own" ? "Edit" : "Set up"}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        wide
        title={editing ? `Payments: ${editing.name}` : "Payments"}
        description="Who clients pay, and the Stripe account that receives the money."
      >
        {editing ? <AccountForm key={editing.id} brand={editing} onClose={() => setEditing(null)} /> : null}
      </Modal>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="card p-4">
      <p className="text-sm text-slate-500">{label}</p>
      <p className="mt-1 text-xl font-semibold tabular-nums text-slate-900">{value}</p>
    </div>
  );
}
