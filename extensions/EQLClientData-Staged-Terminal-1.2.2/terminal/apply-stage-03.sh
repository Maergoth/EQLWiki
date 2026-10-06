#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout
stage02_active || fail "Stage 02 is not enabled. Apply and test Stage 02 first."

SOURCE="$PACKAGE_ROOT/stage-03-spell-overrides"
SRC_PHP="$SOURCE/extensions/SpellLevelSlider/SpellLevelSlider.php"
SRC_JS="$SOURCE/extensions/SpellLevelSlider/spelllevelslider.js"
TARGET_DIR="$EXTROOT/SpellLevelSlider"
TARGET_PHP="$TARGET_DIR/SpellLevelSlider.php"
TARGET_JS="$TARGET_DIR/spelllevelslider.js"
for file in "$SRC_PHP" "$SRC_JS" "$TARGET_PHP" "$TARGET_JS"; do require_file "$file"; done
php -l "$SRC_PHP"
optional_node_check "$SRC_JS"

start_backup "stage-03"
backup_file "$LOCALSETTINGS" "LocalSettings.php"
backup_file "$TARGET_PHP" "extensions/SpellLevelSlider/SpellLevelSlider.php"
backup_file "$TARGET_JS" "extensions/SpellLevelSlider/spelllevelslider.js"

PHP_NEXT="$TARGET_DIR/.SpellLevelSlider.stage03.$$.php"
JS_NEXT="$TARGET_DIR/.spelllevelslider.stage03.$$.js"
LOCAL_NEXT="$WEBROOT/.LocalSettings.stage03.$$.php"
trap 'rm -f "$PHP_NEXT" "$JS_NEXT" "$LOCAL_NEXT"' EXIT
cp -p "$SRC_PHP" "$PHP_NEXT"
cp -p "$SRC_JS" "$JS_NEXT"
cp -p "$LOCALSETTINGS" "$LOCAL_NEXT"
php -l "$PHP_NEXT"
optional_node_check "$JS_NEXT"
php "$SCRIPT_DIR/lib/config-edit.php" stage03 "$LOCAL_NEXT"
php -l "$LOCAL_NEXT"
mv -f "$PHP_NEXT" "$TARGET_PHP"
mv -f "$JS_NEXT" "$TARGET_JS"
mv -f "$LOCAL_NEXT" "$LOCALSETTINGS"
trap - EXIT
restart_litespeed_php

note "Stage 03 installed successfully."
printf 'Rollback copy: %s\n' "$BACKUP_DIR"
printf '\nTest before Stage 04:\n'
printf '%s\n' \
  '1. Open a spell page with a known override.' \
  '2. Confirm the slider and spell classification still work.' \
  '3. Confirm Network no longer reads SpellLevelSliderOverrides during normal load.' \
  '4. Edit one test override, save, and reload the affected spell page.' \
  '5. Check a class/guide page containing lazy spell content.'
printf '\nRollback this stage (only before Stage 04):\n  bash "%s/rollback-stage-03.sh"\n' "$SCRIPT_DIR"
