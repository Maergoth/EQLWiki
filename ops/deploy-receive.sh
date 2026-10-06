#!/bin/bash
# Install outside public_html. GitHub's SSH key may only invoke this receiver.
set -Eeuo pipefail
umask 077
case "${EQL_DEPLOY_ENV:-production}" in
  production) ROOT=/home/eqlwikdq/public_html; STATE=/home/eqlwikdq/deploy/EQLWiki; HEALTH_URL=https://eqlwiki.com; HEALTH_AUTH=() ;;
  staging) ROOT=/home/eqlwikdq/test.eqlwiki.com; STATE=/home/eqlwikdq/deploy/EQLWiki-staging; HEALTH_URL=https://test.eqlwiki.com; HEALTH_AUTH=(--netrc-file "$STATE/health.netrc") ;;
  *) echo 'Unknown deployment environment.' >&2; exit 2 ;;
esac
COMMAND=${SSH_ORIGINAL_COMMAND:-${1:-}}
if [[ ! "$COMMAND" =~ ^deploy\ ([a-f0-9]{40})$ ]]; then
  echo 'Only deploy followed by a full commit SHA is accepted.' >&2
  exit 2
fi
SHA=${BASH_REMATCH[1]}
mkdir -p "$STATE/releases" "$STATE/backups"
exec 9>"$STATE/deploy.lock"
flock -n 9 || { echo 'A deployment is already running.' >&2; exit 3; }
STAGE=$(mktemp -d "$STATE/releases/$SHA.XXXXXX")
trap 'rm -rf -- "$STAGE"' EXIT
cat > "$STAGE/release.tar.gz"
# Only our trusted packaging process creates this archive, but reject path escapes.
tar -tzf "$STAGE/release.tar.gz" > "$STAGE/archive-list"
if grep -Eq '(^/|(^|/)\.\.(/|$)|^\.git(/|$)|^LocalSettings\.php$|^BridgeSecrets\.php$|^EQLStaging\.php$|^bb/Settings\.php$|^images/|^cache/|^bb/(uploads|attachments|custom_avatar|cache|exports|Packages)/)' "$STAGE/archive-list"; then
  echo 'Archive contains a protected path.' >&2; exit 4
fi
mkdir "$STAGE/site"
tar -xzf "$STAGE/release.tar.gz" -C "$STAGE/site" --no-same-owner
if [[ "${EQL_DEPLOY_ENV:-production}" == staging ]]; then
  php "$STATE/staging-prepare.php" "$STAGE/site"
fi
MANIFEST="$STAGE/site/.eql-deployment-manifest"
test -s "$MANIFEST"
test -f "$STAGE/site/index.php"
test -f "$ROOT/LocalSettings.php"
test -f "$ROOT/BridgeSecrets.php"
while IFS= read -r FILE; do
  [[ "$FILE" != /* && "$FILE" != *'..'* ]] || exit 5
  test -f "$STAGE/site/$FILE" && test ! -L "$STAGE/site/$FILE" || exit 5
done < "$MANIFEST"
# Validate the custom PHP we own before touching the live tree.
find "$STAGE/site/extensions" "$STAGE/site/skins/EQLImmersive" -type f -name '*.php' \
    \( -path '*/site/extensions/EQL*/*' -o -path '*/site/skins/EQLImmersive/*' -o -path '*/AjaxHoverHelper/*' -o -path '*/ClassSlotEquip/*' \
    -o -path '*/DynamicQuestItemList/*' -o -path '*/DynamicZoneList/*' \
    -o -path '*/ItemLevelSlider/*' -o -path '*/SpellLevelSlider/*' -o -path '*/UserPageEditProtection/*' \) -print0 > "$STAGE/lint-list"
while IFS= read -r -d '' FILE; do php -l "$FILE" >/dev/null; done < "$STAGE/lint-list"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP="$STATE/backups/$STAMP-$SHA"
mkdir "$BACKUP"
if [[ -f "$STATE/manifest" ]]; then cp "$STATE/manifest" "$BACKUP/previous-manifest"; else cp "$MANIFEST" "$BACKUP/previous-manifest"; fi
cp "$MANIFEST" "$BACKUP/new-manifest"
sort -u "$BACKUP/previous-manifest" "$MANIFEST" > "$BACKUP/managed-paths"
while IFS= read -r FILE; do
  if [[ -f "$ROOT/$FILE" ]]; then printf '%s\n' "$FILE"; fi
done < "$BACKUP/managed-paths" > "$BACKUP/existing-paths"
tar -czf "$BACKUP/files.tar.gz" -C "$ROOT" -T "$BACKUP/existing-paths"
[[ ! -f "$STATE/current-sha" ]] || cp "$STATE/current-sha" "$BACKUP/previous-sha"
sort "$MANIFEST" > "$BACKUP/sorted-new"
sort "$BACKUP/existing-paths" > "$BACKUP/sorted-existing"
sort "$BACKUP/previous-manifest" > "$BACKUP/sorted-previous"
comm -23 "$BACKUP/sorted-new" "$BACKUP/sorted-existing" > "$BACKUP/new-paths"
comm -23 "$BACKUP/sorted-previous" "$BACKUP/sorted-new" > "$BACKUP/deleted-paths"
rollback() {
  echo 'Deployment failed; restoring the previous files.' >&2
  while IFS= read -r FILE; do rm -f -- "$ROOT/$FILE"; done < "$BACKUP/new-paths"
  tar -xzf "$BACKUP/files.tar.gz" -C "$ROOT" --no-same-owner
}
trap 'rollback' ERR
# Keep settings, database, uploads, and generated caches untouched.
php -r '
[$script,$stage,$root,$manifest]=$argv;
foreach (file($manifest, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $file) {
    $source="$stage/$file"; $target="$root/$file"; $directory=dirname($target);
    if (!is_dir($directory)) {
        $missing=[]; $cursor=$directory;
        while (!is_dir($cursor)) { $missing[]=$cursor; $cursor=dirname($cursor); }
        foreach (array_reverse($missing) as $newDirectory) {
            if (!mkdir($newDirectory,0755) || !chmod($newDirectory,0755)) { exit(1); }
        }
    }
    $temporary=$target.".eql-deploy-tmp";
    if (!copy($source,$temporary) || !chmod($temporary,0644|(fileperms($source)&0111)) || !rename($temporary,$target)) { exit(1); }
}
' "$STAGE/site" "$ROOT" "$MANIFEST"
while IFS= read -r FILE; do rm -f -- "$ROOT/$FILE"; done < "$BACKUP/deleted-paths"
curl --fail --silent --show-error --retry 2 "${HEALTH_AUTH[@]}" "$HEALTH_URL/api.php?action=query&meta=siteinfo&format=json" \
  | php -r '$j=json_decode(stream_get_contents(STDIN),true); if (!isset($j["query"]["general"]["sitename"])) { exit(1); } echo "Wiki API health check passed\n";'
cp "$MANIFEST" "$STATE/manifest"
printf '%s\n' "$SHA" > "$STATE/current-sha"
trap - ERR
echo "Deployed $SHA; rollback archive: $BACKUP/files.tar.gz"
