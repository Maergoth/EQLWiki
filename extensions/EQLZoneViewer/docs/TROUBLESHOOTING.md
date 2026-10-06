# Troubleshooting

## The extension does not appear in Special:Version

Confirm that the folder and registration line are exact:

    /public_html/extensions/EQLZoneViewer/extension.json

```php
wfLoadExtension( 'EQLZoneViewer' );
```

Check the PHP error log in cPanel after loading a wiki page.

## The module or worker cannot load

Confirm these URLs return JavaScript rather than an HTML error page:

    /extensions/EQLZoneViewer/resources/dist/ZoneViewerApp.js
    /extensions/EQLZoneViewer/resources/dist/zone-parser.worker.js

If the host adds a Content-Security-Policy header, allow same-origin scripts and workers. The extension does not require an external CDN or a server-side executable.

## Firefox says “Upload Folder”

That is Firefox's wording for the folder-input permission. The extension reads supported files locally and does not send the selected folder or game archives to EQL Wiki.

## No zones are listed

Select the folder that directly contains the `.s3d` or `.eqg` archives, normally the folder containing `eqgame.exe`.

The scanner opens each candidate archive and lists only archives containing recognizable zone data. Global, object, character, equipment, and texture packages are excluded. Valid but unmapped zone names appear under **Other playable zone archives**.

## Skyfire or Skyshrine is missing

Upgrade to 1.5.0. Older candidate filtering treated every filename beginning with `sky` as a shared sky asset. Version 1.5.0 excludes only exact/shared asset prefixes and preserves the Skyfire and Skyshrine zone archives.

## An EQG zone reports that no geometry was produced

The archive may use an EQG revision not supported by the bundled `sage-core` version, or it may depend on a nonstandard asset list. The scanner can identify zone-like EQG contents more broadly than the decoder can successfully export.

Open the browser console and note the first parser error. The S3D/WLD path has broader validation in this release.

## Props are missing

Version 1.5.0 resolves every placed actor independently, preserves numbered model variants, and bakes static skinned foliage into instancable geometry. Clear the viewer cache after upgrading.

The performance panel shows unresolved placements and unmatched model names. A nonzero value usually indicates a client-specific shared archive or naming convention.

## Trees are missing or their canopies are detached in Trakanon's Teeth

Upgrade to 1.6.2 and clear the viewer cache. Earlier fuzzy matching could map numbered tree variants to one model, and version 1.5.0 baked some skinned tree geometry before applying the S3D model's static `pos` animation frame. Version 1.6.2 applies that pose first and then bakes the complete tree for instancing.

The supplied Trakanon test archives resolve 67/67 object models and 17,678/17,678 placements.

## The local map is mirrored

Upgrade to 1.5.0 and clear the viewer cache. The correct legacy S3D/map conversion is applied consistently to map lines, map labels, floating labels, coordinate readouts, and first-person positions.

## The coordinate HUD does not update while walking

Upgrade to 1.5.0. First-person coordinates now update continuously and report the character position rather than remaining at the last clicked point.

## Wall collision works only from one side, misses corners, or lets the player tunnel through

Upgrade to 1.6.2. Earlier releases tested only the latest animation-frame movement even though collision ran at 30 Hz, leaving the movement from skipped frames unchecked. They also used a single ray at camera height and inherited the visual material's one-sided triangle winding.

Version 1.6.2 sweeps all accumulated horizontal movement, uses double-sided collision-only proxies, approximates the player's body with several probes, and slides along contacted walls. **Display → Wall collision** controls horizontal walls only; grounded floor following remains active independently.

## The floor list contains too many entries

Upgrade to 1.6.2 and clear the viewer cache. Floor choices no longer come from map-pack trace heights. The viewer creates floor checkboxes only when substantial walkable geometry overlaps horizontally at distinct vertical elevations—where one floor can hide another.

No checked boxes means **All floors**. Use **Cut above** when a specific manual height is needed.

## A large zone is slow

1. Open **Display** and keep **First-person fog/culling** enabled.
2. Click the performance readout.
3. Run the five-second benchmark.
4. Compare **Terrain batches** and **Prop batches**.
5. Disable **Props** temporarily to isolate object-placement cost.
6. Clear the parsed-zone cache once after upgrading.

Version 1.5.0 physically merges static terrain, instances repeated props, bakes static skinned foliage, uses coarser batching for extremely prop-dense outdoor zones, and distance-culls render batches in First Person. Repeated UV coordinates and tiled textures do not create draw calls.

## The zone loads but the terrain is blank

Upgrade to 1.6.2. Version 1.6.0 installed the optional multi-floor shader on every material, and its world-height expression could fail to compile on materials whose stock shader did not declare Three.js' conditional `worldPosition` variable. Version 1.6.2 leaves ordinary materials untouched and installs the corrected instancing-aware floor shader only after a floor checkbox is selected. A parsed-zone cache clear is not required for this renderer-only fix.

## Textures are gray or missing

Geometry remains usable when texture decoding fails. Common causes are:

- a DDS compression mode unsupported by the GPU;
- a material name that does not match the archive texture name;
- an image format not supported by `createImageBitmap`;
- a client revision with different material conventions.

## Textures appear as long streaks

This was fixed in 1.0.1. Replacement textures inherit the generated glTF texture's wrapping, filtering, and UV transform. Hard-refresh after updating.

## The local map does not open with M

The viewer searches recursively beneath the selected installation's `maps` directory for a matching map family. Select the EverQuest installation root rather than selecting the `maps` folder itself.

The `M` hotkey is shown at the bottom-right when a matching map is available. It cycles Full map → Minimap → Closed. There is no separate map toolbar button.

## Firefox asks for the directory after every reload

That is expected for the folder-input fallback. Chromium browsers can retain a directory handle more naturally, although permission persistence remains under browser control.

## Large zones exhaust memory

Close other viewer tabs and reload. The worker temporarily holds source archive data, generated GLB data, and texture buffers during conversion. The worker terminates after parsing. Parsed payloads above 128 MB intentionally bypass IndexedDB to avoid duplicating their memory footprint.


## Named-mob links do not appear in Top Down

Confirm **Display → Named mob labels** is enabled and that a matching local map family was loaded. Version 1.6.2 removes the old 300-label cap and gives explicit `(Named)` and `(Hunter)` records priority over generic black NPC labels when screen-space labels overlap.

## Path to location does not find a name

The autocomplete searches point (`P`) records in the selected local map family. It supports exact, prefix, and substring matches. Geometry-only line labels are not point records and therefore have no destination coordinate. If the gold beam appears but the path does not, the target was resolved correctly but the grounded search could not find a collision-valid route from the position where the request was submitted.

## Top Down rotation stops at the underside

Upgrade to 1.6.2. Top Down now uses unrestricted trackball rotation instead of polar-limited orbit controls. Press `R` to return to exact overhead.
