'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname,
	'../extensions/SpellLevelSlider/spelllevelslider.js' ), 'utf8' );

function card( title, effect, summary = '', type = 'Detrimental' ) {
	return `<div class="eql-spellpage" data-sls-page-title="${title}">
	<div class="eql-spellpage-summary-text"><p>${summary}</p></div>
	<div class="classes">Learning level 20</div>
	<table class="eql-spellpage-slot-table"><tr><td>1:</td><td>${effect}</td></tr></table>
	<table class="eql-spellpage-detail-table">
	<tr><th>Casting Time</th><td>2.50</td><th>Mana</th><td>50</td></tr>
	<tr><th>Duration</th><td>42 Sec @L44</td><th>Spell Type</th><td>${type}</td></tr>
	<tr><th>Recast Time</th><td>5.00</td><th>Resist</th><td>Magic (-10)</td></tr>
	</table></div>`;
}

async function main() {
	const dom = new JSDOM( '', { url: 'https://example.invalid', runScripts: 'outside-only' } );
	const { window } = dom;
	const hooks = {};
	const rules = {};
	for ( const key of [ 'buff', 'debuff', 'charm_mez', 'nuke_lifetap' ] ) {
		rules[ key ] = { label: key, cast: -0.04, mana: -0.04, duration: 0.1 };
	}
	rules.pet = { label: 'Pet', cast: null, mana: null, duration: null, pet_level: 1 };
	const config = { wgSpellLevelSliderRules: rules, wgEQLSpellLevelOverrides: {}, wgSpellLevelDefault: 0 };
	window.mediaWiki = {
		config: { get: ( key, fallback ) => config[ key ] ?? fallback },
		hook: name => ( { add: callback => { hooks[ name ] = callback; } } )
	};
	window.eval( source );
	const cases = [
		[ 'Charm', 'Charm up to level 25', 'charm_mez', 'Detrimental' ],
		[ 'Mez', 'Mesmerize (2/55)', 'charm_mez', 'Detrimental' ],
		[ 'Calm', 'Frenzy Radius (5/50); Reaction Radius (5/50); Pacify', 'buff', 'Beneficial' ],
		[ 'Calm undead', 'Calm undead up to level 50', 'buff', 'Beneficial' ],
		[ 'Harmony', 'Reduce Aggro Radius by 15 (up to L40); Reaction Radius (15/40)', 'buff', 'Beneficial' ],
		[ 'Lull animal', 'Reduce Aggro Radius by 15 (up to L30); Reaction Radius (25/30)', 'buff', 'Beneficial' ],
		[ 'Stun', 'Stun for 4.0 seconds up to level 55', 'debuff', 'Detrimental' ],
		[ 'Damage stun', 'Decrease Hitpoints by 10; Stun up to level 55', 'nuke_lifetap', 'Detrimental' ],
		[ 'Split', '<a href="/effect">Charm</a> up <b>to level <i>2</i>5</b>; up to level 40', 'charm_mez', 'Detrimental' ],
		[ 'Pet', 'Summon a level 16 skeletal <a href="/pet">pet</a>', 'pet', 'Beneficial' ]
	];
	for ( const [ title, effect, category, type ] of cases ) {
		window.document.body.innerHTML = card( title, effect, 'Affects creatures up to level 30.', type );
		await window.eqlSpellLevelSliderRefresh( window.document );
		const page = window.document.querySelector( '.eql-spellpage' );
		const cell = page.querySelector( '.eql-spellpage-slot-table tr td:last-child' );
		const original = cell.innerHTML;
		const originalText = cell.textContent;
		const links = [ ...cell.querySelectorAll( 'a' ) ];
		assert.equal( page.dataset.slsCategory, category, title );
		for ( const rank of [ 1, 10, 4, 4, 0 ] ) {
			await window.eqlSpellLevelSliderSetLevel( rank, page, false );
			const expected = originalText.replace(
				/(up\s+to\s+(?:level\s+|L\s*))(\d+)|((?:Mesmerize|Frenzy Radius|Reaction Radius)\s*\(\d+\/)(\d+)/gi,
				( _, prefix, number, pair, cap ) => ( prefix || pair ) + ( Number( number || cap ) + rank )
			).replace( /Summon a level (\d+)/, ( _, number ) => 'Summon a level ' + ( Number( number ) + rank ) );
			assert.equal( cell.textContent, expected, `${title} rank ${rank}` );
			assert.equal( cell.classList.contains( 'sls-slot-modified' ), rank > 0, title );
			assert.equal( page.querySelector( '.eql-spellpage-summary-text p' ).textContent,
				`Affects creatures up to level ${30 + rank}.` );
			assert.match( page.querySelector( '.sls-rate-display' ).textContent,
				rank ? new RegExp( `Level cap \\+${rank}` ) : /Base values/ );
			assert.equal( page.querySelector( '.classes' ).textContent, 'Learning level 20' );
			assert.match( page.querySelector( '.eql-spellpage-detail-table' ).textContent, /@L44/ );
			assert.equal( page.querySelector( '.eql-spellpage-detail-table tr:last-child td' ).textContent, '5.00' );
			assert.equal( page.querySelector( '.eql-spellpage-detail-table tr:last-child td:last-child' ).textContent, 'Magic (-10)' );
			assert.deepEqual( [ ...cell.querySelectorAll( 'a' ) ], links );
		}
		assert.equal( cell.innerHTML, original, `${title} reset markup` );
	}
	window.document.body.innerHTML = card( 'No cap', 'Reduce Aggro Radius by 15; Memblur(1%); @L44' );
	await window.eqlSpellLevelSliderSetLevel( 10, window.document, false );
	assert.equal( window.document.querySelector( '.eql-spellpage-slot-table tr td:last-child' ).textContent,
		'Reduce Aggro Radius by 15; Memblur(1%); @L44' );
	assert.equal( window.document.querySelector( '.sls-slot-modified' ), null );
	// A new card enters through the MediaWiki content hook and uses saved rank.
	window.localStorage.setItem( 'sls-default-level-v1', '3' );
	window.document.body.innerHTML = card( 'Dynamic', 'Stun up to level 55' );
	hooks[ 'wikipage.content' ]( window.document.body );
	await window.eqlSpellLevelSliderRefresh( window.document.body );
	assert.match( window.document.querySelector( '.eql-spellpage-slot-table' ).textContent, /level 58/ );
	assert.equal( window.localStorage.getItem( 'sls-default-level-v1' ), '3' );
	window.close();
	console.log( 'Spell slider: 10 synthetic cards pass ranks 1/10/4/4/0; dynamic content passes.' );
}

main().catch( error => { console.error( error ); process.exitCode = 1; } );
