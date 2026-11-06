#!/usr/bin/env bash
#
# Finish a deployment on the server, and say where it landed.
#
#   cd ~/dbelo && git pull && bash bin/deploy.sh
#
# ── WHY git pull IS OUTSIDE THIS SCRIPT ─────────────────────────────────
#
# Because a pull can change this file while bash is running it. Bash reads a
# script incrementally, from a byte offset, so a file that grows or shrinks
# mid-execution can resume in the middle of a different line. Pulling first,
# from outside, means bash opens the version it is going to finish.
#
# ── WHY THE PHP PATH IS SEARCHED AND NOT GUESSED ────────────────────────
#
# The shell on this host starts on PHP 7.4, which cannot run the
# application at all. The 8.3 binary has to be named in full, and an alias
# does not help a cron job or a script.
#
# The search is written in explicit preference order and verified by asking
# each binary its version — never by globbing and taking the first. A glob
# is sorted alphabetically, so /opt/cpanel/ea-php80 sorts before ea-php83
# and `head -1` quietly picks PHP 8.0. That exact mistake cost an evening.

set -euo pipefail

cd "$(dirname "$0")/.."

find_php() {
    local candidate

    # Known good, in the order we want them.
    for candidate in \
        /opt/cpanel/ea-php83/root/usr/bin/php \
        /opt/alt/php83/usr/bin/php
    do
        [ -x "$candidate" ] && { echo "$candidate"; return 0; }
    done

    # Anything else that is new enough, asked rather than assumed.
    for candidate in /opt/cpanel/ea-php8*/root/usr/bin/php /opt/alt/php8*/usr/bin/php; do
        [ -x "$candidate" ] || continue
        if "$candidate" -r 'exit(version_compare(PHP_VERSION, "8.3", ">=") ? 0 : 1);' 2>/dev/null; then
            echo "$candidate"
            return 0
        fi
    done

    command -v php
}

PHP="$(find_php)"

echo "==> PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
echo

# The server is a read-only destination: it pulls and never writes. Anything
# modified here is either something edited in place — which the next pull
# will refuse to overwrite — or cPanel leaving a file behind. Either way it
# is worth seeing before it becomes a failed deployment.
DIRTY="$(git status --porcelain | wc -l | tr -d ' ')"

if [ "$DIRTY" != "0" ]; then
    echo "!! The working tree here is NOT clean. Nothing should be edited on the server:"
    git status --short
    echo
fi

# Migrations do not travel with a pull, and a plan column that does not
# exist yet fails at the worst possible moment. --force because Laravel asks
# for confirmation in production and nobody is there to give it.
echo "==> Migrations"
"$PHP" artisan migrate --force

# Four caches, each for a different reason:
#   config  something changed in config/ or in .env
#   route   a new route exists — without this it answers 404
#   view    any Blade changed
#   cache   stored data that depends on the new code
#
# Always all four, never a subset chosen from what was touched. A deployment
# sequence that varies with the change is one where a step eventually gets
# left out.
echo
echo "==> Caches"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan cache:clear

echo
echo "─────────────────────────────────────────────"
echo "DEPLOYED: $(git log --oneline -1)"
echo "─────────────────────────────────────────────"
echo
echo "Compare that hash with the one bin/ship.sh printed on the Mac."
echo "Note: Admin -> Diagnostics shows the heartbeat red for up to five"
echo "minutes after cache:clear. The next cron run clears it."
