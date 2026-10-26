# Interactive Character sheets

`Template:Character_sheet` and `Template:Character_sheet_item` render a public,
hand-editable inventory snapshot. A page with one Character (Server) opens it by
default; multiple characters start collapsed. Each disclosure is keyboard
accessible and independent of other characters on the page. Without JavaScript the static sheet remains
readable. Item icons link to their existing wiki pages. Hovering or focusing gear
shows its supplied tier-specific stat card, reviewed rich metadata/effect links
and clickable known Exaltations. Socketed Exaltations show their name and effect
only; their numeric stats, tier and unrelated equipment metadata are omitted.
The stylized **View Other Players!** link below equipment opens `Player_List`.
The interactive inspect is a labeled nonmodal dialog. A short 200 ms pointer
grace period allows crossing the gap from gear to its links; entering the card
cancels dismissal. Keyboard users can press ArrowDown from gear to enter those
links, and Escape returns focus to the gear. Focus leaving the card, page scroll,
resize or collapsing a character dismisses it. Without enhancement, all item
equipped statistics and socket name links stay visible in the static sheet.

This uses the already loaded EQLImmersive skin, not the historical Magelo PHP
extension. No new extension, namespace, database schema or authentication policy
is enabled. The browser module makes no account-state or storage writes. A
memoized, bounded public effect-metadata lookup fills absent effects in old
snapshots without changing supplied values. See [Player List](PLAYER_LIST.md)
for its directory, effect-enrichment and public-read safeguards.
The desktop app owns reviewed, revision-guarded own User-page upserts; templates never
write pages. A new snapshot updates that character's managed block and preserves
other characters and the owner's unrelated User-page text. Old User_talk blocks
stay unchanged; saved attempts are destination-bound and require a fresh review
when moving to the User page.

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
|feet_details=Class: ROG<br>Effect: [[Rampage|Rampage (Combat, Casting Time: Instant) at Level 45]]
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
| Optional observed values | `hp_regen`, `mana_regen`, `end_regen`, `attack_speed`, `haste`, `velocity`, `primary_dps`, `secondary_dps`, `ranged_dps` |
| Additional modifiers | `accuracy`, `avoidance`, `combat_effects`, `strikethrough`, `stun_resist`, `damage_shield`, `damage_shield_mitigation`, `dot_shielding`, `melee_shielding`, `spell_shielding` |

A value is a finite decimal number, `?` for unknown, or a supplied estimate with
trailing ` *`. The template performs no item scaling or game-stat calculations.
It never infers DPS, velocity, caps, buffs or missing numbers. The single totals
panel has Character and Stats/resists groups, with an optional Additional
modifiers group and no base/gear/current columns. Starting with Eye of Zomm
0.9.7, exported totals come only from the reviewed fully equipped, unbuffed
stat reading. Equipment/catalog values and old unequipped evidence are never
added to those totals. HP, Mana and Endurance retain only the observed maximum
values. Earlier hand-edited snapshots can still carry an explicit ` *` marker.

The app serializes the following observed keys to fixed parameters. They are
displayed verbatim: `0` remains zero, an explicit `?` stays unknown, and an
omitted modifier produces no row. Older sheets without these fields do not gain
an empty group. `total_accuracy` is the Additional Modifiers accuracy bonus;
`total_atk_accuracy` remains the separate ATK Accuracy component. An observed
`total_attack_speed` takes precedence over the older `total_haste` display.

| App stat key | Template parameter | Display label |
| --- | --- | --- |
| `ACCURACY` | `total_accuracy` | Accuracy bonus |
| `AVOIDANCE` | `total_avoidance` | Avoidance |
| `COMBAT_EFFECTS` | `total_combat_effects` | Combat effects |
| `STRIKETHROUGH` | `total_strikethrough` | Strikethrough |
| `STUN_RESIST` | `total_stun_resist` | Stun resist |
| `DAMAGE_SHIELD` | `total_damage_shield` | Damage shield |
| `DAMAGE_SHIELD_MITIGATION` | `total_damage_shield_mitigation` | Damage shield mitigation |
| `DOT_SHIELDING` | `total_dot_shielding` | DoT shielding |
| `MELEE_SHIELDING` | `total_melee_shielding` | Melee shielding |
| `SPELL_SHIELDING` | `total_spell_shielding` | Spell shielding |

This extends only optional display parameters within schema 1. The template
markers, skin module and app capability probe are unchanged; no extension or
database schema change is needed. Publishing the reviewed main template is a
separate guarded content operation after release coordination, not a result of
pushing its generator to Git.

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
The app exports only known canonical wiki gear/augment identities. Unresolved
inventory-export names or IDs do not establish a page: leave those slots empty
in the public sheet and resolve them in the app before sharing.

`_stats` contains reviewed actual-tier `Label: number` / `Label: ?` lines,
optionally marked ` *`, separated by generated `<br>`. App serialization must
validate numeric values/icon IDs and canonical link titles and entity-escape
ampersands, angle brackets, quotes, braces, pipes, brackets and tildes in text.
Underscores in user text must also be escaped so behavior switches such as
`__NOINDEX__` and `__TOC__` remain literal text.
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
php maintenance/run.php ./ops/test-character-sheet.php
NODE_PATH=/private/jsdom/node_modules node ops/test-character-sheet.cjs
```

The PHP check requires an existing private MediaWiki runtime/database. It injects
both template revisions in memory and runs actual MediaWiki parsing, checks 24
slots per sheet, same-name/different-server sheets, captured item/augment links,
known empty sockets, unknown/estimate preservation, distinct ATK Accuracy and
accuracy bonus, all ten observed modifiers, legacy omissions, Attack speed
precedence, AC/ATK separation, module
registration and absence of duplicate TOC headings. It saves no wiki pages.
`--output=/private/synthetic.html` can retain only the synthetic parse for visual
checks. The DOM test accepts that output via `--html`, checks independent
collapsed sheets, pointer/keyboard inspect, dynamic hook idempotence and no-JS
content, including the pointer gap and keyboard entry into socket links. These
checks do not establish live deployment or authenticated editing.

## Rich item metadata (schema 1 additive fields)

Each fixed equipment prefix accepts `_details`; indexed Exaltations accept
`_augment_1_details` through `_augment_8_details`. The app emits at most32 reviewed
`{label,value,linkTitle?}` lines/item, labels<=60/value<=400 and8000 UTF-8 bytes.
Its serializer escapes all untrusted text including underscores/signatures,
then generates only `<br>` and canonical main-namespace effect links. Numeric
`_stats` remains a separate supplied tier-specific block for equipped gear.
Legacy indexed Exaltation numeric fields remain accepted but are not displayed;
only effect metadata is shown for sockets. The wiki computes no
item/Character totals and does not add Exaltations to recorded current stats.
Hand-edited wiki source uses ordinary wiki permissions/sanitization.

The Itempage-to-Itembox AjaxHoverHelper adapter also forwards the existing
`focus_effect` parameter; this preserves real focus metadata rather than
inventing or reparsing it. Snapshot cards retain their supplied tier values and
metadata instead of fetching a different base-tier card on every hover.
