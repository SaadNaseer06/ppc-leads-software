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
            --ink: #0f172a; --body: #334155; --muted: #64748b; --line: #e2e8f0; --hair: #f1f5f9; --page: #f6f7f9;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--page); color: var(--ink); font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        a { color: var(--accent-ink); }
        .band { background: var(--accent); color: var(--on-accent); padding: 30px 20px 104px; }
        .band-in { max-width: 1040px; margin: 0 auto; }
        .shell, .foot { max-width: 1080px; margin: 0 auto; }
        .band-in { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 18px 32px; }
        .who { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .logo { display: flex; align-items: center; justify-content: center; flex: none; height: 56px; min-width: 56px; max-width: 170px; padding: 8px 10px; border-radius: 12px; background: #fff; box-shadow: 0 1px 3px rgba(15, 23, 42, .18); }
        .logo img { display: block; max-width: 100%; max-height: 100%; }
        .merchant { font-size: 21px; font-weight: 700; letter-spacing: -.01em; line-height: 1.25; overflow-wrap: anywhere; }
        .sub { font-size: 13.5px; opacity: .85; }
        .due { text-align: right; }
        .due-amount { font-size: 34px; font-weight: 750; letter-spacing: -.02em; line-height: 1.15; font-variant-numeric: tabular-nums; }
        .jump { display: none; margin-top: 10px; padding: 9px 22px; border-radius: 10px; background: var(--on-accent); color: var(--accent); font-weight: 650; text-decoration: none; }
        .shell { display: grid; grid-template-columns: minmax(0, 1fr) 392px; gap: 24px; align-items: start; margin-top: -76px; padding: 0 20px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 10px 30px rgba(15, 23, 42, .07); }
        .sheet-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 22px 28px; border-bottom: 1px solid var(--line); }
        h1 { margin: 0; font-size: 20px; letter-spacing: -.01em; }
        h2 { margin: 0; font-size: 17px; letter-spacing: -.01em; }
        .section { padding: 22px 28px; }
        .section + .section { border-top: 1px solid var(--line); }
        .parties { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 20px 28px; }
        .label { margin-bottom: 4px; font-size: 11.5px; font-weight: 650; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
        .strong { font-weight: 600; }
        .muted { color: var(--muted); }
        .small { font-size: 13.5px; }
        .pre { white-space: pre-line; overflow-wrap: anywhere; }
        .pill { display: inline-block; padding: 3px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; white-space: nowrap; }
        .pill.unpaid { background: #eff6ff; color: #1d4ed8; }
        .pill.overdue { background: #fef2f2; color: #b91c1c; }
        .pill.paid { background: #ecfdf5; color: #047857; }
        .pill.cancelled { background: #f1f5f9; color: #475569; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 0 0 10px; border-bottom: 1px solid var(--line); text-align: left; font-size: 11.5px; font-weight: 650; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
        td { padding: 13px 0; border-bottom: 1px solid var(--hair); vertical-align: top; color: var(--body); overflow-wrap: anywhere; }
        td:first-child { color: var(--ink); }
        .num { padding-left: 16px; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .totals { max-width: 320px; margin: 18px 0 0 auto; font-variant-numeric: tabular-nums; }
        .totals div { display: flex; justify-content: space-between; gap: 24px; padding: 4px 0; }
        .totals .grand { margin-top: 8px; padding-top: 12px; border-top: 1px solid var(--line); font-size: 18px; font-weight: 700; }
        .panel { position: sticky; top: 20px; padding: 24px; }
        .paying { margin: 4px 0 18px; font-size: 14px; color: var(--muted); }
        .paying strong { color: var(--ink); font-weight: 600; }
        #card-element { min-height: 120px; }
        .loading { padding: 14px 0; font-size: 14px; color: var(--muted); }
        .btn { display: block; width: 100%; margin-top: 18px; padding: 14px 18px; border: 0; border-radius: 12px; background: var(--accent); color: var(--on-accent); font: inherit; font-size: 16px; font-weight: 650; text-align: center; cursor: pointer; transition: filter .15s; }
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
        .ghost { display: block; margin-top: 18px; padding: 11px 14px; border: 1px solid var(--line); border-radius: 10px; color: var(--ink); font-size: 14px; font-weight: 600; text-align: center; text-decoration: none; }
        .ghost:hover { background: var(--hair); }
        .foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px 24px; padding: 22px 20px 36px; font-size: 13px; color: var(--muted); }
        .show-sm { display: none; }
        .plain { max-width: 520px; margin: 0 auto; padding: 72px 20px; }
        [hidden] { display: none !important; }
        @media (max-width: 960px) {
            .band { padding-bottom: 92px; }
            .shell { grid-template-columns: minmax(0, 1fr); margin-top: -64px; }
            .panel { position: static; }
            .jump { display: inline-block; }
        }
        @media (max-width: 560px) {
            .band { padding: 22px 16px 84px; }
            .shell { padding: 0 12px; }
            .foot { padding-left: 16px; padding-right: 16px; }
            .due { width: 100%; text-align: left; }
            .due-amount { font-size: 30px; }
            .section, .sheet-head { padding-left: 18px; padding-right: 18px; }
            .panel { padding: 20px 18px; }
            .hide-sm { display: none; }
            .show-sm { display: block; }
        }
    </style>
</head>
<body>
    @yield('content')
</body>
</html>
