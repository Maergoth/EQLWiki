# Player List and character effect presentation

`Player_List` browses the current public `Template:Character_sheet` snapshots
on root `User:` pages. It is a player-reported directory: rankings compare the
published readings, not a game-server verification of characters or equipment.

The directory supports character/wiki-user search, server selection, **Max level
only**, and all sixteen class checkboxes. **Include every selected class** selects
class combinations; uncheck it to include any selected class. Sort by total
equipped gear tier, level, AA points, **Achievement count**, or any supported
published statistic. Achievement count means completed achievements and starts
highest first; it is one option in the existing Order by dropdown.
Unknown and estimated values are unranked and sort last in either direction.
Equipped-tier sums exclude socketed Exaltations. An equipped item without a valid
reported tier makes the sum unknown; it never silently contributes zero. The
directory shows the reported equipment coverage alongside that sum.
Known achievement counts also appear in gold below the character identity.
An exported zero is a known value; legacy or malformed achievement summaries
remain unknown and sort last in either direction.

## Ownership and data flow

- The existing EQLImmersive skin registers `<eqlplayers />` and the page-specific
  `skins.EQLImmersive.playerList` ResourceLoader module. The reviewed page source
  is `ops/player-list.wiki`. Other pages do not download the directory module.
- The enabled EQLClientData extension provides read-only `action=eqlplayers`.
  The native template-transclusion index supplies candidate page IDs; it does
  not scan account tables or private inventory. Only current, anonymously
  readable, nonredirect root User pages with public revision text participate.
  User talk pages, subpages and deleted/suppressed text do not participate.
- Each keyset batch examines at most 25 User pages (10 by default), with an
  opaque continuation bounded by the initial page-ID ceiling. The frontend
  follows every batch before sorting or claiming a complete ranking. An error
  presents Retry rather than a partial leaderboard. Display pages contain 50
  characters; filters and sorts cover the complete loaded directory.
- `PlayerListRepository` uses the normal anonymous MediaWiki parser output and
  a short extracted-row cache. Root revision/touched changes and checked
  transclusion dependencies invalidate old extraction. Anonymous readability
  and public current revisions are checked again before cached data is used.
  Parser/dependency failures do not become a successful incomplete batch.
- `CharacterSheetSnapshot` reads schema-1 rendered headings, metadata, totals
  and direct equipped tiers. It does not infer stats from item base values or
  Exaltations. Identical duplicate character/server snapshots collapse;
  conflicting duplicate snapshots retain a profile link with unranked values.
  Its optional `achievementCount` integer comes only from the fixed direct
  `.eql-character-achievements-summary` text `N / T achievements complete`.
  Both numbers must be bounded nonnegative integers with N<=T. It never infers
  completed achievements from completed objectives. Missing, duplicate, malformed
  or conflicted summaries return null rather than zero. The extracted-row cache
  schema changes so previously cached rows gain the optional field on next read.
  The API still traverses complete keyset batches; it does not add per-batch
  sorting or change public visibility/continuation behavior.
- `$wgEQLClientDataPlayerMaxLevel` defaults to 50 and controls Max level only.
  Raising a game level cap is an explicit configuration change.

The existing character-sheet module opens a single-character page by default;
multiple sheets retain independent disclosures. A stylized **View Other Players**
link sits below each equipment display and targets `Player_List`. Repeated wiki
content hooks preserve disclosure state and do not duplicate links or listeners.
When an achievement summary is present, both the outer Character header and
inner Achievements header display a large gold serif completed count. It is
visible while those sections are collapsed. Enhancement reads the existing
summary without rewriting the template or User page; dynamic refreshes update
the two badges without duplicating them or resetting disclosure state.

## Existing and future effect metadata

Socketed Exaltations display their name and effect only. Ordinary equipped gear
retains its supplied tier and full stat block. The display does not add socket
stats to observed character totals. Old schema-1 sources remain accepted.

`Itempage.focus_effect` is separate from `statsblock`. The exporter now preserves
that field and literal effect lines as typed `details`, with canonical effect
links. This corrects focus effects such as Diamondine Earring's Affliction
Efficiency I. The deployed wiki producer and its EoZ server-source mirror must
be reviewed together; a Git push does not itself build a new public dataset.

Already-published sheets can fill absent effect labels through a bounded POST
to `action=eqlcharactereffects` (50 unique item titles per batch). The endpoint
uses current, anonymously readable public item wikitext and the same literal
effect extractor as the dataset producer. It returns effect metadata only.
Dynamic or conflicting item cards are left alone. Browser requests are memoized
per page; no polling or hover-triggered requests occur. Existing supplied effect
labels, numeric values and tiers are preserved. No User page is rewritten.

## Verification and release

```sh
php ops/test-player-list.php
php ops/test-character-effects.php
node ops/build-character-sheet-templates.cjs --check
NODE_PATH=/private/jsdom/node_modules node ops/test-player-list.cjs
NODE_PATH=/private/jsdom/node_modules node ops/test-character-sheet.cjs
php maintenance/run.php ./ops/test-character-sheet.php
```

The source-only checks use synthetic pages, permissions and revisions. The
maintenance parser check injects reviewed templates in memory and saves no pages.
Pass its private `--output` HTML to the DOM test's `--html` option to check actual
unsaved parser markup. These fixtures do not prove production installation.

Deploy the reviewed API and skin before publishing `ops/player-list.wiki` as
`Player_List`. Read the current revision immediately before that content change,
retain a fresh private recovery copy and use normal edit-conflict protection;
revision 0 means create only. Publishing the reviewed item-helper template uses
the existing guarded `ops/sync-character-sheets.php`, with fresh reviewed IDs
for both fixed template pages and a new private backup. Do not overwrite User
pages or republish the main character template unnecessarily. Git deployment
alone does not install database pages. No schema migration, new namespace,
historical Magelo extension, account permission or authentication change is needed.
