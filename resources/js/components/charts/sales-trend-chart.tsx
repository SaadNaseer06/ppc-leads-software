import { useState } from "react";
import { formatMoney } from "@/lib/format";

type Point = { bucket: string; label: string; collected: number; outstanding: number; invoiced: number };

// Validated pair (CVD and contrast) on the white card. Teal is the product's own series color.
const COLLECTED = "#0d9488";
const OUTSTANDING = "#6250d6";
const PLOT_HEIGHT = 220;
const AXIS_WIDTH = 44;

function niceMax(value: number) {
  if (value <= 4) return 4;
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const step = [1, 2, 2.5, 5, 10].map((s) => s * magnitude).find((s) => s * 4 >= value) ?? magnitude * 10;
  return step * 4;
}

function compact(n: number) {
  return new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 1 }).format(n);
}

function Swatch({ color }: { color: string }) {
  return <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ background: color }} />;
}

/** Invoiced per period as stacked columns: what came in, and what is still owed */
export function SalesTrendChart({ data }: { data: Point[] }) {
  const [active, setActive] = useState<number | null>(null);
  const max = niceMax(Math.max(...data.map((d) => d.invoiced), 0));
  const ticks = [0, 1, 2, 3, 4].map((i) => (max / 4) * i);
  const labelEvery = Math.ceil(data.length / 7);
  const point = active !== null ? data[active] : null;
  const height = (value: number) => (value <= 0 ? 0 : Math.max(2, (value / max) * PLOT_HEIGHT));

  return (
    <div>
      <div className="mb-3 flex flex-wrap gap-4 text-xs text-slate-600">
        <span className="inline-flex items-center gap-1.5">
          <Swatch color={COLLECTED} />
          Collected
        </span>
        <span className="inline-flex items-center gap-1.5">
          <Swatch color={OUTSTANDING} />
          Outstanding
        </span>
      </div>

      <div className="relative flex gap-3">
        <div className="relative shrink-0 text-right text-[11px] tabular-nums text-slate-400" style={{ height: PLOT_HEIGHT, width: AXIS_WIDTH }}>
          {ticks.map((t) => (
            <span key={t} className="absolute right-0 -translate-y-1/2" style={{ top: PLOT_HEIGHT - (t / max) * PLOT_HEIGHT }}>
              ${compact(t)}
            </span>
          ))}
        </div>

        <div className="relative min-w-0 flex-1" onMouseLeave={() => setActive(null)}>
          {ticks.map((t) => (
            <div
              key={t}
              className="pointer-events-none absolute inset-x-0 border-t border-slate-100"
              style={{ top: PLOT_HEIGHT - (t / max) * PLOT_HEIGHT }}
            />
          ))}

          <div className="relative flex items-end gap-[2px]" style={{ height: PLOT_HEIGHT }}>
            {data.map((d, i) => (
              <div
                key={d.bucket}
                role="img"
                tabIndex={0}
                aria-label={`${d.label}: ${formatMoney(d.collected)} collected, ${formatMoney(d.outstanding)} outstanding`}
                className="flex h-full min-w-0 flex-1 cursor-default flex-col items-center justify-end outline-none"
                onMouseEnter={() => setActive(i)}
                onFocus={() => setActive(i)}
                onBlur={() => setActive(null)}
              >
                <div
                  className={`w-full max-w-8 rounded-t transition-opacity ${active !== null && active !== i ? "opacity-60" : ""}`}
                  style={{ height: height(d.outstanding), background: OUTSTANDING }}
                />
                {/* 2px gap between the two segments, in the card's own color */}
                {d.outstanding > 0 && d.collected > 0 ? <div className="h-[2px] w-full max-w-8 shrink-0 bg-white" /> : null}
                <div
                  className={`w-full max-w-8 transition-opacity ${d.outstanding > 0 ? "" : "rounded-t"} ${active !== null && active !== i ? "opacity-60" : ""}`}
                  style={{ height: height(d.collected), background: COLLECTED }}
                />
              </div>
            ))}
          </div>

          {point && active !== null ? (
            <div
              className="pointer-events-none absolute z-10 w-max -translate-x-1/2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-md"
              style={{
                left: `min(max(${((active + 0.5) / data.length) * 100}%, 90px), calc(100% - 90px))`,
                bottom: Math.min(PLOT_HEIGHT - 80, (point.invoiced / max) * PLOT_HEIGHT) + 34,
              }}
            >
              <p className="font-semibold text-slate-900">{point.label}</p>
              <p className="mt-1 flex items-center justify-between gap-4 text-slate-600">
                <span className="inline-flex items-center gap-1.5">
                  <Swatch color={COLLECTED} />
                  Collected
                </span>
                <span className="tabular-nums text-slate-900">{formatMoney(point.collected)}</span>
              </p>
              <p className="mt-0.5 flex items-center justify-between gap-4 text-slate-600">
                <span className="inline-flex items-center gap-1.5">
                  <Swatch color={OUTSTANDING} />
                  Outstanding
                </span>
                <span className="tabular-nums text-slate-900">{formatMoney(point.outstanding)}</span>
              </p>
            </div>
          ) : null}

          <div className="mt-2 flex gap-[2px] border-t border-slate-200 pt-1.5">
            {data.map((d, i) => (
              <span key={d.bucket} className="min-w-0 flex-1 overflow-visible whitespace-nowrap text-center text-[11px] text-slate-400">
                {i % labelEvery === 0 ? d.label : ""}
              </span>
            ))}
          </div>
        </div>
      </div>

      <details className="mt-3 text-sm">
        <summary className="cursor-pointer text-xs font-medium text-slate-500 hover:text-slate-700">View as table</summary>
        <div className="mt-2 max-h-56 overflow-y-auto rounded-lg border border-slate-200">
          <table className="table">
            <thead>
              <tr>
                <th>Period</th>
                <th className="text-right">Invoiced</th>
                <th className="text-right">Collected</th>
                <th className="text-right">Outstanding</th>
              </tr>
            </thead>
            <tbody>
              {data.map((d) => (
                <tr key={d.bucket}>
                  <td>{d.label}</td>
                  <td className="text-right tabular-nums">{formatMoney(d.invoiced)}</td>
                  <td className="text-right tabular-nums">{formatMoney(d.collected)}</td>
                  <td className="text-right tabular-nums">{formatMoney(d.outstanding)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </div>
  );
}
