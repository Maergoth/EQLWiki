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
assert.equal( engine.revision, 4 );
assert.equal( engine.statFamily( 'Backstab DMG' ), 'backstab' );
assert.equal( engine.statFamily( 'Mana' ), 'primary' );
assert.equal( engine.statFamily( 'Endurance Regen' ), 'flat' );
assert.equal( engine.statFamily( 'SV Void' ), 'flat' );
assert.equal( engine.statFamily( 'Cold DMG' ), null );
assert.deepEqual( engine.normalizeRank( 0, 40 ), { full: 0, fraction: 0, denom: 1 } );
assert.deepEqual( engine.normalizeRank( 10, 1000 ), { full: 10, fraction: 0, denom: 1024 } );
assert.deepEqual( engine.normalizeRank( 4, 900 ), { full: 4, fraction: 15, denom: 16 } );
assert.deepEqual( engine.normalizeRank( -2, -5 ), { full: 0, fraction: 0, denom: 1 } );
assert.deepEqual( engine.normalizeRank( '4.9', '8.9' ), { full: 4, fraction: 8, denom: 16 } );

// Expected values are screenshot/model boundaries, not calls back into the engine.
const cases = [
	[ 'STR', 0, 5, 0, 0 ], [ 'STR', 10, 5, 16, 15 ], [ 'HP', 11, 5, 0, 16 ],
	// Independent screenshot observations, using the current wiki stats as bases.
	[ 'HP', 50, 5, 4, 75 ], [ 'AC', 14, 6, 5, 22 ], [ 'HP', 50, 4, 4, 71 ],
	[ 'HP', 100, 4, 8, 145 ], [ 'STR', -4, 3, 0, -1 ], [ 'STR', -4, 5, 0, 0 ],
	// No screenshot establishes negative rounding; retain its existing behavior.
	[ 'HP', -50, 5, 4, -24 ],
	[ 'HP', -30, 5, 0, -15 ], [ 'HP', -11, 9, 511, 0 ], [ 'HP', 100, 10, 900, 200 ],
	[ 'DMG', 20, 4, 8, 29 ], [ 'DMG', -4, 5, 0, -4 ], [ 'DMG', 0, 5, 0, 0 ],
	[ 'BACKSTAB_DMG', 12, 5, 0, 15 ], [ 'BACKSTAB', 9, 4, 8, 11 ],
	[ 'BACKSTAB', -4, 10, 0, -4 ], [ 'BACKSTAB', 0, 10, 0, 0 ],
	[ 'RANGE', 100, 4, 15, 140 ], [ 'RANGE', -10, 4, 0, -10 ], [ 'RANGE', 0, 2, 0, 20 ],
	[ 'HP_REGEN', 4, 4, 15, 8 ], [ 'HASTE', 0, 4, 0, 0 ], [ 'MANA_REGEN', -2, 4, 0, -2 ],
	// Intrinsic Void keeps its listed base and adds only whole tiers, even above10.
	[ 'SV_CORRUPTION', 3, 5, 4, 8 ], [ 'SV_VOID', 20, 5, 31, 25 ],
	[ 'SV_CORRUPTION', 3, 10, 900, 13 ], [ 'SV_CORRUPTION', 0, 5, 0, 0 ],
	[ 'SV_CORRUPTION', -3, 5, 0, -3 ], [ 'SV_CORRUPTION', null, 5, 0, null ],
	// Decimal display ceiling must not add a tenth at an exact boundary.
	[ 'WT', 10, 1, 0, 9.1 ], [ 'WT', 10, 10, 0, 1 ], [ 'WT', 0.1, 10, 0, 0.1 ],
	[ 'WT', 2.5, 8, 0, 0.7 ], [ 'WT', 2.5, 10, 0, 0.3 ], [ 'WT', 1, 10, 0, 0.1 ],
	[ 'WT', 5, 2, 0, 4.1 ], [ 'WT', 2.55, 8, 0, 0.8 ],
	[ 'WT', 2.5000000001, 8, 0, 0.8 ], [ 'WT', 2.4999999999, 8, 0, 0.7 ],
	[ 'WT', 2.500000000000001, 8, 0, 0.8 ],
	[ 'COLD_DMG', 4, 10, 0, 4 ], [ 'DELAY', 19, 10, 0, 19 ], [ 'DMG_BONUS', 9, 10, 0, 9 ]
];
for ( const [ key, base, full, fraction, expected ] of cases ) {
	assert.equal( engine.scaleStat( key, base, full, { fraction } ), expected,
		`${key} ${base} at ${full}+${fraction}` );
}
let weightCases = 0;
// Independent integer oracle for every displayed base tenth through weight100.
for ( let tenths = 0; tenths <= 1000; tenths++ ) {
	for ( let rank = 0; rank <= 10; rank++ ) {
		const expected = Math.floor( ( tenths * ( 100 - 9 * rank ) + 99 ) / 100 ) / 10;
		assert.equal( engine.scaleStat( 'WT', tenths / 10, rank ), expected,
			`decimal ceiling for ${tenths}/10 at rank ${rank}` );
		weightCases++;
	}
}
// Genuine fractional ranks keep the reviewed logarithmic reduction unchanged.
for ( let rank = 1; rank < 10; rank++ ) {
	for ( let fraction = 1; fraction < 2 ** rank; fraction++ ) {
		for ( const base of [ 0.2, 0.3, 1, 2.5, 5, 100 ] ) {
			const expected = Math.max( 0, Math.ceil( base * ( 1 - 0.09 *
				( Math.log( 2 ** rank + fraction ) / Math.LN2 ) ) * 10 ) / 10 );
			assert.equal( engine.scaleStat( 'WT', base, rank, { fraction } ), expected );
			weightCases++;
		}
	}
}
// The arithmetic works with reviewed decimal rates/precision, not hardcoded9%.
for ( const [ rate, thousandths ] of [ [ 0, 0 ], [ 0.003, 3 ], [ 0.07, 70 ], [ 0.1, 100 ] ] ) {
	for ( const precision of [ 0, 1, 2, 3 ] ) {
		const customRules = clone( rules ); customRules.weight.perLog2 = rate; customRules.weight.precision = precision;
		const custom = core.createEngine( customRules ), factor = 10n ** BigInt( precision );
		for ( const hundredths of [ 11, 25, 100, 255, 500, 1001 ] ) {
			for ( let rank = 1; rank <= 10; rank++ ) {
				const numerator = BigInt( hundredths ) * BigInt( 1000 - thousandths * rank ) * factor;
				const expected = Number( ( numerator + 99999n ) / 100000n ) / Number( factor );
				assert.equal( custom.scaleStat( 'WT', hundredths / 100, rank ), expected );
				weightCases++;
			}
		}
	}
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
assert.equal( engine.voidBonus( [ 'HP', 'AC' ], 5 ), 5 );
assert.equal( engine.voidEligible( { AC: 0, HP: 0 } ), true, 'known zero primary fields still qualify' );
for ( const test of [
	{ name: 'Diamondine Earring', stats: { AC: 2, HP: 12, STR: 3 }, rank: 5, expected: 5 },
	{ name: 'Talisman of Kejaar Kerrath', stats: { HP_REGEN: 2, MANA_REGEN: 2, END_REGEN: 2 }, rank: 5, expected: 5 },
	{ name: 'Serpent Tooth', stats: { STR: 3, DMG: 13, BACKSTAB: 13 }, rank: 8, expected: 0 },
	{ name: 'Glowing Iron Pike', stats: { DMG: 9, DELAY: 36 }, rank: 4, expected: 0 },
	{ name: 'Single armor field', stats: { AC: 15 }, rank: 3, expected: 0 }
] ) {
	assert.equal( engine.voidBonus( test.stats, test.rank ), test.expected, `${test.name}: observed Void eligibility` );
	const scaled = engine.scaleItemStats( test.stats, test.rank );
	assert.equal( Object.prototype.hasOwnProperty.call( scaled, 'SV_CORRUPTION' ), test.expected > 0,
		`${test.name}: generated Void presence` );
	if ( test.expected ) { assert.equal( scaled.SV_CORRUPTION, test.expected ); }
}
for ( const excluded of [ 'HASTE', 'ATK', 'DMG', 'BACKSTAB', 'DELAY', 'WT' ] ) {
	assert.equal( engine.voidEligible( { AC: 2, [ excluded ]: 5 } ), false, `${excluded} cannot be a second Void qualifier` );
}
const voidAliases = [ 'SV_CORRUPTION', 'SV Corruption', 'SV.Corruption', 'SVCorruption',
	'SV_VOID', 'SV Void', 'SV.Void', 'SVVoid', 'Void', 'Corruption', 'ResistVoid', 'ResistCorruption' ];
for ( const alias of voidAliases ) {
	assert.equal( engine.canonicalStatKey( alias ), 'SV_CORRUPTION', `${alias}: canonical identity` );
	for ( const base of [ 0, -3, 3, 20, null ] ) {
		for ( const [ full, fraction, rank ] of [ [ -2, 10, 0 ], [ 0, 5, 0 ], [ 5, 31, 5 ], [ 11, 1, 10 ] ] ) {
			const stats = { STR: 5, DEX: 5, [ alias ]: base };
			assert.deepEqual( engine.scaleItemStats( stats, full, { fraction } ), {
				STR: rank ? 5 + rank : 5, DEX: rank ? 5 + rank : 5,
				[ alias ]: base > 0 ? base + rank : base
			}, `${alias}/${base}: exact explicit field suppresses a generated duplicate at ${full}+${fraction}` );
		}
	}
	assert.deepEqual( engine.scaleItemStats( { [ alias ]: 3 }, 5 ), { [ alias ]: 8 },
		`${alias}: intrinsic scaling does not require contextual qualifiers` );
	assert.equal( engine.voidEligible( { AC: 2, [ alias ]: 3 } ), false,
		`${alias}: intrinsic Void is not a second generated-Void qualifier` );
}
assert.deepEqual( engine.scaleItemStats( { AC: 0, HP: 0 }, 5, { fraction: 31 } ),
	{ AC: 0, HP: 0, SV_CORRUPTION: 5 }, 'absent Void still uses qualified whole-tier generation' );
assert.deepEqual( engine.scaleItemStats( { DMG: 3 }, 5, { slots: [ 'AMMO' ] } ),
	{ DMG: 4 }, 'an absent unqualified field is not assumed to have base zero' );
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
	value => { value.void.minimumQualifiers = 20; } ] ) {
	const invalid = clone( rules ); mutate( invalid );
	assert.equal( core.validateRules( invalid ), false );
	assert.throws( () => core.createEngine( invalid ), /Unsupported/ );
}
const changed = clone( rules ); changed.revision = 4; changed.range.perTier = 20;
const changedEngine = core.createEngine( changed ); changed.range.perTier = 99;
assert.equal( changedEngine.scaleStat( 'RANGE', 100, 2 ), 140, 'validated rules own an immutable copy' );
assert.equal( engine.scaleStat( 'RANGE', 100, 2 ), 120, 'existing engine remains stable during review' );
assert.equal( Object.isFrozen( changedEngine.rules.range ), true );

const browser = {};
vm.runInNewContext( fs.readFileSync( path.join( directory, 'item-scaling-core.js' ), 'utf8' ), browser );
assert.equal( browser.EQLItemScalingCore.createEngine( rules ).scaleStat( 'BACKSTAB', 12, 5 ), 15,
	'the exact same bytes run as a browser script without require/eval of downloaded data' );
assert.equal( browser.EQLItemScalingCore.createEngine( rules ).scaleStat( 'WT', 2.5, 8 ), 0.7,
	'the browser engine uses the same exact decimal weight ceiling' );
assert.equal( browser.EQLItemScalingCore.createEngine( rules ).scaleStat( 'SVVoid', 20, 5 ), 25,
	'the browser and CommonJS engine preserve the same intrinsic Void base' );
console.log( `Shared item scaling: ${cases.length} stat cases, ${weightCases} weight boundaries/states, contextual fields, strict JSON validation, versioning and browser/CommonJS parity pass.` );
