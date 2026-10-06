#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout
stage03_active || fail "Stage 03 is not enabled. Apply and test Stage 03 first."

SOURCE="$PACKAGE_ROOT/stage-04-resource-loader"
SRC_SKIN="$SOURCE/skins/EQLImmersive/skin.json"
SRC_HOOKS="$SOURCE/skins/EQLImmersive/includes/Hooks.php"
SRC_TEMPLATE="$SOURCE/skins/EQLImmersive/includes/templates/skin.mustache"
TARGET_SKIN="$SKINROOT/skin.json"
TARGET_HOOKS="$SKINROOT/includes/Hooks.php"
TARGET_TEMPLATE="$SKINROOT/includes/templates/skin.mustache"
for file in "$SRC_SKIN" "$SRC_HOOKS" "$SRC_TEMPLATE" "$TARGET_SKIN" "$TARGET_HOOKS" "$TARGET_TEMPLATE"; do require_file "$file"; done

validate_json "$SRC_SKIN"
php -l "$SRC_HOOKS"
php "$SCRIPT_DIR/lib/validate-skin-resources.php" "$SRC_SKIN" "$SKINROOT"

start_backup "stage-04"
backup_file "$LOCALSETTINGS" "LocalSettings.php"
backup_file "$TARGET_SKIN" "skins/EQLImmersive/skin.json"
backup_file "$TARGET_HOOKS" "skins/EQLImmersive/includes/Hooks.php"
backup_file "$TARGET_TEMPLATE" "skins/EQLImmersive/includes/templates/skin.mustache"

SKIN_NEXT="$SKINROOT/.skin.stage04.$$.json"
HOOKS_NEXT="$SKINROOT/includes/.Hooks.stage04.$$.php"
TEMPLATE_NEXT="$SKINROOT/includes/templates/.skin.stage04.$$.mustache"
LOCAL_NEXT="$WEBROOT/.LocalSettings.stage04.$$.php"
trap 'rm -f "$SKIN_NEXT" "$HOOKS_NEXT" "$TEMPLATE_NEXT" "$LOCAL_NEXT"' EXIT
cp -p "$SRC_SKIN" "$SKIN_NEXT"
cp -p "$SRC_HOOKS" "$HOOKS_NEXT"
cp -p "$SRC_TEMPLATE" "$TEMPLATE_NEXT"
cp -p "$LOCALSETTINGS" "$LOCAL_NEXT"
validate_json "$SKIN_NEXT"
php -l "$HOOKS_NEXT"
php "$SCRIPT_DIR/lib/config-edit.php" stage04 "$LOCAL_NEXT"
php -l "$LOCAL_NEXT"

# Resource modules first, then hooks/template, then the LocalSettings selector.
mv -f "$SKIN_NEXT" "$TARGET_SKIN"
mv -f "$HOOKS_NEXT" "$TARGET_HOOKS"
mv -f "$TEMPLATE_NEXT" "$TARGET_TEMPLATE"
mv -f "$LOCAL_NEXT" "$LOCALSETTINGS"
trap - EXIT
restart_litespeed_php

purge_main_page
printf 'Verification module HTTP status: %s\n' "$(curl_status 'https://eqlwiki.com/load.php?lang=en&modules=skins.EQLImmersive.verification&only=scripts&skin=eqlimmersive')"
printf 'Era module HTTP status: %s\n' "$(curl_status 'https://eqlwiki.com/load.php?lang=en&modules=skins.EQLImmersive.eraFilter&only=scripts&skin=eqlimmersive')"
note "Stage 04 installed successfully."
printf 'Rollback copy: %s\n' "$BACKUP_DIR"
printf '\nHard-refresh and test: Main_Page, Search, RecentChanges, Upload, Class_Guides, Magelo, mob, faction, spell, item, merchant, verified and unverified pages.\n'
printf 'Check the browser console for unknown ResourceLoader modules or missing files.\n'
printf '\nRollback this stage:\n  bash "%s/rollback-stage-04.sh"\n' "$SCRIPT_DIR"
