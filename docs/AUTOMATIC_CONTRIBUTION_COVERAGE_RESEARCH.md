# Automatic contribution coverage: NPC and zone research

Status: public source research completed October 25, 2026; the accepted typed
coverage contract is implemented for review. Deployment and dataset publication
remain on hold until the combined checks and release review are complete.
This evidence complements the item/renderer research.

## What the evidence changes

The missing fact is a **relationship**, not necessarily a missing line in a
particular page field. Existing authored relationships, explicit collections and
generated category lists may already cover an observation. Writing their mirror
into another source field can add redundant revisions and turn an intentional
general statement into an unnecessarily specific list.

A page-wide Various stop is too broad to represent that distinction. Of 682 NPC
category pages containing the word, 640 have it outside the zone and loot fields.
The word most often describes an imprecise location, merchant inventory or prose.
It does not establish that every relationship on the page is ambiguous.

## Method and scope

Two bounded, anonymous, read-only production-source audits used current
`FOR_PUBLIC` main-slot wikitext and checked public read permission. They made no
HTTP requests, article edits, parser saves or dataset writes. The second scanned
all 8,062 nonredirect NPC-category pages and 121 nonredirect Zone-category pages
returned by the current category intersection, below its 10,000-row bound; none
were unreadable. Counts below describe source recognition, not a claim that every
categorized article is a supported monster or that a fixture is a live test.

Private receipts remain under `.local/slider-release/`: the full Various audit,
NPC/zone taxonomy audit, selected public examples and canonical zone-impact join.
No raw wiki source or private host material is added to this document.

| Observed pattern | Current public-source count | Interpretation |
| --- | ---: | --- |
| NPC category pages | 8,062 | Identity candidates; not all are rich stat records |
| `Namedmobpage` present | 6,658 | A template name, not proof of Notable status |
| `MerchantPage` present | 1,391 | Merchant stock and description must remain separate from dropped loot |
| No recognized NPC stat card | 13 | Examples include quest/miscategorized articles; do not synthesize a monster |
| Unparsed NPC document | 1 | Withhold procedural changes; report the unsupported source |
| Multiple linked zones in an NPC zone field | 256 | Keep all explicit zones and their aliases; do not force one zone |
| NPC pages with Various anywhere | 682 | A lexical flag is a poor relationship policy |
| Various outside zone and loot fields | 640 | 93.8% of the lexical matches are outside those relationships |
| Various in location | 604 | Unspecified spawn spots, not permission to invent coordinates or suppress unrelated loot |
| Various in description | 66 | Often incidental merchant prose |
| Various in zone field | 26 | 22 are the exact literal Various; four have a more complex expression |
| Various in `known_loot` | 16 | Scope must be interpreted within that field, not the entire article |
| Public pages using `Gear Set` | 273 | An explicit collection mechanism worth preserving |
| NPC loot fields linking one of those set pages | 78 | Existing collection coverage can suppress redundant member additions |
| Zone category pages | 121 | Includes a historical cleanup project; not identical to the runtime zone catalog |
| `DynamicZoneList` invocation | 118 | Most normal population/items lists are generated |
| Existing Unique Items summary row | 99 | A supported destination must already exist before automatic population |
| No Unique Items row | 22 | Absence is a layout choice, not a request to create one |
| Existing Notable NPCs summary row | 89 | Separate from ordinary population |
| Existing Types of Monsters row | 103 | Often descriptive families/plain text, not an enumerated list of entity pages |
| Explicit Raid Instance heading | 6 | Scope is structural and must be retained |

Fields overlap, so their counts must not be summed. Set-reference recognition
counts direct links/transclusions within the NPC loot fields; it does not assert
that all collections or all prose group statements have been resolved.

An additional isolated run of the actual source/count helpers on Bone Chips'
public revision is useful: its own item field yields the exact Najena skeleton
edge and `complete = false`; the broad family text does not become a fake named
monster or a complete one-monster count. That is not the item's whole-wiki count
(NPC-authored sources are absent from this isolated run). Preserve this existing
fail-closed count behavior while adding the missing broad-coverage decision.

## Public examples and their coverage

| Page and reviewed revision | Authored pattern | Correct coverage implication |
| --- | --- | --- |
| [A Spiderling, 120495](https://eqlwiki.com/index.php?title=A_Spiderling&oldid=120495) | Exact Various zone and location; four concrete zone categories; ordinary stat card | Treat the existing broad zone statement as sufficient to avoid a missing-zone proposal. Preserve it. Do not claim a new exact spawn coordinate, enumerate every possible zone, or infer Notable from its template/category. |
| [East Commonlands, 137985](https://eqlwiki.com/index.php?title=East_Commonlands&oldid=137985) | Dynamic normal list; descriptive Types of Monsters; a short Notable list; no Unique Items row | Category membership supplies ordinary population. A newly observed ordinary mob does not belong in the Notable list by default, and this layout should not gain a new Unique Items section automatically. |
| [West Commonlands, 137984](https://eqlwiki.com/index.php?title=West_Commonlands&oldid=137984) | Same generated population mechanism; existing Unique Items placeholder | Preserve the existing generated population and populate only a supported, reviewed summary cell under its own selection policy. |
| [Mistress of Scorn, 199677](https://eqlwiki.com/index.php?title=Mistress_of_Scorn&oldid=199677) | Authored loot says two pieces of Imbrued Armor; common loot links Classic Raid Tier Treasures | A known armor-set member is already covered by the set statement. Do not add each armor piece to the NPC or erase the quantity/rarity prose. A collection reference is not a new individual monster. |
| [Imbrued Armor, 177754](https://eqlwiki.com/index.php?title=Imbrued_Armor&oldid=177754) | Explicit seven-member Gear Set and the named source | Membership is independently checkable public evidence; no item-name-prefix guessing is required. |
| [Classic Raid Tier Treasures, 171191](https://eqlwiki.com/index.php?title=Classic_Raid_Tier_Treasures&oldid=171191) | Plain list of ten item transclusions, not a Gear Set | A collection taxonomy limited to Gear Set misses this existing shared loot pool. Recognize only an approved explicit collection structure; do not extend its general prose to every new monster. |
| [A Scorn Banshee, 199683](https://eqlwiki.com/index.php?title=A_Scorn_Banshee&oldid=199683) | Concrete zone, Various spawn locations, direct armor loot and shared raid-treasure collection | Location uncertainty does not make the loot relationships unknown. Shared collection coverage should remain intact. |
| [Bone Chips, 199674](https://eqlwiki.com/index.php?title=Bone_Chips&oldid=199674) | Broad zone heading and skeleton-family text, plus one exact linked source | Broad coverage is intentionally incomplete enumeration. It must not become a fictitious individual mob or proof that fewer than six distinct mobs drop the item. New observations should not expand the old family list automatically. |
| [Spiderling Silk, 197245](https://eqlwiki.com/index.php?title=Spiderling_Silk&oldid=197245) | Specific sources in multiple zones; some historical zone labels are broad | Canonical zone identity and explicit source evidence matter. A broad Commonlands heading is not proof of a particular east/west instance. |
| [Plane of Sky, 179754](https://eqlwiki.com/index.php?title=Plane_of_Sky&oldid=179754) | Quest/class progression layout, no Unique Items summary, Various in quest narrative | Skip the absent summary destination. Do not prohibit unrelated exact source facts merely because quest prose contains the word Various. |
| [Plane of Hate, 174286](https://eqlwiki.com/index.php?title=Plane_of_Hate&oldid=174286) | Specific boss destinations sometimes have a displayed Various label; other occurrences describe vendors or class loot | The wiki-link destination establishes identity. A display label is not a wildcard, and incidental prose must not change the meaning of other rows. |
| [Cabilis, 124120](https://eqlwiki.com/index.php?title=Cabilis&oldid=124120), [Erudin, 193438](https://eqlwiki.com/index.php?title=Erudin&oldid=193438) | Various appears in guild, trainer, merchant and historical descriptions | Whole-zone suppression would discard unrelated exact contributions. City subzones can share one canonical wiki page. |

The source of `Template:Namedmobpage` at revision 149842 emits both NPCs and Named
Mobs categories for every use. A Spiderling therefore demonstrates why neither
that category nor that template can determine whether the player regards a mob
as Notable. The explicit player choice remains necessary for a Notable summary.

## What the active components actually do

`DynamicZoneList_body.php` selects NPC rows from the intersection of the zone
category and NPCs. It separately selects item rows from the zone category and
Items. Those generated tables do not depend on the manual Notable NPCs or Unique
Items summary cells. The Items table is not a `<6 monster sources` filter.

The manual summary rows are authored page content. They can contain placeholders,
set links, descriptive text and highlighted loot. Their label alone does not prove
that their selection rule equals the NPC Common/Unique loot rule. Consequently:

- Ordinary population presence can be satisfied by category-backed dynamic
  membership without writing a Notable row.
- The absence of a manual Unique Items row must skip that optional projection.
- A global distinct-mob count answers NPC loot classification, not whether the
  zone's authored summary should enumerate every item.
- Existing authored classifications, collections and list structure should be
  preserved; an incomplete lower bound is never evidence of global uniqueness.

The reciprocal source contract in [NPC_RECORDED_LOOT.md](NPC_RECORDED_LOOT.md)
projects authored item/NPC facts into native views without saving mirror revisions.
The exporter also reconciles NPC-authored loot into item source records. This is
evidence that a raw empty reverse field is insufficient to classify a missing fact.
The renderer team is separately verifying exact current collection/instance support;
this document does not assume that every inherited collection already projects.

## Proposed relationship decisions for concept review

Use coverage states with explicit evidence, rather than page-wide skip flags:

| Observation and source evidence | Proposed automatic result |
| --- | --- |
| Exact item/NPC/zone/instance edge already authored on either supported side | Covered; no write and no missing notification |
| Member explicitly covered by an authored, verified item collection | Covered by collection; retain authored group and quantity/rarity text |
| Ordinary NPC already in the category-backed zone list | Covered population; no manual Notable entry |
| Existing exact Various zone or a supported broad family/source statement | Broad coverage; suppress enumeration proposals, preserve the old statement, do not invent exact members/categories/coordinates |
| Various only in location | Location remains unspecified; preserve it while evaluating independently supported loot/zone relationships |
| Various in unrelated narrative, merchant stock or a display label | Do not reinterpret another field; use actual relationship structure and link target |
| Supported genuinely new direct relation from fresh corpse/source evidence | Prepare one authoritative append, not automatic copies on every destination |
| Unknown collection, ambiguous instance, unsupported group membership or malformed source | No automatic mutation; keep local evidence available for deliberate review |
| A new NPC with exact identity and source evidence | Follow the supported current blueprint, ask whether it is Notable, and avoid inheriting an old group's loot or zone membership by name similarity |
| Zone lacks a supported optional summary destination | Skip that destination while preserving unrelated authoritative facts |

The choice of which single authored side owns a new edge needs explicit team
agreement before implementation. A sensible default is the existing supported
NPC loot field for a fresh exact corpse association, with item-side source data
used when that is the only supported authoritative structure. Existing ownership
and authored classification take precedence. A zone summary is an optional curated
projection, not a second or third mandatory source-of-truth write.

The item salience exception discussed during the previous patch is not a complete
coverage policy. AC or weapon damage alone does not justify expanding a broad
group, and even bonus-stat gear should not duplicate a set-covered edge. Item
stats can inform a zone summary's own selection policy after relationship coverage
is established; they should not override group or instance evidence.

## Metadata the bundled dataset needs

The existing presence index, rich map/stat records, `lootSourceCounts` and
`lootSets` answer different questions. A global NPC-name count lacks enough zone,
instance and provenance information to serve as the complete coverage graph.

The next design should preserve these bounded public facts separately:

1. Canonical entity/page identities and redirects, including statless known pages.
2. Authored relationship edges with source page ID/revision, field and structural
   normal/raid/shared scope; keep public link targets separate from labels.
3. Explicit collection identity, verified member identity and completeness, with
   membership provenance. Unknown group names remain unknown.
4. Broad coverage records identifying the affected relation, scope and authored
   source expression. They suppress enumeration without manufacturing exact edges.
5. Zone list capabilities: canonical category, matching DynamicZoneList invocation,
   actual category membership, existing summary rows and their normal/raid scope.
6. Explicit Notable choice for a new NPC summary entry. Existing template names,
   capitalization and the historical Named Mobs category do not supply that choice.

Cache checks should establish positive existing coverage first. A candidate gap
then receives the already-authorized bounded current-page/redirect validation.
Revisions and account binding guard the eventual one-edge append. A stale or
incomplete snapshot cannot turn unknown group scope into a genuinely new fact.

## Acceptance examples before any rollout

The reviewed design should make these observations quiet without editing old
pages: Bone Chips from a previously unenumerated skeleton covered by its broad
statement; an Imbrued armor piece from Mistress of Scorn; an ordinary Commonlands
mob already in the generated list; a spiderling in a zone under its broad zone
statement; and an item whose reverse field is empty but whose exact source is
already authored on the NPC.

Separate cases must prove that a fresh exact new edge can still be recorded once,
an explicit new Notable choice changes only the intended summary, optional absent
zone sections are skipped, normal/raid facts do not leak, unknown family membership
does not get guessed, authored collection text survives, and existing rendered
reciprocal views remain correct without redundant persistent mirror writes.

The earlier lexical policy commits are research inputs, not an approved release
of this revised coverage model. No further rollout should be inferred from their
existence or from these read-only findings.

## Implementation status

This research informed the accepted typed, affected-field coverage contract.
The consolidated EoZ `docs/AUTOMATIC_CONTRIBUTION_COVERAGE.md` plan governs the
implementation. The earlier whole-page Various opt-out and item-stat exceptions
are preserved research prototypes and are superseded by that contract.

The bounded candidate collector dry run read 11,355 public item pages and 8,062
public NPC pages using current anonymous revisions, with no HTTP calls, edits,
installed code changes or pack writes. Its 11,506 count entries have 1,380
verified six-monster Common lower bounds and zero complete Unique proofs.
Research remains an undefined broad group: its delete-tagged source revision
172679 points at the missing Skill Research page. Sarialiyn's Lute is an untyped
literal reference to a self-title stub, not a proved loot collection. These
unresolved definitions remain conservative uncertainty; they do not create fake
member or monster facts. Rusty/Fine Steel/Bronze Weapons and Decayed Armor
uncertainty can be bounded to their explicit structural candidates without
claiming an unconditional member cross-product. A fresh sync alone cannot
repair a missing group definition. Existing positive coverage and verified
Common lower bounds remain independently usable.

