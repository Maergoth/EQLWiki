# Interactive Character sheets

`Template:Character_sheet` and `Template:Character_sheet_item` render a public,
hand-editable inventory snapshot. A page with one Character (Server) opens it by
default; multiple characters start collapsed. Each disclosure is keyboard
accessible and independent of other characters on the page. Without JavaScript the static sheet remains
readable. Item icons link to their existing wiki pages. Hovering or focusing gear
shows its supplied tier-specific stat card, reviewed rich metadata/effect links
and clickable known Exaltations. Socketed Exaltations show their name and effect
only; their numeric stats, tier and unrelated equipment metadata are omitted.
The stylized **View Other Players** link below equipment opens `Player_List`.
An optional achievement export appears below the equipment and statistics.
The left side contains an optional public Character screenshot. Without one,
the red **Add an image** box opens the normal wiki upload page with its PNG
filename preselected. On narrow layouts it stacks above the existing totals and
equipment instead of reserving an empty column. Neither the image nor its
placeholder changes recorded statistics, tiers, sockets or achievements.
Achievements and their categories have independent, initially collapsed
disclosures. Each achievement and objective keeps its explicit Complete or
Incomplete marker, including objective counters; completing every objective
does not change an incomplete parent. Without JavaScript the full list remains
readable. Old sheets without achievements render exactly as before.
The skin displays the completed count in gold serif numerals on both the outer
Character header and inner Achievements header, including while collapsed. It
reads the existing fixed summary and keeps explicit zero distinct from missing
data. This badge and the Player List's Achievement count sort need only a source
deployment; they require no new app payload or database-template publication.
The interactive inspect is a labeled nonmodal dialog. A short 200 ms pointer
grace period allows crossing the gap from gear to its links; entering the card
cancels dismissal. Keyboard users can press ArrowDown from gear to enter those
links, and Escape returns focus to the gear. Focus leaving the card, page scroll,
resize or collapsing a character dismisses it. Without enhancement, all item
equipped statistics and socket name links stay visible in the static sheet.

This uses the already loaded EQLImmersive skin, not the historical Magelo PHP
extension. No new extension, namespace or database schema is enabled. The
server enforces the Character-image ownership policy described below; the
browser module makes no account-state or storage writes. A
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
- The skin's read-only `CharacterScreenshot` parser tag handles the optional
  image and is called by the main template. It validates a local File title and
  delegates both present and missing images to the native File parser, retaining
  ParserOutput media dependencies. A later upload to the default filename can
  therefore invalidate the cached User page normally; no image polling occurs.

The app preflights both template sources for `EQL_CHARACTER_SHEET_V1` and
`EQL_CHARACTER_SHEET_ITEM_V1`, then uses an unsaved `action=parse` request with a
synthetic populated `head_item`. It requires `.eql-character-sheet-v1`, the
helper's `.eql-character-inspect-v1` and the exact module in `parse.modules`.
For an achievement-bearing share it additionally requires
`EQL_CHARACTER_ACHIEVEMENTS_V1` in the main template and an unsaved parse that
retains `.eql-character-achievements-v1` and its supplied probe. A legacy wiki
template must not silently discard the imported list. Shares without an
achievement export still use the original schema-1 capability.
Missing templates or deployment capability must prevent publishing a broken
sheet. Parser/module availability does not grant edit permission.

## Flat parameter contract

The optional `achievements` parameter is generated, bounded HTML containing
`.eql-character-achievement-category`, its category heading/list, individual
`.eql-character-achievement` rows, and optional objective rows with progress.
The app validates the shared export DTO before serialization, recomputes counts,
and entity-escapes every text field before inserting fixed HTML. It publishes no
file path, hash, raw file bytes or inferred completion. The public JSON payload
is limited to 512 KiB, generated template source to 1 MiB and updated User page
to 2 MiB; larger data is rejected without partial publication or truncation.
Existing schema-1 gear, totals, identity and item-helper parameters are unchanged.

`node ops/test-character-achievements.cjs` checks the optional static markup,
independent nested disclosures and dynamic initialization using synthetic DOM.
The normal template generator `--check` and Character sheet parser/DOM checks
continue to cover the base contract. Publishing a new main template remains a
separate revision-guarded database change after deploying the reviewed skin.

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
URIs. The separate optional screenshot feature references a reviewed public
upload rather than embedding capture bytes in template source. Icons reuse
existing numeric `File:item_ID.png` assets.

## Optional Character screenshot (schema 1)

The main template advertises `EQL_CHARACTER_SCREENSHOT_V1`. A client adding an
image must also confirm that an unsaved parse retains
`.eql-character-screenshot-v1`, so a marker alone cannot authorize a discarded
image. Existing clients and shares without an image keep the schema-1 contract.

`screenshot` is an optional **bare filename**, such as
`Character Example - Example portrait.png` on `User:Example`;
do not supply `File:`, a URL, HTML or a data URI. PNG, JPG/JPEG, GIF and WebP are
accepted within native MediaWiki title rules and a 240-byte UTF-8 filename limit.
Controls, wiki delimiters, fragments, backslashes, protocol/namespace prefixes
and absolute-path prefixes are rejected. Interior slashes from a User subpage
are only title characters and are never treated as filesystem paths. Native
File-title normalization handles underscores, spaces, Unicode and initial case.
Invalid or overlong input leaves the entire ordinary sheet intact. Portrait
names must also belong to the page owner's reserved Character-image prefix.
An arbitrary shared filename cannot become private to an account merely by
being placed in `screenshot`; use a dedicated owned portrait instead.

With `screenshot` missing or blank, the default is:

```
Character <PAGENAME> - <name> (<server>).png
```

`PAGENAME` is the full current page title without its namespace, including any
subpage; it is not `ROOTPAGENAME`. For example, on `User:Example/Subpage`,
`name=Reader` and `server=Test` select
`File:Character Example/Subpage - Reader (Test).png`. Distinct page owners do not
share a default accidentally. The app uses the canonical authenticated own
User title and resolves the candidate with the wiki's native File-title API
normalization before upload.

An existing default file displays automatically even if no screenshot parameter
was ever saved. The upload placeholder links to native `Special:Upload` with
the exact encoded `wpDestFile` and current `eqlReturnTo`; the existing upload
return/purge module supplements normal File dependency invalidation. The default
prompt requests PNG. A manually uploaded JPEG under a different filename needs
an explicit `screenshot=...jpg` override. Display uses a normal MediaWiki
thumbnail/frameless File image linking to the full file page, with contain sizing
and no external image element supplied by the client.

New app reshares preserve an existing valid explicit override when no new photo
is selected. Older released clients replace their whole managed template block
and can remove a new explicit parameter they do not know. The deterministic
default survives such a reshare; nondefault overrides may need to be restored
after an older client reshares. The wiki does not intercept or rewrite those
edits. Files remain normal public wiki uploads; no account, private capture,
inventory or User-page writes are performed by this renderer.

### Image ownership and upload completion

User-page edit protection does not extend to `File:` pages. The registered skin
hooks separately reserve `Character <owner> - ...` filenames to the named wiki
account, including first uploads before a character sheet exists. User subpages
belong to their root account. Native title and account normalization apply;
ambiguous owner prefixes are refused rather than assigned by guessing where a
name ends. The existing `editalluserpages` maintenance right permits repairs.
Ordinary upload, block, protection and email requirements still apply.

The server checks new uploads, replacement uploads, publication from a stash,
file-description edits, binary reverts and both endpoints of file moves.
Removing or forging `eqlReturnTo` does not change ownership. Ordinary item/NPC
artwork keeps its existing permissions. A shared filename override or redirect
to an unowned/shared target cannot be used as an owned Character portrait;
the sheet keeps its totals and equipment and explains how to use an owned
filename. No existing file is renamed or copied by rendering.

Successful Character uploads invalidate the validated owner's affected page
through native cache mechanisms. The browser's supplementary return tracker
binds `#mw-upload-form`, reads its submitted destination and preserves native
warning/stash retries. A passive `formdata` listener observes submitted names
even when a delayed handler calls native `form.submit()` directly. It never
assigns a filename, replaces the selected files, prevents submission or encodes
image bytes; existing name matching and format conversion remain with their
original handlers. This is best-effort navigation context; arbitrary external
listener ordering is not an authorization or cache-correctness dependency. The
server's completion hook uses the actual committed File title. The tracker's
short-lived session record is account-bound and
storage failures do not prevent the upload. Return parameters and browser
storage are navigation hints, never proof of ownership.

Use the focused ownership and upload-return checks listed in the development
guide. Native permission/parser integration checks inspect synthetic titles
and unsaved parses without uploading images or editing User pages. Deployment
registers these hooks normally; no database schema, template publication,
historical File ownership migration or general artwork permission change is
required. See MediaWiki's [User-page protection extension](https://www.mediawiki.org/wiki/Extension:UserPageEditProtection)
and [upload verification hook](https://www.mediawiki.org/wiki/Manual:Hooks/UploadVerifyUpload)
for the standard platform interfaces.

Deploy the parser helper/skin registration and CSS **before** the separately
reviewed main-template update. This change does not require an item-helper
revision. Recheck both current template revisions and back up their exact sources
privately; publish only the changed main template with its expected-revision
compare-and-swap guard. Never edit existing User pages to add this feature.

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
php maintenance/run.php ./ops/test-character-screenshot.php --existing-file=Item_500.png
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
The screenshot check uses actual MediaWiki Title normalization and unsaved
template parsing. It verifies safe fallback, entity/non-ASCII/subpage/default
identity, overrides, unchanged totals/slots and native media dependencies for
missing and present files. The optional existing-file argument must name a
current public local image. This is not a live upload or deferred-job test.

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
