#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout
stage01_active && fail "Stage 01 or later is active. Roll those stages back first."
BACKUP="$(latest_backup stage-00)"
[ -n "$BACKUP" ] || fail "No Stage 00 backup found under $BACKUP_ROOT"
require_file "$BACKUP/LocalSettings.php"

start_backup "pre-rollback-stage-00"
backup_file "$LOCALSETTINGS" "LocalSettings.php"
RESCUE="$BACKUP_DIR"

note "Restoring Stage 00 from: $BACKUP"
restore_file_from_backup "$BACKUP" LocalSettings.php "$LOCALSETTINGS"
php -l "$LOCALSETTINGS"
restart_litespeed_php
note "Stage 00 rolled back. This may restore wgInvalidateCacheOnLocalSettingsChange=true."
printf 'Rescue backup of the pre-rollback state: %s\n' "$RESCUE"
verify_live_basics
