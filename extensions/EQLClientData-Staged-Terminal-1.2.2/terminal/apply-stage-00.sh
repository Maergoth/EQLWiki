#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout

start_backup "stage-00"
backup_file "$LOCALSETTINGS" "LocalSettings.php"

NEXT="$WEBROOT/.LocalSettings.stage00.$$.php"
trap 'rm -f "$NEXT"' EXIT
cp -p "$LOCALSETTINGS" "$NEXT"
php "$SCRIPT_DIR/lib/config-edit.php" stage00 "$NEXT"
php -l "$NEXT"
mv -f "$NEXT" "$LOCALSETTINGS"
trap - EXIT
restart_litespeed_php

note "Stage 00 installed: routine LocalSettings edits will no longer globally invalidate caches."
verify_live_basics
printf '\nRollback this stage (only before Stage 01):\n  bash "%s/rollback-stage-00.sh"\n' "$SCRIPT_DIR"
