#!/usr/bin/env bash
source "$(cd "$(dirname "$0")" && pwd)/common.sh"
require_base_layout

note "Compatibility preflight"
printf 'Package root: %s\n' "$PACKAGE_ROOT"
printf 'Web root: %s\n' "$WEBROOT"
printf 'PHP CLI: %s\n' "$(php -r 'echo PHP_VERSION . " (" . PHP_SAPI . ")";')"
printf 'curl: %s\n' "$(curl --version | head -n 1)"

for path in \
  "$PACKAGE_ROOT/stage-01-era-filter/extensions/EQLClientData/extension.json" \
  "$PACKAGE_ROOT/stage-01-era-filter/skins/EQLImmersive/resources/era-filter.js" \
  "$PACKAGE_ROOT/stage-02-verified-pages/skins/EQLImmersive/resources/verified-pages.js" \
  "$PACKAGE_ROOT/stage-03-spell-overrides/extensions/SpellLevelSlider/SpellLevelSlider.php" \
  "$PACKAGE_ROOT/stage-03-spell-overrides/extensions/SpellLevelSlider/spelllevelslider.js" \
  "$PACKAGE_ROOT/stage-04-resource-loader/skins/EQLImmersive/skin.json" \
  "$PACKAGE_ROOT/stage-04-resource-loader/skins/EQLImmersive/includes/Hooks.php" \
  "$PACKAGE_ROOT/stage-04-resource-loader/skins/EQLImmersive/includes/templates/skin.mustache"
do
  require_file "$path"
done

validate_json "$PACKAGE_ROOT/stage-01-era-filter/extensions/EQLClientData/extension.json"
validate_json "$PACKAGE_ROOT/stage-04-resource-loader/skins/EQLImmersive/skin.json"
php -l "$PACKAGE_ROOT/stage-01-era-filter/extensions/EQLClientData/includes/ApiMetadata.php"
php -l "$PACKAGE_ROOT/stage-01-era-filter/extensions/EQLClientData/includes/ConfigRepository.php"
php -l "$PACKAGE_ROOT/stage-01-era-filter/extensions/EQLClientData/includes/Hooks.php"
php -l "$PACKAGE_ROOT/stage-01-era-filter/extensions/EQLClientData/includes/ServiceWiring.php"
php -l "$PACKAGE_ROOT/stage-03-spell-overrides/extensions/SpellLevelSlider/SpellLevelSlider.php"
php -l "$PACKAGE_ROOT/stage-04-resource-loader/skins/EQLImmersive/includes/Hooks.php"

note "Preflight passed. This package does not require Python."
verify_live_basics
