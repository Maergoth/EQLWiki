# Connected World Map

The connected World Map is a lightweight, runtime-generated atlas built from the visitor's local EverQuest map files. It is an alternative to the zone dropdown; it does not replace the full 3D zone renderer.

## Why it uses map snapshots

Loading every S3D/EQG zone into one WebGL scene would consume several gigabytes of decoded geometry and textures and would perform poorly even on high-end systems. The world atlas instead renders each zone's base map-line silhouette into a small cached preview. This makes all zones appear together while retaining one-click access to the complete 3D zone.

## Input files

For each recognized/loadable zone, the atlas locates the best map family under the selected installation's `maps` directory:

- `zone.txt`: base geometry
- `zone_1.txt`: labels, zone lines, NPCs, and annotations
- `zone_2.txt`: coordinate/grid layer; deliberately excluded from world snapshots and connection discovery

Known archive/map filename differences are resolved through `ZONE_MAP_FILE_ALIASES`.

## Node generation

1. Recognized zone archives are canonicalized so equivalent classic/revamped IDs do not become duplicate nodes.
2. Base and `_1` map records are parsed locally.
3. Snapshot axes use the same orientation as the ordinary local-map view: screen horizontal is EQ Y and screen-down is negative EQ X.
4. Percentile bounds reject distant legends, credits, or outlier marks.
5. Dense maps are deterministically sampled to a bounded segment count.
6. Each snapshot is pre-rendered once to an offscreen canvas.
7. The visible world canvas uses `drawImage()` for each zone rather than replaying all source line records during every pan or zoom.

## Connection generation

Point labels beginning with `to ` are interpreted as connection candidates. Resolution uses:

- friendly zone names;
- archive IDs;
- normalized apostrophe, punctuation, and `The` variants;
- common map-pack abbreviations;
- city-section aliases;
- source-specific overrides for genuinely ambiguous classic destinations;
- exact alias matching only;
- explicit splitting of multi-destination labels joined by `&` or `and`.

Substring/containment guessing is deliberately disabled. This prevents later-expansion labels whose text happens to contain a classic zone name from creating false edges. Self-references, internal room labels, and destinations not present in the supported/loadable zone set are ignored.

A small manual route list handles transport that map packs do not represent consistently, including:

- Ocean of Tears boats;
- Erud's Crossing boats;
- Timorous Deep translocators;
- Iceclad/North Ro transport;
- Plane of Hate access from Oasis of Marr;
- Plane of Sky access from East Freeport;
- tutorial exit;
- New Sebilis access from West Commonlands.

## Edge styles

- Solid parchment-gray: ordinary land/dungeon zone line
- Blue dashed curve: boat, translocator, ocean, or water route
- Purple dashed curve: portal, click transport, or planar teleport
- Arrowhead: explicitly validated one-way portal, firepot, or exit

A label found on only one of two map files is retained as connection evidence, but does not automatically become a one-way route. Arrowheads are limited to routes whose direction is explicit in the map content or in a validated access rule.

## Layout

The atlas uses a canonical hand-authored schematic derived from the familiar classic world-map structure rather than a force-directed graph:

- Odus is isolated at left.
- Antonica occupies the central upper field, with the Qeynos/Karana route running west-to-east and the Freeport/Neriak/Ro routes on its eastern side.
- Faydwer sits upper-right.
- Kunark sits lower-right.
- Velious sits below Antonica.
- Ocean of Tears and planar destinations occupy logical interstitial or exterior positions rather than being forced into continent clusters.

Smoky-glass continent frames with antique-gold borders reinforce those regions. Every ordinary, water, and portal connection is routed through a deterministic Manhattan grid that treats every unrelated zone card as an obstacle. A route may bend around a card, but it cannot pass underneath it and visually appear to terminate there. The Timorous Deep firepot network is rendered as a compact destination inset, matching the reference map's separate firepot figure and avoiding eleven long portal lines across the continents. Every firepot destination name is a clickable zone control. Every zone uses a uniform card footprint at an explicit authored slot. New Sebilis is placed in Antonica as a branch from West Commonlands. The layout does not run force, spring, or collision relaxation, so local map aspect ratios cannot move continents, collapse region gaps, or reorder route rows. Zone names are rendered inside their cards to prevent fitted-view label collisions.

The layout is a connection schematic, not a physical merge of unrelated zone coordinate systems.

## Interaction

- Drag: pan
- Wheel: cursor-centered zoom
- Click any zone card or zone name: select and load its full archive
- Click a Timorous Deep firepot destination: load that destination zone
- `R`: fit the complete world atlas
- `Esc`: close World Map
- **World Map** button: open/close

Hovering a node displays its friendly name, archive format, connection count, and a directional summary of its validated neighboring routes. Firepot destination names have their own one-way portal tooltip and clickable hover state.

## Caching and invalidation

World-map data is stored in the existing IndexedDB database under a `world:` key. Parsed 3D zones use separate `zone:` keys.

The world cache fingerprint includes, for every participating zone:

- canonical and actual archive IDs;
- archive format;
- archive size and modification timestamp;
- base and `_1` map filenames;
- map sizes and modification timestamps.

Changing relevant metadata produces a different cache key and triggers regeneration. Selecting or changing zones clears parsed-zone entries without discarding a valid world atlas. **Display → Clear Cache** removes both caches.

Canvas preview objects are never written to IndexedDB. Only serializable map segments, graph edges, node positions, and metadata are cached; preview canvases are reconstructed after loading the cache.

## Validation against the supplied map pack

The 1.15.0 release retains the map-file connection audit, omits the Tutorial Zone from the world atlas, and visualizes 157 validated pairs: 146 ordinary/water/portal routes plus 11 clickable Timorous Deep firepot destinations. Automated geometry validation reports zero overlapping zone cards, zero routes intersecting unrelated cards, and zero shared route lanes. Three necessary geometric crossings are rendered with explicit cartographic bridge arcs so they cannot be mistaken for connections.

The original audit used the supplied `maps(1).zip` pack and the extension's canonical recognized zone IDs:

- 124 recognized map families located; 123 appear on the atlas because Tutorial Zone is intentionally excluded
- 542 map-label records resolved through exact aliases
- 158 validated source pairs; 157 appear in the atlas after excluding Tutorial Zone → South Qeynos
- 81 `to ...` labels rejected as internal, unknown, self-referential, or outside the supported classic/Kunark/Velious graph
- zero connection edges produced through substring guessing

The complete pair-by-pair evidence inventory, including source filenames and line numbers, is included in `docs/WORLD_CONNECTIONS.txt`.
