# EQLWiki custom component map

This map was audited on **October 7, 2026** against the tracked custom code,
sanitized configuration, public live extension/skin inventory, and public
`MediaWiki:` interface scripts. It covers custom entry points and known upstream
integration patches; it is not a line-by-line comparison of bundled MediaWiki,
SMF, or vendor files against pristine upstream releases.

Read [the development guide](EQL_DEVELOPMENT.md) for setup/checks and
[PROJECT.md](../PROJECT.md) for host operations. Recheck registration/configuration
when changing environments: a directory in Git is not proof that it is enabled.

## Code, content, and generated data

| Layer | Source of truth | Examples |
| --- | --- | --- |
| Application files | Git plus loaded registration/hooks | Custom extension PHP, EQLImmersive modules/templates, forum bridges |
| Wiki content/configuration | Database pages and revision history | Itempage/Spellpage templates, Lua modules, categories, VerifiedPages, Template:PageEra, SpellLevelSliderOverrides |
| Interface scripts/styles | Database `MediaWiki:` pages | Common.js/CSS, BlueprintLoader.js, IconFinder.js, ClassGuideDropdown.js |
| Private environment | Ignored settings and owner-installed host files | SQL/bridge credentials, EQLStaging.php, cron/deploy receiver copies |
| Mutable/generated assets | Uploads and cache generation | Wiki images, forum inline images, static icon catalog/index, Eye of Zomm datasets |

Normal file deployment does not publish arbitrary database pages, install private
host scripts, copy the local database to production, or run schema migrations.
Use the guarded sync helpers where available; do not assume a Git source mirror
is the currently executing database script.

## Active skin, layouts, and search

[`skins/EQLImmersive/skin.json`](../skins/EQLImmersive/skin.json) registers
`eqlimmersive`, the default skin. Its PHP class
[`SkinEQLImmersive.php`](../skins/EQLImmersive/includes/SkinEQLImmersive.php)
extends Vector 2022; Vector remains a required loaded dependency.
[`Hooks.php`](../skins/EQLImmersive/includes/Hooks.php) and
[`skin.mustache`](../skins/EQLImmersive/includes/templates/skin.mustache) own
the custom layout and conditional resources. See the
[skin guide](../skins/EQLImmersive/README.md) for module details.

Major custom skin behavior:

- [`HomepageCache.php`](../skins/EQLImmersive/includes/HomepageCache.php) bounds
  Main Page and main-namespace `News` parser output, conditional browser validation
  and CDN expiry to shared five-minute UTC windows, preserving private responses.
  Saving News immediately purges Main Page through native MediaWiki cache handling;
  no revisions, cron job or client polling. Existing announcement collapse/read
  markers remain local and independent. See the
  [skin guide](../skins/EQLImmersive/README.md#main-page-and-news-freshness).

- `resources/copy-waypoints.js/css` (`skins.EQLImmersive.waypoints`) enhances
  rendered article coordinate pairs/triples with accessible inline copy buttons.
  Signed/decimal coordinates retain their displayed order and copy with `/way`.
  Gold hover/focus and temporary **Copied** feedback are client-only; code,
  editors, diffs and ordinary links remain untouched. Single numeric `Template:Loc`
  links copy on normal clicks while retaining map/modified-click navigation. The `wikipage.content`
  hook supports dynamic sections without duplicate buttons. See the
  [skin guide](../skins/EQLImmersive/README.md#copyable-waypoints).

- [`DeploymentAssets.php`](../skins/EQLImmersive/includes/DeploymentAssets.php):
  reads the archive-managed release marker at skin registration, selects a
  query-free versioned local loader path and updates HTML Last-Modified validation.
  `.htaccess`/the local router forward these paths to native ResourceLoader;
  [deployment asset caching](DEPLOYMENT_ASSETS.md) describes packaging and checks.

- The registered navigation hook uses **Talk** and **History** across wiki skins.
  EQL page-tool/sidebar links and overflow menus share those labels. In EQL
  Immersive, the subject tab stays first and Talk immediately precedes Read,
  with a thin leading divider on Read, followed by editing, history, and watch
  actions; other skins retain their native placement. The divider stays visible
  on phones and is omitted only when Read is absent. At 640px or less, the full
  page-action toolbar occupies a separate row and wraps as needed. All available
  page-action buttons remain visible; responsive styling does not hide them.
  The primary Talk ID stays `ca-talk`; its overflow copy uses `ca-more-talk` and
  shares the unread count. Navigation URLs are unchanged.

- Skin hooks provide Verify2Edit for named users with unconfirmed email when
  the page's edit denial requires email confirmation,
  Watch/Unwatch header links, and expansion of `__EQL_PAGE_TOOLS__` and
  `__EQL_ADMIN_TOOLS__`. Admin menus check sysop/bureaucrat/interface-admin
  membership plus actual action permissions.
- `resources/main.js`: movable/pinned main menu, user menu, clickable category
  cards, the custom TOC built from article headings, `toclimit-N` markup, removal
  of empty build-ability panels, and `File:Connections.png` map magnification.
  Native Vector TOC is disabled. Preserve menu storage migrations and layout slots.
- The site header remains locked while scrolling; measured height controls
  fragment and menu offsets. `SkinEQLImmersive.php` omits Vector's duplicate
  header and enabled class before rendering, preventing native scroll behavior
  from taking over the original header. Final navigation processing after extension hooks
  exposes one source-first Edit action while retaining editor switching and
  Verify2Edit. `todo.js/css` supplies the header todo icon and confirmed title/
  heading capture, source links, collapsible entries, sanitized rich-text notes,
  nested note checklists and optional progress. `todo-store.js` owns the global
  `userjs-eql-todo-v1` account option and separate wiki-scoped anonymous storage;
  autosaves wait two seconds after the last change, including changes made
  during an in-flight save. It never imports anonymous lists into an account
  or touches Common.js buckets.
  See the [skin guide](../skins/EQLImmersive/README.md#header-and-personal-todo-list)
  for limits, draft recovery and concurrent-write limitations. Watchlist remains
  in the user menu; only its direct header shortcut is replaced.
  The heading plus and section-edit pencil appear on hover or keyboard focus;
  devices without hover keep both controls visible for touch access.
- `main.css`, `header-actions.css`, `sidebar-redlinks.css`, `responsive-mobile.css`,
  and related modules: game-themed shell, article/table layouts, mobile handling,
  fixed footer, and menus. Database Common.css adds further overrides.
- [`talk-unread.js/css`](../skins/EQLImmersive/resources/talk-unread.js), loaded
  as `skins.EQLImmersive.talkUnread`: green Talk counter for unseen revision
  changes, capped at `99+`, with a subtle swirl that respects reduced motion.
  `Hooks.php` supplies the associated Talk title and displayed revision metadata;
  bounded read-only API queries resolve Talk redirects and check readability.
  A visible current Talk view records only its displayed revision. Old revisions,
  diffs, editors, and background tabs do not clear unseen changes. Browser-local
  `eql-talk-seen-v1` markers are separated by wiki, account/anonymous identity, and
  canonical Talk title; they neither sync across devices nor use Common.js state.
  Missing/unreadable pages and API failures hide the counter. See the
  [skin guide](../skins/EQLImmersive/README.md#unseen-talk-changes) for the contract
  and [MediaWiki Revisions](https://www.mediawiki.org/wiki/API:Revisions) /
  [Info](https://www.mediawiki.org/wiki/API:Info) for API mechanics.
- `announcements.js`: collapse state and recognition of new announcement entries.
  `perf-widget.js`: registered-user diagnostics and page statistics.
- [`SearchMySQL.php`](../skins/EQLImmersive/includes/SearchMySQL.php): registered
  through `SearchMappings`; title completion uses a case-insensitive whole-title
  prefix, including later words, escaped LIKE input, and first-four-character
  ASCII index bounds. Submitted plain title queries of at least three characters
  use matching prefix candidates consistently across result pages (`Raku` finds
  `Rakusha Cloak`). Advanced/short/unmatched queries retain native title search;
  body/full-text search still uses the parent MediaWiki backend.
  `header-search.js` uses native Vector/MediaWiki suggestions and measures the
  space left by header navigation/account controls. The inline field shrinks to
  76px; below 180px it hides the submit button and reduces icon padding, with
  Enter still available. If the field cannot fit, a centered 38px icon opens the
  overlay. ResizeObserver responds to badge, account, tool, and font width changes;
  resizing preserves the input value and caret. At 640px or less, search, era,
  and account controls use the upper row above the wrapping page-action toolbar.
  At 980px or less, account overflow links use the existing user menu only after
  it initializes. Actual throttle constants are three characters and one second
  idle, despite stale comments. Check `guard a`,
  `Cat F`, mixed-case later words, namespace searches, and Enter/keyboard selection.
- `selection-search.js/css` offers a native `Special:Search` link for a short
  selection of article text. It excludes editable/control/hover regions, sends no
  request until followed, and uses the same wiki search as the header.

Preserve the rendered markers in the following table. Changing a template's
class can silently stop its skin module from loading:

| Rendered marker | Skin behavior |
| --- | --- |
| `class-guides-dropdown` | Loads database ClassGuideDropdown.js |
| `eql-mobpage`, `eql-factionpage` | NPC/faction layouts; NPC Known/Unique/Common loot uses stable grid cells so embedded item hovers cannot rebalance columns |
| `eql-spellpage`, `eql-spell-lazy`, `eql-spellpage-items` | Spell layouts and tools |
| `itemeff`, `merchant-page-items-sold`, `spell-hbdiv` | Effect/merchant/spell hover tools |
| `eql-lucy-images-grid` | Icon-reference layout |
| `id="eql-icon-catalog"` | Paginated Icon List |

`wikipage.content` is the normal integration point for dynamically inserted
parsed fragments. Preserve refresh hooks so hovers/sliders/state attach after
lazy loading, not only on the initial document.

## Optional talk contribution policy

[`extensions/EQLTalkContributions/`](../extensions/EQLTalkContributions/) is a new
optional extension, absent from the audited live inventory. The sanitized
settings example includes its activation block; ignored environment settings
must be updated separately before it runs.

`extension.json` registers `includes/Hooks.php` for `getUserPermissionsErrors`
and `AuthChangeFormFields`. With the documented configuration, anonymous visitors
and named users without confirmed email may edit/create talk pages, while every
subject namespace requires a named account with confirmed email. Magelo talk
namespaces 501/503 use the same policy. Existing namespace/page protection,
blocks, sessions, CAPTCHA, and rate limits remain enforced by MediaWiki. Automatic
temporary accounts are disabled in the activation block so anonymous revisions
retain IP attribution. The signup form still requires email, and the skin leaves
usable talk edit links available to unconfirmed named users.

See the [extension guide](../extensions/EQLTalkContributions/README.md) for exact
settings, verification, and rollback ordering. Restoring the prior restrictions
before unloading the hook is required to avoid opening subject-page editing.
Staging's independent login gate remains active and limits anonymous browser
testing. Standard permissions are described in the [MediaWiki user rights manual](https://www.mediawiki.org/wiki/Manual:User_rights).

## Metadata, era filtering, and verification

[`extensions/EQLClientData/`](../extensions/EQLClientData/) is enabled (extension
version 1.1.2 at the prior live audit; 1.2.0 source adds optional observations). Start with `extension.json`, `includes/Hooks.php`,
`includes/ApiMetadata.php`, and `includes/ConfigRepository.php`.

- The read-only `action=eqlmetadata` API requires **POST**. Pipe-delimited titles
  are capped by `EQLClientDataApiMaxTitles` (configured 500). Skin requests batch
  450 titles; retain the response/normalization contract.
- Optional `action=eozobservations` stores named-session/CSRF-protected, attributed
  kill/loot data with actor/event idempotency and hard quotas. It is disabled by
  default and requires three separately installed own tables. It does not edit
  articles or publish drop rates. Standard MediaWiki `action=edit` handles page
  creation/updates. [API, schema and activation contract](CLIENT_API.md); the
  reviewed public `API_Documentation` database page has its own guarded publisher.
- Metadata comes from database `VerifiedPages`, `Template:PageEra`, and
  `SpellLevelSliderOverrides`. Revision-aware WANObjectCache entries and
  save/move/delete invalidation keep data fresh. Era results also depend on page
  ID and `page_touched`.
- Era, verification, and spell overrides are all enabled in the sanitized
  configuration. Server JS configuration injection is specific to EQLImmersive.
  Verification's configured skin module is loaded by this extension's hook.
- `skins/EQLImmersive/resources/era-filter.js` supports Off, On, Outline, and Hide, defaults On,
  deduplicates requests, and uses revision-scoped browser caching plus the
  configured 300-second TTL. Category queries remain a fallback.
- Era overlays must exclude editor controls, toolbar/history/watch links,
  edit-section links, footer, VisualEditor/OOUI overlays, and nonarticle URLs.
  [`ops/test-era-controls.cjs`](../ops/test-era-controls.cjs) protects these rules.
- `skins/EQLImmersive/resources/verified-pages.js` displays/minimizes the unverified-page banner.
  Verification updates the wiki-managed VerifiedPages list through normal edit
  permissions; it is not a new independent identity/approval system.

When renaming the spell override page, keep both
`EQLClientDataSpellOverridesTitle` and `SpellLevelOverridesPage` aligned.

## Equipment, dynamic lists, and tooltips

These extensions use legacy PHP registration (`require_once`) rather than
`wfLoadExtension()`. Their `*_body.php` implementations parse real wiki
content; historical template fields and rendered structures are contracts.

| Enabled component | Entry point / behavior | Development quirks |
| --- | --- | --- |
| [`ClassSlotEquip`](../extensions/ClassSlotEquip/) | `Special:ClassSlotEquip/Class/Slot`; builder on `Equipment By Class`; AJAX `eqlBuilder=1` or legacy `eqlTrio=1` with class/slot/stat/weapon parameters | Category intersections and Itempage fields supply stats, sources, and effects. Selected classes/virtual weapon slots form unions. Current category joins use `cl_target_id`/`linktarget`, not obsolete `cl_to`. `classslotequip-builder.js` reuses ItemLevelSlider output instead of implementing independent formulas. Browser state: `eql-equipment-builder-v2`. |
| [`AjaxHoverHelper`](../extensions/AjaxHoverHelper/) | `Special:AjaxHoverHelper/Page?type=item\|spell\|mob` returns bare tooltip HTML | Extracts balanced Itembox/Itempage, Spellpagesmart/Spellpage, or Namedmobpage/mobStatsBox output. The skin's item hover module consumes it; effect/merchant hovers use Action API parsing of SpellHoverLink/Module:SpellHover instead. Its bundled `eqlwiki.js` is not registered by the extension; avoid duplicate binding/container code. |
| [`DynamicZoneList`](../extensions/DynamicZoneList/) | `{{Special:DynamicZoneList/Zone Name}}` creates zone tables; native NPC loot and item Drops From sections also resolve reciprocal sources | Legacy zone names remain supported; a new canonical public main-namespace page with its own active Category:Zones declaration and matching membership is also admitted, without inventing map IDs or raid filtering. Independent public lookups verify actual `Itempage.dropsfrom` and `Namedmobpage.known_loot/common_loot` fields, preserve native zone lists and instance scopes, analyze affected-field exact/broad/unsupported coverage, resolve finite public collections and canonical zone/NPC duplicates and purge affected caches after saves/link updates/moves/deletes. Generated NPC rows show item names only; items with more than five distinct authored monster sources default to Common Loot unless manually classified on the NPC. See [reciprocal drop links](NPC_RECORDED_LOOT.md) for bounds and parser limits. |
| [`DynamicQuestItemList`](../extensions/DynamicQuestItemList/) | `{{Special:DynamicQuestItemList}}`, optional category subpage; `dqil_action=chunk` API-like endpoint | Renders a filter/table shell then sequentially fetches 500 rows per chunk. Completed HTML caches 12 hours in localStorage (sessionStorage fallback). `dqil:v8` key includes category/count/limit, **not item revisions**: unchanged membership can leave edited rows stale. Emits `wikipage.content`; preserves Notes/Lore without adding unwanted categories to the listing page. |

The hover stack uses `span.ih a`, a shared `#itemHoverContainer`, `itemeff`,
merchant and spell markers. `spell-effect-hover.js` parses SpellHoverLink lazily;
`merchant-spell-hover.js` hydrates merchant spells together. Keep tooltip markup
and API output aligned when changing a template or a parser.

Interactive player snapshots use the reviewed `Template:Character_sheet` and
`Template:Character_sheet_item` database sources under `ops/`, plus the existing
skin's `skins.EQLImmersive.characterSheet` module. A ParserAfterParse hook adds
the module only for rendered schema-1 markup, including unsaved API previews.
Each character expands independently; supplied tier-specific gear/augment stats
and links remain distinct from the base-item AjaxHoverHelper. No historical
Magelo extension is enabled. Optional totals include ten observed Additional
Modifiers; accuracy bonus is distinct from ATK Accuracy. The template only
displays the supplied equipped-stat reading and never adds item/catalog stats.
See [the contract, separate guarded publication and
checks](CHARACTER_SHEETS.md); Git deployment does not publish the templates.

## Item and spell levels

`Template:Itempage` delegates article and tooltip stat blocks to the database
`Template:Itembox`. Reviewed source is now tracked in
[`ops/itembox.wiki`](../ops/itembox.wiki), based on live Itembox revision 157242.
Its existing Rogue-usable piercing Backstab default remains unchanged. Missing
weapon damage bonuses are generated at character level 50 using the Dual Wield
calculator's working formula and floor display rounding: 0.8 for one-handed
melee weapons, 1.1 for two-handed weapons, the greater of damage and character
level, and delay capped at 50. Generation requires PRIMARY, a supported melee skill,
positive standalone DMG/DAMAGE, and positive Atk Delay. An existing DMG Bonus,
Dmg Bon, Damage Bonus, or Damage Bon field wins regardless of case or value
(including zero). Generated values carry `.eql-generated-damage-bonus`, use
listed base stats, and remain independent of the item-rank slider.
They are rendered defaults, never written into item articles. Publishing requires
the guarded [`sync-itembox.php`](../ops/sync-itembox.php); application deployment
alone does not update the template. See the [development guide](EQL_DEVELOPMENT.md)
for read-only parser checks and [operations guide](../PROJECT.md) for publication.

[`ItemLevelSlider`](../extensions/ItemLevelSlider/) loads `ext.itemLevelSlider`
broadly through legacy PHP registration and attaches to compatible item markup.
The shared pure engine is `item-scaling-core.js`, with all coefficients in
`item-scaling-rules.json`. `itemlevelslider.js` owns only template parsing and
presentation. The wiki injects the tracked JSON into page config. Eye of Zomm
vendors the exact engine and rules from a reviewed wiki commit during release
preparation; an installed app version keeps those packaged formulas and never
polls for rules or updates them while running. Math changes require a new app
version after source synchronization. See the
[shared engine guide](../extensions/ItemLevelSlider/README.md). Do not infer
formulas from a displayed slider position. Backstab scaling and weapon accuracy were introduced in the
official [September 22, 2026 patch notes](https://www.everquestlegends.com/patch-notes/eql-update-notes-9-22-2026).
The exact rates and fractional-progress curves below are working models inferred
from item screenshots, not published game formulas. Important contracts:

- `.itemtopbg` followed by `.itembg .itemdata` and `.itembotbg`; original stats
  are retained in `data-ils-*`. Dynamic content can call
  `window.eqlItemLevelSliderRefresh(root)`.
- Damage rows display DMG, damage bonus, then Ratio. The slider moves the generated
  bonus span or wraps an existing plain-text bonus in `.ils-damage-bonus`, retaining
  its label, value, and level annotation. Rank changes leave the bonus unchanged.
- Levels cap at 10; fractions use `2^fullLevel`, with a compressed visual slider
  curve. Standalone weapon damage scales; elemental/bane damage and weapon delay
  do not. Positive `BACKSTAB`, `BACKSTAB DMG`, and `BACKSTAB DAMAGE` values use
  `B + floor(B * effectiveRank / 20)`, where `B` is that field's original value
  and `effectiveRank = fullLevel + fraction / 2^fullLevel`. Explicit Backstab
  values remain independent of ordinary weapon DMG; zero/negative values stay
  unchanged. Fractional Backstab interpolation remains provisional.
- Positive primary stats above 10 use
  `floor(B + B * effectiveRank / 10)` in rules revision 2; values from 1 through
  10 gain only the whole rank. Negative penalties retain their separate rounding.
  The [engine guide](../extensions/ItemLevelSlider/README.md) records the
  screenshot evidence and the unresolved Vambraces AC observation; do not add
  an item-specific exception or infer a new base value from it.
- Rules revision 3 retains the weight curve and display ceiling, but calculates
  whole-rank decimal boundaries with checked integer remainders. This prevents
  floating-point residue from adding a tenth (base 2.5 at rank 8 is 0.7).
  Nonzero fractional ranks keep the existing logarithmic calculation.
- Generated SV Void requires two distinct known primary or HP/Mana/End regen
  fields, including known zero fields. AC, HP, Mana and Endurance qualify
  alongside attributes and the five ordinary resists. Haste, Attack, weapon
  DMG, Backstab, delay and weight do not qualify. Eligible cards gain +1 per
  whole rank, hidden at zero; explicit Void/Corruption suppresses generation.
- Melee and ranged weapon cards with positive standalone DMG gain an Accuracy
  row inside `.itemdata`, near DMG/Ratio. AMMO-only cards are excluded. The gain
  is `2.5 * log2(2^fullLevel + fraction)` percentage points, displayed with a `+`
  and truncated down to one decimal with trailing `.0` removed. This reproduces
  the screenshot values `+10.2%` at `4 + 1/16`, `+11.4%` at `4 + 8/16`,
  `+12.9%` at `5 + 4/32`, and `+20%` at `8 + 1/256`; the fractional curve is
  inferred. The generated row is hidden at rank zero.
  Existing percentage Accuracy adds the gain to its original value; an existing
  nonpercentage Accuracy field is preserved and suppresses a generated duplicate.
- Every listed `Range:` value gains +10 per whole level,
  covering bows, throwing weapons (including those with melee skills), and ammo;
  fractional progress does not add range. Items without `Range:` gain no field.
  Negative penalties, weight, regen/haste, and eligible SV Void have distinct
  rules. Preserve base values to avoid scaling twice.
- Defaults persist in `ils-default-level-v2` with legacy `ils-default-level`
  migration. Legacy PHP unconditionally assigns its configuration before hook
  registration, so disabling it before `require_once` is not equivalent to the
  guarded SpellLevelSlider configuration.

[`SpellLevelSlider`](../extensions/SpellLevelSlider/) has independent spell rules,
not the item formulas. It loads for spell-card/lazy/item-spell markup, expects
`.eql-spellpage-slot-table` and `.eql-spellpage-detail-table`, and supports whole
levels 0–10. Class spell-list table mode is intentionally separate.

`SpellLevelSliderOverrides` contains a `<pre id="spell-level-slider-overrides">`
mapping of page title to category. Categories include summoned pets; explicit
effectiveness caps in Overview/effect cells increase +1 per rank independently
of category (including charm, mez, calm/pacify, and stun). Recognized shorthand
includes `up to LX` and the cap in Mesmerize/Frenzy Radius/Reaction Radius pairs.
Overview highlights apply only to changed cap numbers, preserving surrounding
description styling and restoring original markup at rank zero.
Pet levels and cast/mana/duration values retain their separate rules. Recast
time and resist adjustment await confirmed rates (issue #22).
The client first uses the EQLClientData-injected map, then a raw-page fallback
cached 30 seconds. State key: `sls-default-level-v1`. Integrations can call
`eqlSpellLevelSliderRefresh(root)` or `eqlSpellLevelSliderSetLevel(level, root, persist)`.
See the [extension guide](../extensions/SpellLevelSlider/README.md) for formulas.

## Editor tools, Icon Finder, and Icon List

[`extensions/EQL-Editor-Tools-Icon-Finder/`](../extensions/EQL-Editor-Tools-Icon-Finder/)
contains reviewed database-script sources, not a `wfLoadExtension` registration.
The Common.js loader imports `MediaWiki:BlueprintLoader.js` during source
edit/submit. BlueprintLoader discovers sections/`<pre>` blocks in `Help:Contents`,
offers a selector for a new truly empty source page, and inserts the chosen
blueprint in the source textarea. Existing source pages can use Icon Finder
without the blueprint selector; VisualEditor is a separate integration.

The versioned [shared zone blueprint](ZONE_PAGE_BLUEPRINTS.md) is reviewed in
[`ops/zone-page-blueprint.wiki`](../ops/zone-page-blueprint.wiki). It preserves
the opening `zoneTopTable` and Map, with an independently confirmed optional
Raid Instance overview and Raid Map. The guarded
[`sync-zone-page-blueprint.php`](../ops/sync-zone-page-blueprint.php) changes only
that Help:Contents subsection, defaults to read-only review, and requires a
fresh private full-page backup plus revision guards for explicit publication.
It leaves the City and all other blueprints unchanged. The live BlueprintLoader
needs no script change because it already extracts the section's first pre block.

The active finder is database `MediaWiki:IconFinder.js`, lazy-loaded by
BlueprintLoader. It does not request/download the icon catalog until an image is
pasted. Current behavior is algorithm 6, a 64-bit dHash plus 16×16 RGB grid,
100 ranked candidates, 12 rendered per batch, 80px source/result images,
image-click ID copying, and a corner disclosure for other copy formats.
It never edits the article. Clipboard API failures fall back to a copy prompt.

The preferred path reads `/static/eql-icon-index/meta.json` and an immutable
`index-<generation>.json`, then stores fingerprints in IndexedDB
(`eql-icon-finder`, store `indexes`). Static-index errors retain the browser-build
fallback, whose catalog combines Icon List media and all Item_/Spellicon_ uploads.
The generation/upload signature matters, not just the Icon List page revision.

The browser fallback can retain separate filename records for repeated artwork;
the grouped aliases described below belong to the preferred static server index.

The owner-installed generator is
[`ops/host-bin/eql-icon-static-builder.php`](../ops/host-bin/eql-icon-static-builder.php)
with [`eql-icon-catalog.php`](../ops/host-bin/eql-icon-catalog.php). Separate outputs:

- `index-<generation>.json`: one record per exact artwork group, with every
  original filename/ID retained as an alias. Deduplication compares dimensions
  and visible RGBA pixels, ignoring hidden RGB only where alpha is zero.
  Similar perceptual hashes are **not** enough to merge records.
- `catalog-<generation>.json`: lightweight metadata for **every filename**,
  including aliases. The skin's `icon-list.js` renders at most 100 lazy-loaded
  images, pages through the catalog, supports exact IDs and item/spell libraries,
  and falls back to paginated `allimages`. It does not read the fingerprint index.

[`ops/icon-list.wiki`](../ops/icon-list.wiki) is the reviewed database page source.
`sync-icon-finder.php` and `sync-icon-list.php` require expected revisions and
private recovery copies. The two-minute host cache job detects upload/replacement/
deletion changes even when Icon List is unchanged. See [PROJECT.md](../PROJECT.md)
for the audit/import/cache workflow and tests.

Never renumber or overwrite existing icons to match a new pack without an
explicit reviewed data change. Preserve `Item_<ID>.png`, existing references,
spell letter IDs, and alias-specific copy parameters. The supplied game library
and audit/import manifests stay outside Git. Screenshots with a mismatched
background or surrounding UI can affect matching; use a cropped square icon
with minimal surrounding UI rather than assuming all backgrounds are removed.

## Database-managed browser features

The public [`MediaWiki:Common.js`](https://eqlwiki.com/MediaWiki:Common.js) and
[`MediaWiki:Common.css`](https://eqlwiki.com/MediaWiki:Common.css) remain active.
Only some sections have tracked sources; do not clear these pages on the
assumption that the skin contains everything.

| Common.js feature | Contract / behavior |
| --- | --- |
| `EQLUserState` | Per-page account JSON through API:Options (`userjs-eql-state-v2-p<page hash>`); anonymous localStorage (`eql-user-state-v2:anon:`). Existing account buckets are authoritative. Anonymous/legacy values import once only when an account page bucket has never existed. Await `ready()` before restoration; use `has/get/set/remove/hashKey/getAll` rather than bypassing the service. |
| `EQLPersistentContent` | Binds `.checkbox-list li`, `.eql-sky-reward-input`, `.eql-persist`, and `[data-eql-persist]`. New fields need stable explicit keys/IDs/names; mutable tooltip/stat text is unsuitable identity. |
| Raid Instance view | A “Raid Instance” heading triggers Normal/Raid section switching, rose-gold styling, and shareable `?raidview=1`. |
| Blueprint loader import | Loads database source-edit tools; the complete Common.js is not mirrored by the loader snippet. |
| Skill_Dual_Wield calculator | Page-gated combat/weapon comparison models, class/level caps, and persisted Ambidexterity/Burst toggles. Comments identify provisional skill progression/rounding assumptions; changing the model requires gameplay evidence. |
| Plane of Sky rewards | Stable item-title identities and migration of old persisted keys; tracked [`sky-rewards.js`](../skins/EQLImmersive/resources/sky-rewards.js) is this section only. Guarded sync replaces the section and preserves the rest of Common.js. |
| Zone_Connection_World | Fixed map viewport and persistent click-to-toggle 2× view. Skin main.js still owns Connections.png lookup/magnifier behavior; these are separate from the 3D Zone Viewer. |

Common.css supplies further mob loot/mobile layouts, homepage fixes, performance
overrides, ornate heading/raid styling, spell wrapping, tooltip overflow, page
transitions, and Sky reward styles. Inspect it when skin-only CSS changes appear
ineffective. `MediaWiki:ClassGuideDropdown.js`, loaded by `classguide-loader.js`,
is another database-owned feature without a complete tracked canonical copy.

## Zone Viewer and derived datasets

[`EQLZoneViewer`](../extensions/EQLZoneViewer/) is enabled (1.15.0). PHP under
`src/` exposes `Special:ZoneViewer` and `<zoneviewer height="720px">` markup;
the lightweight bootstrap loads the larger viewer only when needed.

Local EverQuest S3D/EQG/maps data stays in the visitor's browser. Secure-context,
directory picker/folder-input, WebGL2, browser permissions, IndexedDB caches,
coordinate conversion, and worker behavior are part of the feature contract.
The detailed [README](../extensions/EQLZoneViewer/README.md),
[usage guide](../extensions/EQLZoneViewer/docs/USAGE.md), and
[world-map guide](../extensions/EQLZoneViewer/docs/WORLD_MAP.md) cover geometry,
collision, navigation, map aliases, and browser limitations.

Authored sources are `build/src/viewer.js`, `parser-worker.js`, and
`navigation-worker.js`. The [build](../extensions/EQLZoneViewer/build/README.md)
applies pinned sage-core overrides and produces **three** bundles in
`resources/dist/`. Do not hand-edit generated bundles. Keep VERSION/manifest,
viewer markup asset versions, and worker URLs consistent when releasing changes.

[`maintenance/BuildEyeOfZommPack.php`](../maintenance/BuildEyeOfZommPack.php) and
[`zone-catalog.json`](../maintenance/zone-catalog.json) are separate
wiki-data/asset export tooling, not a browser upload flow. The
optional `viewerId` on a catalog record preserves an explicit archive preference
for ambiguous display labels. Clients retain a verified logged short name before
this preference; the producer exports the catalog metadata unchanged. The builder
reads the local wiki database/assets without calling its own HTTP server and
requires an output JSON file path, for example `--output=.local/eoz/eye-of-zomm-pack.json`.
The private `eoz-pack-refresh.sh --if-stale` checks hourly and validates/publishes
a dataset after at least 24 hours since its last successful GitHub publication;
`eoz-sync-github.sh` manages the separate **Maergoth/EQL-EOZ dataset branch**.
Its force-pushed data branch is independent of this repository's protected
application branches. The private `eoz-pack-last-success` epoch advances atomically
only after synchronization succeeds; malformed/future markers trigger a rebuild.
Default/manual runs force a build, and `--local-only` always builds without
publishing or advancing freshness. An unpublished staged manifest never counts as
a success. The desktop checks the public manifest at startup/hourly and replaces
its cache only after a changed pack passes validation. Both staging and the public
export derive from production; SQL/account/settings data never goes to the public
dataset. See [the shared timing, failure, and installation policy](../PROJECT.md#unified-data-refresh-policy).
Its separate JSON validator sets a `768M` CLI bound and reads code from stdin to
avoid cPanel selector filename checks on multiline arguments. A failed validation
preserves the existing pack; source-only synthetic wrapper coverage lives in
[`ops/test-eoz-refresh-wrapper.sh`](../ops/test-eoz-refresh-wrapper.sh).

The producer exports explicit usable class codes for Itembox `Class: ALL except`
restrictions. `ALL` means unrestricted, `NONE` means no playable class, and an
empty class list means missing/unknown metadata. Literal weapon stat fields
export `BACKSTAB_DMG` for `BACKSTAB`/`Backstab DMG`/`Backstab Damage`, `DELAY`
for `Delay`/`Atk Delay`/`Attack Delay`, and `DMG_BONUS` for the written damage
bonus aliases. Explicit zero values survive; missing fields are not inferred
from damage or tier. A filtered dataset rebuild is required to deliver these
fields to clients. Its zone catalog resolves exact
wiki category, field, and title-suffix aliases while retaining existing canonical
viewer names. Recognized explicit NPC template zones take precedence and retain
all listed zones; categories are a fallback, then the article-title suffix.
Stale categories must not override explicit fields or discard a second zone.
An exact literal NPC `zone = Various` additionally exports `zoneScope: various`
on that template's records. It includes a client's current zone without inventing
additional map locations; concrete category rows remain intact for dynamic lists.
The marker does not cross normal/raid template scopes or apply to surrounding prose.
NPC `categoryZones` separately retains verified concrete zone categories, because
DynamicZoneList's NPC table uses the zone/NPC category intersection even when an
explicit spawn field names another zone. This is presence evidence for the normal
dynamic list, not a replacement spawn/stat identity or a raid-membership claim.
Unknown zones remain unmapped unless a categorized zone article with a Raid
Instance section establishes a new canonical zone. NPC locations keep the wiki's
displayed `Y, X, Z` order. Source-only synthetic coverage lives in
[`ops/test-eoz-exporter.php`](../ops/test-eoz-exporter.php).

Raid discovery reads `Category:Zones` articles and latches onto `Raid Instance`
headings at any wiki heading level; it has no raid-zone allowlist. Monster/boss
table fields establish membership within that section. Entrance prose, unrelated
links, nested NPC-template descriptions, and later sibling sections do not.
Explicit raid-only annotations, respawn fields, and `Raid Encounters` categories
establish exclusivity; explicit per-template sections take precedence. NPC and
individual item-source records expose `raidInstance`, `instanceType`, and public
`instanceSources` provenance. Identity includes zone/name/instance variant. Shared
NPCs keep both variants, and derived raid copies omit the public spawn location.
For an identical zone/name pair, raid records precede normal records so older
Eye of Zomm clients that collapse the pair with last-record-wins retain normal
coordinates and stats. Variant-aware clients still keep both identities.
Item source sections preserve both variants without marking a whole item raid.
Drop-source parsing unwraps the presentation-only `VeliousGray` template and
ignores `Era`/`Loc` annotations. NPC identity comes from the actual wiki-link
destination, preserving its drop/stat-page association across display labels and
revamp notes. Wrapped zone headings still establish the next source list's zone;
real nested NPC bullets remain valid, while coordinate-only bullets, file links,
and unresolved templates cannot become monsters. Plain non-NPC sources such as
Ground Spawn remain item sources. This correction requires a fresh published
dataset after producer deployment; no article or database edit is needed.
Structured `ItemWhereRow` sources supply explicit Zone/NPC fields, preserving
piped links and multiline/numeric positional arguments. Area/Loc columns and
the `ItemWhereTable` wrapper do not create identities or alter surrounding zone
scope. Unknown/multiple zone fields remain unresolved rather than guessed.
Explicit normal/raid zone anchors on a row take precedence over its section.
See [`ops/test-eoz-raid-exporter.php`](../ops/test-eoz-raid-exporter.php).

The exporter reconciles NPC-template `known_loot` and `common_loot` with item `dropsfrom`, because
editors maintain these lists independently. Leading item transclusions or direct
item links join exact exported item page titles (preserving internal case after
MediaWiki first-letter/space normalization), with bounded local redirect
resolution for aliases. They add distinct zone/name/instance sources and public
NPC revision/field provenance in `lootSources`; item metadata, classes and eras
remain authoritative on the item record. Missing/statless items are not invented.
Normal/raid template and loot-section scope is preserved through the existing
instance index. An unlabeled public template beside a separate explicit raid NPC
template keeps its own loot; one shared template still supplies both variants.
Explicit loot sections remain sources when the corresponding stat block is
missing; they never synthesize NPC stats. A sibling heading restores the enclosing
template's instance. Comments, namespace links, unknown wrappers, prose and links in
rarity notes do not establish additional drops. `meta.npcLootSourcesIncluded`
marks completion; `npcLootSourceCounts` reports candidate titles, distinct
resolved items, unresolved titles, enriched items and added source variants.
This is a filtered dataset change requiring a rebuild/publication after source
deployment, without article edits or a database migration.

Each rich item also carries `contributionCard: {schemaVersion: 1, complete,
questContext}`. This is an absence proof for quiet routine decisions, separate
from source coverage or item importance. Completeness requires one balanced
literal Itempage, unique static fields, known card/acquisition context, literal
stats, and every normalized stat line present in the exported sixteen-line
bound. Unknown auxiliary fields, dynamic/protected stats, unknown context
templates, variants or truncated lines withhold proof. The producer checks the
same anonymous current public source and revision used by the rich record.
Standard merchant, recipe, forage and related-quest fields are context; a
`relatedquests` field sets `questContext` without declaring an important reward.
Effects, binding and special modifiers remain consumer importance checks.

`maintenance/ItemLootSourceCounts.php` independently exports global loot
classification metadata. `meta.lootSourceCountsVersion = 2` identifies the
additive `lootSourceCounts` catalog, keyed by canonical item title, including
statless categorized items and NPC-authored links to missing item pages. Every
entry has `lootSourceCount`, `lootSourceCountComplete`, and `lootSourceNpcs`;
rich `items` records mirror these fields. The count unions direct Itempage
sources with all public NPC Common/Unique fields across zones and instances.
Canonical page IDs deduplicate redirects. Count names require an actual current
public NPC/Merchant card; NPC redlinks and unrelated existing pages cannot
inflate the verified monster lower bound. An authored redlink remains positive
relationship evidence and makes its affected item count incomplete. Rich NPC
records also expose public redirect `aliases` for
offline pending-source deduplication. Explicit pet labels are excluded.
Counts use anonymous read permission and current `FOR_PUBLIC` main-slot content,
independently of the builder account. Partial builds, unreadable/suppressed
sources, malformed or unknown source syntax, unresolved identities and safety
limits leave an explicit incomplete lower bound. Six verified distinct mobs
already establish Common; fewer than six establish Unique only when complete.
The global collector alone accepts repeated exact static `Namedmobpage`
parameters using the final value, including Common/Unique fields. Dynamic keys,
case collisions and unknown wrappers remain incomplete; ordinary renderer/helper
calls retain strict parsing. Reviewed metadata-only templates are ignored, while
zero-argument legacy item templates require a separately verified public current
revision containing a literal unconditional item hover. Template proof keys
preserve case after the first letter.
`meta.lootSetsVersion = 1` identifies an additive `lootSets` map from canonical
set title to `{items, complete, source}`. Literal positional members of public top-level
`Gear Set` cards and approved unconditional list pools establish membership.
The public source page/revision records membership provenance. The collector expands NPC set references
to member items, deduplicating direct and nested paths by canonical mob identity;
set titles are omitted from item counts. Unknown members, cycles and bounded
expansion leave lower bounds incomplete. Membership never comes from name guesses
or arbitrary template expansion. Conditional tables remain incomplete and never
create a positive member cross-product. Bounded possible members use separate
`possibleItems`/`possibleItemsComplete` uncertainty metadata; only those item
counts become incomplete. Unknown expansion remains conservatively incomplete.
An unbounded undefined group can make the entire global scan incomplete;
a fresh dataset does not manufacture complete Unique counts from that gap.
The reviewed legacy Research domain and literal self-title stubs can instead
bound possible identities, with `possibleRule` and optional `possibleSource`
public revision proof. They remain incomplete, have no positive members, and
never expand NPC loot in the renderer. See [the source investigation and
fail-closed contract](LOOT_SOURCE_GROUP_COUNTS.md). The producer also augments
the curated map catalog from public canonical Category:Zones pages; new normal
zones need no map ID or raid heading, and only unambiguous literal normal
overview `/who` values add aliases.
Safe positive/finite-set coverage and verified six-monster Common lower bounds
remain usable independently. Unresolved Unique placement requires review.

`meta.wikiRelationshipCoverageVersion = 1` identifies `wikiRelationshipCoverage`,
keyed by canonical public page title before rich stat filtering. Records retain
the current public revision, entity kind, exact positive edges, affected field
and normal/raid scopes, verified collection references, and independent
`state`/`complete` capabilities. Broad, unsupported and variant capabilities
apply to the affected field. Unrelated Various prose does not block an entire
item, NPC or zone. Zone capabilities describe existing nested summary cells and
exact DynamicZoneList category consumers; missing optional summaries do not
block independent NPC/item loot facts. See the
[source research](AUTOMATIC_CONTRIBUTION_COVERAGE_RESEARCH.md).
This is cached dataset metadata, with no new count API or per-contribution wiki
queries. Run [`ops/test-eoz-loot-counts.php`](../ops/test-eoz-loot-counts.php),
deploy the reviewed maintenance/helper sources, then separately rebuild and
publish the GitHub dataset before claiming clients have the new counts.

The additive `wikiEntities` presence index contains NPC/item candidate titles,
recognized template name aliases, and public main-namespace titles including
redirects. Skipped stat records remain known; private/user namespaces and account
data are excluded. `meta.wikiEntitiesComplete` is true only for full exports;
clients must not infer absence from an old/limited pack or a filtered intel list.

Keep this producer/catalog aligned with the
[Eye of Zomm producer source](https://github.com/Maergoth/EQL-EOZ/tree/main/server).
Merging desktop changes does not update this producer. Reviewed producer files
must reach the target wiki's `maintenance/` directory, followed by a separate
scheduled or owner-authorized rebuild/publication before clients receive new
records. No database migration or article edits are required; private database
snapshots, settings, and generated packs do not belong in a contribution PR.

## Wiki/forum integration

SMF lives under [`bb/`](../bb/) (checked-in entrypoint reports 2.1.7). MediaWiki
is the intended identity/password source; SMF keeps separate shadow accounts,
database, sessions, and cookies. These are related systems, not one database.

| File | Role |
| --- | --- |
| [`wiki_session_bridge.php`](../wiki_session_bridge.php) | POST/shared-secret validation of an existing wiki session and identity |
| [`wiki_auth_bridge.php`](../wiki_auth_bridge.php) | Legacy POST/shared-secret password-hash verification; distinct from a full AuthManager/OATH login flow |
| [`bb/smf_mediawiki_auto_login.php`](../bb/smf_mediawiki_auto_login.php) | Session bridge, find/create shadow account, set forum cookie; uses direct DB access instead of the broken SSI integration path |
| [`bb/smf_mediawiki_login.php`](../bb/smf_mediawiki_login.php) | Older explicit username/password bridge used by patched default login forms |
| [`bb/smf_create_member.php`](../bb/smf_create_member.php) | Account creation at wiki LocalUserCreated or lazily on forum entry; direct mysqli schema-aware insert |
| [`eql_logout.php`](../eql_logout.php), [`bb/smf_mediawiki_logout.php`](../bb/smf_mediawiki_logout.php) | Unified logout versus forum-only cookie removal |
| [`bb/Themes/PurpleHaze/index.template.php`](../bb/Themes/PurpleHaze/index.template.php) | EQL header/menu/theme integration, session check, wiki login/account links, paste-image script |
| [`bb/Themes/default/Login.template.php`](../bb/Themes/default/Login.template.php) | Patched upstream default login/reset wiring; an SMF upgrade can overwrite it |

In [`BridgeSecrets.example.php`](../BridgeSecrets.example.php), `wikiAuth` must
match `forumAuth` and `wikiSession` must match `forumSession`; use separate local,
staging, and live keys. Existing same-name forum members require matching
nonempty emails for automatic linking; mismatches need owner review. Avoid
rewriting identity/session logic as incidental UI cleanup.

`bb/.htaccess` redirects normal forum login/reset/registration/account routes
to the wiki and disables caching. Local PHP routing does not reproduce these
Apache restrictions. PurpleHaze `scripts/themeswitch.js` uses its own browser
`theme` key and data-theme attribute, separately from wiki preferences.

Forum paste/drop images use `scripts/eql_paste_image_upload.js` with
`bb/eql_inline_image_upload.php` and `bb/eql_inline_image_finalize.php`.
Textarea/SCEditor inserts a temporary BBCode URL, then finalization rewrites it
to a permanent year/month path. Endpoints authenticate the SMF cookie, validate
image MIME and a 10MB limit, and restrict temporary files to their owner.
Temporary files are cleaned after 24 hours during upload. These uploads are
mutable assets outside Git. The theme exposes a CSRF variable, but the current
client/endpoints do not consume it; do not claim token protection merely from
that variable existing.

## Smaller features, host jobs, and historical tools

- `upload-purge.js` adds `eqlReturnTo`/`eqlAlsoPurge` to NPC upload links.
  Despite its old header, `upload-converter.js` currently tracks purge/return
  state (15-minute sessionStorage expiry); it does not re-encode image pixels.
- `spellblade-indicator.js` uses rendered cast/reuse values to mark eligible
  spell rows, excluding Bard songs and zero-cast spells. Keep tooltip field
  semantics aligned with the templates.
- Filename trap: `checkbox-lists.js` implements Category:Spells lazy section
  loading; `spell-lazy-loader.js` stabilizes spell-page item hovers.
  `upload-links.js` intentionally contains no obsolete checkbox handler;
  Common.js is the persistent-state owner.
- Some skin hover/help/contribution links contain production URLs. Staging's
  explicit preparation list rewrites known files; local development and future
  files do not automatically inherit that rewrite.
- Host `eqlwiki-run-jobs.sh`: locked, low-priority, bounded CLI jobs; web job
  execution is disabled (`wgJobRunRate=0`). `eql-icon-cache-refresh.sh` is the
  isolated, locked icon rebuild wrapper. `eql-worker-watch.sh` captures worker
  incident diagnostics. These are source copies of privately installed jobs.
- Root `fix-bard-instrument-resonance.php`, `fix-item-transclusion-tags.php`,
  `fix-shopkeeper-class.php`, and maintenance `AddQuestItemsCategory.php`,
  `tagRedirectsWithEra.php`, `migrateItemPages.php` are historical data-repair
  tools. Inspect dry-run/write flags before use. `migrateItemPages` uses `--save`
  and should receive an explicit private preview directory. `backfill_smf_accounts.php`
  writes by default; use `--dry-run` for inspection and keep account output private.

## Enabled upstream components and inactive copies

The live inventory also enables these upstream components. Use their official
documentation for standard behavior:

- [Vector](https://www.mediawiki.org/wiki/Skin:Vector),
  [ConfirmEdit/Turnstile](https://www.mediawiki.org/wiki/Extension:ConfirmEdit), and
  [ParserFunctions](https://www.mediawiki.org/wiki/Extension:ParserFunctions).
- [CategoryTree](https://www.mediawiki.org/wiki/Extension:CategoryTree),
  [Variables](https://www.mediawiki.org/wiki/Extension:Variables),
  [Scribunto](https://www.mediawiki.org/wiki/Extension:Scribunto),
  [Labeled Section Transclusion](https://www.mediawiki.org/wiki/Extension:Labeled_Section_Transclusion),
  and [DynamicPageList](https://www.mediawiki.org/wiki/Extension:DynamicPageList_%28Wikimedia%29)
  (`extensions/intersection`).
- [VisualEditor](https://www.mediawiki.org/wiki/Extension:VisualEditor),
  [Replace Text](https://www.mediawiki.org/wiki/Extension:Replace_Text),
  [OATHAuth](https://www.mediawiki.org/wiki/Extension:OATHAuth), and
  [UserPageEditProtection](https://www.mediawiki.org/wiki/Extension:UserPageEditProtection).

Site-specific settings include Magelo namespaces 500–503, protected Template
namespace, owner/sysop user-page editing, and 2FA permissions. The Magelo
namespace/style presence does not mean its old PHP extension is enabled.

| Present source | Status and caution |
| --- | --- |
| [`EQLIconIndex`](../extensions/EQLIconIndex/) | Disabled in the checked config and absent from live inventory. Older API/job-based index; current workflow uses static JSON/host builder. Do not run its installer/patcher as current setup. |
| [`Magelo`](../extensions/Magelo/) | Not loaded. Historical APIs/SQL need compatibility review before enabling; namespace/template/CSS support remains. |
| [`SecureLinkFixer`](../extensions/SecureLinkFixer/) | Installed upstream extension but not loaded. |
| [`EQLImmersiveOLD`](../skins/EQLImmersiveOLD/) | Historical skin sharing the active name/namespace; do not load both. |
| [`EQLClientData-Staged-Terminal-1.2.2`](../extensions/EQLClientData-Staged-Terminal-1.2.2/) | Historical rollout snapshots/scripts, not the active extension or current feature flags. |
| [`extensions/BACKUPS`](../extensions/BACKUPS/), tracked `.bak2`/`.save` siblings | Historical copies. Edit the active file, not its backup. Existing tracked copies are still tracked despite ignore rules. |

For ordinary PHP extension registration, templates, parser behavior, APIs,
ResourceLoader, skins, sessions, and maintenance, start with the official
[MediaWiki technical manual](https://www.mediawiki.org/wiki/Manual:Contents).
