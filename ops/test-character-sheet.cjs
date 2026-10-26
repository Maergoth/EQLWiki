'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/resources/character-sheet.js' ), 'utf8' );
const css = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/resources/character-sheet.css' ), 'utf8' );
// The legacy socket markup proves already published snapshots are simplified.
const fixture = name => `<div class="eql-character-sheet eql-character-sheet-v1"><div class="eql-character-heading"><strong>${name}</strong><span class="eql-character-metadata">Level 60 · WAR / CLR · 123 AAs</span></div><div class="eql-character-body"><div class="eql-character-layout"><div class="eql-character-totals">HP: 1234</div><div class="eql-character-equipment"><div class="eql-character-equipment-row"><div class="eql-character-item"><a href="/wiki/Synthetic_Boots" title="Synthetic Boots">Boots</a><div class="eql-character-inspect-v1"><strong>Synthetic Boots</strong><span class="eql-character-tier">+3</span><div class="eql-character-item-stats">HP: 133</div><div class="eql-character-item-details">Flags: MAGIC<br>Class: WAR<br>Effect: Parent boots effect</div><div class="eql-character-augments"><strong>Exaltations (2 sockets)</strong><ul><li><span class="eql-character-augment-icon"><a href="/wiki/Synthetic_Sword">◇</a></span><span><strong><a href="/wiki/Synthetic_Sword">Synthetic Sword</a></strong><span class="eql-character-tier">+7</span><span class="eql-character-augment-stats">HP: 765</span><span class="eql-character-item-details">Flags: LORE<br>Size: SMALL<br>Effect: <a href="/wiki/See_Invisible" title="See Invisible">See Invisible</a> (Worn)<br>Class: ALL<br>Focus effect: <a href="/wiki/Synthetic_Focus">Synthetic Focus</a><br>Notes: Effect: Not an effect row<br>Race: ALL</span></span></li><li class="eql-character-empty-socket">Socket 2: Empty</li></ul></div></div></div></div></div></div></div></div>`;
const htmlIndex = process.argv.indexOf( '--html' );
const markup = htmlIndex < 0 ? fixture( 'Synthetic Reader (Fixture One)' ) + fixture( 'Synthetic Reader (Fixture Two)' ) : fs.readFileSync( process.argv[ htmlIndex + 1 ], 'utf8' );
const dom = new JSDOM( '<head><style>' + css + '</style></head><body><main>' + markup + '</main><button id="outside">Outside</button></body>', { url: 'https://wiki.example/wiki/User_talk:Synthetic', runScripts: 'outside-only' } );
const { window } = dom, document = window.document;
const screenshots = Array.from( document.querySelectorAll( '.eql-character-screenshot-v1' ) );
const screenshotMarkup = screenshots.map( element => element.outerHTML );
for ( const screenshot of screenshots ) {
    assert.notEqual( window.getComputedStyle( screenshot ).display, 'none', 'Static no-JS screenshot or upload placeholder stays visible.' );
    assert( screenshot.querySelector( 'a[href]' ), 'The screenshot or missing-image action is a native link without JavaScript.' );
}
let hook;
window.mw = { util: { getUrl: title => { assert.equal( title, 'Player_List' );return '/wiki/' + title; } }, hook: name => { assert.equal( name, 'wikipage.content' ); return { add: fn => { hook = fn; } }; } };
assert.equal( document.querySelectorAll( '.eql-character-body[hidden]' ).length, 0, 'Static no-JS content remains readable.' );
assert.notEqual( window.getComputedStyle( document.querySelector( '.eql-character-inspect-v1' ) ).display, 'none', 'No-JS item statistics remain visible, not just totals and links.' );
let timerId = 0;
const timers = new Map();
window.setTimeout = ( fn, delay ) => { assert.equal( delay, 200 ); timers.set( ++timerId, fn ); return timerId; };
window.clearTimeout = id => timers.delete( id );
window.eval( source );
document.dispatchEvent( new window.Event( 'DOMContentLoaded' ) );
let toggles = document.querySelectorAll( '.eql-character-disclosure' );
screenshots.forEach( ( screenshot, index ) => {
    assert( screenshot.isConnected );assert.equal( screenshot.outerHTML, screenshotMarkup[index], 'Enhancement never rewrites screenshot pixels, upload destinations or File identity.' );
    assert.equal( screenshot.querySelector( 'a' ).getAttribute( 'role' ), null, 'Native image/upload navigation is not turned into a custom button.' );
} );
assert.equal( toggles.length, 2 );
assert.equal( document.querySelectorAll( '.eql-character-body[hidden]' ).length, 2, 'Multiple characters start collapsed.' );
assert.notEqual( toggles[ 0 ].getAttribute( 'aria-controls' ), toggles[ 1 ].getAttribute( 'aria-controls' ) );
for ( const sheet of document.querySelectorAll( '.eql-character-sheet-v1' ) ) {
    const cta = sheet.querySelector( '.eql-character-players-cta' );
    assert( cta );assert.equal( cta.parentElement.className, 'eql-character-equipment' );assert.equal( cta.parentElement.lastElementChild, cta, 'Player link follows equipment rows.' );
    assert.equal( cta.href, 'https://wiki.example/wiki/Player_List' );assert.equal( cta.getAttribute( 'aria-label' ), 'View Other Players' );assert.equal( cta.tabIndex, 0 );
    assert.equal( cta.querySelector( 'svg' ).getAttribute( 'focusable' ), 'false' );assert.equal( cta.querySelectorAll( 'script,image,foreignObject,img' ).length, 0 );
}
toggles[ 0 ].click();
assert.equal( toggles[ 0 ].getAttribute( 'aria-expanded' ), 'true' );
assert.equal( toggles[ 1 ].getAttribute( 'aria-expanded' ), 'false', 'Each disclosure is independent.' );
const item = htmlIndex<0?document.querySelector('.eql-character-item'):[...document.querySelectorAll('.eql-character-item')].find(node=>node.querySelector('.eql-character-augments a'));
assert(item,'The fixture must include an equipment item with a known Exaltation link.');
const originalInspect=item.querySelector('.eql-character-inspect-v1').textContent;
item.getBoundingClientRect = () => ( { left: 970, right: 1020, top: 700 } );
item.dispatchEvent( new window.MouseEvent( 'pointerover', { bubbles: true } ) );
const popup = document.querySelector( '.eql-character-inspect-flyout' );
assert( popup && !popup.hidden );
assert.equal( popup.getAttribute( 'role' ), 'dialog' );assert.equal( popup.getAttribute( 'aria-label' ), 'Item details' );
assert.equal(popup.textContent,originalInspect,'The complete scoped tier-specific stats/metadata/Exaltations are cloned without truncation.');
assert.equal(popup.querySelectorAll('.eql-character-augments .eql-character-tier,.eql-character-augments .eql-character-augment-stats,.eql-character-augments .eql-character-item-details,.eql-character-augments .eql-character-empty-socket,.eql-character-augments .eql-character-augment-icon').length,0,'Actual parser output and legacy markup both reduce sockets to names/effects.');
if(htmlIndex<0){
    assert.match(popup.textContent,/HP:\s*133/);assert.match(popup.textContent,/Flags: MAGIC/);assert.match(popup.textContent,/Class: WAR/);assert.match(popup.textContent,/\+3/,'Parent gear tier remains intact.');
    const augments=popup.querySelector('.eql-character-augments');
    assert.match(augments.textContent,/Synthetic Sword/);assert.match(augments.textContent,/Effect: See Invisible \(Worn\)/);assert.match(augments.textContent,/Focus effect: Synthetic Focus/);
    assert(!/HP: 765|\+7|LORE|SMALL|Class: ALL|Race: ALL|Not an effect row|sockets|Empty/.test(augments.textContent),'Socket inspect contains only names and effect rows.');
    assert.equal(augments.querySelectorAll('.eql-character-tier,.eql-character-augment-stats,.eql-character-item-details,.eql-character-empty-socket,.eql-character-augment-icon').length,0);
    assert.equal(augments.querySelector('a[title="See Invisible"]').href,'https://wiki.example/wiki/See_Invisible','Canonical effect links remain interactive.');
}
const socketTarget=item.querySelector('.eql-character-augments a')?.getAttribute('href')||'/wiki/Synthetic_Sword';
assert([...popup.querySelectorAll('a')].some(a=>a.getAttribute('href')===socketTarget),'Known socket items retain clickable wiki destinations.');
document.body.dispatchEvent( new window.MouseEvent( 'pointerover', { bubbles: true } ) );assert.equal( popup.hidden, false, 'Crossing the gap must not immediately hide clickable item/augment links.' );assert.equal( timers.size, 1 );
popup.dispatchEvent( new window.MouseEvent( 'pointerover', { bubbles: true } ) );assert.equal( timers.size, 0, 'Entering the inspect cancels deferred hiding.' );assert.equal( popup.hidden, false );
document.body.dispatchEvent( new window.MouseEvent( 'pointerover', { bubbles: true } ) );const pendingHide = [ ...timers.values() ][ 0 ];timers.clear();pendingHide();assert.equal( popup.hidden, true, 'Leaving both trigger and inspect eventually dismisses it.' );
item.dispatchEvent( new window.MouseEvent( 'pointerover', { bubbles: true } ) );
assert( parseInt( popup.style.left, 10 ) >= 10 && parseInt( popup.style.top, 10 ) >= 10 );
document.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
assert.equal( popup.hidden, true );
const link = item.querySelector( 'a' );
link.focus();assert.equal( popup.hidden, false, 'Keyboard focus shows the same captured-tier inspect.' );
link.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true, cancelable: true } ) );assert( popup.contains( document.activeElement ), 'ArrowDown lets keyboard users enter the interactive inspect links.' );
document.body.dispatchEvent( new window.MouseEvent( 'pointerover', { bubbles: true } ) );const focusedHide = [ ...timers.values() ][ 0 ];timers.clear();focusedHide();assert.equal( popup.hidden, false, 'Pointer departure cannot dismiss focused inspect links.' );
document.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );assert.equal( document.activeElement, link, 'Escape from inspect links returns focus to the original gear link.' );assert.equal( popup.hidden, true );
link.blur();link.focus();assert.equal( popup.hidden, false );
document.getElementById( 'outside' ).focus();assert.equal( popup.hidden, true );
hook( [ document.querySelector( 'main' ) ] );hook( [ document.querySelector( 'main' ) ] );
assert.equal( document.querySelectorAll( '.eql-character-disclosure' ).length, 2, 'Dynamic refresh does not double-bind or duplicate buttons.' );
assert.equal( document.querySelectorAll( '.eql-character-players-cta' ).length, 2 );assert.equal(toggles[0].getAttribute('aria-expanded'),'true','Refresh preserves an opened character.');
const dynamic = document.createElement( 'section' );dynamic.innerHTML = fixture( 'Synthetic Reader (Fixture Three)' );document.body.appendChild( dynamic );hook( [ dynamic ] );
toggles = document.querySelectorAll( '.eql-character-disclosure' );assert.equal( toggles.length, 3 );assert.equal( toggles[ 2 ].getAttribute( 'aria-expanded' ), 'false' );
assert.equal(document.querySelectorAll('.eql-character-players-cta').length,3,'Each dynamically added character gets exactly one player link.');
toggles[ 0 ].click();assert.equal( document.querySelector( '.eql-character-body' ).hidden, true );assert.equal( popup.hidden, true );
assert.equal( document.querySelectorAll( 'script,img[src^="data:"]' ).length, 0 );
dom.window.close();

function fresh( markup, options = {} ) {
    const view = new JSDOM( '<body><main>' + markup + '</main></body>', { url: 'https://wiki.example/wiki/User:Synthetic', runScripts: 'outside-only' } );
    let refresh;
    if ( options.noMw !== true ) { view.window.mw = { ...options.mw, hook: () => ( { add: fn => { refresh = fn; } } ) }; }
    view.window.eval( source );
    if ( options.defer !== true ) { view.window.document.dispatchEvent( new view.window.Event( 'DOMContentLoaded' ) ); }
    return { view, document:view.window.document, refresh: content => refresh( [ content ] ) };
}
{
    const test=fresh(fixture('Single User character'),{mw:{config:{get:key=>key==='wgArticlePath'?'/article/$1':undefined}}}),doc=test.document;
    const toggle=doc.querySelector('.eql-character-disclosure'),body=doc.querySelector('.eql-character-body');
    assert.equal(toggle.getAttribute('aria-expanded'),'true');assert.equal(body.hidden,false,'A single User-page character starts expanded.');
    assert.equal(doc.querySelector('.eql-character-players-cta').href,'https://wiki.example/article/Player_List');
    toggle.click();test.refresh(doc.querySelector('main'));assert.equal(body.hidden,true,'Dynamic hooks do not undo a user collapse.');
    const equipment=doc.querySelector('.eql-character-equipment');equipment.querySelector('.eql-character-players-cta').remove();test.refresh(equipment);
    const cta=equipment.querySelector('.eql-character-players-cta');assert(cta,'A nested content refresh restores a missing CTA without rebinding the sheet.');
    assert.equal(body.hidden,true);toggle.click();cta.focus();assert.equal(doc.activeElement,cta,'The player link is available to keyboard users.');
    const enter=new test.view.window.KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true});cta.dispatchEvent(enter);assert.equal(enter.defaultPrevented,false,'The native anchor keeps normal Enter navigation.');
    const dynamic=doc.createElement('section');dynamic.innerHTML=fixture('Second character');doc.body.appendChild(dynamic);test.refresh(dynamic);
    assert.equal(toggle.getAttribute('aria-expanded'),'true','Adding another sheet preserves the already open one.');assert.equal(dynamic.querySelector('.eql-character-disclosure').getAttribute('aria-expanded'),'false','A one-sheet fragment counts every sheet on the page.');
    test.view.window.close();
}
{
    const test=fresh(fixture('First')+fixture('Second'),{defer:true}),second=test.document.querySelectorAll('.eql-character-sheet-v1')[1];test.refresh(second);
    assert.equal(second.querySelector('.eql-character-disclosure').getAttribute('aria-expanded'),'false','Initializing only one root still counts the entire page.');
    test.document.dispatchEvent(new test.view.window.Event('DOMContentLoaded'));assert.equal(test.document.querySelectorAll('.eql-character-body[hidden]').length,2);test.view.window.close();
}
for(const options of [
    {mw:{util:{getUrl:()=>{throw Error('Unavailable');}},config:{get:()=>'/w/index.php?title=$1'}}},
    {mw:{util:{getUrl:()=> 'https://outside.example/Player_List'},config:{get:()=>'/wiki/$1'}}},
    {mw:{util:{getUrl:()=> 'javascript:alert(1)'},config:{get:()=> 'https://outside.example/$1'}}},
    {noMw:true}
]){
    const test=fresh(fixture('Fallback'),options),link=test.document.querySelector('.eql-character-players-cta');assert.equal(new URL(link.href).origin,'https://wiki.example');assert.match(link.href,/Player_List/);test.view.window.close();
}
{
    const test=fresh(fixture('Sanitized effects').replace('href="/wiki/See_Invisible"','href="https://outside.example/See_Invisible"'));
    const socket=test.document.querySelector('.eql-character-augments');assert.match(socket.textContent,/Effect: See Invisible \(Worn\)/);assert.equal(socket.querySelector('a[href^="https://outside.example"]'),null,'Nonlocal effect destinations become text without copying untrusted anchor attributes.');test.view.window.close();
}
{
    const simple=fixture('New template').replace(/<span class="eql-character-augment-icon">[\s\S]*?<\/span>/,'').replace(/<span class="eql-character-tier">\+7<\/span>/,'').replace(/<span class="eql-character-augment-stats">[\s\S]*?<\/span>/,'');
    const test=fresh(simple),group=test.document.querySelector('.eql-character-augments'),effect=group.querySelector('a[title="See Invisible"]');
    const before=group.innerHTML;test.refresh(test.document.querySelector('main'));assert.equal(group.innerHTML,before,'Already reduced name/effect markup is idempotent.');assert.equal(group.querySelector('a[title="See Invisible"]'),effect,'A refresh preserves existing effect-link focus targets.');test.view.window.close();
}
assert.match(css,/eql-character-players-cta:focus-visible/);assert.match(css,/@media\(max-width:400px\)/);assert.match(css,/prefers-reduced-motion/);
const effectFixture = ( name='Synthetic Boots' ) => fixture('Effect hydration').replace('<strong>Synthetic Boots</strong>',`<strong><a href="/wiki/${encodeURIComponent(name.replace(/ /g,'_'))}" title="${name}">${name}</a></strong>`);
const settle = () => new Promise(resolve=>setImmediate(resolve));
async function checkEffects(){
    {
        const calls=[],pending=[];
        class Api { post(request){calls.push(request);return new Promise(resolve=>pending.push(resolve));} }
        const test=fresh(effectFixture()+effectFixture(),{mw:{Api}}),doc=test.document;
        const stats=[...doc.querySelectorAll('.eql-character-item-stats')].map(node=>node.textContent),tiers=[...doc.querySelectorAll('.eql-character-item>.eql-character-tier')].map(node=>node.textContent);
        await settle();assert.equal(calls.length,1);assert.equal(calls[0].action,'eqlcharactereffects');assert.equal(calls[0].formatversion,2);assert.deepEqual(calls[0].titles.split('|'),['Synthetic Boots','Synthetic Sword'],'Physical copies share one public metadata request per canonical item.');
        pending.shift()({eqlcharactereffects:{items:[null,{title:'Unrequested',effects:[{label:'Focus effect',value:'Do not display'}]},{title:'Synthetic Boots',stats:{HP:99999},effects:[{label:'Effect',value:'Conflicting current effect'},{label:'Focus effect',value:'Affliction Efficiency I',linkTitle:'Affliction Efficiency I'}]},{title:'Synthetic Sword',effects:[{label:'Effect',value:'Wrong replacement'},{label:'Focus effect',value:'Wrong replacement'},{label:'Click effect',value:'Synthetic click (Instant)',linkTitle:'Synthetic click'},{label:'Click effect',value:' Synthetic click   (Instant) ',linkTitle:'Synthetic click'},{label:'Flags',value:'Ignore me'}]}]}});
        await settle();
        for(const inspect of doc.querySelectorAll('.eql-character-inspect-v1')){
            const details=inspect.querySelector(':scope>.eql-character-item-details'),socket=inspect.querySelector('.eql-character-augments');
            assert.match(details.textContent,/Effect: Parent boots effect/);assert.match(details.textContent,/Focus effect: Affliction Efficiency I/);assert(!/Conflicting|99999|Do not display/.test(inspect.textContent));
            assert.equal(details.querySelector('a[title="Affliction Efficiency I"]').href,'https://wiki.example/index.php?title=Affliction_Efficiency_I');
            assert.match(socket.textContent,/Effect: See Invisible \(Worn\)/);assert.match(socket.textContent,/Click effect: Synthetic click \(Instant\)/);assert.equal((socket.textContent.match(/Click effect:/g)||[]).length,1);assert(!/Wrong replacement|Ignore me/.test(socket.textContent));
        }
        assert.deepEqual([...doc.querySelectorAll('.eql-character-item-stats')].map(node=>node.textContent),stats);assert.deepEqual([...doc.querySelectorAll('.eql-character-item>.eql-character-tier')].map(node=>node.textContent),tiers,'Live effects never refresh recorded gear numbers or tiers.');
        test.refresh(doc.querySelector('main'));test.refresh(doc.querySelector('main'));await settle();assert.equal(calls.length,1,'Repeated hooks neither poll nor retry existing titles.');
        const extra=doc.createElement('section');extra.innerHTML=effectFixture('Another item');doc.body.appendChild(extra);test.refresh(extra);await settle();assert.equal(calls.length,2);assert.equal(calls[1].titles,'Another item','Only previously unseen titles trigger a new batch.');extra.remove();pending.shift()({eqlcharactereffects:{items:[{title:'Another item',effects:[{label:'Focus effect',value:'Detached effect'}]}]}});await settle();assert(!extra.textContent.includes('Detached effect'),'An old detached sheet is not modified by a late response.');test.view.window.close();
    }
    {
        const calls=[],pending=[];
        class Api { post(request){calls.push(request);return new Promise(resolve=>pending.push(resolve));} }
        const test=fresh(effectFixture('Original Item'),{mw:{Api}}),doc=test.document;
        await settle();
        const inspect=doc.querySelector('.eql-character-inspect-v1'),parentLink=inspect.querySelector(':scope>strong>a'),socket=inspect.querySelector('.eql-character-augments li'),oldSocketLink=socket.querySelector(':scope>strong>a');
        const stats=inspect.querySelector('.eql-character-item-stats').textContent;
        parentLink.textContent='Replacement Item';parentLink.title='Replacement Item';parentLink.href='/wiki/Replacement_Item';
        const newSocketLink=doc.createElement('a');newSocketLink.textContent='Replacement Socket';newSocketLink.title='Replacement Socket';newSocketLink.href='/wiki/Replacement_Socket';oldSocketLink.replaceWith(newSocketLink);
        test.refresh(doc.querySelector('main'));
        pending.shift()({eqlcharactereffects:{items:[{title:'Original Item',effects:[{label:'Focus effect',value:'Stale parent effect'}]},{title:'Synthetic Sword',effects:[{label:'Click effect',value:'Stale socket effect'}]}]}});
        await settle();
        assert(!inspect.textContent.includes('Stale parent effect'),'An old response cannot hydrate a reused inspect whose existing name anchor changed.');
        assert(!socket.textContent.includes('Stale socket effect'),'An old response cannot hydrate a reused socket whose name anchor was replaced.');
        assert.equal(oldSocketLink.isConnected,false,'The former socket anchor is detached while its socket stays connected.');
        assert.equal(calls.length,2);assert.deepEqual(calls[1].titles.split('|'),['Replacement Item','Replacement Socket']);
        pending.shift()({eqlcharactereffects:{items:[{title:'Replacement Item',effects:[{label:'Focus effect',value:'Current parent effect'}]},{title:'Replacement Socket',effects:[{label:'Click effect',value:'Current socket effect'}]}]}});
        await settle();
        assert.match(inspect.querySelector(':scope>.eql-character-item-details').textContent,/Current parent effect/);
        assert.match(socket.textContent,/Current socket effect/,'The replacement item still receives its own requested metadata.');
        assert.equal(inspect.querySelector('.eql-character-item-stats').textContent,stats,'Identity guards do not change recorded statistics.');
        test.view.window.close();
    }
    {
        const calls=[],pending=[];let active=0,maxActive=0;
        class Api { post(request){calls.push(request);active++;maxActive=Math.max(maxActive,active);return new Promise(resolve=>pending.push(data=>{active--;resolve(data);}));} }
        const test=fresh(Array.from({length:51},(_,i)=>effectFixture('Synthetic item '+i)).join(''),{mw:{Api}});
        await settle();assert.equal(calls.length,1);assert.equal(calls[0].titles.split('|').length,50);pending.shift()({eqlcharactereffects:{items:[]}});await settle();assert.equal(calls.length,2);assert.equal(calls[1].titles.split('|').length,2);assert.equal(maxActive,1,'Public lookup batches are serial, not a per-item fan-out.');pending.shift()({eqlcharactereffects:{items:[]}});await settle();test.refresh(test.document.querySelector('main'));await settle();assert.equal(calls.length,2);test.view.window.close();
    }
    {
        let calls=0;class Api { post(){calls++;return Promise.reject(Error('Synthetic unavailable service'));} }
        const test=fresh(effectFixture(),{mw:{Api}});await settle();test.refresh(test.document.querySelector('main'));await settle();assert.equal(calls,1);assert.match(test.document.querySelector('.eql-character-augments').textContent,/See Invisible/,'Failures quietly preserve supplied effect metadata.');test.view.window.close();
    }
}
checkEffects().then(()=>console.log('Character sheet DOM passed: single-expanded/multiple-independent sheets, local CTA, effect-only legacy/new sockets, read-only batched missing-effect hydration, immutable stats/tiers, keyboard inspect and dynamic idempotence.')).catch(error=>{console.error(error);process.exitCode=1;});
