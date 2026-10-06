#!/bin/bash
set -u
PHP_BIN="/usr/local/bin/php"
FLOCK_BIN="/bin/flock"
TIMEOUT_BIN="/bin/timeout"
NICE_BIN="/bin/nice"
WIKI="$HOME/public_html"
LOCK="$HOME/private-cache/mediawiki/eql-jobrunner.lock"
LOG="$HOME/private-cache/mediawiki/eql-jobrunner.log"

cd "$WIKI" || exit 1

exec 9>"$LOCK"
"$FLOCK_BIN" -n 9 || exit 0

"$NICE_BIN" -n 15 "$TIMEOUT_BIN" 25 "$PHP_BIN" maintenance/run.php runJobs --maxjobs=3 --maxtime=20 --memory-limit=192M --quiet >> "$LOG" 2>&1

exit 0
