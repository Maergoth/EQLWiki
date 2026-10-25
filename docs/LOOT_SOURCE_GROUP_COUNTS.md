# Bounded uncertainty in global loot counts

The global source count is the number of verified canonical public NPC articles,
across all zones and instances. Six verified sources establish Common even when
other sources are unresolved. A count below six establishes Unique only if the
item's source scan is complete. A broad authored group is not permission to add
each possible member to each NPC page.

## Public source investigation, 25 October 2026

The full pre-change diagnostic examined 11,355 categorized item pages and 8,062
NPC pages. All 11,506 count entries were incomplete; 1,380 still had a verified
Common lower bound. The only unbounded collection identities were `Research`
and `Sarialiyn's Lute`. Conditional Rusty, Fine Steel, Bronze and Decayed groups
already supplied bounded possible-member uncertainty, without positive expansion.

The eleven unbounded diagnostics are not eleven Research NPCs. Ten NPC articles
have a Research reference in a loot field:

| Article | Reviewed revision | Loot context |
| --- | ---: | --- |
| A Spiroc Proven | 82853 | Research materials |
| Cynthia | 83025 | Research materials, rare |
| A Skulking Brute | 84357 | Research materials |
| A Griffawn | 84797 | Research materials; two zone contexts, one NPC identity |
| A crag spider | 85409 | Research |
| A gorging brute | 176607 | Research materials |
| A scarecrow | 85811 | Lowest-tier Research ingredients |
| A thought corruptor | 106076 | Research ingredient-list anchor |
| A goblin diviner | 106115 | Research materials |
| Glendl Vargnus | 158733 | Low-level Research components |

Lanivon Baxer revision 122602 mentions a Research quest in description text and
explicitly has no loot; it is not an additional source. Prefix/quantity prose
can supply uncertainty without establishing a positive member/drop fact.

`Research` revision 172679 contains a deletion marker and a redirect-like line
to the missing `Skill Research` page. It is not a native redirect. The reviewed
public [Spell Research Materials](https://eqlwiki.com/Spell_Research_Materials)
catalog, revision 170964, explicitly describes the removed legacy skill:
“This skill has been removed from Everquest Legends”. Its authored body consists
of four literal item lists. The lists contain 52 references to **50 distinct
article identities**; two identical repeats are harmless. The catalog's scope
is the legacy material labels above, not arbitrary future Research prose,
recipes or every item usable as an ingredient.

All fifty targets were verified as public Itempage sources in a bounded
anonymous API/local replay. Eight are uncategorized Itempages wrapped in
`onlyinclude`; their absence from the rich item index is not a redlink or
non-item proof. The replay used current public source/revision data, not names
or equipment/material guesses.

`Sarialiyn's Lute` revision 176798 consists solely of its own title. The source
on Orc scoutsman (Crushbone), revision 164900, cannot establish a complete
Itempage or finite collection. Its uncertainty is bounded to that exact possible
identity; it never creates a positive drop or an item count entry for a stub.

## Producer contract

`SOURCE_GROUP_DOMAINS` is a deliberately small reviewed registry. The Research
entry requires the reviewed source signature, the reviewed catalog introduction,
strict closure of literal list syntax, and public current item/missing member
identities. Identical member references deduplicate. Unknown fields, dynamic or
protected markup, unsupported member roles, changed meaning, unavailable sources
and limits fail closed. Equipment is not excluded from possible membership.

Both cases remain `complete:false` and `items:[]`. They add only
`possibleItems`, `possibleItemsComplete`, and `possibleRule`. Research also
records `possibleSource` alongside the group `source`, retaining both public
revisions. The optional rule is `reviewed-research-catalog` or
`literal-self-title`. Schema versions remain loot source counts v2 and loot sets
v1. Consumers must never interpret possible membership as coverage or a positive
NPC source. A missing/unsupported domain still makes the global scan incomplete.

This scopes uncertainty to the possible identities, permitting independently
complete unaffected items to regain Unique proof. It does not force every item
below six to complete: that item's malformed sources, unknown NPCs or possible
membership still withhold completeness. A source deployment and full refreshed
dataset are required before an installed client receives new classifications.

## Public zone discovery

The exporter now augments its curated map catalog with canonical anonymously
readable current `Category:Zones` pages, including pages without raids. Curated
normal/raid identity and map metadata are retained. New zones receive their
canonical name/title and no invented viewer ID or viewer name. Only one literal
`Name in /who` row in the normal overview can supply an alias; raid cards,
placeholders, dynamic values and cross-zone conflicts do not.

The actual-source replay includes Lake Nerius revision 199310, with unknown
`/who` and no alias, and Nektropos Undercrofts revision 199696, with the literal
`nektrunder` alias. Runtime observed log pairs are a separate client concern.

## Verification and limits

Run `ops/test-eoz-loot-counts.php`, `ops/test-eoz-raid-exporter.php`,
`ops/test-eoz-exporter.php`, and the typed coverage checks. Fixtures exercise the
actual producer adapter with synthetic public permission/revision services,
including private/dynamic catalog failures, self-title terminal uncertainty,
unchanged curated zone metadata and alias conflicts. The bounded actual-source
replay is private under `.local/`, uses anonymous API data, and writes no wiki
articles or pack. It is not a new full global scan; full refresh verification is
a separate release gate. The previous global diagnostic and these fixtures do
not by themselves establish the final production Unique count.
