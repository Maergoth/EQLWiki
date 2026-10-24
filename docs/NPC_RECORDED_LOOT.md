# Automatic NPC drop-source links

## Goal and source of truth

An item that names a monster in `Itempage.dropsfrom` should also appear on that
monster's page, including when either page is created or edited manually and
regardless of creation order. Ordinary prose links do not establish a drop.
Existing `Namedmobpage.known_loot` and `common_loot` lists remain authoritative
for authored rarity and are never rewritten.

## Implementation ledger

- [x] Locate active registration: `extensions/DynamicZoneList/DynamicZoneList.php`,
  loaded from site settings; existing mob/item cards are database templates.
- [x] Choose a read-only article hook rather than modifying database templates.
- [x] Extract active balanced NPC/item templates, excluding comments and examples.
- [x] Resolve public source items through bounded backlinks, redirects and a
  bounded same-zone item-category fallback for case-variant redlinks.
- [x] Match real source rows, preserve normal/raid zone labels and deduplicate
  items already present in the NPC's authored Common/Unique lists.
- [x] Append a separate Additional recorded drops panel using ordinary item
  transclusions, preserving existing item cards, links and hover behavior.
- [x] Bound lookup, parser and CDN caching; disclose incomplete lookups.
- [x] Run focused parser/lookup/render fixtures, unsaved MediaWiki integration,
  PHP syntax and release packaging checks. No production test-page writes.
- [ ] Open and merge a scoped feature PR into `staging`; inspect deployment.
- [ ] Promote only reviewed feature commits to `main`, inspect deployment and
  verify real public page output. No database pull/schema/template edit required.

## Code and maintenance map

| File | Responsibility |
| --- | --- |
| `extensions/DynamicZoneList/NpcLootWikitext.php` | Pure balanced-template/source-row parsing and normalized identities |
| `extensions/DynamicZoneList/NpcLootLookup.php` | Public revision reads, canonical identities, bounded discovery, deduplication and short shared cache |
| `extensions/DynamicZoneList/NpcLootRenderer.php` | Root legacy parser hook, item transclusions, current-data labels, parser/CDN expiry |
| `ops/test-npc-loot-source.php` | Synthetic source-contract regressions |
| `ops/test-npc-loot-lookup.php` | Synthetic repository/discovery regressions |
| `ops/test-npc-loot-renderer.php` | Hook, markup and cache regressions |
| `ops/test-npc-loot-mediawiki.php` | Unsaved actual MediaWiki parsing, hover markup and dependency integration |

The parser scans only the original article input, before transclusion expansion.
A per-output guard prevents recursive parser calls from adding more panels.
Old cached output containing the NPC template is rejected once; new output
records the guard even when no additional drops exist. Unrelated page caches
are not flushed.
The generated heading uses HTML, avoiding an edit-section link to source text
that does not exist. It is not stored in article revisions.

## Public behavior and limits

The panel supplements the entire NPC article, including multi-zone/instance
articles. Each added item appears once, with the source zones and Normal Zone /
Raid Zone labels beside it. It does not invent Common/Unique rarity. Suppressed
or unreadable revisions must never enter the public lookup or shared cache.

This uses the legacy parser used by ordinary read views. VisualEditor/Parsoid and
section previews are excluded; editing still changes only the author's source.
An old NPC revision may show a clearly labeled panel from **current published
item data**, not a reconstruction of historical drops.

Discovery intersects `Category:Items`, with direct and redirect backlinks taking
priority over zone fallback. Limits per lookup are 20 aliases, eight zones,
160 candidate item pages, 100 authored references and 50 additional items.
Public content reads are capped at 320 pages / 8 MiB total, with 512 KiB per
source page. Redirect resolution stops after eight hops. Unsupported or malformed
source structures are skipped conservatively, rather than treating prose as loot.
The zone fallback also requires a page link whose title case-folds to a bounded
NPC/alias spelling, avoiding a scan of unrelated item content in busy zones.

An incomplete lookup shows a notice instead of claiming there are no drops.
Item transclusion dependencies handle changes to rendered items. A 60-second
candidate-discovery cache plus a 300-second parser and CDN expiry allows newly
linked items to appear without editing the mob page. These independent intervals
can add together (up to roughly 11 minutes, plus normal MediaWiki link-table/job
or replica lag). Candidate caching never replaces fresh public revision/permission
checks when the lookup runs. Incomplete discovery is cached for ten seconds.

No pages, accounts, observations, schemas or private host scripts are changed.
Deployment uses the normal tracked application release. Roll back the scoped
application commit to remove the generated panel; authored loot is untouched.

## Validation record (2026-10-24)

- PHP 8.3: 38 source-parser checks, 253 synthetic lookup assertions and renderer /
  cache-migration contracts passed.
- Actual local MediaWiki 1.45: 20 unsaved parser checks passed, including normal
  item hover markup, transclusion dependencies and one-time old-cache migration.
- Read-only local snapshot lookup: Lord of Loathing (3 candidates), Vicar M`Kari
  (0), A Loathling Lich (35; 23 additional drops), Drelzna (10; Soft Leather Shoes)
  all completed without warnings, in roughly 0.02–0.08 seconds per lookup.
- Public pre-deploy source check confirms Soft Leather Shoes names Drelzna in
  its drop sources while Drelzna's authored loot omits it. Local fixtures and
  snapshot results alone do not establish production deployment.
