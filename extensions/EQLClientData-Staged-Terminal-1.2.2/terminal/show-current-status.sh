#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout

printf 'Package root: %s\n' "$PACKAGE_ROOT"
printf 'Web root: %s\n\n' "$WEBROOT"
printf '%s\n' 'Relevant LocalSettings.php lines:'
grep -nE '\$wgInvalidateCacheOnLocalSettingsChange|EQLClientData' "$LOCALSETTINGS" || true

printf '\n%s\n' 'Installed EQLClientData extension:'
if [ -f "$EXTROOT/EQLClientData/extension.json" ]; then
  php -r '
    $data = json_decode( file_get_contents( $argv[1] ), true );
    echo ( $data["name"] ?? "unknown" ) . " " . ( $data["version"] ?? "unknown" ) . PHP_EOL;
  ' "$EXTROOT/EQLClientData/extension.json"
else
  printf '%s\n' 'Not installed yet.'
fi

printf '\nStage flags:\n'
printf '  Stage 01 EQLClientData loaded: %s\n' "$(stage01_active && echo yes || echo no)"
printf '  Stage 02 verification enabled: %s\n' "$(stage02_active && echo yes || echo no)"
printf '  Stage 03 spell overrides enabled: %s\n' "$(stage03_active && echo yes || echo no)"
printf '  Stage 04 split verification module enabled: %s\n' "$(stage04_active && echo yes || echo no)"

printf '\nBackups under %s:\n' "$BACKUP_ROOT"
if [ -d "$BACKUP_ROOT" ]; then
  find "$BACKUP_ROOT" -maxdepth 1 -mindepth 1 -type d -printf '  %f\n' | sort | tail -n 30
else
  printf '  none yet\n'
fi

verify_live_basics
