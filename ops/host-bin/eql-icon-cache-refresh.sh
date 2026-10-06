#!/bin/bash
set -u

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

[ -n "$FLOCK_BIN" ] || exit 1
[ -n "$TIMEOUT_BIN" ] || exit 1
[ -n "$NICE_BIN" ] || exit 1

cd "$WIKI" || exit 1

exec 8>"$LOCK"
"$FLOCK_BIN" -n 8 || exit 0

if [ -n "$IONICE_BIN" ]; then
    "$TIMEOUT_BIN" 180 \
        "$IONICE_BIN" -c 3 \
        "$NICE_BIN" -n 19 \
        "$PHP_BIN" maintenance/run.php "$BUILDER" \
        >> "$LOG" 2>&1
else
    "$TIMEOUT_BIN" 180 \
        "$NICE_BIN" -n 19 \
        "$PHP_BIN" maintenance/run.php "$BUILDER" \
        >> "$LOG" 2>&1
fi

exit $?
