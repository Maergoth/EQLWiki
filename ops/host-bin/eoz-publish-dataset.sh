#!/usr/bin/env bash
set -Eeuo pipefail

INPUT="${1:-}"
OUTDIR="${2:-}"

if [[ -z "$INPUT" || -z "$OUTDIR" || ! -f "$INPUT" ]]; then
  echo "Usage: $0 /path/to/eye-of-zomm-pack.json /path/to/dataset-staging"
  exit 2
fi

mkdir -p "$OUTDIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
SOURCE_HASH="$(sha256sum "$INPUT" | awk '{print substr($1,1,12)}')"
VERSION="${STAMP}-${SOURCE_HASH}"
PACK="eqlwiki-eye-of-zomm-data.json.gz"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

gzip -9 -c "$INPUT" > "$TMP/$PACK"
PACK_HASH="$(sha256sum "$TMP/$PACK" | awk '{print $1}')"
PACK_BYTES="$(stat -c '%s' "$TMP/$PACK")"

cat > "$TMP/manifest.json" <<JSON
{
  "schemaVersion": 1,
  "product": "EQLWiki - Eye of Zomm",
  "version": "$VERSION",
  "generatedAt": "$(date -u -Is)",
  "pack": "$PACK",
  "encoding": "gzip",
  "sha256": "$PACK_HASH",
  "bytes": $PACK_BYTES,
  "source": "EQLWiki"
}
JSON

mv -f "$TMP/$PACK" "$OUTDIR/$PACK"
mv -f "$TMP/manifest.json" "$OUTDIR/manifest.json"

echo "Dataset staging updated:"
echo "  $OUTDIR/manifest.json"
echo "  $OUTDIR/$PACK"
echo "  version=$VERSION"
