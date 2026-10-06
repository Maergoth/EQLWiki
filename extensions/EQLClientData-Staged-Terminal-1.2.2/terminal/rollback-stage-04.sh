#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout
stage04_active || fail "Stage 04 does not appear to be active. Nothing to roll back."
BACKUP="$(latest_backup stage-04)"
[ -n "$BACKUP" ] || fail "No Stage 04 backup found under $BACKUP_ROOT"
for relative in LocalSettings.php skins/EQLImmersive/skin.json skins/EQLImmersive/includes/Hooks.php skins/EQLImmersive/includes/templates/skin.mustache; do require_file "$BACKUP/$relative"; done

start_backup "pre-rollback-stage-04"
backup_file "$LOCALSETTINGS" "LocalSettings.php"
backup_file "$SKINROOT/skin.json" "skins/EQLImmersive/skin.json"
backup_file "$SKINROOT/includes/Hooks.php" "skins/EQLImmersive/includes/Hooks.php"
backup_file "$SKINROOT/includes/templates/skin.mustache" "skins/EQLImmersive/includes/templates/skin.mustache"
RESCUE="$BACKUP_DIR"

note "Restoring Stage 04 from: $BACKUP"
restore_file_from_backup "$BACKUP" LocalSettings.php "$LOCALSETTINGS"
restore_file_from_backup "$BACKUP" skins/EQLImmersive/skin.json "$SKINROOT/skin.json"
restore_file_from_backup "$BACKUP" skins/EQLImmersive/includes/Hooks.php "$SKINROOT/includes/Hooks.php"
restore_file_from_backup "$BACKUP" skins/EQLImmersive/includes/templates/skin.mustache "$SKINROOT/includes/templates/skin.mustache"
php -l "$LOCALSETTINGS"
php -l "$SKINROOT/includes/Hooks.php"
validate_json "$SKINROOT/skin.json"
restart_litespeed_php
purge_main_page
note "Stage 04 rolled back. Stage 03 and earlier remain installed."
printf 'Rescue backup of the pre-rollback state: %s\n' "$RESCUE"
verify_live_basics
