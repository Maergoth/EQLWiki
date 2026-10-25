'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const directory = path.join( __dirname, '../extensions/ItemLevelSlider' );
const core = require( path.join( directory, 'item-scaling-core.js' ) );
const rules = JSON.parse( fs.readFileSync( path.join( directory, 'item-scaling-rules.json' ), 'utf8' ) );
const engine = core.createEngine( rules );
const clone = value => JSON.parse( JSON.stringify( value ) );

assert.equal( core.validateRules( rules ), true );
assert.equal( engine.revision, 1 );
assert.equal( engine.statFamily( 'Backstab DMG' ), 'backstab' );
assert.equal( engine.statFamily( 'Mana' ), 'primary' );
assert.equal( engine.statFamily( 'Endurance Regen' ), 'flat' );
assert.equal( engine.statFamily( 'Cold DMG' ), null );
assert.deepEqual( engine.normalizeRank( 0, 40 ), { full: 0, fraction: 0, denom: 1 } );
assert.deepEqual( engine.normalizeRank( 10, 1000 ), { full: 10, fraction: 0, denom: 1024 } );
assert.deepEqual( engine.normalizeRank( 4, 900 ), { full: 4, fraction: 15, denom: 16 } );
assert.deepEqual( engine.normalizeRank( -2, -5 ), { full: 0, fraction: 0, denom: 1 } );
assert.deepEqual( engine.normalizeRank( '4.9', '8.9' ), { full: 4, fraction: 8, denom: 16 } );

// Expected values are screenshot/model boundaries, not calls back into the engine.
const cases = [
	[ 'STR', 0, 5, 0, 0 ], [ 'STR', 10, 5, 16, 15 ], [ 'HP', 11, 5, 0, 17 ],
	[ 'HP', 100, 4, 8, 145 ], [ 'STR', -4, 3, 0, -1 ], [ 'STR', -4, 5, 0, 0 ],
	[ 'HP', -30, 5, 0, -15 ], [ 'HP', -11, 9, 511, 0 ], [ 'HP', 100, 10, 900, 200 ],
	[ 'DMG', 20, 4, 8, 29 ], [ 'DMG', -4, 5, 0, -4 ], [ 'DMG', 0, 5, 0, 0 ],
	[ 'BACKSTAB_DMG', 12, 5, 0, 15 ], [ 'BACKSTAB', 9, 4, 8, 11 ],
	[ 'BACKSTAB', -4, 10, 0, -4 ], [ 'BACKSTAB', 0, 10, 0, 0 ],
	[ 'RANGE', 100, 4, 15, 140 ], [ 'RANGE', -10, 4, 0, -10 ], [ 'RANGE', 0, 2, 0, 20 ],
	[ 'HP_REGEN', 4, 4, 15, 8 ], [ 'HASTE', 0, 4, 0, 0 ], [ 'MANA_REGEN', -2, 4, 0, -2 ],
	// Preserve the wiki's unadjusted IEEE-754 ceiling, including this boundary.
	[ 'WT', 10, 1, 0, 9.1 ], [ 'WT', 10, 10, 0, 1.1 ], [ 'WT', 0.1, 10, 0, 0.1 ],
	[ 'COLD_DMG', 4, 10, 0, 4 ], [ 'DELAY', 19, 10, 0, 19 ], [ 'DMG_BONUS', 9, 10, 0, 9 ]
];
for ( const [ key, base, full, fraction, expected ] of cases ) {
	assert.equal( engine.scaleStat( key, base, full, { fraction } ), expected,
		`${key} ${base} at ${full}+${fraction}` );
}
for ( const [ full, fraction, expected ] of [ [ 0, 0, 0 ], [ 1, 0, 2.5 ], [ 4, 1, 10.2 ],
	[ 4, 8, 11.4 ], [ 5, 4, 12.9 ], [ 8, 1, 20 ], [ 10, 0, 25 ] ] ) {
	assert.equal( engine.accuracyBonus( full, fraction ), expected );
}
assert.equal( engine.scaleStat( 'ACCURACY', 1.5, 5, { fraction: 4, weapon: true, accuracyPercent: true } ), 14.4 );
assert.equal( engine.scaleStat( 'ACCURACY', 4, 5, { weapon: true } ), 4, 'untyped Accuracy stays unchanged' );
assert.equal( engine.scaleStat( 'HP', null, 4 ), null, 'unknown stats stay unknown' );
assert.equal( engine.voidEligible( { STR: 0, DEX: 0 } ), true, 'known zero fields qualify' );
assert.equal( engine.voidEligible( { STR: 4, DEX: null } ), false, 'unknown fields do not qualify' );
assert.equal( engine.voidEligible( [ 'STR', 'Strength' ] ), false, 'aliases count once' );
assert.equal( engine.voidBonus( [ 'STR', 'DEX' ], 5 ), 5 );
assert.equal( engine.voidBonus( [ 'HP', 'AC' ], 5 ), 0 );
assert.equal( engine.weaponEligible( { DMG: 18 }, [ 'AMMO' ] ), false );
assert.equal( engine.weaponEligible( { DMG: 18 }, [ 'RANGE', 'AMMO' ] ), true );
assert.equal( engine.weaponEligible( { COLD_DMG: 18 }, [ 'PRIMARY' ] ), false );
assert.deepEqual( engine.scaleItemStats( { BACKSTAB_DMG: 12, DMG: 20, STR: 0, DEX: 5, HP: null },
	5, { slots: [ 'PRIMARY' ] } ), { BACKSTAB_DMG: 15, DMG: 30, STR: 0, DEX: 10, HP: null, ACCURACY: 12.5, SV_CORRUPTION: 5 } );
assert.deepEqual( engine.scaleItemStats( { STR: 0, DEX: 5, SV_CORRUPTION: 3 }, 0 ),
	{ STR: 0, DEX: 5, SV_CORRUPTION: 3 }, 'tier zero does not generate contextual fields' );

for ( const mutate of [ value => { value.schemaVersion = 2; }, value => { value.engineVersion = 2; },
	value => { value.maxTier = 11; }, value => { value.revision = 0; }, value => { value.extra = 1; },
	value => { value.primary.divisor = 0; }, value => { value.weight.perLog2 = 10; },
	value => { value.accuracy.precision = 4; }, value => { value.flat.perTier = NaN; },
	value => { value.range.perTier = -1; }, value => { value.primary = []; },
	value => { value.void.minimumQualifiers = 13; } ] ) {
	const invalid = clone( rules ); mutate( invalid );
	assert.equal( core.validateRules( invalid ), false );
	assert.throws( () => core.createEngine( invalid ), /Unsupported/ );
}
const changed = clone( rules ); changed.revision = 2; changed.range.perTier = 20;
const changedEngine = core.createEngine( changed ); changed.range.perTier = 99;
assert.equal( changedEngine.scaleStat( 'RANGE', 100, 2 ), 140, 'validated rules own an immutable copy' );
assert.equal( engine.scaleStat( 'RANGE', 100, 2 ), 120, 'existing engine remains stable during review' );
assert.equal( Object.isFrozen( changedEngine.rules.range ), true );

const browser = {};
vm.runInNewContext( fs.readFileSync( path.join( directory, 'item-scaling-core.js' ), 'utf8' ), browser );
assert.equal( browser.EQLItemScalingCore.createEngine( rules ).scaleStat( 'BACKSTAB', 12, 5 ), 15,
	'the exact same bytes run as a browser script without require/eval of downloaded data' );
console.log( `Shared item scaling: ${cases.length} stat cases, contextual fields, strict JSON validation, versioning and browser/CommonJS parity pass.` );
