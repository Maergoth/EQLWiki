# Interactive Character sheets

`Template:Character_sheet` and `Template:Character_sheet_item` render a public,
hand-editable inventory snapshot. Each Character (Server) starts collapsed after
JavaScript initializes; its disclosure is keyboard accessible and independent of
other characters on the page. Without JavaScript the full static sheet remains
readable. Item icons link to their existing wiki pages. Hovering or focusing gear
shows its supplied tier-specific stat card and clickable known augments.
The interactive inspect is a labeled nonmodal dialog. A short 200 ms pointer
grace period allows crossing the gap from gear to its links; entering the card
cancels dismissal. Keyboard users can press ArrowDown from gear to enter those
links, and Escape returns focus to the gear. Focus leaving the card, page scroll,
resize or collapsing a character dismisses it. Without enhancement, all item
statistics and socket links stay visible in the static sheet.

This uses the already loaded EQLImmersive skin, not the historical Magelo PHP
extension. No new extension, namespace, database schema or authentication policy
is enabled. The browser module has no network, account-state or storage writes.
The desktop app owns reviewed, revision-guarded User_talk upserts; templates never
write pages. A new snapshot updates that character's managed block and preserves
other characters and the owner's unrelated talk-page text.

## Sources and deployment boundaries

- `ops/build-character-sheet-templates.cjs` defines schema 1 and generates the
  reviewed `ops/character-sheet.wiki` and `ops/character-sheet-item.wiki` sources.
  Run it after changing the contract; `--check` detects generated-source drift.
- The two actual `Template:` pages live in the database. Git deployment does not
  publish them. Deploy the reviewed skin module first, then separately review and
  publish the two fixed templates with `ops/sync-character-sheets.php`.
- `skins/EQLImmersive/includes/Hooks.php` registers `ParserAfterParse`. Rendered
  `.eql-character-sheet-v1` markup adds `skins.EQLImmersive.characterSheet` JS
  and CSS to ParserOutput, including unsaved Action API previews and cached
  pages. Quoted documentation markup does not load the module.
- `resources/character-sheet.js/css` progressively enhance that markup. They do
  not reuse the base-item AjaxHoverHelper card, which could show a different
  item tier from the reviewed snapshot. Existing `span.ih` behavior is untouched.

The app preflights both template sources for `EQL_CHARACTER_SHEET_V1` and
`EQL_CHARACTER_SHEET_ITEM_V1`, then uses an unsaved `action=parse` request with a
synthetic populated `head_item`. It requires `.eql-character-sheet-v1`, the
helper's `.eql-character-inspect-v1` and the exact module in `parse.modules`.
Missing templates or deployment capability must prevent publishing a broken
sheet. Parser/module availability does not grant edit permission.

## Flat parameter contract

Use named parameters; underscores and spaces in the two template titles have
normal MediaWiki equivalence. A minimal sheet is:

```wikitext
{{Character_sheet
|name=Example character
|server=Example server
|level=60
|classes=WAR / CLR
|aa=123
|total_hp=1234
|total_mp=?
|total_end=456 *
|feet_item=Example Boots
|feet_tier=3
|feet_icon=500
|feet_stats=HP: 133<br>STR: 7
|feet_augment_slots=2
|feet_augment_1_item=Example Sword
|feet_augment_1_tier=2
|feet_augment_1_stats=HP: 5
}}
```

Identity fields are `name`, `server`, `level`, `classes`, `aa`, optional `race`,
`updated` and `caption`. The app bounds caption to 2,000 characters. It escapes
each text line before adding generated `<br>` separators. Public snapshots omit
fractional progress, raw OCR evidence, account identifiers, screenshots and data
URIs. Icons reuse existing numeric `File:item_ID.png` assets; no game artwork is
uploaded by this flow.

Totals use `total_` plus a canonical lowercase key:

| Group | Keys |
| --- | --- |
| Resources | `hp`, `mp`, `end` — maximum values only, selected by the app's shared totals helper |
| AC components | `ac_mitigation`, `ac_mitigation_softcap`, `ac_evasion` |
| ATK components | `atk_offense`, `atk_accuracy` |
| Legacy fallbacks | `ac`, `atk` — suppressed when their explicit component fields are present |
| Attributes | `str`, `sta`, `agi`, `dex`, `wis`, `int`, `cha` |
| Resists | `sv_magic`, `sv_fire`, `sv_cold`, `sv_poison`, `sv_disease`, `sv_corruption` |
| Optional observed/computed values | `hp_regen`, `mana_regen`, `end_regen`, `haste`, `velocity`, `primary_dps`, `secondary_dps`, `ranged_dps` |

A value is a finite decimal number, `?` for unknown, or a supplied estimate with
trailing ` *`. The template performs no item scaling or game-stat calculations.
It never infers DPS, velocity, caps, buffs or missing numbers. The single totals
panel has Character and Stats/resists groups, with no base/gear/current columns.

The 24 slot prefixes are `ear1`, `neck`, `face`, `head`, `ear2`, `finger1`,
`wrist1`, `arms`, `hands`, `wrist2`, `finger2`, `shoulders`, `chest`, `back`,
`waist`, `legs`, `feet`, `primary`, `secondary`, `range`, `ammo`, `charm`,
`any1`, `any2`. There is no Power slot. Each accepts `_item` (canonical page
title), `_tier` (0–10), `_icon` (existing numeric icon ID), `_stats`, and
`_augment_slots` (unknown/omitted or 0–8). Sparse `_augment_1_item`, `_tier`,
`_icon`, `_stats` through `_augment_8_*` use human socket numbers 1–8; the app
maps its zero-based indexes to them. Known empty sockets display Empty. Unknown
capacity emits no coverage warning. Any known item may be an augment; no
equipment-slot restriction is imposed on socket contents.

`_stats` contains reviewed actual-tier `Label: number` / `Label: ?` lines,
optionally marked ` *`, separated by generated `<br>`. App serialization must
validate numeric values/icon IDs and canonical link titles and entity-escape
ampersands, angle brackets, quotes, braces, pipes, brackets and tildes in text.
This prevents parameter/template injection and accidental signature expansion.
Hand editing remains ordinary sanitized MediaWiki wikitext, not trusted raw HTML.

## Publication and recovery

Read the current revisions of both fixed template pages immediately before
publication. An absent page requires expected revision `0` (create only). First
run the helper without `--apply`; it checks the reviewed sources and revisions
but saves nothing:

```sh
php maintenance/run.php ./ops/sync-character-sheets.php --expected-sheet=0 --expected-item=0
```

Only an owner-approved content publication adds `--apply` and a fresh private
`--backup=/outside/webroot/character-sheet-before.json`. The backup stores both
prior sources/revisions (including absent pages) and is verified before any save.
The helper installs the item template first, using a per-page compare-and-swap
guard, then the main template. It never edits user pages. A partial failure leaves
the backup and printed successful revisions intact; read fresh revisions and
review the remainder before retrying with a new backup. Never overwrite a
concurrent editor to roll back. Restore only reviewed template revisions; undo
new-page creation separately with normal owner permissions.

`ops/` is excluded from application releases. Copying the reviewed publisher and
sources to a private host directory and running it are separate operations, not
an automatic consequence of deploying the skin. Purge/reparse affected pages
only as an explicitly coordinated content operation if prior cached markup
predates module installation.

## Checks and limits

```sh
node ops/build-character-sheet-templates.cjs --check
php ops/test-character-sheet-publisher.php
php maintenance/run.php ./ops/test-character-sheet.php
NODE_PATH=/private/jsdom/node_modules node ops/test-character-sheet.cjs
```

The PHP check requires an existing private MediaWiki runtime/database. It injects
both template revisions in memory and runs actual MediaWiki parsing, checks 24
slots per sheet, same-name/different-server sheets, captured item/augment links,
known empty sockets, unknown/estimate preservation, AC/ATK separation, module
registration and absence of duplicate TOC headings. It saves no wiki pages.
`--output=/private/synthetic.html` can retain only the synthetic parse for visual
checks. The DOM test accepts that output via `--html`, checks independent
collapsed sheets, pointer/keyboard inspect, dynamic hook idempotence and no-JS
content, including the pointer gap and keyboard entry into socket links. These
checks do not establish live deployment or authenticated editing.

Publisher equality accepts CRLF normalization and one optional final LF removed
by MediaWiki's pre-save transform. It preserves all other whitespace/content
differences and retains the exact original recovery text and revision guards.
