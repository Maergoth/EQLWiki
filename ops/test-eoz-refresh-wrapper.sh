#!/usr/bin/env bash
# Synthetic host-wrapper checks. No MediaWiki bootstrap, DB, network or Git sync.
set -Eeuo pipefail

EOZ_TEST_REPO="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
EOZ_TEST_ROOT="$(mktemp -d)"
EOZ_TEST_PHP="$(command -v php)"
export EOZ_TEST_ROOT EOZ_TEST_PHP
trap 'rm -rf -- "$EOZ_TEST_ROOT"' EXIT

mkdir -p "$EOZ_TEST_ROOT/wiki/maintenance" "$EOZ_TEST_ROOT/private"
cat > "$EOZ_TEST_ROOT/wiki/maintenance/BuildEyeOfZommPack.php" <<'PHP'
<?php // Placeholder; the synthetic runner below never loads MediaWiki.
PHP
cat > "$EOZ_TEST_ROOT/wiki/maintenance/run.php" <<'PHP'
<?php
// Match the real builder's separate MediaWiki-managed memory allowance. The
// wrapper's validator starts a fresh PHP process and must set its own bound.
ini_set('memory_limit', '768M');
$output = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--output=')) $output = substr($argument, 9);
}
if (!$output) exit(90);
file_put_contents(getenv('EOZ_TEST_ROOT') . '/built', 'x', FILE_APPEND);
$mode = getenv('EOZ_TEST_MODE');
if ($mode === 'build-failure') exit(92);
if ($mode === 'malformed') { file_put_contents($output, '{bad json'); exit; }
$pack = ['meta'=>['schemaVersion'=>$mode === 'wrong-schema' ? 2 : 3],
    'zones'=>[['name'=>'Synthetic zone']], 'npcs'=>[['name'=>'Synthetic NPC']],
    'items'=>[['name'=>'Synthetic item']]];
if ($mode === 'empty') $pack['items'] = [];
// A 40 MiB scalar makes file_get_contents + json_decode exceed the shim's
// 32 MiB default. Nothing contains article, player or game-file data.
if ($mode === 'large') $pack['items'][0]['notes'] = str_repeat('x', 40 * 1024 * 1024);
file_put_contents($output, json_encode($pack, JSON_THROW_ON_ERROR));
PHP
cat > "$EOZ_TEST_ROOT/php-selector" <<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
for argument in "$@"; do
  if [[ "$argument" == *$'\n'* ]]; then
    echo "Fixture selector rejects multiline command arguments; use stdin." >&2
    exit 91
  fi
done
# User CLI directives later in argv must override this deliberately low default.
exec "$EOZ_TEST_PHP" -d memory_limit=32M -d log_errors=0 "$@"
BASH
cat > "$EOZ_TEST_ROOT/publisher" <<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ -s "$1" && -d "$2" ]]
if [[ "$EOZ_TEST_SUCCESS_EXPECTED" == absent ]]; then
  [[ ! -e "$EQL_EOZ_PRIVATE/eoz-pack-last-success" ]]
else
  [[ "$(cat "$EQL_EOZ_PRIVATE/eoz-pack-last-success")" == "$EOZ_TEST_SUCCESS_EXPECTED" ]]
fi
printf x >> "$EOZ_TEST_ROOT/published"
[[ "${EOZ_TEST_PUBLISH_FAIL:-0}" == 0 ]] || exit 93
BASH
cat > "$EOZ_TEST_ROOT/syncer" <<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ -d "$1" ]]
if [[ "$EOZ_TEST_SUCCESS_EXPECTED" == absent ]]; then
  [[ ! -e "$EQL_EOZ_PRIVATE/eoz-pack-last-success" ]]
else
  [[ "$(cat "$EQL_EOZ_PRIVATE/eoz-pack-last-success")" == "$EOZ_TEST_SUCCESS_EXPECTED" ]]
fi
printf x >> "$EOZ_TEST_ROOT/synced"
[[ "${EOZ_TEST_SYNC_FAIL:-0}" == 0 ]] || exit 94
BASH
chmod +x "$EOZ_TEST_ROOT/php-selector" "$EOZ_TEST_ROOT/publisher" "$EOZ_TEST_ROOT/syncer"
export EQL_WIKI_ROOT="$EOZ_TEST_ROOT/wiki"
export EQL_EOZ_PRIVATE="$EOZ_TEST_ROOT/private"
export EQL_PHP_BIN="$EOZ_TEST_ROOT/php-selector"
export EQL_EOZ_PUBLISHER="$EOZ_TEST_ROOT/publisher"
export EQL_EOZ_SYNCER="$EOZ_TEST_ROOT/syncer"
export EOZ_TEST_SUCCESS_EXPECTED=absent
EOZ_TEST_SUCCESS="$EQL_EOZ_PRIVATE/eoz-pack-last-success"
EOZ_TEST_BUILDS=0
EOZ_TEST_PUBLISHES=0
EOZ_TEST_SYNCS=0

fixture_count() {
  if [[ -f "$EOZ_TEST_ROOT/$1" ]]; then wc -c < "$EOZ_TEST_ROOT/$1"; else printf '0'; fi
}
check_delta() {
  [[ "$(fixture_count built)" -eq $((EOZ_TEST_BUILDS + $1)) ]]
  [[ "$(fixture_count published)" -eq $((EOZ_TEST_PUBLISHES + $2)) ]]
  [[ "$(fixture_count synced)" -eq $((EOZ_TEST_SYNCS + $3)) ]]
  EOZ_TEST_BUILDS=$((EOZ_TEST_BUILDS + $1))
  EOZ_TEST_PUBLISHES=$((EOZ_TEST_PUBLISHES + $2))
  EOZ_TEST_SYNCS=$((EOZ_TEST_SYNCS + $3))
}
check_success() {
  EOZ_TEST_SUCCESS_VALUE="$(cat "$EOZ_TEST_SUCCESS")"
  [[ "$EOZ_TEST_SUCCESS_VALUE" =~ ^[0-9]{1,10}$ ]]
  (( 10#$EOZ_TEST_SUCCESS_VALUE >= EOZ_TEST_STARTED && 10#$EOZ_TEST_SUCCESS_VALUE <= $(date +%s) ))
  [[ -z "$(find "$EQL_EOZ_PRIVATE" -maxdepth 1 -name '.eoz-pack-last-success.*.tmp' -print -quit)" ]]
}

export EOZ_TEST_MODE=large
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --local-only > "$EOZ_TEST_ROOT/run.log" 2>&1
grep -q 'Validated: zones=1 npcs=1 items=1' "$EOZ_TEST_ROOT/run.log"
grep -q 'Local-only mode: GitHub was not modified.' "$EOZ_TEST_ROOT/run.log"
[[ -s "$EQL_EOZ_PRIVATE/eye-of-zomm-pack.json" && ! -e "$EOZ_TEST_ROOT/synced" ]]
[[ ! -e "$EOZ_TEST_SUCCESS" ]]
check_delta 1 1 0
EOZ_TEST_HASH="$(sha256sum "$EQL_EOZ_PRIVATE/eye-of-zomm-pack.json" | cut -d ' ' -f 1)"

for EOZ_TEST_MODE in malformed wrong-schema empty; do
  export EOZ_TEST_MODE
  EOZ_TEST_EXPECTED=4
  [[ "$EOZ_TEST_MODE" == empty ]] && EOZ_TEST_EXPECTED=5
  EOZ_TEST_STATUS=0
  bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --local-only > "$EOZ_TEST_ROOT/run.log" 2>&1 || EOZ_TEST_STATUS=$?
  [[ "$EOZ_TEST_STATUS" -eq "$EOZ_TEST_EXPECTED" ]]
  [[ "$(sha256sum "$EQL_EOZ_PRIVATE/eye-of-zomm-pack.json" | cut -d ' ' -f 1)" == "$EOZ_TEST_HASH" ]]
  check_delta 1 0 0
  [[ ! -e "$EOZ_TEST_SUCCESS" ]]
  [[ -z "$(find "$EQL_EOZ_PRIVATE" -maxdepth 1 -name 'eye-of-zomm-pack.*.tmp.json' -print -quit)" ]]
done

export EOZ_TEST_MODE=small
EOZ_TEST_STARTED="$(date +%s)"
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" > "$EOZ_TEST_ROOT/run.log" 2>&1
check_delta 1 1 1
check_success

# A fresh published success skips every expensive stage, preserving the marker.
export EOZ_TEST_SUCCESS_EXPECTED="$EOZ_TEST_SUCCESS_VALUE"
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --if-stale > "$EOZ_TEST_ROOT/run.log" 2>&1
grep -q 'Dataset refresh skipped' "$EOZ_TEST_ROOT/run.log"
check_delta 0 0 0
[[ "$(cat "$EOZ_TEST_SUCCESS")" == "$EOZ_TEST_SUCCESS_EXPECTED" ]]

# Explicit manual/default runs still rebuild even if the last publication is fresh.
EOZ_TEST_STARTED="$(date +%s)"
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" > "$EOZ_TEST_ROOT/run.log" 2>&1
check_delta 1 1 1
check_success
export EOZ_TEST_SUCCESS_EXPECTED="$EOZ_TEST_SUCCESS_VALUE"

# Local-only is an explicit build, including when combined with --if-stale.
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --if-stale --local-only > "$EOZ_TEST_ROOT/run.log" 2>&1
check_delta 1 1 0
[[ "$(cat "$EOZ_TEST_SUCCESS")" == "$EOZ_TEST_SUCCESS_EXPECTED" ]]

# A staged manifest is not proof of publication; missing markers must rebuild.
rm -f "$EOZ_TEST_SUCCESS"
printf '{"generatedAt":"2099-01-01T00:00:00Z"}\n' > "$EQL_EOZ_PRIVATE/eoz-dataset/manifest.json"
export EOZ_TEST_SUCCESS_EXPECTED=absent
EOZ_TEST_STARTED="$(date +%s)"
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --if-stale > "$EOZ_TEST_ROOT/run.log" 2>&1
check_delta 1 1 1
check_success

# Stale markers, malformed values, oversized values and future clocks cannot skip.
for EOZ_TEST_SUCCESS_EXPECTED in "$(( $(date +%s) - 90000 ))" not-an-epoch 999999999999999999999999999999 "$(( $(date +%s) + 3600 ))"; do
  export EOZ_TEST_SUCCESS_EXPECTED
  printf '%s\n' "$EOZ_TEST_SUCCESS_EXPECTED" > "$EOZ_TEST_SUCCESS"
  EOZ_TEST_STARTED="$(date +%s)"
  bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --if-stale > "$EOZ_TEST_ROOT/run.log" 2>&1
  check_delta 1 1 1
  check_success
done

# Every failure retains the previous successful epoch. Stubs assert its value
# during publication/sync, so a premature marker write also fails the fixture.
export EOZ_TEST_SUCCESS_EXPECTED="$(( $(date +%s) - 90000 ))"
printf '%s\n' "$EOZ_TEST_SUCCESS_EXPECTED" > "$EOZ_TEST_SUCCESS"
export EOZ_TEST_MODE=build-failure
EOZ_TEST_STATUS=0
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --if-stale > "$EOZ_TEST_ROOT/run.log" 2>&1 || EOZ_TEST_STATUS=$?
[[ "$EOZ_TEST_STATUS" -eq 92 ]]
check_delta 1 0 0
[[ "$(cat "$EOZ_TEST_SUCCESS")" == "$EOZ_TEST_SUCCESS_EXPECTED" ]]
export EOZ_TEST_MODE=malformed
EOZ_TEST_STATUS=0
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --if-stale > "$EOZ_TEST_ROOT/run.log" 2>&1 || EOZ_TEST_STATUS=$?
[[ "$EOZ_TEST_STATUS" -eq 4 ]]
check_delta 1 0 0
[[ "$(cat "$EOZ_TEST_SUCCESS")" == "$EOZ_TEST_SUCCESS_EXPECTED" ]]
export EOZ_TEST_MODE=small EOZ_TEST_PUBLISH_FAIL=1
EOZ_TEST_STATUS=0
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --if-stale > "$EOZ_TEST_ROOT/run.log" 2>&1 || EOZ_TEST_STATUS=$?
[[ "$EOZ_TEST_STATUS" -eq 93 ]]
check_delta 1 1 0
[[ "$(cat "$EOZ_TEST_SUCCESS")" == "$EOZ_TEST_SUCCESS_EXPECTED" ]]
export EOZ_TEST_PUBLISH_FAIL=0 EOZ_TEST_SYNC_FAIL=1
EOZ_TEST_STATUS=0
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --if-stale > "$EOZ_TEST_ROOT/run.log" 2>&1 || EOZ_TEST_STATUS=$?
[[ "$EOZ_TEST_STATUS" -eq 94 ]]
check_delta 1 1 1
[[ "$(cat "$EOZ_TEST_SUCCESS")" == "$EOZ_TEST_SUCCESS_EXPECTED" ]]
export EOZ_TEST_SYNC_FAIL=0

# Overlap exits without work or freshness changes, leaving the lock owner alone.
exec 8>"$EQL_EOZ_PRIVATE/eoz-pack.lock"
flock -n 8
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --if-stale > "$EOZ_TEST_ROOT/run.log" 2>&1
grep -q 'already running' "$EOZ_TEST_ROOT/run.log"
check_delta 0 0 0
[[ "$(cat "$EOZ_TEST_SUCCESS")" == "$EOZ_TEST_SUCCESS_EXPECTED" ]]
flock -u 8
exec 8>&-

echo 'Synthetic refresh wrapper checks passed: bounded validation, stdin code, publication-only freshness, hourly eligibility, force/local-only behavior, invalid clocks, failures and overlap.'
