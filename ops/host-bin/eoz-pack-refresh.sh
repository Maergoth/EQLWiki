#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT="${EQL_WIKI_ROOT:-$HOME/public_html}"
PRIVATE="${EQL_EOZ_PRIVATE:-$HOME/private-cache/mediawiki}"
PHPBIN="${EQL_PHP_BIN:-/usr/local/bin/php}"
BUILDER="$ROOT/maintenance/BuildEyeOfZommPack.php"
PUBLISHER="${EQL_EOZ_PUBLISHER:-$HOME/bin/eoz-publish-dataset.sh}"
SYNCER="${EQL_EOZ_SYNCER:-$HOME/bin/eoz-sync-github.sh}"
LOCK="$PRIVATE/eoz-pack.lock"
SUCCESS="$PRIVATE/eoz-pack-last-success"
SUCCESS_TMP="$PRIVATE/.eoz-pack-last-success.$$.tmp"
FINAL="$PRIVATE/eye-of-zomm-pack.json"
TMP="$PRIVATE/eye-of-zomm-pack.$$.tmp.json"
DATASET="$PRIVATE/eoz-dataset"
CURRENT_ERA="${EQL_CURRENT_ERA:-Classic}"
PAUSE_MS="${EQL_EOZ_PAUSE_MS:-25}"
LOCAL_ONLY=0
IF_STALE=0

for argument in "$@"; do
  case "$argument" in
    --local-only) LOCAL_ONLY=1 ;;
    --if-stale) IF_STALE=1 ;;
    *) echo "Usage: $0 [--if-stale] [--local-only]" >&2; exit 2 ;;
  esac
done

mkdir -p "$PRIVATE" "$DATASET"
exec 9>"$LOCK"
if ! /usr/bin/flock -n 9; then
  echo "Another Eye of Zomm dataset refresh is already running; exiting."
  exit 0
fi

cleanup() { rm -f "$TMP" "$SUCCESS_TMP"; }
trap cleanup EXIT INT TERM

# Scheduled checks use successful GitHub publication, never a staged manifest.
# A manual/local-only run always builds. Bad clocks/state must not suppress work.
if (( IF_STALE && ! LOCAL_ONLY )) && [[ -f "$SUCCESS" ]]; then
  LAST_SUCCESS="$(cat "$SUCCESS")"
  NOW="$(date +%s)"
  if [[ "$LAST_SUCCESS" =~ ^[0-9]{1,10}$ ]] && (( 10#$LAST_SUCCESS <= NOW )); then
    if (( NOW - 10#$LAST_SUCCESS < 86400 )); then
      echo "Dataset refresh skipped: last successful GitHub publication was less than 24 hours ago."
      exit 0
    fi
  else
    echo "Invalid or future Eye of Zomm success marker; rebuilding instead of treating it as fresh." >&2
  fi
fi

[[ -f "$BUILDER" ]] || { echo "Missing builder: $BUILDER" >&2; exit 2; }
[[ -x "$PUBLISHER" ]] || { echo "Missing publisher: $PUBLISHER" >&2; exit 2; }

echo "[$(date -u -Is)] Building EQLWiki - Eye of Zomm dataset at low priority..."

/usr/bin/ionice -c 3 \
/usr/bin/nice -n 19 \
"$PHPBIN" "$ROOT/maintenance/run.php" "$BUILDER" \
    --output="$TMP" \
    --current-era="$CURRENT_ERA" \
    --pause-ms="$PAUSE_MS"

[[ -s "$TMP" ]] || { echo "Builder produced no data; refusing to publish." >&2; exit 3; }

# The CLI default can be 128M even though MediaWiki raises its own limit.
# Pass validator code on stdin so cPanel's selector never stats multiline argv.
"$PHPBIN" -d memory_limit=768M -d display_errors=stderr -f /dev/stdin -- "$TMP" <<'PHP'
<?php
$f=$argv[1];
$j=json_decode(file_get_contents($f), true);
if (!is_array($j) || ($j["meta"]["schemaVersion"] ?? null) !== 3 || !isset($j["zones"],$j["npcs"],$j["items"])) { fwrite(STDERR,"Eye of Zomm dataset schemaVersion 3 is required\n"); exit(4); }
if (count($j["zones"]) < 1 || count($j["npcs"]) < 1 || count($j["items"]) < 1) { fwrite(STDERR,"Dataset is unexpectedly empty\n"); exit(5); }
echo "Validated: zones=".count($j["zones"])." npcs=".count($j["npcs"])." items=".count($j["items"])."\n";
PHP

mv -f "$TMP" "$FINAL"
bash "$PUBLISHER" "$FINAL" "$DATASET"

if (( LOCAL_ONLY )); then
  echo "Local-only mode: GitHub was not modified."
  echo "Download these two files if you want to publish manually:"
  echo "  $DATASET/manifest.json"
  echo "  $DATASET/eqlwiki-eye-of-zomm-data.json.gz"
else
  [[ -x "$SYNCER" ]] || { echo "Missing GitHub syncer: $SYNCER" >&2; exit 6; }
  bash "$SYNCER" "$DATASET"
  # Only a published pack advances freshness; failures/local-only leave it intact.
  date +%s > "$SUCCESS_TMP"
  mv -f "$SUCCESS_TMP" "$SUCCESS"
fi

echo "[$(date -u -Is)] Eye of Zomm dataset refresh complete."
