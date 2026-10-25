# Developing EQLWiki

Start with [contributing](../CONTRIBUTING.md) and use the
[component map](EQL_COMPONENTS.md) to locate the feature you are changing.
[PROJECT.md](../PROJECT.md) covers host operations and recovery in more detail.

## Choose the environment you need

| Environment | Suitable work | What a clone does not supply |
| --- | --- | --- |
| Source-only checkout | PHP/JS syntax, source review, dependency-free regressions, synthetic UI tests | Wiki pages, uploaded assets, accounts, a running database |
| New local wiki with synthetic content | Extension/skin development and isolated feature reproduction | Production templates, Lua modules, interface scripts, custom configuration pages, forum content |
| Owner-prepared local snapshot | Tests close to the installed wiki | Permission to redistribute its private account/database contents |
| [Staging](https://test.eqlwiki.com) | Browser and integration tests against a recent production database copy | General contributor access; entry requires a live wiki admin/bureaucrat session |
| [Production](https://eqlwiki.com) | Owner-approved releases and read-only reproduction | Authorization for unreviewed code, data, login, or configuration changes |

The repository contains the installed MediaWiki application and bundled vendor
files, not a content export or a turnkey development container. There is no
tracked fresh-clone bootstrap or public production-data fixture. Production
settings, databases, uploads, browser state, and host-generated caches are separate.

## Fresh-clone setup

1. Install PHP and MySQL/MariaDB suitable for this MediaWiki release. Start with
   the official [installation guide](https://www.mediawiki.org/wiki/Manual:Installation_guide)
   and the checked-in [`INSTALL`](../INSTALL) and
   [`composer.json`](../composer.json). This checkout requires PHP 8.2 or newer.
   The custom forum bridge uses `mysqli`; a core-only SQLite installation does
   not reproduce that integration. Icon fingerprint generation requires GD.
2. Use local-only databases, secrets, mail settings, and URLs. The sanitized
   [`LocalSettings.example.php`](../LocalSettings.example.php),
   [`BridgeSecrets.example.php`](../BridgeSecrets.example.php), and
   [`bb/Settings.example.php`](../bb/Settings.example.php) explain the installation,
   but contain production paths/URLs and are **not drop-in local settings**.
   Configure your own ignored real settings. The forum example uses SQLite
   caching; provide SQLite3 or choose a reviewed local cache alternative.
3. For a new wiki, use the official installer and enable the required EQL
   extensions/skin after configuring local credentials. Keep Vector loaded:
   EQLImmersive depends on its services and modules. Do not enable every directory
   under `extensions/`; see the active/inactive inventory in the component map.
4. Create minimal test pages for the component's markup and configuration
   contracts. Full integration tests need compatible database templates, Lua
   modules, category membership, and `MediaWiki:` pages. Ask the owner for a
   suitable sanitized content export if synthetic pages are insufficient.
   Never put a private snapshot in a PR or public artifact.
5. If forum behavior is in scope, create/configure a separate local SMF database
   and local bridge secrets. Otherwise clearly state that forum integration has
   not been tested. A clean clone cannot sign in using production accounts.
6. Disable outgoing email, production CAPTCHA calls, and automatic production
   jobs in local settings. Use local cookie names/domains and local service URLs.
   Check custom files for absolute live URLs before assuming complete isolation.

Bundled dependencies are committed to reproduce the imported installation.
Do not run a blanket `composer update` as a setup shortcut. Dependency upgrades
need a separate review. There is no root `package.json` and no project-wide
`npm install` step; Node is used by focused tests and the Zone Viewer build.

Normal installation, configuration, database import/backup, and upgrades belong
in the [MediaWiki manual](https://www.mediawiki.org/wiki/Manual:Contents),
[configuration reference](https://www.mediawiki.org/wiki/Manual:Configuration_settings),
[backup guide](https://www.mediawiki.org/wiki/Manual:Backing_up_a_wiki), and
[upgrade guide](https://www.mediawiki.org/wiki/Manual:Upgrading).

## Existing Windows installation

The owner-prepared workspace keeps its private runtime beneath `.local/`.
[`ops/start-local.ps1`](../ops/start-local.ps1) and
[`ops/stop-local.ps1`](../ops/stop-local.ps1) launch/stop an existing installation;
they do not download MariaDB, initialize databases, create site settings, or
import content.

Required inputs include PHP on `PATH`, initialized MariaDB data, database
server/client configs, a writable log directory, local site/bridge/forum settings,
and ignored `ops/local-runtime.json`. This illustrative runtime schema shows the
field names consumed by the scripts; create the tools/configs first and substitute
your actual paths:

```json
{
  "databaseExecutable": ".local/tools/mariadb/bin/mariadbd.exe",
  "databaseConfig": ".local/runtime/my.ini",
  "clientExecutable": ".local/tools/mariadb/bin/mariadb.exe",
  "clientConfig": ".local/runtime/client.cnf",
  "logDirectory": ".local/runtime",
  "databasePort": 3308,
  "httpPort": 8080,
  "backendHttpPort": 8081
}
```

Paths can be relative to the checkout. Keep credentials inside private configs,
not this JSON example or a committed file.

```powershell
powershell -ExecutionPolicy Bypass -File ops/start-local.ps1
# Develop at http://127.0.0.1:8080 with the usual configuration.
powershell -ExecutionPolicy Bypass -File ops/stop-local.ps1
```

The usual database listens on `127.0.0.1:3308`. A second PHP server at
`127.0.0.1:8081` handles internal bridge/REST requests. `EQL_WIKI_URL` directs
forum session requests to that server; a single Windows PHP server can otherwise
deadlock when it calls back into itself. Preserve this distinction when changing
local URLs or process setup.

[`ops/local-router.php`](../ops/local-router.php) implements short wiki URLs,
forum routing, REST paths, and private-directory blocking. It does not execute
Apache `.htaccess` rules. HTTPS redirects, forum route restrictions, and cPanel
behavior therefore need staging verification. Private fixtures under `.local/`
cannot be served by this router; keep any temporary public test fixture synthetic
and remove it from public routes after verification.

## Find the source that actually runs

| Change | Source of execution | Required release step |
| --- | --- | --- |
| Registered skin/extension PHP, JS, CSS | Git files plus module/hook registration | Normal application deployment; confirm the module is loaded on the target page |
| Icon Finder | Database `MediaWiki:IconFinder.js` | Deploy reviewed file, then guarded [`sync-icon-finder.php`](../ops/sync-icon-finder.php) |
| Blueprint tools | Database `MediaWiki:BlueprintLoader.js` | Separately publish the reviewed filesystem source with a revision check and recovery copy |
| Plane of Sky reward fields | A section of database `MediaWiki:Common.js` | [`sync-sky-rewards.php`](../ops/sync-sky-rewards.php), replacing only that section |
| Icon List container | Database `Icon List` | [`sync-icon-list.php`](../ops/sync-icon-list.php) plus the skin listing module |
| Itembox stat defaults | Database `Template:Itembox` | Reviewed [`ops/itembox.wiki`](../ops/itembox.wiki), separately published with [`sync-itembox.php`](../ops/sync-itembox.php) |
| Interactive Character sheets | Two database templates plus conditional EQLImmersive module | Deploy skin files, then separately review/publish both templates with [`sync-character-sheets.php`](../ops/sync-character-sheets.php); [contract and checks](CHARACTER_SHEETS.md) |
| Client observations/public API documentation | Optional EQLClientData module, three own tables, API_Documentation database page | Separate reviewed schema/config activation and guarded public-page publisher; [CLIENT_API.md](CLIENT_API.md). Never a blind core update or automatic DB-page publication |
| Optional talk contribution policy | Registered `EQLTalkContributions` PHP hooks plus ignored environment settings | Deploy files, then separately activate the reviewed settings block; source deployment alone does not enable it |
| Other Common.js/CSS or ClassGuideDropdown behavior | Database interface pages | Review and publish the affected page; no complete canonical Git mirror currently exists |
| Template/Lua/category/verification/era data | Wiki database | An explicit content change/export/import, separate from file deployment |
| Host cache builders, cron wrappers, staging policies, deployment receiver | Owner-installed private host files | Separate installation; `ops/` is excluded from application releases |
| Ignored settings or secrets | Environment-specific files | Separate owner-reviewed local/staging/production configuration |

For database changes, read the current page revision immediately before applying
the change, save a fresh private backup, and refuse concurrent edits. The sync
helpers require `--source`, `--expected-revision`, and `--backup`; inspect each
helper's `--help` and [PROJECT.md](../PROJECT.md) before use. Never replace the
entire Common.js page with `sky-rewards.js`.

For browser changes, inspect ResourceLoader registration, conditional hooks, and
rendered template markers. A source file existing in Git does not mean it executes.
Normal releases stamp local loader paths automatically; see
[deployment asset caching](DEPLOYMENT_ASSETS.md) before changing loader URLs or
release packaging. A normal page reload should pick up a new release without a
hard refresh, while already open documents retain their current resources.
Use [`skin.json`](../skins/EQLImmersive/skin.json), extension registration, the
network/module inspector, and the actual database script to verify ownership.

## Checks by component

Run the checks relevant to your change and report results and limitations.
CI currently lints changed PHP/shell files and checks release packaging;
it does **not** automatically run JavaScript syntax or custom regression tests.

| Check | Command from repository root | Prerequisites / coverage |
| --- | --- | --- |
| JS syntax | `node --check path/to/changed.js` | Node; syntax only |
| Header editing policy | `php ops/test-header-editing.php` | Synthetic navigation fixtures; source Edit, extension ordering, permissions and Verify2Edit |
| Locked header inheritance | `php ops/test-locked-header.php` | Source-only stub parent; omits inherited duplicate markup and enabled class while preserving original header data |
| Locked header | `node ops/test-locked-header.cjs` | Synthetic DOM plus bundled Vector observer; prevents duplicate takeover, measured offsets, menu bounds, VisualEditor toolbar and source-action handling |
| Todo persistence | `node ops/test-todo-store.cjs` | Synthetic API/storage; lazy load, account isolation, failures, limits, concurrent baselines, progress and navigation warnings |
| Todo UI | `node ops/test-todo-ui.cjs` | jsdom; confirmation, page/section identities, deduplication, sanitized notes, progress, collapse, reload and editor retention |
| Selected-text search | `node ops/test-selection-search.cjs` | jsdom; article-only selection, URL/label safety, bounds, dismissal and frame scheduling |
| PHP syntax | `php -l path/to/changed.php` | PHP; syntax only |
| Eye of Zomm producer | `php ops/test-eoz-exporter.php` and `php ops/test-eoz-raid-exporter.php` | Source-only synthetic class/zone/location, section-scoped raid variants, legacy last-wins NPC projection, source provenance, and complete wiki-presence contracts; no MediaWiki bootstrap, settings, database access, pack writes, or dataset publication |
| Global item loot classification | `php ops/test-eoz-loot-counts.php` | Synthetic public-revision/permission/redirect service doubles and actual producer helpers; five/six distinct mobs, all zones/instances, Common/Unique fields, statless materials and redlinked items, aliases/IDs, pets, hidden pages, bounded incomplete results. No wiki/database/pack writes |
| Shell syntax | `bash -n path/to/changed.sh` | Bash; syntax only |
| Whitespace | `git diff --check` | Git |
| Talk contribution policy | `php ops/test-talk-contributions.php` | Source-only synthetic title/user/configuration fixtures; policy and signup form hooks, not live editing or revision attribution |
| Talk permissions integration | `php maintenance/run.php ./ops/test-talk-permissions.php --conf .local/talk-contributions/LocalSettings.php` | Configured isolated local wiki and ignored activation overlay; read-only core permission checks, no page/account/protection writes |
| Unseen Talk counter | `node ops/test-talk-unread.cjs` | jsdom 27 via `NODE_PATH`; synthetic DOM/API/storage fixtures, no database writes or live-site verification |
| Icon metadata | `node ops/test-icon-finder.cjs` | No third-party package; IDs, parameters, aliases |
| Icon result UI | `node ops/test-icon-finder-ui.cjs` | jsdom; 12-result batches, 100 reachable candidates, selected-alias copying, flyout formats/dismissal |
| Era controls | `node ops/test-era-controls.cjs` | No third-party package; excludes editor and page navigation controls |
| Spell rank caps | `node ops/test-spell-level-slider.cjs` | jsdom; categories, cap shorthand, split markup, base restoration, pets, and dynamic cards |
| Shared item scaling kernel | `node ops/test-item-scaling-core.cjs` | No dependencies; numeric boundaries, contextual fields, unknown values, JSON validation/versioning, immutable engines and identical browser/CommonJS execution |
| Item ranks and weapon stat layout | `node ops/test-item-level-slider.cjs` | jsdom and bundled jQuery; loads the shared engine/rules and checks Backstab aliases and screenshot values, Accuracy eligibility/formatting, bows, throwing weapons, ammo, fractional ranks, base restoration, multiple/dynamic cards, and DMG/bonus/Ratio ordering with generated and explicit values |
| Itembox damage bonus | `php maintenance/run.php ./ops/test-itembox.php` | Configured local wiki with ParserFunctions string functions and Variables; supplies the reviewed template in memory, parses synthetic cards, and saves no pages. Covers handedness, level-50 rounding, delay cap, explicit/zero overrides, Backstab, excluded items, and per-card isolation |
| Interactive Character sheets | `php maintenance/run.php ./ops/test-character-sheet.php`, `node ops/test-character-sheet.cjs` and `node ops/build-character-sheet-templates.cjs --check` | Configured local wiki for unsaved two-template parser checks, observed modifiers/unknowns and distinct accuracy fields; jsdom for collapsed/keyboard/dynamic behavior. No page/account/upload writes; [separate publication contract](CHARACTER_SHEETS.md) |
| Client observations | `php ops/test-eoz-observations.php`, PHP/JSON lint | Pure payload and transactional repository/API doubles: attribution, CSRF/POST declaration, dedup/conflicts, quotas/rollback. No schema installation or real account login; [activation is separate](CLIENT_API.md) |
| Sky/account state | `node ops/test-sky-rewards.cjs .local/Common.js` | jsdom and a reviewed Common.js snapshot containing the real state service |
| Exact-artwork catalog | `php ops/test-icon-catalog.php` | GD and private `eql_icons/500.png`, `3470.png`, `3471.png` |
| Full icon coverage | `php ops/verify-icon-coverage.php <library> <index-json> <audit-manifest>` | GD and the private source library/current index/audit |
| Header search layout | `node ops/test-header-search.cjs` | jsdom 27 via `NODE_PATH`; synthetic header measurements, resize, and input fixtures, not real browser layout or native suggestion integration |
| Search integration | `python ops/check-search.py http://127.0.0.1:8080` | A running populated wiki with item/title fixtures; autocomplete, submitted `Raku`, case, namespaces, pagination, native syntax and full-text; use `--help` for staging options |
| Release archive | `python ops/build-release.py --output .local/release-review.tar.gz` | Python; create `.local/` first. Tracked files only, so stage new files before checking inclusion |

To run jsdom tests, install the pinned dependency in an ignored directory using
a Node version supported by jsdom 27:

```powershell
npm install --prefix .local/test-runtime --no-save --package-lock=false jsdom@27.0.0
$env:NODE_PATH = (Resolve-Path .local/test-runtime/node_modules).Path
node ops/test-icon-finder-ui.cjs
```

On a Unix shell, use `NODE_PATH="$PWD/.local/test-runtime/node_modules"` for the
test process. A reviewed public raw Common.js snapshot may be used privately for
the Sky regression; a full private database is not needed for that fixture.

UI smoke checks should match the component: source editor and VisualEditor;
partial/mixed-case search; item/spell/merchant hovers; dynamic sections after
`wikipage.content`; era modes without disabling editor controls; anonymous and
account persistence; mobile menu/flyout containment; and the relevant page-specific
view.

For header search, resize through the available-space thresholds with different
account/tool widths and a Talk badge. Check the 76px inline minimum, narrow
fields below 180px, the centered icon when the field cannot fit, and overlay
containment. Keep a typed query and caret selection while resizing; verify
native suggestions, Enter submission, and Escape focus return in a real browser.
The synthetic layout fixture does not verify actual CSS/font measurements or
ResourceLoader delivery.

Check Talk immediately before Read and Read's thin leading divider, selected
and custom-namespace Talk links, and unique primary/overflow Talk IDs. On phones,
verify the full page-action toolbar wraps below the search/era/account row with
all available subject, Talk, Read, edit, History, and Watch buttons visible and
unclipped. The divider stays visible with Read on phones and is absent only when
Read is absent, including page creation. For the Talk counter, check unseen
revision counts, opening the current Talk view, changes newer than the displayed
revision, old revisions/diffs/editors,
anonymous/account separation, redirects, missing/unreadable pages, narrow layouts,
and reduced motion. The synthetic fixture does not verify live permissions or
ResourceLoader delivery. See the [skin guide](../skins/EQLImmersive/README.md#unseen-talk-changes)
for storage and refresh behavior. Zone Viewer changes need supported browsers and
local game files; use its
[build guide](../extensions/EQLZoneViewer/build/README.md) and
[validation notes](../extensions/EQLZoneViewer/docs/VALIDATION_1.15.0.txt).

For Itembox, use a synthetic primary-capable 2H Blunt weapon with DMG 45 and
Atk Delay 52: the generated bonus should be `34 @ lvl 50`. A 1H weapon with
DMG 40 and delay 50 should show 25. Check existing explicit values on the DMG
line, delay line, and their own line, plus zero; no second bonus should appear.
With the slider active, generated and plain-text explicit bonuses appear between
DMG and Ratio. Check this order on narrow cards and after dynamic content refresh.
Check article and item hover output on staging after the separately approved
template publication. Item rank changes should leave the damage bonus's base-stat
default unchanged. The parser fixture verifies template output, not live
ResourceLoader, uploaded icons, or staging hovers.

ItemLevelSlider's pure engine and coefficient JSON are shared with Eye of Zomm
through release-time source synchronization. The wiki uses its deployed files;
each app version bundles exact reviewed source and keeps those formulas fixed.
A formula change needs a normal wiki deployment and a new app version after
synchronization, without runtime formula polling, API additions, database-page
publication or schema changes. See the
[shared engine guide](../extensions/ItemLevelSlider/README.md).

For ItemLevelSlider, check Backstab and Accuracy inside article and hover item
frames at rank zero, a whole rank, and fractional progress. Backstab uses the
original Backstab field independently of DMG; check `BACKSTAB`, `BACKSTAB DMG`,
and `BACKSTAB DAMAGE`, including zero/negative values. Accuracy should appear
for melee/ranged weapons with positive standalone DMG, excluding AMMO-only
cards, and disappear on reset if the slider generated it. Existing percentage
Accuracy adds the rank gain; existing nonpercentage Accuracy remains unchanged
without a duplicate row. Check narrow cards, multiple cards, repeated refresh,
and content inserted through `wikipage.content`.

The working screenshot models are `B + floor(B * (fullLevel + fraction /
2^fullLevel) / 20)` for Backstab and `2.5 * log2(2^fullLevel + fraction)`
percentage points for Accuracy, truncated down to one decimal with trailing `.0`
removed. Thornstinger at `5 + 4/32` should show Backstab 15 from base 12 and
Accuracy `+12.9%`; Serpent's Tooth at `8 + 1/256` should show Backstab 18 from
base 13 and Accuracy `+20%`. Bow of Underfoot at `4 + 1/16` should show
Accuracy `+10.2%`; Staff of Elemental Mastery: Water at `4 + 8/16` should show
`+11.4%`, which distinguishes truncation from rounding to `+11.5%`.
Fractional Backstab interpolation and the fractional
Accuracy curve remain inferred. The official
[September 22, 2026 patch notes](https://www.everquestlegends.com/patch-notes/eql-update-notes-9-22-2026)
confirm upgrade scaling for Backstab and Accuracy on melee/ranged weapons, but
do not specify these formulas. These slider changes run in the filesystem
ResourceLoader module and require no database-template publication.

## Optional anonymous talk contributions

[`EQLTalkContributions`](../extensions/EQLTalkContributions/README.md) is not yet
enabled on the audited live site. Its activation changes private environment
settings as well as loading the extension. Use the exact block in its guide:
anonymous and unconfirmed named users may contribute to talk pages; subject
pages require a named account with confirmed email. Core protection, blocks,
sessions, CAPTCHA, and rate limits still apply. The policy covers all talk
namespaces, including Magelo Blue/Red talk, and keeps IP attribution by disabling
automatic temporary accounts.

Use an ignored local configuration overlay for the read-only integration check
above. Browser verification should cover existing/new talk pages, subject-page
denials, confirmed/unconfirmed accounts, Verify2Edit navigation, protected talk,
and the signup form's required email. Permission probes do not verify saved
revision attribution; state separately whether a synthetic local save was checked.
Staging's live-login gate remains unchanged and prevents a normal logged-out
browser check. Restore the previous restrictive settings before unloading the
extension; see the extension guide for safe rollback ordering.

## Staging and release quirks

- Contributions target `staging`; the owner separately approves `main` releases.
  Promote specific reviewed changes, not the entire staging branch.
- Every push to either deployment branch runs a file deployment, even docs-only
  pushes. The receiver validates, backs up managed files, and checks the wiki API.
  It does not apply database schema migrations or publish arbitrary wiki pages.
- A staging push or hourly scheduled eligibility check copies the entire live
  wiki/forum database when the previous successful refresh is at least 24 hours
  old. Explicit manual refresh bypasses the limit; scheduled refresh never forces
  recovery of an interrupted replacement. The workflow schedule runs from `main`,
  so a staging-only workflow merge is insufficient to activate it.
  This is a full replacement, not a database diff: staging-only DB edits disappear.
- The public Eye of Zomm pack uses the same hourly-check/24-hour-success rule,
  with a separate private marker written only after successful GitHub publication.
  The desktop checks the small manifest at startup/hourly and downloads changed
  data; it does not request private DBs or trigger a host build. Local-only builds,
  skipped/overlapping checks, and failed publication never defer the next automatic
  source refresh; a successful manual publication does advance freshness.
  See [the complete shared policy](../PROJECT.md#unified-data-refresh-policy) for
  timing, force behavior, recovery, and separate host installation requirements.
- Only missing uploads/attachments and related mutable assets are copied.
  Existing staging files stay; replacements can remain older and production
  deletions do not propagate. Compare the relevant image bytes when reproducing
  an asset bug.
- Staging isolates databases, cookies, bridge secrets, caches and login policy,
  disables outbound mail/CAPTCHA/automatic jobs, and checks a live admin session.
  The access policy is loaded from ignored `EQLStaging.php`, not shared production
  code. Do not copy it into normal login code.
- Staging preparation rewrites an explicit list of production URLs. Local and
  new custom files can still contain live links or fetch live hover data. Check
  the browser network requests and the current
  [`staging-prepare.php` on the staging branch](https://github.com/Maergoth/EQLWiki/blob/staging/ops/staging-prepare.php)
  before claiming an integration test is completely isolated.
- Staging deployment/refresh reapplies the reviewed Sky Common.js section. It
  does not automatically publish Icon Finder or Icon List database pages.
- Host-script changes are installed separately. A receiver rollback restores
  managed application files, not arbitrary DB changes; [`rollback.sh`](../ops/rollback.sh)
  currently targets production. Keep separate recovery copies for data edits.

## Automatic NPC recorded loot

[`DynamicZoneList`'s reciprocal lookup](NPC_RECORDED_LOOT.md) fills native NPC loot
and item Drops From fields while rendering public authored relationships, including
manual pages created in either order. Targeted old/new cache purges repeat after
link updates. Stored wikicode and native Common/Unique fields remain intact. Run these bounded,
synthetic checks without a database:

```sh
php ops/test-npc-loot-source.php
php ops/test-npc-loot-lookup.php
php ops/test-npc-loot-renderer.php
php ops/test-item-drop-lookup.php
php ops/test-npc-loot-invalidation.php
```

With a configured private local wiki, also run
`php maintenance/run.php ./ops/test-npc-loot-mediawiki.php` for unsaved parser
integration. An optional `--output` writes synthetic HTML to a private path.

Check both page-creation orders, real versus prose links, redirect/case collisions,
normal/raid contexts, authored-list deduplication, public revision visibility,
discovery limits and cache expiry. An unsaved real MediaWiki parse should also
verify original item hover markup, transclusion dependencies and the generated
heading's lack of a source-section edit link. Fixtures alone do not establish
live coverage. No template publication, schema installation or database refresh
is part of this feature's deployment.

## Header and todo browser checks

Check a scrolled article and fragment navigation, floating menus, keyboard
access/Escape and narrow layouts. For signed-in readers, scroll below the title
and confirm the original search and menu controls remain accessible; no duplicate
Vector header should be rendered. Check plus/pencil visibility on modern heading
wrappers and legacy headings: hover and keyboard focus reveal both controls,
moving onto the pencil keeps them visible, and touch devices retain direct access.
Confirm one Edit opens source while native
editor switching still works. Add a page and a section, cancel confirmation,
edit rich notes/nested checkboxes, move progress to/from 100 and reload. Check
anonymous/account separation and a failed save without discarding its draft.
Synthetic API/DOM tests do not establish a live account save or browser layout.
Normal application deployment loads these registered skin modules; no database
interface-page sync or schema update is needed. Staging database refreshes can
replace account preference lists.

## Maintenance scripts are not routine tests

For Eye of Zomm producer changes, run the source-only regression above and lint
`maintenance/BuildEyeOfZommPack.php`. Keep the corresponding producer and catalog
in the [desktop repository](https://github.com/Maergoth/EQL-EOZ/tree/codex/wiki-map-integration-audit)
aligned. `Class: ALL except` must export the playable-class complement, explicit
`NONE` must remain distinct from unknown `[]`, and wiki `Y, X, Z` location order
must not be converted to viewer axes in the producer. Add exact zone aliases
without renaming the canonical viewer records or guessing unknown zones.
Recognized explicit NPC template zones win over categories and preserve every
listed zone; categories and title suffixes are fallbacks. Include conflicting
categories and multi-zone fields in producer regressions.

Raid tests must include an uncatalogued future zone, nested/sibling headings,
normal and raid variants with the same NPC name, an explicit raid-only boss,
entrance Voidling exclusion, and section-specific item sources. Do not copy a
public NPC coordinate into a derived raid variant.
Drop-source regressions must also cover Hate-shaped `VeliousGray` NPC links,
display labels/revamp notes, wrapped/era-annotated zone headings, nested actual
NPCs versus `Loc` annotations, and an exact normal/raid drop-to-NPC join. Never
repair these identities by stripping arbitrary template text in the client.
Include a structured `ItemWhereRow` with piped links, multiline/numeric arguments,
an unrelated Area link, an unknown zone, and normal/raid source joins. Its own
zone must not leak into the surrounding bullet list.
A full presence index must retain categorized pages skipped for missing stats and main-namespace redirect
titles; limited exports must set `wikiEntitiesComplete` false. Never interpret a
filtered intel list as the complete set of wiki pages.

NPC `known_loot` reconciliation regressions must include an item absent from its
own reverse source list, duplicate evidence in both directions, differing item
page/display names, internally case-distinct titles, a local redirect, a repeated
merge with zero new-source counters, normal/raid NPC template and nested loot
sections, unlabeled public templates beside separate raid stat templates, shared
variants and public revision/field provenance. Include explicit instance loot
without that instance's stat block and verify that a sibling loot heading returns
to its enclosing normal/raid template. Unknown wrappers,
namespace targets, notes, comments and prose cannot invent drops; no item record
may be synthesized from a missing/statless item page. `npcLootSourcesIncluded`
and reconciliation counters distinguish rebuilt datasets from older exports.

The fixture evaluates actual exporter methods with synthetic page objects;
it does not bootstrap MediaWiki or prove live database coverage.
An approved source deployment and subsequent dataset rebuild/publication are
separate release steps, described in [PROJECT.md](../PROJECT.md#existing-scheduled-jobs).
Do not use a production export as a routine test or commit database snapshots,
generated packs, settings, uploads, or client archives.

The [component map](EQL_COMPONENTS.md) lists historical data-repair and dataset
tools. Read flags and defaults before executing them. Most repair scripts offer
dry runs, but [`backfill_smf_accounts.php`](../backfill_smf_accounts.php) writes
unless `--dry-run` is supplied. Its output can contain private account information.
`migrateItemPages.php` uses `--save` for writes and defaults to a preview directory
outside the checkout; explicitly choose `.local/` for previews.

Normal platform testing and maintenance mechanics are documented upstream:
[extension development](https://www.mediawiki.org/wiki/Manual:Developing_extensions),
[hooks](https://www.mediawiki.org/wiki/Manual:Hooks),
[ResourceLoader](https://www.mediawiki.org/wiki/ResourceLoader),
[Action API](https://www.mediawiki.org/wiki/API:Main_page), and
[maintenance scripts](https://www.mediawiki.org/wiki/Manual:Maintenance_scripts).
