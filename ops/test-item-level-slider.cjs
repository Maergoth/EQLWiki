'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname,
	'../extensions/ItemLevelSlider/itemlevelslider.js' ), 'utf8' );
const core = fs.readFileSync( path.join( __dirname,
	'../extensions/ItemLevelSlider/item-scaling-core.js' ), 'utf8' );
const rules = JSON.parse( fs.readFileSync( path.join( __dirname,
	'../extensions/ItemLevelSlider/item-scaling-rules.json' ), 'utf8' ) );
const jquery = fs.readFileSync( path.join( __dirname,
	'../resources/lib/jquery/jquery.js' ), 'utf8' );

function card( title, stats ) {
	return `<div class="itemtopbg">${title}</div>
	<div class="itembg"><div class="itemdata">${stats}</div></div>
	<div class="itembotbg"></div>`;
}

function input( window, slider, value ) {
	slider.value = String( value );
	slider.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
}

function stepUp( wrapper, count ) {
	for ( let i = 0; i < count; i++ ) {
		wrapper.querySelector( '.ils-step-up' ).click();
	}
}

function accuracyText( root ) {
	const value = root.querySelector( '.ils-accuracy-value' );
	assert.ok( value, 'Accuracy has a numeric span' );
	return value.textContent + ( value.nextSibling ? value.nextSibling.textContent : '' );
}

async function main() {
	const dom = new JSDOM( '', { url: 'https://example.invalid', runScripts: 'outside-only' } );
	const { window } = dom;
	const hooks = {};
	window.mediaWiki = {
		config: { get: ( key, fallback ) => key === 'wgEQLItemScalingRules' ? rules : fallback },
		hook: name => ( { add: callback => { hooks[ name ] = callback; } } )
	};
	window.eval( jquery );
	window.mw = window.mediaWiki;
	window.eval( core );
	window.eval( source );

	// Synthetic stats retain the item-template structure without using wiki data.
	const cases = [
		{ title: 'Bow', stats: 'Slot: RANGE<br>Skill: Archery Atk Delay: 40<br>DMG: 20<br>WT: 2.0 Range: 100 Size: LARGE', base: 100, scalable: true },
		{ title: 'Throwing weapon', stats: 'Slot: RANGE<br>Skill: Throwing Atk Delay: 20<br>DMG: 20<br>Range: 40', base: 40, scalable: true },
		{ title: 'Throwingv2 weapon', stats: 'Slot: RANGE AMMO<br>Skill: Throwingv2 Atk Delay: 20<br>DMG: 20<br>Range: 20', base: 20, scalable: true },
		{ title: 'Zero-range bow', stats: 'Slot: RANGE<br>Skill: Archery Atk Delay: 40<br>DMG: 20<br>Range: 0', base: 0, scalable: true },
		{ title: 'Negative range penalty', stats: 'Slot: RANGE<br>DMG: 20<br>Range: -10', base: -10, scalable: true },
		{ title: 'Multiple slots', stats: 'Slot: PRIMARY SECONDARY RANGE<br>Skill: Throwing Atk Delay: 20<br>DMG: 20<br>Range: 30', base: 30, scalable: true },
		{ title: 'Formatted slots', stats: 'Slot:&nbsp;<a href="/synthetic-primary">PRIMARY</a> <a href="/synthetic-range">RANGE</a><br>Skill: Throwing Atk Delay: 20<br>DAMAGE: 20<br>Range: +25', base: 25, scalable: true, sign: '+' },
		{ title: 'Throwingv1 weapon', stats: 'Slot: RANGE<br>Skill: Throwingv1 Atk Delay: 20<br>DMG: 20<br>Range: 30', base: 30, scalable: true },
		{ title: 'Throwable blunt weapon', stats: 'Slot: PRIMARY RANGE<br>Skill: 2H Blunt Atk Delay: 20<br>DMG: 20<br>Range: 40', base: 40, scalable: true },
		{ title: 'Throwable piercing weapon', stats: 'Slot: PRIMARY SECONDARY RANGE<br>Skill: Piercing Atk Delay: 20<br>DMG: 20<br>Range: 20', base: 20, scalable: true },
		{ title: 'Ranged weapon without skill', stats: 'Slot: RANGE<br>DMG: 20<br>Range: 60', base: 60, scalable: true },
		{ title: 'Ammo', stats: 'Slot: AMMO<br>DMG: 20<br>Range: 50', base: 50, scalable: true },
		{ title: 'Ammo without weapon damage', stats: 'Slot: AMMO<br>Range: 0', base: 0, scalable: true },
		{ title: 'Range accessory', stats: 'Slot: RANGE<br>AC: 5', scalable: false },
		{ title: 'Elemental damage only', stats: 'Slot: RANGE<br>Cold DMG: 20', scalable: false },
		{ title: 'Melee weapon', stats: 'Slot: PRIMARY SECONDARY<br>Skill: 1H Slashing Atk Delay: 20<br>DMG: 20', scalable: false }
	];

	for ( const test of cases ) {
		window.localStorage.clear();
		window.document.body.innerHTML = card( test.title, test.stats );
		window.eqlItemLevelSliderRefresh( window.document );
		const wrapper = window.document.querySelector( '.ils-item-wrapper' );
		const item = wrapper.querySelector( '.itemdata' );
		const slider = wrapper.querySelector( '.ils-slider' );
		const range = item.querySelector( '.ils-stat[data-ils-stat="RANGE"]' );
		const damage = item.querySelector( '.ils-stat[data-ils-stat="DMG"]' );
		assert.equal( Boolean( range ), test.scalable, `${test.title}: range eligibility` );
		assert.equal( wrapper.querySelectorAll( '.ils-slider' ).length, 1 );
		const instrumentedHtml = item.innerHTML;

		function check( full, label, expectedDamage ) {
			assert.equal( wrapper.querySelector( '.ils-level-display' ).textContent, label, test.title );
			if ( range ) {
				const expected = test.base < 0 ? test.base : test.base + 10 * full;
				assert.equal( range.textContent, `${test.sign || ''}${expected}`, `${test.title}: rank ${label}` );
				assert.equal( Number( range.dataset.ilsBase ), Math.abs( test.base ), `${test.title}: original range` );
				assert.equal( Number( range.dataset.ilsCurrentValue ), expected, test.title );
				assert.equal( range.classList.contains( 'ils-modified' ), full > 0 && test.base >= 0, test.title );
			} else {
				assert.doesNotMatch( item.textContent, /\bRange:/i, `${test.title}: no invented range field` );
			}
			if ( damage ) {
				assert.equal( Number( damage.textContent ), expectedDamage, `${test.title}: weapon damage` );
			}
			if ( test.title === 'Elemental damage only' ) {
				assert.match( item.textContent, /Cold DMG: 20/ );
				assert.equal( damage, null );
			}
		}

		check( 0, '0', 20 );
		stepUp( wrapper, 1 );
		check( 1, '1 + 0/2', 22 );
		stepUp( wrapper, 1 );
		check( 1, '1 + 1/2', 23 );
		stepUp( wrapper, 1 );
		check( 2, '2 + 0/4', 24 );
		stepUp( wrapper, 3 );
		check( 2, '2 + 3/4', 25 );
		stepUp( wrapper, 9 );
		check( 4, '4 + 0/16', 28 );
		input( window, slider, slider.value );
		window.eqlItemLevelSliderRefresh( wrapper );
		check( 4, '4 + 0/16', 28 );
		assert.equal( wrapper.querySelectorAll( '.ils-slider' ).length, 1, `${test.title}: idempotent refresh` );
		input( window, slider, 10000 );
		check( 10, '10', 40 );
		wrapper.querySelector( '.ils-step-down' ).click();
		check( 9, '9 + 511/512', 39 );
		input( window, slider, 0 );
		check( 0, '0', 20 );
		assert.equal( item.innerHTML, instrumentedHtml, `${test.title}: rank zero restoration` );
	}

	// Independent screenshot regressions reach the DOM through the shared engine.
	for ( const test of [
		{ title: 'Fractional HP truncation', key: 'HP', base: 50, full: 5, fraction: 4, expected: 75 },
		{ title: 'Fractional armor truncation', key: 'AC', base: 14, full: 6, fraction: 5, expected: 22 },
		{ title: 'Fractional HP progress', key: 'HP', base: 50, full: 4, fraction: 4, expected: 71 }
	] ) {
		window.localStorage.setItem( 'ils-default-level-v2', JSON.stringify( { full: test.full, fraction: test.fraction } ) );
		window.document.body.innerHTML = card( test.title, `${test.key}: ${test.base}` );
		window.eqlItemLevelSliderRefresh( window.document );
		const wrapper = window.document.querySelector( '.ils-item-wrapper' );
		const stat = wrapper.querySelector( `[data-ils-stat="${test.key}"]` );
		assert.equal( stat.textContent, String( test.expected ), `${test.title}: observed integer value` );
		assert.equal( wrapper.querySelector( '.ils-level-display' ).textContent,
			`${test.full} + ${test.fraction}/${2 ** test.full}`, `${test.title}: exact progress retained` );
		window.eqlItemLevelSliderRefresh( wrapper );
		assert.equal( stat.textContent, String( test.expected ), `${test.title}: refresh cannot scale twice` );
		input( window, wrapper.querySelector( '.ils-slider' ), 0 );
		assert.equal( stat.textContent, String( test.base ), `${test.title}: base restoration` );
	}

	// Primary/weight and contextual Void also flow through the injected engine.
	window.localStorage.clear();
	window.document.body.innerHTML = card( 'Shared numeric boundaries',
		'STR: -4 DEX: 0<br>HP: 100<br>WT: 10.0' );
	window.eqlItemLevelSliderRefresh( window.document );
	const boundaryWrapper = window.document.querySelector( '.ils-item-wrapper' );
	const boundaryItem = boundaryWrapper.querySelector( '.itemdata' );
	input( window, boundaryWrapper.querySelector( '.ils-slider' ), 10000 );
	assert.equal( boundaryItem.querySelector( '[data-ils-stat="STR"]' ).textContent, '0' );
	assert.equal( boundaryItem.querySelector( '[data-ils-stat="HP"]' ).textContent, '200' );
	assert.equal( boundaryItem.querySelector( '[data-ils-stat="WT"]' ).textContent, '1.0', 'exact decimal weight ceiling does not add an extra tenth' );
	assert.equal( boundaryItem.querySelector( '.ils-void-resist' ).textContent, 'SV VOID: +10' );
	input( window, boundaryWrapper.querySelector( '.ils-slider' ), 0 );
	assert.equal( boundaryItem.querySelector( '[data-ils-stat="STR"]' ).textContent, '-4' );
	assert.equal( boundaryItem.querySelector( '.ils-void-resist' ).textContent, '' );
	for ( const test of [
		{ base: '2.5', full: 8, expected: '0.7' }, { base: '2.5', full: 10, expected: '0.3' },
		{ base: '1.0', full: 10, expected: '0.1' }, { base: '5.0', full: 2, expected: '4.1' }
	] ) {
		window.localStorage.setItem( 'ils-default-level-v2', JSON.stringify( { full: test.full, fraction: 0 } ) );
		window.document.body.innerHTML = card( 'Decimal weight boundary', `WT: ${test.base}` );
		window.eqlItemLevelSliderRefresh( window.document );
		const wrapper = window.document.querySelector( '.ils-item-wrapper' ), stat = wrapper.querySelector( '[data-ils-stat="WT"]' );
		assert.equal( stat.textContent, test.expected, `weight ${test.base} at rank ${test.full}` );
		input( window, wrapper.querySelector( '.ils-slider' ), 0 );
		assert.equal( stat.textContent, test.base, 'rank zero restores the authored weight text' );
	}
	for ( const test of [
		{ title: 'Diamondine primary fields', stats: 'AC: 2<br>HP: 12<br>STR: 3', full: 5, expected: 'SV VOID: +5' },
		{ title: 'Talisman regen fields', stats: 'HP Regen: 2<br>Mana Regen: 2<br>End Regen: 2', full: 5, expected: 'SV VOID: +5' },
		{ title: 'Serpent single attribute', stats: 'Slot: PRIMARY<br>DMG: 13<br>BACKSTAB: 13<br>STR: 3', full: 8, expected: '' },
		{ title: 'Pike weapon fields', stats: 'Slot: PRIMARY<br>Skill: 1H Piercing Atk Delay: 36<br>DMG: 9', full: 4, expected: '' },
		{ title: 'Single armor field', stats: 'AC: 15', full: 3, expected: '' },
		{ title: 'Known zero primary fields', stats: 'AC: 0<br>HP: 0', full: 5, expected: 'SV VOID: +5' }
	] ) {
		window.localStorage.setItem( 'ils-default-level-v2', JSON.stringify( { full: test.full, fraction: 0 } ) );
		window.document.body.innerHTML = card( test.title, test.stats );
		window.eqlItemLevelSliderRefresh( window.document );
		const wrapper = window.document.querySelector( '.ils-item-wrapper' );
		const generated = wrapper.querySelector( '.ils-void-resist' );
		assert.equal( generated.textContent, test.expected, `${test.title}: literal screenshot eligibility` );
		window.eqlItemLevelSliderRefresh( wrapper );
		assert.equal( generated.textContent, test.expected, `${test.title}: refresh preserves one generated value` );
		input( window, wrapper.querySelector( '.ils-slider' ), 0 );
		assert.equal( generated.textContent, '', `${test.title}: reset hides generated Void` );
	}
	const voidLabels = [ 'SV VOID', 'SV Corruption', 'SV_VOID', 'SV.Corruption', 'SVVoid',
		'SVCorruption', 'Void', 'Corruption', 'ResistVoid', 'ResistCorruption' ];
	for ( const label of voidLabels ) {
		for ( const base of [ '+3', '25', '0', '+0', '-3', '-25' ] ) {
			window.localStorage.setItem( 'ils-default-level-v2', '{"full":5,"fraction":4}' );
			window.document.body.innerHTML = card( 'Explicit Void resist', `STR: 5 DEX: 5<br>${label}: ${base}` );
			hooks[ 'wikipage.content' ]( window.jQuery( window.document.body ) );
			const wrapper = window.document.querySelector( '.ils-item-wrapper' );
			const stat = wrapper.querySelector( '.ils-stat[data-ils-stat="SV_CORRUPTION"]' );
			const expected = { '+3': 8, '25': 37, '0': 0, '+0': 0, '-3': 0, '-25': -12 }[ base ];
			const sign = base.startsWith( '+' ) && Number( base ) > 0 ? '+' : '';
			assert.ok( stat, `${label}: explicit field is instrumented` );
			assert.equal( stat.textContent, sign + expected, `${label}/${base}: ordinary resist scaling` );
			assert.equal( stat.classList.contains( 'ils-modified' ), expected !== Number( base ) );
			assert.equal( Number( stat.dataset.ilsBase ), Math.abs( Number( base ) ), 'listed base retained' );
			window.eqlItemLevelSliderRefresh( wrapper );
			assert.equal( stat.textContent, sign + expected, 'refresh never scales the current value again' );
			assert.equal( wrapper.querySelectorAll( '[data-ils-stat="SV_CORRUPTION"]' ).length, 1 );
			input( window, wrapper.querySelector( '.ils-slider' ), 10000 );
			assert.equal( stat.textContent, sign + ( { '+3': 13, '25': 50, '0': 0, '+0': 0, '-3': 0, '-25': 0 }[ base ] ) );
			assert.equal( wrapper.querySelector( '.ils-void-resist' ).textContent, '', 'explicit field suppresses duplicate' );
			input( window, wrapper.querySelector( '.ils-slider' ), 0 );
			assert.equal( stat.textContent, sign + Number( base ), `${label}/${base}: rank zero restores original value/sign` );
			assert.equal( stat.classList.contains( 'ils-modified' ), false );
		}
	}
	window.localStorage.setItem( 'ils-default-level-v2', '{"full":5,"fraction":4}' );
	window.document.body.innerHTML = card( 'Intrinsic Void only', 'SV VOID: 20' ) +
		card( 'Unknown explicit Void', 'STR: 5 DEX: 5<br>SV Void: ?' );
	hooks[ 'wikipage.content' ]( window.jQuery( window.document.body ) );
	assert.equal( window.document.querySelector( '[data-ils-stat="SV_CORRUPTION"]' ).textContent, '30',
		'intrinsic Void alone does not require generated-Void qualifiers' );
	assert.equal( window.document.querySelectorAll( '.ils-void-resist.ils-vr-active' ).length, 0,
		'explicit unknown Void remains unknown rather than generating an assumed zero base' );
	assert.match( window.document.body.textContent, /SV Void: \?/ );

	// A dynamic card inherits the saved fractional rank, and refresh never scales twice.
	window.localStorage.setItem( 'ils-default-level-v2', JSON.stringify( { full: 3, fraction: 5 } ) );
	window.document.body.innerHTML = card( 'Dynamic bow',
		'Slot: RANGE<br>Skill: Archery Atk Delay: 30<br>DMG: 20<br>Range: 75' );
	hooks[ 'wikipage.content' ]( window.jQuery( window.document.body ) );
	window.eqlItemLevelSliderRefresh( window.document.body );
	assert.equal( window.document.querySelector( '.ils-level-display' ).textContent, '3 + 5/8' );
	assert.equal( window.document.querySelector( '.ils-stat[data-ils-stat="RANGE"]' ).textContent, '105' );
	assert.equal( window.document.querySelectorAll( '.ils-slider' ).length, 1 );
	assert.equal( window.localStorage.getItem( 'ils-default-level-v2' ), '{"full":3,"fraction":5}' );

	// Screen observations use distinct original weapon and backstab damage values.
	// Synthetic names keep these fixtures independent of the live wiki's item data.
	const backstabCases = [
		{ title: 'Piercing A', label: 'BACKSTAB', base: 12, full: 5, fraction: 0, expected: 15, cap: 18 },
		{ title: 'Piercing B', label: 'BACKSTAB DMG', base: 9, full: 4, fraction: 0, expected: 10, cap: 13 },
		{ title: 'Piercing C', label: 'Backstab Damage', base: 9, full: 2, fraction: 0, expected: 9, cap: 13 },
		{ title: 'Piercing D', label: 'BACKSTAB', base: 13, full: 8, fraction: 0, expected: 18, cap: 19 },
		{ title: 'Piercing E', label: 'BACKSTAB', base: 15, full: 1, fraction: 0, expected: 15, cap: 22 },
		{ title: 'Fractional backstab threshold', label: 'BACKSTAB DMG', base: 9, full: 4, fraction: 8, expected: 11, cap: 13 },
		{ title: 'Explicit plus sign', label: 'BACKSTAB', base: 12, sign: '+', full: 5, fraction: 4, expected: 15, cap: 18 },
		{ title: 'Zero backstab', label: 'BACKSTAB', base: 0, full: 10, fraction: 0, expected: 0, cap: 0 },
		{ title: 'Negative backstab', label: 'BACKSTAB DAMAGE', base: -4, full: 10, fraction: 0, expected: -4, cap: -4 }
	];
	for ( const test of backstabCases ) {
		window.localStorage.clear();
		window.localStorage.setItem( 'ils-default-level-v2', JSON.stringify( { full: test.full, fraction: test.fraction } ) );
		window.document.body.innerHTML = card( test.title,
			`Slot: PRIMARY SECONDARY<br>Skill: 1H Piercing Atk Delay: 20<br>DMG: 20<br>${test.label}: ${test.sign || ''}${test.base}<br>Cold DMG: 6<br>Bane DMG: 3<br>DMG Bonus: 7 @ lvl 50` );
		window.eqlItemLevelSliderRefresh( window.document );
		const wrapper = window.document.querySelector( '.ils-item-wrapper' );
		const item = wrapper.querySelector( '.itemdata' );
		const backstab = item.querySelector( '.ils-stat[data-ils-stat="BACKSTAB"]' );
		assert.ok( backstab, `${test.title}: explicit backstab field is instrumented` );
		assert.equal( backstab.textContent, `${test.sign || ''}${test.expected}`, `${test.title}: observed rank value` );
		assert.equal( Number( backstab.dataset.ilsCurrentValue ), test.expected, `${test.title}: current metadata` );
		assert.equal( Number( backstab.dataset.ilsBase ) * ( backstab.dataset.ilsSign === '-' ? -1 : 1 ), test.base, `${test.title}: separate original backstab` );
		assert.match( item.textContent, /Cold DMG: 6/ );
		assert.match( item.textContent, /Bane DMG: 3/ );
		assert.match( item.textContent, /DMG Bonus: 7 @ lvl 50/ );
		assert.match( item.textContent, /Atk Delay: 20/ );
		window.eqlItemLevelSliderRefresh( wrapper );
		assert.equal( item.querySelectorAll( '.ils-stat[data-ils-stat="BACKSTAB"]' ).length, 1, `${test.title}: refresh does not duplicate backstab` );
		assert.equal( backstab.textContent, `${test.sign || ''}${test.expected}`, `${test.title}: refresh does not scale twice` );
		input( window, wrapper.querySelector( '.ils-slider' ), 10000 );
		assert.equal( backstab.textContent, `${test.sign || ''}${test.cap}`, `${test.title}: rank ten` );
		assert.equal( item.querySelector( '.ils-stat[data-ils-stat="DMG"]' ).textContent, '40', `${test.title}: weapon damage keeps its own rate` );
		input( window, wrapper.querySelector( '.ils-slider' ), 0 );
		assert.equal( backstab.textContent, `${test.sign || ''}${test.base}`, `${test.title}: original backstab restored` );
		assert.equal( backstab.classList.contains( 'ils-modified' ), false, `${test.title}: reset highlight` );
	}

	// Separate cards retain their own explicit baselines, including dynamic content.
	window.localStorage.clear();
	window.document.body.innerHTML = card( 'Independent primary',
		'Slot: PRIMARY<br>Skill: 1H Piercing Atk Delay: 20<br>DMG: 20<br>BACKSTAB: 12' ) +
		card( 'Independent secondary',
			'Slot: SECONDARY<br>Skill: 1H Piercing Atk Delay: 20<br>DMG: 15<br>BACKSTAB: 9' ) +
		card( 'No backstab weapon', 'Slot: PRIMARY<br>Skill: 1H Blunt Atk Delay: 20<br>DMG: 20' );
	window.eqlItemLevelSliderRefresh( window.document );
	const independentCards = window.document.querySelectorAll( '.ils-item-wrapper' );
	input( window, independentCards[ 0 ].querySelector( '.ils-slider' ), 10000 );
	assert.equal( independentCards[ 0 ].querySelector( '[data-ils-stat="BACKSTAB"]' ).textContent, '18' );
	assert.equal( independentCards[ 1 ].querySelector( '[data-ils-stat="BACKSTAB"]' ).textContent, '9' );
	assert.equal( independentCards[ 2 ].querySelector( '[data-ils-stat="BACKSTAB"]' ), null, 'no invented backstab field' );
	assert.equal( accuracyText( independentCards[ 0 ] ), '+25%', 'first card Accuracy changes with its rank' );
	assert.equal( accuracyText( independentCards[ 1 ] ), '0%', 'second card Accuracy remains independent' );
	const dynamicBackstab = window.document.createElement( 'div' );
	dynamicBackstab.innerHTML = card( 'Dynamic piercing',
		'Slot: PRIMARY<br>Skill: 1H Piercing Atk Delay: 20<br>DMG: 20<br>BACKSTAB: 9' );
	window.document.body.appendChild( dynamicBackstab );
	hooks[ 'wikipage.content' ]( window.jQuery( dynamicBackstab ) );
	window.eqlItemLevelSliderRefresh( dynamicBackstab );
	assert.equal( dynamicBackstab.querySelector( '.ils-level-display' ).textContent, '10' );
	assert.equal( dynamicBackstab.querySelector( '[data-ils-stat="BACKSTAB"]' ).textContent, '13' );
	assert.equal( dynamicBackstab.querySelectorAll( '[data-ils-stat="BACKSTAB"]' ).length, 1 );
	assert.equal( accuracyText( dynamicBackstab ), '+25%', 'dynamic Accuracy inherits saved rank' );

	// Accuracy follows merge progression and truncates the gain to one decimal.
	// The Staff screenshot distinguishes truncation (11.4%) from rounding (11.5%).
	const accuracyCases = [
		{ full: 0, fraction: 0, expected: '0%' },
		{ full: 1, fraction: 0, expected: '+2.5%' },
		{ full: 1, fraction: 1, expected: '+3.9%' },
		{ full: 2, fraction: 3, expected: '+7%' },
		{ title: 'Bow of Underfoot screenshot', full: 4, fraction: 1, expected: '+10.2%' },
		{ title: 'Staff of Elemental Mastery: Water screenshot', full: 4, fraction: 8, expected: '+11.4%' },
		{ full: 5, fraction: 4, expected: '+12.9%' },
		{ full: 8, fraction: 1, expected: '+20%' },
		{ full: 10, fraction: 0, expected: '+25%' }
	];
	for ( const test of accuracyCases ) {
		window.localStorage.clear();
		window.localStorage.setItem( 'ils-default-level-v2', JSON.stringify( { full: test.full, fraction: test.fraction } ) );
		window.document.body.innerHTML = card( test.title || 'Accuracy progression',
			'Slot: PRIMARY<br>Skill: 1H Piercing Atk Delay: 20<br>DMG: 20<br>BACKSTAB: 12' );
		window.eqlItemLevelSliderRefresh( window.document );
		const wrapper = window.document.querySelector( '.ils-item-wrapper' );
		const item = wrapper.querySelector( '.itemdata' );
		const accuracy = item.querySelector( '.ils-accuracy' );
		assert.ok( accuracy, `rank ${test.full} + ${test.fraction}: Accuracy is inside the item frame` );
		assert.equal( accuracyText( accuracy ), test.expected, 'displayed Accuracy includes sign and percent' );
		assert.equal( accuracy.hidden || accuracy.style.display === 'none', test.full === 0, 'generated Accuracy is hidden only at rank zero' );
		window.eqlItemLevelSliderRefresh( wrapper );
		assert.equal( item.querySelectorAll( '.ils-accuracy' ).length, 1, 'refresh never duplicates Accuracy' );
		assert.equal( accuracyText( accuracy ), test.expected, 'refresh preserves the selected Accuracy' );
		input( window, wrapper.querySelector( '.ils-slider' ), 0 );
		assert.equal( accuracyText( accuracy ), '0%' );
		assert.ok( accuracy.hidden || accuracy.style.display === 'none', 'reset hides generated Accuracy' );
		input( window, wrapper.querySelector( '.ils-slider' ), 10000 );
		assert.equal( accuracyText( accuracy ), '+25%' );
		assert.equal( accuracy.hidden || accuracy.style.display === 'none', false, 'ranked Accuracy is visible after reset' );
	}

	const accuracyEligibilityCases = [
		{ title: 'Primary melee Accuracy', stats: 'Slot: PRIMARY SECONDARY<br>Skill: 1H Piercing Atk Delay: 20<br>DMG: 20', eligible: true },
		{ title: 'Ranged bow Accuracy', stats: 'Slot: RANGE<br>Skill: Archery Atk Delay: 30<br>DMG: 20', eligible: true },
		{ title: 'Throwable melee Accuracy', stats: 'Slot: RANGE<br>Skill: 1H Piercing Atk Delay: 20<br>DMG: 20', eligible: true },
		{ title: 'Ranged ammo slot Accuracy', stats: 'Slot: RANGE AMMO<br>Skill: Throwing Atk Delay: 20<br>DMG: 20', eligible: true },
		{ title: 'Ammo only', stats: 'Slot: AMMO<br>DMG: 20<br>Range: 50', eligible: false },
		{ title: 'Armor', stats: 'Slot: CHEST<br>AC: 20', eligible: false },
		{ title: 'Elemental damage', stats: 'Slot: RANGE<br>Cold DMG: 20', eligible: false },
		{ title: 'Zero weapon damage', stats: 'Slot: PRIMARY<br>Skill: 1H Piercing Atk Delay: 20<br>DMG: 0', eligible: false }
	];
	for ( const test of accuracyEligibilityCases ) {
		window.localStorage.clear();
		window.localStorage.setItem( 'ils-default-level-v2', '{"full":1,"fraction":0}' );
		window.document.body.innerHTML = card( test.title, test.stats );
		window.eqlItemLevelSliderRefresh( window.document );
		const accuracy = window.document.querySelector( '.itemdata .ils-accuracy' );
		assert.equal( Boolean( accuracy ), test.eligible, `${test.title}: Accuracy eligibility` );
		if ( accuracy ) {
			assert.equal( accuracyText( accuracy ), '+2.5%', test.title );
		}
	}

	// Existing percentage stats retain their own baseline; untyped legacy values
	// are not silently reinterpreted as a percentage or duplicated.
	window.localStorage.clear();
	window.localStorage.setItem( 'ils-default-level-v2', '{"full":5,"fraction":4}' );
	window.document.body.innerHTML = card( 'Explicit percentage Accuracy',
		'Slot: PRIMARY<br>Skill: 1H Piercing Atk Delay: 20<br>DMG: 20<br>Accuracy: +1.5%' );
	window.eqlItemLevelSliderRefresh( window.document );
	const explicitAccuracyItem = window.document.querySelector( '.itemdata' );
	const explicitAccuracy = explicitAccuracyItem.querySelector( '.ils-stat[data-ils-stat="ACCURACY"]' );
	assert.ok( explicitAccuracy, 'percentage Accuracy is instrumented' );
	assert.equal( Number( explicitAccuracy.dataset.ilsBase ), 1.5, 'original percentage is retained' );
	assert.equal( explicitAccuracy.textContent, '+14.4', 'rank gain adds to original percentage' );
	assert.equal( explicitAccuracyItem.textContent.match( /Accuracy:/gi ).length, 1, 'no duplicate explicit Accuracy label' );
	window.eqlItemLevelSliderRefresh( explicitAccuracyItem.parentNode );
	assert.equal( explicitAccuracy.textContent, '+14.4', 'explicit Accuracy does not scale twice' );
	input( window, window.document.querySelector( '.ils-slider' ), 0 );
	assert.equal( explicitAccuracy.textContent, '+1.5', 'reset restores original percentage and sign' );
	assert.match( explicitAccuracyItem.textContent, /Accuracy: \+1\.5%/ );
	window.localStorage.clear();
	window.localStorage.setItem( 'ils-default-level-v2', '{"full":10,"fraction":0}' );
	window.document.body.innerHTML = card( 'Legacy untyped Accuracy',
		'Slot: PRIMARY<br>Skill: 1H Piercing Atk Delay: 20<br>DMG: 20<br>Accuracy: +4' );
	window.eqlItemLevelSliderRefresh( window.document );
	assert.match( window.document.querySelector( '.itemdata' ).textContent, /Accuracy: \+4(?![\d.%])/ );
	assert.equal( window.document.querySelector( '.ils-accuracy' ), null, 'legacy Accuracy suppresses a generated field' );

	// The displayed order is DMG, existing/generated bonus, then calculated ratio.
	const bonusCases = [
		{ title: 'Inline generated bonus', stats: 'DMG: 20<span class="eql-generated-damage-bonus">&nbsp;&nbsp;DMG Bonus: 10 @ lvl 50</span><br>BACKSTAB: 20', bonus: 'DMG Bonus: 10 @ lvl 50' },
		{ title: 'Previous template footer', stats: 'DMG: 20<br>BACKSTAB: 20<br>Race: ALL<br><span class="eql-generated-damage-bonus">DMG Bonus: 10 @ lvl 50</span><br>', bonus: 'DMG Bonus: 10 @ lvl 50' },
		{ title: 'Inline explicit override', stats: 'DMG: 20  DMG Bonus: 99 @ lvl 30  AC: 3<br>BACKSTAB: 7', bonus: 'DMG Bonus: 99 @ lvl 30' },
		{ title: 'Separate explicit zero', stats: 'DMG: 20<br>Dmg Bon: 0<br>Class: ALL<br>BACKSTAB: 7', bonus: 'Dmg Bon: 0' },
		{ title: 'Delay-line override', stats: 'DMG: 20<br>Atk Delay: 20 Damage Bonus: +7 @ lvl 50<br>Class: ALL', bonus: 'Damage Bonus: +7 @ lvl 50' },
		{ title: 'Short full-word label', stats: 'DAMAGE: 20<br>damage bon: 5<br>Class: ALL', bonus: 'damage bon: 5' }
	];
	for ( const test of bonusCases ) {
		window.localStorage.clear();
		window.document.body.innerHTML = card( test.title, 'Slot: PRIMARY<br>Skill: 1H Blunt Atk Delay: 20<br>' + test.stats );
		window.eqlItemLevelSliderRefresh( window.document );
		const wrapper = window.document.querySelector( '.ils-item-wrapper' );
		const item = wrapper.querySelector( '.itemdata' );
		const damage = item.querySelector( '.ils-stat[data-ils-stat="DMG"]' );
		const bonus = item.querySelector( '.eql-generated-damage-bonus, .ils-damage-bonus' );
		const ratio = item.querySelector( '.ils-ratio' );
		assert.equal( bonus.textContent.trim(), test.bonus, `${test.title}: original bonus text` );
		assert.equal( bonus.nextSibling, ratio, `${test.title}: bonus directly before ratio` );
		assert.ok( damage.compareDocumentPosition( bonus ) & window.Node.DOCUMENT_POSITION_FOLLOWING, `${test.title}: bonus follows damage` );
		assert.equal( damage.parentNode, bonus.parentNode, `${test.title}: one damage line` );
		assert.doesNotMatch( item.innerHTML.slice( item.innerHTML.indexOf( '</span>' ) + 7, item.innerHTML.indexOf( bonus.outerHTML ) ), /<br\b/i, `${test.title}: no break before bonus` );
		if ( test.title === 'Inline explicit override' ) {
			assert.equal( item.querySelector( '.ils-stat[data-ils-stat="AC"]' ).textContent, '3' );
			assert.match( item.textContent, /BACKSTAB: 7/ );
		}
		const baseHtml = item.innerHTML;
		input( window, wrapper.querySelector( '.ils-slider' ), 10000 );
		assert.equal( damage.textContent, '40', `${test.title}: rank damage still scales` );
		assert.equal( bonus.textContent.trim(), test.bonus, `${test.title}: rank preserves bonus` );
		assert.equal( ratio.querySelector( '.ils-ratio-value' ).textContent, '(2.00)' );
		window.eqlItemLevelSliderRefresh( wrapper );
		assert.equal( item.querySelectorAll( '.eql-generated-damage-bonus, .ils-damage-bonus' ).length, 1, `${test.title}: refresh does not duplicate bonus` );
		input( window, wrapper.querySelector( '.ils-slider' ), 0 );
		assert.equal( item.innerHTML, baseHtml, `${test.title}: base restoration preserves placement` );
	}

	window.close();
	console.log( `Item slider: ${cases.length} range cards, 3 primary floor cards, 6 Void eligibility cards, ${backstabCases.length} backstab cards, ${accuracyCases.length} Accuracy ranks, ${accuracyEligibilityCases.length} Accuracy eligibility cases, and ${bonusCases.length} damage-bonus cards pass ranks, reset, ordering, and refresh.` );
}

main().catch( error => { console.error( error ); process.exitCode = 1; } );
