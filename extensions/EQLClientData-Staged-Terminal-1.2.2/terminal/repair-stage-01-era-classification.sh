#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout
stage01_active || fail "Stage 01 is not active. Use apply-stage-01.sh for a fresh installation instead."

SOURCE="$PACKAGE_ROOT/stage-01-era-filter"
SRC_EXT="$SOURCE/extensions/EQLClientData"
SRC_CONFIG="$SRC_EXT/includes/ConfigRepository.php"
SRC_MANIFEST="$SRC_EXT/extension.json"
SRC_JS="$SOURCE/skins/EQLImmersive/resources/era-filter.js"
TARGET_EXT="$EXTROOT/EQLClientData"
TARGET_CONFIG="$TARGET_EXT/includes/ConfigRepository.php"
TARGET_MANIFEST="$TARGET_EXT/extension.json"
TARGET_JS="$SKINROOT/resources/era-filter.js"

for file in \
  "$SRC_CONFIG" \
  "$SRC_MANIFEST" \
  "$SRC_JS" \
  "$TARGET_CONFIG" \
  "$TARGET_MANIFEST" \
  "$TARGET_JS"
do
  require_file "$file"
done

note "Validating the Stage 01 era-classification repair..."
php -l "$SRC_CONFIG"
validate_json "$SRC_MANIFEST"
optional_node_check "$SRC_JS"

start_backup "hotfix-stage-01-era-classification"
backup_file "$TARGET_CONFIG" "extensions/EQLClientData/includes/ConfigRepository.php"
backup_file "$TARGET_MANIFEST" "extensions/EQLClientData/extension.json"
backup_file "$TARGET_JS" "skins/EQLImmersive/resources/era-filter.js"
HOTFIX_BACKUP="$BACKUP_DIR"

rollback_hotfix() {
  note "Repair verification failed. Restoring the pre-repair Stage 01 files..."
  restore_file_from_backup "$HOTFIX_BACKUP" extensions/EQLClientData/includes/ConfigRepository.php "$TARGET_CONFIG"
  restore_file_from_backup "$HOTFIX_BACKUP" extensions/EQLClientData/extension.json "$TARGET_MANIFEST"
  restore_file_from_backup "$HOTFIX_BACKUP" skins/EQLImmersive/resources/era-filter.js "$TARGET_JS"
  restart_litespeed_php
  fail "Stage 01 era-classification repair was automatically rolled back."
}

atomic_copy_file "$SRC_CONFIG" "$TARGET_CONFIG"
atomic_copy_file "$SRC_MANIFEST" "$TARGET_MANIFEST"
atomic_copy_file "$SRC_JS" "$TARGET_JS"
php -l "$TARGET_CONFIG"
validate_json "$TARGET_MANIFEST"
optional_node_check "$TARGET_JS"
restart_litespeed_php

note "Confirming MediaWiki loaded the repaired extension..."
if ! SITEINFO="$(curl -fsS 'https://eqlwiki.com/api.php?action=query&meta=siteinfo&siprop=extensions&format=json&formatversion=2')"; then
  rollback_hotfix
fi
if ! printf '%s' "$SITEINFO" | php "$SCRIPT_DIR/lib/check-extension-siteinfo.php"; then
  rollback_hotfix
fi

note "Testing known in-era and out-of-era pages..."
if ! ERA_RESPONSE="$(curl -fsS -X POST 'https://eqlwiki.com/api.php' \
  --data-urlencode 'action=eqlmetadata' \
  --data-urlencode 'format=json' \
  --data-urlencode 'formatversion=2' \
  --data-urlencode 'titles=Necklace of Superiority|Green Silken Drape')"; then
  rollback_hotfix
fi
if ! printf '%s' "$ERA_RESPONSE" | php "$SCRIPT_DIR/lib/check-era-known-pages.php"; then
  rollback_hotfix
fi

purge_main_page
note "Stage 01 era-classification repair installed successfully."
printf 'Repair rollback copy: %s\n' "$HOTFIX_BACKUP"
printf '%s\n' \
  'The server cache namespace was bumped, so incorrect old era-status cache entries are not reused.' \
  'The browser cache namespace was also bumped, so old false client results are ignored immediately.' \
  'Now hard-refresh Enchanter and confirm Necklace of Superiority is marked out-of-era.'
