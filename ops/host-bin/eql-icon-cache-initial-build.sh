#!/bin/bash
set -uo pipefail

PATH="/usr/local/bin:/usr/bin:/bin"

WIKI="$HOME/public_html"
BUILDER="$HOME/bin/eql-icon-static-builder.php"
LOCK="$HOME/private-cache/mediawiki/eql-icon-static.lock"
LOG="$HOME/private-cache/mediawiki/eql-icon-static.log"

PHP_BIN="/usr/local/bin/php"
FLOCK_BIN="$(command -v flock || true)"
TIMEOUT_BIN="$(command -v timeout || true)"
NICE_BIN="$(command -v nice || true)"
IONICE_BIN="$(command -v ionice || true)"

[ -n "$FLOCK_BIN" ] || { echo "flock not found"; exit 1; }
[ -n "$TIMEOUT_BIN" ] || { echo "timeout not found"; exit 1; }
[ -n "$NICE_BIN" ] || { echo "nice not found"; exit 1; }

cd "$WIKI" || exit 1

exec 8>"$LOCK"

"$FLOCK_BIN" -n 8 || {
    echo "Another icon-cache build is already running."
    exit 0
}

export EQL_ICON_FORCE=1

echo "Starting low-priority initial static icon-cache build..."
echo "PHP: $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_SAPI, " ", PHP_VERSION;'))"

if [ -n "$IONICE_BIN" ]; then
    "$TIMEOUT_BIN" 300 \
        "$IONICE_BIN" -c 3 \
        "$NICE_BIN" -n 19 \
        "$PHP_BIN" maintenance/run.php "$BUILDER" \
        2>&1 | tee -a "$LOG"
else
    "$TIMEOUT_BIN" 300 \
        "$NICE_BIN" -n 19 \
        "$PHP_BIN" maintenance/run.php "$BUILDER" \
        2>&1 | tee -a "$LOG"
fi
