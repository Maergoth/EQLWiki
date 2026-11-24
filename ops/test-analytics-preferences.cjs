'use strict';

// Synthetic browser storage and dialog checks; no provider, account or network calls.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/resources/analytics-preferences.js' ), 'utf8' );
const cookieName = 'eql_analytics_opt_out';
const notificationKey = 'eql-analytics-preference-change';
const cases = [];
const test = ( name, run ) => cases.push( { name, run } );

function visit( options = {} ) {
    let reloads = 0;
    const virtualConsole = new VirtualConsole();
    virtualConsole.on( 'jsdomError', error => {
        if ( error.message.includes( 'Not implemented: navigation' ) ) { reloads++; }
        else { throw error; }
    } );
    const dom = new JSDOM( '<!doctype html><html><head></head><body><ul id="footer-places"><li><a href="/EQLWiki:Privacy_policy">Privacy policy</a></li></ul></body></html>', {
        url: options.url || 'https://eqlwiki.com/Synthetic_article', runScripts: 'outside-only', virtualConsole
    } );
    const w = dom.window;
    let globalPrivacyControl = options.gpc === true;
    Object.defineProperty( w.navigator, 'globalPrivacyControl', { get: () => globalPrivacyControl } );
    if ( options.cookiesDisabled ) { Object.defineProperty( w.navigator, 'cookieEnabled', { value: false } ); }
    const cookieAccess = Object.getOwnPropertyDescriptor( w.Document.prototype, 'cookie' );
    const writeCookie = value => cookieAccess.set.call( w.document, value );
    const actualCookies = () => cookieAccess.get.call( w.document );
    for ( const cookie of options.cookies || [] ) { writeCookie( cookie ); }
    let readFails = options.readFails === true, writeFails = options.writeFails || false;
    const writes = [];
    Object.defineProperty( w.document, 'cookie', {
        configurable: true,
        get() { if ( readFails ) { throw new Error( 'Synthetic unreadable cookie jar' ); } return actualCookies(); },
        set( value ) {
            writes.push( value );
            if ( writeFails === 'throw' ) { throw new Error( 'Synthetic rejected write' ); }
            if ( !writeFails ) { writeCookie( value ); }
        }
    } );
    let state;
    const states = [], subscribers = [], readKeys = [];
    const hook = {
        fire( value ) { assert.equal( typeof value, 'boolean' ); state = value; states.push( value ); subscribers.forEach( callback => callback( value ) ); },
        add( callback ) { subscribers.push( callback ); if ( state !== undefined ) { callback( state ); } }
    };
    w.mw = {
        config: { get( key ) { readKeys.push( key ); assert.equal( key, 'wgEQLAnalyticsPreferences' );
            return options.config === undefined ? { origin: 'https://eqlwiki.com' } : options.config; } },
        hook( name ) { assert.equal( name, 'eql.analytics.state', 'Never use ad or account state.' ); return hook; }
    };
    w.HTMLDialogElement.prototype.showModal = function () { this.setAttribute( 'open', '' ); };
    w.HTMLDialogElement.prototype.close = function () { this.removeAttribute( 'open' ); this.dispatchEvent( new w.Event( 'close' ) ); };
    const storageWrites = [];
    const setItem = w.Storage.prototype.setItem;
    w.Storage.prototype.setItem = function ( key, value ) {
        storageWrites.push( [ key, value ] );
        if ( options.storageFails ) { throw new Error( 'Synthetic blocked storage' ); }
        return setItem.call( this, key, value );
    };
    w.eval( source );
    const beforeDomState = state;
    w.document.dispatchEvent( new w.Event( 'DOMContentLoaded' ) );
    const link = () => w.document.getElementById( 'eql-analytics-settings-link' );
    const dialog = () => w.document.getElementById( 'eql-analytics-settings' );
    const checkbox = () => dialog().querySelector( 'input' );
    return {
        w, writes, states, storageWrites, beforeDomState, readKeys, hook, link, dialog, checkbox,
        state: () => state, reloads: () => reloads, cookies: actualCookies,
        cookie( value ) { writeCookie( value ); },
        gpc( value ) { globalPrivacyControl = value; },
        readFails( value ) { readFails = value; }, writeFails( value ) { writeFails = value; },
        focus() { w.dispatchEvent( new w.Event( 'focus' ) ); },
        show() { w.dispatchEvent( new w.PageTransitionEvent( 'pageshow', { persisted: true } ) ); },
        storage( key = notificationKey ) { w.dispatchEvent( new w.StorageEvent( 'storage', { key, newValue: 'not-a-preference' } ) ); },
        open() { link().click(); },
        save( allow ) { checkbox().checked = allow; dialog().querySelector( 'form' ).dispatchEvent( new w.Event( 'submit', { cancelable: true } ) ); },
        close() { dom.window.close(); }
    };
}

test( 'Choice defaults on without any ad/account configuration and publishes before DOM readiness', () => {
    const page = visit();
    assert.equal( page.beforeDomState, true );
    let remembered; page.hook.add( value => { remembered = value; } ); assert.equal( remembered, true );
    assert.equal( page.dialog().open, false ); assert.equal( page.link().textContent, 'Analytics settings' );
    assert.equal( page.writes.length, 0 ); assert.equal( page.storageWrites.length, 0 );
    assert.equal( page.w.document.querySelectorAll( 'script,iframe' ).length, 0 );
    assert.equal( page.w.googlefc, undefined ); assert.equal( page.w.eqlAnalyticsDataLayer, undefined );
    assert.deepEqual( page.readKeys, [ 'wgEQLAnalyticsPreferences' ] ); page.close();
} );

test( 'Exact production configuration is required; staging/local/invalid settings create nothing', () => {
    for ( const options of [ { config: null }, { config: {} }, { config: { origin: 'https://test.eqlwiki.com' } },
        { url: 'https://test.eqlwiki.com/' }, { url: 'http://eqlwiki.com/' }, { url: 'https://www.eqlwiki.com/' },
        { url: 'https://eqlwiki.com:8443/' }, { url: 'http://127.0.0.1/' } ] ) {
        const page = visit( options ); assert.equal( page.state(), undefined ); assert.equal( page.link(), null );
        assert.equal( page.writes.length, 0 ); page.close();
    }
} );

test( 'Dialog works on tag-free policy/account pages and preserves accessible names/focus', () => {
    for ( const url of [ 'https://eqlwiki.com/EQLWiki:Privacy_policy', 'https://eqlwiki.com/Special:Preferences' ] ) {
        const page = visit( { url } ); page.open();
        assert.equal( page.dialog().open, true ); assert.equal( page.checkbox().checked, true );
        assert.equal( page.w.document.activeElement, page.checkbox() );
        assert.equal( page.dialog().getAttribute( 'aria-labelledby' ), 'eql-analytics-settings-title' );
        assert.ok( page.w.document.getElementById( page.dialog().getAttribute( 'aria-describedby' ) ).textContent.includes( 'Ads settings are separate' ) );
        assert.equal( page.link().getAttribute( 'aria-haspopup' ), 'dialog' );
        page.dialog().querySelector( 'button[type="button"]' ).click();
        assert.equal( page.dialog().open, false ); assert.equal( page.w.document.activeElement, page.link() );
        page.w.document.dispatchEvent( new page.w.Event( 'DOMContentLoaded' ) );
        assert.equal( page.w.document.querySelectorAll( '#eql-analytics-settings-link' ).length, 1 );
        assert.equal( page.w.document.querySelectorAll( 'script,iframe' ).length, 0 ); page.close();
    }
} );

test( 'Opt-out saves only the browser cookie, stops before the write, and reloads once', () => {
    const page = visit( { cookies: [ 'unrelated=keep; Path=/', 'eql_ads_session_v1_test=side; Path=/' ] } );
    const stateAtWrite = [];
    const access = Object.getOwnPropertyDescriptor( page.w.document, 'cookie' );
    Object.defineProperty( page.w.document, 'cookie', { ...access, set( value ) { stateAtWrite.push( page.state() ); access.set( value ); } } );
    page.open(); page.save( false );
    assert.deepEqual( stateAtWrite, [ false ] ); assert.equal( page.state(), false );
    assert.equal( page.reloads(), 1 ); assert.equal( page.writes.length, 1 );
    assert.match( page.writes[ 0 ], /^eql_analytics_opt_out=1;/ );
    assert.match( page.writes[ 0 ], /Max-Age=31536000/ ); assert.match( page.writes[ 0 ], /Path=\// );
    assert.match( page.writes[ 0 ], /SameSite=Lax/ ); assert.match( page.writes[ 0 ], /Secure/ );
    assert.doesNotMatch( page.writes[ 0 ], /Domain=/i );
    assert.match( page.cookies(), /unrelated=keep/ ); assert.match( page.cookies(), /eql_ads_session_v1_test=side/ );
    assert.deepEqual( page.storageWrites.map( pair => pair[ 0 ] ), [ notificationKey ] );
    assert.match( page.storageWrites[ 0 ][ 1 ], /^\d+$/ );
    page.save( true ); page.focus(); assert.equal( page.reloads(), 1 ); assert.equal( page.state(), false );
    const next = visit( { cookies: [ cookieName + '=1; Path=/' ], url: 'https://eqlwiki.com/Category:Character' } );
    assert.equal( next.state(), false ); assert.equal( next.writes.length, 0 ); next.close(); page.close();
} );

test( 'Opt-in deletes only the opt-out cookie and stays stopped until the fresh document', () => {
    const page = visit( { cookies: [ cookieName + '=1; Path=/', 'other=keep; Path=/' ], storageFails: true } );
    page.open(); page.save( true );
    assert.equal( page.reloads(), 1 ); assert.doesNotMatch( page.cookies(), /eql_analytics_opt_out/ );
    assert.match( page.cookies(), /other=keep/ ); assert.match( page.writes[ 0 ], /Max-Age=0/ );
    page.focus(); page.show(); assert.equal( page.state(), false, 'No restart before reload.' );
    assert.ok( !page.states.includes( true ) ); page.close();
} );

test( 'Unreadable, disabled or malformed cookie state fails closed without renewal', () => {
    for ( const options of [ { readFails: true }, { cookiesDisabled: true }, { cookies: [ cookieName + '=bad; Path=/' ] } ] ) {
        const page = visit( options ); assert.equal( page.state(), false ); page.open();
        assert.equal( page.checkbox().checked, false ); assert.equal( page.writes.length, 0 );
        if ( !options.cookies ) { assert.match( page.dialog().textContent, /could not read/ ); }
        page.focus(); page.show(); assert.equal( page.state(), false ); page.close();
    }
} );

test( 'Blocked writes leave measurement off with visible error, do not reload, and permit retry', () => {
    for ( const writeFails of [ 'throw', 'ignore' ] ) {
        const page = visit( { writeFails } ); page.open(); page.save( false );
        assert.equal( page.state(), false ); assert.equal( page.reloads(), 0 );
        assert.match( page.dialog().querySelector( '[role="status"]' ).textContent, /could not be saved/ );
        assert.equal( page.dialog().open, true ); assert.equal( page.storageWrites.length, 0 );
        page.focus(); page.show(); page.storage(); assert.equal( page.state(), false, 'Failed write remains latched off.' );
        page.writeFails( false ); page.save( false ); assert.equal( page.reloads(), 1 );
        assert.match( page.cookies(), /eql_analytics_opt_out=1/ ); page.close();
    }
    const optIn = visit( { cookies: [ cookieName + '=1; Path=/' ], writeFails: 'ignore' } );
    optIn.open(); optIn.save( true ); assert.equal( optIn.state(), false ); assert.equal( optIn.reloads(), 0 ); optIn.close();
} );

test( 'GPC vetoes initial and later collection and cannot be overridden by form input', () => {
    const page = visit( { gpc: true } ); page.open();
    assert.equal( page.state(), false ); assert.equal( page.checkbox().disabled, true );
    assert.match( page.dialog().textContent, /Global Privacy Control is on/ );
    page.save( true ); assert.match( page.cookies(), /eql_analytics_opt_out=1/ ); assert.equal( page.state(), false ); page.close();
    const later = visit(); later.gpc( true ); later.focus(); assert.equal( later.state(), false );
    later.open(); assert.equal( later.checkbox().disabled, true ); later.close();
} );

test( 'Cross-tab events and focus reread cookies rather than trust the notification payload', () => {
    const page = visit(); page.cookie( cookieName + '=1; Path=/' ); page.storage(); assert.equal( page.state(), false );
    page.cookie( cookieName + '=; Path=/; Max-Age=0' ); page.storage( 'unrelated' ); assert.equal( page.state(), false );
    page.focus(); assert.equal( page.state(), true );
    page.cookie( cookieName + '=1; Path=/' ); page.show(); assert.equal( page.state(), false );
    page.cookie( cookieName + '=; Path=/; Max-Age=0' ); page.storage( null ); assert.equal( page.state(), true );
    assert.equal( page.writes.length, 0 ); assert.equal( page.storageWrites.length, 0 ); page.close();
} );

for ( const { name, run } of cases ) { run(); console.log( 'PASS ' + name ); }
console.log( cases.length + ' synthetic Analytics preference checks passed; native browser layout/focus and real reload require browser verification.' );
