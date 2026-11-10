# Deploying on cPanel

PPC Leads is a standard Laravel app. The screens are already compiled into `public/build`, so the server needs
**PHP and MySQL only, no Node.js**.

## What the hosting needs

- **PHP 8.2 or newer** (cPanel → *Select PHP Version* or *MultiPHP Manager*), with these extensions ticked:
  `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `dom`, `ctype`, `fileinfo`, `zip`, `gd`, `bcmath`, `curl`,
  and **`opcache`** (without OPcache every page is ~0.4 s slower).
- **MySQL 5.7+/8 or MariaDB 10.4+** (*MySQL Databases*).
- **SSL** for the domain (*SSL/TLS Status* → AutoSSL). Tawk.to only sends webhooks to HTTPS URLs.
- **Terminal** or SSH access is the easy way to run the setup commands. Without it, see
  [No Terminal?](#no-terminal) below.

## 1. Create the database

*MySQL Databases* → create a database (e.g. `cpuser_ppcleads`), create a user with a strong password, and
**add the user to the database with ALL PRIVILEGES**. Note all three names; cPanel prefixes them with your account name.

## 2. Upload the code (Git CI/CD on this cPanel)

For account **serverlinktestwe** / domain **serverlinktestwebsites.com**:

### A. Push the app to GitHub (once)

From your laptop (repo already prepared with `.cpanel.yml`):

```bash
git remote add origin https://github.com/SaadNaseer06/ppc-leads-software.git
git push -u origin master
```

### B. Clone in cPanel → *Git™ Version Control*

1. Open **Git™ Version Control** (under *Files* in cPanel).
2. **Create** → fill in:
   - **Clone URL:** `https://github.com/SaadNaseer06/ppc-leads-software.git`  
     Private repo: use  
     `https://YOUR_GITHUB_USERNAME:YOUR_PAT@github.com/SaadNaseer06/ppc-leads-software.git`  
     (GitHub → Settings → Developer settings → Personal access tokens, scope `repo`).
   - **Repository Path:** `/home/serverlinktestwe/ppc-leads`  
     (must be empty / not exist yet — do **not** clone into `public_html`).
   - **Repository Name:** `ppc-leads`
3. Create the repository. cPanel clones the branch (usually `master` or `main`).
4. Open the repo → **Manage** → **Pull or Deploy** → **Update from Remote** then **Deploy HEAD Commit**.  
   Deploy runs `.cpanel.yml` (`composer install`, `migrate`, `optimize`).  
   First deploy needs a `.env` already in place (step 4) or migrate will fail — create `.env` first, then Deploy again.

### C. Zip upload (alternative)

Zip the project **without** `node_modules` and `.env`, upload with *File Manager* to
`/home/serverlinktestwe/ppc-leads`, and extract. If there is no Terminal, include `vendor`
(run `composer install --no-dev` on your computer first).

## 3. Point the domain at the app

Best: *Domains* → manage **serverlinktestwebsites.com** (or a subdomain) → set **Document Root** to
`/home/serverlinktestwe/ppc-leads/public`.

If the main domain root **must** stay `public_html` and cannot change, put the project inside `public_html`
instead. The root `.htaccess` sends every request into `public/` and blocks direct access to `.env`.

## 4. Configure `.env`

In *File Manager*, copy `.env.example` to `.env` (enable *Show Hidden Files*) and edit:

```ini
APP_NAME="PPC Leads"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://leads.yourcompany.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cpuser_ppcleads
DB_USERNAME=cpuser_ppcuser
DB_PASSWORD="the password from step 1"

REPORT_TIMEZONE=America/New_York     # your business timezone
SESSION_SECURE_COOKIE=true           # site is served over HTTPS
LOG_LEVEL=warning

# Native live chat (Pusher cloud — free sandbox is enough to start)
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=mt1
VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"

# Client invoice payments (see "Take card payments" below)
STRIPE_SECRET_KEY=
STRIPE_PUBLISHABLE_KEY=
STRIPE_WEBHOOK_SECRET=
PAYMENT_INSTRUCTIONS="Bank transfer: Your Bank\nIBAN: XX00 0000 0000"
```

Keep `SESSION_DRIVER=database`, `CACHE_STORE=database` and `QUEUE_CONNECTION=sync`. No Redis, queue worker or
WebSocket server is needed — chat realtime goes through Pusher. After changing `VITE_PUSHER_*`, rebuild assets
locally (`npm run build`) and deploy `public/build` so the dashboard Chats page can subscribe.

## 5. Install

In *Terminal*, from the project folder:

```bash
composer install --no-dev --optimize-autoloader   # skip if you uploaded vendor/
php artisan key:generate --force
php artisan migrate --force
php artisan ppc:create-admin you@yourcompany.com "Your Name"     # asks for a password
php artisan optimize
```

If `php` is an older version in Terminal, call the one your domain uses, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`.

`storage/` and `bootstrap/cache/` must be writable by PHP (the default permissions on cPanel are fine; set folders to
755 if you see "Permission denied").

## 6. Cron job (scheduled tasks)

*Cron Jobs* → add, every minute (`* * * * *`):

```bash
/usr/local/bin/php /home/cpuser/ppc-leads/artisan schedule:run >> /dev/null 2>&1
```

Every minute it collects clients' email replies from the connected mailboxes and files them on the lead. Every 10
minutes it closes live chats whose visitor has gone quiet for 3 hours (so the "live" chat counts stay accurate), and
once a day it deletes webhook and audit logs older than 90 days. Without it, email replies never reach the app,
abandoned chats stay open and the log tables keep growing.

Replace `cpuser` with your cPanel username and `ppc-leads` with the folder the app is in (*File Manager* shows the full
path at the top, for example `/home/cpuser/ppc-leads`). If the job doesn't seem to run, the PHP path may differ: in
*Terminal*, `which php` shows it, or use the version-specific one, such as `/opt/cpanel/ea-php82/root/usr/bin/php`. To
check it works: on the **Email** page, a mailbox's *Last checked* should stay at "just now" or "1m ago".

## 7. Check it

1. Open `https://leads.yourcompany.com` and sign in with the account from step 5.
2. **Brands & routing** → create a brand. **Websites** → add a website and press **Send test lead**.
3. Follow [INTEGRATIONS.md](INTEGRATIONS.md) to put the embed snippet on your websites and connect Tawk.to.

## Take card payments (optional)

Every invoice has a link clients open to view and pay it. The card form appears on that page once the brand has Stripe
keys. Until then, the page shows `PAYMENT_INSTRUCTIONS` instead. Check first that Stripe supports your business's country.

1. Sign in as a **Super Admin** and open **Payments** in the sidebar. Only Super Admins see this, because these keys
   decide where clients' money goes.
2. Choose the brand and set its **merchant name**.
3. From the [Stripe dashboard](https://dashboard.stripe.com/apikeys), paste the **publishable key** and **secret key**,
   then press **Save and connect**. Stripe checks the key before anything is saved, and the invoice page starts taking
   cards straight away. No file changes or restart are needed.
4. Recommended: in Stripe, *Developers → Webhooks → Add endpoint*, using the address shown for the brand on **Payments** and the
   event `payment_intent.succeeded`. Paste its signing secret (`whsec_…`) into the brand on **Payments**. A payment is then
   recorded even if the client closes the page straight after paying.
5. Use test keys first: raise a small invoice, open its link and pay with the card `4242 4242 4242 4242`. The page shows
   a *Test mode* note. The invoice should turn **Paid** and the payment should appear on the **Finance** page. Then
   replace the keys with the live ones, and add the webhook again in live mode, since it has its own signing secret.

Each brand can use its own Stripe account, so its money goes there, or several brands can share one by entering the same
keys. Secrets are encrypted with `APP_KEY`, so **don't change `APP_KEY`** on a running site or they will need entering again.

The name on a client's **card statement** comes from the Stripe account, not the invoice's merchant name. Set it under
*Settings → Public details* in Stripe.

**Prefer `.env`?** `STRIPE_PUBLISHABLE_KEY`, `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` still work for any brand with
no keys of its own, with the webhook address `/api/webhooks/stripe`. Run `php artisan optimize` afterwards, or press
**Reload settings** on the Invoices page, because the app keeps its settings cached.

**No card form on the invoice?** Open **Payments**: it shows each brand's status, and the Invoices page names any brand that can't take cards.

## Connect mailboxes (optional)

Each rep emails leads from their own address on the brand's domain, and clients' replies show up on the lead.

**Create mailboxes from the app (recommended).** Nobody has to visit cPanel per rep, and nobody ever learns a
mailbox's password, so reps can't open webmail and read clients' full addresses there.

1. In each cPanel account that hosts a brand's domain: *Security → Manage API Tokens → Create*. Copy the token (cPanel
   shows it once). A token can manage the whole cPanel account, so give it only to the app.
2. Sign in as a **Super Admin**, open **Email** in the sidebar and press **Add cPanel account**: enter the cPanel server
   name (as in the cPanel address, without `https://` or `:2083`), the cPanel username and the token. The app reads
   which mail domains the account hosts to prove the token works, and stores the token encrypted. Add one per cPanel
   account; brands on different accounts or servers are fine.
3. Press **Create mailbox**: pick the brand, who it belongs to (or *Shared by the whole brand*, used by anyone on that
   brand without a mailbox of their own), the address and the domain. The app creates it in the right cPanel account
   with a long random password and connects it.
4. In a mailbox's **Edit** dialog: **Reset password** sets a new random one in cPanel and the app at once; **Disconnect**
   stops the app using it but keeps it in cPanel with its mail; **Delete in cPanel** removes it from the server for good.
   When a rep leaves, removing them from the app disconnects their mailbox and keeps it in cPanel, so mail isn't lost.
5. Added a domain in cPanel later? Press **Refresh domains** on its account.

**Connect an existing mailbox by hand** (for a brand whose domain isn't on cPanel, or a mailbox that already exists):
press **Connect existing** and enter the address, password and mail server (usually `mail.yourbrand.com`, see *Email
Accounts → Connect Devices* in cPanel). Keep the ports at 993 and 465 and keep the password to yourself. The app logs in
to check before saving.

Either way:

- Replies are collected every minute by the cron job from step 6, so that job must be running. Mail already in an inbox
  is left alone; only new emails from addresses that belong to a lead are filed, once, whichever mailbox they reach.
- Make sure *Email Deliverability* in cPanel shows SPF and DKIM as valid for the domain, or emails may land in spam.
- Attachments: one email can carry up to 18 MB of files, but never more than PHP accepts in one upload. In
  *MultiPHP INI Editor* set `upload_max_filesize` and `post_max_size` to at least `20M` (cPanel often starts at
  `2M`, and then 2 MB is the most a rep can attach). Files sent and received are kept in `storage/app/private`,
  never under `public_html`, and are deleted with their lead.

Nothing extra needs installing: the app talks IMAP itself, without PHP's imap extension. If a mail server's certificate
doesn't match its name, set `MAIL_VERIFY_CERTIFICATES=false` in `.env` (better: use the name the certificate covers).

## Connect a brand's own domain (optional)

Invoice links can use a brand's address, such as `pay.yourbrand.com`, instead of this app's. Links stay on the app's
address until the domain is proven to work, so doing this never breaks a link a client already has.

1. **Brands & routing** → *Customize* on the brand → enter the domain under **Invoice domain** and save.
2. At the domain's DNS provider, add a `CNAME` record for it pointing to this app's domain.
3. In cPanel, *Domains → Create A New Domain*: add it with the **same document root** as this app, then let *SSL/TLS
   Status* issue its certificate. HTTPS is required, because card payments won't run without it.
4. Back in **Customize**, press **Check connection**. Once it says *Connected*, every link for that brand uses the domain,
   including links shown for existing invoices.

A brand's domain only serves its own invoices. The dashboard, sign-in page and other brands' invoices return 404 there.
Leave `SESSION_DOMAIN` empty. To use Apple Pay or Google Pay on a brand's domain, also register it in Stripe under
*Settings → Payment method domains*.

## Updating (CI/CD)

Prefer the automated pipeline in **[CI-CD-CPANEL.md](CI-CD-CPANEL.md)** (GitHub Actions → SSH pull on every push to `master`).

Manual fallback in cPanel → *Git™ Version Control* → **Pull or Deploy**:
**Update from Remote** → **Deploy HEAD Commit** (runs `.cpanel.yml`).

Manual equivalent in Terminal:

```bash
cd /home/serverlinktestwe/public_html/ppcleads.serverlinktestwebsites.com
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

The compiled screens come with the code (`public/build`). If you change files in `resources/js` or
`resources/css`, run `npm run build` **on your computer** and commit `public/build` before pushing.

## No Terminal?

Use *Cron Jobs* to run each command once: add a job for "every minute" with the command (prefix it with
`cd /home/cpuser/ppc-leads &&`), wait a minute, then delete the job. For example:

```bash
cd /home/cpuser/ppc-leads && /usr/local/bin/php artisan migrate --force >> storage/logs/setup.log 2>&1
```

Do the same for `key:generate --force`, `ppc:create-admin you@yourcompany.com "Your Name" --password=...`, and
`optimize`, then read `storage/logs/setup.log` in *File Manager* to confirm each step ran. Delete the
`ppc:create-admin` cron entry immediately, because it contains the password.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "500 Server Error" right after upload | `.env` missing, `APP_KEY` empty, or `vendor/` missing. Set `APP_DEBUG=true` briefly to see the message, then turn it off. |
| Page loads but has no styling | `public/build` wasn't uploaded, or `APP_URL` is wrong. |
| Buttons fail with "CSRF token mismatch" / you're sent back to sign-in | Clear the browser's cookies for the site; check `SESSION_SECURE_COOKIE=true` only when the site is on HTTPS. |
| Tawk.to deliveries show "Signature mismatch" | The webhook secret saved on the website doesn't match the one in Tawk.to. |
| Slow with hundreds of thousands of leads | Shared MySQL has little memory for indexes; move to a VPS or ask the host to raise `innodb_buffer_pool_size`. |
