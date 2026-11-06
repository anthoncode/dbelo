#!/usr/bin/env bash
#
# Ship from the Mac: compile, commit, push — and say what left.
#
#   bash bin/ship.sh "what this commit does"
#
# ── WHY THIS EXISTS ─────────────────────────────────────────────────────
#
# Not to save typing. The four commands were always the same; what was
# missing is that neither half of a deployment said where it ended up, so
# "did I run it?" had no answer until something broke. On one day alone,
# three separate "it still fails" turned out to be production running older
# code — invisible from the browser, and invisible from here too.
#
# This prints the hash it pushed. bin/deploy.sh prints the hash production
# landed on. Two short strings to compare, and the question is settled.
#
# ── WHY npm run build IS NOT OPTIONAL ───────────────────────────────────
#
# Tailwind only emits a class it saw at build time, and the server has no
# Node, so compiled assets travel in the repository or not at all. Skip it
# and the new markup renders with no styling — not broken, unpainted. And
# nothing fails, so nothing lands in any log. It has cost a full day of a
# half-painted site, twice.

set -euo pipefail

cd "$(dirname "$0")/.."

MESSAGE="${1:-}"

if [ -z "$MESSAGE" ]; then
    echo "Usage: bash bin/ship.sh \"what this commit does\"" >&2
    exit 1
fi

echo "==> Building assets"
npm run build

# Shown before committing, never after: this is the last moment where
# somebody can notice a file they did not mean to include. A stray file
# swept in by `git add -A` once broke the server's pull, because git
# refuses to overwrite an untracked file it does not control.
echo
echo "==> About to commit"
git status --short
echo

git add -A

if git diff --cached --quiet; then
    echo "Nothing to commit."
else
    # Attribution last, as the trailing lines of the message.
    if [ -n "${CLAUDE_SESSION:-}" ]; then
        git commit -m "$MESSAGE" \
                   -m "Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>" \
                   -m "Claude-Session: ${CLAUDE_SESSION}"
    else
        git commit -m "$MESSAGE" \
                   -m "Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
    fi
fi

echo
echo "==> Pushing"
git push

echo
echo "─────────────────────────────────────────────"
echo "PUSHED:   $(git log --oneline -1)"
echo "─────────────────────────────────────────────"
echo
echo "Now on the server:"
echo "  cd ~/dbelo && git pull && bash bin/deploy.sh"
