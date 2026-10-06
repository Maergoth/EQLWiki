#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout
stage04_active && fail "Stage 04 is still active. Roll back Stage 04 first."
stage03_active && fail "Stage 03 is still active. Roll back Stage 03 first."
stage02_active || fail "Stage 02 does not appear to be active. Nothing to roll back."
BACKUP="$(latest_backup stage-02)"
[ -n "$BACKUP" ] || fail "No Stage 02 backup found under $BACKUP_ROOT"
for relative in LocalSettings.php skins/EQLImmersive/resources/verified-pages.js; do require_file "$BACKUP/$relative"; done

start_backup "pre-rollback-stage-02"
backup_file "$LOCALSETTINGS" "LocalSettings.php"
backup_file "$SKINROOT/resources/verified-pages.js" "skins/EQLImmersive/resources/verified-pages.js"
RESCUE="$BACKUP_DIR"

note "Restoring Stage 02 from: $BACKUP"
restore_file_from_backup "$BACKUP" LocalSettings.php "$LOCALSETTINGS"
restore_file_from_backup "$BACKUP" skins/EQLImmersive/resources/verified-pages.js "$SKINROOT/resources/verified-pages.js"
php -l "$LOCALSETTINGS"
restart_litespeed_php
purge_main_page
note "Stage 02 rolled back. Stage 01 remains installed."
printf 'Rescue backup of the pre-rollback state: %s\n' "$RESCUE"
verify_live_basics
