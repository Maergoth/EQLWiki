# Player List and character effect presentation

`Player_List` browses the current public `Template:Character_sheet` snapshots
on root `User:` pages. It is a player-reported directory: rankings compare the
published readings, not a game-server verification of characters or equipment.

The directory supports character/wiki-user search, server selection, **Max level
only**, and all sixteen class checkboxes. **Any** appears first and starts checked
without a class restriction. Selecting classes requires every selected class in
the character's combination; choosing Any clears those selections. Clearing the
last class returns to Any. Class labels cannot be text-selected, while native
checkboxes and keyboard focus remain available. Any spans both class rows on
desktop; narrower layouts give it a full-width row before the evenly packed classes.
Sort by total equipped gear tier, **Gear Score**, level, AA points, **Achievement count**, or any supported
published statistic. Achievement count means completed achievements and starts
highest first; it is one option in the existing Order by dropdown.
Unknown and estimated values are unranked and sort last in either direction.
Equipped-tier sums exclude socketed Exaltations. An equipped item without a valid
reported tier makes the sum unknown; it never silently contributes zero. The
directory shows the reported equipment coverage alongside that sum.
Known achievement counts also appear in gold below the character identity.
An exported zero is a known value; legacy or malformed achievement summaries
remain unknown and sort last in either direction.

Clicking a complete three-class combination opens its build guide in a new tab.
Guide titles follow the current wiki selector: sort full class names alphabetically,
replace spaces with underscores, join with underscores and append `_Guide`.
The displayed class order is preserved. Incomplete or malformed combinations stay
plain text; links use the wiki's local URL builder without per-row API requests.

## Expand a character without leaving the list

Click a non-link area of an entry to expand that character's published totals,
equipped items, Exaltations and optional portrait below the comparison row.
Keyboard users can focus the row and press Enter or Space. Profile and
build-guide links retain their normal navigation. This is the equipment already
shared on the wiki; it never reads a
player's private inventory, bags or local Eye of Zomm profile. Achievement
completion remains a summary here; the complete achievement list stays on the
profile.

The Character screenshot area sits below the equipment. A published image
appears there; otherwise **Add an image** opens the normal wiki upload page.
Its destination comes from the existing Character screenshot helper, including
any explicit filename override and the native return to the character's User
page. The directory does not invent a filename in the browser or upload a file
on the visitor's behalf. Normal wiki sign-in and upload permissions still apply,
and server-side Character-image ownership checks restrict the destination to
the character's wiki account (with the existing maintenance-right exception).
Shared artwork cannot be claimed through a filename override; see
[Character screenshots](CHARACTER_SHEETS.md#image-ownership-and-upload-completion).
At very narrow inline widths, equipment rows wrap while keeping readable labels
and the existing item target sizes. Ordinary profile layouts are unchanged.

Details are requested only after expansion. Reopening an entry reuses its
in-memory details, and sorting, filtering and moving between results pages do
not request every character's equipment. A bounded cache and request queue keep
rapid browsing from creating an unbounded set of requests or retained profiles.
Failed reads show an explicit retry. Refresh list discards the previous detail
snapshot, and detached or superseded responses cannot fill a different entry.
At most two detail reads run concurrently, each with a ten-second deadline.
The memory cache holds at most 50 responses or 8 MiB and is never persisted.

The directory still follows every summary continuation before showing rankings,
filters and sorts the complete result, then displays pages of 50. Expansion adds
a detail row; it does not count as another character or change a rank. Detail
reads are bound to the public snapshot used by the list. If a profile or its
template dependencies changed, the directory renews that snapshot in place
before opening the newer details. It keeps the active filters and ordering,
without a page reload or a notification asking the visitor to refresh.

The detail API returns bounded typed values, not the complete User page,
arbitrary HTML, executable modules or private content. The browser constructs
the component with DOM APIs and uses the fixed Character-sheet tooltip module.
Inline details do not add another outer Character collapse, a link back to this
same directory, or per-item effect requests. Normal profile pages retain their
existing disclosures and effect enrichment.

`action=eqlplayers&detail=<id>&snapshot=<snapshotToken>` reads one exact
directory record. Summaries include the page revision and a token covering the
rendered snapshot and its checked dependencies. The response contains bounded
typed fields, with a 1 MiB response limit and a short server cache; anonymous
visibility and current revisions are checked again before returning cached data.
A stale token triggers one silent directory renewal per expansion attempt.
Repeated changes cannot start a refresh loop.

## Editable provisional Gear Score

This browser-only benchmark weights published whole-character totals. It does
not estimate equipment-only deltas or normalize class, level, AA or buffs. The
initial ATK weighting favors offense; use the class/max-level filters for closer
comparisons. The existing equipped-tier sort remains the default.

| Required reported stats | Points |
| --- | ---: |
| HP, Mana, Endurance | each × 0.05 |
| STR, STA, AGI, DEX, WIS, INT, CHA | each × 1 |
| Six resists, including Void (`sv_corruption`) | each × 2 |
| Mitigation AC + Evasion AC | each × 1 |
| ATK offense + ATK accuracy | each × 5 |
| Explicit Haste | × 10 |
| If Haste is unavailable: Attack Speed | (reported value − 100) × 10 |

These are the initial settings, not constants in the browser code. Administrators
can edit **[MediaWiki:PlayerListGearScore.json](https://eqlwiki.com/MediaWiki:PlayerListGearScore.json)**
using the normal wiki editor. The reviewed initial source is
[`ops/player-gear-score.json`](../ops/player-gear-score.json):

```json
{
  "schemaVersion": 1,
  "weights": {
    "resources": 0.05,
    "attributes": 1,
    "resists": 2,
    "ac": 1,
    "atk": 5,
    "haste": 10
  },
  "attackSpeedBaseline": 100
}
```

`resources` applies to each of HP/Mana/Endurance; `attributes` to each primary
attribute; `resists` to each of the six resistances. `ac` and `atk` apply to each
corresponding component (or the legacy aggregate under the rules below).
`haste` also weights the Attack Speed fallback. A weight of `0` excludes its
whole group and requires no readings for it. All weights and the speed baseline
must be JSON numbers from 0 through 1000; all listed keys are required and extra
keys or an unsupported schema version invalidate the complete policy. Keep the
page's content model **JSON**. The namespace's normal interface/site-JSON editing
permissions control edits; this feature grants no new permissions. See MediaWiki's
[interface administrator documentation](https://www.mediawiki.org/wiki/Help:User_rights_and_groups#Interface_administrators).

Use **Refresh list** or reload after saving. One bounded read of that fixed public
page loads the current settings before each complete directory load; concurrent
mounts share an in-flight request. There is no polling or browser-persisted policy.
Each complete ranking uses one validated revision, including its display and
per-player contribution breakdown. The formula disclosure links the settings and
shows that revision. Settings edits need no Git deployment, EOZ update or export.
If the page is missing, hidden, malformed, not JSON, or cannot be read, Gear Score
alone stays unavailable while other directory filters/sorts work. There are no
silent hardcoded defaults. The request has a ten-second network timeout.

The default 100% speed baseline is a declared **point-system policy**, not a conversion
to a Haste stat; no snapshot value, label or wiki source is changed. Explicit
Haste zero wins over Attack Speed. Negative readings retain signed points.

Legacy AC/ATK may replace the corresponding **entire** component pair only when
both components are unavailable. A partial pair never mixes with or falls back
to an aggregate. AC softcap, regeneration, DPS, other derived bonuses, tiers,
progression and achievement counts add no separate points.

All readings required by enabled groups must be numeric; missing, estimated, conflicting or
nonfinite totals remain unranked rather than contributing invented zeros.
Rankings use unrounded totals, with rounding only for display and stable ties.
Selecting Gear Score shows a keyboard-accessible formula disclosure. Score cells
explain their contributions or missing readings in their title; legacy scoring
is labeled. This calculation uses the complete directory and the single public
policy read. It adds no cache schema changes or User/template writes.

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
NODE_PATH=/private/jsdom/node_modules node ops/test-player-list-details.cjs
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

For the initial editable-score rollout, separately create
`MediaWiki:PlayerListGearScore.json` with content model `json` from the reviewed
`ops/player-gear-score.json`. This is a fixed interface-page publication, not a
file deployed from `ops/`. Review the current target (revision 0 means absent),
retain a fresh private recovery record, verify the ordinary owner's page and
site-JSON edit permissions, and use edit-conflict/create-only protection. Preserve
an existing page rather than resetting tuned weights during later releases.
Future tuning belongs on that wiki page; do not republish the initial example.
