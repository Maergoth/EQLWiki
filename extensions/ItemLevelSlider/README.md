# Shared item scaling

`item-scaling-core.js` is the shared arithmetic engine. `item-scaling-rules.json`
is its sole coefficient source. The wiki slider and Eye of Zomm use that engine
instead of maintaining independent forward formulas. The portable engine and
JSON rules are MIT licensed; see `item-scaling-core.LICENSE`. Existing wiki
adapter files retain their existing licenses.

Legacy registration in `ItemLevelSlider.php` loads the engine before the DOM
adapter and injects the same JSON as `wgEQLItemScalingRules`. Template parsing,
layout, green modified values, saved slider state and dynamic-card refresh stay
in `itemlevelslider.js`. ClassSlotEquip continues to consume the same module.
No database-page synchronization or schema migration is required for this
extension's engine or rules.

## Release-time synchronization

Eye of Zomm bundles the exact engine and JSON rules from a reviewed wiki commit.
Its source-sync script records that commit and each file's SHA-256; its source
check rejects edited or mismatched copies. Formula changes therefore need one
canonical implementation change, followed by source synchronization into a new
app version. An installed app keeps its packaged formulas for its entire version,
including previews, reverse calculations and contribution review.

The app does not poll the wiki, download rules or apply formula changes while it
is running. There is no item-scaling API endpoint. A wiki formula deployment uses
the normal application deployment; the matching app formulas arrive through an
app update. ResourceLoader versions deployed JavaScript normally, and the wiki
injects the tracked rules into page config. No public API documentation page
needs publishing for this feature.

## Portable engine contract

The classic script exposes `globalThis.EQLItemScalingCore`; CommonJS consumers
receive the same API through `module.exports`. ES modules may import it for its
side effect. `createEngine(rules)` validates and freezes an independent copy.
Engines expose:

- `normalizeRank(full, fraction)`, `effectiveLevel(full, fraction)` and
  `canonicalStatKey(key)`; ranks 0 and 10 force fraction zero.
- `statFamily(key)` for primary/flat/damage/backstab/range/weight, or null for
  fields without an independent scaling family.
- `scaleStat(key, base, full, {fraction, weapon, accuracyPercent})`; null stays
  unknown. Explicit percentage Accuracy requires its weapon context; unitless
  Accuracy is preserved.
- `weaponEligible(stats, slots)`, `accuracyBonus(full, fraction)`,
  `voidEligible(statsOrKeys)` and `voidBonus(statsOrKeys, full)`. Distinct known
  zero-valued attributes/resists qualify for Void; unknown values do not.
- `scaleItemStats(stats, full, {fraction, slots, accuracyPercent})` preserves
  input keys, generates missing contextual Accuracy/Void only above tier zero,
  and preserves existing explicit Void values. The wiki DOM adapter also
  suppresses a generated duplicate when explicit Void/Corruption is present.

Only standalone weapon DMG scales. Elemental/bane DMG, delay and damage bonus
remain independent. Generated damage bonuses still belong to `Template:Itembox`.
Backstab and fractional Accuracy remain screenshot-derived working models, not
published game formulas. Matching the wiki does not turn them into verified
game mechanics. Preserve the existing ceiling/rounding behavior, including
floating-point weight boundaries, until a reviewed change updates both clients.

## Changing a formula

For coefficient changes, edit the JSON and increment its positive integer
`revision`. The validator permits only known keys and bounded coefficients;
`maxTier` remains 10. Run `node ops/test-item-scaling-core.cjs` and the existing
`node ops/test-item-level-slider.cjs` DOM suite with the documented jsdom runtime.
Update fixtures only against evidence for an intentional formula change.

A new algorithm needs a new supported `engineVersion` plus compatible client
code. Synchronize the app's vendored engine/rules using its checked source-sync
script, record the wiki commit and source hashes, then ship a new app version.
Both coefficient and algorithm changes follow this release process. Do not add
runtime formula checks or a second hand-maintained implementation in a consumer.
