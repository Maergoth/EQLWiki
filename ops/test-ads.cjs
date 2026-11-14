'use strict';

// Synthetic browser checks only: no account, provider network, or real ad impressions.
// NODE_PATH=<private jsdom node_modules> node ops/test-ads.cjs
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/resources/ads.js' ), 'utf8' );
const template = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/includes/templates/skin.mustache' ), 'utf8' );
const toggleMarkup = template.match( /<div id="eql-ads-toggle"[\s\S]*?<\/div>/ )?.[ 0 ];
const sidebarMarkup = template.match( /<aside id="eql-ad-sidebar"[\s\S]*?<\/aside>/ )?.[ 0 ];
const bannerMarkup = template.match( /<aside id="eql-ad-banner"[\s\S]*?<\/aside>/ )?.[ 0 ];
const adMarkup = template.match( /<aside id="eql-advertisement"[\s\S]*?<\/aside>/ )?.[ 0 ];
assert.ok( toggleMarkup && adMarkup && sidebarMarkup && bannerMarkup, 'The active skin must render the control and inert units.' );
const OPTION = 'userjs-eql-ads';
const wiki = 'fixturewiki';
const cookieName = 'eql_ads_session_v1_' + encodeURIComponent( wiki );
const sessionKey = 'eql-ads-session-v1:' + encodeURIComponent( wiki );
const legacyKey = 'eql-ads-v1:' + encodeURIComponent( wiki );
const config = { client: 'ca-pub-1234567890123456', format: 'anchor', environment: 'production' };
const stagingConfig = { client: 'ca-pub-9876543210987654', format: 'anchor', environment: 'staging' };
const offNotice = 'Ads disabled. Log in to save your preference';
const staging = { url: 'https://test.eqlwiki.com/Article', config: { preview: true } };
const cases = [];
const settle = async () => { await new Promise( resolve => setImmediate( resolve ) ); };

function deferred() {
    let resolve, reject;
    const promise = new Promise( ( yes, no ) => { resolve = yes; reject = no; } );
    return { promise, resolve, reject };
}

function visit( options = {} ) {
    const errors = [];
    const virtualConsole = new VirtualConsole();
    virtualConsole.on( 'jsdomError', error => errors.push( error ) );
    const dom = new JSDOM( '<!doctype html><html><head></head><body class="skin-eqlimmersive"><div class="mw-page-container-inner">' +
        '<div class="mw-content-container"><main id="content"><h1>Synthetic article</h1><div id="bodyContent"><div id="contentSub">Synthetic subtitle</div>' +
        ( options.articleAnchor === false ? '<div class="mw-parser-output">Synthetic article</div>' :
            '<div id="mw-content-text" class="mw-body-content"><div class="mw-parser-output">Synthetic article</div></div>' ) +
        '<div id="catlinks">Synthetic categories</div></div></main>' + sidebarMarkup + bannerMarkup + '</div>' + adMarkup +
        ( options.footer === false ? toggleMarkup : '<div class="mw-footer-container"><button id="eql-support-button">Donate</button>' +
            toggleMarkup + '</div>' ) + '</div></body></html>', {
        url: options.url || 'https://eqlwiki.com/Synthetic_article', runScripts: 'outside-only', virtualConsole
    } );
    const w = dom.window;
    const layoutFrames = new Map(), layoutObservers = [];
    let frameId = 0;
    if ( options.anchorLayout ) {
        Object.defineProperties( w, { innerHeight: { value: 720, writable: true }, innerWidth: { value: 1280, writable: true } } );
        w.requestAnimationFrame = callback => { layoutFrames.set( ++frameId, callback ); return frameId; };
        w.cancelAnimationFrame = id => layoutFrames.delete( id );
        w.ResizeObserver = class {
            constructor( callback ) { this.callback = callback; this.targets = new Set(); layoutObservers.push( this ); }
            observe( node ) { this.targets.add( node ); }
            disconnect() { this.targets.clear(); }
        };
    }
    if ( options.viewport ) {
        Object.defineProperties( w, { innerWidth: { value: options.viewport.width, writable: true }, innerHeight: { value: options.viewport.height, writable: true } } );
        w.matchMedia = () => ( { matches: options.viewport.fine !== false } );
        Object.defineProperty( w.navigator, 'maxTouchPoints', { value: options.viewport.touch || 0 } );
        w.document.querySelector( '.mw-content-container' ).getBoundingClientRect = () => ( { width: options.viewport.contentWidth ?? w.innerWidth - 32 } );
    }
    const scope = options.wiki === undefined ? wiki : options.wiki;
    const scopedSessionKey = 'eql-ads-session-v1:' + encodeURIComponent( scope || w.location.host + ( options.scriptPath || '' ) );
    const cookieAccess = Object.getOwnPropertyDescriptor( w.Document.prototype, 'cookie' );
    if ( options.cookie ) { cookieAccess.set.call( w.document, options.cookie + '; Path=/' ); }
    const cookieWrites = [];
    Object.defineProperty( w.document, 'cookie', {
        get() { if ( options.cookieReadBlocked ) { throw new Error( 'Blocked' ); } return cookieAccess.get.call( w.document ); },
        set( value ) { cookieWrites.push( value ); if ( !options.cookieBlocked ) { cookieAccess.set.call( w.document, value ); } }
    } );
    const session = options.session instanceof Map ? options.session : new Map( Object.entries( options.session || {} ) );
    const local = new Map( Object.entries( options.local || {} ) );
    const storageCalls = [];
    let localAccesses = 0;
    const store = ( name, data, blocked ) => ( {
        getItem( key ) { storageCalls.push( { store: name, method: 'get', key } );
            if ( blocked === 'all' || blocked?.read ) { throw new Error( 'Blocked' ); } return data.has( key ) ? data.get( key ) : null; },
        setItem( key, value ) { storageCalls.push( { store: name, method: 'set', key, value } );
            if ( blocked === 'all' || blocked?.write ) { throw new Error( 'Blocked' ); } data.set( key, String( value ) ); },
        removeItem( key ) { storageCalls.push( { store: name, method: 'remove', key } );
            if ( blocked === 'all' || blocked?.remove ) { throw new Error( 'Blocked' ); } data.delete( key ); }
    } );
    const localStore = store( 'local', local, options.localBlocked );
    Object.defineProperty( w, 'localStorage', { get() { localAccesses++; return localStore; } } );
    Object.defineProperty( w, 'sessionStorage', { value: store( 'session', session, options.sessionBlocked ) } );
    let visibility = 'visible';
    Object.defineProperties( w.document, {
        visibilityState: { get: () => visibility }, hidden: { get: () => visibility !== 'visible' }
    } );
    const user = options.user || 0;
    const name = options.name === undefined ? ( user ? 'Fixture Reader' : null ) : options.name;
    let accountOption = options.option;
    let identity = { id: user, name };
    const reads = [], saves = [], notifications = [], cacheWrites = [];
    let activeSaves = 0, maximumSaves = 0;
    const userinfo = () => ( { query: { userinfo: { ...identity, options: accountOption === undefined ? {} : { [ OPTION ]: accountOption } } } } );
    function get( parameters ) {
        const pending = deferred();
        const record = { parameters, resolve: value => pending.resolve( value === undefined ? userinfo() : value ), reject: pending.reject };
        reads.push( record );
        if ( !options.deferReads ) {
            if ( options.readError ) { pending.reject( new Error( 'Synthetic read failure' ) ); }
            else { record.resolve( options.readResponse ); }
        }
        return pending.promise;
    }
    function saveOption( option, value, parameters ) {
        const pending = deferred();
        activeSaves++; maximumSaves = Math.max( maximumSaves, activeSaves );
        const record = { option, value, parameters, resolve: result => pending.resolve( result === undefined ? { options: 'success' } : result ),
            reject: pending.reject };
        saves.push( record );
        if ( !options.deferSaves ) {
            if ( options.saveError ) { record.reject( new Error( 'Synthetic save failure' ) ); }
            else { record.resolve( options.saveResponse ); }
        }
        return pending.promise.then( result => {
            activeSaves--;
            if ( result?.options === 'success' && !result.error && !result.warnings ) { accountOption = value; }
            return result;
        }, error => { activeSaves--; throw error; } );
    }
    const values = { wgWikiID: scope, wgScriptPath: options.scriptPath || '', wgUserId: user, wgUserName: name,
        wgUserIsTemp: options.temporary || false, wgIsMainPage: options.mainPage === true,
        wgEQLAdSense: options.config === undefined ? config : options.config };
    w.mw = {
        config: { get: key => values[ key ] }, Api: function () { return { get, saveOption }; },
        user: { options: { set: ( option, value ) => cacheWrites.push( { option, value } ) } },
        notify: ( message, settings ) => notifications.push( { message, settings } )
    };
    if ( options.apiMissing ) { delete w.mw.Api; }
    w.eval( source ); w.document.dispatchEvent( new w.Event( 'DOMContentLoaded' ) );
    const control = w.document.getElementById( 'eql-ads-toggle' );
    const toggle = control.querySelector( '[value="anchor"]' );
    return {
        w, toggle, control, reads, saves, notifications, cacheWrites, cookieWrites, session, local, storageCalls,
        choose( next ) { const input = control.querySelector( '[value="' + ( next === true ? 'anchor' : next === false ? 'off' : next ) + '"]' ); input.checked = true; input.dispatchEvent( new w.Event( 'change' ) ); },
        selected: () => control.querySelector( ':checked' ).value,
        scripts: () => [ ...w.document.querySelectorAll( 'script[src]' ) ],
        hidden: () => w.document.getElementById( 'eql-advertisement' ).hidden,
        previewOpen: () => w.document.body.classList.contains( 'eql-ad-preview-open' ),
        reloads: () => errors.filter( error => /navigation/i.test( error.message ) ).length,
        cookie: () => cookieAccess.get.call( w.document ),
        setCookie( value ) { cookieAccess.set.call( w.document, cookieName + '=' + value + '; Path=/' ); },
        setAccount( option, nextIdentity ) { accountOption = option; if ( nextIdentity ) { identity = nextIdentity; } },
        setConfigIdentity( id, nextName ) { values.wgUserId = id; values.wgUserName = nextName; },
        pageshow() { w.dispatchEvent( new w.PageTransitionEvent( 'pageshow', { persisted: true } ) ); },
        focus() { w.dispatchEvent( new w.Event( 'focus' ) ); },
        visible( next = true ) { visibility = next ? 'visible' : 'hidden'; w.document.dispatchEvent( new w.Event( 'visibilitychange' ) ); },
        maximumSaves: () => maximumSaves,
        async layout() {
            await settle();
            const pending = [ ...layoutFrames.values() ]; layoutFrames.clear();
            pending.forEach( callback => callback() ); await settle();
        },
        resizeAnchors() { layoutObservers.filter( observer => observer.targets.size ).forEach( observer => observer.callback() ); },
        clearance: () => w.document.documentElement.style.getPropertyValue( '--eql-ad-anchor-clearance' ),
        close() {
            try {
                assert.equal( localAccesses, 0, 'Ads must never access localStorage or its legacy preferences.' );
                assert.ok( storageCalls.every( call => call.store === 'session' && [ scopedSessionKey, 'eql-ads-scroll-v1:' + encodeURIComponent( scope || w.location.host + ( options.scriptPath || '' ) ) ].includes( call.key ) ), 'Only the new scoped session fallback is accessed.' );
                if ( user && !options.temporary ) {
                    assert.equal( cookieWrites.length, 0, 'Account state must not migrate into anonymous cookies.' );
                    assert.equal( storageCalls.filter( call => call.method !== 'get' && !call.key.startsWith( 'eql-ads-scroll-v1:' ) ).length, 0, 'Account state must not migrate into session storage.' );
                    for ( const read of reads ) {
                        assert.equal( read.parameters.action, 'query' ); assert.equal( read.parameters.meta, 'userinfo' );
                        assert.ok( read.parameters.uiprop.split( '|' ).includes( 'options' ) );
                        assert.equal( read.parameters.assert, 'user' ); assert.equal( read.parameters.assertuser, name );
                    }
                    for ( const save of saves ) {
                        assert.equal( save.option, OPTION ); assert.ok( [ 'side', 'anchor', 'off' ].includes( save.value ) );
                        assert.equal( save.parameters.assert, 'user' ); assert.equal( save.parameters.assertuser, name );
                    }
                } else { assert.equal( reads.length + saves.length, 0, 'Anonymous choices must not use account APIs.' ); }
            } finally { w.close(); }
        }
    };
}

function test( name, run ) { cases.push( { name, run } ); }
function noProvider( page ) {
    assert.equal( page.scripts().length, 0 ); assert.equal( page.w.document.querySelector( 'ins, iframe' ), null );
    assert.equal( page.w.adsbygoogle, undefined );
}
function assertOffNotice( page ) {
    assert.equal( page.control.title, offNotice );
    assert.ok( page.notifications.some( notice => notice.message === offNotice ) );
}

test( 'The skin renders an inert Ads control beside Donate and an accessible external preview dock', () => {
    const dom = new JSDOM( template );
    const group = dom.window.document.getElementById( 'eql-ads-toggle' );
    assert.equal( group.getAttribute( 'role' ), 'radiogroup' );
    assert.equal( group.previousElementSibling.id, 'eql-support-button' );
    const radios = [ ...group.querySelectorAll( 'input' ) ];
    assert.deepEqual( radios.map( input => input.value ), [ 'off', 'side', 'anchor' ] );
    assert.ok( radios.every( input => input.type === 'radio' && input.disabled && input.getAttribute( 'aria-label' ) ) );
    assert.equal( group.querySelector( ':checked' ).value, 'off' );
    const ad = dom.window.document.getElementById( 'eql-advertisement' );
    assert.equal( ad.hidden, true ); assert.equal( ad.nextElementSibling.className, 'mw-footer-container' );
    const close = dom.window.document.getElementById( 'eql-ad-preview-close' );
    assert.equal( close.type, 'button' ); assert.match( close.getAttribute( 'aria-label' ), /Turn ads off/ );
    const manifest = JSON.parse( fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/skin.json' ), 'utf8' ) );
    assert.ok( manifest.ResourceModules[ 'skins.EQLImmersive.base' ].scripts.includes( 'resources/ads.js' ) ); dom.window.close();
} );
test( 'Anonymous readers default ON while all legacy persistent preferences are ignored', async () => {
    for ( const legacy of [ undefined, 'off', 'on', 'corrupt' ] ) {
        const page = visit( { ...staging, local: legacy === undefined ? {} : { [ legacyKey ]: legacy },
            session: { [ legacyKey ]: 'off' }, cookie: 'eql_ads_off_' + encodeURIComponent( legacyKey ) + '=off' } );
        await settle(); assert.equal( page.toggle.disabled, false ); assert.equal( page.toggle.checked, true );
        assert.equal( page.previewOpen(), true ); noProvider( page ); page.close();
    }
} );
test( 'Anonymous OFF uses a verified host-only session cookie and the exact login notice', async () => {
    const page = visit( staging ); await settle(); page.choose( false ); await settle();
    assert.equal( page.toggle.checked, false ); assert.equal( page.hidden(), true ); assert.equal( page.previewOpen(), false );
    assert.equal( page.cookie(), cookieName + '=off' ); assertOffNotice( page );
    const write = page.cookieWrites.find( value => value.startsWith( cookieName + '=off;' ) );
    assert.ok( write && /;\s*Path=\//i.test( write ) && /;\s*SameSite=Lax/i.test( write ) && /;\s*Secure/i.test( write ) );
    assert.ok( page.cookieWrites.every( value => !/;\s*(Domain|Max-Age|Expires)=/i.test( value ) ), 'Preference cookies must remain host-only session cookies.' );
    const next = visit( { ...staging, cookie: page.cookie() } ); await settle();
    assert.equal( next.toggle.checked, false );
    assert.equal( next.control.title, offNotice, 'A later OFF visit describes the effective preference.' );
    assert.equal( next.notifications.length, 0, 'Reading an existing OFF preference must not toast on page load.' );
    noProvider( next ); next.close(); page.close();
} );
test( 'A later anonymous ON and navigation retain only the temporary session choice', async () => {
    const page = visit( { ...staging, cookie: cookieName + '=off' } ); await settle(); page.choose( true ); await settle();
    assert.equal( page.toggle.checked, true ); assert.equal( page.cookie(), cookieName + '=anchor' );
    const next = visit( { ...staging, cookie: page.cookie() } ); await settle(); assert.equal( next.previewOpen(), true );
    const fresh = visit( staging ); await settle(); assert.equal( fresh.previewOpen(), true ); next.close(); fresh.close(); page.close();
} );
test( 'Valid anonymous cookies take precedence over stale session fallbacks', async () => {
    for ( const [ cookie, fallback, expected ] of [ [ 'off', 'on', false ], [ 'on', 'off', true ] ] ) {
        const page = visit( { ...staging, cookie: cookieName + '=' + cookie, session: { [ sessionKey ]: fallback } } );
        await settle(); assert.equal( page.toggle.checked, expected ); assert.equal( page.previewOpen(), expected ); page.close();
    }
} );
test( 'Malformed and unreadable anonymous cookies fail OFF', async () => {
    for ( const options of [ ...[ '', 'ON', 'true', 'on%0A', 'garbage' ].map( value => ( { cookie: cookieName + '=' + value } ) ),
        { cookieReadBlocked: true, cookieBlocked: true } ] ) {
        const page = visit( { ...staging, ...options } ); await settle();
        assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false ); noProvider( page ); page.close();
    }
} );
test( 'Blocked anonymous cookies use only a verified scoped session fallback across tab navigation', async () => {
    const page = visit( { ...staging, cookieBlocked: true } ); await settle(); page.choose( false ); await settle();
    assert.equal( page.session.get( sessionKey ), 'off' ); assertOffNotice( page );
    const next = visit( { ...staging, cookieBlocked: true, session: page.session } ); await settle();
    assert.equal( next.toggle.checked, false ); next.choose( true ); await settle();
    assert.equal( next.session.get( sessionKey ), 'anchor' ); assert.equal( next.previewOpen(), true ); next.close(); page.close();
} );
test( 'An immutable readable cookie ON cannot be overridden by a fallback OFF and reload', async () => {
    const page = visit( { cookie: cookieName + '=on', cookieBlocked: true } ); await settle();
    assert.equal( page.scripts().length, 1 ); page.choose( false ); await settle();
    assert.equal( page.toggle.checked, false ); assert.equal( page.reloads(), 0 ); assertOffNotice( page );
    page.pageshow(); page.focus(); page.visible(); await settle(); assert.equal( page.toggle.checked, false ); assert.equal( page.reloads(), 0 ); page.close();
} );
test( 'Failed anonymous persistence latches OFF and retains the exact login notice plus a warning', async () => {
    const page = visit( { ...staging, cookieBlocked: true, sessionBlocked: 'all' } ); await settle();
    page.choose( false ); await settle(); assertOffNotice( page );
    assert.ok( page.notifications.some( notice => notice.settings.type === 'warn' ) );
    page.pageshow(); page.focus(); page.visible(); await settle();
    assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false ); assert.equal( page.reloads(), 0 ); page.close();
} );
test( 'Anonymous pageshow, focus, and visibility refresh shared cookies without automatic writes', async () => {
    for ( const refresh of [ 'pageshow', 'focus', 'visible' ] ) {
        const page = visit( staging ); await settle(); const count = page.cookieWrites.length;
        page.setCookie( 'off' ); page[ refresh ](); await settle();
        assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false );
        assert.equal( page.cookieWrites.length, count, 'Refreshes only read preferences.' ); page.close();
    }
} );
test( 'Anonymous cookie and session fallback names are scoped to the current wiki or host/path', async () => {
    for ( const options of [ { wiki: 'other wiki', expected: 'other%20wiki' }, { wiki: '', scriptPath: '/w', expected: 'test.eqlwiki.com%2Fw' } ] ) {
        const page = visit( { ...staging, ...options, cookie: cookieName + '=off', session: { [ sessionKey ]: 'off' } } );
        await settle(); assert.equal( page.toggle.checked, true ); page.choose( false ); await settle();
        assert.ok( page.cookie().includes( 'eql_ads_session_v1_' + options.expected + '=off' ) ); page.close();
    }
} );
test( 'Signed-in readers wait for asserted account options and default OFF without writes', async () => {
    const page = visit( { ...staging, user: 42, deferReads: true, cookie: cookieName + '=on', session: { [ sessionKey ]: 'on' } } );
    assert.equal( page.toggle.disabled, true ); assert.equal( page.toggle.checked, false ); noProvider( page );
    await settle(); assert.ok( page.reads.length >= 1 ); page.reads[ 0 ].resolve(); await settle();
    assert.equal( page.toggle.disabled, false ); assert.equal( page.toggle.checked, false );
    assert.equal( page.previewOpen(), false ); assert.equal( page.saves.length, 0 ); page.close();
} );
test( 'Verified account ON ignores anonymous OFF and never migrates either preference', async () => {
    const page = visit( { ...staging, user: 42, option: 'on', cookie: cookieName + '=off', session: { [ sessionKey ]: 'off' } } );
    await settle(); assert.equal( page.toggle.checked, true ); assert.equal( page.previewOpen(), true );
    assert.equal( page.cookie(), cookieName + '=off' ); assert.equal( page.saves.length, 0 ); page.close();
    const anonymous = visit( staging ); await settle(); assert.equal( anonymous.toggle.checked, true ); anonymous.close();
} );
test( 'Missing and malformed account options remain OFF without writing defaults remotely', async () => {
    for ( const value of [ undefined, '', 'off', 'ON', 'true', 'on\n', 1, { enabled: true } ] ) {
        const page = visit( { ...staging, user: 42, option: value } ); await settle();
        assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false ); assert.equal( page.saves.length, 0 ); page.close();
    }
} );
test( 'Account reads reject switched IDs/names, anonymous sessions, errors, and missing identity', async () => {
    for ( const response of [
        { query: { userinfo: { id: 77, name: 'Fixture Reader', options: { [ OPTION ]: 'on' } } } },
        { query: { userinfo: { id: 42, name: 'Other Reader', options: { [ OPTION ]: 'on' } } } },
        { query: { userinfo: { id: 0, name: 'Fixture Reader', options: { [ OPTION ]: 'on' } } } },
        { query: { userinfo: { options: { [ OPTION ]: 'on' } } } }, { error: { code: 'assertuserfailed' } }, {}
    ] ) {
        const page = visit( { ...staging, user: 42, readResponse: response } ); await settle();
        assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false ); assert.equal( page.saves.length, 0 ); noProvider( page ); page.close();
    }
    for ( const options of [ { readError: true }, { apiMissing: true } ] ) {
        const page = visit( { ...staging, user: 42, ...options } ); await settle();
        assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false ); assert.equal( page.saves.length, 0 ); page.close();
    }
} );
test( 'Account ON saves only on a gesture and activates after a successful asserted response', async () => {
    const page = visit( { ...staging, user: 42, deferSaves: true } ); await settle();
    assert.equal( page.saves.length, 0 ); page.choose( true ); assert.equal( page.previewOpen(), false ); await settle();
    assert.equal( page.saves.length, 1 ); assert.equal( page.saves[ 0 ].value, 'anchor' );
    page.saves[ 0 ].resolve(); await settle(); assert.equal( page.toggle.checked, true ); assert.equal( page.previewOpen(), true ); page.close();
} );
test( 'A newer OFF gesture prevents a stale ON save from activating and serializes the final save', async () => {
    const page = visit( { ...staging, user: 42, deferSaves: true } ); await settle(); page.choose( true ); await settle(); page.choose( false );
    assert.equal( page.previewOpen(), false ); assert.equal( page.saves.length, 1 );
    page.saves[ 0 ].resolve(); await settle(); assert.equal( page.previewOpen(), false );
    assert.equal( page.saves.length, 2 ); assert.equal( page.saves[ 1 ].value, 'off' );
    assert.equal( page.cacheWrites.some( write => write.value === 'anchor' ), false, 'Stale ON must not enter the API option cache.' );
    page.saves[ 1 ].resolve(); await settle(); assert.equal( page.toggle.checked, false );
    assert.equal( page.previewOpen(), false ); assert.equal( page.maximumSaves(), 1 ); page.close();
} );
test( 'Queued gestures coalesce to the latest intent instead of replaying obsolete OFF choices', async () => {
    const page = visit( { ...staging, user: 42, deferSaves: true } ); await settle();
    page.choose( true ); await settle(); page.choose( false ); page.choose( true );
    assert.equal( page.saves.length, 1 ); page.saves[ 0 ].resolve(); await settle();
    if ( page.saves.length === 2 ) { assert.equal( page.saves[ 1 ].value, 'anchor' ); page.saves[ 1 ].resolve(); await settle(); }
    assert.ok( page.saves.length <= 2 ); assert.equal( page.maximumSaves(), 1 );
    assert.equal( page.toggle.checked, true ); assert.equal( page.previewOpen(), true ); page.close();
} );
test( 'Failed, warning-bearing, and malformed account saves fail OFF without automatic retry writes', async () => {
    for ( const response of [ {}, { options: 'failed' }, { options: 'success', error: { code: 'assertuserfailed' } },
        { options: 'success', warnings: { options: 'Synthetic warning' } } ] ) {
        const page = visit( { ...staging, user: 42, saveResponse: response } ); await settle(); page.choose( true ); await settle();
        assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false ); assert.equal( page.saves.length, 1 ); page.close();
    }
    const page = visit( { ...staging, user: 42, saveError: true } ); await settle(); page.choose( true ); await settle();
    assert.equal( page.toggle.checked, false ); assert.equal( page.saves.length, 1 ); noProvider( page ); page.close();
} );
test( 'Account OFF hides immediately and reloads a provider only after verified persistence', async () => {
    const page = visit( { user: 42, option: 'on', deferSaves: true } ); await settle(); assert.equal( page.scripts().length, 1 );
    page.choose( false ); assert.equal( page.toggle.checked, false ); assert.equal( page.reloads(), 0 ); await settle();
    assert.equal( page.saves[ 0 ].value, 'off' ); page.saves[ 0 ].resolve(); await settle();
    assert.equal( page.reloads(), 1 ); assert.equal( page.w.adsbygoogle, undefined ); page.close();
} );
test( 'Failed account OFF stays latched through refreshes and never reloads into stale ON', async () => {
    const page = visit( { user: 42, option: 'on', saveError: true } ); await settle(); page.choose( false ); await settle();
    page.pageshow(); page.focus(); page.visible(); await settle();
    assert.equal( page.toggle.checked, false ); assert.equal( page.reloads(), 0 ); assert.equal( page.saves.length, 1 ); page.close();
} );
test( 'An uncertain failed ON cannot adopt server ON through background refresh', async () => {
    const page = visit( { ...staging, user: 42, saveError: true } ); await settle(); page.choose( true ); await settle();
    page.setAccount( 'on' ); page.pageshow(); page.focus(); page.visible(); await settle();
    assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false ); assert.equal( page.saves.length, 1 );
    assert.equal( page.cacheWrites.some( write => write.value === 'anchor' ), false ); page.close();
} );
test( 'Account lifecycle refreshes reassert identity and reread options without writes', async () => {
    for ( const refresh of [ 'pageshow', 'focus', 'visible' ] ) {
        const page = visit( { ...staging, user: 42, option: 'on' } ); await settle(); const reads = page.reads.length;
        page.setAccount( 'off' ); page[ refresh ](); await settle();
        assert.ok( page.reads.length > reads ); assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false );
        assert.equal( page.saves.length, 0 ); page.close();
    }
} );
test( 'An account change discovered on refresh fails OFF without migrating the incoming option', async () => {
    const page = visit( { ...staging, user: 42, option: 'on' } ); await settle();
    page.setAccount( 'on', { id: 77, name: 'Other Reader' } ); page.focus(); await settle();
    assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false ); assert.equal( page.saves.length, 0 ); page.close();
} );
test( 'Observed anonymous/account config switches latch OFF for the document without migrating writes', async () => {
    for ( const initial of [ { user: 0, name: null }, { user: 42, name: 'Fixture Reader', option: 'on' } ] ) {
        const page = visit( { ...staging, ...initial } ); await settle(); assert.equal( page.previewOpen(), true );
        const reads = page.reads.length;
        page.setConfigIdentity( 77, 'Other Reader' ); page.choose( false ); page.focus(); await settle();
        assert.equal( page.previewOpen(), false ); assert.equal( page.toggle.checked, false );
        assert.equal( page.cookieWrites.length, 0 ); assert.equal( page.saves.length, 0 ); assert.equal( page.reads.length, reads );
        assert.equal( page.storageCalls.filter( call => call.method !== 'get' && !call.key.startsWith( 'eql-ads-scroll-v1:' ) ).length, 0 );
        page.setConfigIdentity( initial.user, initial.name ); page.choose( true ); page.pageshow(); page.visible(); await settle();
        assert.equal( page.previewOpen(), false ); assert.equal( page.toggle.checked, false );
        assert.equal( page.cookieWrites.length, 0 ); assert.equal( page.saves.length, 0 ); assert.equal( page.reads.length, reads ); page.close();
    }
} );
test( 'A stale account refresh cannot re-enable after a newer OFF gesture', async () => {
    const page = visit( { ...staging, user: 42, option: 'on', deferReads: true, deferSaves: true } );
    await settle(); page.reads[ 0 ].resolve(); await settle(); assert.equal( page.previewOpen(), true );
    page.focus(); await settle(); const pending = page.reads[ page.reads.length - 1 ]; page.choose( false );
    pending.resolve( { query: { userinfo: { id: 42, name: 'Fixture Reader', options: { [ OPTION ]: 'on' } } } } ); await settle();
    assert.equal( page.toggle.checked, false ); assert.equal( page.previewOpen(), false );
    page.saves[ 0 ].resolve(); await settle(); assert.equal( page.previewOpen(), false ); page.close();
} );
test( 'Supported production anchors request one script without a manual creative or push', async () => {
    const page = visit(); await settle(); assert.equal( page.scripts().length, 1 );
    const script = page.scripts()[ 0 ];
    assert.equal( script.src, 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + config.client );
    assert.equal( script.async, true ); assert.equal( script.crossOrigin, 'anonymous' ); assert.equal( script.dataset.overlays, 'collapsed-bottom' );
    script.dispatchEvent( new page.w.Event( 'load' ) ); page.pageshow(); page.focus(); page.visible(); page.w.eval( source ); await settle();
    assert.equal( page.scripts().length, 1 ); assert.equal( page.w.adsbygoogle, undefined ); assert.equal( page.w.document.querySelector( 'ins, iframe' ), null );
    assert.equal( page.hidden(), true ); assert.equal( page.previewOpen(), false ); assert.equal( page.reloads(), 0 ); page.close();
} );
test( 'Anonymous provider OFF saves its session cookie before reloading', async () => {
    const page = visit(); await settle(); page.choose( false ); await settle();
    assert.equal( page.cookie(), cookieName + '=off' ); assert.equal( page.reloads(), 1 ); assertOffNotice( page );
    const next = visit( { cookie: page.cookie() } ); await settle(); noProvider( next ); next.close(); page.close();
} );
test( 'Absent and invalid publisher formats never request Google or leave a dock gap', async () => {
    for ( const invalid of [ null, {}, { ...config, client: 'ca-pub-123' }, { ...config, client: config.client + '\n' },
        { ...config, format: 1234 }, { ...config, format: 'display' }, { ...config, format: 'anchor\n' }, { client: config.client, slot: '1234567890' } ] ) {
        const page = visit( { config: invalid } ); await settle(); assert.equal( page.toggle.checked, true );
        noProvider( page ); assert.equal( page.hidden(), true ); assert.equal( page.previewOpen(), false ); page.close();
    }
} );
test( 'Copied production IDs cannot load on staging or override preview isolation', async () => {
    for ( const options of [ { url: 'https://test.eqlwiki.com/Article', config }, { url: 'http://127.0.0.1:8080/Article', config },
        { url: 'https://other.example/Article', config }, { url: 'https://eqlwiki.com/Article', config: { ...config, preview: true } },
        { ...staging, config: { ...config, preview: true } } ] ) {
        const page = visit( options ); await settle(); noProvider( page ); page.close();
    }
} );
test( 'Explicit staging anchors use the native loader without a local preview or reserved footer space', async () => {
    const page = visit( { ...staging, config: stagingConfig } ); await settle();
    assert.equal( page.scripts().length, 1 );
    const script = page.scripts()[ 0 ];
    assert.equal( script.src, 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + stagingConfig.client );
    assert.equal( script.async, true ); assert.equal( script.crossOrigin, 'anonymous' );
    assert.equal( script.dataset.overlays, 'collapsed-bottom' );
    assert.equal( page.hidden(), true ); assert.equal( page.previewOpen(), false );
    assert.equal( page.w.document.querySelector( '.eql-ad-preview, ins, iframe' ), null );
    page.pageshow(); page.focus(); page.visible(); page.w.eval( source ); await settle();
    assert.equal( page.scripts().length, 1 );
    page.choose( false ); await settle(); assert.equal( page.reloads(), 1 ); assertOffNotice( page ); page.close();
} );
test( 'Staging provider respects anonymous session OFF and verified account OFF before any request', async () => {
    const anonymous = visit( { ...staging, config: stagingConfig, cookie: cookieName + '=off' } ); await settle();
    noProvider( anonymous ); assert.equal( anonymous.toggle.checked, false ); anonymous.close();
    const account = visit( { ...staging, config: stagingConfig, user: 42, option: 'off', deferSaves: true } ); await settle();
    noProvider( account ); assert.equal( account.toggle.checked, false );
    account.choose( true ); await settle(); noProvider( account );
    account.saves[ 0 ].resolve(); await settle(); assert.equal( account.scripts().length, 1 );
    assert.equal( account.previewOpen(), false ); account.close();
} );
test( 'Provider configuration requires its exact environment and matching browser origin', async () => {
    const invalidConfigurations = [ { ...config, environment: undefined }, { ...config, environment: 'test' },
        { ...config, environment: true }, { ...stagingConfig, environment: 'staging\n' } ];
    for ( const invalid of invalidConfigurations ) {
        const page = visit( { config: invalid } ); await settle(); noProvider( page ); page.close();
    }
    for ( const url of [ 'https://eqlwiki.com/Article', 'http://test.eqlwiki.com/Article',
        'https://www.test.eqlwiki.com/Article', 'https://test.eqlwiki.com.evil.example/Article',
        'http://127.0.0.1:8080/Article' ] ) {
        const page = visit( { url, config: stagingConfig } ); await settle();
        noProvider( page ); assert.equal( page.previewOpen(), false ); page.close();
    }
} );
test( 'Staging dock leaves article DOM intact and closes to temporary OFF with keyboard focus', async () => {
    const page = visit( { ...staging, config: { ...config, preview: true } } ); await settle();
    const doc = page.w.document, ad = doc.getElementById( 'eql-advertisement' );
    assert.equal( ad.nextElementSibling.className, 'mw-footer-container' ); assert.equal( page.previewOpen(), true );
    assert.equal( ad.parentNode, doc.querySelector( '.mw-footer-container' ).parentNode ); assert.match( ad.textContent, /Staging preview.*Real ads are off/s );
    assert.equal( ad.querySelector( 'select' ), null ); assert.equal( doc.querySelectorAll( '.mw-parser-output' ).length, 1 );
    assert.equal( doc.getElementById( 'mw-content-text' ).parentNode.id, 'bodyContent' ); assert.equal( doc.querySelector( 'main > h1' ).textContent, 'Synthetic article' );
    const close = doc.getElementById( 'eql-ad-preview-close' ); close.focus(); close.click(); await settle();
    assert.equal( page.hidden(), true ); assert.equal( page.previewOpen(), false ); assert.equal( page.toggle.checked, false );
    assert.equal( page.cookie(), cookieName + '=off' ); assert.equal( doc.activeElement.value, 'off' ); assertOffNotice( page );
    page.choose( true ); page.pageshow(); page.w.eval( source ); await settle();
    assert.equal( doc.querySelectorAll( '.eql-ad-preview' ).length, 1 ); noProvider( page ); assert.equal( page.reloads(), 0 ); page.close();
} );
test( 'Account preview close saves account OFF without an anonymous login notice', async () => {
    const page = visit( { ...staging, user: 42, option: 'on', deferSaves: true } ); await settle();
    page.w.document.getElementById( 'eql-ad-preview-close' ).click(); assert.equal( page.previewOpen(), false ); await settle();
    assert.equal( page.saves[ 0 ].value, 'off' ); assert.equal( page.notifications.some( notice => notice.message === offNotice ), false );
    page.saves[ 0 ].resolve(); await settle(); assert.equal( page.previewOpen(), false ); page.close();
} );
test( 'Preview requires its flag, exact staging origin, article anchor, and unclipped footer sibling', async () => {
    for ( const options of [ { url: 'https://other.example/Article', config: { preview: true } },
        { url: 'http://test.eqlwiki.com/Article', config: { preview: true } },
        { url: 'https://test.eqlwiki.com.evil.example/Article', config: { preview: true } },
        { ...staging, config: { preview: 'true' } }, { ...staging, articleAnchor: false }, { ...staging, footer: false } ] ) {
        const page = visit( options ); await settle(); assert.equal( page.hidden(), true ); assert.equal( page.previewOpen(), false );
        assert.equal( page.w.document.querySelector( '.eql-ad-preview' ), null ); noProvider( page ); page.close();
    }
} );
test( 'Preview CSS matches footer breakpoint and reserves its entire dock only while open', async () => {
    const styles = [ 'layout-reset.css', 'contribute.css' ].map( name => fs.readFileSync(
        path.join( __dirname, '../skins/EQLImmersive/resources', name ), 'utf8' ) );
    const dom = new JSDOM( '<style>' + styles.join( '\n' ) + '</style>' );
    function declarations( width, selector ) {
        const result = {};
        function apply( rules ) {
            for ( const rule of rules ) {
                if ( rule.type === dom.window.CSSRule.MEDIA_RULE ) {
                    const limit = rule.media.mediaText.match( /max-width:\s*([0-9.]+)px/ );
                    if ( limit && width <= Number( limit[ 1 ] ) ) { apply( rule.cssRules ); }
                } else if ( rule.selectorText?.split( ',' ).map( value => value.trim() ).includes( selector ) ) {
                    for ( let i = 0; i < rule.style.length; i++ ) {
                        const name = rule.style[ i ]; result[ name ] = rule.style.getPropertyValue( name );
                    }
                }
            }
        }
        apply( dom.window.document.styleSheets[ 0 ].cssRules ); return result;
    }
    for ( const width of [ 320, 375, 900, 901, 1280 ] ) {
        const body = declarations( width, 'body.skin-eqlimmersive' ), footer = declarations( width, 'body.skin-eqlimmersive .mw-footer-container' );
        assert.equal( body[ '--eql-fixed-footer-height' ], footer.height );
        assert.equal( body[ '--eql-ad-preview-creative-height' ], width <= 900 ? '50px' : '90px' );
    }
    const dock = declarations( 375, 'body.skin-eqlimmersive.eql-ad-preview-open .eql-advertisement' );
    assert.equal( dock.position, 'fixed' ); assert.equal( dock.bottom, 'var(--eql-fixed-footer-height)' );
    assert.equal( dock.height, 'var(--eql-ad-preview-dock-height)' );
    const reserveSelector = 'body.skin-eqlimmersive.eql-ad-preview-open .mw-page-container-inner';
    assert.equal( declarations( 375, reserveSelector )[ 'padding-bottom' ], 'calc(3.25rem + var(--eql-ad-preview-dock-height))' );
    const page = visit( staging ); await settle();
    const inner = page.w.document.querySelector( '.mw-page-container-inner' );
    assert.equal( inner.matches( reserveSelector ), true );
    page.choose( false ); await settle();
    // jsdom 27 can cache querySelector results after an ancestor class changes;
    // matches checks the element against its current ancestor classes.
    assert.equal( inner.matches( reserveSelector ), false ); page.close(); dom.window.close();
} );
test( 'A blocked provider leaves the custom preview dock hidden', async () => {
    const page = visit(); await settle(); page.scripts()[ 0 ].dispatchEvent( new page.w.Event( 'error' ) );
    assert.equal( page.hidden(), true ); assert.equal( page.previewOpen(), false ); assert.equal( page.w.document.querySelector( 'ins' ), null ); page.close();
} );

function anchor( page, geometry = {} ) {
    const el = page.w.document.createElement( 'ins' );
    el.className = 'adsbygoogle adsbygoogle-noablate';
    el.dataset.anchorStatus = 'displayed';
    el.style.cssText = 'position:fixed;bottom:0;display:block;visibility:visible;opacity:1';
    el.innerHTML = '<div class="grippy-host"></div><iframe title="Advertisement"></iframe>';
    const box = { top: 591, bottom: 720, left: 130, right: 1135, width: 1005, height: 129, ...geometry };
    el.getBoundingClientRect = () => ( { ...box } );
    // Google's observed anchor is a child of <html>, outside <body>.
    page.w.document.documentElement.appendChild( el );
    return { el, box };
}
test( 'A late native bottom anchor puts the footer flush above the ad body without reserving its collapse tab', async () => {
    const page = visit( { anchorLayout: true, url: 'https://test.eqlwiki.com/Article', config: stagingConfig } );
    await page.layout(); assert.equal( page.clearance(), '' );
    const ad = anchor( page ); const before = ad.el.outerHTML;
    await page.layout(); assert.equal( page.clearance(), '129px' );
    assert.equal( ad.el.outerHTML, before ); assert.equal( page.previewOpen(), false );
    assert.equal( page.scripts().length, 1 ); page.close();
} );
test( 'Native anchor resizing, collapsing, hiding and removal restore only the required footer clearance', async () => {
    const page = visit( { anchorLayout: true } ); const ad = anchor( page ); await page.layout();
    ad.box.top = 520; ad.box.height = 200; page.resizeAnchors(); await page.layout();
    assert.equal( page.clearance(), '200px' );
    ad.box.top = 720; ad.box.bottom = 920; ad.el.dataset.anchorStatus = 'dismissed'; await page.layout();
    assert.equal( page.clearance(), '', 'The collapse/reopen tab may overlay the normal footer.' );
    ad.el.style.display = 'none'; await page.layout(); assert.equal( page.clearance(), '' );
    ad.el.style.display = 'block'; ad.box.top = 520; ad.box.bottom = 720; await page.layout(); assert.equal( page.clearance(), '200px' );
    ad.el.remove(); await page.layout(); assert.equal( page.clearance(), '' ); page.close();
} );
test( 'Viewport resizing and BFCache restoration remeasure the anchor without duplicating the loader', async () => {
    const page = visit( { anchorLayout: true } ); const ad = anchor( page ); await page.layout();
    page.w.innerHeight = 600; Object.assign( ad.box, { top: 500, bottom: 600, height: 100 } );
    page.w.dispatchEvent( new page.w.Event( 'resize' ) ); await page.layout(); assert.equal( page.clearance(), '100px' );
    page.w.dispatchEvent( new page.w.PageTransitionEvent( 'pagehide', { persisted: true } ) );
    ad.el.remove(); await page.layout(); assert.equal( page.clearance(), '100px', 'Inactive documents do no geometry work.' );
    page.pageshow(); await page.layout(); assert.equal( page.clearance(), '' );
    assert.equal( page.scripts().length, 1 ); page.close();
} );
test( 'Non-anchor, top, invisible and out-of-viewport ads do not move the footer', async () => {
    const page = visit( { anchorLayout: true } ); const ad = anchor( page );
    ad.el.removeAttribute( 'data-anchor-status' ); await page.layout(); assert.equal( page.clearance(), '' );
    ad.el.dataset.anchorStatus = 'displayed'; ad.box.top = 0; ad.box.bottom = 129;
    await page.layout(); assert.equal( page.clearance(), '' );
    ad.box.top = 591; ad.box.bottom = 720; ad.el.style.bottom = 'auto'; await page.layout(); assert.equal( page.clearance(), '' );
    ad.el.style.bottom = '0px'; ad.el.style.opacity = '0'; await page.layout(); assert.equal( page.clearance(), '' );
    ad.el.style.opacity = '1'; ad.box.top = 800; ad.box.bottom = 929; await page.layout(); assert.equal( page.clearance(), '' );
    ad.box.top = 591; ad.box.bottom = 720; ad.box.width = 0; page.resizeAnchors(); await page.layout(); assert.equal( page.clearance(), '' );
    page.close();
} );
test( 'Layout tracking starts only with a permitted provider and keeps controls clear if an OFF save fails', async () => {
    for ( const options of [ { config: null }, staging, { user: 42, option: 'off' } ] ) {
        const page = visit( { anchorLayout: true, ...options } ); anchor( page ); await page.layout();
        assert.equal( page.clearance(), '' ); page.close();
    }
    const page = visit( { anchorLayout: true, user: 42, option: 'on', saveError: true } );
    await page.layout(); anchor( page ); await page.layout(); assert.equal( page.clearance(), '129px' );
    page.choose( false ); await page.layout(); assert.equal( page.clearance(), '129px' );
    assert.equal( page.reloads(), 0 ); assert.match( page.notifications[ 0 ].message, /Close this tab/ ); page.close();
} );

const layoutsConfig = { ...stagingConfig, format: 'layouts', sidebarSlot: '1234567890' };
const layoutsVisit = { ...staging, config: layoutsConfig };
test( 'Fresh defaults select Side only with sufficient landscape desktop space', async () => {
    for ( const [ viewport, expected ] of [
        [ { width: 1920, height: 1080 }, 'side' ], [ { width: 1080, height: 1920 }, 'anchor' ],
        [ { width: 1920, height: 1080, contentWidth: 1150 }, 'anchor' ],
        [ { width: 1920, height: 1080, touch: 1 }, 'anchor' ], [ { width: 1920, height: 1080, fine: false }, 'anchor' ],
        [ { width: 1600, height: 500 }, 'anchor' ]
    ] ) {
        const page = visit( { ...layoutsVisit, viewport } ); await settle();
        assert.equal( page.selected(), expected ); assert.equal( page.cookieWrites.length, 0 ); page.close();
    }
} );
test( 'Existing ON remains Bottom on a wide screen; explicit side and anchor preferences survive navigation', async () => {
    for ( const [ saved, expected ] of [ [ 'on', 'anchor' ], [ 'side', 'side' ], [ 'anchor', 'anchor' ], [ 'off', 'off' ] ] ) {
        for ( const account of [ false, true ] ) {
            const page = visit( { ...layoutsVisit, viewport: { width: 1920, height: 1080 },
                ...( account ? { user: 42, option: saved } : { cookie: cookieName + '=' + saved } ) } );
            await settle(); assert.equal( page.selected(), expected ); assert.equal( page.saves.length + page.cookieWrites.length, 0 ); page.close();
        }
    }
} );
test( 'Side requests one issued display unit and no anchor override; resizing never requests another ad', async () => {
    const page = visit( { ...layoutsVisit, viewport: { width: 1920, height: 1080 }, cookie: cookieName + '=side' } ); await settle();
    const ad = page.w.document.querySelector( 'ins.adsbygoogle' );
    assert.equal( ad.dataset.adSlot, layoutsConfig.sidebarSlot ); assert.equal( ad.dataset.adClient, layoutsConfig.client );
    assert.equal( ad.style.width, '300px' ); assert.equal( ad.style.height, '250px' );
    assert.equal( page.scripts()[ 0 ].hasAttribute( 'data-overlays' ), false ); assert.equal( page.w.adsbygoogle.length, 1 );
    const host = page.w.document.querySelector( '.mw-content-container' ); assert.equal( host.classList.contains( 'eql-ad-side-wide' ), true );
    const before = ad.outerHTML;
    page.w.innerWidth = 375; page.w.innerHeight = 812; page.w.dispatchEvent( new page.w.Event( 'resize' ) );
    assert.equal( host.classList.contains( 'eql-ad-side-wide' ), false ); assert.equal( page.selected(), 'side' );
    page.pageshow(); page.focus(); page.visible(); await settle();
    assert.equal( page.w.document.querySelectorAll( 'ins.adsbygoogle' ).length, 1 ); assert.equal( ad.outerHTML, before );
    assert.equal( page.scripts().length, 1 ); assert.equal( page.w.adsbygoogle.length, 1 ); assert.equal( page.reloads(), 0 ); page.close();
} );
test( 'Bottom requests only the native anchor even when a valid sidebar slot is configured', async () => {
    const page = visit( { ...layoutsVisit, cookie: cookieName + '=anchor' } ); await settle();
    assert.equal( page.scripts()[ 0 ].dataset.overlays, 'collapsed-bottom' );
    assert.equal( page.w.document.querySelector( 'ins' ), null ); assert.equal( page.w.adsbygoogle, undefined ); page.close();
} );
test( 'Mode changes persist before one reload and never create two live styles in a document', async () => {
    for ( const [ first, second ] of [ [ 'side', 'anchor' ], [ 'anchor', 'side' ], [ 'side', 'off' ] ] ) {
        const page = visit( { ...layoutsVisit, cookie: cookieName + '=' + first } ); await settle();
        page.choose( second ); await settle(); assert.equal( page.cookie(), cookieName + '=' + second ); assert.equal( page.reloads(), 1 );
        assert.equal( page.scripts().length, 1 ); assert.equal( page.w.document.querySelectorAll( 'ins' ).length, first === 'side' ? 1 : 0 );
        const restoration = JSON.parse( page.session.get( 'eql-ads-scroll-v1:' + wiki ) ); assert.equal( restoration.page, '/Article' );
        const next = visit( { ...layoutsVisit, cookie: page.cookie() } ); await settle(); assert.equal( next.selected(), second );
        if ( second === 'off' ) { noProvider( next ); }
        next.close(); page.close();
    }
} );
test( 'Account mode changes serialize and a stale Side response cannot replace the latest Bottom choice', async () => {
    const page = visit( { ...layoutsVisit, user: 42, deferSaves: true } ); await settle();
    page.choose( 'side' ); await settle(); page.choose( 'anchor' );
    assert.equal( page.saves[ 0 ].value, 'side' ); noProvider( page );
    page.saves[ 0 ].resolve(); await settle(); noProvider( page );
    assert.equal( page.saves[ 1 ].value, 'anchor' ); page.saves[ 1 ].resolve(); await settle();
    assert.equal( page.selected(), 'anchor' ); assert.equal( page.scripts().length, 1 );
    assert.equal( page.scripts()[ 0 ].dataset.overlays, 'collapsed-bottom' ); assert.equal( page.maximumSaves(), 1 ); page.close();
} );
test( 'Failed Side opt-out leaves the real unit intact, warns, and does not reload into stale account state', async () => {
    const page = visit( { ...layoutsVisit, user: 42, option: 'side', saveError: true } ); await settle();
    const unit = page.w.document.querySelector( 'ins' ), before = unit.outerHTML;
    page.choose( 'off' ); await settle(); page.focus(); await settle();
    assert.equal( page.selected(), 'off' ); assert.equal( page.reloads(), 0 );
    assert.equal( page.w.document.getElementById( 'eql-ad-sidebar' ).hidden, false ); assert.equal( unit.outerHTML, before );
    assert.ok( page.notifications.some( x => /Close this tab/.test( x.message ) ) ); page.close();
} );
test( 'Layouts require an exact staging origin and a valid issued slot', async () => {
    for ( const invalid of [ { ...layoutsConfig, sidebarSlot: '' }, { ...layoutsConfig, sidebarSlot: 1234567890 },
        { ...layoutsConfig, sidebarSlot: '1234567890\n' }, { ...layoutsConfig, sidebarSlot: 'not-a-slot' },
        { ...layoutsConfig, environment: 'production' } ] ) {
        const page = visit( { ...layoutsVisit, config: invalid, cookie: cookieName + '=side' } ); await settle(); noProvider( page ); page.close();
    }
    const copied = visit( { config: layoutsConfig, cookie: cookieName + '=side' } ); await settle(); noProvider( copied ); copied.close();
} );
test( 'Both synthetic styles switch without Google requests and retain the article DOM', async () => {
    const page = visit( { ...staging, viewport: { width: 1920, height: 1080 } } ); await settle();
    assert.equal( page.selected(), 'side' ); assert.equal( page.w.document.getElementById( 'eql-ad-sidebar' ).hidden, false );
    const article = page.w.document.querySelector( 'main' ).outerHTML;
    page.choose( 'anchor' ); await settle(); assert.equal( page.previewOpen(), true ); assert.equal( page.w.document.getElementById( 'eql-ad-sidebar' ).hidden, true );
    page.choose( 'off' ); await settle(); assert.equal( page.previewOpen(), false ); assert.equal( page.w.document.querySelector( 'main' ).outerHTML, article );
    noProvider( page ); page.close();
} );

const manualConfig = { ...layoutsConfig, format: 'manual-layouts', bannerSlot: '0987654321' };
const manualVisit = { ...staging, config: manualConfig };
test( 'Manual banner uses its issued slot at 90px high, in flow before article or after Main Page', async () => {
    for ( const mainPage of [ false, true ] ) {
        const page = visit( { ...manualVisit, mainPage, cookie: cookieName + '=anchor' } ); await settle();
        const d = page.w.document, banner = d.getElementById( 'eql-ad-banner' ), ad = banner.querySelector( 'ins' );
        assert.equal( banner.hidden, false ); assert.equal( ad.dataset.adSlot, manualConfig.bannerSlot );
        assert.equal( ad.style.height, '90px' ); assert.equal( ad.style.width, '100%' ); assert.equal( ad.style.maxWidth, '970px' );
        assert.equal( ad.hasAttribute( 'data-ad-format' ), false ); assert.equal( ad.hasAttribute( 'data-full-width-responsive' ), false );
        assert.equal( page.scripts()[ 0 ].hasAttribute( 'data-overlays' ), false );
        assert.equal( d.getElementById( 'eql-ad-anchor-backdrop' ), null ); assert.equal( page.previewOpen(), false );
        assert.equal( page.w.adsbygoogle.length, 1 ); assert.equal( d.querySelectorAll( 'ins' ).length, 1 );
        if ( mainPage ) { assert.equal( d.querySelector( 'main' ).nextElementSibling, banner ); }
        else { assert.equal( banner.nextElementSibling.id, 'bodyContent' ); assert.equal( banner.parentElement.id, 'content' ); }
        assert.ok( d.querySelector( 'main#content > #bodyContent > #mw-content-text.mw-body-content' ) );
        assert.equal( page.toggle.getAttribute( 'aria-label' ), 'Style 2: Banner, in-page' );
        page.focus(); page.pageshow(); page.visible(); page.w.dispatchEvent( new page.w.Event( 'resize' ) ); await settle();
        assert.equal( page.w.adsbygoogle.length, 1 ); assert.equal( d.querySelectorAll( 'ins' ).length, 1 ); page.close();
    }
} );
test( 'Manual styles switch only after saving and request one distinct unit per document', async () => {
    for ( const account of [ false, true ] ) {
        for ( const [ first, second ] of [ [ 'side', 'anchor' ], [ 'anchor', 'side' ], [ 'anchor', 'off' ] ] ) {
            const pref = mode => account ? { user: 42, option: mode } : { cookie: cookieName + '=' + mode };
            const page = visit( { ...manualVisit, ...pref( first ) } ); await settle();
            const ad = page.w.document.querySelector( 'ins' );
            assert.equal( ad.dataset.adSlot, first === 'side' ? manualConfig.sidebarSlot : manualConfig.bannerSlot );
            page.choose( second ); await settle(); assert.equal( page.reloads(), 1 );
            assert.equal( page.w.document.querySelectorAll( 'ins' ).length, 1 ); assert.equal( page.w.adsbygoogle.length, 1 );
            const next = visit( { ...manualVisit, ...pref( second ) } ); await settle();
            assert.equal( next.selected(), second ); if ( second === 'off' ) { noProvider( next ); }
            page.close(); next.close();
        }
    }
} );
test( 'Failed banner opt-out leaves the requested creative intact and reports the unsaved state', async () => {
    const page = visit( { ...manualVisit, user: 42, option: 'anchor', saveError: true } ); await settle();
    const banner = page.w.document.getElementById( 'eql-ad-banner' ), before = banner.innerHTML;
    page.choose( 'off' ); await settle(); page.focus(); await settle();
    assert.equal( page.reloads(), 0 ); assert.equal( banner.hidden, false ); assert.equal( banner.innerHTML, before );
    assert.ok( page.notifications.some( x => /Close this tab/.test( x.message ) ) ); page.close();
} );
test( 'Manual layouts reject invalid slots, copied production origin and production environment', async () => {
    for ( const invalid of [ '', 1234567890, '0987654321\n', 'bad-slot' ] ) {
        const page = visit( { ...manualVisit, config: { ...manualConfig, bannerSlot: invalid }, cookie: cookieName + '=anchor' } );
        await settle(); noProvider( page ); page.close();
    }
    for ( const invalid of [ { ...manualConfig, sidebarSlot: '' }, { ...manualConfig, environment: 'production' } ] ) {
        const page = visit( { ...manualVisit, config: invalid, cookie: cookieName + '=anchor' } ); await settle(); noProvider( page ); page.close();
    }
    const page = visit( { config: manualConfig, cookie: cookieName + '=anchor' } ); await settle(); noProvider( page ); page.close();
} );
test( 'Manual staging previews switch between Side, Banner and Off without a floating dock', async () => {
    const page = visit( { ...staging, config: { preview: true, manual: true }, viewport: { width: 1920, height: 1080 } } ); await settle();
    const body = page.w.document.getElementById( 'bodyContent' ), before = body.outerHTML;
    page.choose( 'anchor' ); await settle();
    const banner = page.w.document.getElementById( 'eql-ad-banner' );
    assert.equal( banner.hidden, false ); assert.equal( banner.nextElementSibling, body ); assert.equal( page.previewOpen(), false );
    assert.equal( page.w.document.getElementById( 'eql-ad-sidebar' ).hidden, true );
    page.choose( 'off' ); await settle(); assert.equal( banner.hidden, true ); assert.equal( body.outerHTML, before );
    noProvider( page ); page.close();
} );

( async () => {
    for ( const { name, run } of cases ) {
        try { await run(); console.log( 'PASS ' + name ); }
        catch ( error ) { console.error( 'FAIL ' + name ); throw error; }
    }
    console.log( cases.length + ' synthetic advertising checks passed; no live account, provider, or real revenue tested.' );
} )().catch( error => { console.error( error ); process.exitCode = 1; } );
