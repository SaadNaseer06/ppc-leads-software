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
        body { margin: 0; background: var(--page); color: var(--ink); font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        a { color: var(--accent-ink); }
        h1, h2 { margin: 0; letter-spacing: -.01em; }

        /* A slim strip of the brand's colour: the logo does the talking, on white, in the document */
        .strip { height: 148px; background: var(--accent); color: var(--on-accent); padding: 18px 20px 0; }
        .strip-in { display: flex; align-items: center; justify-content: space-between; gap: 16px; max-width: 1080px; margin: 0 auto; padding: 0 2px; font-size: 13.5px; }
        .strip-name { font-weight: 600; overflow-wrap: anywhere; }
        .strip-safe { display: inline-flex; align-items: center; gap: 6px; flex: none; opacity: .9; }
        .strip-safe svg { width: 13px; height: 13px; }

        .shell { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 24px; align-items: start; max-width: 1120px; margin: -92px auto 0; padding: 0 20px; }
        .paper { background: #fff; border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 12px 34px rgba(15, 23, 42, .08); }

        .head { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; padding: 34px 36px 26px; }
        .head-logo { display: block; max-width: 230px; max-height: 76px; }
        .head-name { font-size: 23px; font-weight: 700; letter-spacing: -.015em; line-height: 1.25; overflow-wrap: anywhere; }
        .head-right { flex: none; text-align: right; }
        .head-title { font-size: 27px; font-weight: 300; letter-spacing: .14em; line-height: 1.1; color: var(--accent-ink); }
        .head-number { margin: 5px 0 9px; font-size: 14px; font-weight: 600; color: var(--body); }

        .facts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); margin: 0 36px; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .fact { padding: 13px 0; }
        .fact + .fact { padding-left: 18px; border-left: 1px solid var(--line); }
        .fact-value { font-weight: 600; font-variant-numeric: tabular-nums; }
        .fact-value.late { color: #b91c1c; }

        .parties { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 22px 28px; padding: 24px 36px 6px; }
        .label { margin-bottom: 5px; font-size: 11px; font-weight: 650; letter-spacing: .07em; text-transform: uppercase; color: var(--muted); }
        .strong { font-weight: 600; overflow-wrap: anywhere; }
        .muted { color: var(--muted); }
        .small { font-size: 13.5px; }
        .pre { white-space: pre-line; overflow-wrap: anywhere; }
        .pill { display: inline-block; padding: 2px 11px; border-radius: 999px; font-size: 12.5px; font-weight: 600; white-space: nowrap; }
        .pill.unpaid { background: #eff6ff; color: #1d4ed8; }
        .pill.overdue { background: #fef2f2; color: #b91c1c; }
        .pill.paid { background: #ecfdf5; color: #047857; }
        .pill.cancelled { background: #f1f5f9; color: #475569; }

        .lines { padding: 20px 36px 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 9px 12px; background: var(--accent-soft); color: var(--accent-ink); text-align: left; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        th:first-child { border-radius: 8px 0 0 8px; }
        th:last-child { border-radius: 0 8px 8px 0; }
        td { padding: 13px 12px; border-bottom: 1px solid var(--hair); vertical-align: top; color: var(--body); overflow-wrap: anywhere; }
        td:first-child { color: var(--ink); }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        td.num:last-child { color: var(--ink); font-weight: 500; }

        .totals { max-width: 330px; margin: 14px 0 0 auto; padding: 0 12px; font-variant-numeric: tabular-nums; }
        .totals div { display: flex; justify-content: space-between; gap: 24px; padding: 4px 0; }
        .totals .sum { margin-top: 6px; padding-top: 10px; border-top: 1px solid var(--line); font-weight: 600; }
        .owed { display: flex; align-items: center; justify-content: space-between; gap: 16px; max-width: 330px; margin: 12px 0 0 auto; padding: 12px 14px; border-radius: 10px; background: var(--accent-soft); color: var(--accent-ink); font-weight: 700; font-variant-numeric: tabular-nums; }
        .owed strong { font-size: 19px; letter-spacing: -.01em; }

        .note { margin: 22px 36px 0; padding: 14px 16px; border-radius: 10px; background: #f8fafc; }
        .paper-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px 24px; margin-top: 26px; padding: 18px 36px 24px; border-top: 1px solid var(--line); font-size: 13px; color: var(--muted); }

        .panel { position: sticky; top: 20px; padding: 24px; }
        .panel-amount { margin: 2px 0 2px; font-size: 32px; font-weight: 750; letter-spacing: -.02em; line-height: 1.15; font-variant-numeric: tabular-nums; }
        .paying { margin: 0 0 18px; font-size: 14px; color: var(--muted); overflow-wrap: anywhere; }
        .paying strong { color: var(--ink); font-weight: 600; }
        .rule { margin: 0 0 18px; border: 0; border-top: 1px solid var(--line); }
        .loading { padding: 14px 0 90px; font-size: 14px; color: var(--muted); }
        .btn { display: block; width: 100%; margin-top: 18px; padding: 14px 18px; border: 0; border-radius: 11px; background: var(--accent); color: var(--on-accent); font: inherit; font-size: 16px; font-weight: 650; text-align: center; cursor: pointer; transition: filter .15s; }
        .btn:hover { filter: brightness(.93); }
        .btn:disabled { opacity: .6; cursor: progress; }
        .btn:focus-visible { outline: 3px solid var(--accent-soft); outline-offset: 2px; }
        .secure { display: flex; gap: 8px; margin: 14px 0 0; font-size: 12.5px; line-height: 1.45; color: var(--muted); }
        .secure svg { flex: none; width: 14px; height: 14px; margin-top: 2px; }
        .or { margin-top: 20px; padding-top: 18px; border-top: 1px solid var(--line); font-size: 14px; }
        .notice { margin-bottom: 16px; padding: 11px 14px; border-radius: 10px; font-size: 14px; }
        .notice.error { background: #fef2f2; color: #b91c1c; }
        .notice.info { background: var(--accent-soft); color: var(--accent-ink); }
        .state { padding: 8px 0 4px; text-align: center; }
        .state-icon { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; margin-bottom: 12px; border-radius: 50%; background: #ecfdf5; color: #047857; }
        .state-icon.off { background: #f1f5f9; color: #64748b; }
        .state-icon svg { width: 26px; height: 26px; }
        .state p { margin: 6px 0 0; color: var(--muted); font-size: 14px; }
        .ghost { display: block; margin-top: 16px; padding: 11px 14px; border: 1px solid var(--line); border-radius: 10px; color: var(--ink); font-size: 14px; font-weight: 600; text-align: center; text-decoration: none; }
        .ghost:hover { background: var(--hair); }
        .jump { display: none; margin-top: 14px; padding: 12px 18px; border-radius: 11px; background: var(--accent); color: var(--on-accent); font-weight: 650; text-align: center; text-decoration: none; }
        .below { height: 40px; }
        .show-sm { display: none; }
        .plain { max-width: 520px; margin: 0 auto; padding: 72px 20px; }
        [hidden] { display: none !important; }

        @media print {
            body { background: #fff; }
            .strip, .panel, .jump { display: none; }
            .shell { display: block; max-width: none; margin: 0; padding: 0; }
            .paper { border: 0; box-shadow: none; }
        }
        @media (max-width: 980px) {
            .shell { grid-template-columns: minmax(0, 1fr); }
            .panel { position: static; }
            .jump { display: block; margin-left: 36px; margin-right: 36px; }
        }
        @media (max-width: 600px) {
            .strip { padding: 14px 16px 0; height: 128px; }
            .shell { padding: 0 12px; margin-top: -84px; }
            .head { padding: 22px 18px 18px; gap: 14px; }
            .head-logo { max-width: 150px; max-height: 56px; }
            .head-name { font-size: 19px; }
            .head-title { font-size: 20px; }
            .facts { margin: 0 18px; }
            .fact + .fact { padding-left: 10px; }
            .fact-value { font-size: 13.5px; }
            .parties { grid-template-columns: minmax(0, 1fr); padding: 20px 18px 4px; }
            .lines { padding: 18px 18px 6px; }
            th, td { padding-left: 8px; padding-right: 8px; }
            .note { margin: 18px 18px 0; }
            .paper-foot { padding: 16px 18px 18px; }
            .panel { padding: 20px 18px; }
            .jump { margin-left: 18px; margin-right: 18px; }
            .hide-sm { display: none; }
            .show-sm { display: block; }
        }
    </style>
</head>
<body>
    @yield('content')
</body>
</html>
