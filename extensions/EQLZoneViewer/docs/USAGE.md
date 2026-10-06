# User guide

1. Click **Select EverQuest Folder**.
2. Approve read access or choose the folder through the fallback picker.
3. Choose a zone and click **Load Zone**, or click **World Map** to browse the connected atlas visually.

Firefox may call the permission action **Upload Folder**. The viewer only receives permission to read supported files locally. It does not upload the folder or game archives to EQL Wiki.

Changing to a different zone clears the previous parsed-zone cache before the new zone is loaded. The selected zone may then be cached for a faster reload.


## Connected World Map

After directory indexing, click **World Map** instead of choosing a dropdown entry. The first open reads recognized base and `_1` files from the local `maps` directory, builds zone previews and connections, and stores the result in the browser cache. Subsequent opens reuse the cached graph until a relevant zone archive or map file has different size or modification metadata.

- Drag: pan.
- Wheel: zoom around the pointer.
- Click a zone preview: load that zone.
- `R`: fit all world-map zones.
- `Esc` or **World Map**: close the atlas.

Solid connections are ordinary zone lines. Blue dashes represent sea, boat, or translocator routes. Purple dashes represent portals, click transports, or planar teleports. The layout is a navigable connection schematic; it does not imply that unrelated zone coordinate systems can be placed in one physically continuous game world.

The previews use local map lines rather than full 3D geometry. This is intentional: rendering every complete S3D/EQG zone at once would consume excessive memory and GPU resources, while map snapshots preserve recognizable silhouettes and allow instant click-through to a full zone.

## Zone list

The selector has two groups:

- **Known EverQuest zones:** mapped to their friendly in-game names.
- **Other playable zone archives:** valid S3D/EQG zone archives without a friendly-name mapping.

Object, character, equipment, texture, and other non-zone archives are not listed.

## Top Down

Top Down is the default manipulable third-person view.

- Left-drag: freely rotate and roll, including beneath the zone.
- Right-drag: pan.
- Mouse wheel: zoom.
- Click geometry: record native EQ coordinates.
- `R`: reset to a perfectly overhead view.
- `M`: cycle Closed → Full map → bottom-right Minimap → Closed.

## First Person

- Click the canvas to capture the mouse.
- `W`, `A`, `S`, `D`: move.
- `Shift`: sprint.
- `Space`: jump roughly 10 EQ feet while grounded.
- Grounded mode is enabled by default and follows the surface below the player.
- `G`: toggle Grounded/Fly.
- In Fly mode, forward/back movement follows full mouse pitch.
- `Space` or `E`: rise while flying.
- `Q` or `C`: descend while flying.
- `Esc`: release the mouse.

Ctrl is not used by the viewer. Ctrl/Alt/Command combinations are ignored as movement input.

The coordinate HUD reports the character position and updates continuously while walking, jumping, or flying.

## Local game map

Press `M` to cycle the map states for the loaded zone. There is intentionally no separate toolbar button. The first press opens the full map, the second returns to the rendered zone with a player-centered minimap at bottom right, and the third closes the minimap. The full map uses the same Top Down camera and does not reset pan, rotation, or zoom when opened or closed.

The minimap excludes the `_2` coordinate/grid layer, draws local map geometry around the player, and includes the same glowing red position/facing arrow.

The viewer searches recursively beneath:

    <Selected EverQuest Folder>/maps

It selects the most complete matching family, including files such as:

    zone.txt
    zone_1.txt
    zone_2.txt
    zone_3.txt

Standard `L` line and `P` point records are supported. Line and label colors are retained. The `_2` coordinate/grid layer can appear in the explicit map view, but it is not used to infer floors and never appears as an artificial grid under the 3D zone.

## Named/NPC labels

When the map pack contains named, Hunter, or plausible black NPC markers, the viewer shows floating labels over the 3D zone.

- Click a label in Top Down to open the corresponding EQL Wiki page in a new tab.
- In First Person, center a label in the crosshair and click to open it in a new tab.
- Explicit Named/Hunter labels are retained preferentially when labels overlap.
- Use **Display → Named mob labels** to hide or show them.

Navigation labels, succor markers, tips, ground spawns, and similar annotations are excluded from floating named-mob links. Trailing map metadata such as `(Hunter,Roam,HS)` is removed from the wiki page link.
Map point labels that explicitly mention Brewall are suppressed entirely, including map rendering, floating labels, links, and Path-to-location autocomplete. The source map files are not modified.

## Path to location

The **Path to location** box autocompletes against every loaded map point label, not only named-mob links. Enter a full or partial label and press Enter or **Go to**. The viewer selects the nearest matching duplicate, captures the current position as the route origin, switches to First Person without teleporting the camera, paints a vertical gold beam at the exact destination, and attempts to paint a grounded golden route from that captured request position. You may keep moving while it calculates; movement does not restart or rebase the route. The route uses loaded collision geometry, honors the 10-unit jump limit, and rejects wall-blocked segments. Some doors, lifts, water routes, and scripted transitions may still require manual navigation.

## Floors and height cutting

Floor options are checkboxes:

- No floors checked: show all geometry.
- One floor checked: show only that floor's vertical band.
- Multiple floors checked: show the union of those floor bands.

Floor inference does not use map-pack drawing bands. It analyzes upward-facing zone geometry and only creates floor choices when substantial walkable surfaces overlap in X/Y at different Z elevations—where one floor can actually hide another. Hills, wall traces, disconnected platforms at the same level, and ordinary map-grid layers do not become separate floors.

Use **Cut above** for manual height clipping.


## Responsive pathfinding

Path calculations run in short cooperative time slices. The status bar shows progress and exposes a **Cancel path** button while a route is being built. Large or unusually complex zones are bounded by a fixed search-time and node budget; the destination beam remains available even when no route is found.

## Background path calculation and point-of-interest pillars

Selecting an exact entry in **Path to location** starts route calculation immediately. The expensive map-graph search runs in `navigation.worker.js`, so First Person movement, camera control, and rendering remain available while the progress bar advances. The final short projection pass checks the worker route against loaded ground and wall collision, including the 10-unit jump limit. **Cancel path** stops the active request without removing the destination beam.

Every searchable map point receives a slim golden pillar. Named NPC labels remain the only labels shown persistently, but hovering, clicking/tapping, or aiming at a pillar while pointer lock is active shows the complete point-of-interest label, including non-NPC annotations. Use **Display → Location beams** to hide all slim pillars and the selected destination beam; an already-built golden ground path remains visible.
