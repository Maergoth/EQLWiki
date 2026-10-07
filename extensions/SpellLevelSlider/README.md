# SpellLevelSlider

`SpellLevelSlider` is a standalone legacy-style MediaWiki extension for the
EverQuest Legends Wiki. It adds a whole-number spell-level control to supported
individual spell pages and scales:

- Casting Time
- Mana
- Duration, when the category has a duration rate and the value contains a
  recognized time unit
- Explicit summoned-pet level, by one level per slider rank
- Charm maximum-level caps, through the separate charm-cap adjustment

It does not contain or reuse any ItemLevelSlider formulas.

## Why no template edit is required

Both `Template:Spellpage` and the full-page mode of
`Template:Spellpagesmart` render the same stable structure:

- `.eql-spellpage`
- `.eql-spellpage-slot-table`
- `.eql-spellpage-detail-table`

The extension reads the labeled cells in those tables. It does not require
changes to individual spell articles, `Template:Spellpage`, or
`Template:Spellpagesmart`.

Class spell-list transclusions use the table mode of `Spellpagesmart` and do not
render `.eql-spellpage`, so the slider is intentionally limited to full spell
pages and any full spell cards loaded dynamically.

## Installation

1. Create:

   `extensions/SpellLevelSlider/`

2. Upload these files into that directory:

   - `SpellLevelSlider.php`
   - `spelllevelslider.js`
   - `spelllevelslider.css`

3. Add this near the bottom of `LocalSettings.php`:

   ```php
   require_once "$IP/extensions/SpellLevelSlider/SpellLevelSlider.php";
   ```

4. Create the wiki page `SpellLevelSliderOverrides` and paste in the contents
   of `SpellLevelSliderOverrides.wiki`.

5. Hard-refresh a spell page after uploading.

## Rate profiles

All rates are stored once in `$wgSpellLevelSliderRules` inside
`SpellLevelSlider.php`.

| Category | Cast per level | Mana per level | Duration per level |
| --- | ---: | ---: | ---: |
| Nuke / lifetap | −2% | −2% | n/a |
| DoT | −4% | −2% | +5% |
| Heal | −4% | −2% | n/a |
| HoT | −4% | −2% | +5% |
| Debuff | −4% | −4% | +10% |
| Charm / mez | −4% | −4% | +10% |
| Buff | −4% | −4% | +10% |
| Pet | unchanged | unchanged | unchanged |

Pet rules add one to an explicitly stated summoned-pet level per slider rank.
Charm level caps use `applyCharmCapScaling()`; they are separate from the
percentage duration/cast/mana rules below.

Scaling is linear from the spell page's base value:

```text
scaled value = base value × (1 + rate × spell level)
```

At spell level 4, for example, a buff receives −16% casting time, −16% mana,
and +40% duration.

Formatting rules:

- Spell levels are integers from 0 through 10.
- Casting time is shown to two decimal places.
- Mana is rounded to the nearest whole number.
- Duration numbers are rounded to at most two decimal places.
- `Instant` and `Permanent` durations remain unchanged.
- Level annotations such as `@L44` are not changed.

## Classification

The classifier reads Spell Type, Target Type, Duration, Spell Effects, and the
overview text. Its precedence is:

1. Explicit page override
2. Damage Over Time
3. Heal Over Time
4. Summoned pet with an explicit level
5. Charm / mez
6. Heal
7. Nuke / lifetap
8. Debuff
9. Buff

This ordering handles important edge cases:

- `Envenomed Breath` is identified from `Damage Over Time`.
- `Burning Arrow` is identified as a DoT from its `per tick` damage despite
  using the generic `Detrimental` spell type.
- `Lifespike` is identified from the `Lifetap` target type.
- `Enthrall` and `Charm` are separated from generic detrimental debuffs.
- Short, single-effect HP-per-tick spells such as `Celestial Elixir` and
  `Pact of Shadow` are HoTs.
- Long regeneration/stat packages remain buffs instead of becoming HoTs.
- Beneficial instant utility spells such as teleports and summons are left
  unsupported rather than incorrectly treated as buffs.

## Classification overrides

Odd spells can be corrected centrally without editing their wiki articles.
The wiki page `SpellLevelSliderOverrides` is the single source of truth for
manual overrides.

Add one entry per line inside the page's
`<pre id="spell-level-slider-overrides">` block:

```text
Form of the Bear = buff
Some Unusual Elixir = hot
```

Accepted category keys are:

- `nuke_lifetap`
- `dot`
- `heal`
- `hot`
- `debuff`
- `charm_mez`
- `buff`
- `pet`

Wiki links are also accepted on the left side:

```text
[[Some Unusual Elixir]] = hot
```

On EQLWiki, EQLClientData injects the override map into the page. The slider also
supports a raw-page fetch fallback, cached for 30 seconds. Overrides take
priority over automatic classification. Invalid lines and unknown category keys
are ignored. Keep `$wgSpellLevelOverridesPage` and
`$wgEQLClientDataSpellOverridesTitle` aligned when renaming the page.

When a spell cannot be classified automatically and has no override, the
extension displays `Spell Scaling Unknown. Adjust Category or Override` in
place of the slider. `Override` links to `SpellLevelSliderOverrides`.

## Other configuration

Set these before loading the extension:

```php
$wgSpellLevelSliderEnabled = true;
$wgSpellLevelDefault = 0;
$wgSpellLevelMaximum = 10;
$wgSpellLevelOverridesPage = 'SpellLevelSliderOverrides';

require_once "$IP/extensions/SpellLevelSlider/SpellLevelSlider.php";
```

The selected level is stored in the browser and reused on other supported
spell pages.

For dynamically inserted full spell cards, the extension listens to
`wikipage.content` and also exposes:

```js
await window.eqlSpellLevelSliderRefresh( rootElement );
await window.eqlSpellLevelSliderSetLevel( level, rootElement );
```
