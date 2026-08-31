#!/usr/bin/env bash
#
# dbelo — everything the site needs locally, in one terminal.
#
# Herd serves PHP on its own, but three things run beside it and the site
# breaks in a different way when each one is missing:
#
#   meilisearch   search dies — the catalogue falls back to a plain LIKE
#   queue:work    SCOUT_QUEUE=true, so uploads never reach the index and
#                 sounds stay stuck on "Queued" forever
#   npm run dev   new Tailwind classes are never compiled, so fresh screens
#                 render unstyled
#   schedule:work the weekly digest and the analytics rollup never run
#
# Run:  ./dev.sh          Stop: Ctrl-C (stops all three)

set -uo pipefail
cd "$(dirname "$0")"

MEILI_DIR="$HOME/meilisearch"
MEILI_BIN="$MEILI_DIR/meilisearch"
MEILI_HOST="http://127.0.0.1:7700"

# Kill the whole process group on exit, so Ctrl-C does not leave orphans
# still holding port 7700 — which is its own confusing morning.
trap 'echo ""; echo "· stopping everything"; kill 0' EXIT INT TERM

label() { sed "s/^/[$1] /"; }

echo "· starting dbelo"
echo ""

# ── Dependencies ────────────────────────────────────────────────────────
# Vite fails hard on a package that is in package.json but not on disk, and
# the error it prints ("Failed to resolve import") reads like a code bug
# rather than a missing install. Comparing the two timestamps catches it
# before Vite ever starts.
if [ ! -d node_modules ] || [ package.json -nt node_modules ]; then
    echo "[npm] package.json is newer than node_modules — installing"
    npm install || { echo "[npm] install failed"; exit 1; }
    touch node_modules
    echo ""
fi

if [ ! -d vendor ] || [ composer.json -nt vendor ]; then
    echo "[composer] composer.json is newer than vendor — installing"
    composer install || { echo "[composer] install failed"; exit 1; }
    touch vendor
    echo ""
fi

# ── Meilisearch ─────────────────────────────────────────────────────────
if curl -sf --max-time 2 "$MEILI_HOST/health" >/dev/null 2>&1; then
    echo "[meilisearch] already answering on $MEILI_HOST"
elif [ -x "$MEILI_BIN" ]; then
    # Keep the key in step with the app: reading it from .env means the two
    # can never drift into a 403 that looks like a bug.
    MEILI_KEY=$(grep -E '^MEILISEARCH_KEY=' .env 2>/dev/null | cut -d= -f2- | tr -d '"'\''' | xargs || true)

    if [ -n "${MEILI_KEY:-}" ]; then
        "$MEILI_BIN" --db-path "$MEILI_DIR/data.ms" --master-key "$MEILI_KEY" 2>&1 | label meilisearch &
    else
        "$MEILI_BIN" --db-path "$MEILI_DIR/data.ms" 2>&1 | label meilisearch &
    fi
else
    echo "[meilisearch] NOT FOUND at $MEILI_BIN"
    echo "[meilisearch] search will run in reduced mode until it is installed"
fi

# ── Queue worker ────────────────────────────────────────────────────────
# Audio processing, search indexing and every email go through here.
#
# One queue on purpose. A named queue is only consumed by a worker started
# with the matching --queue flag, so any worker started without it converts
# nothing at all — with no error to show for it.
php artisan queue:work --tries=3 --timeout=300 2>&1 | label queue &

# ── Scheduler ───────────────────────────────────────────────────────────
# Drives the hourly jobs: the newsletter digest and the analytics rollup.
php artisan schedule:work 2>&1 | label schedule &

# ── Vite ────────────────────────────────────────────────────────────────
npm run dev 2>&1 | label vite &

sleep 2
echo ""
echo "· http://dbelo.test    Ctrl-C stops everything"
echo ""

wait
