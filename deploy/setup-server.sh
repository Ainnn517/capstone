#!/usr/bin/env bash
# One-time move of the live site from `php -S` in the home folder to a production setup:
#   Caddy (web server) -> PHP-FPM running as the `barangay` system user -> /srv/barangay/app
#
#   sudo deploy/setup-server.sh
#
# The current site keeps running while this script works. It ends by checking the new setup
# on 127.0.0.1:8002; switching the Cloudflare tunnel to it is a separate step.
# Safe to run again: existing settings in /srv/barangay are kept.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo: sudo $0" >&2; exit 1; }
[ -n "${SUDO_USER:-}" ] || { echo "Run with sudo from your own account, not as root directly." >&2; exit 1; }

SRC="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
DEPLOY="$SRC/deploy"
APP=/srv/barangay/app
OWNER_ENV=/srv/barangay/owner.env
USER_HOME="$(getent passwd "$SUDO_USER" | cut -d: -f6)"
OLD_ENV_OVERRIDES="$USER_HOME/.config/systemd/user/barangay-app.env"

echo "==> 1/8 Installing Caddy and PHP-FPM"
install -d -m 755 /etc/caddy
install -m 644 "$DEPLOY/Caddyfile" /etc/caddy/Caddyfile
DEBIAN_FRONTEND=noninteractive apt-get install -y -q -o Dpkg::Options::=--force-confold \
    caddy php8.3-fpm php8.3-curl rsync >/dev/null

echo "==> 2/8 Creating the barangay system user and /srv/barangay"
id barangay >/dev/null 2>&1 || useradd --system --user-group --home-dir /srv/barangay \
    --no-create-home --shell /usr/sbin/nologin barangay
install -d -o root -g barangay -m 751 /srv/barangay
install -d -o root -g barangay -m 751 "$APP"
[ -x /usr/local/bin/composer ] || install -m 755 "$USER_HOME/.local/bin/composer" /usr/local/bin/composer
install -m 755 "$DEPLOY/barangay-artisan" /usr/local/bin/barangay-artisan

echo "==> 3/8 Writing the production settings"
if [ ! -f "$APP/.env" ]; then
    # Production .env = the current .env with the live overrides applied. The database owner
    # account goes to a separate root-only file, so the web app itself never holds it.
    umask 077
    python3 -I - "$SRC/.env" "$OLD_ENV_OVERRIDES" "$APP/.env" "$OWNER_ENV" <<'PY'
import re, sys
src, overrides, app_env, owner_env = sys.argv[1:5]
lines = open(src).read().splitlines()
owner = [l for l in lines if re.match(r'^DB_ADMIN_(USERNAME|PASSWORD)=', l)]
lines = [l for l in lines if l not in owner]
skip = {'PHP_CLI_SERVER_WORKERS'}
for raw in open(overrides).read().splitlines():
    m = re.match(r'^([A-Z][A-Z0-9_]*)=(.*)$', raw)
    if not m or m.group(1) in skip:
        continue
    key = m.group(1)
    idx = [i for i, l in enumerate(lines) if l.startswith(key + '=')]
    if idx:
        lines[idx[0]] = raw
    else:
        lines.append(raw)
open(app_env, 'w').write('\n'.join(lines) + '\n')
open(owner_env, 'w').write('\n'.join(owner) + '\n')
PY
    umask 022
    chown root:root "$OWNER_ENV"
    chmod 600 "$OWNER_ENV"
else
    echo "    $APP/.env already exists - kept"
fi

echo "==> 4/8 Copying uploaded photos, backups and sessions"
for dir in app/private app/public app/backups framework/cache/data framework/sessions framework/views logs; do
    install -d "$APP/storage/$dir"
done
install -d "$APP/bootstrap/cache"
rsync -a "$SRC/storage/app/private/" "$APP/storage/app/private/"
rsync -a "$SRC/storage/app/backups/" "$APP/storage/app/backups/"
rsync -a "$SRC/storage/framework/sessions/" "$APP/storage/framework/sessions/"

echo "==> 5/8 Configuring PHP-FPM"
install -m 644 "$DEPLOY/php-fpm-barangay.conf" /etc/php/8.3/fpm/pool.d/barangay.conf
install -m 644 "$DEPLOY/php-barangay.ini" /etc/php/8.3/fpm/conf.d/99-barangay.ini
install -d -m 755 /etc/systemd/system/php8.3-fpm.service.d
install -m 644 "$DEPLOY/php-fpm-hardening.conf" /etc/systemd/system/php8.3-fpm.service.d/hardening.conf
systemctl daemon-reload
php-fpm8.3 -t
systemctl enable --quiet php8.3-fpm
systemctl restart php8.3-fpm

echo "==> 6/8 Publishing the code"
SKIP_HEALTH_CHECK=1 "$DEPLOY/deploy.sh"   # checked at the end, once Caddy is running

echo "==> 7/8 Starting Caddy and the scheduler"
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
systemctl enable --quiet caddy
systemctl restart caddy
install -m 644 "$DEPLOY/barangay-scheduler.service" /etc/systemd/system/barangay-scheduler.service
install -m 644 "$DEPLOY/barangay-scheduler.timer" /etc/systemd/system/barangay-scheduler.timer
systemctl daemon-reload
systemctl enable --now --quiet barangay-scheduler.timer

echo "==> 8/8 Final copy of anything uploaded meanwhile, then checks"
rsync -a --update "$SRC/storage/app/private/" "$APP/storage/app/private/"
rsync -a --update "$SRC/storage/framework/sessions/" "$APP/storage/framework/sessions/"
chown -R barangay:barangay "$APP/storage"
find "$APP/storage" -type d -exec chmod 700 {} +
find "$APP/storage" -type f -exec chmod 600 {} +
sleep 1

home=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: barangaysystem.online' http://127.0.0.1:8002/ || true)
health=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: barangaysystem.online' http://127.0.0.1:8002/up || true)
env_file=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: barangaysystem.online' http://127.0.0.1:8002/.env || true)
echo
echo "  home page ............ HTTP $home (want 200)"
echo "  health check ......... HTTP $health (want 200)"
echo "  /.env blocked ........ HTTP $env_file (want 404)"
echo "  photos copied ........ $(find "$APP/storage/app/private" -type f ! -name .gitignore | wc -l) (old: $(find "$SRC/storage/app/private" -type f ! -name .gitignore | wc -l))"
echo "  backups copied ....... $(find "$APP/storage/app/backups" -type f | wc -l) (old: $(find "$SRC/storage/app/backups" -type f | wc -l))"
echo "  web app runs as ...... $(ps -o user= -C php-fpm8.3 | sort -u | tr '\n' ' ')"
echo
if [ "$home" = "200" ] && [ "$health" = "200" ] && [ "$env_file" = "404" ]; then
    echo "SETUP-OK - the new setup works on 127.0.0.1:8002. Tell Claude to switch the tunnel."
else
    echo "SETUP-PROBLEM - the live site is unchanged. Send Claude the output above." >&2
    exit 1
fi
