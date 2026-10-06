#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout

SOURCE="$PACKAGE_ROOT/stage-01-era-filter"
SRC_EXT="$SOURCE/extensions/EQLClientData"
SRC_JS="$SOURCE/skins/EQLImmersive/resources/era-filter.js"
TARGET_EXT="$EXTROOT/EQLClientData"
TARGET_JS="$SKINROOT/resources/era-filter.js"

for file in \
  "$SRC_EXT/extension.json" \
  "$SRC_EXT/includes/ApiMetadata.php" \
  "$SRC_EXT/includes/ConfigRepository.php" \
  "$SRC_EXT/includes/Hooks.php" \
  "$SRC_EXT/includes/ServiceWiring.php" \
  "$SRC_JS" \
  "$TARGET_JS"
do
  require_file "$file"
done

note "Validating Stage 01 source files..."
validate_json "$SRC_EXT/extension.json"
php -l "$SRC_EXT/includes/ApiMetadata.php"
php -l "$SRC_EXT/includes/ConfigRepository.php"
php -l "$SRC_EXT/includes/Hooks.php"
php -l "$SRC_EXT/includes/ServiceWiring.php"
optional_node_check "$SRC_JS"

start_backup "stage-01"
backup_file "$LOCALSETTINGS" "LocalSettings.php"
backup_file "$TARGET_JS" "skins/EQLImmersive/resources/era-filter.js"
if [ -d "$TARGET_EXT" ]; then
  backup_dir "$TARGET_EXT" "extensions/EQLClientData"
else
  mark_absent "extensions/EQLClientData"
fi

note "Installing EQLClientData extension files..."
atomic_replace_dir "$SRC_EXT" "$TARGET_EXT"

# Prepare both live changes before enabling the extension.
JS_NEXT="$SKINROOT/resources/.era-filter.stage01.$$.js"
LOCAL_NEXT="$WEBROOT/.LocalSettings.stage01.$$.php"
trap 'rm -f "$JS_NEXT" "$LOCAL_NEXT"' EXIT
cp -p "$SRC_JS" "$JS_NEXT"
cp -p "$LOCALSETTINGS" "$LOCAL_NEXT"
optional_node_check "$JS_NEXT"
php "$SCRIPT_DIR/lib/config-edit.php" stage01 "$LOCAL_NEXT"
php -l "$LOCAL_NEXT"

# New client code first, then activate its server endpoint immediately after.
mv -f "$JS_NEXT" "$TARGET_JS"
mv -f "$LOCAL_NEXT" "$LOCALSETTINGS"
trap - EXIT
restart_litespeed_php

note "Confirming MediaWiki loaded EQLClientData..."
SITEINFO="$(curl -fsS 'https://eqlwiki.com/api.php?action=query&meta=siteinfo&siprop=extensions&format=json&formatversion=2')"
printf '%s' "$SITEINFO" | php "$SCRIPT_DIR/lib/check-extension-siteinfo.php"

note "Testing the batched era endpoint and known classifications..."
ERA_RESPONSE="$(curl -fsS -X POST 'https://eqlwiki.com/api.php' \
  --data-urlencode 'action=eqlmetadata' \
  --data-urlencode 'format=json' \
  --data-urlencode 'formatversion=2' \
  --data-urlencode 'titles=Necklace of Superiority|Green Silken Drape')"
printf '%s' "$ERA_RESPONSE" | php "$SCRIPT_DIR/lib/check-eqlmetadata.php"
printf '%s' "$ERA_RESPONSE" | php "$SCRIPT_DIR/lib/check-era-known-pages.php"

purge_main_page
note "Stage 01 installed successfully."
printf 'Rollback copy: %s\n' "$BACKUP_DIR"
printf '\nTest before Stage 02:\n'
printf '%s\n' \
  '1. Hard-refresh a link-heavy page such as Monk.' \
  '2. Confirm the era filter defaults to On.' \
  '3. Confirm On, Outline, Hide, and Off all work.' \
  '4. In Network, confirm action=eqlmetadata replaces the old prop=categories fan-out.' \
  '5. Browse normally for a while before continuing.'
printf '\nRollback this stage (only before Stage 02):\n  bash "%s/rollback-stage-01.sh"\n' "$SCRIPT_DIR"
