# Installation through cPanel File Manager

## 1. Upload and extract

Upload `EQLZoneViewer-1.15.0.zip` to:

    /public_html/extensions/

Extract it. Confirm this exact file exists:

    /public_html/extensions/EQLZoneViewer/extension.json

Do not leave an extra nested directory such as:

    /public_html/extensions/EQLZoneViewer/EQLZoneViewer/extension.json

## 2. Enable the extension

Open `/public_html/LocalSettings.php` and add:

```php
wfLoadExtension( 'EQLZoneViewer' );
```

No database migration, Composer command, npm command, Python runtime, Node.js runtime, or server executable is required.

## 3. Test the automatic special page

Open:

    https://eqlwiki.com/Special:ZoneViewer

The page should show **Select EverQuest Folder**.

## 4. Create the clean `/Zone_Viewer` URL

Create or edit the wiki page named exactly:

    Zone Viewer

Use this page content:

```wikitext
<zoneviewer height="calc(100vh - 190px)"></zoneviewer>
```

Then open:

    https://eqlwiki.com/Zone_Viewer

You can also embed a shorter viewer elsewhere:

```wikitext
<zoneviewer height="720px"></zoneviewer>
```

## 5. Select the correct game directory

Visitors should select the folder that directly contains the zone archives, normally the same folder containing `eqgame.exe`.

Firefox may display **Upload Folder**. In this extension that is only permission to read supported files locally. The game folder is not uploaded to EQL Wiki.

The extension indexes `.s3d`, `.eqg`, `.txt`, `.eff`, `.xmi`, `.emt`, and `.zon` files. It does not intentionally read executables, account files, logs, or screenshots.

After indexing completes, **World Map** should be enabled. The first open may take several seconds while the browser reads local base and `_1` map files, generates zone snapshots, and connects `to ...` labels. The finished atlas is cached locally and automatically receives a new cache key when relevant map/archive size or modification metadata changes.

## Updating from an earlier release

1. Rename the current `/public_html/extensions/EQLZoneViewer/` folder as a backup.
2. Upload and extract the replacement ZIP under `/public_html/extensions/`.
3. Confirm `/public_html/extensions/EQLZoneViewer/extension.json` exists.
4. Keep the existing `wfLoadExtension( 'EQLZoneViewer' );` line.
5. Hard-refresh `/Zone_Viewer`.
6. Open **Display → Clear Cache** once to remove older parsed scenes.

The JavaScript and worker URLs contain a `v=1.15.0` cache-busting query string. No database update is required.

## Uninstalling

Remove or comment out:

```php
wfLoadExtension( 'EQLZoneViewer' );
```

Then delete `/public_html/extensions/EQLZoneViewer/`. Browser-side IndexedDB data can be cleared first with **Display → Clear Cache** or later through browser site-data settings.
