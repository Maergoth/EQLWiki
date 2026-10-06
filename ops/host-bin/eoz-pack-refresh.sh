#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${EQL_WIKI_ROOT:-$HOME/public_html}"
PRIVATE="${EQL_EOZ_PRIVATE:-$HOME/private-cache/mediawiki}"
PHPBIN="${EQL_PHP_BIN:-/usr/local/bin/php}"
BUILDER="$ROOT/maintenance/BuildEyeOfZommPack.php"
PUBLISHER="${EQL_EOZ_PUBLISHER:-$HOME/bin/eoz-publish-dataset.sh}"
SYNCER="${EQL_EOZ_SYNCER:-$HOME/bin/eoz-sync-github.sh}"
LOCK="$PRIVATE/eoz-pack.lock"
FINAL="$PRIVATE/eye-of-zomm-pack.json"
TMP="$PRIVATE/eye-of-zomm-pack.$$.tmp.json"
DATASET="$PRIVATE/eoz-dataset"
CURRENT_ERA="${EQL_CURRENT_ERA:-Classic}"
PAUSE_MS="${EQL_EOZ_PAUSE_MS:-25}"
LOCAL_ONLY=0

if [[ "${1:-}" == "--local-only" ]]; then
  LOCAL_ONLY=1
fi

mkdir -p "$PRIVATE" "$DATASET"
exec 9>"$LOCK"
if ! /usr/bin/flock -n 9; then
  echo "Another Eye of Zomm dataset refresh is already running; exiting."
  exit 0
fi

cleanup() { rm -f "$TMP"; }
trap cleanup EXIT INT TERM

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

"$PHPBIN" -r '
$f=$argv[1];
$j=json_decode(file_get_contents($f), true);
if (!is_array($j) || ($j["meta"]["schemaVersion"] ?? null) !== 3 || !isset($j["zones"],$j["npcs"],$j["items"])) { fwrite(STDERR,"Eye of Zomm dataset schemaVersion 3 is required\n"); exit(4); }
if (count($j["zones"]) < 1 || count($j["npcs"]) < 1 || count($j["items"]) < 1) { fwrite(STDERR,"Dataset is unexpectedly empty\n"); exit(5); }
echo "Validated: zones=".count($j["zones"])." npcs=".count($j["npcs"])." items=".count($j["items"])."\n";
' "$TMP"

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
fi

echo "[$(date -u -Is)] Eye of Zomm dataset refresh complete."
