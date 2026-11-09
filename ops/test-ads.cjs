'use strict';

// Synthetic browser checks only: no provider network, account, or real ad impressions.
// NODE_PATH=<private jsdom node_modules> node ops/test-ads.cjs
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/resources/ads.js' ), 'utf8' );
const template = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/includes/templates/skin.mustache' ), 'utf8' );
const toggleMarkup = template.match( /<label class="eql-ads-choice"[\s\S]*?<\/label>/ )?.[ 0 ];
const adMarkup = template.match( /<aside id="eql-advertisement"[\s\S]*?<\/aside>/ )?.[ 0 ];
assert.ok( toggleMarkup && adMarkup, 'The active skin must render the control and inert unit.' );
const key = 'eql-ads-v1:fixturewiki';
const cookieName = 'eql_ads_off_' + encodeURIComponent( key );
const config = { client: 'ca-pub-1234567890123456', slot: '1234567890' };
let checks = 0;

function visit( options = {} ) {
    const errors = [];
    const virtualConsole = new VirtualConsole();
    virtualConsole.on( 'jsdomError', error => errors.push( error ) );
    const dom = new JSDOM( '<!doctype html><html><head></head><body>' +
        '<main><div class="mw-parser-output">Synthetic article</div>' +
        adMarkup + '</main>' + toggleMarkup + '</body></html>', {
        url: options.url || 'https://eqlwiki.com/Synthetic_article', runScripts: 'outside-only', virtualConsole
    } );
    const w = dom.window;
    if ( options.cookie ) { w.document.cookie = options.cookie; }
    const cookieAccess = Object.getOwnPropertyDescriptor( w.Document.prototype, 'cookie' );
    const cookieWrites = [];
    Object.defineProperty( w.document, 'cookie', {
        get() { if ( options.cookieReadBlocked ) { throw new Error( 'Blocked' ); } return cookieAccess.get.call( w.document ); },
        set( value ) { cookieWrites.push( value ); if ( !options.cookieBlocked ) { cookieAccess.set.call( w.document, value ); } }
    } );
    const local = new Map( Object.entries( options.local || {} ) );
    const session = new Map( Object.entries( options.session || {} ) );
    const store = ( data, blocked ) => ( {
        getItem( name ) { if ( blocked === 'all' || blocked?.read ) { throw new Error( 'Blocked' ); } return data.has( name ) ? data.get( name ) : null; },
        setItem( name, value ) { if ( blocked === 'all' || blocked?.write ) { throw new Error( 'Blocked' ); } data.set( name, String( value ) ); },
        removeItem( name ) { if ( blocked === 'all' || blocked?.remove ) { throw new Error( 'Blocked' ); } data.delete( name ); }
    } );
    const localStore = store( local, options.localBlocked );
    const sessionStore = store( session, options.sessionBlocked );
    Object.defineProperty( w, 'localStorage', { value: localStore } );
    Object.defineProperty( w, 'sessionStorage', { value: sessionStore } );
    let accountWrites = 0;
    w.mw = {
        config: { get: name => ( { wgWikiID: options.wiki || 'fixturewiki', wgScriptPath: '',
            wgUserId: options.user || 0, wgEQLAdSense: options.config === undefined ? config : options.config } )[ name ] },
        Api: function () { accountWrites++; throw new Error( 'Advertising must not write account data' ); }
    };
    w.eval( source );
    w.document.dispatchEvent( new w.Event( 'DOMContentLoaded' ) );
    const toggle = w.document.getElementById( 'eql-ads-toggle' );
    const scripts = () => [ ...w.document.querySelectorAll( 'script[src]' ) ];
    const reloads = () => errors.filter( error => /navigation/i.test( error.message ) ).length;
    const choose = enabled => { toggle.checked = enabled; toggle.dispatchEvent( new w.Event( 'change' ) ); };
    const storage = ( newValue, storageKey = key, area = localStore, update = true ) => {
        if ( update && area === localStore ) {
            if ( storageKey === null ) { local.clear(); }
            else if ( newValue === null ) { local.delete( storageKey ); }
            else { local.set( storageKey, newValue ); }
        }
        const event = new w.Event( 'storage' );
        Object.defineProperties( event, { key: { value: storageKey }, newValue: { value: newValue }, storageArea: { value: area } } );
        w.dispatchEvent( event );
    };
    return { w, local, session, toggle, scripts, choose, storage, reloads, cookieWrites,
        hidden: () => w.document.getElementById( 'eql-advertisement' ).hidden,
        accountWrites: () => accountWrites, close: () => w.close() };
}

function test( name, run ) {
    run(); checks++; console.log( 'PASS ' + name );
}

test( 'The actual skin renders an unchecked native Ads control directly after Donate', () => {
    const dom = new JSDOM( template );
    const toggle = dom.window.document.getElementById( 'eql-ads-toggle' );
    assert.equal( toggle.type, 'checkbox' );
    assert.equal( toggle.checked, false );
    assert.equal( toggle.disabled, true, 'Remain inert if JavaScript cannot initialize.' );
    assert.equal( toggle.closest( 'label' ).textContent.trim(), 'Ads' );
    assert.equal( toggle.closest( 'label' ).previousElementSibling.id, 'eql-support-button' );
    assert.equal( dom.window.document.getElementById( 'eql-advertisement' ).hidden, true );
    const manifest = JSON.parse( fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/skin.json' ), 'utf8' ) );
    assert.ok( manifest.ResourceModules[ 'skins.EQLImmersive.base' ].scripts.includes( 'resources/ads.js' ) );
    dom.window.close();
} );

for ( const value of [ undefined, 'off', '', 'true', 'ON', 'on\n', '{"enabled":true}' ] ) {
    test( 'No initial advertising for ' + JSON.stringify( value ), () => {
        const page = visit( { local: value === undefined ? {} : { [ key ]: value } } );
        assert.equal( page.toggle.disabled, false );
        assert.equal( page.toggle.checked, false );
        assert.equal( page.hidden(), true );
        assert.equal( page.scripts().length, 0 );
        assert.equal( page.accountWrites(), 0 ); page.close();
    } );
}
test( 'Explicit opt-in requests one responsive unit; script load initializes once', () => {
    const page = visit(); page.choose( true );
    assert.equal( page.local.get( key ), 'on' );
    assert.equal( page.scripts().length, 1 );
    assert.equal( page.hidden(), false );
    const script = page.scripts()[ 0 ];
    assert.equal( script.src, 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + config.client );
    assert.equal( script.async, true ); assert.equal( script.crossOrigin, 'anonymous' );
    const ad = page.w.document.querySelector( 'ins.adsbygoogle' );
    assert.equal( ad.dataset.adSlot, config.slot ); assert.equal( ad.dataset.adFormat, 'auto' );
    script.dispatchEvent( new page.w.Event( 'load' ) );
    script.dispatchEvent( new page.w.Event( 'load' ) );
    page.choose( true ); page.w.eval( source );
    assert.equal( page.w.adsbygoogle.length, 1 ); assert.equal( page.scripts().length, 1 ); page.close();
} );
test( 'Saved preference survives navigation and login without account writes', () => {
    const first = visit(); first.choose( true ); const local = Object.fromEntries( first.local ); first.close();
    for ( const user of [ 0, 42, 77 ] ) {
        const page = visit( { local, user, url: 'https://eqlwiki.com/Another_article' } );
        assert.equal( page.toggle.checked, true ); assert.equal( page.scripts().length, 1 );
        assert.equal( page.accountWrites(), 0 ); page.close();
    }
} );
test( 'OFF persists before reload and prevents a late initialization', () => {
    const page = visit( { local: { [ key ]: 'on' } } );
    const script = page.scripts()[ 0 ]; page.choose( false );
    assert.equal( page.local.get( key ), 'off' ); assert.equal( page.hidden(), true );
    assert.equal( page.w.document.querySelector( 'ins.adsbygoogle' ), null );
    assert.equal( page.reloads(), 1 );
    script.dispatchEvent( new page.w.Event( 'load' ) ); assert.equal( page.w.adsbygoogle, undefined );
    const next = visit( { local: Object.fromEntries( page.local ) } );
    assert.equal( next.scripts().length, 0 ); next.close(); page.close();
} );
test( 'Cross-tab OFF, clear, and malformed updates stop a loaded provider', () => {
    for ( const [ value, storageKey ] of [ [ 'off', key ], [ null, null ], [ 'corrupt', key ] ] ) {
        const page = visit( { local: { [ key ]: 'on' } } ); page.storage( value, storageKey );
        assert.equal( page.toggle.checked, false ); assert.equal( page.hidden(), true ); assert.equal( page.reloads(), 1 ); page.close();
    }
} );
test( 'Cross-tab ON loads once and other wiki preferences have no effect', () => {
    const page = visit(); page.storage( 'on', 'eql-ads-v1:anotherwiki' ); assert.equal( page.scripts().length, 0 );
    page.storage( 'on' ); page.storage( 'on' ); assert.equal( page.scripts().length, 1 ); page.close();
} );
test( 'Unavailable local storage starts OFF and session opt-in survives tab navigation', () => {
    const page = visit( { localBlocked: 'all' } ); assert.equal( page.scripts().length, 0 );
    page.choose( true ); assert.equal( page.session.get( key ), 'on' ); assert.equal( page.scripts().length, 1 );
    const next = visit( { localBlocked: 'all', session: Object.fromEntries( page.session ) } );
    assert.equal( next.toggle.checked, true ); assert.equal( next.scripts().length, 1 ); next.close(); page.close();
} );
test( 'A stale persistent ON cannot override a newer session OFF', () => {
    const page = visit( { localBlocked: 'all', local: { [ key ]: 'on' }, session: { [ key ]: 'on' } } );
    page.choose( false ); assert.equal( page.session.get( key ), 'off' );
    const next = visit( { local: Object.fromEntries( page.local ), session: Object.fromEntries( page.session ) } );
    assert.equal( next.toggle.checked, false ); assert.equal( next.scripts().length, 0 ); next.close(); page.close();
} );
test( 'Readable immutable ON stores use a verified host-only OFF cookie and allow explicit re-enable', () => {
    const failures = { write: true, remove: true };
    const page = visit( { local: { [ key ]: 'on' }, session: { [ key ]: 'on' }, localBlocked: failures, sessionBlocked: failures } );
    assert.equal( page.scripts().length, 1 ); page.choose( false );
    assert.equal( page.toggle.checked, false ); assert.equal( page.hidden(), true ); assert.equal( page.reloads(), 1 );
    assert.equal( page.w.document.cookie, cookieName + '=off' );
    const write = page.cookieWrites.find( value => value.startsWith( cookieName + '=off;' ) );
    assert.ok( write.includes( '; Path=/' ) && write.includes( '; SameSite=Lax' ) && write.includes( '; Secure' ) );
    assert.equal( /domain=/i.test( write ), false );
    const next = visit( { local: Object.fromEntries( page.local ), session: Object.fromEntries( page.session ),
        localBlocked: failures, sessionBlocked: failures, cookie: page.w.document.cookie + '; Path=/; Secure' } );
    assert.equal( next.toggle.checked, false ); assert.equal( next.scripts().length, 0 );
    next.storage( 'on', key, undefined, false );
    assert.equal( next.w.document.cookie, cookieName + '=off' ); assert.equal( next.scripts().length, 0 );
    next.choose( true );
    assert.equal( next.w.document.cookie, '' ); assert.equal( next.toggle.checked, true ); assert.equal( next.scripts().length, 1 );
    next.close(); page.close();
} );
test( 'An OFF cookie for another wiki does not alter this wiki preference', () => {
    const page = visit( { local: { [ key ]: 'on' }, cookie: 'eql_ads_off_otherwiki=off; Path=/; Secure' } );
    assert.equal( page.toggle.checked, true ); assert.equal( page.scripts().length, 1 ); page.close();
} );
test( 'Explicit ON fails OFF when the saved OFF cookie cannot be cleared', () => {
    const page = visit( { local: { [ key ]: 'on' }, cookie: cookieName + '=off; Path=/; Secure', cookieBlocked: true } );
    page.choose( true ); assert.equal( page.toggle.checked, false ); assert.equal( page.scripts().length, 0 );
    assert.equal( page.w.document.cookie, cookieName + '=off' ); page.close();
} );
test( 'Blocked OFF persistence hides ads without reloading into stale ON and latches across pageshow', () => {
    const failures = { write: true, remove: true };
    const page = visit( { local: { [ key ]: 'on' }, session: { [ key ]: 'on' },
        localBlocked: failures, sessionBlocked: failures, cookieBlocked: true } );
    const script = page.scripts()[ 0 ]; script.dispatchEvent( new page.w.Event( 'load' ) ); page.choose( false );
    assert.equal( page.toggle.checked, false ); assert.equal( page.hidden(), true ); assert.equal( page.reloads(), 0 );
    assert.match( page.toggle.closest( 'label' ).title, /could not save OFF.*Close this tab to fully stop/ );
    page.w.dispatchEvent( new page.w.PageTransitionEvent( 'pageshow', { persisted: true } ) );
    page.storage( 'on', key, undefined, false );
    assert.equal( page.toggle.checked, false ); assert.equal( page.hidden(), true ); assert.equal( page.reloads(), 0 );
    assert.equal( page.local.get( key ), 'on' ); assert.equal( page.session.get( key ), 'on' );
    assert.equal( page.w.document.cookie, '' ); page.close();
} );
test( 'Unreadable cookies cannot falsely verify a silently rejected OFF tombstone', () => {
    const failures = { write: true, remove: true };
    const page = visit( { local: { [ key ]: 'on' }, session: { [ key ]: 'on' },
        localBlocked: failures, sessionBlocked: failures, cookieBlocked: true, cookieReadBlocked: true } );
    assert.equal( page.scripts().length, 0 ); page.choose( false );
    assert.equal( page.toggle.checked, false ); assert.equal( page.reloads(), 0 );
    assert.match( page.toggle.closest( 'label' ).title, /could not save your preference/ );
    page.choose( true ); assert.equal( page.scripts().length, 0 ); page.close();
} );
test( 'Both stores unavailable fails OFF when opt-in cannot be saved', () => {
    const page = visit( { localBlocked: 'all', sessionBlocked: 'all' } ); page.choose( true );
    assert.equal( page.toggle.checked, false ); assert.equal( page.scripts().length, 0 ); page.close();
} );
test( 'Persistent OFF wins when an old session ON cannot be removed or overwritten', () => {
    const failures = { write: true, remove: true };
    const page = visit( { local: { [ key ]: 'on' }, session: { [ key ]: 'on' }, sessionBlocked: failures } );
    page.choose( false );
    assert.equal( page.local.get( key ), 'off' ); assert.equal( page.session.get( key ), 'on' );
    assert.equal( page.toggle.checked, false ); assert.equal( page.reloads(), 1 );
    const next = visit( { local: Object.fromEntries( page.local ), session: Object.fromEntries( page.session ), sessionBlocked: failures } );
    assert.equal( next.toggle.checked, false ); assert.equal( next.scripts().length, 0 ); next.close(); page.close();
} );
test( 'Unremovable writable session fallback aligns with an explicit later ON', () => {
    const page = visit( { local: { [ key ]: 'off' }, session: { [ key ]: 'off' }, sessionBlocked: { remove: true } } );
    page.choose( true );
    assert.equal( page.local.get( key ), 'on' ); assert.equal( page.session.get( key ), 'on' );
    assert.equal( page.toggle.checked, true ); assert.equal( page.scripts().length, 1 ); page.close();
} );
test( 'Opt-in fails OFF if a contradictory session OFF cannot be reconciled', () => {
    const page = visit( { local: { [ key ]: 'off' }, session: { [ key ]: 'off' }, sessionBlocked: { write: true, remove: true } } );
    page.choose( true );
    assert.equal( page.toggle.checked, false ); assert.equal( page.local.get( key ), 'off' ); assert.equal( page.scripts().length, 0 ); page.close();
} );
test( 'Normal explicit ON clears a writable older session OFF', () => {
    const page = visit( { local: { [ key ]: 'on' }, session: { [ key ]: 'off' } } );
    assert.equal( page.toggle.checked, false ); page.choose( true );
    assert.equal( page.toggle.checked, true ); assert.equal( page.session.has( key ), false ); assert.equal( page.scripts().length, 1 ); page.close();
} );
test( 'Cross-tab OFF survives navigation despite an unremovable old session ON', () => {
    const failures = { write: true, remove: true };
    const page = visit( { local: { [ key ]: 'on' }, session: { [ key ]: 'on' }, sessionBlocked: failures } );
    page.storage( 'off' );
    assert.equal( page.local.get( key ), 'off' ); assert.equal( page.toggle.checked, false );
    const next = visit( { local: Object.fromEntries( page.local ), session: Object.fromEntries( page.session ) } );
    assert.equal( next.toggle.checked, false ); assert.equal( next.scripts().length, 0 ); next.close(); page.close();
} );
test( 'A queued old cross-tab ON cannot reverse a newer persisted OFF', () => {
    const page = visit( { local: { [ key ]: 'on' } } );
    page.local.set( key, 'off' ); page.storage( 'on', key, undefined, false );
    assert.equal( page.toggle.checked, false ); assert.equal( page.local.get( key ), 'off' );
    assert.equal( page.hidden(), true ); assert.equal( page.reloads(), 1 ); page.close();
} );
test( 'Back/forward restoration honors a later same-tab OFF and stops the old provider', () => {
    const page = visit( { local: { [ key ]: 'on' } } );
    const script = page.scripts()[ 0 ];
    script.dispatchEvent( new page.w.Event( 'load' ) );
    page.local.set( key, 'off' );
    page.w.dispatchEvent( new page.w.PageTransitionEvent( 'pageshow', { persisted: true } ) );
    assert.equal( page.toggle.checked, false ); assert.equal( page.hidden(), true );
    assert.equal( page.w.document.querySelector( 'ins.adsbygoogle' ), null );
    assert.equal( page.reloads(), 1 ); page.close();
} );
test( 'Normal and restored pageshow never duplicate an enabled provider', () => {
    const page = visit( { local: { [ key ]: 'on' } } );
    const script = page.scripts()[ 0 ]; script.dispatchEvent( new page.w.Event( 'load' ) );
    for ( const persisted of [ false, true, false ] ) {
        page.w.dispatchEvent( new page.w.PageTransitionEvent( 'pageshow', { persisted } ) );
    }
    assert.equal( page.scripts().length, 1 ); assert.equal( page.w.adsbygoogle.length, 1 );
    assert.equal( page.w.document.querySelectorAll( 'ins.adsbygoogle' ).length, 1 );
    assert.equal( page.reloads(), 0 ); page.close();
} );
test( 'Absent or invalid publisher configuration never requests Google or leaves a gap', () => {
    for ( const invalid of [ null, {}, { ...config, client: 'ca-pub-123' }, { ...config, client: config.client + '\n' },
        { ...config, slot: 1234 }, { ...config, slot: '12<script>' }, { ...config, slot: '123\n' } ] ) {
        const page = visit( { config: invalid, local: { [ key ]: 'on' } } );
        assert.equal( page.toggle.checked, true ); assert.equal( page.scripts().length, 0 ); assert.equal( page.hidden(), true ); page.close();
    }
} );
test( 'Clones never load ads even with copied publisher configuration', () => {
    for ( const url of [ 'https://test.eqlwiki.com/Article', 'http://127.0.0.1:8080/Article', 'https://other.example/Article' ] ) {
        const page = visit( { url, local: { [ key ]: 'on' } } ); assert.equal( page.scripts().length, 0 ); page.close();
    }
} );
test( 'A blocked provider collapses the unit', () => {
    const page = visit( { local: { [ key ]: 'on' } } ); page.scripts()[ 0 ].dispatchEvent( new page.w.Event( 'error' ) );
    assert.equal( page.hidden(), true ); assert.equal( page.w.document.querySelector( 'ins' ), null ); page.close();
} );
console.log( checks + ' synthetic advertising checks passed; no live provider or real revenue tested.' );
