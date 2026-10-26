#!/bin/bash
# Install outside public_html. The forced SSH command permits only status/deploy.
set -Eeuo pipefail
umask 077
case "${EQL_DEPLOY_ENV:-production}" in
  production) ROOT=/home/eqlwikdq/public_html; STATE=/home/eqlwikdq/deploy/EQLWiki; HEALTH_URL=https://eqlwiki.com; HEALTH_AUTH=() ;;
  staging) ROOT=/home/eqlwikdq/test.eqlwiki.com; STATE=/home/eqlwikdq/deploy/EQLWiki-staging; HEALTH_URL=https://test.eqlwiki.com; HEALTH_AUTH=(--netrc-file "$STATE/health.netrc") ;;
  *) echo 'Unknown deployment environment.' >&2; exit 2 ;;
esac
# Synthetic tests cannot redirect an actual forced SSH invocation.
if [[ "${EQL_DEPLOY_TESTING:-}" == 1 && -z "${SSH_ORIGINAL_COMMAND:-}" ]]; then
  ROOT=${EQL_DEPLOY_TEST_ROOT:?}; STATE=${EQL_DEPLOY_TEST_STATE:?}; HEALTH_URL=${EQL_DEPLOY_TEST_HEALTH_URL:?}; HEALTH_AUTH=()
fi
COMMAND=${SSH_ORIGINAL_COMMAND:-${1:-}}
if [[ "$COMMAND" != status && ! "$COMMAND" =~ ^deploy\ ([a-f0-9]{40})$ ]]; then
  echo 'Only status or deploy followed by a full commit SHA is accepted.' >&2; exit 2
fi
mkdir -p "$STATE/releases" "$STATE/backups"
exec 9>"$STATE/deploy.lock"
flock -n 9 || { echo 'A deployment is already running.' >&2; exit 3; }
if [[ "$COMMAND" == status ]]; then php "$STATE/deploy-files.php" status "$STATE"; exit; fi
php "$STATE/deploy-files.php" ready "$STATE"
SHA=${COMMAND#deploy }
if [[ "${EQL_DEPLOY_ENV:-production}" == staging && -f "$STATE/refresh-in-progress" ]]; then
  echo 'A staging refresh did not complete; run a forced refresh before deployment.' >&2; exit 4
fi
STAGE=$(mktemp -d "$STATE/releases/$SHA.XXXXXX")
trap 'rm -rf -- "$STAGE"' EXIT
cat > "$STAGE/release.tar.gz"
# List original names without tar's leading-/../ stripping. Reject links,
# directories, specials, duplicate/escaped paths before extracting any member.
tar --list --gzip --absolute-names --quoting-style=escape --file "$STAGE/release.tar.gz" > "$STAGE/archive-list"
tar --list --verbose --numeric-owner --gzip --absolute-names --quoting-style=escape --file "$STAGE/release.tar.gz" > "$STAGE/archive-types"
php "$STATE/deploy-files.php" validate-archive "$STAGE/archive-list" "$STAGE/archive-types"
mkdir "$STAGE/site"
tar --extract --gzip --file "$STAGE/release.tar.gz" --directory "$STAGE/site" --no-same-owner --same-permissions --no-overwrite-dir
php "$STATE/deploy-files.php" validate "$STAGE/site" "$SHA" > "$STAGE/bundle.json"
health() {
  curl --fail --silent --show-error --retry 2 --connect-timeout 10 --max-time 30 "${HEALTH_AUTH[@]}" "$HEALTH_URL/api.php?action=query&meta=siteinfo&format=json" \
    | php -r '$j=json_decode(stream_get_contents(STDIN),true); if (!isset($j["query"]["general"]["sitename"])) { exit(1); } echo "Wiki API health check passed\n";'
}
if [[ "${EQL_DEPLOY_ENV:-production}" == staging ]]; then
  if [[ -f "$STAGE/site/.htaccess" ]]; then cp "$STAGE/site/.htaccess" "$STAGE/htaccess-base"; fi
  php "$STATE/staging-prepare.php" "$STAGE/site" "$STATE"
fi
# Lost SSH acknowledgments are safe to retry only when the exact transferred,
# staging-prepared delta still matches the already activated target.
if php "$STATE/deploy-files.php" already-applied "$STAGE/site" "$ROOT" "$STATE" "$SHA"; then
  health
  echo "Deployed $SHA (already applied delta; no files rewritten)."
  exit
else
  DUPLICATE_STATUS=$?
  [[ "$DUPLICATE_STATUS" == 10 ]] || exit "$DUPLICATE_STATUS"
fi
test -f "$ROOT/LocalSettings.php" && test ! -L "$ROOT/LocalSettings.php"
test -f "$ROOT/BridgeSecrets.php" && test ! -L "$ROOT/BridgeSecrets.php"
# Lint only transferred custom PHP. Unchanged manifest entries are not opened.
php -r '
require $argv[1]; $stage=$argv[2];
$files=eql_deploy_list(is_file("$stage/.eql-deployment-payload")?"$stage/.eql-deployment-payload":"$stage/.eql-deployment-manifest");
foreach($files as $file) {
 if(!str_ends_with($file,".php"))continue;
 if(!str_contains($file,"/") || preg_match("~^bb/[^/]+\\.php$~",$file) || $file==="bb/Themes/PurpleHaze/index.template.php" || preg_match("~^(?:skins/EQLImmersive/|extensions/(?:EQL[^/]*|AjaxHoverHelper|ClassSlotEquip|DynamicQuestItemList|DynamicZoneList|ItemLevelSlider|SpellLevelSlider|UserPageEditProtection)/)~",$file)) echo "$stage/$file\0";
}' "$STATE/deploy-files.php" "$STAGE/site" > "$STAGE/lint-list"
while IFS= read -r -d '' FILE; do php -l "$FILE" >/dev/null; done < "$STAGE/lint-list"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP="$STATE/backups/$STAMP-$SHA"
if [[ -e "$BACKUP" ]]; then
  sleep 1
  STAMP=$(date -u +%Y%m%dT%H%M%SZ)
  BACKUP="$STATE/backups/$STAMP-$SHA"
fi
mkdir "$BACKUP"
if php "$STATE/deploy-files.php" plan "$STAGE/site" "$ROOT" "$STATE" "$SHA" "$BACKUP" > "$STAGE/plan.json"; then
  :
else
  PLAN_STATUS=$?
  # Rejected stale/unsafe bundles leave no empty backup transaction behind.
  # Preserve any nonempty partial backup for operator inspection.
  rmdir "$BACKUP" 2>/dev/null || true
  exit "$PLAN_STATUS"
fi
php "$STATE/deploy-files.php" begin "$STATE" "$BACKUP"
rollback() {
  local code=${1:-$?}
  trap - ERR INT TERM HUP
  echo 'Deployment failed; restoring previous touched files and private state.' >&2
  if ! php "$STATE/deploy-files.php" restore "$ROOT" "$STATE" "$BACKUP" automatic; then
    echo "Automatic recovery failed; preserve and inspect $BACKUP before another deployment." >&2
  fi
  exit "$code"
}
trap rollback ERR
trap 'rollback 130' INT
trap 'rollback 143' TERM
trap 'rollback 129' HUP
# The marker is installed after every payload write and managed deletion.
php "$STATE/deploy-files.php" apply "$STAGE/site" "$ROOT" "$BACKUP"
health
php "$STATE/deploy-files.php" activate "$STAGE/site" "$STATE" "$BACKUP" "$SHA"
trap - ERR INT TERM HUP
cat "$STAGE/plan.json"
echo "Deployed $SHA; rollback backup: $BACKUP"
