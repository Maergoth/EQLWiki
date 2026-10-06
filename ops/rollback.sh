#!/bin/bash
set -Eeuo pipefail
STATE=/home/eqlwikdq/deploy/EQLWiki
ROOT=/home/eqlwikdq/public_html
NAME=${1:?Provide the backup folder name printed by deployment}
[[ "$NAME" =~ ^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{40}$ ]] || exit 2
BACKUP="$STATE/backups/$NAME"
test -f "$BACKUP/files.tar.gz"
exec 9>"$STATE/deploy.lock"
flock -n 9 || { echo 'Deployment is running.' >&2; exit 3; }
while IFS= read -r FILE; do
  [[ "$FILE" != /* && "$FILE" != *'..'* ]] || exit 4
  rm -f -- "$ROOT/$FILE"
done < "$BACKUP/new-paths"
tar -xzf "$BACKUP/files.tar.gz" -C "$ROOT" --no-same-owner
cp "$BACKUP/previous-manifest" "$STATE/manifest"
if [[ -f "$BACKUP/previous-sha" ]]; then cp "$BACKUP/previous-sha" "$STATE/current-sha"; else rm -f "$STATE/current-sha"; fi
echo "Restored $NAME. Verify the site before accepting more deployments."
