'use strict';

// Synthetic consent/provider stubs only. No Google tag or measurement is fetched.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/resources/analytics.js' ), 'utf8' );
const id = 'G-1234567890';
const config = { measurementId: id, origin: 'https://eqlwiki.com',
    pageLocation: 'https://eqlwiki.com/Synthetic_article', pageTitle: 'Synthetic article',
    pageNamespace: 0, consentPublisher: 'pub-1234567890123456' };
const cases = [];
const test = ( name, run ) => cases.push( { name, run } );

function visit( options = {} ) {
    const dom = new JSDOM( '<!doctype html><html><head><title>Private browser context</title></head><body></body></html>', {
        url: options.url || 'https://eqlwiki.com/Synthetic_article?search=private&token=secret#private',
        referrer: options.referrer || 'https://other.example/private?token=secret', runScripts: 'outside-only'
    } );
    const w = dom.window;
    if ( options.gpc ) { Object.defineProperty( w.navigator, 'globalPrivacyControl', { value: true } ); }
    const values = { wgEQLAnalytics: options.config === undefined ? config : options.config,
        wgEQLAdSense: options.provider === undefined ? { environment: 'production', client: 'ca-pub-1234567890123456' } : options.provider,
        wgUserId: options.named ? 42 : 0, wgUserName: options.named ? 'Private account' : null, wgUserIsTemp: false };
    let hookValue = options.enabled, hooks = [], status = options.status === undefined ? 1 : options.status;
    let tcfListener, modeReady = false;
    const callbacks = [];
    const fire = enabled => { hookValue = enabled; hooks.forEach( hook => hook( enabled ) ); };
    w.mw = { config: { get: key => values[ key ] }, hook: name => {
        assert.equal( name, 'eql.analytics.state', 'Analytics must never subscribe to Ads preferences.' );
        return { add( callback ) { hooks.push( callback ); if ( hookValue !== undefined ) { callback( hookValue ); } }, fire };
    } };
    if ( options.existingCmp ) {
        const script = w.document.createElement( 'script' );
        script.src = 'https://fundingchoicesmessages.google.com/i/' + config.consentPublisher + '?ers=1';
        w.document.head.appendChild( script );
    }
    w.googlefc = {
        callbackQueue: { push( item ) {
            callbacks.push( item );
            if ( modeReady && item.CONSENT_MODE_DATA_READY ) { item.CONSENT_MODE_DATA_READY(); }
            return callbacks.length;
        } },
        getGoogleConsentModeValues() { if ( options.throwConsent ) { throw new Error( 'Synthetic CMP failure' ); }
            return { analyticsStoragePurposeConsentStatus: status }; }
    };
    w.__tcfapi = ( method, version, callback ) => {
        assert.equal( method, 'addEventListener' ); assert.equal( version, 0 ); tcfListener = callback;
        if ( !options.deferTcf ) { callback( { cmpStatus: 'loaded', eventStatus: options.initialTcfStatus || 'tcloaded' }, true ); }
    };
    // Model a current Google tag: its destination may auto-initialize on js,
    // before script.load, and then ignore duplicate on-page config commands.
    // No provider code executes and every simulated network/cookie write is local.
    const requests = [], cookieWrites = [], bootSnapshots = [];
    let runtimeStarted = false, destination, ignoredConfigs = 0;
    const globals = {};
    const defaults = { page_location: w.location.href, page_title: w.document.title,
        page_referrer: w.document.referrer, send_page_view: true,
        allow_google_signals: true, allow_ad_personalization_signals: true,
        cookie_prefix: '', cookie_domain: 'auto', cookie_expires: 63072000, cookie_update: true };
    const runtimeFields = () => ( { ...defaults, ...globals, ...destination } );
    function send( event, fields = {} ) {
        if ( w[ 'ga-disable-' + id ] ) { return; }
        const values = { ...runtimeFields(), ...fields };
        cookieWrites.push( { name: values.cookie_prefix + '_ga', domain: values.cookie_domain,
            expires: values.cookie_expires, update: values.cookie_update } );
        requests.push( { event, ...values } );
    }
    function processCommand( command ) {
        const [ kind, value, parameters ] = Array.from( command );
        if ( kind === 'set' ) { Object.assign( globals, value ); }
        if ( kind === 'js' || kind === 'config' ) {
            if ( kind === 'config' && options.revokeDuringConfig ) { fire( false ); }
            if ( kind === 'config' && destination && options.ignoreDuplicateConfig !== false ) { ignoredConfigs++; return; }
            destination = kind === 'config' ? { ...parameters } : {};
            if ( runtimeFields().send_page_view !== false ) { send( 'page_view' ); }
        }
        if ( kind === 'event' ) { send( value, parameters ); }
    }
    function bootstrap() {
        assert.equal( runtimeStarted, false ); runtimeStarted = true;
        const queue = w.eqlAnalyticsDataLayer;
        bootSnapshots.push( { disabled: w[ 'ga-disable-' + id ], commands: Array.from( queue, command => Array.from( command ) ) } );
        const queued = Array.from( queue );
        queue.push = function ( command ) { const length = Array.prototype.push.call( this, command ); processCommand( command ); return length; };
        queued.forEach( processCommand );
    }
    if ( options.earlyRuntime ) {
        const appendChild = w.document.head.appendChild.bind( w.document.head );
        w.document.head.appendChild = element => {
            const result = appendChild( element );
            if ( element.src && element.src.includes( 'googletagmanager.com/gtag/' ) ) { bootstrap(); }
            return result;
        };
    }
    w.eval( source );
    const scripts = () => [ ...w.document.querySelectorAll( 'script[src]' ) ].filter( script => script.src.includes( 'googletagmanager.com/gtag/' ) );
    const cmpScripts = () => [ ...w.document.querySelectorAll( 'script[src]' ) ].filter( script => script.src.includes( 'fundingchoicesmessages.google.com/' ) );
    return {
        w, values, fire, scripts, cmpScripts, callbacks, bootstrap, requests, cookieWrites, bootSnapshots,
        ignoredConfigs: () => ignoredConfigs,
        commands: () => Array.from( w.eqlAnalyticsDataLayer || [], command => Array.from( command ) ),
        disabled: () => w[ 'ga-disable-' + id ],
        ready() { modeReady = true; callbacks.forEach( item => item.CONSENT_MODE_DATA_READY?.() ); },
        apiReady() { callbacks.forEach( item => item.CONSENT_API_READY?.() ); },
        status( next ) { status = next; },
        load() { scripts()[ 0 ].dispatchEvent( new w.Event( 'load' ) ); },
        error() { scripts()[ 0 ].dispatchEvent( new w.Event( 'error' ) ); },
        consentEvent( eventStatus, success = true ) { tcfListener( { cmpStatus: 'loaded', eventStatus }, success ); },
        focus() { w.dispatchEvent( new w.Event( 'focus' ) ); },
        hide( persisted = false ) { w.dispatchEvent( new w.PageTransitionEvent( 'pagehide', { persisted } ) ); },
        show() { w.dispatchEvent( new w.PageTransitionEvent( 'pageshow', { persisted: true } ) ); },
        close() { dom.window.close(); }
    };
}

test( 'Separate analytics opt-out and missing preference never register CMP or request Analytics', () => {
    for ( const enabled of [ undefined, false, 'true', 1 ] ) {
        const page = visit( { enabled } ); page.ready(); page.focus();
        assert.equal( page.callbacks.length, 0 ); assert.equal( page.scripts().length, 0 ); assert.equal( page.cmpScripts().length, 0 );
        assert.equal( page.commands().length, 0 ); assert.equal( page.disabled(), true ); page.close();
    }
} );
test( 'Missing, malformed, nonproduction and private canonical config fail closed', () => {
    const invalid = [ { config: null }, { gpc: true },
        ...[ '', 'pub-1234567890123456\n', 'ca-pub-1234567890123456', true ].map( consentPublisher => ( { config: { ...config, consentPublisher } } ) ),
        ...[ -1, 1, 2, 4, undefined, '0' ].map( pageNamespace => ( { config: { ...config, pageNamespace } } ) ),
        { url: 'https://test.eqlwiki.com/Synthetic_article' }, { url: 'https://other.example/Synthetic_article' },
        ...[ '', 'UA-1234567890', 'G-1234567890\n', 'G-too-short', true ].map( measurementId => ( { config: { ...config, measurementId } } ) ),
        ...[ 'https://other.example/Article', 'http://eqlwiki.com/Article',
            'https://eqlwiki.com/Article?token=private', 'https://eqlwiki.com/Article#private',
            'https://eqlwiki.com/User:Reader', 'https://eqlwiki.com/User%3AReader',
            'https://eqlwiki.com/index.php', 'https://eqlwiki.com/bb/profile',
            'https://reader:password@eqlwiki.com/Article' ].map( pageLocation => ( { config: { ...config, pageLocation } } ) )
    ];
    for ( const options of invalid ) {
        const page = visit( { enabled: true, ...options } ); page.ready();
        assert.equal( page.scripts().length, 0, JSON.stringify( options ) );
        assert.equal( page.cmpScripts().length, 0 );
        assert.equal( page.commands().length, 0 ); page.close();
    }
} );
test( 'Ads absence, Ads X/default-Off and unavailable ad preferences do not prevent permitted measurement', () => {
    for ( const named of [ false, true ] ) {
        for ( const provider of [ null, { environment: 'production', format: '' }, { preview: true } ] ) {
            const page = visit( { enabled: true, named, provider } );
            page.ready(); page.load();
            assert.equal( page.disabled(), false );
            assert.equal( page.commands().filter( c => c[ 0 ] === 'event' && c[ 1 ] === 'page_view' ).length, 1 );
            assert.equal( page.w.document.querySelectorAll( 'script[src*="adsbygoogle"]' ).length, 0 );
            assert.ok( !JSON.stringify( page.commands() ).includes( 'Private account' ) );
            page.close();
        }
    }
} );

test( 'Standalone consent loads once without ad scripts and respects an existing Google runtime', () => {
    for ( const existingCmp of [ false, true ] ) {
        const page = visit( { enabled: true, provider: null, existingCmp } );
        page.fire( true ); page.focus();
        assert.equal( page.cmpScripts().length, 1 );
        assert.equal( page.cmpScripts()[ 0 ].src, 'https://fundingchoicesmessages.google.com/i/pub-1234567890123456?ers=1' );
        if ( !existingCmp ) {
            assert.equal( page.cmpScripts()[ 0 ].referrerPolicy, 'strict-origin' );
            assert.ok( page.w.frames.googlefcPresent );
        }
        assert.equal( page.scripts().length, 0, 'Loading the CMP is not consent.' );
        page.ready(); page.load();
        assert.equal( page.cmpScripts().length, 1 ); assert.equal( page.scripts().length, 1 );
        page.close();
    }
    const failed = visit( { enabled: true } );
    failed.cmpScripts()[ 0 ].dispatchEvent( new failed.w.Event( 'error' ) );
    failed.ready(); failed.fire( true ); failed.focus();
    assert.equal( failed.disabled(), true ); assert.equal( failed.scripts().length, 0 ); failed.close();
} );

test( 'Public Category and Help canonical pages strip visitor queries without admitting private namespaces', () => {
    for ( const [ pageNamespace, prefix ] of [ [ 14, 'Category:' ], [ 12, 'Help:' ] ] ) {
        const publicConfig = { ...config, pageNamespace, pageTitle: prefix + 'Synthetic', pageLocation: 'https://eqlwiki.com/' + prefix + 'Synthetic' };
        const page = visit( { enabled: true, config: publicConfig, url: publicConfig.pageLocation + '?token=private#private' } );
        page.ready(); page.load();
        const view = page.commands().find( c => c[ 0 ] === 'event' );
        assert.equal( view[ 2 ].page_location, publicConfig.pageLocation );
        assert.ok( !JSON.stringify( page.commands() ).includes( 'private' ) ); page.close();
        const mismatch = visit( { enabled: true, config: { ...publicConfig, pageLocation: 'https://eqlwiki.com/User:Private_account' } } );
        mismatch.ready(); assert.equal( mismatch.cmpScripts().length, 0 ); assert.equal( mismatch.scripts().length, 0 ); mismatch.close();
    }
} );

test( 'Acquisition includes only external referrer origin, never private paths or internal navigation', () => {
    for ( const [ referrer, expected ] of [
        [ 'https://search.example/private?q=private#private', 'https://search.example/' ],
        [ 'https://eqlwiki.com/User:Private_account?token=private', '' ],
        [ 'https://test.eqlwiki.com/private', '' ],
        [ 'https://reader:private@external.example/path', '' ]
    ] ) {
        const page = visit( { enabled: true, referrer } ); page.ready(); page.load();
        assert.equal( page.commands().find( c => c[ 0 ] === 'set' )[ 1 ].page_referrer, expected );
        assert.equal( page.commands().find( c => c[ 0 ] === 'event' )[ 2 ].page_referrer, expected );
        assert.ok( !JSON.stringify( page.commands() ).includes( 'private' ) ); page.close();
    }
} );

test( 'Missing, unknown, denied and unconfigured consent make no GA request or command', () => {
    for ( const status of [ undefined, null, 0, 2, 4, 99, '1' ] ) {
        const page = visit( { enabled: true } ); page.status( status );
        assert.equal( page.scripts().length, 0 ); page.ready(); page.focus(); page.fire( true );
        assert.equal( page.scripts().length, 0 ); assert.equal( page.commands().length, 0 );
        assert.equal( page.disabled(), true ); page.close();
    }
    const error = visit( { enabled: true, throwConsent: true } ); error.ready();
    assert.equal( error.scripts().length, 0 ); error.close();
} );
test( 'Granted and explicitly inapplicable consent load once with sanitized measurement settings', () => {
    for ( const status of [ 1, 3 ] ) {
        const page = visit( { enabled: true, status } ); page.ready(); page.ready(); page.fire( true ); page.focus();
        assert.equal( page.scripts().length, 1 );
        assert.equal( page.scripts()[ 0 ].src, 'https://www.googletagmanager.com/gtag/js?id=' + id + '&l=eqlAnalyticsDataLayer' );
        assert.equal( page.scripts()[ 0 ].referrerPolicy, 'no-referrer' );
        assert.equal( page.commands().filter( x => x[ 0 ] === 'config' ).length, 0, 'Wait for load and recheck consent.' );
        page.load(); page.load(); page.focus();
        const configs = page.commands().filter( x => x[ 0 ] === 'config' );
        assert.equal( configs.length, 1 ); assert.equal( configs[ 0 ][ 1 ], id );
        assert.equal( configs[ 0 ][ 2 ].page_location, config.pageLocation );
        assert.equal( configs[ 0 ][ 2 ].page_title, config.pageTitle );
        assert.equal( configs[ 0 ][ 2 ].page_referrer, 'https://other.example/' );
        assert.equal( configs[ 0 ][ 2 ].allow_google_signals, false );
        assert.equal( configs[ 0 ][ 2 ].allow_ad_personalization_signals, false );
        assert.equal( configs[ 0 ][ 2 ].cookie_domain, 'none' );
        assert.equal( configs[ 0 ][ 2 ].cookie_prefix, 'eql' );
        assert.equal( configs[ 0 ][ 2 ].cookie_expires, 2592000 );
        assert.equal( configs[ 0 ][ 2 ].cookie_update, false );
        assert.equal( configs[ 0 ][ 2 ].send_page_view, false );
        assert.equal( configs[ 0 ][ 2 ].user_id, undefined );
        const views = page.commands().filter( x => x[ 0 ] === 'event' && x[ 1 ] === 'page_view' );
        assert.equal( views.length, 1 ); assert.equal( views[ 0 ][ 2 ].send_to, id );
        assert.equal( views[ 0 ][ 2 ].page_location, config.pageLocation );
        assert.equal( views[ 0 ][ 2 ].page_title, config.pageTitle );
        assert.equal( views[ 0 ][ 2 ].page_referrer, 'https://other.example/' );
        const defaults = page.commands().find( x => x[ 0 ] === 'consent' );
        assert.deepEqual( Object.keys( defaults[ 2 ] ), [ 'analytics_storage' ], 'The CMP retains ownership of all advertising consent fields.' );
        assert.equal( page.w.dataLayer, undefined, 'Analytics does not populate the shared dataLayer.' );
        assert.equal( page.disabled(), false ); page.close();
    }
} );
test( 'Early destination bootstrap cannot send defaults, write cookies or duplicate the explicit sanitized pageview', () => {
    for ( const ignoreDuplicateConfig of [ true, false ] ) {
        const page = visit( { enabled: true, earlyRuntime: true, ignoreDuplicateConfig } ); page.ready();
        assert.equal( page.bootSnapshots.length, 1 ); assert.equal( page.bootSnapshots[ 0 ].disabled, true );
        const commands = page.bootSnapshots[ 0 ].commands;
        assert.ok( commands.findIndex( c => c[ 0 ] === 'set' ) < commands.findIndex( c => c[ 0 ] === 'js' ) );
        const safe = commands.find( c => c[ 0 ] === 'set' )[ 1 ];
        assert.equal( safe.send_page_view, false ); assert.equal( safe.page_location, config.pageLocation );
        assert.equal( safe.page_title, config.pageTitle ); assert.equal( safe.page_referrer, 'https://other.example/' );
        assert.equal( safe.allow_google_signals, false ); assert.equal( safe.allow_ad_personalization_signals, false );
        assert.equal( safe.cookie_domain, 'none' ); assert.equal( safe.cookie_expires, 2592000 ); assert.equal( safe.cookie_update, false );
        assert.equal( safe.cookie_prefix, 'eql' );
        page.ready(); page.fire( true ); page.focus();
        assert.equal( page.disabled(), true, 'Repeated permission callbacks must not lift the bootstrap kill switch.' );
        assert.equal( page.requests.length, 0 ); assert.equal( page.cookieWrites.length, 0 );
        page.load(); page.load(); page.focus(); page.hide( true ); page.show();
        assert.equal( page.requests.length, 1 ); assert.equal( page.requests[ 0 ].event, 'page_view' );
        assert.equal( page.requests[ 0 ].page_location, config.pageLocation ); assert.equal( page.requests[ 0 ].page_title, config.pageTitle );
        assert.equal( page.requests[ 0 ].page_referrer, 'https://other.example/' ); assert.equal( page.requests[ 0 ].allow_ad_personalization_signals, false );
        assert.equal( page.requests[ 0 ].allow_google_signals, false );
        assert.deepEqual( page.cookieWrites, [ { name: 'eql_ga', domain: 'none', expires: 2592000, update: false } ] );
        assert.equal( page.ignoredConfigs(), ignoreDuplicateConfig ? 1 : 0 );
        assert.ok( !JSON.stringify( page.requests ).includes( 'private' ) ); page.close();
    }
} );

test( 'Off, revocation and departure during early bootstrap leave no event or cookie write', () => {
    for ( const revoke of [ page => page.fire( false ), page => { page.status( 2 ); page.focus(); },
        page => page.consentEvent( 'cmpuishown' ), page => page.hide() ] ) {
        const page = visit( { enabled: true, earlyRuntime: true } ); page.apiReady(); page.ready();
        revoke( page ); page.load(); page.fire( true ); page.ready(); page.focus();
        assert.equal( page.disabled(), true ); assert.equal( page.requests.length, 0 ); assert.equal( page.cookieWrites.length, 0 );
        assert.equal( page.commands().filter( c => c[ 0 ] === 'event' ).length, 0 ); page.close();
    }
    const synchronous = visit( { enabled: true, earlyRuntime: true, revokeDuringConfig: true } );
    synchronous.ready(); synchronous.load();
    assert.equal( synchronous.disabled(), true ); assert.equal( synchronous.requests.length, 0 );
    assert.equal( synchronous.cookieWrites.length, 0 ); synchronous.close();
} );

test( 'Independent preference gate works before or after analytics preferences initialize', () => {
    for ( const enabled of [ undefined, true ] ) {
        const page = visit( { enabled } ); page.fire( true ); page.ready(); page.load();
        assert.equal( page.scripts().length, 1 ); assert.equal( page.disabled(), false ); page.close();
    }
} );
test( 'Off, failed preference verification and identity changes immediately stop measurement', () => {
    const page = visit( { enabled: true } ); page.ready(); page.load();
    const count = page.commands().length;
    page.fire( false ); page.ready(); page.focus();
    assert.equal( page.disabled(), true ); assert.equal( page.commands().length, count );
    page.close();
    const identity = visit( { enabled: true } ); identity.ready(); identity.load();
    identity.values.wgUserId = 42; identity.values.wgUserName = 'Private account'; identity.focus();
    assert.equal( identity.disabled(), true ); identity.fire( true ); identity.ready();
    assert.equal( identity.disabled(), true );
    assert.ok( !JSON.stringify( identity.commands() ).includes( 'Private account' ) ); identity.close();
} );
test( 'Late loader completion cannot revive an Off or newly denied page', () => {
    for ( const revoke of [ page => page.fire( false ), page => page.status( 2 ), page => { page.values.wgUserId = 1; } ] ) {
        const page = visit( { enabled: true } ); page.ready(); revoke( page ); page.load();
        assert.equal( page.commands().filter( x => x[ 0 ] === 'config' ).length, 0 );
        assert.equal( page.disabled(), true ); page.ready(); page.fire( true );
        assert.equal( page.disabled(), true, 'A stale ready callback cannot recover a rejected load.' ); page.close();
    }
} );
test( 'Consent withdrawal and reopening stop for this document even if FC still reports the old grant', () => {
    for ( const event of [ 'cmpuishown', 'useractioncomplete' ] ) {
        const page = visit( { enabled: true } ); page.apiReady(); page.ready(); page.load();
        page.consentEvent( event ); assert.equal( page.disabled(), true );
        page.ready(); page.fire( true ); page.focus(); assert.equal( page.disabled(), true );
        assert.equal( page.scripts().length, 1 ); page.close();
    }
} );
test( 'First consent waits for the initial prompt completion in either callback order', () => {
    for ( const readyFirst of [ true, false ] ) {
        const page = visit( { enabled: true } ); page.apiReady(); page.consentEvent( 'cmpuishown' );
        if ( readyFirst ) { page.ready(); assert.equal( page.scripts().length, 0 ); }
        page.consentEvent( 'useractioncomplete' );
        if ( !readyFirst ) { assert.equal( page.scripts().length, 0 ); page.ready(); }
        assert.equal( page.scripts().length, 1 ); page.load();
        assert.equal( page.disabled(), false ); assert.equal( page.commands().filter( x => x[ 0 ] === 'config' ).length, 1 );
        page.close();
    }
} );
test( 'Initial UNKNOWN readiness does not mistake its first consent prompt for a reopening', () => {
    for ( const deferTcf of [ true, false ] ) {
        const page = visit( { enabled: true, deferTcf, status: 0 } ); page.apiReady(); page.ready();
        assert.equal( page.scripts().length, 0 ); page.consentEvent( 'cmpuishown' );
        page.status( 1 ); page.consentEvent( 'useractioncomplete' ); page.ready();
        assert.equal( page.scripts().length, 1 ); page.load(); assert.equal( page.disabled(), false );
        page.consentEvent( 'cmpuishown' ); page.ready(); assert.equal( page.disabled(), true ); page.close();
    }
} );

test( 'A delayed initial TCF snapshot is not mistaken for consent withdrawal', () => {
    for ( const initialTcfStatus of [ 'tcloaded', 'useractioncomplete' ] ) {
        const page = visit( { enabled: true, deferTcf: true } ); page.apiReady(); page.ready();
        assert.equal( page.scripts().length, 0, 'Await the initial TCF state before requesting GA.' );
        page.consentEvent( initialTcfStatus ); assert.equal( page.scripts().length, 1 ); page.load();
        assert.equal( page.disabled(), false ); page.consentEvent( 'useractioncomplete' );
        assert.equal( page.disabled(), true, 'A subsequent choice is distinct from the initial snapshot.' ); page.close();
    }
    const lateApi = visit( { enabled: true, initialTcfStatus: 'useractioncomplete' } ); lateApi.ready(); lateApi.apiReady(); lateApi.load();
    assert.equal( lateApi.disabled(), false ); lateApi.close();
} );
test( 'CMP failures and failed loaders remain stopped without retries', () => {
    const page = visit( { enabled: true } ); page.apiReady(); page.ready(); page.load();
    page.consentEvent( 'tcloaded', false ); page.focus(); assert.equal( page.disabled(), true ); page.close();
    const failed = visit( { enabled: true } ); failed.ready(); failed.error(); failed.ready(); failed.fire( true );
    assert.equal( failed.disabled(), true ); assert.equal( failed.scripts().length, 1 ); failed.close();
} );
test( 'Page departure prevents late starts; BFCache rechecks consent without a new pageview', () => {
    const late = visit( { enabled: true } ); late.hide(); late.ready(); assert.equal( late.scripts().length, 0 ); late.close();
    const page = visit( { enabled: true } ); page.ready(); page.load(); page.hide( true ); page.show();
    assert.equal( page.scripts().length, 1 ); assert.equal( page.commands().filter( x => x[ 0 ] === 'config' ).length, 1 );
    page.hide( true ); page.status( 2 ); page.show();
    assert.equal( page.disabled(), true ); page.status( 1 ); page.ready();
    assert.equal( page.disabled(), true ); page.close();
} );

for ( const { name, run } of cases ) { run(); console.log( 'PASS ' + name ); }
console.log( cases.length + ' synthetic Analytics checks passed; no real data collection tested.' );
