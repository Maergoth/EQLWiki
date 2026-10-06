#!/bin/bash
# Retry connection resets; server application failures remain visible immediately.
set -euo pipefail
COMMAND=${1:?Command required}
ARCHIVE=${2:-}
invoke() {
  ssh -i ~/.ssh/eqlwiki_deploy -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes \
    -o ConnectTimeout=20 -o ConnectionAttempts=3 -o ServerAliveInterval=30 -o ServerAliveCountMax=3 \
    eqlwikdq@us2.ableshared.com "$COMMAND"
}
for ATTEMPT in 1 2 3; do
  STATUS=0
  if [[ -n "$ARCHIVE" ]]; then invoke < "$ARCHIVE" || STATUS=$?; else invoke || STATUS=$?; fi
  if [[ "$STATUS" == 0 ]]; then exit 0; fi
  if [[ "$STATUS" != 255 || "$ATTEMPT" == 3 ]]; then exit "$STATUS"; fi
  echo 'SSH connection ended; retrying in 10 seconds.' >&2
  sleep 10
done
