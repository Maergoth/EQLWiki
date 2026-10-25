# Item source coverage research and proposed policy

Status: research completed for concept review, 25 October 2026. Rollout remains
on hold pending the combined dry run. The accepted implementation uses typed
affected-field coverage and a single authored relationship. The earlier
whole-page `Various` detector and stat exception were rejected as the eligibility
policy. The discussion below preserves that research history; the current
[renderer contract](NPC_RECORDED_LOOT.md) describes the implementation. This
document makes no claim that an application push has installed it.

## Evidence and limits

Two bounded anonymous reads examined 65 requested public titles at
07:17–07:19 UTC. There were 62 distinct available canonical pages, including 52
pages with balanced Itempage documents (one has multiple variants), one additional
Itempage rejected for duplicate arguments,
explicit collections and representative NPCs. This is a deliberately selected
sample, not a prevalence estimate for the whole wiki. Public current revisions
were read with `RevisionRecord::FOR_PUBLIC`; unreadable/missing results were not
used. No pages, link tables, settings or purges were written. Full receipts remain
in ignored private diagnostics rather than committed fixtures.

The separately available bundled snapshot `wiki-20261025T060106Z` was measured
locally. It contains 11,351 rich items and 44,700 `dropSources` rows. Of those rows,
35,617 carry NPC-authored `lootSources` provenance, spread across 5,326 items.
945 items associate the same canonical NPC with more than one zone. There are
814 unscoped rows and 294 rows whose labels contain broad terms such as
various/many/most/all/types/mobs/monsters. The last number is a diagnostic lexical
bucket, not a classifier. Snapshot numbers are not fresh live completeness claims;
all the inspected count records in that older snapshot were marked incomplete.

A previous complete bounded public-category scan found the literal word Various
on 209 of 11,355 Items-category pages. This measures a word in the page's own
source, not generic source coverage or item importance. Categories also contain
nonstandard pages; they are not synonymous with a supported Itempage contract.

The observed source is changing: the layout probe read Bone Chips revision
199663, while the later research read revision 199674. Comparisons below name
revisions so concurrent article corrections are not mistaken for parser behavior.

## Authored patterns actually present

| Pattern | Public evidence | Meaning for automation |
| --- | --- | --- |
| Global family prose plus exact examples | [Bone Chips, rev 199674](https://eqlwiki.com/index.php?title=Bone_Chips&oldid=199674) combines a broad zone/family description with a concrete Najena source. | A generic family is intentional non-enumeration. Keep the exact example, but do not enumerate every skeleton or turn the prose into an NPC title. |
| Long explicit material lists with no Various | [Spider Silk, rev 198148](https://eqlwiki.com/index.php?title=Spider_Silk&oldid=198148) has 43 parsed zone/NPC rows; [Spiderling Eye, rev 199671](https://eqlwiki.com/index.php?title=Spiderling_Eye&oldid=199671) has 10; [Spider Legs, rev 199672](https://eqlwiki.com/index.php?title=Spider_Legs&oldid=199672) has 38. | A word-only opt-out misses routine source floods. Existing exact coverage from either side should suppress duplicate writes independently of salience. |
| Widespread reagent with large exact coverage | [Crystallized Sulfur, rev 199690](https://eqlwiki.com/index.php?title=Crystallized_Sulfur&oldid=199690) has 142 parsed rows and no Various. | Neither an item-only local count nor absence of a keyword establishes Unique loot or a worthwhile backfill. |
| Broad scope mixed with specific exceptions | [Black Sapphire, rev 193898](https://eqlwiki.com/index.php?title=Black_Sapphire&oldid=193898) mixes broad high-level drops in several zones with exact NPCs elsewhere. [Blue Diamond, rev 193994](https://eqlwiki.com/index.php?title=Blue_Diamond&oldid=193994) uses most-mob descriptions without Various. | Scope belongs to particular source groups. A broad group is neither an exact NPC edge nor a reason to discard all exact facts on the page. |
| Random level-based research drops | [Rune of Howling, rev 196732](https://eqlwiki.com/index.php?title=Rune_of_Howling&oldid=196732), [Breath of Solusek, rev 194072](https://eqlwiki.com/index.php?title=Breath_of_Solusek&oldid=194072). | Level bands and anecdotes do not enumerate a finite source set. They can signal intentional non-enumeration; they cannot prove a specific family member. |
| Region or wing rather than NPC | [Ancient Silk Boots, rev 145689](https://eqlwiki.com/index.php?title=Ancient_Silk_Boots&oldid=145689), [Tattered Silk Robe, rev 147210](https://eqlwiki.com/index.php?title=Tattered_Silk_Robe&oldid=147210), [Unadorned Breastplate, rev 147214](https://eqlwiki.com/index.php?title=Unadorned_Breastplate&oldid=147214). | Important quest armor may have generic regional coverage and no bonus stats. Do not invent a mapping from NPC name or zone prefix to an east/west wing. |
| Explicit speculation and uncertainty | [Iksar Hide Boots, rev 142272](https://eqlwiki.com/index.php?title=Iksar_Hide_Boots&oldid=142272) has two exact sources plus a suspected family. [Dwarven Axe, rev 139961](https://eqlwiki.com/index.php?title=Dwarven_Axe&oldid=139961) has exact NPCs and an uncertain guard group. [Words of Wealth: Bottom Left, rev 197937](https://eqlwiki.com/index.php?title=Words_of_Wealth%3A_Bottom_Left&oldid=197937) has a named source plus a tentative broad group. | Preserve qualifiers. Do not promote a question, most/all statement or family suspicion to an unconditional relationship. |
| General prose outside a list | [Sporali Defender's Blade, rev 167284](https://eqlwiki.com/index.php?title=Sporali_Defender%27s_Blade&oldid=167284), [Forlorn Totem of Rolfron Zek, rev 143083](https://eqlwiki.com/index.php?title=Forlorn_Totem_of_Rolfron_Zek&oldid=143083), [Stave of Shielding, rev 143136](https://eqlwiki.com/index.php?title=Stave_of_Shielding&oldid=143136). | The parser should retain verified list facts and separately report unparsed/generic coverage. Prose cannot become an NPC row. |
| Low-value gear with ordinary weapon/armor fields | [Rusty Axe, rev 198130](https://eqlwiki.com/index.php?title=Rusty_Axe&oldid=198130), [Cloth Cape, rev 198105](https://eqlwiki.com/index.php?title=Cloth_Cape&oldid=198105), [Fine Steel Dagger, rev 140306](https://eqlwiki.com/index.php?title=Fine_Steel_Dagger&oldid=140306). | Equippable, AC and weapon damage do not establish importance. |
| Useful gear with generic sources | [Spiked Goblin Ring, rev 138693](https://eqlwiki.com/index.php?title=Spiked_Goblin_Ring&oldid=138693), [Split Paw Hide Belt, rev 179026](https://eqlwiki.com/index.php?title=Split_Paw_Hide_Belt&oldid=179026). | Bonus stats are a useful signal but cannot prove that a generic group is absent or should be expanded. |
| Important items without bonus-stat numbers | [Manastone, rev 162395](https://eqlwiki.com/index.php?title=Manastone&oldid=162395), [Staff of Elemental Mastery: Water, rev 173882](https://eqlwiki.com/index.php?title=Staff_of_Elemental_Mastery%3A_Water&oldid=173882), [Handmaiden's Handwrap, rev 199605](https://eqlwiki.com/index.php?title=Handmaiden%27s_Handwrap&oldid=199605). | Effects, epic/focus roles and weapons matter. The proposed stat exception alone is not a complete priority model. Manastone also carries an unconfirmed-in-this-game caveat; do not delete that note automatically. |
| Important quest object shared by many named enemies | [Shattered Emerald of Corruption, rev 196960](https://eqlwiki.com/index.php?title=Shattered_Emerald_of_Corruption&oldid=196960) lists 12 explicit rows. | Common by source-count classification does not mean unimportant or routine. A blanket common-material rule would need positive material evidence and must keep these cases separate. |
| Conditional source, sex or historical annotation | [Staff of Elemental Mastery: Fire, rev 173888](https://eqlwiki.com/index.php?title=Staff_of_Elemental_Mastery%3A_Fire&oldid=173888), [Shin Gauntlets, rev 167083](https://eqlwiki.com/index.php?title=Shin_Gauntlets&oldid=167083). | Male-only and Lower-Guk-only constraints are facts. An unqualified NPC-level source does not authorize a new opposite-sex/zone/instance assertion. |

In this selected sample, 16 items with no Various still returned incomplete source
syntax, including generic descriptions, annotations and duplicate contexts. A
parser's incomplete result is not evidence that all its positive rows are missing.

## Explicit sets are different from generic families

[Imbrued Armor, rev 177754](https://eqlwiki.com/index.php?title=Imbrued_Armor&oldid=177754)
has a finite seven-member `Gear Set`. The actual
[Mistress of Scorn source, rev 199677](https://eqlwiki.com/index.php?title=Mistress_of_Scorn&oldid=199677)
explicitly names this armor collection without listing its individual bracer.
A verified set reference in that NPC's
matching loot context can cover a member without a second individual append.
Only the explicit set-membership proof supplies that implication; the shared
name prefix supplies none.

[Classic Raid Tier Treasures, rev 171191](https://eqlwiki.com/index.php?title=Classic_Raid_Tier_Treasures&oldid=171191)
is an explicit ten-item transclusion list, not a Gear Set. It is linked from
Common Loot and should be a candidate for a separate reviewed collection contract.
Arbitrarily expanding every linked prose page would be unsafe. The nearby
[Classic Planar Armor: Group 2, rev 172314](https://eqlwiki.com/index.php?title=Classic_Planar_Armor%3A_Group_2&oldid=172314)
uses class, slot and zone tables. A Cartesian product of every armor item and
every listed mob would invent drops: the authored table restricts slots by mob.

Use complete, public, bounded collection membership; resolve canonical set/item
redirects; keep the originating loot field, zone and instance. Incomplete sets
can preserve already proved positive facts but cannot prove full membership or
absence. Do not infer family membership from creature names, articles, prefixes,
race text, zone names, levels or a broad most/all statement.

## Root cause: a known relationship is being treated as a missing mirror

`BuildEyeOfZommPack::rememberNpcLootSources` and `mergeNpcLootSources` already join
NPC-authored loot into item `dropSources`, with source page/revision/field/section
provenance. This is positive knowledge, even if the raw Itempage does not repeat
it. The older measured snapshot has 35,617 such source rows.

`desktop/automatic-loot-sources.cjs::AutomaticLootSources.process` obtains the
native catalog, but after the material/Various guards it resolves live pages,
builds a `missing-source` candidate and calls `preparePlan` without first testing
whether the observed edge is already positively covered in that catalog.
`checkPlan` then validates an item-source patch plus possible NPC and zone patches.
It validates the form of the changes, not whether the relationship needs to be
saved again. Queue UI coverage and the independent native publisher can therefore
disagree. Fixing a notification classifier alone cannot stop these writes.

`app/contribution-reasons.js` already consults the joined rows, but requires a
zone match. A cached authored relationship to the same canonical NPC with no
specified zone can be repeatedly proposed for zone refinement. Suppression should
recognize that the NPC relationship is already documented without asserting that
all zone-specific variants are now proven. Unknown instance must not silently
become proof of the opposite explicit instance.

The dataset is incomplete in different ways from the renderer. The legacy rich
join reads `known_loot`, rejects colon-containing targets and does not carry full
set closure. The newer global count collector has broader namespace-validated,
set-aware extraction. Existing positive rows remain useful; absence of a rich
row is not proof of a missing fact. Reuse positive coverage first, then close these
producer contract gaps rather than pretending a completely new graph is required.

## Does one authored side suffice?

For the common exact, public, supported layout: yes. The wiki already computes
reciprocal read views without storing a second revision:

- `NpcLootLookup` reads item Drops From to enrich native NPC loot.
- `ItemDropLookup` reads authored NPC loot to enrich item Drops From.
- The exporter independently unions authored facts; it does not scrape generated
  renderer HTML, so projected output cannot become circular new evidence.

The right default is **one authored relationship, multiple read views**. Do not
persist item, NPC and aggregate zone mirrors merely to make their raw text match.
But the present renderer cannot be assumed to show every union edge:

| Limitation | Consequence |
| --- | --- |
| Neither renderer lookup currently expands finite NPC set links into members or deduplicates their member closure. | NPC set coverage can be sufficient for saved facts while an item read view still needs a separate rendering fix. Do not solve that by writing individual members everywhere. |
| `ItemDropLookup` calls `npcLootSources` with the default strict colon rejection. | Colon-named epic staves are not reliably projected from NPC loot, even though they are valid main-namespace pages. Namespace validation, not blanket colon rejection, is the eventual fix. |
| `itemSources` rejects a repeated same-target zone block. Bat Wing rev 193815 has separate displayed West/East Freeport headings pointing at the same Freeport target. | The parser returns no source rows and incomplete status, despite positive rows elsewhere. Preserve safe positives and report context ambiguity; never treat the whole page as empty. |
| Fire Sky Ruby rev 194931 has two same-name Itempages with different icon IDs/weights. Rune of Banding rev 196712 repeats a template argument. | These require variant/duplicate-field handling or manual review. Do not choose a card by the item name alone. |
| Candidate/result/byte/read caps, anonymous access, unsupported markup and replica lag are explicit limits. | A missing projection is a read-model limitation, not authorization to persist an identical source again. |
| `NpcLootLookup::defaultLootKind` counts only direct item sources and falls back to Known when classification is incomplete. | It does not implement the user's whole-wiki Unique `<6` requirement. Read-time Known/Unique classification must use the same global proof as native publication, or remain explicitly unclassified. |

Canonical NPC identity, zone precision, instance and qualifiers are separate axes.
Repeated zones for one canonical monster do not increase the distinct-mob count.
An unscoped authored same-NPC relationship can suppress redundant refinement
writes without claiming every scoped variant. A generic family label cannot cover
an unrelated exact NPC by spelling similarity. Existing explicit item-side
restrictions must not be broadened by a shared multi-zone NPC card.

## Proposed eligibility and backfill strategy for review

Keep four decisions separate:

1. **Identity and provenance:** Are item, NPC, source context and collection members
   canonically/publicly identified? Can the edge be traced to a current public
   authored revision or a fresh exact-corpse observation?
2. **Effective coverage:** Is the relationship already authored on either side or
   through a verified finite collection, at the relevant instance/precision?
3. **Publication priority/policy:** Does an uncovered relationship warrant automatic
   publication or an interruptive proposal? Routine materials and intentionally
   broad groups can be quiet without being declared nonexistent.
4. **Loot classification:** Are at least six distinct canonical mobs proved, or is
   a complete global result below six available? This answers Common/Unique only,
   not importance, coverage or drop probability.

Suggested decision sequence:

- Check the native-owned snapshot first. A positive authored item/NPC/set edge
  suppresses automatic mirror writes and duplicate gap notices. Do not require
  complete global counts to honor a known positive relationship.
- Treat generic/uncertain source groups as intentional non-enumeration, not a list
  of synthetic NPCs. Scope that policy to the authored group where it can be
  resolved safely. The current whole-page word rule is a conservative interim
  switch with known false positives and false negatives, not the final ontology.
- If the snapshot has no positive edge, distinguish unknown from uncovered. Fetch
  only the current relevant item/NPC/verified collection pages before any proposed
  write, and recheck both authored sides. No per-loot whole-wiki scan is needed.
- Preserve user-requested snapshot-first presence behavior: only when a page is
  absent locally perform the bounded live existence check before showing a
  missing-page notification. A live page suppresses that notification.
- For a genuinely new, supported, eligible relationship, author it once. Prefer an
  exact item Drops From entry for an existing item when that avoids inventing an
  NPC rarity class. A new item page can carry its observed source in the initial
  creation. Whether existing-NPC-only storage is preferable in some workflows is
  a concept-review choice; it must not automatically imply two more writes.
- Treat aggregate zone loot as a separate editorial choice. An exact NPC drop does
  not require another aggregate zone entry. Honor existing zone/instance set
  coverage and only add a specifically approved, useful aggregate entry.
- Keep manual review available for important effect-only gear, epic components,
  unresolved variants and qualified/conflicting claims. Avoid a blanket rule that
  all statless, Quest-tagged or count-common items are unimportant. Conversely a
  single bonus number is not proof that enumeration is wanted.
- Keep observations distinct from published contributions in History. A repeated
  known drop can be retained quietly as observation evidence if desired; it must
  not report a contribution as published when no edit occurred.

Do not mass-backfill raw mirrors. First produce a dry-run report of candidate
edges with reason codes: already covered, collection covered, generic policy,
routine policy, genuinely uncovered, identity ambiguous, context ambiguous,
conflicting qualifier, unsupported syntax and incomplete lookup. Review important
uncovered examples and a randomized negative sample before enabling automatic
writes. Counts should report how many *new facts* would be added, not how many
raw page fields differ.

## Implementation plan after concept approval

1. Extract one native-owned positive-coverage predicate shared by queue reasons,
   notifications, preview and `AutomaticLootSources`. Initially reuse existing
   `dropSources` provenance and trusted set membership. An untrusted renderer
   argument must never create authority to skip native validation or write pages.
2. Version additive source-coverage metadata only where the rich exporter is
   insufficient: separate exact edges, scope precision, provenance, qualifiers,
   collection membership, generic groups and completeness. Keep current positive
   facts usable with older catalogs; never turn missing metadata into absence.
3. Fix renderer/producer support deliberately: canonical zone deduplication,
   namespace-validated colon titles, set/approved collection closure, usable
   positives from partially understood pages, and consistent global classification.
   Preserve privacy, read bounds, canonical case distinctions and authored text.
4. Make native publication re-evaluate the same effective coverage before plan
   creation and again against live relevant pages before the first write. Retry,
   legacy saved plans and manual previews must have explicit, separately tested
   behavior. Preserve account/revision checks and append-only constraints.
5. Run dry-run receipts and focused synthetic regressions, then isolated real
   parser/native tests. Only after reviewing measured deltas resume the held
   stage/production release procedure.

Required regressions include item-only, NPC-only and both-sided facts; scoped and
unscoped same NPC; redirects and real case-distinct pages; normal/raid conflicts;
finite sets vs regional pools vs prose; routine silk/reagent examples; important
statless quest/effect-only items; base-damage-only weapons; qualified historical
claims; repeated aliases; multiple Itempages; private/suppressed revisions; bounded
incomplete lookups; stale snapshots; repeated observations; and cancellation or
partial publication without mirror repair floods.
