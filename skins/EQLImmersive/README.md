# EQLImmersiveTEST Skin

A self-contained dark-fantasy MediaWiki skin for [EverQuest Legends Wiki](https://eqlwiki.com).
Extends Vector 2022 — reuses its HTML structure, sidebar, sticky header, and responsive
infrastructure, then replaces all visual styling and adds EQ-specific functionality.

> **This skin is fully self-contained.** MediaWiki:Common.css and MediaWiki:Common.js
> should be empty when this skin is the default. All site CSS and JS lives here.

---

## Quick Reference

| Item | Value |
|------|-------|
| Skin ID | `eqlimmersivetest` |
| Body class | `skin-eqlimmersivetest` |
| PHP namespace | `EQLImmersiveTEST` |
| Extends | `MediaWiki\Skins\Vector\SkinVector22` |
| Requires | MediaWiki ≥ 1.45, Vector skin loaded |
| Default skin setting | `$wgDefaultSkin = 'eqlimmersivetest';` |

---

## Directory Structure

```
skins/EQLImmersiveTEST/
├── skin.json                          # Skin manifest, resource module definitions
├── i18n/en.json                       # Localization strings
├── includes/
│   ├── SkinEQLImmersiveTEST.php       # Main skin class (minimal, extends SkinVector22)
│   ├── Hooks.php                      # Server-side hooks (sidebar, watch link, viewport)
│   └── templates/                     # Mustache templates (49 files, inherited from Vector)
├── resources/
│   ├── images/                        # Background images, nav icons (13 files)
│   ├── *.css                          # CSS modules (39 files)
│   └── *.js                           # JS modules (27 files)
└── README.md                          # This file
```

---

## CSS Modules

### Core Skin Layout (inherited from original EQLImmersive skin)
| File | Purpose |
|------|---------|
| `main.css` | Design tokens, page background, dark theme for all UI, scrollbars, beta banner |
| `homepage.css` | Main Page card grid, hero section, announcements panel |
| `announcements.css` | Collapsible announcements toggle button and states |
| `header-actions.css` | Header flexbox, logo, hamburger, page tabs, search, user menu |
| `sidebar-redlinks.css` | Custom sidebar, pin button, TOC menu, red links |
| `layout-reset.css` | Fixed footer positioning |
| `era-filter.css` | Out-of-era toggle button and link overlay styles |
| `contribute.css` | Footer Contribute/Support buttons |
| `special-pages.css` | Dark theme for Special pages, forms, preferences |

### Extracted from Common.css — EQ Game Templates
| File | Purpose |
|------|---------|
| `item-hover.css` | Item frame backgrounds (.itembg, .itemtopbg, .itembotbg, .itemtitle, .itemdata) |
| `item-hoverbox.css` | Item hover tooltip positioning (.hbdiv, .magelohb) |
| `magelo.css` | Magelo character inventory display, slot positions, era colors, fashion show, price check, quotation styles, tabs, stackguide, group hunting spots |
| `spell-hover.css` | Spell examine window, spellpage item hover stabilizer, item effect spell hovers |
| `spell-lazy.css` | Spell list lazy loader placeholder/loading/error states |
| `checkbox-lists.css` | Persistent checkbox list styling ({{CheckboxList}} template) |
| `spellblade-lucy.css` | Spellblade compatibility icon, Lucy Images responsive grid |

### Extracted from Common.css — Skin-Specific Layouts
| File | Purpose |
|------|---------|
| `mobpage.css` | Template:Namedmobpage — named mob page 3-column grid layout |
| `spellpage.css` | Template:spellpage — spell/song page layout |
| `factionpage.css` | Template:Factionpage — faction page raise/lower columns |
| `article-prose.css` | Article typography: headings, lists, blockquotes, code, images, thumbnails |
| `editsection.css` | Replaces [edit\|edit source] with single source-edit pencil icon |
| `header-wordmark.css` | Header wordmark gradient text, page action button styling |
| `hide-title.css` | Hides wordmark/tagline text (logo icon only) |
| `search-page.css` | Special:Search dark theme styling |
| `rcfilters.css` | Special:RecentChanges filter popup dark theme |
| `page-era.css` | {{PageEra}} badge positioning without layout shift |
| `floating-search.css` | Floating search button, expanded form, typeahead dropdown |
| `homepage-mobile.css` | Main Page mobile category card layout |
| `responsive-mobile.css` | Mobile/responsive foundation for all page types |
| `toc-limit.css` | {{TOC limit|N}} support for custom side TOC |
| `toc-h1-override.css` | TOC H1 heading display and indentation levels |

### Extracted from Common.css — General Wiki
| File | Purpose |
|------|---------|
| `grayout-misc.css` | .grayout class, auction tracker box, contribution scores |
| `esec.css` | Empty section header styling (.esec) |
| `table-base.css` | Base eoTable/wikitable/sortable table styling |
| `darkmode-tables.css` | Dark mode overrides for tables (skin-theme-clientpref-night) |
| `table-sizing.css` | Unified table sizing with !important for consistency |
| `missing-image.css` | Missing/unuploaded image placeholder prompt |
| `perf-widget.css` | Footer performance stats gear widget |
| `active-skills.css` | Active skills table column widths for build guides |

---

## JS Modules

### Core Skin Behavior (inherited from original EQLImmersive skin)
| File | Purpose |
|------|---------|
| `main.js` | Sidebar toggle/pin, TOC building, user menu, clickable cards, zone map magnifier |
| `announcements.js` | Announcements collapse/expand, new content detection via localStorage |
| `era-filter.js` | Out-of-era link filtering via category API queries |
| `contribute.js` | Footer button click handlers |

### Extracted from Common.js — Item/Spell Interactions
| File | Purpose |
|------|---------|
| `item-hover.js` | Ajax cache library + item hover tooltip (mouseover/mouseout/mousemove) |
| `inline-hover-fix.js` | Fixes punctuation after inline item hover transclusions |
| `spell-effect-hover.js` | Lazy-loaded spell examine hovers on .itemeff links |
| `merchant-spell-hover.js` | Bulk spell hover hydration for merchant sold-items lists |
| `spell-lazy-loader.js` | Lazy loads spell list sections on Category:Spells when expanded |
| `spellblade-indicator.js` | Adds ⚔ icon to spells where reuse_time ≤ casting_time |
| `checkbox-lists.js` | Persistent checkbox state via localStorage |

### Extracted from Common.js — Wiki Utilities
| File | Purpose |
|------|---------|
| `verified-pages.js` | Page verification banner system |
| `classguide-loader.js` | Loads MediaWiki:ClassGuideDropdown.js |
| `missing-image.js` | Replaces broken File: links with upload prompt boxes |
| `sidebar-cleanup.js` | Removes __EQL_PAGE_TOOLS__ placeholders outside this skin |
| `perf-widget.js` | Footer page-load stats gear widget |
| `upload-converter.js` | Normalizes uploaded images via canvas before submission |
| `upload-purge.js` | Purges source page cache after image upload |
| `upload-links.js` | Adds return/purge parameters to NPC upload links |

### Extracted from Common.js — Search
| File | Purpose |
|------|---------|
| `click-search.js` | Click-to-expand search (creates toggle button, manages open/close) |
| `floating-search.js` | Floating search override (hides native, creates fixed-position form) |
| `floating-search-anchor.js` | Positions floating search relative to era filter button |
| `floating-search-typeahead.js` | Search-as-you-type suggestions via API |
| `floating-search-clickguard.js` | Ensures suggestion clicks navigate correctly |

### Extracted from Common.js — Table of Contents
| File | Purpose |
|------|---------|
| `toc-limit.js` | Applies body class for {{TOC limit|N}} |
| `toc-rebuild.js` | Rebuilds custom side TOC from article headings (includes H1) |
| `toc-h1-override.js` | Final TOC H1 override — authoritative rebuild with MutationObserver |

---

## Server-Side Hooks (Hooks.php)

| Hook | What it does |
|------|-------------|
| `BeforePageDisplay` | Sets responsive viewport meta tag |
| `SkinTemplateNavigation::Universal` | Adds Watch/Unwatch to header tabs, creates views-overflow |
| `SkinBuildSidebar` | Replaces __EQL_PAGE_TOOLS__ and __EQL_ADMIN_TOOLS__ sidebar placeholders with real page/admin tool links |

---

## Related Extensions

These extensions run independently but interact with skin styling:

| Extension | Interaction |
|-----------|------------|
| **AjaxHoverHelper** | Creates `#itemHoverContainer` for item tooltips; `item-hover.js` also creates one (first wins) |
| **ItemLevelSlider** | Loads on all pages via BeforePageDisplay — adds item level scaling UI |
| **Magelo** | Inventory display styled by `magelo.css`; hooks into parser output |
| **ClassSlotEquip** | Equipment builder, loads conditionally on "Equipment By Class" page |
| **DynamicQuestItemList** | Dynamic tables, styled by `table-base.css` |
| **DynamicZoneList** | Zone list generation |
| **AuctionTracker** | Auction log parser, styled by `grayout-misc.css` |

---

## How to Deploy

### First-time setup
1. Upload `skins/EQLImmersiveTEST/` to the server
2. Add to `LocalSettings.php`:
   ```php
   wfLoadSkin( 'EQLImmersiveTEST' );
   ```
3. Test with `?useskin=eqlimmersivetest` on any page

### Make it the default
1. In `LocalSettings.php`, set:
   ```php
   $wgDefaultSkin = 'eqlimmersivetest';
   ```
2. Set `MediaWiki:Common.css` content to:
   ```css
   /* All styles moved to EQLImmersiveTEST skin */
   ```
3. Set `MediaWiki:Common.js` content to:
   ```js
   /* All scripts moved to EQLImmersiveTEST skin */
   ```

### Rollback
Change `$wgDefaultSkin` back to `'eqlimmersive'` and restore Common.css/Common.js.

---

## Development Notes

### Body class
All skin-scoped CSS uses `body.skin-eqlimmersivetest` as the selector prefix.
JS checks use `document.body.classList.contains('skin-eqlimmersivetest')`.

### CSS load order
All CSS files in `skins.eqlimmersivetest.core` load as one concatenated, minified
ResourceLoader module. Order in `skin.json` determines cascade priority — files listed
later override earlier ones.

### JS load order
All JS files in `skins.eqlimmersivetest.js` are concatenated and execute in order.
Many modules are IIFEs with DOM-ready guards. Several use `mw.hook('wikipage.content')`
to handle dynamically loaded content.

### Known duplication (future optimization targets)
- **Search**: 5 JS files (click-search, floating-search, anchor, typeahead, clickguard)
  could be consolidated into one ~500-line module
- **TOC**: 3 JS files (toc-limit, toc-rebuild, toc-h1-override) have overlapping logic;
  toc-rebuild and toc-h1-override do similar work
- **Hover positioning**: item-hover.js, spell-effect-hover.js, merchant-spell-hover.js
  all implement independent mouse-tracking + tooltip positioning; a shared utility
  would eliminate ~200 lines of duplication
- **RCFilters CSS**: rcfilters.css contains 4 successive override blocks from iterative
  development; could be flattened to one clean pass

### Performance characteristics
- Skin CSS/JS loads via ResourceLoader (minified, versioned, cached)
- Common.css was previously inlined as `<style>` in every HTML response (render-blocking);
  eliminating it removes ~6KB of blocking CSS from every page load
- Several JS modules use MutationObservers on document.body — consider consolidating
  to a single observer in future optimization passes

---

## History

This skin was created by forking `EQLImmersive` and absorbing all code from
`MediaWiki:Common.css` (6,157 lines) and `MediaWiki:Common.js` (5,651 lines)
into skin resource modules. The goal was to make the skin fully self-contained
so that Common.css and Common.js could be emptied, eliminating the render-blocking
site styles and consolidating all custom code into one maintainable location.

The original `EQLImmersive` skin and the original Common.css/Common.js content
are preserved in the `Important Mediawiki Files/` directory as reference copies.
