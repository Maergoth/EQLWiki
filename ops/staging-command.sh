#!/bin/bash
set -Eeuo pipefail
STATE=/home/eqlwikdq/deploy/EQLWiki-staging
COMMAND=${SSH_ORIGINAL_COMMAND:-${1:-}}
case "$COMMAND" in
  'refresh if-stale') php "$STATE/staging-refresh.php" if-stale || exit 1 ;;
  'refresh force') php "$STATE/staging-refresh.php" force || exit 1 ;;
  deploy\ *) export EQL_DEPLOY_ENV=staging; exec /bin/bash "$STATE/deploy-receive.sh" "$COMMAND" ;;
  *) echo 'Only staging deploy or refresh commands are accepted.' >&2; exit 2 ;;
esac
