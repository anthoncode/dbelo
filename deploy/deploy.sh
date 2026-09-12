#!/usr/bin/env bash
#
# dbelo — deploy a new version.
#
# Run it AS THE DEPLOY USER, from the project root on the server:
#
#     cd /var/www/dbelo && ./deploy.sh
#
# Never as root. Files created by root are files php-fpm cannot write to
# later, and the failure surfaces days afterwards as an unwritable log or a
# cache that will not rebuild.
#
# ── THE TWO THINGS THIS SCRIPT EXISTS TO GET RIGHT ───────────────────────
#
# 1. THE SITE COMES BACK UP EVEN WHEN THE DEPLOY FAILS.
#    `php artisan down` is the first real step, and `up` runs from a trap
#    rather than from the last line. A cleanup that only runs on the happy
#    path is not a cleanup: a composer failure halfway through would
#    otherwise leave dbelo.com showing the maintenance page until somebody
#    noticed and ran `up` by hand.
#
# 2. THE QUEUE WORKERS PICK UP THE NEW CODE.
#    A worker is a long-running PHP process: it loaded your classes when it
#    started and keeps using them until it exits. Without `queue:restart`
#    the site runs the new version and the workers run the old one — which
#    is the worst kind of bug, because it half works.

set -euo pipefail

cd "$(dirname "$0")/.."

APP_DIR="$(pwd)"

echo "· deploying dbelo in ${APP_DIR}"

if [ "$(id -u)" -eq 0 ]; then
    echo "· refusing to run as root — use the deploy user" >&2
    exit 1
fi

# ── Maintenance mode ─────────────────────────────────────────────────────
#
# --retry tells crawlers to come back rather than treating the 503 as
# permanent. --secret gives you a URL that bypasses the page so you can
# check the new version before letting everybody in.
#
# This is Laravel's own maintenance mode, NOT the site status switch in
# Admin → Settings → General. They are different tools: that one lets admins
# through on purpose, this one blocks everybody including you, which is what
# you want while migrations are running.
php artisan down --retry=15 --secret="deploy-preview" || true

# From here on, whatever happens, the site comes back.
trap 'php artisan up || true' EXIT

# ── Code ─────────────────────────────────────────────────────────────────
git pull --ff-only

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# ── Front-end ────────────────────────────────────────────────────────────
#
# Built here rather than committed, because Tailwind compiles at build time:
# a class that only appears in a Blade file added in this commit does not
# exist in the stylesheet until this runs.
#
# `npm ci` and not `npm install`: it installs exactly what package-lock.json
# says, so the server cannot quietly get a different minor version of a build
# tool than the machine the design was checked on.
#
# The full install, including devDependencies — vite and tailwind ARE
# devDependencies, and omitting them means `npm run build` has nothing to
# build with.
if command -v npm >/dev/null 2>&1; then
    npm ci
    npm run build
else
    echo "· npm not found — skipping the asset build" >&2
    echo "· if any Blade file changed, the stylesheet is now stale" >&2
fi

# ── Database ─────────────────────────────────────────────────────────────
#
# --force because migrate refuses to run unprompted in production, and there
# is no prompt to answer inside a script.
php artisan migrate --force

# ── Caches ───────────────────────────────────────────────────────────────
#
# Cleared before being rebuilt. `config:cache` on top of a stale cache is one
# of those things that appears to work until an old value survives into the
# new release.
php artisan optimize:clear
php artisan optimize

# storage/app/public → public/storage. Only created if it is missing:
# re-running it on an existing link is noise, and on some versions an error.
if [ ! -L public/storage ]; then
    php artisan storage:link
fi

# ── Workers ──────────────────────────────────────────────────────────────
#
# Tells every worker to finish the job it is on and then exit. Supervisor
# (deploy/queue-worker.conf, autorestart=true) starts them again within
# seconds, on the new code. Nothing in flight is lost.
php artisan queue:restart

# ── Search ───────────────────────────────────────────────────────────────
#
# NOT reindexed automatically, deliberately. `scout:import` on a large
# catalogue is minutes of work and a spike of queue traffic, and almost no
# deploy changes the shape of the index. Run it by hand when a searchable
# field actually changed:
#
#     php artisan scout:import "App\Models\Sound"

echo "· deployed"
echo "· preview before opening the doors: https://dbelo.com/deploy-preview"

# The trap runs `php artisan up` here.
