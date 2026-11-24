'use strict';

// Synthetic consent/provider stubs only. No Google tag or measurement is fetched.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/resources/analytics.js' ), 'utf8' );
const id = 'G-1234567890';
const config = { measurementId: id, origin: 'https://eqlwiki.com',
    pageLocation: 'https://eqlwiki.com/Synthetic_article', pageTitle: 'Synthetic article' };
const cases = [];
const test = ( name, run ) => cases.push( { name, run } );

function visit( options = {} ) {
    const dom = new JSDOM( '<!doctype html><html><head><title>Private browser context</title></head><body></body></html>', {
        url: options.url || 'https://eqlwiki.com/Synthetic_article?search=private&token=secret#private',
        referrer: 'https://other.example/private?token=secret', runScripts: 'outside-only'
    } );
    const w = dom.window;
    if ( options.gpc ) { Object.defineProperty( w.navigator, 'globalPrivacyControl', { value: true } ); }
    const values = { wgEQLAnalytics: options.config === undefined ? config : options.config,
        wgEQLAdSense: options.provider === undefined ? { environment: 'production', client: 'ca-pub-1234567890123456' } : options.provider,
        wgUserId: 0, wgUserName: null, wgUserIsTemp: false };
    let hookValue = options.enabled, hooks = [], status = options.status === undefined ? 1 : options.status;
    let tcfListener, modeReady = false;
    const callbacks = [];
    const fire = enabled => { hookValue = enabled; hooks.forEach( hook => hook( enabled ) ); };
    w.mw = { config: { get: key => values[ key ] }, hook: name => {
        assert.equal( name, 'eql.ads.state' );
        return { add( callback ) { hooks.push( callback ); if ( hookValue !== undefined ) { callback( hookValue ); } }, fire };
    } };
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
    w.eval( source );
    const scripts = () => [ ...w.document.querySelectorAll( 'script[src]' ) ];
    return {
        w, values, fire, scripts, callbacks,
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

test( 'Off and missing effective preference never register CMP or request Analytics', () => {
    for ( const enabled of [ undefined, false, 'true', 1 ] ) {
        const page = visit( { enabled } ); page.ready(); page.focus();
        assert.equal( page.callbacks.length, 0 ); assert.equal( page.scripts().length, 0 );
        assert.equal( page.commands().length, 0 ); assert.equal( page.disabled(), true ); page.close();
    }
} );
test( 'Missing, malformed, staging, preview and private canonical config fail closed', () => {
    const invalid = [ { config: null }, { provider: null }, { gpc: true },
        { provider: { environment: 'staging' } }, { provider: { environment: 'production', preview: true } },
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
        assert.equal( page.commands().length, 0 ); page.close();
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
        assert.equal( configs[ 0 ][ 2 ].page_referrer, '' );
        assert.equal( configs[ 0 ][ 2 ].allow_google_signals, false );
        assert.equal( configs[ 0 ][ 2 ].allow_ad_personalization_signals, false );
        assert.equal( configs[ 0 ][ 2 ].cookie_domain, 'none' );
        assert.equal( configs[ 0 ][ 2 ].cookie_expires, 2592000 );
        assert.equal( configs[ 0 ][ 2 ].cookie_update, false );
        assert.equal( configs[ 0 ][ 2 ].user_id, undefined );
        const defaults = page.commands().find( x => x[ 0 ] === 'consent' );
        assert.deepEqual( Object.keys( defaults[ 2 ] ), [ 'analytics_storage' ], 'The CMP retains ownership of all advertising consent fields.' );
        assert.equal( page.w.dataLayer, undefined, 'Analytics does not populate the shared dataLayer.' );
        assert.equal( page.disabled(), false ); page.close();
    }
} );
test( 'Preference gate works when the Analytics module loads before or after ads.js', () => {
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
