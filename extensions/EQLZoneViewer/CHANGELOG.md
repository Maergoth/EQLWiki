# Changelog

## 1.15.0

- Fixes a regression in the relaxed background route pass where smoothing ignored penalized wall cells and collapsed valid detours back into straight wall-crossing segments.
- Filters decorative map layer 2 and vertically unrelated map lines out of route rasterization, then grounds the returned route at short intervals with local collision-safe detours instead of rejecting an entire path after one coarse segment.
- Adds a **Location beams** checkbox under **Display** that hides all ordinary point-of-interest pillars and the selected destination beam without hiding an already-built golden path.
- Adds click/tap pinning for point-of-interest pillar labels and expands hit testing to the complete pillar height; hovering, clicking, and center-reticle targeting now use the same nearest-visible selection logic.
- Adds a conservative prop-alignment diagnostic that automatically corrects a globally mirrored prop set only when the alternate placement is decisively better contained by the loaded zone geometry, addressing the displaced Karnor's Castle objects without hard-coding a zone offset.
- Advances parsed-zone cache metadata to `v13` and asset cache-busting metadata to 1.15.0.

## 1.14.1

- Anchored each path request to an immutable snapshot of the player position at the moment the destination is selected.
- Removed route rebasing and nearest-waypoint trimming during the final projection pass. Walking or teleporting while calculation runs no longer changes or restarts the route.
- Clarified in-progress status and help text that movement remains interactive but does not alter the submitted route.

## 1.14.0

- Moved the expensive route graph search into a dedicated background Web Worker so First Person movement and rendering remain interactive during path calculation.
- Increased the pathfinding safety budget from 8 seconds to 45 seconds and retained a tightly bounded cooperative collision fallback.
- Rebased completed routes onto the player’s current position when the player moves during calculation.
- Added hover identification for every slim golden point-of-interest pillar, including non-NPC map labels.
- Allowed world-map zone-name plaques to extend beyond card boundaries without clipping or ellipsis.
- Expanded world-map hit testing so the escaped name plaques remain clickable.

## 1.13.0

- Reworked navigation pathfinding into cooperative 5 ms time slices so rendering, controls, and UI interactions continue during calculation.
- Added a determinate progress bar and an explicit **Cancel path** control.
- Added hard state, grid-size, and elapsed-time budgets to prevent runaway searches on very large zones.
- Added fast collision-validated projected-route attempts before A*.
- Added X/Z spatial indexes for terrain and wall collider candidate lookup.
- Reduced repeated wall tests with per-search segment caching and limited multi-level surface branching.
- Prevented duplicate path builds when the selected destination is unchanged.

## 1.12.0

- Fixes the stopped navigation coroutine that previously rendered the destination beam and then failed before path construction or teleportation. Selecting an exact entry in **Path to location** now immediately builds the highlighted destination beam and golden route; the **Go to** button and Enter key additionally teleport the First Person camera to the selected location.
- Adds an instanced slim golden pillar at every searchable local-map location while keeping the selected target's existing bold beam and ground ring.
- Improves grounded route discovery with exact start/end sampling, three adaptive A* passes, and a collision- and jump-validated projected fallback.
- Separates persistent location pillars from the selected route group so rebuilding a path no longer removes ordinary markers.
- Moves the Timorous Deep firepot panel into dedicated open water directly beneath Timorous Deep, eliminating overlap with Odus cards.
- Replaces excessive global detours with short obstacle-aware orthogonal routes. Routes still avoid unrelated cards, do not share exact lanes, and use cartographic bridges for genuine crossings.
- Advances the zone cache to `v12`, World Map cache to `world-v10`, and asset cache-busting metadata to 1.12.0.

## 1.11.0

- Rebuilds the atlas on a wider hand-authored artboard inspired by the official world geography: Faydwer and Kunark move farther east, Velious sits southwest, and continent clusters have substantially more breathing room.
- Omits Tutorial Zone and its South Qeynos exit from the World Map while retaining the source evidence in the connection inventory.
- Keeps all 146 normally drawn routes out of unrelated zone cards and shared lanes. The one remaining graph crossing receives a visible cartographic bridge arc, so crossing lines cannot be mistaken for a zone connection.
- Increases in-card zone-name prominence and retains clickable full cards, clickable zone names, and clickable Timorous Deep firepot destinations.
- Renames **Go to NPC** to **Path to location** and **Go** to **Go to**.
- Adds a vertical additive gold destination beam beneath the selected map label.
- Adds best-effort sparse A* ground routing over loaded collision surfaces, using the same 10-unit jump ceiling and wall-sweep collision checks as grounded First Person, then paints the route as a glowing golden ribbon with directional chevrons.
- Extends the low-cost smoky-glass and antique-gold visual treatment across the viewer controls and navigation label.
- Advances the World Map cache format to `world-v9` and browser/extension cache-busting metadata to 1.11.0.

## 1.10.0

- Replaces midpoint orthogonal lines and free curves with deterministic obstacle-aware routing. Every validated connection now routes around every unrelated zone card, preventing a real Skyfire Mountains ↔ The Overthere line from visually appearing to connect Skyfire Mountains to Temple of Droga.
- Validates the complete 158-edge audited graph against the fixed atlas geometry; the release routing test reports zero connection segments crossing unrelated zone cards.
- Makes every Timorous Deep firepot destination name an individually clickable control that loads the destination zone and provides a dedicated one-way portal tooltip.
- Retains click-to-load behavior over each complete zone card and gives zone names explicit gold hover treatment so their interactivity is visually apparent.
- Restyles the atlas with smoky dark glass, antique-gold borders, parchment route lines, teal continent headings, and matching gold tooltips without adding costly backdrop filtering.
- Adds known-zone archive fallbacks when the archive scanner rejects an otherwise recognized archive that has a matching local map family. This keeps zones such as Eastern Wastes present and loadable from installations whose archive directory metadata is atypical.
- Advances the World Map cache format to `world-v6` and browser/extension cache-busting metadata to 1.10.0.

## 1.9.0

- Rebuilds the World Map connection graph from an audited inventory of the uploaded base and `_1` map files; the complete 158-pair evidence list is included in `docs/WORLD_CONNECTIONS.txt`.
- Removes substring/containment guessing from connection resolution. Destinations must now match the exact zone/alias table, preventing expansion labels such as `East Wastes: Zeixshi-Kar's Awakening` from becoming false classic-zone connections.
- Splits validated multi-destination labels such as `to Erudin & South Qeynos` and `to East Freeport & The Butcherblock Mountains` into separate routes.
- Corrects planar access: Plane of Hate now connects from Oasis of Marr, and Plane of Sky now connects from East Freeport. The incorrect Neriak Third Gate and Greater Faydark planar routes are removed.
- Uses `oceanoftears` and `newsebexp` map-family aliases so the atlas reads their actual navigation labels and previews.
- Preserves source/target evidence for every edge and draws arrowheads only for explicitly validated one-way firepots, portals, and exits; a one-sided map annotation no longer automatically implies a one-way game mechanic.
- Adds directional connection summaries to zone hover tooltips and a one-way route key to the World Map legend.
- Bundles the eleven Timorous Deep firepot destinations into a compact reference-style inset instead of drawing eleven continent-crossing portal curves; the underlying route metadata remains available in tooltips and the audited connection inventory.
- Advances the World Map cache format to `world-v5` and browser/extension cache-busting metadata to 1.9.0.

## 1.8.2

- Corrects New Sebilis Expedition's world-map placement: it now belongs to Antonica beside West Commonlands rather than Kunark.
- Replaces the incorrect New Sebilis-to-Old Sebilis portal edge with a normal zone connection to West Commonlands.
- Adds `New Sebilis` as an explicit connection-label alias and advances the World Map cache format to `world-v4` so cached 1.8.1 positions and edges are discarded.
- Updates browser/extension cache-busting metadata to 1.8.2.

## 1.8.1

- Makes the World Map a rigid reference-map artboard: canonical zone slots are now used verbatim with no force, spring, or all-node collision pass capable of moving continents or route rows.
- Compacts the artboard into the familiar classic structure: Odus at middle-left, Antonica above center, Faydwer upper-right, Kunark lower-right, and Velious below Antonica, with planes and ocean travel pinned into the corresponding gaps.
- Gives every zone a uniform card footprint so a tall or wide local map silhouette cannot change the atlas geometry.
- Moves zone names into a two-line footer inside each card, eliminating the external-label collisions that made the fitted map look like an unstructured horizontal mass.
- Places New Sebilis Expedition near Old Sebilis in the 1.8.1 schematic; this region assignment was corrected in 1.8.2.
- Strengthens continent frames and headings for clearer region separation at the default fit-to-screen zoom.
- Advances the World Map cache format to `world-v3` and browser/extension cache-busting metadata to 1.8.1 so the displaced 1.8.0 arrangement cannot be restored from cache.

## 1.8.0

- Replaces the force-directed World Map arrangement with a canonical, hand-authored schematic matching the familiar classic world layout.
- Positions Odus at left, Antonica in the center, Faydwer upper-right, Kunark lower-right, and Velious below Antonica, while placing transport hubs and planes at their logical interstitial locations.
- Adds subtle continent frames and headings so region structure remains clear at fit-to-screen zoom.
- Routes ordinary zone connections orthogonally for cleaner, map-like corridors while retaining curved dashed styling for boats, portals, and teleports.
- Uses bounded collision relaxation only for local card-size conflicts, preventing wide zone previews from overlapping without allowing the geographic hierarchy to drift.
- Places New Sebilis Expedition near Old Sebilis in the 1.8.0 schematic; this region assignment was corrected in 1.8.2.
- Advances the World Map cache format to `world-v2` and browser/extension cache-busting metadata to 1.8.0 so previously cached force-directed positions cannot be reused.

## 1.7.0

- Adds a connected **World Map** button as an alternative to the zone dropdown.
- Generates a top-down preview for every recognized/loadable zone from local base and `_1` map files without loading every 3D archive.
- Parses `to ...` map labels and resolves friendly names, archive IDs, city sections, classic aliases, and source-specific ambiguous destinations into a unified connection graph.
- Adds explicit schematic routes for boats, translocators, planes, the tutorial, and New Sebilis Expedition where local map packs do not provide a consistent direct edge.
- Separates zones into Odus, Antonica, Faydwer, Kunark, Velious, and Planes clusters and applies deterministic spring layout plus rectangle de-overlap.
- Draws ordinary zone lines as solid links, water/boat routes as blue dashed curves, and portal/teleport routes as purple dashed curves.
- Supports drag-to-pan, cursor-centered wheel zoom, `R` to fit, `Esc` to close, hover details, and click-to-load for every zone snapshot.
- Caches the generated graph in IndexedDB using relevant map/archive filename, size, and modification metadata, while keeping world and parsed-zone cache keys separate.
- Adds map filename aliases such as `kerra` → `kerraridge` and canonical world-node aliases for classic/revamped archive pairs.
- Advances parsed-zone cache format to v11 and browser/extension cache-busting metadata to 1.7.0.

## 1.6.6

- Preserves Top Down camera position, rotation, pan, and zoom when opening and closing the full local map.
- Changes the `M` hotkey cycle to Closed → Full map → Minimap → Closed.
- Adds a bottom-right, player-centered minimap with local map geometry and the glowing facing arrow.
- Fits the initial Top Down camera to terrain/map content rather than distant prop placements or inflated scene bounds.
- Reduces the default Top Down framing margin for a tighter viewport fit.
- Restores the previous Top Down framing when returning from First Person.

## 1.6.5

- Fixes incomplete S3D skeletal props procedurally by exporting every bone-attached mesh fragment into one object GLB instead of allowing later fragments to overwrite earlier ones. This corrects detached or missing trunks/canopies in Emerald Jungle, Trakanon's Teeth, and other foliage-heavy legacy zones without per-zone offsets.
- Adds a glowing red player arrow to the local map. It marks the most recent First Person ground position and rotates to show the viewer's horizontal facing direction.
- Bumps parsed-zone cache format to v10 so zones parsed with incomplete skeletal object models are not reused.
- Updates browser bundle cache-busting and extension metadata to 1.6.5.

## 1.6.4

- Suppresses any local-map point label that explicitly mentions Brewall, including possessive forms, before map drawing, floating-label construction, wiki-link construction, or Go-to-NPC autocomplete.
- Replaces the short project README with a complete architecture, feature, privacy, parsing, rendering, collision, maps, labels, floors, caching, build, deployment, recreation, testing, and release guide.
- Synchronizes extension and browser cache-busting version metadata at 1.6.4.

## 1.6.3

- Keeps every on-screen named-mob label visible in Top Down, independent of overlap, distance, floor selection, or terrain.
- Retains First Person distance and overlap culling while forcing nearby labels to remain visible.

## 1.6.2

- Fixes first-person wall tunneling caused by testing only the most recent animation frame even though wall collision runs at 30 Hz. Movement is now swept from the last position that was actually collision-tested.
- Uses non-rendered, double-sided wall-collision proxies so legacy S3D triangle winding cannot make a wall passable from one direction.
- Approximates the player as a body volume with multiple low, center, shoulder, and eye-height probes instead of a single ray from the camera point.
- Adds broad-phase bounding-box rejection so the extra body probes raycast only against nearby terrain batches.
- Separates vertical ground handling from horizontal wall handling, ignores walkable floor/slope normals during wall tests, and slides remaining movement along the contacted wall instead of snapping the player entirely backward.

## 1.6.1

- Fixes terrain disappearing after the 1.6.0 floor-checkbox release. The floor filter no longer patches every material during ordinary rendering, and its optional shader now computes world height safely for regular, merged, and instanced meshes.
- Aligns the local game map to the exact Top Down camera instead of reframing it with a separate orthographic camera. Toggling `M` now swaps map and terrain at the same center, zoom, and orientation.
- Projects local map labels through the active Top Down camera so labels and map lines remain registered while panning and zooming.

## 1.6.0

- Opens floating named-mob wiki links in a new tab in both Top Down and First Person.
- Replaces constrained orbiting with unrestricted trackball rotation in Top Down, including full underside access and camera roll; Reset still returns to exact overhead.
- Applies the static `pos` animation frame before baking skinned S3D props, fixing detached Trakanon tree canopies while preserving instancing.
- Adds a grounded Spacebar jump with an approximately 10-EQ-foot apex. In Fly mode, Space retains its vertical-rise behavior.
- Replaces the single floor selector with multi-select checkboxes. No checked floors means All floors; one or more checked floors render only the selected vertical bands.
- Replaces map-pack Z-band floor inference with occlusion-based geometry analysis: a floor is offered only when substantial walkable surfaces overlap horizontally at distinct heights. Ordinary cartographer trace heights no longer become floors.
- Makes explicit Named/Hunter labels higher priority in Top Down and removes the previous hard label-count cap.
- Adds **Go to NPC** autocomplete over every loaded map point label. The closest exact/prefix/substring match switches to First Person at that location.
- Bumps parsed-zone cache format to v9.

## 1.5.0

- Replaces the separate 2D/Orbit choices with **Top Down** and **First Person**. Top Down is now the manipulable third-person 3D camera, while Reset returns it to an exact overhead view.
- Adds adaptive first-person fog and distance culling, including live view-distance and culled-batch diagnostics.
- Bakes static skinned foliage into instancable geometry, preserves numbered tree/plant variants, and uses coarser batching for extremely prop-dense outdoor zones. The supplied Trakanon archives resolve all 67 object models and 17,678 placements and batch to roughly 462 terrain/prop draws before distance culling.
- Stops loading zone character archives for static viewing; the main and `_obj` archives provide zone geometry and placed props.
- Updates the bottom-left coordinates continuously while walking or flying.
- Uses the validated native EQ-to-renderer transform `[EQ Y, EQ Z, -EQ X]` for map geometry, labels, coordinate readouts, and first-person positions, correcting mirrored local maps.
- Adds clickable floating named/Hunter and plausible black NPC labels from local map files, with overlap suppression, a Display toggle, and first-person crosshair targeting.
- Removes the visible Game Map and Filter Zones controls. `M` remains a documented HUD hotkey.
- Shows friendly zone names and separates recognized zones from other playable S3D/EQG archives without hiding unknown zone archives; fixes candidate filtering so Skyfire and Skyshrine are not mistaken for shared sky assets.
- Adds a clearer Firefox folder-permission explanation.
- Chooses first-person spawn points from a valid ground surface near EQ 0,0,0, a local map point, or the zone center instead of an arbitrary bounding-box height.
- Reworks floor inference to use strong, separated map Z peaks and map-layer colors. When a local map indicates an outdoor or single-level zone, the viewer no longer invents floors from hills, props, or tree tops.
- Bumps parsed-zone cache format to v8.

## 1.4.0

- Locks visual quality to the High profile and targets 60 FPS.
- Removes the unexplained Three.js helper grid.
- Renames **Fit Zone** to **Reset** and changes its shortcut from F to R.
- Halves normal Walk speed; Shift sprint uses the previous normal speed.
- Makes Walk ground-locked by default and adds a visible **Grounded/Fly** toggle with the G shortcut.
- Makes flight forward/back movement follow full mouse-look pitch while retaining Space/E and Q/C world-vertical controls.
- Removes Ctrl from all movement controls and suppresses conflicting browser movement-key shortcuts while pointer lock is active.
- Indexes map files recursively under the selected installation's `maps` directory, including custom map-pack subdirectories.
- Adds an M shortcut and **Game Map** button that render local EQ map lines and labels.
- Uses local map Z geometry to create more meaningful floor presets, with mesh-derived levels as a fallback.
- Bumps parsed-zone cache format to v7.

## 1.3.0

- Replaced the remaining per-mesh static rendering path with physically merged zone geometry and spatially chunked `THREE.InstancedMesh` groups for repeated props.
- Avoids relying on `WEBGL_multi_draw`, so Firefox receives the same real draw-call reduction as Chromium browsers.
- Deduplicated equivalent materials across the complete scene and enabled single-pass rendering for transparent double-sided foliage materials.
- Fixed a strict referenced-model filter that could omit trees, shrubs, and other foliage actors.
- Resolves each placed object actor independently so a global model-count cutoff cannot silently remove models that sort late in the generated asset list.
- Removed the 30/45 FPS quality caps. All quality modes now target 60 FPS; Auto uses adaptive internal resolution to approach that target.
- Made the HUD performance readout clickable.
- Added live renderer draw-call and triangle counts, rolling FPS and frame timing, GPU resource counts, parser/model diagnostics, and a bottleneck explanation.
- Added a five-second continuous-render benchmark and an **Optimize for 60 FPS** action.
- Incremented the local parsed-zone cache format to invalidate prior scene builds.

## 1.2.0

- Instanced repeated static prop models and split large instance sets into spatial chunks, reducing draw calls while preserving first-person frustum culling.
- Reused prop geometry and materials rather than cloning GPU resources for every placement.
- Filtered parser output to object models and textures referenced by the selected zone.
- Added automatic High, Balanced, and Performance rendering profiles based on scene complexity.
- Added a manual Display → Quality selector.
- Reduced first-person collision checks to 20 Hz and limited them to principal zone geometry.
- Capped Walk-mode rendering to 60, 45, or 30 FPS according to the active quality profile.
- Skipped IndexedDB caching for parsed payloads over 128 MB and moved smaller cache writes after scene display.
- Avoided redundant ArrayBuffer copies while loading GLB files.
- Reused coordinate-picking targets and improved GPU resource disposal between zones.
- Added a HUD readout for rendered triangle count, approximate draw calls, and active quality profile.

## 1.1.0

- Identifies playable zones by inspecting archive contents instead of listing every `.s3d` and `.eqg` file.
- Excludes common object, character, equipment, mirror, and shared-asset archives from the zone selector.
- Corrects object-placement transforms across the mirrored zone/object coordinate systems used by the bundled decoder.
- Loads EQG dependencies before the selected zone archive so a dependency cannot replace the requested zone output.
- Adds a visible Controls panel, persistent per-view control hints, keyboard shortcuts, and a clearer first-person entry prompt.
- Makes top-down controls pan/zoom only and orbit controls rotate/pan/zoom consistently.
- Clears parsed-zone cache automatically when a new directory is selected or a different zone is loaded.
- Increments the parsed-data cache format to invalidate incompatible older results.

## 1.0.1

- Preserves glTF texture wrapping and UV transforms when replacing placeholder textures with locally decoded textures.

## 1.0.0

- Initial local-file MediaWiki viewer release.
