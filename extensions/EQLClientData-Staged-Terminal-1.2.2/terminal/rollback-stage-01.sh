#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout
stage04_active && fail "Stage 04 is still active. Roll back later stages first."
stage03_active && fail "Stage 03 is still active. Roll back later stages first."
stage02_active && fail "Stage 02 is still active. Roll back Stage 02 first."
stage01_active || fail "Stage 01 does not appear to be active. Nothing to roll back."
BACKUP="$(latest_backup stage-01)"
[ -n "$BACKUP" ] || fail "No Stage 01 backup found under $BACKUP_ROOT"
require_file "$BACKUP/LocalSettings.php"
require_file "$BACKUP/skins/EQLImmersive/resources/era-filter.js"

start_backup "pre-rollback-stage-01"
backup_file "$LOCALSETTINGS" "LocalSettings.php"
backup_file "$SKINROOT/resources/era-filter.js" "skins/EQLImmersive/resources/era-filter.js"
backup_dir "$EXTROOT/EQLClientData" "extensions/EQLClientData"
RESCUE="$BACKUP_DIR"

note "Restoring Stage 01 from: $BACKUP"
# Restore the old browser code while the new API is still available, then unload it.
restore_file_from_backup "$BACKUP" skins/EQLImmersive/resources/era-filter.js "$SKINROOT/resources/era-filter.js"
restore_file_from_backup "$BACKUP" LocalSettings.php "$LOCALSETTINGS"

if [ -d "$BACKUP/extensions/EQLClientData" ]; then
  atomic_replace_dir "$BACKUP/extensions/EQLClientData" "$EXTROOT/EQLClientData"
elif [ -f "$BACKUP/.absent/extensions/EQLClientData" ] || [ ! -e "$BACKUP/extensions/EQLClientData" ]; then
  rm -rf "$EXTROOT/EQLClientData"
fi

php -l "$LOCALSETTINGS"
restart_litespeed_php
purge_main_page
note "Stage 01 rolled back. EQLClientData has been returned to its pre-Stage-01 state."
printf 'Rescue backup of the pre-rollback state: %s\n' "$RESCUE"
verify_live_basics
