/**
 * Faint, repeated name and date across the screen for anyone who sees masked contacts: a photo or
 * screenshot of a lead always shows who took it. It never blocks clicks or selection.
 */
export function Watermark({ name, email }: { name: string; email: string }) {
  const date = new Date().toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
  const label = `${name} · ${email} · ${date}`.replace(/[<>&"']/g, "");
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="420" height="220"><text x="0" y="120" transform="rotate(-24 210 110)" fill="rgba(15,23,42,0.07)" font-family="system-ui, sans-serif" font-size="14">${label}</text></svg>`;

  return (
    <div
      aria-hidden
      data-watermark
      className="pointer-events-none fixed inset-0 z-[60] select-none print:block"
      style={{ backgroundImage: `url("data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}")`, backgroundRepeat: "repeat" }}
    />
  );
}
