#!/bin/bash
# Private operator command; restore the current deployment, not old deltas.
set -Eeuo pipefail
umask 077
case "${EQL_DEPLOY_ENV:-production}" in
  production) STATE=/home/eqlwikdq/deploy/EQLWiki; ROOT=/home/eqlwikdq/public_html ;;
  staging) STATE=/home/eqlwikdq/deploy/EQLWiki-staging; ROOT=/home/eqlwikdq/test.eqlwiki.com ;;
  *) echo 'Unknown deployment environment.' >&2; exit 2 ;;
esac
if [[ "${EQL_DEPLOY_TESTING:-}" == 1 && -z "${SSH_ORIGINAL_COMMAND:-}" ]]; then ROOT=${EQL_DEPLOY_TEST_ROOT:?}; STATE=${EQL_DEPLOY_TEST_STATE:?}; fi
NAME=${1:?Provide the backup folder name printed by deployment}
[[ "$NAME" =~ ^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{40}$ ]] || exit 2
BACKUP="$STATE/backups/$NAME"
exec 9>"$STATE/deploy.lock"
flock -n 9 || { echo 'Deployment is running.' >&2; exit 3; }
if [[ -f "$BACKUP/plan.json" && ! -L "$BACKUP/plan.json" ]]; then
  php "$STATE/deploy-files.php" restore "$ROOT" "$STATE" "$BACKUP"
else
  # Validate historical full backups too. Retired host-owned cache entries are
  # excluded from restoration even when present in a historical archive.
  test -f "$BACKUP/files.tar.gz" && test ! -L "$BACKUP/files.tar.gz"
  STAGE=$(mktemp -d "$STATE/releases/rollback.XXXXXX")
  trap 'rm -rf -- "$STAGE"' EXIT
  tar --list --gzip --absolute-names --quoting-style=escape --file "$BACKUP/files.tar.gz" > "$STAGE/list"
  tar --list --verbose --numeric-owner --gzip --absolute-names --quoting-style=escape --file "$BACKUP/files.tar.gz" > "$STAGE/types"
  php "$STATE/deploy-files.php" validate-archive "$STAGE/list" "$STAGE/types" legacy
  mkdir "$STAGE/site"
  tar --extract --gzip --file "$BACKUP/files.tar.gz" --directory "$STAGE/site" --no-same-owner --same-permissions --no-overwrite-dir
  php "$STATE/deploy-files.php" restore-legacy "$ROOT" "$STATE" "$BACKUP" "$STAGE/site"
fi
echo "Restored $NAME. Verify the site before accepting more deployments."
