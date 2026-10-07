# EQL Immersive skin

The active skin is `eqlimmersive`, under `skins/EQLImmersive/`, with PHP namespace
`EQLImmersive` and body class `skin-eqlimmersive`. It extends Vector 2022 and uses
Vector services/modules; keep Vector loaded. See the root [README](../../README.md),
[component map](../../docs/EQL_COMPONENTS.md), and
[development guide](../../docs/EQL_DEVELOPMENT.md).

## Entry points

| File | Role |
| --- | --- |
| [skin.json](skin.json) | Skin registration, SearchMappings, ResourceLoader scripts/styles and their ordering |
| [includes/SkinEQLImmersive.php](includes/SkinEQLImmersive.php) | Minimal subclass of SkinVector22 |
| [includes/Hooks.php](includes/Hooks.php) | Sidebar/navigation hooks and conditional module loading |
| [includes/templates/skin.mustache](includes/templates/skin.mustache) | Active outer layout, native search, menus, article/sticky-header slots and footer |
| [includes/SearchMySQL.php](includes/SearchMySQL.php) | Case-insensitive whole-title-prefix completion; normal full-text behavior remains upstream |
| [resources/](resources/) | Custom layout, game-page styles and browser behavior |

The historical `EQLImmersiveOLD` copy registers the same name/namespace and should
not be loaded alongside this skin. This is not the old `EQLImmersiveTEST` skin.
Some inherited template files are unused by the active outer template;
`Header.mustache` is not the active search header.

Hooks also provide Verify2Edit for named users with unconfirmed email,
Watch/Unwatch header links, and `__EQL_PAGE_TOOLS__` / `__EQL_ADMIN_TOOLS__`
sidebar placeholders. Admin tools require the configured privileged wiki groups
and the relevant action permissions; preserve those checks when changing menus.

## Modules and template contracts

`skins.EQLImmersive.core`, `.base`, and `.eraFilter` are global skin modules.
Hooks load other modules from rendered HTML markers, including
`class-guides-dropdown`, `eql-mobpage`, `eql-factionpage`, `eql-spellpage`,
`eql-spell-lazy`, `eql-spellpage-items`, `itemeff`, `merchant-page-items-sold`,
`spell-hbdiv`, `eql-lucy-images-grid`, and `id="eql-icon-catalog"`.
EQLClientData separately controls the verification module. Preserve these markers
or update both producer and consumer when changing database templates.

| Area | Resources / dependencies |
| --- | --- |
| Header, menu, TOC and cards | `main.js`, `main.css`, header/sidebar/layout/responsive styles; native Vector TOC is disabled |
| Search | `header-search.js/css`, native Vector/MediaWiki suggestion UI and SearchMySQL.php |
| Era/verification | `era-filter.js/css`, `verified-pages.js`, EQLClientData and database PageEra/VerifiedPages configuration |
| Item/spell hovers | `item-hover.js`, `spell-effect-hover.js`, `merchant-spell-hover.js`, AjaxHoverHelper and compatible template HTML |
| Spell sections | `checkbox-lists.js` currently performs Category:Spells lazy loading; `spell-lazy-loader.js` stabilizes item hovers |
| Game-page layouts | `mobpage.css`, `factionpage.css`, `spellpage.css`, `magelo.css`, item/spell/table styles |
| Icon List | `icon-list.js/css`, database Icon List container, lightweight generated catalog |
| Upload workflow | `upload-purge.js`, `upload-converter.js`: return/purge tracking; no image re-encoding implementation |
| Smaller controls | Announcements, Spellblade indicators, missing-image prompts, diagnostics and contribution links |

Use `mw.hook('wikipage.content')` and existing refresh APIs when inserting parsed
content. CSS order in skin.json and the database Common.css cascade both matter.

## Database scripts remain active

**Do not clear MediaWiki:Common.js or Common.css.** The current site is not fully
contained in the skin directory. The [component map](../../docs/EQL_COMPONENTS.md)
explains these database-owned features:

- Common.js: EQLUserState/EQLPersistentContent, Raid Instance view, editor-loader
  import, Skill_Dual_Wield calculator, Plane of Sky rewards and connection-map view.
- Common.css: additional game-page, mobile, tooltip, raid and Sky styles.
- BlueprintLoader.js/IconFinder.js: active source-editor tools; tracked mirrors
  are under `extensions/EQL-Editor-Tools-Icon-Finder/`.
- ClassGuideDropdown.js: database script loaded by `classguide-loader.js`.

`resources/sky-rewards.js` is a reviewed section of Common.js, not a registered
skin module. `ops/sync-sky-rewards.php` replaces only that section with a revision
check and private backup. `toc-h1-override.js/css` also exist but are not registered
in the active manifest. `upload-links.js` intentionally does not restore the old
local-only checkbox handler; Common.js owns account-backed state.

## Checking changes

Run syntax and relevant regressions from the repository root; see the
[check matrix](../../docs/EQL_DEVELOPMENT.md#checks-by-component). Browser checks
should cover native search/keyboard navigation, source edit/VisualEditor,
item/spell/merchant hovers, lazy content, era controls, anonymous/account state,
narrow layouts, and the page-specific feature changed.

Some resources still contain absolute live URLs. Staging rewrites an explicit
file list; local development does not automatically rewrite all links or hover
requests. Inspect the network target when testing environment isolation.

For ordinary platform behavior use official
[skin development](https://www.mediawiki.org/wiki/Manual:Skinning),
[ResourceLoader](https://www.mediawiki.org/wiki/ResourceLoader), and
[hook documentation](https://www.mediawiki.org/wiki/Manual:Hooks).
