#!/usr/bin/env bash
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
printf '%s\n' \
  'This compatibility repair has been superseded by the Stage 01 classification-safe repair.' \
  'Running the current repair now...'
exec bash "$SCRIPT_DIR/repair-stage-01-era-classification.sh"
