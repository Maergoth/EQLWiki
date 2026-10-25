/**
 * Shared EQL item scaling kernel. The wiki owns this source and its JSON rules.
 * Consumers vendor the exact kernel and rules from reviewed source at release time.
 * Copyright (c) 2026 Maergoth. SPDX-License-Identifier: MIT
 * See item-scaling-core.LICENSE for the complete license.
 */
( function ( root, factory ) {
	'use strict';
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	root.EQLItemScalingCore = api;
}( typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';
	var PRIMARY = [ 'AC', 'HP', 'MP', 'END', 'STR', 'STA', 'AGI', 'DEX', 'WIS', 'INT', 'CHA',
		'SV_MAGIC', 'SV_FIRE', 'SV_COLD', 'SV_POISON', 'SV_DISEASE' ];
	var FLAT = [ 'HP_REGEN', 'MANA_REGEN', 'END_REGEN', 'HASTE' ];
	var VOID_QUALIFIERS = PRIMARY.concat( [ 'HP_REGEN', 'MANA_REGEN', 'END_REGEN' ] );
	var ALIASES = {
		DAMAGE: 'DMG', BASE_DMG: 'DMG', MANA: 'MP', ENDURANCE: 'END', ENDUR: 'END',
		BACKSTAB_DMG: 'BACKSTAB', BACKSTAB_DAMAGE: 'BACKSTAB', WEIGHT: 'WT',
		STRENGTH: 'STR', STAMINA: 'STA', AGILITY: 'AGI', DEXTERITY: 'DEX',
		WISDOM: 'WIS', INTELLIGENCE: 'INT', CHARISMA: 'CHA',
		MAGIC: 'SV_MAGIC', FIRE: 'SV_FIRE', COLD: 'SV_COLD', POISON: 'SV_POISON', DISEASE: 'SV_DISEASE',
		RESISTMAGIC: 'SV_MAGIC', RESISTFIRE: 'SV_FIRE', RESISTCOLD: 'SV_COLD',
		RESISTPOISON: 'SV_POISON', RESISTDISEASE: 'SV_DISEASE', RESISTCORRUPTION: 'SV_CORRUPTION',
		SV_VOID: 'SV_CORRUPTION', HPREGEN: 'HP_REGEN', MANAREGEN: 'MANA_REGEN',
		ENDURANCEREGEN: 'END_REGEN', ENDURANCE_REGEN: 'END_REGEN', ENDUR_REGEN: 'END_REGEN',
		MP_REGEN: 'MANA_REGEN', HP_REGENERATION: 'HP_REGEN', MANA_REGENERATION: 'MANA_REGEN',
		ENDURANCE_REGENERATION: 'END_REGEN'
	};
	function canonicalStatKey( key ) {
		key = String( key || '' ).trim().replace( /[\s.-]+/g, '_' ).toUpperCase();
		return Object.prototype.hasOwnProperty.call( ALIASES, key ) ? ALIASES[ key ] : key;
	}
	function statFamily( key ) {
		key = canonicalStatKey( key );
		if ( PRIMARY.indexOf( key ) !== -1 ) { return 'primary'; }
		if ( FLAT.indexOf( key ) !== -1 ) { return 'flat'; }
		return ( { DMG: 'damage', BACKSTAB: 'backstab', RANGE: 'range', WT: 'weight' } )[ key ] || null;
	}
	function exactKeys( object, keys ) {
		return object && typeof object === 'object' && !Array.isArray( object ) &&
			Object.keys( object ).length === keys.length && keys.every( function ( key ) {
				return Object.prototype.hasOwnProperty.call( object, key );
			} );
	}
	function numberIn( value, min, max, integer ) {
		return typeof value === 'number' && Number.isFinite( value ) && value >= min && value <= max &&
			( !integer || Number.isInteger( value ) );
	}
	/** Unknown algorithms/fields and unreasonable coefficients fail closed. */
	function validateRules( rules ) {
		if ( !exactKeys( rules, [ 'schemaVersion', 'engineVersion', 'revision', 'maxTier', 'primary',
			'damage', 'backstab', 'range', 'flat', 'weight', 'accuracy', 'void' ] ) ||
			rules.schemaVersion !== 1 || rules.engineVersion !== 1 || rules.maxTier !== 10 ||
			!numberIn( rules.revision, 1, Number.MAX_SAFE_INTEGER, true ) ) { return false; }
		var sections = [
			[ 'primary', { smallThreshold: [ 1, 100, true ], divisor: [ 1, 1000 ] } ],
			[ 'damage', { divisor: [ 1, 1000 ] } ], [ 'backstab', { divisor: [ 1, 1000 ] } ],
			[ 'range', { perTier: [ 0, 1000, true ] } ], [ 'flat', { perTier: [ 0, 100, true ] } ],
			[ 'weight', { perLog2: [ 0, 0.1 ], minimumBase: [ 0, 10 ], precision: [ 0, 3, true ] } ],
			[ 'accuracy', { perLog2: [ 0, 100 ], precision: [ 0, 3, true ] } ],
			[ 'void', { minimumQualifiers: [ 1, VOID_QUALIFIERS.length, true ], perTier: [ 0, 100, true ] } ]
		];
		return sections.every( function ( section ) {
			var value = rules[ section[ 0 ] ], fields = section[ 1 ];
			return exactKeys( value, Object.keys( fields ) ) && Object.keys( fields ).every( function ( key ) {
				var bounds = fields[ key ];
				return numberIn( value[ key ], bounds[ 0 ], bounds[ 1 ], bounds[ 2 ] );
			} );
		} );
	}
	function freeze( value ) {
		Object.keys( value ).forEach( function ( key ) {
			if ( value[ key ] && typeof value[ key ] === 'object' ) { freeze( value[ key ] ); }
		} );
		return Object.freeze( value );
	}
	function excelRound( value ) {
		return value >= 0 ? Math.floor( value + 0.5 ) : Math.ceil( value - 0.5 );
	}
	function decimalFraction( value ) {
		var parts = String( value ).split( /e/i ), decimal = parts[ 0 ].split( '.' ),
			numerator = Number( decimal.join( '' ) ), power = ( decimal[ 1 ] || '' ).length - Number( parts[ 1 ] || 0 ), denominator;
		while ( power > 0 && numerator % 10 === 0 ) { numerator /= 10; power--; }
		if ( power < 0 ) { numerator *= Math.pow( 10, -power ); power = 0; }
		denominator = Math.pow( 10, power );
		return Number.isSafeInteger( numerator ) && Number.isSafeInteger( denominator ) ?
			{ numerator: numerator, denominator: denominator } : null;
	}
	// Whole ranks have an exact decimal result before display ceiling. Integer
	// remainder avoids turning 7.000000000000001 into an extra displayed tenth,
	// without snapping genuinely fractional values down with an epsilon.
	function wholeRankWeight( base, rank, perRank, factor ) {
		var weight = decimalFraction( base ), rate = decimalFraction( perRank ), remaining, numerator, denominator, remainder;
		if ( !weight || !rate || !Number.isSafeInteger( rate.numerator * rank ) ) { return null; }
		remaining = rate.denominator - rate.numerator * rank;
		numerator = weight.numerator * remaining;
		denominator = weight.denominator * rate.denominator;
		if ( !Number.isSafeInteger( numerator ) || !Number.isSafeInteger( numerator * factor ) ||
			!Number.isSafeInteger( denominator ) || denominator <= 0 ) { return null; }
		numerator *= factor;
		remainder = numerator % denominator;
		return ( ( numerator - remainder ) / denominator + ( remainder > 0 ? 1 : 0 ) ) / factor;
	}
	function createEngine( input ) {
		if ( !validateRules( input ) ) { throw new TypeError( 'Unsupported EQL item scaling rules.' ); }
		var rules = freeze( JSON.parse( JSON.stringify( input ) ) );
		function normalizeRank( full, fraction ) {
			full = Math.max( 0, Math.min( rules.maxTier, Math.floor( Number( full ) || 0 ) ) );
			var denom = Math.pow( 2, full );
			fraction = full === 0 || full === rules.maxTier ? 0 :
				Math.max( 0, Math.min( denom - 1, Math.floor( Number( fraction ) || 0 ) ) );
			return { full: full, fraction: fraction, denom: denom };
		}
		function effectiveLevel( full, fraction ) {
			var state = normalizeRank( full, fraction );
			return state.full + state.fraction / state.denom;
		}
		function accuracyBonus( full, fraction ) {
			var state = normalizeRank( full, fraction ), factor = Math.pow( 10, rules.accuracy.precision );
			return state.full <= 0 ? 0 : Math.floor( factor * rules.accuracy.perLog2 *
				( Math.log( Math.pow( 2, state.full ) + state.fraction ) / Math.LN2 ) ) / factor;
		}
		function voidEligible( stats ) {
			var keys = Array.isArray( stats ) ? stats : Object.keys( stats || {} ).filter( function ( key ) {
				return stats[ key ] !== null && stats[ key ] !== undefined && Number.isFinite( Number( stats[ key ] ) );
			} ), found = {};
			keys.forEach( function ( key ) {
				key = canonicalStatKey( key );
				if ( VOID_QUALIFIERS.indexOf( key ) !== -1 ) { found[ key ] = true; }
			} );
			return Object.keys( found ).length >= rules.void.minimumQualifiers;
		}
		function voidBonus( stats, full ) {
			return voidEligible( stats ) ? normalizeRank( full, 0 ).full * rules.void.perTier : 0;
		}
		function weaponEligible( stats, slots ) {
			var damage = Object.keys( stats || {} ).some( function ( key ) {
				return canonicalStatKey( key ) === 'DMG' && Number( stats[ key ] ) > 0;
			} );
			var slotText = ( Array.isArray( slots ) ? slots.join( ' ' ) : String( slots || '' ) ).toUpperCase();
			return damage && !( /\bAMMO\b/.test( slotText ) && !/\b(?:PRIMARY|SECONDARY|RANGE)\b/.test( slotText ) );
		}
		function scaleStat( key, base, full, options ) {
			options = options || {};
			if ( base === null ) { return null; }
			key = canonicalStatKey( key );
			base = Number( base );
			var state = normalizeRank( full, options.fraction ), effective = effectiveLevel( state.full, state.fraction );
			if ( !Number.isFinite( base ) || state.full <= 0 ) { return base; }
			var family = statFamily( key );
			if ( family === 'primary' ) {
				if ( base === 0 ) { return 0; }
				if ( base > 0 && base <= rules.primary.smallThreshold ) { return base + state.full; }
				if ( base > rules.primary.smallThreshold ) { return Math.floor( base + base * effective / rules.primary.divisor ); }
				return Math.min( 0, base + ( Math.abs( base ) <= rules.primary.smallThreshold ? state.full :
					excelRound( Math.abs( base ) * effective / rules.primary.divisor ) ) );
			}
			if ( family === 'damage' || family === 'backstab' ) {
				return base > 0 ? base + Math.floor( base * effective / rules[ family ].divisor ) : base;
			}
			if ( family === 'range' ) { return base >= 0 ? base + rules.range.perTier * state.full : base; }
			if ( family === 'flat' ) { return base > 0 ? base + rules.flat.perTier * state.full : base; }
			if ( family === 'weight' && base > rules.weight.minimumBase ) {
				var factor = Math.pow( 10, rules.weight.precision ), whole = state.fraction === 0 ?
					wholeRankWeight( base, state.full, rules.weight.perLog2, factor ) : null;
				if ( whole !== null ) { return Math.max( 0, whole ); }
				return Math.max( 0, Math.ceil( base * ( 1 - rules.weight.perLog2 *
					( state.fraction === 0 ? state.full : Math.log( Math.pow( 2, state.full ) + state.fraction ) / Math.LN2 ) ) * factor ) / factor );
			}
			if ( key === 'ACCURACY' && options.weapon && options.accuracyPercent ) {
				return base + accuracyBonus( state.full, state.fraction );
			}
			return base;
		}
		/** Preserve caller keys; generated fields use the app's canonical names. */
		function scaleItemStats( stats, full, options ) {
			stats = stats || {}; options = options || {};
			var result = {}, keys = Object.keys( stats ), weapon = weaponEligible( stats, options.slots ), accuracyKey;
			keys.forEach( function ( key ) {
				if ( canonicalStatKey( key ) === 'ACCURACY' ) { accuracyKey = key; }
				Object.defineProperty( result, key, { value: scaleStat( key, stats[ key ], full, {
					fraction: options.fraction, weapon: weapon, accuracyPercent: options.accuracyPercent
				} ), writable: true, enumerable: true, configurable: true } );
			} );
			if ( normalizeRank( full, 0 ).full > 0 ) {
				if ( weapon && !accuracyKey ) { result.ACCURACY = accuracyBonus( full, options.fraction ); }
				// Existing explicit Void stays caller-owned; only generate a missing field.
				if ( !keys.some( function ( key ) { return canonicalStatKey( key ) === 'SV_CORRUPTION'; } ) && voidEligible( stats ) ) {
					result.SV_CORRUPTION = voidBonus( stats, full );
				}
			}
			return result;
		}
		return Object.freeze( { rules: rules, revision: rules.revision, normalizeRank: normalizeRank,
			canonicalStatKey: canonicalStatKey, statFamily: statFamily, effectiveLevel: effectiveLevel,
			accuracyBonus: accuracyBonus, voidEligible: voidEligible, voidBonus: voidBonus,
			weaponEligible: weaponEligible, scaleStat: scaleStat, scaleItemStats: scaleItemStats } );
	}
	return Object.freeze( { schemaVersion: 1, engineVersion: 1, validateRules: validateRules,
		createEngine: createEngine, canonicalStatKey: canonicalStatKey, statFamily: statFamily } );
} ) );
