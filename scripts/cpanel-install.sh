#!/bin/bash
# Run once on cPanel after the Git clone (Terminal or SSH):
#   bash scripts/cpanel-install.sh
set -euo pipefail

cd "$(dirname "$0")/.."
APP_ROOT="$(pwd)"
echo "==> App root: $APP_ROOT"

# Prefer the PHP version selected for this account (change 82 → 83 if needed)
for CANDIDATE in \
  /opt/cpanel/ea-php82/root/usr/bin/php \
  /opt/cpanel/ea-php83/root/usr/bin/php \
  /opt/cpanel/ea-php84/root/usr/bin/php \
  "$(command -v php || true)"
do
  if [ -n "$CANDIDATE" ] && [ -x "$CANDIDATE" ]; then
    PHP_BIN="$CANDIDATE"
    break
  fi
done

if [ -z "${PHP_BIN:-}" ]; then
  echo "ERROR: PHP 8.2+ not found. Enable it in MultiPHP Manager."
  exit 1
fi

PHP_VER="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "==> PHP: $PHP_BIN ($PHP_VER)"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
  || { echo "ERROR: Need PHP >= 8.2"; exit 1; }

for CANDIDATE in \
  /opt/cpanel/composer/bin/composer \
  "$HOME/bin/composer" \
  "$(command -v composer || true)"
do
  if [ -n "$CANDIDATE" ] && [ -x "$CANDIDATE" ]; then
    COMPOSER_BIN="$CANDIDATE"
    break
  fi
done

# Install Composer locally for this account if the host does not provide it
if [ -z "${COMPOSER_BIN:-}" ]; then
  echo "==> Installing Composer into ~/bin"
  mkdir -p "$HOME/bin"
  cd /tmp
  curl -sS https://getcomposer.org/installer -o composer-setup.php
  "$PHP_BIN" composer-setup.php --install-dir="$HOME/bin" --filename=composer
  rm -f composer-setup.php
  COMPOSER_BIN="$HOME/bin/composer"
  cd "$APP_ROOT"
fi

echo "==> Composer: $COMPOSER_BIN"
"$PHP_BIN" "$COMPOSER_BIN" --version

echo "==> composer install --no-dev"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
  echo "==> Creating .env from .env.example (edit DB_* before migrate)"
  cp .env.example .env
  "$PHP_BIN" artisan key:generate --force
  echo "STOP: edit .env (DB credentials, APP_URL), then re-run this script."
  exit 0
fi

echo "==> migrate + optimize"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan optimize

echo "==> Done. vendor/ is installed. Open the site and sign in."
echo "    Create an admin if needed:"
echo "    $PHP_BIN artisan ppc:create-admin you@company.com \"Your Name\""
