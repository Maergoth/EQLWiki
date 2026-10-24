# EQLWiki

The application behind [EverQuest Legends Wiki](https://eqlwiki.com):
MediaWiki 1.45.3, the EQL Immersive skin, custom game-data and editor tools,
and an integrated Simple Machines Forum in `bb/`.

This is a snapshot of the installed application, including bundled dependencies.
Wiki articles, templates, Lua modules, interface scripts, users, uploads, and forum
posts are stored separately in databases or mutable files. A Git clone alone is
**not** a complete copy of the running site.

## Start here

| If you want to… | Read |
| --- | --- |
| Submit a fix or feature | [Contribution guidelines](CONTRIBUTING.md) |
| Set up development and choose useful checks | [Development guide](docs/EQL_DEVELOPMENT.md) |
| Find the code for an EQL feature | [Custom component map](docs/EQL_COMPONENTS.md) |
| Work on the skin | [EQL Immersive skin guide](skins/EQLImmersive/README.md) |
| Understand deployment, staging, backups, and host jobs | [Operations guide](PROJECT.md) |
| Work as an AI coding agent | [Repository instructions](AGENTS.md), then the guides above |

## Contributing

Create a feature branch from `staging` and open a pull request against `staging`.
Describe the observed problem, the resulting behavior, and how you verified it.
@Maergoth reviews contributions and controls releases to `main`.

Accepted staging changes deploy to [test.eqlwiki.com](https://test.eqlwiki.com).
Access uses a live wiki administrator or bureaucrat login; repository access does
not grant staging access. Every push to `main` deploys the production application,
including documentation-only pushes. Production releases require owner approval.

Use GitHub issues for application bugs. Ordinary article/template corrections
belong on the wiki unless they expose a code defect. Follow the
[Code of Conduct](CODE_OF_CONDUCT.md) and retain component license notices.

## Major EQL components

| Area | Starting point | What it owns |
| --- | --- | --- |
| Skin and search | [`skins/EQLImmersive/`](skins/EQLImmersive/) | Vector-based layout, custom sidebar/TOC, title-prefix completion, era overlays, game-page styles, hovers, uploads, and page-specific modules |
| Game metadata | [`extensions/EQLClientData/`](extensions/EQLClientData/) | Batched `eqlmetadata` API, era status, verification metadata, and spell override configuration |
| Client/API contributions | [Client API guide](docs/CLIENT_API.md), [public page source](ops/api-documentation.wiki) | Standard attributed page creation/edit/upload plus optional separately activated private observation ingestion; no EoZ secret or bot proxy |
| Item and spell calculations | [`ItemLevelSlider`](extensions/ItemLevelSlider/), [`SpellLevelSlider`](extensions/SpellLevelSlider/) | Browser controls that depend on the wiki's template markup |
| Reciprocal mob loot | [Automatic recorded loot](docs/NPC_RECORDED_LOOT.md) | NPC read views supplement authored loot with verified public item drop sources, including manual edits and either page-creation order |
| Dynamic lists and item hovers | [`ClassSlotEquip`](extensions/ClassSlotEquip/), [`AjaxHoverHelper`](extensions/AjaxHoverHelper/), [`DynamicZoneList`](extensions/DynamicZoneList/), [`DynamicQuestItemList`](extensions/DynamicQuestItemList/) | Equipment lookup, special-page tooltip HTML, zone/category lists, and quest item lists |
| Icon Finder and Icon List | [`extensions/EQL-Editor-Tools-Icon-Finder/`](extensions/EQL-Editor-Tools-Icon-Finder/), [`ops/host-bin/`](ops/host-bin/), [`icon-list.js`](skins/EQLImmersive/resources/icon-list.js) | Full uploaded-icon catalog, exact-artwork aliases, cached matching, and bounded browsing |
| Local game-file viewer | [`extensions/EQLZoneViewer/`](extensions/EQLZoneViewer/) | Browser-only zone parsing, 3D/map views, navigation workers, and connected world map |
| Persistent article controls | Live [`MediaWiki:Common.js`](https://eqlwiki.com/MediaWiki:Common.js), reviewed [`sky-rewards.js`](skins/EQLImmersive/resources/sky-rewards.js) | Account/anonymous state, checklists, raid view, combat calculator, Sky rewards, and the connection-map view |
| Wiki/forum integration | [`wiki_auth_bridge.php`](wiki_auth_bridge.php), [`wiki_session_bridge.php`](wiki_session_bridge.php), [`eql_logout.php`](eql_logout.php), [`bb/`](bb/) | Wiki-authoritative login, shadow forum accounts, unified logout, and inline forum images |
| Hosting and data refresh | [`ops/`](ops/), [`.github/workflows/`](.github/workflows/) | Local launchers, file deployment, isolated staging, cache jobs, and maintenance helpers |

The [component map](docs/EQL_COMPONENTS.md) covers smaller features, template/DOM
contracts, inactive copies, and the distinction between installed and enabled
components. Its enabled-component inventory was checked against the public live
site on October 7, 2026; recheck configuration when changing environments.

## Development essentials

- For the existing private Windows installation, run
  `powershell -ExecutionPolicy Bypass -File ops/start-local.ps1` from the checkout.
  The usual frontend is `http://127.0.0.1:8080`. The launcher requires an already
  configured database/runtime; it does not bootstrap a fresh clone.
- For a fresh clone, follow the [development guide](docs/EQL_DEVELOPMENT.md):
  install a compatible local MediaWiki environment, use local credentials and
  test data, and configure the EQL components you need. Production data is private.
- Prefer custom extension/skin entry points over edits to MediaWiki core or
  bundled vendor code. Read the relevant `extension.json`/`skin.json`, hooks,
  template markup, and API contracts before changing a feature.
- Some JavaScript runs from `MediaWiki:` database pages. Committing a filesystem
  copy does not publish that page. Document the separate synchronization step.
- Never commit real settings, bridge secrets, database dumps, user data, uploads,
  generated icon indexes, game-file packs, or deployment credentials.

## Developer workflow and client integration

Start in the [component map](docs/EQL_COMPONENTS.md), then check the component's
`extension.json` or `skin.json`, loaded settings, hooks and relevant database
pages. For example, EQLImmersive's `includes/Hooks.php` selects ResourceLoader
modules, `EQLClientData/includes/` owns the custom API, and AjaxHoverHelper owns
the item-hover adapter. Historical copies and unregistered directories do not
establish what runs. Read-only `siteinfo`/`paraminfo` requests help verify the
actual environment; they do not replace checking its private registration.

Plan changes around their installation boundary:

| Change | Development and release boundary |
| --- | --- |
| Extension/skin PHP, JavaScript or CSS | Scoped Git PR to `staging`; verify active registration, API/template/DOM contracts and actual deployed files |
| Templates, Lua or `MediaWiki:` scripts | Database revisions; tracked sources need a separate guarded publisher, fresh expected revisions and private recovery backup |
| Observation schema or private settings | Explicit reviewed installation/configuration; file deployment does not create tables or enable collection |
| Host jobs, icons and public game datasets | Separate reviewed operator workflow; see [PROJECT.md](PROJECT.md), preserve canonical assets and verify the published artifact |

Use checks appropriate to that boundary. The client API's source-only contracts
can be checked without an account or production database:

```sh
php ops/test-eoz-observations.php
php ops/test-eoz-installers.php
php ops/test-hover-item-metadata.php
```

These use synthetic doubles; they do not prove a live login, database activation
or page publication. The [development guide](docs/EQL_DEVELOPMENT.md) maps other
PHP/JavaScript, parser and UI checks. Report the actual environment and remaining
limits in the PR. Promote only reviewed feature commits to `main`, with owner
authorization and any separate content/configuration steps; never merge the
whole staging branch as a production shortcut.

[Eye of Zomm](https://github.com/Maergoth/EQL-EOZ) is a separate desktop repository.
Production wiki articles remain authoritative: the producer
[`maintenance/BuildEyeOfZommPack.php`](maintenance/BuildEyeOfZommPack.php) publishes
a filtered public manifest/pack to that repository's `dataset` branch. The app
checks the manifest at startup and hourly; it does not pull a wiki database.
The [unified refresh policy](PROJECT.md#unified-data-refresh-policy) describes
publication eligibility and cache replacement.

In the other direction, clients use normal attributed MediaWiki edit/upload APIs
for reviewed contributions and User-page Character sheets. The separate
`eozobservations` API stores authenticated kill/loot evidence and batch receipts;
it does not edit articles or turn observations into drop rates. Start with the
[client API contract](docs/CLIENT_API.md), the live
[API Documentation](https://eqlwiki.com/API_Documentation), and the
[Character sheet contract](docs/CHARACTER_SHEETS.md). Use bounded public metadata
and synthetic fixtures; raw private logs, player/account data, settings and
credentials do not belong in source, dataset packs or public issue attachments.

## Standard MediaWiki documentation

Use the official documentation for standard platform behavior:

- [Technical manual](https://www.mediawiki.org/wiki/Manual:Contents),
  [installation](https://www.mediawiki.org/wiki/Manual:Installation_guide), and
  [configuration](https://www.mediawiki.org/wiki/Manual:Configuration_settings).
- [Extension development](https://www.mediawiki.org/wiki/Manual:Developing_extensions),
  [registration](https://www.mediawiki.org/wiki/Manual:Extension_registration), and
  [hooks](https://www.mediawiki.org/wiki/Manual:Hooks).
- [Skin development](https://www.mediawiki.org/wiki/Manual:Skinning) and
  [ResourceLoader](https://www.mediawiki.org/wiki/ResourceLoader).
- [Action API](https://www.mediawiki.org/wiki/API:Main_page),
  [site JavaScript](https://www.mediawiki.org/wiki/Manual:Interface/JavaScript), and
  [maintenance scripts](https://www.mediawiki.org/wiki/Manual:Maintenance_scripts).

MediaWiki's upstream release notes and install/upgrade notes remain in
[`RELEASE-NOTES-1.45`](RELEASE-NOTES-1.45), [`INSTALL`](INSTALL), and [`UPGRADE`](UPGRADE).
The base application is GPL-2.0-or-later; see [`COPYING`](COPYING), [`CREDITS`](CREDITS),
and individual component licenses. Public source access does not grant permission
to redistribute private data or game assets.
