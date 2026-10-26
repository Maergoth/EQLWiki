#!/bin/bash
set -Eeuo pipefail
STATE=/home/eqlwikdq/deploy/EQLWiki-staging
COMMAND=${SSH_ORIGINAL_COMMAND:-${1:-}}
case "$COMMAND" in
  'refresh if-stale') php "$STATE/staging-refresh.php" if-stale || exit 1; php "$STATE/staging-sync-sky-rewards.php" ;;
  'refresh force') php "$STATE/staging-refresh.php" force || exit 1; php "$STATE/staging-sync-sky-rewards.php" ;;
  status) export EQL_DEPLOY_ENV=staging; /bin/bash "$STATE/deploy-receive.sh" "$COMMAND" ;;
  deploy\ *) export EQL_DEPLOY_ENV=staging; /bin/bash "$STATE/deploy-receive.sh" "$COMMAND" || exit 1; php "$STATE/staging-sync-sky-rewards.php" ;;
  *) echo 'Only staging status, deploy or refresh commands are accepted.' >&2; exit 2 ;;
esac
