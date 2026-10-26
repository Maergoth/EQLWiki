'use strict';
// Dependency-only DOM regression. No MediaWiki bootstrap, account state, network
// request, database publication or real character export is used.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const root = path.join( __dirname, '..' );
const script = fs.readFileSync( path.join( root, 'skins/EQLImmersive/resources/character-sheet.js' ), 'utf8' );
const css = fs.readFileSync( path.join( root, 'skins/EQLImmersive/resources/character-sheet.css' ), 'utf8' );
const template = fs.readFileSync( path.join( root, 'ops/character-sheet.wiki' ), 'utf8' );
assert.match( template, /EQL_CHARACTER_ACHIEVEMENTS_V1/ );
assert( template.indexOf( 'class="eql-character-achievements-v1"' ) > template.indexOf( 'class="eql-character-equipment"' ), 'Achievements follow the equipment/totals layout.' );
const category = name => `<div class="eql-character-achievement-category"><div class="eql-character-achievement-category-heading">${name} (0 / 1)</div><ul class="eql-character-achievement-list"><li class="eql-character-achievement"><div class="eql-character-achievement-heading"><strong>Unfinished parent</strong> <span class="eql-character-achievement-status">Incomplete</span></div><ul class="eql-character-achievement-objectives"><li class="is-complete"><span class="eql-character-achievement-status">Complete</span> Finished objective <span class="eql-character-achievement-progress">4 / 4</span></li><li class="is-incomplete"><span class="eql-character-achievement-status">Incomplete</span> Continue exploration <span class="eql-character-achievement-progress">2 / 10</span></li></ul></li></ul></div>`;
const achievements = `<div class="eql-character-achievements-v1"><div class="eql-character-achievements-heading">Achievements</div><div class="eql-character-achievements-body"><p class="eql-character-achievements-summary">0 / 2 achievements complete</p>${category( 'Classic' )}${category( 'Exploration &amp; Travel' )}</div></div>`;
const sheet = name => `<div class="eql-character-sheet eql-character-sheet-v1"><div class="eql-character-heading"><strong>${name}</strong></div><div class="eql-character-body"><div class="eql-character-layout"><div class="eql-character-totals">HP: 1234</div><div class="eql-character-equipment"></div></div>${achievements}</div></div>`;
const dom = new JSDOM( `<style>${css}</style><main>${sheet( 'Fixture One' )}${sheet( 'Fixture Two' )}</main>`, { url: 'https://wiki.example/User:Fixture', runScripts: 'outside-only' } );
const { window } = dom, document = window.document;
let refresh;
window.mw = { util: { getUrl: () => '/Player_List' }, hook: () => ( { add: value => { refresh = value; } } ) };
assert.equal( document.querySelectorAll( '[hidden]' ).length, 0, 'Achievement content remains readable without enhancement.' );
const before = [ ...document.querySelectorAll( '.eql-character-achievement' ) ].map( node => node.textContent );
window.eval( script );document.dispatchEvent( new window.Event( 'DOMContentLoaded' ) );
const sections = [ ...document.querySelectorAll( '.eql-character-achievements-v1' ) ];
assert.equal( document.querySelectorAll( '.eql-character-achievements-disclosure' ).length, 6 );
assert.equal( document.querySelectorAll( '.eql-character-achievements-body[hidden]' ).length, 2, 'Achievement sections start collapsed independently of the character sheet.' );
assert.equal( document.querySelectorAll( '.eql-character-achievement-list[hidden]' ).length, 4, 'Categories start collapsed.' );
assert.equal( document.querySelectorAll( '.eql-character-achievement-count' ).length, 4, 'Both collapsed character and achievement headers expose the completed count.' );
assert.equal( document.querySelector( '.eql-character-disclosure > .eql-character-achievement-count' ).getAttribute( 'aria-label' ), '0 completed achievements', 'Known zero is shown and labeled, rather than treated as missing.' );
const controls = [ ...document.querySelectorAll( 'button[aria-controls]' ) ];
assert.equal( new Set( controls.map( button => button.getAttribute( 'aria-controls' ) ) ).size, controls.length, 'All character and nested disclosure IDs are unique.' );
for ( const button of controls ) { assert.equal( button.type, 'button' );assert( document.getElementById( button.getAttribute( 'aria-controls' ) ) ); }
document.querySelector( '.eql-character-disclosure' ).click();
const mainToggle = sections[0].querySelector( ':scope > button' ), categoryToggle = sections[0].querySelector( '.eql-character-achievement-category > button' ), characterToggle = sections[0].closest( '.eql-character-sheet-v1' ).querySelector( ':scope > .eql-character-disclosure' );
mainToggle.focus();assert.equal( document.activeElement, mainToggle );mainToggle.click();assert.equal( mainToggle.getAttribute( 'aria-expanded' ), 'true' );
categoryToggle.click();assert.equal( categoryToggle.getAttribute( 'aria-expanded' ), 'true' );
assert.equal( sections[1].querySelector( ':scope > button' ).getAttribute( 'aria-expanded' ), 'false', 'Another character remains unchanged.' );
assert.deepEqual( [ ...document.querySelectorAll( '.eql-character-achievement' ) ].map( node => node.textContent ), before, 'Completion and progress are not recalculated from objectives.' );
refresh( [ document.querySelector( 'main' ) ] );refresh( [ sections[0] ] );
assert.equal( document.querySelectorAll( '.eql-character-achievements-disclosure' ).length, 6, 'Repeated content hooks are idempotent.' );
assert.equal( mainToggle.getAttribute( 'aria-expanded' ), 'true' );assert.equal( categoryToggle.getAttribute( 'aria-expanded' ), 'true' );
const extra = document.createElement( 'div' );extra.innerHTML = category( 'New fragment' );sections[0].querySelector( '.eql-character-achievements-body' ).appendChild( extra );refresh( [ extra ] );
assert.equal( extra.querySelector( 'button' ).getAttribute( 'aria-expanded' ), 'false', 'New categories inside an existing initialized character are enhanced.' );
const summary = sections[0].querySelector( '.eql-character-achievements-summary' );
summary.textContent = '76 / 491 achievements complete';refresh( [ summary ] );
assert.equal( document.querySelector( '.eql-character-disclosure > .eql-character-achievement-count .eql-character-achievement-count-number' ).textContent, '76' );
assert.equal( mainToggle.querySelector( '.eql-character-achievement-count-number' ).textContent, '76' );
assert.equal( document.querySelectorAll( '.eql-character-achievement-count' ).length, 4, 'Refresh updates existing badges without duplication or cross-character leakage.' );
assert.equal( mainToggle.getAttribute( 'aria-expanded' ), 'true', 'Refreshing counts preserves open disclosure state.' );
for ( const invalid of [ '5 / 2 achievements complete', '-1 / 2 achievements complete', '1.5 / 2 achievements complete', '1 / 1000000001 achievements complete', '01 / 2 achievements complete', '0 / 02 achievements complete', '1 / 2 Achievements complete', '76 / 491 achievements complete extra' ] ) {
    summary.textContent = invalid;refresh( [ summary ] );assert.equal( characterToggle.querySelector( '.eql-character-achievement-count' ), null );assert.equal( mainToggle.querySelector( '.eql-character-achievement-count' ), null );
}
summary.textContent = '76 / 491 achievements complete';const duplicate = summary.cloneNode( true );summary.parentNode.appendChild( duplicate );refresh( [ summary ] );assert.equal( mainToggle.querySelector( '.eql-character-achievement-count' ), null, 'Ambiguous duplicate summaries are unranked.' );duplicate.remove();refresh( [ summary ] );
const legacy = document.createElement( 'div' );legacy.innerHTML = '<div class="eql-character-sheet-v1"><div class="eql-character-heading"><strong>Legacy</strong></div><div class="eql-character-body">No exported achievements</div></div>';document.querySelector( 'main' ).appendChild( legacy );refresh( [ legacy ] );assert.equal( legacy.querySelector( '.eql-character-achievement-count' ), null, 'Legacy sheets do not invent a zero.' );
assert.match( css, /eql-character-achievements-v1 \[hidden\]\{display:none!important\}/ );
assert.match( css, /eql-character-achievement-count-number\{[^}]*Georgia[^}]*font-size:32px/ );
dom.window.close();
console.log( 'Character achievement disclosures: static fallback, hierarchy, completion/counters, independent accessible toggles, dynamic fragments and idempotence passed.' );
