import { useState } from "react";

type Point = { day: string; label: string; count: number };

// Teal-600: passes the lightness band, chroma floor and 3:1 contrast on the white card
const BAR = "#0d9488";
const BAR_ACTIVE = "#0f766e";
const PLOT_HEIGHT = 200;

function niceMax(value: number) {
  if (value <= 4) return 4;
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const step = [1, 2, 2.5, 5, 10].map((s) => s * magnitude).find((s) => s * 4 >= value) ?? magnitude * 10;
  return step * 4;
}

function compact(n: number) {
  return new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 1 }).format(n);
}

/** Daily leads as columns: 4px rounded tops, 2px gaps, per-column hover/focus tooltip, table fallback */
export function LeadsTrendChart({ data }: { data: Point[] }) {
  const [active, setActive] = useState<number | null>(null);
  const max = niceMax(Math.max(...data.map((d) => d.count), 0));
  const ticks = [0, 1, 2, 3, 4].map((i) => (max / 4) * i);
  const labelEvery = Math.ceil(data.length / 7);
  const point = active !== null ? data[active] : null;

  return (
    <div>
      <div className="relative flex gap-3">
        <div className="relative w-8 shrink-0 text-right text-[11px] tabular-nums text-slate-400" style={{ height: PLOT_HEIGHT }}>
          {ticks.map((t) => (
            <span key={t} className="absolute right-0 -translate-y-1/2" style={{ top: PLOT_HEIGHT - (t / max) * PLOT_HEIGHT }}>
              {compact(t)}
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
                key={d.day}
                role="img"
                tabIndex={0}
                aria-label={`${d.label}: ${d.count} leads`}
                className="flex h-full flex-1 cursor-default items-end justify-center outline-none"
                onMouseEnter={() => setActive(i)}
                onFocus={() => setActive(i)}
                onBlur={() => setActive(null)}
              >
                <div
                  className="w-full max-w-6 rounded-t transition-colors"
                  style={{
                    height: d.count === 0 ? 0 : Math.max(2, (d.count / max) * PLOT_HEIGHT),
                    background: active === i ? BAR_ACTIVE : BAR,
                  }}
                />
              </div>
            ))}
          </div>

          {point && active !== null ? (
            <div
              className="pointer-events-none absolute z-10 -translate-x-1/2 rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-md"
              style={{
                left: `${((active + 0.5) / data.length) * 100}%`,
                bottom: Math.min(PLOT_HEIGHT - 40, (point.count / max) * PLOT_HEIGHT) + 10,
              }}
            >
              <p className="whitespace-nowrap text-sm font-semibold text-slate-900">
                {point.count.toLocaleString("en-US")} lead{point.count === 1 ? "" : "s"}
              </p>
              <p className="whitespace-nowrap text-xs text-slate-500">{point.label}</p>
            </div>
          ) : null}

          <div className="mt-2 flex gap-[2px] border-t border-slate-200 pt-1.5">
            {data.map((d, i) => (
              <span key={d.day} className="flex-1 overflow-visible whitespace-nowrap text-center text-[11px] text-slate-400">
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
                <th>Day</th>
                <th className="text-right">Leads</th>
              </tr>
            </thead>
            <tbody>
              {data.map((d) => (
                <tr key={d.day}>
                  <td>{d.label}</td>
                  <td className="text-right tabular-nums">{d.count.toLocaleString("en-US")}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </div>
  );
}
