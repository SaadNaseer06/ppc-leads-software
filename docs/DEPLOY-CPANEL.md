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

## 6. Cron job (daily cleanup)

*Cron Jobs* → add, every minute (`* * * * *`):

```bash
/usr/local/bin/php /home/cpuser/ppc-leads/artisan schedule:run >> /dev/null 2>&1
```

It deletes webhook and audit logs older than 90 days once a day. The app works without it; the tables just keep growing.

## 7. Check it

1. Open `https://leads.yourcompany.com` and sign in with the account from step 5.
2. **Brands & routing** → create a brand. **Websites** → add a website and press **Send test lead**.
3. Follow [INTEGRATIONS.md](INTEGRATIONS.md) to put the embed snippet on your websites and connect Tawk.to.

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
