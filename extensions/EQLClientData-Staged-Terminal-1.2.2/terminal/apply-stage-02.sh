#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout
stage01_active || fail "Stage 01 is not enabled. Apply and test Stage 01 first."

SOURCE="$PACKAGE_ROOT/stage-02-verified-pages"
SRC_JS="$SOURCE/skins/EQLImmersive/resources/verified-pages.js"
TARGET_JS="$SKINROOT/resources/verified-pages.js"
require_file "$SRC_JS"
require_file "$TARGET_JS"
optional_node_check "$SRC_JS"

start_backup "stage-02"
backup_file "$LOCALSETTINGS" "LocalSettings.php"
backup_file "$TARGET_JS" "skins/EQLImmersive/resources/verified-pages.js"

JS_NEXT="$SKINROOT/resources/.verified-pages.stage02.$$.js"
LOCAL_NEXT="$WEBROOT/.LocalSettings.stage02.$$.php"
trap 'rm -f "$JS_NEXT" "$LOCAL_NEXT"' EXIT
cp -p "$SRC_JS" "$JS_NEXT"
cp -p "$LOCALSETTINGS" "$LOCAL_NEXT"
optional_node_check "$JS_NEXT"
php "$SCRIPT_DIR/lib/config-edit.php" stage02 "$LOCAL_NEXT"
php -l "$LOCAL_NEXT"
mv -f "$JS_NEXT" "$TARGET_JS"
mv -f "$LOCAL_NEXT" "$LOCALSETTINGS"
trap - EXIT
restart_litespeed_php

purge_main_page
note "Stage 02 installed successfully."
printf 'Rollback copy: %s\n' "$BACKUP_DIR"
printf '\nTest before Stage 03:\n'
printf '%s\n' \
  '1. Open a verified article: no verification banner.' \
  '2. Open an unverified article: banner appears.' \
  '3. Confirm Network no longer reads the full VerifiedPages revision during navigation.' \
  '4. Verify one test page and reload it.' \
  '5. Open a Special page and confirm no article-verification banner appears.'
printf '\nRollback this stage (only before Stage 03):\n  bash "%s/rollback-stage-02.sh"\n' "$SCRIPT_DIR"
