<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ $theme['accent'] }}">
    <title>@yield('title')</title>
    @yield('head')
    <style>
        :root {
            --accent: {{ $theme['accent'] }}; --on-accent: {{ $theme['on_accent'] }}; --accent-ink: {{ $theme['ink'] }}; --accent-soft: {{ $theme['soft'] }};
            --ink: #0f172a; --body: #334155; --muted: #64748b; --line: #e2e8f0; --hair: #f1f5f9; --page: #f3f4f7;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--page); color: var(--ink); font: 14.5px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        a { color: var(--accent-ink); }
        h1, h2 { margin: 0; letter-spacing: -.01em; }

        /* The brand's colour behind the top of the page: the logo does the talking, on white */
        .band { position: absolute; inset: 0 0 auto; height: 132px; background: var(--accent); }

        .shell { position: relative; display: grid; grid-template-columns: minmax(0, 1fr) 372px; gap: 20px; align-items: start; max-width: 1120px; margin: 0 auto; padding: 20px; }
        .paper { background: #fff; border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 12px 34px rgba(15, 23, 42, .08); }
        .doc { display: flex; flex-direction: column; overflow: hidden; }

        .head { display: flex; flex: none; align-items: center; justify-content: space-between; gap: 24px; padding: 20px 30px 16px; }
        .head-logo { display: block; max-width: 210px; max-height: 58px; }
        .head-name { font-size: 21px; font-weight: 700; letter-spacing: -.015em; line-height: 1.25; overflow-wrap: anywhere; }
        .head-right { flex: none; text-align: right; }
        .head-title { font-size: 23px; font-weight: 300; letter-spacing: .14em; line-height: 1.15; color: var(--accent-ink); }
        .head-meta { display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 4px; font-size: 13.5px; font-weight: 600; color: var(--body); }

        .info { display: grid; flex: none; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1.4fr) minmax(0, .8fr) minmax(0, .8fr); gap: 14px 20px; padding: 13px 30px; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .label { margin-bottom: 3px; font-size: 10.5px; font-weight: 650; letter-spacing: .07em; text-transform: uppercase; color: var(--muted); }
        .strong { font-weight: 600; overflow-wrap: anywhere; }
        .late { color: #b91c1c; }
        .muted { color: var(--muted); }
        .small { font-size: 13px; line-height: 1.4; }
        .pre { white-space: pre-line; overflow-wrap: anywhere; }
        .pill { display: inline-block; padding: 1px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .pill.unpaid { background: #eff6ff; color: #1d4ed8; }
        .pill.overdue { background: #fef2f2; color: #b91c1c; }
        .pill.paid { background: #ecfdf5; color: #047857; }
        .pill.cancelled { background: #f1f5f9; color: #475569; }
        .pill.test { background: var(--accent-soft); color: var(--accent-ink); }

        /* The shadow at the edge only shows while there is more to scroll to */
        .items {
            flex: 0 1 auto; min-height: 0; margin: 14px 30px 0; overflow-y: auto; scrollbar-width: thin;
            background:
                linear-gradient(#fff 30%, rgba(255, 255, 255, 0)) center top / 100% 28px no-repeat local,
                linear-gradient(rgba(255, 255, 255, 0), #fff 70%) center bottom / 100% 28px no-repeat local,
                radial-gradient(farthest-side at 50% 100%, rgba(15, 23, 42, .16), transparent) center bottom / 100% 10px no-repeat scroll;
        }
        table { width: 100%; border-collapse: collapse; }
        th { position: sticky; top: 0; z-index: 1; padding: 7px 12px; background: var(--accent-soft); color: var(--accent-ink); text-align: left; font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        th:first-child { border-radius: 8px 0 0 8px; }
        th:last-child { border-radius: 0 8px 8px 0; }
        td { padding: 8px 12px; border-bottom: 1px solid var(--hair); vertical-align: top; color: var(--body); line-height: 1.4; overflow-wrap: anywhere; }
        td:first-child { color: var(--ink); }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        td.num:last-child { color: var(--ink); font-weight: 500; }

        .summary { display: flex; flex: none; align-items: flex-end; justify-content: space-between; gap: 24px; padding: 12px 30px 14px; }
        .note { flex: 1 1 0; min-width: 0; max-height: 112px; overflow-y: auto; padding: 10px 12px; border-radius: 10px; background: #f8fafc; font-size: 13px; line-height: 1.45; }
        .summary-right { flex: none; width: 300px; margin-left: auto; }
        .totals { padding: 0 12px; font-variant-numeric: tabular-nums; }
        .totals div { display: flex; justify-content: space-between; gap: 24px; padding: 2px 0; }
        .totals .sum { margin-top: 4px; padding-top: 6px; border-top: 1px solid var(--line); font-weight: 600; }
        .owed { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 8px; padding: 8px 12px; border-radius: 10px; background: var(--accent-soft); color: var(--accent-ink); font-weight: 700; font-variant-numeric: tabular-nums; }
        .owed strong { font-size: 18px; letter-spacing: -.01em; }
        .doc-foot { display: flex; flex: none; flex-wrap: wrap; justify-content: space-between; gap: 2px 24px; padding: 9px 30px 11px; border-top: 1px solid var(--line); font-size: 12.5px; color: var(--muted); }

        .panel { padding: 18px 22px 14px; }
        .panel-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .panel-top .label { margin: 0; }
        .panel-amount { margin-top: 2px; font-size: 29px; font-weight: 750; letter-spacing: -.02em; line-height: 1.15; font-variant-numeric: tabular-nums; }
        .paying { margin: 1px 0 12px; font-size: 13.5px; color: var(--muted); overflow-wrap: anywhere; }
        .paying strong { color: var(--ink); font-weight: 600; }
        .rule { margin: 0 0 12px; border: 0; border-top: 1px solid var(--line); }
        .loading { padding: 10px 0 80px; font-size: 14px; color: var(--muted); }
        .btn { display: block; width: 100%; margin-top: 12px; padding: 12px 18px; border: 0; border-radius: 11px; background: var(--accent); color: var(--on-accent); font: inherit; font-size: 15.5px; font-weight: 650; text-align: center; cursor: pointer; transition: filter .15s; }
        .btn:hover { filter: brightness(.93); }
        .btn:disabled { opacity: .6; cursor: progress; }
        .btn:focus-visible { outline: 3px solid var(--accent-soft); outline-offset: 2px; }
        .secure { display: flex; justify-content: center; gap: 6px; margin: 9px 0 0; font-size: 12px; line-height: 1.4; color: var(--muted); }
        .secure svg { flex: none; width: 13px; height: 13px; margin-top: 1px; }
        .or { margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--line); font-size: 13px; line-height: 1.4; }
        .notice { margin-bottom: 10px; padding: 9px 12px; border-radius: 10px; font-size: 13.5px; }
        .notice.error { background: #fef2f2; color: #b91c1c; }
        .notice.info { background: var(--accent-soft); color: var(--accent-ink); }
        .state { padding: 8px 0 4px; text-align: center; }
        .state-icon { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; margin-bottom: 12px; border-radius: 50%; background: #ecfdf5; color: #047857; }
        .state-icon.off { background: #f1f5f9; color: #64748b; }
        .state-icon svg { width: 26px; height: 26px; }
        .state p { margin: 6px 0 0; color: var(--muted); font-size: 14px; }
        .pdf { display: block; margin-top: 8px; font-size: 13px; font-weight: 600; text-align: center; text-decoration: none; }
        .pdf:hover { text-decoration: underline; }
        .jump { display: none; flex: none; margin: 12px 30px 0; padding: 12px 18px; border-radius: 11px; background: var(--accent); color: var(--on-accent); font-weight: 650; text-align: center; text-decoration: none; }
        .show-sm { display: none; }
        .plain { max-width: 520px; margin: 0 auto; padding: 72px 20px; }
        [hidden] { display: none !important; }

        /* On a computer the whole invoice stays on one screen. The page itself never scrolls: if an
           invoice has too many items to fit, only the item list scrolls, so the totals and the Pay
           button stay in view. Screens too short to fit it scroll as normal. */
        @media (min-width: 981px) and (min-height: 600px) {
            body { height: 100vh; height: 100dvh; overflow: hidden; }
            .shell { height: 100%; grid-template-rows: minmax(0, 1fr); }
            .doc, .panel { max-height: 100%; }
            .panel { overflow-y: auto; }
        }
        @media (max-height: 720px) and (min-width: 981px) {
            .shell { padding-top: 12px; padding-bottom: 12px; }
            .head { padding-top: 14px; padding-bottom: 10px; }
            .head-logo { max-height: 46px; }
            .info { padding-top: 9px; padding-bottom: 9px; }
            .items { margin-top: 10px; }
            td { padding-top: 6px; padding-bottom: 6px; }
            .summary { padding-top: 8px; padding-bottom: 10px; }
            .doc-foot { padding-top: 6px; padding-bottom: 7px; }
            .panel { padding-top: 13px; padding-bottom: 10px; }
            .panel-amount { font-size: 25px; }
            .paying { margin-bottom: 8px; }
            .rule { margin-bottom: 8px; }
            .btn { margin-top: 9px; padding-top: 10px; padding-bottom: 10px; }
        }
        @media (max-height: 640px) and (min-width: 981px) {
            .doc-foot { display: none; }
        }
        @media print {
            body { height: auto; overflow: visible; background: #fff; }
            .band, .panel, .jump { display: none; }
            .shell { display: block; height: auto; max-width: none; margin: 0; padding: 0; }
            .paper { border: 0; box-shadow: none; }
            .doc { max-height: none; overflow: visible; }
            .items { overflow: visible; }
        }
        @media (max-width: 980px) {
            .shell { grid-template-columns: minmax(0, 1fr); }
            .jump { display: block; }
            .note { max-height: none; }
        }
        @media (max-width: 720px) {
            .info { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 600px) {
            .band { height: 112px; }
            .shell { padding: 12px; }
            .head { padding: 18px 18px 14px; gap: 14px; }
            .head-logo { max-width: 150px; max-height: 52px; }
            .head-name { font-size: 18px; }
            .head-title { font-size: 19px; }
            .info { padding: 12px 18px; }
            .items { margin: 14px 18px 0; }
            th, td { padding-left: 8px; padding-right: 8px; }
            .summary { flex-direction: column; align-items: stretch; gap: 12px; padding: 12px 18px 14px; }
            .note { flex: none; }
            .summary-right { width: auto; margin-left: 0; }
            .doc-foot { padding: 10px 18px 12px; }
            .panel { padding: 18px; }
            .jump { margin: 12px 18px 0; }
            .hide-sm { display: none; }
            .show-sm { display: block; }
        }
    </style>
</head>
<body>
    @yield('content')
</body>
</html>
