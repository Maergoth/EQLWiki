# Reciprocal NPC and item drop links

## Contract and source of truth

An authored relationship on either page is enough once both pages exist:

- `Itempage.dropsfrom` supplies missing entries in the NPC's native loot card.
- `Namedmobpage.known_loot` / `common_loot` supplies missing entries in the item's
  native Drops From section.
- Either page may be created first, through the wiki editor or an attributed API
  edit. Missing pages remain redlinks; later creation makes the relationship usable.

The extension changes the parser's in-memory input. It never saves generated
wikicode, creates placeholder pages, invents an edit author or adds fake revisions.
Automatic display enrichment uses the affected authored source/loot field, not
words elsewhere on the page or an item's bonus-stat numbers. Broad groups,
qualified/unsupported layouts and multiple physical item variants retain their
positive facts but do not authorize blind automatic expansion. Comments, quoted
examples and arbitrary wrapper arguments never become source claims.

Only public authored fields establish relationships; generic prose, comments,
protected examples and unused wrapper arguments do not establish drops.

## Layout and duplicate handling

NPC additions go inside the existing `Namedmobpage` loot fields. The existing
single HTML list is extended where safe; otherwise a list is added within the
same field. Exact None placeholders disappear when the first drop is added.
The template keeps its native headings: Known Loot, or Unique Loot when Common
Loot is populated. Explicit contributor Common/Unique fields stay intact.
Item additions go inside the existing `Itempage.dropsfrom` / `drops_from` field.
Existing simple zone lists and normal/raid sections are extended in place,
including when a current public zone redirect supplies an equivalent title.
New normal zones are inserted before the raid boundary. Unknown-zone sources
receive an explicit instance reset so they cannot inherit the preceding zone.
Duplicate or unsafe list contexts remain unchanged and report unplaced sources.
There is no separate Additional recorded drops panel.

Normal/raid and zone contexts are preserved. A sole unheaded card is shared;
explicitly partitioned or multiple cards require an unambiguous matching context.
Ambiguous relationships are reported instead of being assigned to an arbitrary
card. Generated NPC loot rows display only the item name through normal item
transclusions, retaining the original wiki hover cards. Zone and instance remain
internal placement metadata rather than extra labels beside every item.
A generated NPC row is classified Common only after more than five distinct
canonical publicly readable NPC sources are proved, a safe whole-wiki lower
bound. Five or fewer direct sources cannot establish whole-wiki Unique. Such
facts remain available to lookup callers with `projectionSupported=false` and a
null `lootKind`, while the renderer quietly skips that new row. Existing authored
Known/Unique/Common rows remain authoritative. No global scan runs during parsing.
Inner normal/raid sections within one NPC loot field are extracted as distinct
facts, but generated appends to those fields are quietly deferred rather than
placed under the wrong heading. Native outer instance cards remain supported.
Actual source
lookup failures and shared read/byte-budget exhaustion still report incomplete
results. Native loot lists use the skin's stable grid layout so opening an item
hover cannot move entries between balanced columns.

Duplicates are excluded using current canonical public page identities and
redirect resolution. First-letter normalization and safe case-variant redlinks
are supported without merging two real case-distinct pages. NPC loot exclusions
cover direct transclusions and links inside spans, bold markup, inline groups,
leading-colon links and fragments. If the authored exclusion set exceeds its
bound, additions are withheld rather than risking duplicates. Existing authored
lists and their rarity notes remain unchanged. An authored unscoped NPC already
covers that same canonical NPC in the matching instance; recovering a zone does
not add the NPC a second time. Real case-distinct zone pages stay distinct.
Zone redirect proof uses the same anonymous current-public revision checks and
shared read/byte limits as source lookup.

Finite collections are explicit `Gear Set` members or one unconditional root-only
list of literal item links/transclusions. Every member and nested collection must
resolve to a current anonymous-readable item/finite collection before complete
coverage is used. Conditional tables, nested wrapper/NPC loot lists, unknown
expansions and family name patterns are not finite collection proofs. Reverse
item discovery checks at most 20 collections, depth eight, within the existing
160-candidate and 320-public-read budgets. Membership and permissions are
rechecked on cache hits. An authored complete set suppresses redundant individual
NPC projections in the same Normal/Raid instance and supplies member item read
views without saved mirrors. Quantity/rarity prose can retain a verified set
reference for coverage while conservatively deferring a novel automatic append.

The shared `contributionCoverage` contract separates exact positive edges from
field capability (`exact`, `broad`, `unsupported`, `variant`) and preserves scoped
collection candidates for public verification. `authoredPageKind` recognizes
balanced root cards independently of unrelated duplicate fields. Literal possible
member helpers are uncertainty over-approximations only: they never prove drops
or a Cartesian product. Conflicting affected fields do not choose arbitrary
positive facts. Reaching an edge, scope or collection limit marks capability
incomplete; a truncated list never authorizes an automatic append. See
[the item-source research](AUTOMATIC_ITEM_SOURCE_RESEARCH.md).

## Targeted cache invalidation

Item source changes purge old and new affected NPCs. NPC loot changes purge old
and new affected items. Saves run this both immediately and after committed
MediaWiki link/category updates, so an early render cannot retain an old empty
candidate result. Moves and deletions use the same public relationship checks.

Each affected page receives a candidate-discovery WAN check-key invalidation and
core `WikiPage::doPurge()`, refreshing page/parser/file/CDN caches without creating
a revision. Unrelated pages are not globally purged. A per-output guard prevents
recursive parses; prior-layout item/NPC-containing parser caches refresh once.
The guard version also invalidates output generated with prior projection policies.

The fallback remains a 60-second discovery cache (ten seconds when incomplete)
and at most 300 seconds for parser/CDN output. Deferred link processing, replica
lag or exhausted safety bounds can still delay updates. Independent fallback
intervals can add together, up to roughly 11 minutes plus processing lag if the
normal targeted update cannot complete. Old CDN responses may retain their old
expiry until a purge reaches them. Public current revisions and anonymous read
permissions are rechecked when lookups execute.

## Code and maintenance map

| File | Responsibility |
| --- | --- |
| `extensions/DynamicZoneList/DynamicZoneList.php` | Active legacy registration, autoload and hooks |
| `NpcLootWikitext.php` | Balanced public field contracts, scoped relationship extraction and in-memory native-field merging |
| `PublicLootCollections.php` | Shared current-public finite set/list closure, with cycle/depth/read limits |
| `NpcLootLookup.php` | Item-to-NPC public lookup and authored-loot exclusions |
| `ItemDropLookup.php` | NPC-to-item public lookup, including pagelinks and item transclusion backlinks |
| `NpcLootInvalidation.php` | Old/new relationship invalidation on save, post-links, move and delete |
| `NpcLootRenderer.php` | Root read-view routing, normal item cards and cache markers |
| `ops/test-contribution-coverage.php` | Affected-field capabilities, positive precision, finite/possible collection boundaries and truncation guards |
| `ops/test-npc-loot-source.php` | Synthetic source and placement regressions |
| `ops/test-npc-loot-lookup.php`, `ops/test-item-drop-lookup.php` | Synthetic public repository/cache/identity regressions |
| `ops/test-npc-loot-invalidation.php` | Save, post-links, move/delete and both-direction purge contracts |
| `ops/test-npc-loot-renderer.php` | Root parser, markup and cache migration contracts |
| `ops/test-npc-loot-mediawiki.php` | Unsaved actual MediaWiki rendering, native sections, hover dependencies and unchanged pre-save source |

Lookups use category intersections and bounded literal-name predicates; neither
performs a full source-content scan. MySQL/MariaDB binary title fields are cast
to UTF-8 text before case folding. Redirects stop after eight hops; candidates,
outputs, authored references, public content bytes and invalidation targets have
explicit code bounds. Incomplete work produces a notice. The legacy-parser read
view is supported; Parsoid/VisualEditor and section previews are excluded.
Historical renders can reflect current published relationship data, rather than
reconstructing historical drop tables.

## Release and checks

1. Run the focused source, both repository, invalidation and renderer fixtures;
   lint changed PHP. With a configured private local wiki, run the unsaved parser
   test through `php maintenance/run.php ./ops/test-npc-loot-mediawiki.php`.
2. Open a scoped PR to staging and inspect CI/deployment. Promote only reviewed
   commits to main with owner authorization, then inspect production deployment.
3. Verify ordinary public NPC and item pages, native sections, duplicate handling
   and the active extension version. A forced link refresh of an existing test
   page can exercise post-links invalidation without editing its content.

The 25 October candidate passed 40 unsaved checks through the actual MediaWiki
legacy parser, including native sections, unchanged pre-save source, hover
dependencies and affected-field policy. The lookup factories in that parser
fixture are synthetic; separate repository fixtures verify collection discovery,
permissions, canonical identity and bounds. No wiki pages were saved by the probe.

No schema, private setting, template publication, database pull or host-script
installation is required. Roll back the scoped application commit to remove the
rendering/invalidation behavior; stored authored content is unaffected.

## Eye of Zomm

The desktop dataset producer independently reconciles authored NPC loot with item
Drops From records. It does not scrape this extension's generated HTML. The app
joins those item source records into its NPC browser, Live Map and Intel views.
New relationships reach the app after successful dataset publication and download;
wiki cache invalidation does not publish a dataset. See the shared refresh policy
in PROJECT.md and the client integration guide.
