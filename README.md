# PPC Leads

Lead management for businesses running PPC on several websites. Form submissions from every website and
Tawk.to chat queries land in one dashboard, with the Google/Microsoft Ads click and campaign attached. Admins
assign them to the team, and every touchpoint is tracked until the lead converts.

One **Laravel 12** application (PHP 8.2+, **MySQL 8 / MariaDB 10.4+**). The screens are React, rendered through
Inertia and compiled once into `public/build`, so the server only needs PHP and MySQL. It runs on ordinary
**cPanel** hosting with no Node.js. See **[docs/DEPLOY-CPANEL.md](docs/DEPLOY-CPANEL.md)**.

```
 client websites ──(form embed / chat widget)──────┐
 Pusher cloud ────(realtime chat)──────────────────┤
 Tawk.to ─────────(legacy webhooks, optional)──────┤
 your team's browser ──(session cookie)────────────┼──► Laravel ──► MySQL
```

## What it does

- **Multi-website capture.** Each website gets a key. Leads arrive through a drop-in embed script that captures
  every form and stores ad attribution (gclid, UTM, landing page) for 90 days, or through a webhook URL
  (Elementor, WPForms, Gravity Forms, Zapier, custom code).
- **Native live chat.** Drop-in `ppc-chat.js` widget; agents reply from **Chats**. Pusher (cloud) delivers
  messages in realtime on shared hosting; polling is the fallback. First visitor message creates a lead.
- **Tawk.to (legacy).** Optional signed webhooks keep old chats as read-only history.
- **Assignment.** Assign one lead or hundreds at once. Each brand can rotate new leads round-robin across its
  Front Sales reps (safe under concurrent traffic). The assign menu shows each rep's open-lead workload.
  Deactivating a user signs them out and returns their open leads to the unassigned queue.
- **Live dashboard.** Sidebar badges and a "new leads" pill update the moment a lead arrives (Pusher pings), with
  polling only as a fallback. KPIs show PPC share, conversion rate and the unassigned queue, with charts per day,
  per website and per rep.
- **Lead timeline.** Creation, repeat submissions, assignments, status changes, notes and chats. Repeat submissions
  from the same email/phone within 30 days merge into the existing lead instead of duplicating it.
- **Roles.** Super Admin, Admin, Front Sales, Up Sales, Finance, Developer. Each user is scoped to their brands, and
  sales reps only see their own leads. Invoices with payment tracking (part payments, paid, cancelled), PDF downloads,
  finance and per-brand sales reports are included.

## Run it locally

Requires PHP 8.2+ with Composer, MySQL (XAMPP, a local install, or `docker compose up -d` for MySQL 8.4 on port
3307), and Node 20+ **only to rebuild the screens** after changing files in `resources/js` or `resources/css`.

```bash
composer install
cp .env.example .env          # DB_* (database ppc_leads), REPORT_TIMEZONE
php artisan key:generate
php artisan migrate --seed    # tables + the demo team accounts below (add brands and websites in the app)
php artisan serve             # http://127.0.0.1:8000
```

The built screens are committed in `public/build`, so that's enough to use the app. When you change the React
code, run `npm install` once and then `npm run dev` (live reload) or `npm run build` (before committing/deploying).

Every demo account uses the password `password123`:

| Email | Role | Sees |
|---|---|---|
| superadmin@ppc.lead | Super Admin | Everything |
| admin@ppc.lead | Admin | The brands given to them on the Team page; assigns leads, manages the team and websites |
| front1@ppc.lead | Front Sales | Only leads assigned to them |
| upsales@ppc.lead | Up Sales | Converted leads handed to them |
| finance@ppc.lead | Finance | Invoices, payments, reports |
| dev@ppc.lead | Developer | Everything, for testing (use staging data) |

For a real install, skip `--seed` and create the first account with
`php artisan ppc:create-admin you@company.com "Your Name"`.

## Code map

```
app/Enums/                       Role, LeadStatus, LeadSource, Channel
app/Models/                      Eloquent models; each has a visibleTo($user) scope = who may see what
app/Models/User.php              permission rules (canAssignLeads, canViewFinance, ...)
app/Services/LeadIntake.php      every new lead: field mapping, de-duplication, attribution, auto-assign
app/Services/NativeChatService.php  first-party chat sessions, replies, lead linking
app/Services/TawkService.php     Tawk.to signature check, chat/transcript/ticket handling (legacy)
public/ppc-chat.js               live chat widget for client websites
app/Services/LeadAssignment.php  assign, round-robin (row lock + deadlock retry), status changes
app/Services/LeadQuery.php       list filters, full-text search, keyset pagination, capped counts
app/Services/Dashboard.php       one-pass GROUP BY behind every dashboard number
app/Http/Controllers/Api/        JSON endpoints (the screens' buttons call these)
app/Http/Controllers/PageController.php   screens: render the same payloads through Inertia
resources/js/Pages/              the screens (React + TypeScript)
resources/js/components/         shared UI: sidebar, live updates, badges, modal, chart
public/ppc-leads.js              embed script served to client websites
public/build/                    compiled CSS/JS (committed; rebuild with npm run build)
tests/Feature/                   intake, Tawk.to, access control, paging/search/dashboard, screens
```

## Tests

Feature tests run against a real MySQL database, because they exercise MySQL-only behaviour (FULLTEXT search, row
locks, `CONVERT_TZ`):

```bash
mysql -uroot -e "CREATE DATABASE ppc_leads_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan test
npm run typecheck             # the React code
```

## API

The screens' buttons call these JSON endpoints with the session cookie and CSRF token. Other systems can use them
with a token from `POST /api/auth/login` (`Authorization: Bearer …`). Errors are `{ "error": "Readable message" }`.

| Public | |
|---|---|
| `POST /api/intake/{key}` | Lead from a website: JSON, form-encoded, multipart or `text/plain` JSON. Rate-limited 20 / 10 min per key and IP. |
| `POST /api/webhooks/tawk` | Tawk.to webhook (HMAC-SHA1 signed, retries de-duplicated by `X-Hook-Event-Id`). |
| `POST /api/auth/login` | `{email, password}` → `{token, user}` |

| Signed in | |
|---|---|
| `GET /api/me` · `GET /api/live` · `GET /api/lookups` · `GET /api/dashboard?range=7\|30\|90` | Profile and permissions, live badge counts, picker options, dashboard |
| `GET /api/leads` · `POST /api/leads` · `GET/PATCH /api/leads/{id}` · `POST /api/leads/{id}/notes` | List (filters `q status source channel website brand assignee from to`, paging `cursor dir size`), create, detail, update, note |
| `POST /api/leads/bulk` · `GET /api/leads/export` | Bulk assign/status (≤ 500); streaming CSV |
| `GET /api/chats` · `GET/POST /api/websites` · `GET/PATCH /api/websites/{id}` | Tawk.to inbox; websites, snippets, delivery log |
| `GET/POST /api/admin/users` · `PATCH /api/admin/users/{id}` · `GET/POST/PATCH /api/admin/brands` | Team; brands and auto-assign |
| `GET/POST /api/invoices` · `PATCH /api/invoices/{id}` · `POST /api/invoices/{id}/payments` · `GET /api/invoices/{id}/pdf` · `GET /api/finance` · `POST /api/finance/bulk-pdf` · `GET /api/reports/sales-by-brand` | Invoices (status: sent / paid / cancelled), payments, PDFs, finance, reports |

## Built for large lead volumes

Lead counts only grow, so no page view scans the whole table:

- **Covering indexes.** Three `leads` indexes start with how a view is scoped (everything / a brand / an assignee),
  then `(created_at, id)`, then every column the lists and dashboard filter on. Lists read rows in index order;
  counts and dashboard aggregates are answered from the index without touching row data.
- **Keyset pagination.** Older/newer pages use a `(created_at, id)` cursor, so page 5,000 costs the same as page 1.
  For admins with several brands, each brand is read in index order and the pages merged.
- **Capped counts.** Lists say "10,000+" rather than counting millions of rows on every request.
- **Full-text search** on name, email, phone digits and company.
- **One-pass dashboard.** Every KPI and chart comes from a single `GROUP BY` over the selected period.
- **Streaming CSV.** Exports are written in 2,000-row batches; memory stays flat at any size.
- **Safe concurrency.** Round-robin locks the brand row and retries deadlocks; if assignment still fails, the lead
  is saved and waits in the unassigned queue.

Measured with 500,000+ leads (`php artisan leads:load-test 500000`) on a laptop with XAMPP's MariaDB 10.4 at its
16 MB default buffer pool, which is too small to hold the indexes, so these are worst-case times (full page loads,
PHP with OPcache):

| View | Time |
|---|---|
| Leads list: default, status, unassigned, date range | 0.3–0.9 s |
| Leads list: channel + status, website | 0.4–1.7 s |
| Older/newer pages, 10 pages deep (100 per page) | 0.15–0.24 s each |
| Search by email or phone / by full name | 0.3–0.8 s / 1.5–1.8 s |
| Dashboard, 30 days / 90 days | 0.4–0.7 s / 1.4–1.6 s |
| Live badge poll | 20–60 ms |

More memory for MySQL (`innodb_buffer_pool_size`) makes these much faster. Shared hosting doesn't let you change it,
which is why very large volumes are better served by a VPS.

## More

- [docs/DEPLOY-CPANEL.md](docs/DEPLOY-CPANEL.md): putting it on cPanel hosting
- [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md): connecting websites and Tawk.to
- [docs/ENVIRONMENTS.md](docs/ENVIRONMENTS.md): environments and schema changes
