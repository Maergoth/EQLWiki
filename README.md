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
| Item and spell calculations | [`ItemLevelSlider`](extensions/ItemLevelSlider/), [`SpellLevelSlider`](extensions/SpellLevelSlider/) | Browser controls that depend on the wiki's template markup |
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
