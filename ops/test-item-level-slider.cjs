'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname,
	'../extensions/ItemLevelSlider/itemlevelslider.js' ), 'utf8' );
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

async function main() {
	const dom = new JSDOM( '', { url: 'https://example.invalid', runScripts: 'outside-only' } );
	const { window } = dom;
	const hooks = {};
	window.mediaWiki = {
		config: { get: ( key, fallback ) => fallback },
		hook: name => ( { add: callback => { hooks[ name ] = callback; } } )
	};
	window.eval( jquery );
	window.mw = window.mediaWiki;
	window.eval( source );

	// Synthetic stats retain the item-template structure without using wiki data.
	const cases = [
		{ title: 'Bow', stats: 'Slot: RANGE<br>Skill: Archery Atk Delay: 40<br>DMG: 20<br>WT: 2.0 Range: 100 Size: LARGE', base: 100, scalable: true },
		{ title: 'Throwing weapon', stats: 'Slot: RANGE<br>Skill: Throwing Atk Delay: 20<br>DMG: 20<br>Range: 40', base: 40, scalable: true },
		{ title: 'Throwingv2 weapon', stats: 'Slot: RANGE AMMO<br>Skill: Throwingv2 Atk Delay: 20<br>DMG: 20<br>Range: 20', base: 20, scalable: true },
		{ title: 'Zero-range bow', stats: 'Slot: RANGE<br>Skill: Archery Atk Delay: 40<br>DMG: 20<br>Range: 0', base: 0, scalable: true },
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
				const expected = test.base + 10 * full;
				assert.equal( range.textContent, `${test.sign || ''}${expected}`, `${test.title}: rank ${label}` );
				assert.equal( Number( range.dataset.ilsBase ), test.base, `${test.title}: original range` );
				assert.equal( Number( range.dataset.ilsCurrentValue ), expected, test.title );
				assert.equal( range.classList.contains( 'ils-modified' ), full > 0, test.title );
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
	console.log( `Item slider: ${cases.length} range cards and ${bonusCases.length} damage-bonus cards pass ranks, reset, ordering, and refresh.` );
}

main().catch( error => { console.error( error ); process.exitCode = 1; } );
