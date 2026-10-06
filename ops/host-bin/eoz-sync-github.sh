#!/usr/bin/env bash
set -Eeuo pipefail

SOURCE_DIR="${1:-$HOME/private-cache/mediawiki/eoz-dataset}"
REMOTE="${EQL_EOZ_GITHUB_REMOTE:-git@github.com:Maergoth/EQL-EOZ.git}"
BRANCH="${EQL_EOZ_DATA_BRANCH:-dataset}"
KEY="${EQL_EOZ_DEPLOY_KEY:-$HOME/.ssh/eql_eoz_dataset}"
PACK="eqlwiki-eye-of-zomm-data.json.gz"

[[ -f "$SOURCE_DIR/manifest.json" ]] || { echo "Missing $SOURCE_DIR/manifest.json" >&2; exit 2; }
[[ -f "$SOURCE_DIR/$PACK" ]] || { echo "Missing $SOURCE_DIR/$PACK" >&2; exit 2; }
[[ -f "$KEY" ]] || {
  echo "Missing GitHub deploy key: $KEY" >&2
  echo "See server/README.md for one-time setup." >&2
  exit 3
}
command -v git >/dev/null 2>&1 || { echo "git is not available." >&2; exit 4; }

PRIVATE="${EQL_EOZ_PRIVATE:-$HOME/private-cache/mediawiki}"
mkdir -p "$PRIVATE"
WORK="$(mktemp -d "$PRIVATE/eoz-git.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT INT TERM

cp -p "$SOURCE_DIR/manifest.json" "$WORK/manifest.json"
cp -p "$SOURCE_DIR/$PACK" "$WORK/$PACK"

cd "$WORK"
git init -q
git checkout -q --orphan "$BRANCH"
git add manifest.json "$PACK"
git -c user.name='EQLWiki Eye of Zomm Data Sync' \
    -c user.email='eqlwiki-eye-of-zomm@users.noreply.github.com' \
    commit -q -m "Update EQLWiki dataset $(date -u +%Y-%m-%d)"

export GIT_SSH_COMMAND="ssh -i $KEY -o IdentitiesOnly=yes -o BatchMode=yes"

# The dataset branch intentionally contains only one commit. Force-pushing this
# dedicated branch prevents a weekly compressed-data file from bloating Git history.
git push --force "$REMOTE" "HEAD:refs/heads/$BRANCH"

echo "GitHub dataset branch updated: $REMOTE#$BRANCH"
