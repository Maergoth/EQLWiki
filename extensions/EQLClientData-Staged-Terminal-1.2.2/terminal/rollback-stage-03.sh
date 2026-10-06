#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout
stage04_active && fail "Stage 04 is still active. Roll back Stage 04 first."
stage03_active || fail "Stage 03 does not appear to be active. Nothing to roll back."
BACKUP="$(latest_backup stage-03)"
[ -n "$BACKUP" ] || fail "No Stage 03 backup found under $BACKUP_ROOT"
for relative in LocalSettings.php extensions/SpellLevelSlider/SpellLevelSlider.php extensions/SpellLevelSlider/spelllevelslider.js; do require_file "$BACKUP/$relative"; done

start_backup "pre-rollback-stage-03"
backup_file "$LOCALSETTINGS" "LocalSettings.php"
backup_file "$EXTROOT/SpellLevelSlider/SpellLevelSlider.php" "extensions/SpellLevelSlider/SpellLevelSlider.php"
backup_file "$EXTROOT/SpellLevelSlider/spelllevelslider.js" "extensions/SpellLevelSlider/spelllevelslider.js"
RESCUE="$BACKUP_DIR"

note "Restoring Stage 03 from: $BACKUP"
restore_file_from_backup "$BACKUP" LocalSettings.php "$LOCALSETTINGS"
restore_file_from_backup "$BACKUP" extensions/SpellLevelSlider/SpellLevelSlider.php "$EXTROOT/SpellLevelSlider/SpellLevelSlider.php"
restore_file_from_backup "$BACKUP" extensions/SpellLevelSlider/spelllevelslider.js "$EXTROOT/SpellLevelSlider/spelllevelslider.js"
php -l "$LOCALSETTINGS"
php -l "$EXTROOT/SpellLevelSlider/SpellLevelSlider.php"
restart_litespeed_php
note "Stage 03 rolled back. Stage 02 and earlier remain installed."
printf 'Rescue backup of the pre-rollback state: %s\n' "$RESCUE"
verify_live_basics
