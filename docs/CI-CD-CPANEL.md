# Push → cPanel pipeline

Goal: every `git push` to `master` updates
`/home/serverlinktestwe/public_html/ppcleads.serverlinktestwebsites.com`.

Use **Option A** (recommended). Option B is a fallback if SSH is blocked.

---

## Before either option

### 1. Fix the clone URL (private GitHub repo)

Your screenshot used:

`https://github.com/SaadNaseer06/ppc-leads-software.git`

That fails for a **private** repo. In cPanel → Git™ Version Control → your repo →
**Manage** (or recreate), set the remote to:

```text
https://SaadNaseer06:GITHUB_PAT@github.com/SaadNaseer06/ppc-leads-software.git
```

Create a PAT: GitHub → Settings → Developer settings → Personal access tokens →
classic token with **`repo`** scope. Do not commit the PAT.

Repository path you chose is fine:

```text
/home/serverlinktestwe/public_html/ppcleads.serverlinktestwebsites.com
```

(The project root `.htaccess` routes traffic into `public/`.)

### 2. Create `.env` on the server once

After the first successful clone, in File Manager copy `.env.example` → `.env`,
set MySQL credentials, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`,
then in Terminal:

```bash
cd ~/public_html/ppcleads.serverlinktestwebsites.com
php artisan key:generate --force
php artisan migrate --force
php artisan ppc:create-admin you@company.com "Your Name"
php artisan optimize
```

---

## Option A — GitHub Actions over SSH (auto on every push)

Workflow file: [`.github/workflows/deploy-cpanel.yml`](../.github/workflows/deploy-cpanel.yml)

### Enable SSH in cPanel

1. cPanel → **SSH Access** → **Manage SSH Keys** → Generate a new key (or Import).
2. **Authorize** the public key.
3. Copy the **private** key (you will paste it into GitHub Secrets only).

Optional: add the same public key as a GitHub **deploy key** on the repo if you
prefer SSH remotes instead of a PAT in the clone URL.

### Add GitHub Actions secrets

Repo → **Settings** → **Secrets and variables** → **Actions** → New repository secret:

| Secret | Example value |
|--------|----------------|
| `CPANEL_HOST` | `serverlinktestwebsites.com` (or the shared IP `66.29.149.231`) |
| `CPANEL_USER` | `serverlinktestwe` |
| `CPANEL_SSH_KEY` | Full private key including `BEGIN` / `END` lines |
| `CPANEL_SSH_PORT` | `22` (only if your host uses another port) |
| `CPANEL_APP_DIR` | `/home/serverlinktestwe/public_html/ppcleads.serverlinktestwebsites.com` |

### Test

1. Push any commit to `master`, or run **Actions** → **Deploy to cPanel** → **Run workflow**.
2. Open the run log: you should see `git pull`, `composer install`, `migrate`, `optimize`.

---

## Option B — cPanel deploy webhook (no GitHub Actions)

1. cPanel → **Git™ Version Control** → manage `ppc-leads-software`.
2. Open **Pull or Deploy**. If your host shows a **Webhook URL**, copy it.
3. GitHub → repo → **Settings** → **Webhooks** → **Add webhook**:
   - Payload URL = that cPanel URL
   - Content type = `application/json`
   - Events = Just the push event
4. Push to `master`. cPanel should pull and run `.cpanel.yml`.

Not all cPanel plans expose this webhook. If you do not see one, use Option A.

---

## Day-to-day

```bash
git add -A
git commit -m "your message"
git push origin master
```

Within ~1–2 minutes the Action finishes and the live site has the new code
(plus DB migrations when present).

Front-end changes under `resources/js` or `resources/css`: run `npm run build`
locally and **commit `public/build`** before pushing (the server has no Node).
