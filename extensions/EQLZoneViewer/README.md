# EQL Zone Viewer

EQL Zone Viewer is a self-contained MediaWiki extension that lets a visitor select a local EverQuest installation and inspect zone geometry in the browser. It provides an unrestricted top-down 3D view, a ground-aware first-person view, local EverQuest map overlays, floating named-mob labels, NPC navigation, floor isolation, coordinate readouts, collision, performance diagnostics, and a cached connected World Map generated from local map files.

The extension is designed for EQL Wiki, but the MediaWiki integration is generic enough to install on another recent MediaWiki site with minimal changes.

**Release described by this document:** 1.15.0


### Connected World Map

After a game directory has been indexed, **World Map** builds a schematic atlas from the local `maps` directory. Every recognized, loadable zone is represented by a cached top-down map-line snapshot and its friendly in-game zone name. The viewer parses point labels beginning with `to ...`, resolves them through an exact audited alias table, splits explicit multi-destination labels, and stitches the snapshots into a navigable connection graph. Substring guessing is disabled so later-expansion labels cannot silently become false classic-zone routes.

- Drag to pan the world.
- Use the mouse wheel to zoom around the cursor.
- Click any zone card or zone name to load that zone through the ordinary S3D/EQG pipeline.
- Click any Timorous Deep firepot destination name to load that destination.
- Press `R` to fit the complete atlas.
- Press `Esc` or click **World Map** again to return to the loaded zone or zone selector.
- Solid connections represent ordinary zone lines.
- Blue dashed routes represent boats, translocators, or water routes.
- Purple dashed routes represent portals, click transports, or planar teleports.
- Arrowheads identify explicitly validated one-way routes such as firepots, portal exits, and planar access.
- Timorous Deep's eleven one-way firepot destinations are bundled into a compact clickable inset rather than drawn as eleven continent-crossing routes.
- Every drawn connection is routed around unrelated zone cards, so a valid long route cannot visually appear to connect to a card it merely passes near.

This is deliberately a connected schematic rather than a claim that all EQ zones share one continuous physical coordinate system. Zone previews are generated from local map geometry so the atlas gives the visual impression of many zones being present at once without loading every full 3D archive into GPU memory. A rigid hand-authored artboard gives every supported zone a canonical slot and a uniform card footprint; no force, spring, or all-node collision pass is allowed to move the geography. The resulting graph is cached in IndexedDB using a fingerprint of relevant zone archives and map files; replacing or modifying those files produces a new cache key.

### Map states and camera persistence

The `M` hotkey cycles through three states: the normal rendered zone, the full local map, and a bottom-right minimap. The full map uses the same camera transform as Top Down and preserves pan, rotation, and zoom when opened or closed. The minimap is player-centered, excludes the `_2` coordinate-grid layer, and displays the current location/facing arrow. Initial Top Down framing uses terrain and meaningful map bounds rather than distant prop placements.

---

## 1. Goals and design constraints

The project was built around several non-negotiable constraints:

1. **The user's game files remain local.** The browser receives permission to read selected files, but the extension does not upload S3D, EQG, WLD, texture, map, account, configuration, or executable files to the wiki server.
2. **The cPanel server remains simple.** Installation consists of extracting a MediaWiki extension and adding one `wfLoadExtension()` line. No server-side Node.js, .NET, Python, Composer package, database migration, or runtime extractor is required.
3. **Parsing happens off the UI thread.** Legacy archives are decoded in a same-origin Web Worker so a large zone does not freeze MediaWiki's page chrome.
4. **The viewer preserves native EverQuest coordinates.** Geometry, local map lines, map points, labels, picked locations, first-person movement, and teleport targets all use one canonical coordinate conversion.
5. **Large zones must be renderable on ordinary desktop browsers.** Static terrain is merged, repeated props are instanced, equivalent materials are reused, and first-person mode can apply fog plus true distance culling.
6. **Local map packs enhance the viewer rather than define the geometry.** Map files supply labels, map lines, useful spawn points, and navigation metadata, but they are not treated as authoritative 3D floor meshes.
7. **The user interface exposes the controls.** The application includes a Controls panel, contextual HUD shortcuts, performance diagnostics, and plain-language directory-permission messaging.

---

## 2. What the extension provides

### Local source selection

- Select an EverQuest installation folder.
- Scan relevant files recursively.
- Support the modern `showDirectoryPicker()` API where available.
- Fall back to a folder input using `webkitdirectory` in Firefox and compatible browsers.
- Explain that Firefox's **Upload Folder** wording grants local read permission and does not transmit the folder.

### Zone discovery

- Inspect S3D and EQG archives before listing them.
- Exclude obvious global, character, equipment, object-only, texture-only, and shared archives from the primary zone list.
- Show recognized zones by friendly in-game names.
- Preserve valid but unmapped zone archives under **Other playable zone archives**.
- Offer a connected World Map as an alternative to selecting a zone from the dropdown.
- Resolve related main, object, character, lighting, sound, and asset-list filenames as needed by the parser.

### Rendering views

- **Top Down**: an unrestricted trackball camera that can rotate, roll, pan, zoom, and move beneath the map.
- **Reset**: returns Top Down to an exact overhead view fitted to the zone.
- **First Person**: pointer-lock navigation with grounded walking, sprinting, jumping, optional flight, ground following, wall collision, and live native-EQ coordinates.
- `M`: cycles Closed → Full map → bottom-right Minimap while retaining Top Down framing.
- The local map draws a glowing red arrow at the most recent First Person location and rotates it to match the viewer's horizontal facing direction.

### Display controls

- Textures
- Props
- Named mob labels
- Location beams
- First-person fog and distance culling
- Wireframe
- Wall collision
- Manual height cut
- Multi-select floor checkboxes
- Full-screen mode
- Parsed-zone cache clearing

### Labels and navigation

- Parse local map point records.
- Display named/Hunter and plausible NPC labels as floating HTML links.
- Open wiki pages in a new browser tab.
- Keep all on-screen named labels visible in Top Down.
- Apply distance and overlap suppression only in First Person, while preserving nearby labels.
- Exclude navigation notes, tips, traps, ground-spawn labels, and other non-NPC annotations from named-mob links.
- Suppress any map point label that explicitly mentions **Brewall**, including possessive forms such as `Brewall's`.
- Provide **Path to location** autocomplete over all remaining loaded map point labels. The nearest matching location receives a vertical gold destination beam, and First Person receives a best-effort collision-aware ground route that honors the viewer’s 10-unit jump limit.


### World-map generation pipeline

1. Build a canonical set of recognized, loadable archive records. Duplicate modern/classic aliases such as `northro`/`nro`, `southro`/`sro`, `sky`/`airplane`, and `westkarana`/`qey2hh1` collapse to one world node.
2. Locate the best base and `_1` map family for each node. Known filename differences such as `kerra` → `kerraridge` are handled by explicit aliases.
3. Parse base geometry and point records while excluding `_2` coordinate-grid layers.
4. Produce a robust top-down snapshot. Endpoint percentiles reject distant legends or author marks, and very dense maps are deterministically sampled to a bounded segment count.
5. Resolve `to ...` point labels through normalized friendly names, archive IDs, common aliases, and source-specific city/dungeon overrides. Unknown expansion, internal-room, or self-referential labels are ignored.
6. Add a small set of explicit non-geometric routes for validated boats, translocators, planes, and expedition entrances that ordinary map labels do not describe consistently. The Tutorial Zone is deliberately omitted from the atlas.
7. Place continents and zones on a hand-authored, spacious schematic matching the recognizable classic-world geography. Cards never participate in force or collision relaxation.
8. Route each connection through dedicated obstacle-aware lanes around unrelated cards. A single unavoidable topological crossing uses a cartographic bridge arc so the lines visibly cross without implying a connection. Each zone preview is pre-rendered once to an offscreen canvas.
9. Cache serializable graph data—not canvas objects—under a `world:` IndexedDB key. The zone cache uses separate `zone:` keys, allowing zone changes to invalidate parsed 3D data without discarding an unchanged world atlas.

The world atlas never loads all S3D/EQG geometry simultaneously. It reads map text to build lightweight previews, then loads a single full zone only after the user clicks it.

### Performance and diagnostics

- Merge compatible static terrain geometry.
- Batch terrain spatially.
- Instance repeated prop models.
- Assemble every bone-attached mesh fragment of a legacy skeletal prop into one model before applying its static pose. This prevents canopy, trunk, branch, and plant fragments from overwriting one another.
- Apply static bind-pose animation before baking legacy skinned foliage.
- Preserve numbered tree/plant model variants.
- Use adaptive first-person fog and actual distance-based batch visibility.
- Display measured FPS, frame interval, render time, draw calls, triangles, GPU geometry/texture counts, terrain batches, prop batches, visible/culled batches, placed props, unresolved placements, and view distance.
- Run a five-second continuous-render benchmark.

---

## 3. User workflow

1. Open `Special:ZoneViewer` or the wiki page that embeds `<zoneviewer>`.
2. Click **Select EverQuest Folder**.
3. Select the folder that contains the game archives, normally the folder containing `eqgame.exe`.
4. Choose a recognized zone or an entry under **Other playable zone archives**.
5. Click **Load Zone**.
6. Wait while the Web Worker decodes the archive and the main thread builds render batches.
7. Use Top Down to inspect the complete zone or First Person to navigate it.
8. Press `M` when a matching local map family exists: first press opens the full map, second press returns to the zone with a minimap, and third press closes the minimap. The glowing red arrow shows the last First Person position and heading.
9. Enable or disable labels, props, textures, fog/culling, wireframe, and collision from **Display**.
10. Click the performance readout to inspect live renderer metrics.

The selected directory is not uploaded. Only browser-local `File` objects and generated in-memory buffers are used.

---

## 4. MediaWiki entry points

The extension automatically registers:

```text
Special:ZoneViewer
```

To provide a clean article URL such as:

```text
https://eqlwiki.com/Zone_Viewer
```

create a normal wiki page named `Zone Viewer` containing:

```wikitext
<zoneviewer height="calc(100vh - 190px)"></zoneviewer>
```

A smaller embedded viewer can be placed on another page:

```wikitext
<zoneviewer height="720px"></zoneviewer>
```

The parser tag currently accepts only the `height` parameter. The PHP layer sanitizes it to either:

- a three- or four-digit pixel value, such as `720px`; or
- a restricted CSS `calc(...)` expression.

Invalid values fall back to `780px`.

---

## 5. Installation

### Requirements

- MediaWiki 1.43 or newer
- PHP 8.1 or newer
- HTTPS, because directory access and several browser APIs require a secure context
- A browser with WebGL2 support

### cPanel installation

1. Upload the release ZIP to:

   ```text
   /public_html/extensions/
   ```

2. Extract it so this exact file exists:

   ```text
   /public_html/extensions/EQLZoneViewer/extension.json
   ```

3. Add this line to `LocalSettings.php`:

   ```php
   wfLoadExtension( 'EQLZoneViewer' );
   ```

4. Open:

   ```text
   https://eqlwiki.com/Special:ZoneViewer
   ```

5. Hard-refresh once after an update so the new browser bundle is loaded.

No database update is required.

### Optional configuration

The extension exposes one MediaWiki configuration value:

```php
$wgEQLZoneViewerDefaultHeight = 780;
```

This is used for embeds that do not specify a `height` attribute.

---

## 6. Repository and release layout

```text
EQLZoneViewer/
├── extension.json
├── EQLZoneViewer.alias.php
├── VERSION
├── LICENSE
├── THIRD_PARTY_NOTICES.md
├── README.md
├── INSTALL.md
├── CHANGELOG.md
├── i18n/
│   ├── en.json
│   └── qqq.json
├── src/
│   ├── Hooks.php
│   ├── SpecialZoneViewer.php
│   └── ViewerMarkup.php
├── resources/
│   ├── ext.eqlZoneViewer.bootstrap.js
│   ├── ext.eqlZoneViewer.css
│   └── dist/
│       ├── ZoneViewerApp.js
│       └── zone-parser.worker.js
├── docs/
│   ├── USAGE.md
│   └── TROUBLESHOOTING.md
├── LICENSES/
│   ├── sage-core-MIT.txt
│   └── three-MIT.txt
└── build/
    ├── package.json
    ├── package-lock.json
    ├── rebuild.sh
    ├── README.md
    ├── src/
    │   ├── viewer.js
    │   └── parser-worker.js
    └── overrides/
        └── sage-core/
```

The `resources/dist` files are production bundles. cPanel installation does not require the `build` directory, but it is included so the browser code can be audited and reproduced.

---

## 7. MediaWiki integration architecture

### `extension.json`

Declares:

- extension metadata and minimum versions;
- the `EQLWiki\ZoneViewer\` autoload namespace;
- `Special:ZoneViewer`;
- the parser hook;
- the `ext.eqlZoneViewer` ResourceLoader module;
- i18n message directories;
- the default-height configuration value.

### `Hooks.php`

Registers the `<zoneviewer>` parser tag during `ParserFirstCallInit`:

```php
$parser->setHook( 'zoneviewer', [ self::class, 'renderTag' ] );
```

The render callback adds the ResourceLoader module and returns a sanitized viewer root element.

### `SpecialZoneViewer.php`

Creates the dedicated special page, adds the summary message, loads the ResourceLoader module, and renders a full-height viewer instance.

### `ViewerMarkup.php`

Produces a root element:

```html
<div class="eqlzv-root" data-eqlzv-config="..."></div>
```

The JSON payload contains:

- the versioned viewer-module URL;
- the versioned worker URL;
- the selected viewer height;
- the extension version.

Version query strings are used to break browser and intermediary caches after a release.

### Bootstrap module

`ext.eqlZoneViewer.bootstrap.js`:

1. Finds unmounted `.eqlzv-root` elements.
2. Reads the JSON configuration.
3. Dynamically imports `ZoneViewerApp.js` once.
4. Calls `mountZoneViewer(root, config)` for each root.
5. Hooks into `mw.hook('wikipage.content')` so embeds added by MediaWiki navigation or dynamic content can initialize.

The full viewer bundle is intentionally not part of the ordinary ResourceLoader JavaScript bundle because it is large and contains ESM dependencies bundled by esbuild.

---

## 8. Local file access and privacy model

### Chromium path

When supported, the application calls:

```js
showDirectoryPicker({ mode: 'read' })
```

The returned directory handle is traversed recursively. The browser controls permission persistence.

### Firefox and fallback path

The extension creates a hidden input:

```html
<input type="file" webkitdirectory multiple>
```

Firefox may label the confirmation action **Upload Folder**. In this extension, it only creates local `File` objects available to the current page. There is no upload endpoint or request containing the file bytes.

### Indexed files

The scanner considers supported extensions such as:

```text
.s3d .eqg .txt .eff .xmi .emt .zon
```

It records normalized relative paths, filenames, sizes, modification timestamps, and directory depth. It does not intentionally read account credentials, screenshots, logs, executables, or configuration files.

### Network isolation

The authored application does not send selected game-file contents through:

- `fetch()`;
- `XMLHttpRequest`;
- `WebSocket`;
- `sendBeacon()`;
- form upload endpoints.

The parser worker is loaded from the same extension origin. Three.js, the decoder, and supporting code are bundled locally rather than loaded from a CDN.

---

## 9. Zone discovery

A game directory contains many S3D and EQG archives that are not zones. Listing every archive creates misleading entries such as equipment, mirrors, objects, characters, spell assets, and global texture containers.

The discovery process therefore uses several stages.

### Filename prefiltering

Known non-zone prefixes and suffixes are excluded. Examples include:

```text
global_*
gequip*
*_obj
*_chr
*_lit
*_sounds
*_assets
*_items
*_mirrors
*_textures
```

The rules are deliberately conservative so a valid archive with an unknown friendly name can still appear.

### Archive inspection

Candidate archives are opened and inspected for recognizable zone data.

For legacy S3D/PFS archives, the scanner looks for the expected zone WLD and related entries. For EQG archives, it looks for zone/terrain placement data rather than assuming every EQG is a zone.

### Friendly-name mapping

A static mapping converts internal short names such as:

```text
trakanon   -> Trakanon's Teeth
soldungb   -> Nagafen's Lair (Sol B)
airplane   -> Plane of Sky
```

Recognized entries are grouped separately from valid archives without a known mapping. Unknown archives remain selectable rather than being silently hidden.

### Related-file resolution

When a zone is loaded, the source resolver collects the main archive and relevant siblings/dependencies. Depending on client format, these may include object archives, character archives, lighting files, sound banks, or EQG asset-list dependencies.

Character archives are not treated as static zone scenery unless the decoder actually needs them for the selected format.

---

## 10. Parser worker architecture

The worker is responsible for archive decoding and browser-safe export. Its job is to turn local game archives into data the Three.js viewer can load efficiently.

### Why a worker is required

Archive decompression, WLD decoding, object resolution, texture conversion, and GLB generation can be CPU- and memory-intensive. Running them on the page's main thread would block controls, progress messages, and MediaWiki UI.

### Bundled decoder

The project uses a modified browser build of `sage-core` 0.0.38. The source is MIT licensed and the required notices are included.

The build applies local overrides because upstream export paths assume a conventional file system and optional exporter behavior. EQL Zone Viewer replaces those assumptions with an in-memory virtual file system.

### In-memory file system

The worker constructs memory-backed directory and file handles that implement the minimal interfaces expected by the decoder. Generated output is written into a virtual store rather than to the user's disk.

Conceptually:

```text
Local File objects
    -> in-memory directory handles
    -> sage-core decoder/exporter
    -> virtual generated files
    -> ArrayBuffers returned to the page
```

### S3D/PFS path

The legacy path:

1. Reads the PFS archive directory.
2. Inflates compressed entries.
3. Locates WLD data and referenced textures/models.
4. Uses the decoder to export the zone to GLB.
5. Exports referenced object models to separate GLB buffers.
6. Returns placement metadata and decoded textures.

### EQG path

The EQG path:

1. Loads explicit dependencies before the selected zone archive.
2. Decodes zone/terrain and asset records.
3. Exports the selected zone to GLB.
4. Collects referenced object GLBs and textures.

Dependency order matters: loading a dependency after the selected zone can allow an unrelated generated zone file to replace the intended result.

### Selecting the generated zone

The worker first searches for the expected generated path:

```text
zones/<zone-short-name>.glb
```

If it is unavailable, it falls back to the closest generated zone GLB. The page reports a parser error when no usable geometry is produced.

### Referenced object filtering

Object archives may contain hundreds of models, most of which are not placed in the selected zone. The worker uses placement metadata and aliases from:

- filenames;
- glTF scene names;
- node names;
- mesh names;
- actor definitions;
- actor names;
- sprite/mesh suffixes.

Every placed actor is resolved independently. Exact numbered variants are preferred so assets such as `jntree101` through `jntree105` do not collapse to one model.

### Referenced texture filtering

The worker inspects generated glTF JSON and gathers referenced image, texture, and material names. For very large texture sets, it returns the referenced subset plus likely numbered animation frames. If filtering appears unreliable, it conservatively returns the complete set rather than causing a textureless zone.

### Worker result

The page receives a message containing approximately:

```js
{
  zoneBuffer,       // GLB ArrayBuffer
  objects,          // { filename: GLB ArrayBuffer }
  textures,         // decoded/raw texture buffers
  metadata,         // placements and related zone data
  stats              // parser and resolution diagnostics
}
```

The worker terminates after completion or failure to release its memory.

---

## 11. Canonical coordinate system

EverQuest and Three.js use different axis conventions. The viewer uses one canonical transform everywhere:

```text
Three X =  EQ Y
Three Y =  EQ Z
Three Z = -EQ X
```

Equivalent vector form:

```js
new THREE.Vector3(eqY, eqZ, -eqX)
```

The inverse is:

```text
EQ X = -Three Z
EQ Y =  Three X
EQ Z =  Three Y
```

This transform is applied to:

- decoded zone geometry alignment;
- placed prop transforms;
- local map line endpoints;
- map points;
- floating labels;
- clicked coordinates;
- the live first-person HUD;
- Path-to-location targets;
- candidate first-person spawn positions.

Using separate transforms for maps, labels, and terrain produces mirrored maps, displaced labels, and incorrect coordinate readouts. The implementation deliberately centralizes these conversions.

---

## 12. Local EverQuest map files

The viewer recursively searches the selected installation's `maps` directory, including custom map-pack subdirectories.

A map family may include:

```text
zone.txt
zone_1.txt
zone_2.txt
zone_3.txt
...
```

### Supported record types

Line record:

```text
L x1, y1, z1, x2, y2, z2, red, green, blue
```

Point record:

```text
P x, y, z, red, green, blue, size, label
```

Labels may contain commas. The parser joins every field after the size field so metadata such as `(Hunter,Roam,HS)` remains intact.

Underscores in point labels are converted to spaces for display and search.

### Layer behavior

The base file generally contains map geometry. `_1` commonly contains point labels. `_2` commonly contains axes, coordinate markers, or a grid. The viewer does not assume every map pack follows exactly the same convention, but it treats coordinate/grid layers as annotation rather than 3D floor evidence.

### Brewall-label suppression

Point labels are filtered during parsing. A label is discarded when it contains the standalone word `Brewall`, case-insensitively, including possessive variants:

```text
Brewall
Brewall's
Brewall’s
```

Because the filter runs before map rendering, named-label construction, and autocomplete construction, a suppressed label cannot appear in:

- the local map overlay;
- floating named/NPC labels;
- wiki links;
- Path-to-location suggestions.

The source map file remains untouched.

### Map rendering

Map lines are rendered as colored Three.js line segments using the same top-down camera as the terrain. Point labels are drawn to an overlay canvas by projecting their 3D positions through that active camera.

Pressing `M` cycles the terrain, full map, and minimap states. The full map reuses the active Top Down camera without reframing it, and its pan/zoom state becomes the restored Top Down state when the full map closes. The minimap is a separate player-centered 2D overlay and does not alter the main camera.

The overlay canvas also draws a fixed-size glowing red direction arrow. Its anchor is the most recently captured First Person ground position. A second projected point, offset along the stored horizontal camera-forward vector, determines the arrow's screen rotation. Because both points are projected through the active map camera, the arrow remains correctly oriented while the user pans, zooms, rolls, or rotates the map.

---

## 13. Scene construction

The viewer builds three principal scene groups:

```text
zoneGroup   decoded/merged zone geometry
propsGroup  placed static objects and foliage
mapGroup    local map line and point geometry
```

Only the active terrain/full-map group is visible. The minimap is a lightweight 2D canvas overlay. Named labels are HTML overlays positioned from 3D coordinates.

### GLB loading

`GLTFLoader` parses the generated zone and object buffers directly from memory. No generated GLB is uploaded or written to the wiki server.

### Material preparation

The viewer:

- normalizes side and transparency behavior;
- preserves texture wrapping and UV transforms;
- uses repeat wrapping for legacy tiled UV coordinates;
- replaces placeholder/generated images with decoded local textures;
- deduplicates equivalent materials;
- uses one-pass double-sided transparency where practical for foliage.

Repeated UVs are normal for EverQuest and do not inherently create more draw calls.

### Terrain batching

The decoded zone often contains many small source meshes. Rendering them independently can create tens of thousands of draw calls despite a modest triangle count.

The batcher:

1. Traverses static meshes.
2. Converts geometry into a common coordinate space.
3. Groups compatible records by material and spatial region.
4. Physically merges compatible `BufferGeometry` objects.
5. Keeps unsupported/animated fallback objects separate.

The result is a smaller set of real WebGL draw calls and works consistently in Firefox without depending on optional multi-draw extensions.

### Prop placement

Zone metadata stores object definitions separately from placement instances. The viewer resolves each placement name to an object template, applies the EQ/object transform, and then either:

- adds it to a spatial `InstancedMesh` batch; or
- clones it through a fallback path when the model cannot be safely instanced.

### Static skinned foliage

Legacy S3D foliage has two independent assembly requirements.

First, a skeletal actor can store separate mesh fragments on several bones. The upstream export loop wrote every fragment to the same object filename, so later fragments replaced earlier fragments. A tree whose canopy was exported last could therefore retain the canopy while losing the trunk and lower branches. The bundled `sage-core` override now gathers every bone-attached mesh, merges all material primitives into one glTF mesh, assigns each triangle to its owning bone with `JOINTS_0` and `WEIGHTS_0`, attaches the full skeleton, and writes the actor once. This is procedural and applies to all legacy skeletal props; there is no per-zone correction table.

Second, some legacy trees store their correct bind transforms in an animation named `pos` rather than directly on the exported glTF nodes. Baking geometry before applying this pose leaves branches or canopies at the model origin.

The viewer therefore:

1. receives the complete multi-fragment actor from the worker;
2. finds the `pos` clip, or the first clip as a fallback;
3. applies it at time zero with an `AnimationMixer`;
4. updates world and skeleton matrices;
5. bakes all skinned vertices into static geometry;
6. instances the complete posed tree.

This preserves trunks, branches, and tops as one assembled model while retaining instancing performance.

---

## 14. Top Down view

Top Down uses `TrackballControls`, not a polar-limited orbit camera. This permits:

- full pitch rotation;
- unrestricted underside inspection;
- continuous roll;
- panning;
- wheel zoom.

Controls:

```text
Left drag      Rotate
Right drag     Pan
Mouse wheel    Zoom
R              Reset to exact overhead
M              Cycle Full map → Minimap → Closed
H              Open/close Controls
```

### Reset behavior

Reset computes terrain-focused bounds, optionally substitutes meaningful non-grid map bounds when terrain bounds are clearly inflated, chooses the center and required camera distance, places the camera directly above the zone, points it downward, and updates the trackball target. Distant props are excluded from initial fitting, and the framing margin is intentionally tight.

The full local map uses the same camera state, so opening or closing it preserves pan, rotation, and zoom. Resetting either terrain or full map returns to the same exact overhead framing.

---

## 15. First Person view

First Person uses `PointerLockControls` and a custom movement/collision layer.

### Controls

```text
Click viewport   Capture mouse
W A S D          Move
Shift            Sprint
Space            Jump when grounded; rise when flying
E                Rise when flying
Q / C            Descend when flying
G                Toggle Grounded / Fly
Esc              Release mouse
```

Ctrl is not used for movement, avoiding conflicts with browser shortcuts.

### Walking speed

Normal grounded movement is intentionally slower than the original prototype. Sprint uses the former normal speed.

### Flight

Forward and backward movement follow the full view direction, including pitch. Looking down and pressing `W` moves toward the ground. Separate rise/descend controls remain available.

### Ground following

Grounded mode casts downward against walkable terrain and maintains an eye-height offset. The ground search is separate from horizontal wall collision so disabling wall collision does not disable ground locking.

### Jumping

Space applies a vertical jump velocity calibrated for an approximately ten-EQ-foot apex under the viewer's gravity model. The user returns to ground following after the airborne phase.

### Spawn selection

The initial first-person position is selected in this order:

1. valid ground near native EQ `0,0,0`;
2. valid ground near a local map point or line endpoint closest to the origin;
3. valid ground near the zone center;
4. a conservative bounds-based fallback.

This avoids beginning far below an irregular zone merely because the geometric bounding-box center is underground.

---

## 16. Collision design

Ground and wall collision are deliberately separate systems.

### Ground collision

- Uses downward rays against walkable terrain.
- Updates the grounded height continuously.
- Handles slopes within the configured walkable-normal range.
- Maintains eye height above the surface.

### Wall collision

Wall collision uses collision-only proxies rather than visual material winding. Proxies are double-sided so a legacy triangle cannot be passable from one direction.

The system:

1. accumulates horizontal movement since the last collision test;
2. performs a swept test rather than checking only the latest animation frame;
3. probes multiple heights approximating feet, torso, shoulders, and eyes;
4. broad-phase rejects terrain batches whose bounds cannot intersect the movement sweep;
5. ignores upward-facing floor/slope normals during horizontal wall handling;
6. removes the blocked component of movement to slide along the wall.

This reduces wall tunneling, corner penetration, and one-sided collision while avoiding a full physics engine.

---

## 17. Floating labels and wiki links

### Source labels

Floating labels are derived from point records in the selected local map family.

A point qualifies as a named/NPC candidate when it is:

- explicitly marked `(Named)` or `(Hunter)`;
- close to the standard named-mob brown color; or
- a plausible black NPC label that is not a navigation or annotation record.

Examples excluded from named-mob links include labels beginning with:

```text
to
succor
tip:
gs:
trap:
locked
zone
warning
boat
path
bridge
dock
water
lava
```

Any label mentioning Brewall is removed earlier during parsing and never reaches this classifier.

### Label cleanup

Trailing metadata is removed from the visible wiki title when it contains marker terms such as:

```text
Named, Hunter, Roam, HS, Mission, GM, Merchant, Parcel, Raid, Bank, Cultural
```

For example:

```text
The_Domineering_Ukun_(Hunter,Roam,HS)
```

becomes:

```text
The Domineering Ukun
```

### Link behavior

The extension uses `mw.util.getUrl()` when available and falls back to a standard `/wiki/<title>` URL. Links use:

```html
target="_blank" rel="noopener noreferrer"
```

so clicking a named label opens the corresponding wiki page in a new tab without giving the new page access to the opener.

### Top Down visibility

All named labels that project on screen are shown. They are not hidden by:

- terrain depth;
- walls;
- floor filtering;
- distance limits;
- overlap suppression.

The labels are DOM elements above the WebGL canvas.

### First Person visibility

First Person may hide labels that are:

- behind the camera;
- outside the viewport;
- beyond the active label distance;
- lower priority during overlap.

Nearby labels within the configured always-visible radius remain displayed even when they overlap or are outside a selected floor band.

### Crosshair targeting

When the pointer is locked, clicking the viewport opens the label nearest the crosshair when it is inside the targeting threshold. Otherwise the click remains a normal first-person input action.

---

## 18. Path to location

The autocomplete is built from all remaining point labels, not only named-mob links.

Search order:

1. exact cleaned or raw label;
2. prefix match;
3. substring match.

When several identical labels exist, the viewer chooses the occurrence nearest the current position. That position is captured as the immutable route origin. The player may move while calculation continues, but the route is never restarted, trimmed, or rebased. It then:

1. converts the map coordinate to Three.js space;
2. resolves valid ground at the destination;
3. switches to First Person without teleporting the user;
4. paints a vertical gold beam beneath the destination label;
5. searches walkable surfaces with sparse A* pathfinding;
6. rejects steps above the same 10-unit jump limit and tests each segment against wall collision;
7. paints a glowing golden route when a valid path is found.

The route is navigation guidance rather than an authoritative game navmesh. Complex doors, elevators, scripted teleports, underwater travel, and geometry absent from the loaded collision scene can prevent a complete path. The destination beam remains visible even when no grounded path is found.

---

## 19. Floor detection and filtering

Map-pack Z bands are not floor counts. Cartographers often trace walls at several nearby standing heights, producing many bands in a zone that has one actual floor.

The viewer uses this rule:

> A separate floor exists only where a substantial walkable surface vertically hides another substantial walkable surface over meaningful horizontal area.

### Detection outline

1. Sample upward-facing triangles from the actual zone geometry.
2. Reject walls, steep slopes, tiny decorative surfaces, and props.
3. Spatially bin the walkable samples in the horizontal plane.
4. In each bin, collect significant height clusters.
5. Find height clusters that recur across many bins.
6. Require horizontal overlap between candidate levels.
7. Merge near-duplicate levels.
8. Discard weak or isolated candidates.
9. Limit the final number of useful floor bands.

Outdoor hills and slopes ordinarily produce one continuous terrain set rather than many checkbox floors.

### Checkbox semantics

- No checked boxes: show all floors.
- One checked box: show only that vertical band.
- Multiple checked boxes: show the union of those bands.

The filter is applied through an optional material shader only when at least one floor is selected. Ordinary rendering leaves stock Three.js material shaders untouched.

### Manual cut

The **Cut above** slider provides a separate manual world-height clip for cases where automatic floor detection is not sufficient.

---

## 20. Texture handling

The parser can return BMP, DDS, PNG, and JPEG data. The viewer indexes textures by normalized filename and material aliases.

Important texture rules:

- Preserve the generated glTF texture's `wrapS`, `wrapT`, repeat, offset, rotation, and filtering.
- Use repeat wrapping for tiled legacy UVs.
- Avoid clamping because UV coordinates outside `0..1` are common and clamping stretches edge pixels across large surfaces.
- Dispose old textures when changing zones.
- Reuse texture promises so one image is not decoded repeatedly.

A texture failure should not prevent geometry from rendering; affected materials fall back to an untextured appearance.

---

## 21. Performance architecture

### Why draw calls matter

A zone with 500,000 triangles can render smoothly when it uses a few hundred draws, while a zone with fewer triangles can perform poorly with tens of thousands of separate meshes/material passes.

Texture tiling does not multiply draw calls. Separate render objects and material passes do.

### Terrain merging

Compatible terrain is physically merged into spatial/material batches. This guarantees real draw-call reduction across Firefox and Chromium.

### Prop instancing

Repeated static props use `THREE.InstancedMesh`. Instance matrices preserve translation, rotation, and scale without cloning geometry and materials for every placement.

Large instance sets are divided into spatial chunks so first-person distance/frustum culling remains useful.

### Material reuse

Equivalent materials are deduplicated. Transparent double-sided foliage uses a single pass when possible.

### First-person fog and culling

Fog alone is cosmetic. The implementation also toggles render-batch visibility by distance from the first-person camera.

The environment updater tracks:

- visible render batches;
- distance-culled batches;
- current view distance;
- fog near/far distances.

Top Down intentionally shows the complete zone. Distance culling is a First Person optimization.

### Fixed quality target

The current design uses a fixed High visual profile and targets 60 FPS. The renderer limits pixel ratio to the configured High cap rather than silently changing quality modes.

### On-demand rendering

Top Down renders primarily when the camera or UI changes. First Person renders continuously while active. The benchmark forces continuous rendering for five seconds so the measured FPS is meaningful.

### Performance diagnostics

The clickable HUD opens a panel containing live data from `WebGLRenderer.info` and application counters:

- measured FPS;
- average frame interval;
- render-call duration;
- rendered draw calls;
- rendered triangles;
- render meshes;
- source mesh instances;
- terrain batches;
- prop batches;
- placed props;
- unresolved placements;
- GPU geometries;
- GPU textures;
- current resolution and pixel ratio;
- object models loaded;
- unmatched model names;
- visible and culled batches;
- first-person view distance.

The diagnostic summary distinguishes draw-call-bound, geometry-bound, CPU/update-bound, and driver/render-bound situations.

---

## 22. Coordinate HUD and picking

### Top Down

Clicking zone geometry raycasts against precomputed pick targets and records the native EQ coordinate.

### First Person

The HUD updates continuously from the character/ground position rather than remaining at the last clicked point or using eye height as ground Z.

### Pick-target optimization

The application builds the coordinate-picking target list once after scene construction rather than traversing the complete scene on every click.

---

## 23. Parsed-zone caching

The viewer uses IndexedDB database:

```text
EQLZoneViewer
```

with an object store named:

```text
zones
```

### Cache key

The key includes:

- an internal cache-format version;
- zone identifier and format;
- relevant source filenames;
- source file sizes;
- source modification timestamps.

Changing archives therefore generates a different signature.

### Invalidation

The viewer clears parsed-zone cache when:

- a different directory is selected;
- a different zone is loaded;
- the user clicks **Clear Cache**.

The internal format version is incremented when parser output or scene assumptions become incompatible.

### Large payload protection

Parsed payloads above 128 MB are not written to IndexedDB. Storing a very large object can duplicate ArrayBuffers temporarily and cause severe memory spikes after parsing.

Smaller zones are displayed before the asynchronous cache write begins.

### What is cached

The cache contains generated parser output, not the raw complete installation. It may contain generated GLB buffers, referenced object GLBs, selected texture data, metadata, and parser stats for the selected zone.

---

## 24. Controls reference

### Global

```text
1              Top Down
2 or 3         First Person, depending on current shortcut mapping
R              Reset
M              Cycle Full map → Minimap → Closed
G              Toggle Grounded / Fly
H              Toggle Controls panel
Esc            Release pointer lock / close transient interaction
```

The application also displays context-sensitive controls at the bottom-right. The exact numeric view shortcuts should be kept synchronized with the source whenever the toolbar changes.

### Top Down

```text
Left drag      Rotate
Right drag     Pan
Mouse wheel    Zoom
R              Exact overhead reset
```

### First Person — Grounded

```text
W A S D        Move
Shift          Sprint
Space          Jump
G              Enter Fly mode
Esc            Release mouse
```

### First Person — Fly

```text
W A S D        Move along view direction
Shift          Sprint
Space / E      Rise
Q / C          Descend
G              Return to Grounded mode
Esc            Release mouse
```

---

## 25. CSS and overlay architecture

The application uses one responsive flex-column container:

```text
primary toolbar
secondary controls toolbar
relative-positioned viewport
status/progress row
```

The viewport layers are ordered approximately as:

1. WebGL canvas;
2. map-label canvas;
3. floating named-mob DOM labels;
4. zone badge;
5. loading/error overlay;
6. controls and performance panels;
7. first-person prompt;
8. HUD.

Named labels are DOM elements rather than Three.js sprites so they remain readable, clickable, keyboard-focusable, and above terrain in Top Down.

The styles use fixed dark interface colors because the viewer is a self-contained visualization surface. Wiki page chrome remains controlled by the active MediaWiki skin.

---

## 26. Error handling

The application handles:

- directory-picker cancellation;
- unreadable files;
- invalid PFS headers;
- unsupported archive revisions;
- missing generated geometry;
- failed object GLB parsing;
- missing textures;
- worker crashes;
- IndexedDB failures;
- pointer-lock denial;
- absent matching map files.

Fatal zone-load errors are shown in the viewport overlay and logged to the browser console with an `[EQLZoneViewer]` prefix where appropriate.

Object or texture failures are generally nonfatal: the viewer continues with the usable geometry and exposes unresolved counts in diagnostics.

---

## 27. Building the browser bundles

The checked-in production bundles are built with pinned packages:

```text
sage-core  0.0.38
three      0.180.0
esbuild    0.25.0
```

On a development machine:

```bash
cd build
npm install
./rebuild.sh
```

The rebuild script:

1. installs dependencies when needed;
2. copies the included `sage-core` overrides into `node_modules`;
3. bundles `src/viewer.js` as an ES module;
4. bundles `src/parser-worker.js` as a worker-compatible IIFE;
5. minifies both bundles;
6. writes them to `resources/dist/`.

The worker build marks Node filesystem modules as external because the browser path uses the custom memory-file layer.

After source changes, update all version references consistently:

- `VERSION`;
- `extension.json`;
- `ViewerMarkup.php` module and worker query strings;
- `build/package.json` and the root package entry in `package-lock.json`;
- `CHANGELOG.md`;
- README release number.

---

## 28. Recreating the solution from scratch

The following sequence is the minimum practical blueprint for rebuilding a comparable viewer.

### Phase A: MediaWiki shell

1. Create a MediaWiki extension with `extension.json`.
2. Register a special page.
3. Register a `<zoneviewer>` parser tag.
4. Emit a root element containing JSON configuration.
5. Load a small ResourceLoader bootstrap.
6. Dynamically import the large ESM application bundle.

### Phase B: local directory abstraction

1. Implement a source class with two selection paths:
   - `showDirectoryPicker()`;
   - `webkitdirectory` input fallback.
2. Recursively index file handles or `File` objects.
3. Normalize relative paths and filenames.
4. Build maps by filename, path, extension, and directory depth.
5. Implement file lookup and related-zone resolution.
6. Build a source signature from names, sizes, and timestamps.

### Phase C: zone discovery

1. Collect `.s3d` and `.eqg` candidates.
2. Apply conservative filename exclusions.
3. Inspect archive directories for recognizable zone data.
4. Create grouped `<optgroup>` entries for known and unknown zones.
5. Add a friendly-name dictionary independent of archive recognition.

### Phase D: parser worker

1. Bundle or write S3D/PFS/WLD and EQG decoders.
2. Adapt them to `File`/Blob inputs.
3. Perform decompression and export in a Web Worker.
4. Replace disk output with an in-memory file system.
5. Generate one zone GLB, referenced object GLBs, textures, and placement metadata.
6. Resolve object aliases carefully, preserving numbered variants.
7. Post transferable ArrayBuffers back to the page.
8. Terminate the worker after each parse.

### Phase E: canonical coordinates

1. Choose one EQ-to-render transform.
2. Implement forward and inverse functions.
3. Use them everywhere, including maps and labels.
4. Validate with a known dock, zone line, and coordinate point before adding features.

### Phase F: renderer

1. Create a Three.js renderer, scene, perspective camera, and lighting.
2. Load generated GLB buffers with `GLTFLoader`.
3. Preserve texture UV transforms and wrapping.
4. Traverse zone meshes and create static batch records.
5. Merge compatible terrain geometry by material and spatial chunk.
6. Build an alias map for object templates.
7. Apply static animation poses before baking skinned props.
8. Instance repeated props in spatial chunks.
9. Keep fallback meshes for unsupported animated/nonstatic cases.
10. Build one-time pick and collision target lists.

### Phase G: views and movement

1. Use unrestricted trackball controls for the top-down/inspection view.
2. Implement a deterministic overhead reset.
3. Use pointer lock for First Person.
4. Track grounded/flying states.
5. Add horizontal velocity, sprinting, gravity, jumping, and pitch-following flight.
6. Cast downward for ground following.
7. Build double-sided wall proxies and swept multi-height wall probes.
8. Slide movement along wall normals.

### Phase H: maps and labels

1. Find the matching `maps/<zone>*.txt` family recursively.
2. Parse `L` and `P` records, retaining commas in labels.
3. Suppress unwanted credit labels during parsing.
4. Convert all positions through the canonical transform.
5. Render map lines with vertex colors.
6. Project point labels through the active camera.
7. Classify named/Hunter/NPC points.
8. Create DOM anchors with safe new-tab links.
9. Apply permissive Top Down visibility and selective First Person culling.
10. Build path-to-location search entries, destination beams, and collision-aware grounded route guidance.

### Phase I: floors and clipping

1. Sample upward-facing zone triangles.
2. Build horizontal spatial bins.
3. Find recurring vertically separated surface clusters.
4. Require meaningful horizontal overlap.
5. Create checkbox bands only for real occluding levels.
6. Apply an optional shader-based vertical-band filter only when selected.
7. Add a separate manual cut-above control.

### Phase J: optimization and diagnostics

1. Render Top Down on demand.
2. Render First Person continuously.
3. Use instancing and physical merging, not just visual fog.
4. Divide batches spatially.
5. Hide distant batches in First Person.
6. Expose live `renderer.info` metrics.
7. Add a forced-render benchmark.
8. Cache parsed output in IndexedDB with a versioned source signature.
9. Skip oversized cache entries.
10. Dispose geometry, materials, textures, controls, and workers when changing zones.

---

## 29. Release validation checklist

Before publishing a release:

### PHP and metadata

- Run `php -l` on every PHP file.
- Parse every JSON file strictly.
- Confirm `extension.json` version and requirements.
- Confirm `ViewerMarkup.php` versioned URLs.

### JavaScript

- Run syntax checks on unminified source.
- Rebuild both bundles.
- Import or parse the production module in a test environment.
- Initialize the worker and test a controlled error path.
- Search authored source for unintended network/upload APIs.

### Functional smoke tests

- Select a directory in Chromium.
- Select a directory in Firefox.
- Load a small S3D zone.
- Load a large outdoor S3D zone.
- Load at least one supported EQG zone.
- Verify props and numbered foliage.
- Verify texture wrapping.
- Verify Top Down reset and unrestricted rotation.
- Verify First Person ground lock, jump, flight, and wall collision.
- Verify native coordinate readout.
- Verify `M` map alignment.
- Verify floor checkboxes.
- Verify named labels and new-tab links.
- Verify Brewall labels do not appear or enter autocomplete.
- Verify Path to location.
- Run the performance benchmark.
- Change zones and confirm old geometry/cache state does not leak.

### Packaging

- Exclude `.git`, temporary test output, and `node_modules` from the release ZIP.
- Include production bundles, unminified source, lockfile, overrides, licenses, and documentation.
- Extract the ZIP to a clean directory and repeat syntax checks.
- Generate a SHA-256 checksum.

---

## 30. Known limitations

- EQG support depends on the revisions understood by the bundled decoder and is less broadly validated than classic S3D/WLD.
- Very large zones can temporarily use substantial memory during source decoding, GLB generation, texture conversion, and scene batching.
- The viewer is not a complete EverQuest client and does not reproduce dynamic doors, particles, NPC animations, scripted zone state, or server spawn logic.
- Named labels come from the user's local map pack. Their accuracy, completeness, colors, and wiki-title compatibility depend on that pack.
- Black map labels are heuristically interpreted as possible NPCs after navigation/annotation filtering; some unusual annotation may still look like an NPC.
- Floor inference is intentionally conservative and may omit a marginal mezzanine rather than invent many false floors.
- Collision is a custom viewer approximation, not the original client collision engine.
- Browser storage may evict IndexedDB entries under storage pressure.
- Firefox generally requires reselecting the folder after a reload.

---

## 31. Licensing

- MediaWiki extension code: GPL-2.0-or-later
- Modified `sage-core` portions: MIT
- Three.js: MIT

See:

```text
LICENSE
THIRD_PARTY_NOTICES.md
LICENSES/sage-core-MIT.txt
LICENSES/three-MIT.txt
```

Do not remove third-party notices when redistributing the extension.
