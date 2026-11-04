<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title')</title>
    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --accent: #0f766e; --accent-dark: #115e59; --page: #f1f5f9; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--page); color: var(--ink); font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .wrap { max-width: 720px; margin: 0 auto; padding: 32px 16px 48px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 8px 30px rgba(15, 23, 42, .06); overflow: hidden; }
        .top { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; padding: 24px; border-bottom: 1px solid var(--line); }
        .brand { font-size: 18px; font-weight: 700; }
        .muted { color: var(--muted); }
        .small { font-size: 13px; }
        .pill { display: inline-block; padding: 3px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; }
        .pill.unpaid { background: #eff6ff; color: #1d4ed8; }
        .pill.overdue { background: #fef2f2; color: #b91c1c; }
        .pill.paid { background: #ecfdf5; color: #047857; }
        .pill.cancelled { background: #f1f5f9; color: #475569; }
        .section { padding: 20px 24px; }
        .section + .section { border-top: 1px solid var(--line); }
        .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px 24px; }
        .label { font-size: 12px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: var(--muted); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: var(--muted); padding: 0 0 8px; border-bottom: 1px solid var(--line); }
        td { padding: 11px 0; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; padding-left: 12px; }
        th.num { padding-left: 12px; }
        .totals { margin: 16px 0 0 auto; max-width: 300px; }
        .totals div { display: flex; justify-content: space-between; gap: 24px; padding: 4px 0; }
        .totals .grand { font-size: 18px; font-weight: 700; border-top: 1px solid var(--line); margin-top: 6px; padding-top: 10px; }
        .btn { display: block; width: 100%; border: 0; border-radius: 12px; background: var(--accent); color: #fff; font: inherit; font-size: 16px; font-weight: 650; padding: 14px 18px; cursor: pointer; text-align: center; text-decoration: none; }
        .btn:hover { background: var(--accent-dark); }
        .link { color: var(--accent); font-weight: 600; text-decoration: none; }
        .link:hover { text-decoration: underline; }
        .notice { border-radius: 12px; padding: 12px 14px; font-size: 14px; }
        .notice.ok { background: #ecfdf5; color: #065f46; }
        .notice.error { background: #fef2f2; color: #b91c1c; margin-bottom: 12px; }
        .pre { white-space: pre-line; }
        .foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin-top: 16px; padding: 0 4px; font-size: 13px; color: var(--muted); }
        .show-sm { display: none; }
        @media (max-width: 520px) {
            .hide-sm { display: none; }
            .show-sm { display: block; }
            .wrap { padding-top: 16px; }
        }
    </style>
</head>
<body>
    <main class="wrap">
        @yield('content')
    </main>
</body>
</html>
