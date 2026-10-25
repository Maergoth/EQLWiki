# Shared zone page blueprint

The reviewed source is [`ops/zone-page-blueprint.wiki`](../ops/zone-page-blueprint.wiki),
a single `Help:Contents` subsection containing one literal `<pre>` block.
The database-backed BlueprintLoader already discovers that heading and extracts
the first pre block. Updating this source does not publish Help:Contents or
change existing zone articles. No interface-script or template change is needed.

## Observed public contracts

The October 25, 2026 read-only review found:

- [Help:Contents revision 199617](https://eqlwiki.com/index.php?title=Help:Contents&oldid=199617)
  already supplied a normal zone overview, Notable NPCs, Unique Items, a Map,
  and DynamicZoneList. It supplied no raid variant. The separate City blueprint
  remains appropriate for city-specific services.
- [Rathe Mountains revision 176917](https://eqlwiki.com/index.php?title=Rathe_Mountains&oldid=176917)
  follows that overview/map arrangement. Preserve the `zoneTopTable` class and
  the literal field labels consumed by contribution/source tools.
- [Plane of Hate revision 174286](https://eqlwiki.com/index.php?title=Plane_of_Hate&oldid=174286)
  has a reduced normal overview, two existing normal map images above it, and
  a separate final `= Raid Instance =` section with its own table and maps.
  Normalization must retain every original raid byte and existing map filename,
  caption, anchor and body section. A missing normal summary is not evidence
  that raid-only NPCs/items belong in the normal summary.
- [Nektropos Undercrofts revision 199696](https://eqlwiki.com/index.php?title=Nektropos_Undercrofts&oldid=199696)
  contains an uncertain HDE starting-area description, `/who` name `nektrunder`,
  an uploaded contribution screenshot and zone categories. It supplies no
  confirmed raid status, NPCs, drops, levels or map. Its screenshot must remain
  a screenshot rather than being relabelled as a map.
- `MediaWiki:Common.js` revision 192877 selects the exact `Raid_Instance`
  heading, hides that whole section in Normal View, and shows it in Raid View
  (`?raidview=1`). The section ends at the next heading of equal or higher
  level. Keep one final level-one Raid Instance heading, with level-two raid
  sections beneath it. No separate normal heading or duplicate page-title H1
  is needed.
- DynamicZoneList is an active legacy special page registered by
  `extensions/DynamicZoneList/DynamicZoneList.php`. The installation reviewed
  here used a fixed zone allowlist and zone/category intersections. It has
  no normal/raid filter parameter. A second identical invocation under Raid
  Instance would repeat the general list, not create a filtered raid list.
  The proposed version 0.4.4 retains legacy names and also admits canonical
  public main-namespace pages with their own active literal Category:Zones link
  and matching category membership. This makes Nektropos Undercrofts and other
  newly authored zones eligible without a per-zone code deployment once the
  admission change itself is installed. No map archive or raid capability is
  inferred from that eligibility.

## Version-one client contract

The first pre block begins with `<!-- EQL-ZONE-BLUEPRINT:1 -->`. A client must
recognize that version and instantiate the actual blueprint; it must not discard
the source and silently substitute a smaller generated table. Unsupported or
missing versions remain reviewable until the client supports them.

The optional block is bounded by separate literal comments
`<!-- EQL-ZONE-RAID:BEGIN -->` and `<!-- EQL-ZONE-RAID:END -->`. Resolve those
bounds before replacing tokens. `raidAvailability` is an explicit player choice
`yes`, `no` or `unknown`, separate from the instance of an observation. For yes,
keep the block and fill its independent fields; for no/unknown remove the entire
block. Neither a normal-only observation nor a missing cached raid record proves
that a raid does not exist. Never infer yes from the blueprint's optional block.

| Token | Candidate field / representation |
| --- | --- |
| `ERA` | Confirmed `era` as an allowed era template, otherwise `{{Era}}` |
| `ZONE_NAME` | Canonical reviewed zone title |
| `OVERVIEW` | `notes`/description with no invented facts |
| `RAID_AVAILABILITY` | Explicit Yes / No / Unknown |
| `LEVEL_RANGE`, `MIN_PLAYER_LEVEL` | `levelRange`, `minPlayerLevel` |
| `MONSTER_TYPES`, `NOTABLE_NPCS` | `monsterTypes`, explicit `notableNPCs`; never copy all known NPCs into Notable |
| `COMMON_LOOT`, `UNIQUE_ITEMS` | Independently reviewed `knownLoot`, `uniqueItems`; retain existing native count/stat validation |
| `RELATED_QUESTS`, `ADJACENT_ZONES` | `relatedQuests`, `adjacentZones` |
| `WHO_NAME`, `RESPAWN_TIME`, `SUCCOR`, `ZEM` | `whoName`, `respawnTime`, `succor`, `zem` |
| `MAP_IMAGE` | `mapFilename` rendered as one validated File link; absent file becomes a visible Map needed placeholder |
| `MAP_NOTES` | `mapNotes` |
| `DYNAMIC_ZONE_LIST` | Canonical public zone invocation (or a reviewed new page that will declare Category:Zones), otherwise a visible unavailable placeholder; appears only once outside the raid block |

Every table token is delimited `@@TOKEN@@`. Raid overrides use the same token
names prefixed `RAID_`, including `RAID_OVERVIEW`, `RAID_MAP_IMAGE` and
`RAID_MAP_NOTES`; there is no raid dynamic-list token. Empty fields become `?`
or another honest placeholder. Normal and raid fields never copy across scopes
without explicit independent confirmation. A map placeholder must not create a
nonexistent `File:map_ZONE_NAME.jpg` link or fake `0, 0` coordinates. File titles,
links and free text must retain the native contribution editor's escaping and
validation boundaries. This blueprint does not authorize automatic page writes
or bypass source-count, item-stat or Notable-choice policy.

## Review and publication

`ops/ZonePageBlueprint.php` replaces only the active level-three Zone Page
Blueprint subsection. It ignores headings inside comments/protected code and
requires the reviewed next City Page Blueprint boundary, refusing an unbounded
replacement. Everything before and after that subsection is preserved exactly.

Run source-only checks:

```sh
php ops/test-zone-page-blueprint.php
php ops/test-zone-blueprint-installer.php
php ops/test-dynamic-zone-admission.php
php -l ops/sync-zone-page-blueprint.php
```

The source fixtures cover protected headings, bounded/idempotent section
replacement, missing/ambiguous contracts, and independent normal/raid structure.
The publisher fixture covers read-only default, exact full-page recovery,
non-overwriting private paths and revision races before/after backup and at save.
An October 25 anonymous unsaved `action=parse` check also rendered a normal
Nektropos draft with one overview and Map, and a synthetic confirmed-raid draft
with two overviews plus Map/Raid Map and the `Raid_Instance` anchor. No article
was saved. This confirms parser structure, not new gameplay facts or publication.
The Nektropos draft now contains its canonical DynamicZoneList invocation for
the proposed admission change. The current live installation still refuses that
new zone; the unsaved receipt records this limitation rather than claiming the
local fallback is deployed. The separate source/service fixture executes the
actual admission and table-render entry against synthetic public/private pages.
The exact proposed admission method was also evaluated in memory against eight
current public-title cases through real anonymous permissions and replica reads:
Lake Nerius, Nektropos Undercrofts and legacy Rathe Mountains were admitted;
an NPC, Category namespace, raid fragment, unproved short alias and the literal
`ZONE NAME` placeholder were refused. This read-only probe installed no code and
performed no article or dataset writes.

Review the current public Help revision and diff first. With an explicitly
reviewed revision, the maintenance helper defaults to read-only review:

```sh
php maintenance/run.php ops/sync-zone-page-blueprint.php \
  --source=ops/zone-page-blueprint.wiki --expected-revision=REVIEWED_ID
```

Only an authorized separate content publication adds `--apply` and
`--backup=/private/recovery/fresh-help-contents.wiki`. It requires the existing
owner account, a fresh non-overwriting full-page backup outside the served root
(or blocked local `.local/`), a latest revision check before and after backup,
and PageUpdater conflict protection at save. No-op runs write nothing. Retain
the backup on failure. As with other private `ops/` tools, application deployment
does not install this publisher or publish its database source. See
[PROJECT.md](../PROJECT.md) and the official
[maintenance script guide](https://www.mediawiki.org/wiki/Manual:Maintenance_scripts).
