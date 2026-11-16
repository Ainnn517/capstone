#!/usr/bin/env bash
# Publish the code in this project folder to the live site (/srv/barangay/app).
#
#   sudo deploy/deploy.sh
#
# Run the tests first (php artisan test). Editing files here does NOT change the live site
# until this script runs. The live .env, uploaded photos, backups and logs are never touched.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo: sudo $0" >&2; exit 1; }

SRC="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
APP=/srv/barangay/app
OWNER_ENV=/srv/barangay/owner.env
PORT=8002

as_app() { (cd "$APP" && runuser -u barangay -- "$@"); }
as_owner() { (set -a; . "$OWNER_ENV"; set +a; cd "$APP" && runuser -u barangay -- "$@"); }

fix_permissions() {
    # Code: readable by the app, changeable only by root (a compromised app cannot alter it).
    chown -R root:barangay "$APP"
    find "$APP" -type d -exec chmod 750 {} +
    find "$APP" -type f -exec chmod 640 {} +
    chmod 751 "$APP"
    # Public assets: the web server (caddy) serves these directly.
    find "$APP/public" -type d -exec chmod 755 {} +
    find "$APP/public" -type f -exec chmod 644 {} +
    # Uploads, sessions, cache, logs, backups: the app's own, private.
    chown -R barangay:barangay "$APP/storage" "$APP/bootstrap/cache"
    find "$APP/storage" "$APP/bootstrap/cache" -type d -exec chmod 700 {} +
    find "$APP/storage" "$APP/bootstrap/cache" -type f -exec chmod 600 {} +
    chown root:barangay "$APP/.env"
    chmod 640 "$APP/.env"
}

echo "==> Copying code from $SRC"
rsync -a --delete --exclude-from="$SRC/deploy/rsync-exclude.txt" "$SRC/" "$APP/"

echo "==> Installing PHP packages (production only)"
COMPOSER_ALLOW_SUPERUSER=1 /usr/local/bin/composer install --working-dir="$APP" \
    --no-dev --optimize-autoloader --no-interaction --no-scripts --no-plugins --quiet

fix_permissions

echo "==> Updating the database and caches"
as_app /usr/bin/php artisan package:discover --quiet
as_owner /usr/bin/php artisan migrate --database=mysql_admin --force
as_app /usr/bin/php artisan view:cache --quiet
systemctl reload php8.3-fpm

if [ "${SKIP_HEALTH_CHECK:-0}" = "1" ]; then
    exit 0
fi
status=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: barangaysystem.online' "http://127.0.0.1:${PORT}/up" || true)
if [ "$status" = "200" ]; then
    echo "==> Live site OK (health check HTTP $status)"
else
    echo "!! Health check returned HTTP $status - check $APP/storage/logs" >&2
    exit 1
fi
