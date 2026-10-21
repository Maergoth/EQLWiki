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
$mode = getenv('EOZ_TEST_MODE');
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
printf x >> "$EOZ_TEST_ROOT/published"
BASH
cat > "$EOZ_TEST_ROOT/syncer" <<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ -d "$1" ]]
printf x >> "$EOZ_TEST_ROOT/synced"
BASH
chmod +x "$EOZ_TEST_ROOT/php-selector" "$EOZ_TEST_ROOT/publisher" "$EOZ_TEST_ROOT/syncer"
export EQL_WIKI_ROOT="$EOZ_TEST_ROOT/wiki"
export EQL_EOZ_PRIVATE="$EOZ_TEST_ROOT/private"
export EQL_PHP_BIN="$EOZ_TEST_ROOT/php-selector"
export EQL_EOZ_PUBLISHER="$EOZ_TEST_ROOT/publisher"
export EQL_EOZ_SYNCER="$EOZ_TEST_ROOT/syncer"

export EOZ_TEST_MODE=large
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --local-only > "$EOZ_TEST_ROOT/run.log" 2>&1
grep -q 'Validated: zones=1 npcs=1 items=1' "$EOZ_TEST_ROOT/run.log"
grep -q 'Local-only mode: GitHub was not modified.' "$EOZ_TEST_ROOT/run.log"
[[ -s "$EQL_EOZ_PRIVATE/eye-of-zomm-pack.json" && ! -e "$EOZ_TEST_ROOT/synced" ]]
[[ "$(wc -c < "$EOZ_TEST_ROOT/published")" -eq 1 ]]
EOZ_TEST_HASH="$(sha256sum "$EQL_EOZ_PRIVATE/eye-of-zomm-pack.json" | cut -d ' ' -f 1)"

for EOZ_TEST_MODE in malformed wrong-schema empty; do
  export EOZ_TEST_MODE
  EOZ_TEST_EXPECTED=4
  [[ "$EOZ_TEST_MODE" == empty ]] && EOZ_TEST_EXPECTED=5
  EOZ_TEST_STATUS=0
  bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" --local-only > "$EOZ_TEST_ROOT/run.log" 2>&1 || EOZ_TEST_STATUS=$?
  [[ "$EOZ_TEST_STATUS" -eq "$EOZ_TEST_EXPECTED" ]]
  [[ "$(sha256sum "$EQL_EOZ_PRIVATE/eye-of-zomm-pack.json" | cut -d ' ' -f 1)" == "$EOZ_TEST_HASH" ]]
  [[ "$(wc -c < "$EOZ_TEST_ROOT/published")" -eq 1 && ! -e "$EOZ_TEST_ROOT/synced" ]]
  [[ -z "$(find "$EQL_EOZ_PRIVATE" -maxdepth 1 -name 'eye-of-zomm-pack.*.tmp.json' -print -quit)" ]]
done

export EOZ_TEST_MODE=small
bash "$EOZ_TEST_REPO/ops/host-bin/eoz-pack-refresh.sh" > "$EOZ_TEST_ROOT/run.log" 2>&1
[[ "$(wc -c < "$EOZ_TEST_ROOT/published")" -eq 2 && "$(wc -c < "$EOZ_TEST_ROOT/synced")" -eq 1 ]]
echo 'Synthetic refresh wrapper checks passed: bounded validation memory, stdin code, invalid-pack preservation, local-only isolation and stubbed sync.'
