#!/usr/bin/env bash
(
set -e

cd "$HOME/public_html"

EXT="$HOME/public_html/extensions/EQLIconIndex"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$HOME/eqlclientdata-stage-backups/eql-icon-index-install-$STAMP"
LOCAL="$HOME/public_html/LocalSettings.php"

test -f "$EXT/extension.json" || {
	echo "ERROR: EQLIconIndex is not extracted to:"
	echo "$EXT"
	exit 1
}

mkdir -p "$BACKUP"
cp -a "$LOCAL" "$BACKUP/LocalSettings.php"

echo "Checking PHP syntax..."

find "$EXT" -type f -name '*.php' -print0 |
while IFS= read -r -d '' file; do
	php -l "$file" >/dev/null
done

if grep -q "wfLoadExtension( *['\"]EQLIconIndex['\"]" "$LOCAL"; then
	echo "EQLIconIndex is already enabled in LocalSettings.php."
else
	cat >> "$LOCAL" <<'PHP'

# EQL Icon Finder server-side fingerprint cache
wfLoadExtension( 'EQLIconIndex' );
PHP
	echo "Enabled EQLIconIndex in LocalSettings.php."
fi

echo
echo "Checking extension registration..."

if ! php maintenance/run.php checkDependencies --extensions EQLIconIndex; then
	echo "Dependency check failed. Restoring LocalSettings.php..."
	cp "$BACKUP/LocalSettings.php" "$LOCAL"
	exit 1
fi

echo
echo "Checking GD..."

php -r '
echo "PHP=" . PHP_VERSION . PHP_EOL;
echo "GD=" . (extension_loaded("gd") && function_exists("imagecreatefromstring") ? "yes" : "no") . PHP_EOL;
'

echo
echo "Extension installed."
echo "Backup: $BACKUP/LocalSettings.php"
echo
echo "Do NOT restart LSPHP."
)
