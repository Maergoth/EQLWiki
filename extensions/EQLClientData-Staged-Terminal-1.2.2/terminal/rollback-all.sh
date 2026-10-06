#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"

INCLUDE_STAGE_00=false
ASSUME_YES=false

usage() {
  cat <<'EOF'
Usage:
  bash terminal/rollback-all.sh
  bash terminal/rollback-all.sh --including-stage-00
  bash terminal/rollback-all.sh --yes

Default:
  Restores Stages 04 -> 01 and keeps the Stage 00 safety setting:
    $wgInvalidateCacheOnLocalSettingsChange = false;

--including-stage-00
  Also restores the pre-Stage-00 LocalSettings.php. This can restore the old
  global cache-invalidation behavior.

--yes / -y
  Skip the ROLLBACK confirmation prompt.
EOF
}

for argument in "$@"; do
  case "$argument" in
    --including-stage-00) INCLUDE_STAGE_00=true ;;
    --yes|-y) ASSUME_YES=true ;;
    --help|-h) usage; exit 0 ;;
    *) usage >&2; fail "Unknown argument: $argument" ;;
  esac
done

require_base_layout
require_dir "$BACKUP_ROOT"

B0="$(oldest_backup stage-00 || true)"
B1="$(oldest_backup stage-01 || true)"
B2="$(oldest_backup stage-02 || true)"
B3="$(oldest_backup stage-03 || true)"
B4="$(oldest_backup stage-04 || true)"

if [ -z "$B1$B2$B3$B4" ] && ! $INCLUDE_STAGE_00; then
  fail "No Stage 01-04 backups were found. Nothing to roll back with the default command."
fi
if $INCLUDE_STAGE_00 && [ -z "$B0$B1$B2$B3$B4" ]; then
  fail "No stage backups were found."
fi

printf '\nRollback chain:\n'
printf '  Stage 04: %s\n' "${B4:-not applied / no backup}"
printf '  Stage 03: %s\n' "${B3:-not applied / no backup}"
printf '  Stage 02: %s\n' "${B2:-not applied / no backup}"
printf '  Stage 01: %s\n' "${B1:-not applied / no backup}"
if $INCLUDE_STAGE_00; then
  printf '  Stage 00: %s\n' "${B0:-not applied / no backup}"
else
  printf '  Stage 00: KEEP SAFETY SETTING (false)\n'
fi

# Validate all backups before touching production.
if [ -n "$B4" ]; then
  for r in LocalSettings.php skins/EQLImmersive/skin.json skins/EQLImmersive/includes/Hooks.php skins/EQLImmersive/includes/templates/skin.mustache; do require_file "$B4/$r"; done
fi
if [ -n "$B3" ]; then
  for r in LocalSettings.php extensions/SpellLevelSlider/SpellLevelSlider.php extensions/SpellLevelSlider/spelllevelslider.js; do require_file "$B3/$r"; done
fi
if [ -n "$B2" ]; then
  for r in LocalSettings.php skins/EQLImmersive/resources/verified-pages.js; do require_file "$B2/$r"; done
fi
if [ -n "$B1" ]; then
  require_file "$B1/LocalSettings.php"
  require_file "$B1/skins/EQLImmersive/resources/era-filter.js"
fi
if $INCLUDE_STAGE_00 && [ -n "$B0" ]; then
  require_file "$B0/LocalSettings.php"
fi

if ! $ASSUME_YES; then
  printf '\nType ROLLBACK to continue: '
  read -r confirmation
  [ "$confirmation" = "ROLLBACK" ] || fail "Rollback cancelled."
fi

start_backup "pre-rollback-all"
RESCUE="$BACKUP_DIR"
backup_file "$LOCALSETTINGS" "LocalSettings.php"
backup_file "$SKINROOT/resources/era-filter.js" "skins/EQLImmersive/resources/era-filter.js"
backup_file "$SKINROOT/resources/verified-pages.js" "skins/EQLImmersive/resources/verified-pages.js"
backup_file "$EXTROOT/SpellLevelSlider/SpellLevelSlider.php" "extensions/SpellLevelSlider/SpellLevelSlider.php"
backup_file "$EXTROOT/SpellLevelSlider/spelllevelslider.js" "extensions/SpellLevelSlider/spelllevelslider.js"
backup_file "$SKINROOT/skin.json" "skins/EQLImmersive/skin.json"
backup_file "$SKINROOT/includes/Hooks.php" "skins/EQLImmersive/includes/Hooks.php"
backup_file "$SKINROOT/includes/templates/skin.mustache" "skins/EQLImmersive/includes/templates/skin.mustache"
backup_dir "$EXTROOT/EQLClientData" "extensions/EQLClientData"

note "Rolling back in reverse order..."

if [ -n "$B4" ]; then
  restore_file_from_backup "$B4" LocalSettings.php "$LOCALSETTINGS"
  restore_file_from_backup "$B4" skins/EQLImmersive/skin.json "$SKINROOT/skin.json"
  restore_file_from_backup "$B4" skins/EQLImmersive/includes/Hooks.php "$SKINROOT/includes/Hooks.php"
  restore_file_from_backup "$B4" skins/EQLImmersive/includes/templates/skin.mustache "$SKINROOT/includes/templates/skin.mustache"
  printf 'Restored Stage 04.\n'
fi

if [ -n "$B3" ]; then
  restore_file_from_backup "$B3" LocalSettings.php "$LOCALSETTINGS"
  restore_file_from_backup "$B3" extensions/SpellLevelSlider/SpellLevelSlider.php "$EXTROOT/SpellLevelSlider/SpellLevelSlider.php"
  restore_file_from_backup "$B3" extensions/SpellLevelSlider/spelllevelslider.js "$EXTROOT/SpellLevelSlider/spelllevelslider.js"
  printf 'Restored Stage 03.\n'
fi

if [ -n "$B2" ]; then
  restore_file_from_backup "$B2" LocalSettings.php "$LOCALSETTINGS"
  restore_file_from_backup "$B2" skins/EQLImmersive/resources/verified-pages.js "$SKINROOT/resources/verified-pages.js"
  printf 'Restored Stage 02.\n'
fi

if [ -n "$B1" ]; then
  # Old browser code first; the endpoint remains available until LocalSettings is restored.
  restore_file_from_backup "$B1" skins/EQLImmersive/resources/era-filter.js "$SKINROOT/resources/era-filter.js"
  restore_file_from_backup "$B1" LocalSettings.php "$LOCALSETTINGS"
  if [ -d "$B1/extensions/EQLClientData" ]; then
    atomic_replace_dir "$B1/extensions/EQLClientData" "$EXTROOT/EQLClientData"
  else
    rm -rf "$EXTROOT/EQLClientData"
  fi
  printf 'Restored Stage 01.\n'
fi

if $INCLUDE_STAGE_00 && [ -n "$B0" ]; then
  restore_file_from_backup "$B0" LocalSettings.php "$LOCALSETTINGS"
  printf 'Restored Stage 00.\n'
else
  NEXT="$WEBROOT/.LocalSettings.rollback-safety.$$.php"
  trap 'rm -f "$NEXT"' EXIT
  cp -p "$LOCALSETTINGS" "$NEXT"
  php "$SCRIPT_DIR/lib/config-edit.php" rollback-safety "$NEXT"
  php -l "$NEXT"
  mv -f "$NEXT" "$LOCALSETTINGS"
  trap - EXIT
  printf 'Retained Stage 00 safety setting.\n'
fi

php -l "$LOCALSETTINGS"
[ -f "$SKINROOT/includes/Hooks.php" ] && php -l "$SKINROOT/includes/Hooks.php"
[ -f "$EXTROOT/SpellLevelSlider/SpellLevelSlider.php" ] && php -l "$EXTROOT/SpellLevelSlider/SpellLevelSlider.php"
[ -f "$SKINROOT/skin.json" ] && validate_json "$SKINROOT/skin.json"
restart_litespeed_php
purge_main_page

note "Rollback complete."
printf 'Rescue backup of the state immediately before rollback: %s\n' "$RESCUE"
verify_live_basics
