'use strict';

// Source-only browser regression; uses synthetic pages/revision metadata only.
// Run with jsdom 27 available through NODE_PATH: node ops/test-talk-unread.cjs
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname,
    '../skins/EQLImmersive/resources/talk-unread.js' ), 'utf8' );
const jquery = fs.readFileSync( path.join( __dirname,
    '../resources/lib/jquery/jquery.js' ), 'utf8' );
const TALK = 'Talk:Synthetic_example';
const STAMP = '2026-10-08T12:00:00Z';
const NEXT = '2026-10-08T12:01:00Z';
const LATER = '2026-10-08T12:02:00Z';
const marker = ( revision = 700, timestamp = STAMP, pageId ) => ( {
    revision, timestamp, ...( pageId === undefined ? {} : { pageId } )
} );
const row = ( revid, timestamp = STAMP ) => ( { revid, timestamp } );
const response = ( revisions, extra = {} ) => ( {
    query: { pages: [ { pageid: 44, ns: 1, actions: { read: true }, title: TALK, revisions, ...extra } ] }
} );
const initialRows = [ row( 100 ), row( 700, NEXT ), row( 910, LATER ) ];
const key = ( user = null, title = TALK, wiki = 'fixturewiki' ) =>
    'eql-talk-seen-v1:' + encodeURIComponent( wiki ) + ':' +
    ( user > 0 ? 'user-' + user : 'anon' ) + ':' + encodeURIComponent( title );
const stored = value => ( { [ key() ]: JSON.stringify( value ) } );
let cases = 0;

function deferred() {
    let resolve, reject;
    const promise = new Promise( ( done, fail ) => { resolve = done; reject = fail; } );
    return { promise, resolve, reject };
}

function apiPromise( value ) {
    const promise = value instanceof Error ? Promise.reject( value ) : Promise.resolve( value );
    // mw.Api supports both promise and older done/fail consumers.
    promise.done = callback => { promise.then( callback, () => {} ); return promise; };
    promise.fail = callback => { promise.catch( callback ); return promise; };
    promise.abort = () => {};
    return promise;
}

async function visit( options = {} ) {
    const virtualConsole = new VirtualConsole();
    const errors = [];
    virtualConsole.on( 'jsdomError', error => errors.push( error ) );
    const markup = options.markup === undefined ?
        '<li id="ca-talk"><a href="/wiki/Talk:Synthetic_example"><span>Talk</span></a></li>' +
        '<li id="n-eql-discussion"><a href="/wiki/Talk:Synthetic_example">Talk</a></li>' : options.markup;
    const dom = new JSDOM( '<body class="' + ( options.skin === undefined ? 'skin-eqlimmersive' : options.skin ) + '">' + markup +
        ( options.parser === false ? '' : '<div class="mw-parser-output">Synthetic content</div>' ) + '</body>', {
        url: 'https://wiki.example/wiki/Synthetic_example' + ( options.query || '' ),
        runScripts: 'outside-only', virtualConsole
    } );
    const w = dom.window;
    let visible = options.visible !== false;
    Object.defineProperty( w.document, 'visibilityState', { configurable: true,
        get: () => visible ? 'visible' : 'hidden' } );
    Object.defineProperty( w.document, 'hidden', { configurable: true, get: () => !visible } );
    const storage = w.localStorage;
    const rawGet = storage.getItem.bind( storage );
    const rawSet = storage.setItem.bind( storage );
    const rawRemove = storage.removeItem.bind( storage );
    for ( const [ storageKey, value ] of Object.entries( options.local || {} ) ) {
        rawSet( storageKey, value );
    }
    if ( options.blockStorage ) {
        Object.defineProperty( w, 'localStorage', { get() { throw new w.DOMException( 'Blocked', 'SecurityError' ); } } );
    }
    const config = {
        wgEQLTalkPageName: TALK, wgEQLTalkIsTalkPage: false,
        wgEQLTalkRevisionTimestamp: STAMP, wgWikiID: 'fixturewiki', wgUserId: null,
        wgAction: 'view', wgRevisionId: 700, wgCurRevisionId: 700,
        wgArticleId: 44, wgNamespaceNumber: 0, wgIsArticle: true,
        ...options.config
    };
    const hooks = new Map();
    const calls = [];
    const writes = [];
    const requests = options.responses ? options.responses.slice() : [];
    const defaultResponse = options.response === undefined ? response( initialRows ) : options.response;
    const api = {
        get( args ) {
            calls.push( JSON.parse( JSON.stringify( args ) ) );
            const value = requests.length ? requests.shift() : defaultResponse;
            return apiPromise( typeof value === 'function' ? value( args ) : value );
        },
        post( args ) { writes.push( args ); throw new Error( 'Unread status must not write API state' ); },
        postWithToken( _token, args ) { return this.post( args ); },
        postWithEditToken( args ) { return this.post( args ); }
    };
    w.matchMedia = () => ( { matches: Boolean( options.reducedMotion ),
        addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} } );
    w.addEventListener( 'error', event => errors.push( event.error || event.message ) );
    w.mw = w.mediaWiki = {
        config: { get: ( name, fallback ) => Object.hasOwn( config, name ) ? config[ name ] : fallback,
            set: ( name, value ) => { config[ name ] = value; } },
        hook: name => ( {
            add( callback ) { if ( !hooks.has( name ) ) { hooks.set( name, [] ); } hooks.get( name ).push( callback ); },
            fire( ...args ) { for ( const callback of hooks.get( name ) || [] ) { callback( ...args ); } }
        } ),
        Api: function () { return api; },
        loader: { using: () => apiPromise() },
        msg: ( name, count ) => name === 'eql-talk-unread-label-more' ? 'More than 99 unseen Talk changes' :
            name === 'eql-talk-unread-label' ? count + ' unseen Talk changes' : name,
        user: { options: { get() { throw new Error( 'Unread state must not read account options' ); },
            set() { throw new Error( 'Unread state must not write account options' ); } } }
    };
    Object.defineProperty( w, 'EQLUserState', { get() { throw new Error( 'Unread state must not use article account state' ); } } );
    w.eval( jquery );
    w.eval( source );
    const tick = async () => {
        for ( let i = 0; i < 3; i++ ) { await new Promise( resolve => setTimeout( resolve, 0 ) ); }
    };
    await tick();
    return {
        w, calls, writes, config, errors, tick,
        anchors: () => Array.from( w.document.querySelectorAll( '#ca-talk > a, #ca-more-talk > a, #n-eql-discussion > a, a#ca-talk-sticky-header' ) ),
        counts: () => Array.from( w.document.querySelectorAll( '.eql-talk-unread-number' ), el => el.textContent ),
        snapshot: () => Object.fromEntries( Array.from( { length: storage.length }, ( _, i ) => {
            const storageKey = storage.key( i ); return [ storageKey, rawGet( storageKey ) ];
        } ) ),
        get: storageKey => rawGet( storageKey ),
        put: ( storageKey, value ) => rawSet( storageKey, value ),
        content() { w.mw.hook( 'wikipage.content' ).fire( w.jQuery( w.document.querySelector( '.mw-parser-output' ) ) ); },
        async setVisible( value ) { visible = value; w.document.dispatchEvent( new w.Event( 'visibilitychange' ) ); await tick(); },
        async storage( storageKey, value ) {
            const oldValue = rawGet( storageKey );
            if ( value === null ) { rawRemove( storageKey ); } else { rawSet( storageKey, value ); }
            w.dispatchEvent( new w.StorageEvent( 'storage', { key: storageKey, oldValue, newValue: value,
                url: w.location.href } ) );
            await tick();
        },
        async pageshow( persisted ) { w.dispatchEvent( new w.PageTransitionEvent( 'pageshow', { persisted } ) ); await tick(); },
        close() { assert.equal( writes.length, 0, 'No account/revision API writes' );
            assert.deepEqual( errors, [], 'No uncaught browser errors' ); dom.window.close(); }
    };
}

function checkCount( page, expected, label ) {
    assert.deepEqual( page.counts(), expected === null ? [] : page.anchors().map( () => expected ), label );
    for ( const anchor of page.anchors() ) {
        assert.equal( anchor.getAttribute( 'href' ), '/wiki/Talk:Synthetic_example', label + ': preserves Talk URL' );
        assert.ok( anchor.textContent.includes( 'Talk' ), label + ': preserves Talk label' );
        assert.equal( anchor.querySelectorAll( '.eql-talk-unread-badge' ).length, expected === null ? 0 : 1,
            label + ': single badge per link' );
        if ( expected !== null ) {
            const message = expected === '99+' ? 'More than 99 unseen Talk changes' : expected + ' unseen Talk changes';
            assert.ok( ( anchor.getAttribute( 'aria-label' ) || '' ).includes( message ), label + ': accessible count' );
            assert.ok( ( anchor.getAttribute( 'title' ) || '' ).includes( message ), label + ': tooltip count' );
        }
    }
    cases++;
}

function checkQuery( args, withEnd, title = TALK ) {
    assert.equal( args.action, 'query' );
    assert.equal( args.prop, 'info|revisions' );
    assert.equal( Object.hasOwn( args, 'inprop' ), false, 'No deprecated readable Info property' );
    assert.equal( args.intestactions, 'read' );
    assert.equal( args.intestactionsdetail, 'boolean' );
    assert.equal( args.redirects, true );
    assert.equal( args.titles, title );
    assert.equal( args.rvprop, 'ids|timestamp', 'No content or contributor details requested' );
    assert.equal( args.rvdir, 'older', 'Query newest changes before same-second seen rows' );
    assert.equal( Number( args.rvlimit ), 101, 'Bound revision query' );
    assert.equal( Number( args.formatversion ), 2 );
    assert.equal( Object.hasOwn( args, 'rvend' ), withEnd );
    assert.equal( Object.hasOwn( args, 'rvstart' ), false, 'No ascending timestamp start boundary' );
}

async function main() {
    let page = await visit();
    checkCount( page, '3', 'First visit counts public revisions' );
    assert.equal( page.calls.length, 1 );
    checkQuery( page.calls[ 0 ], false );
    assert.deepEqual( page.snapshot(), {}, 'Visiting a subject does not mark Talk read' );
    page.content(); page.content();
    page.w.document.dispatchEvent( new page.w.Event( 'DOMContentLoaded' ) );
    await page.tick();
    checkCount( page, '3', 'Repeated startup/content events are idempotent' );
    assert.equal( page.calls.length, 1, 'One initial request despite repeated hooks' );
    page.close();

    const boundaryRows = [ row( 100, STAMP ), row( 700, STAMP ), row( 701, STAMP ), row( 910, NEXT ) ];
    page = await visit( { local: stored( marker() ), response: response( boundaryRows ) } );
    checkCount( page, '2', 'Timestamp ties use revision IDs and include later timestamps' );
    checkQuery( page.calls[ 0 ], true );
    assert.equal( Date.parse( page.calls[ 0 ].rvend ), Date.parse( STAMP ) );
    assert.deepEqual( JSON.parse( page.get( key() ) ), marker(), 'Counting preserves seen marker' );
    page.close();
    page = await visit( { local: stored( marker( 700, NEXT ) ) } );
    checkCount( page, '1', 'Nonconsecutive global revision IDs are counted, not subtracted' );
    page.close();
    page = await visit( { local: stored( marker( 500 ) ), response: response( [ row( 600, NEXT ), row( 910, LATER ) ] ) } );
    checkCount( page, '2', 'Removed baseline revision does not lose later changes' );
    page.close();
    page = await visit( { local: stored( marker( 910, LATER ) ) } );
    checkCount( page, null, 'No unseen revisions hides badge' );
    page.close();

    // Model API ordering/truncation, not just a canned list: a timestamp is
    // inclusive and cannot identify which of >101 same-second rows were seen.
    const sameSecondRows = Array.from( { length: 106 }, ( _, i ) => row( i + 1, STAMP ) );
    function boundedHistory( args ) {
        const direction = args.rvdir === 'older' ? -1 : 1;
        const revisions = sameSecondRows.filter( revision => {
            const timestamp = Date.parse( revision.timestamp );
            if ( args.rvstart && ( direction === 1 ? timestamp < Date.parse( args.rvstart ) :
                timestamp > Date.parse( args.rvstart ) ) ) { return false; }
            if ( args.rvend && ( direction === -1 ? timestamp < Date.parse( args.rvend ) :
                timestamp > Date.parse( args.rvend ) ) ) { return false; }
            return true;
        } ).sort( ( a, b ) => direction * ( Date.parse( a.timestamp ) - Date.parse( b.timestamp ) ||
            a.revid - b.revid ) ).slice( 0, Number( args.rvlimit ) );
        return response( revisions );
    }
    const oldWindow = boundedHistory( { rvdir: 'newer', rvstart: STAMP, rvlimit: 101 } ).query.pages[ 0 ].revisions;
    assert.equal( oldWindow.filter( revision => revision.revid > 105 ).length, 0,
        'Fixture reproduces ascending-window loss after more than 101 same-second seen rows' );
    page = await visit( { local: stored( marker( 105, STAMP, 44 ) ), responses: [ boundedHistory ] } );
    checkCount( page, '1', 'Newest-first bounded query retains unseen same-second revision after 105 seen rows' );
    assert.equal( page.calls.length, 1, 'Same-second boundary requires no pagination' );
    checkQuery( page.calls[ 0 ], true ); page.close();
    for ( const count of [ 0, 1, 99, 100, 101 ] ) {
        page = await visit( { response: { ...response( Array.from( { length: count }, ( _, i ) => row( i + 1 ) ) ),
            continue: { rvcontinue: 'synthetic-continue' } } } );
        checkCount( page, count === 0 ? null : count > 99 ? '99+' : String( count ), 'Count boundary ' + count );
        assert.equal( page.calls.length, 1, 'No pagination after bounded query' );
        page.close();
    }

    const talkConfig = { wgEQLTalkIsTalkPage: true, wgNamespaceNumber: 1 };
    page = await visit( { config: talkConfig } );
    const read = JSON.parse( page.get( key() ) );
    assert.equal( read.revision, 700 );
    assert.equal( Date.parse( read.timestamp ), Date.parse( STAMP ) );
    assert.equal( read.pageId, 44 );
    assert.equal( page.calls.length, 0, 'Current Talk view needs no history request' );
    checkCount( page, null, 'Viewing current Talk marks its displayed revision read' );
    const seenLocal = page.snapshot(); page.close();
    page = await visit( { local: seenLocal } );
    checkCount( page, '2', 'Later changes remain unread after viewing displayed Talk revision' );
    page.close();

    for ( const gate of [
        { name: 'edit', config: { wgAction: 'edit' } },
        { name: 'history', config: { wgAction: 'history' } },
        { name: 'old revision', config: { wgRevisionId: 100 } },
        { name: 'oldid URL', query: '?oldid=700' },
        { name: 'diff URL', query: '?diff=prev&oldid=700' },
        { name: 'visual editor URL', query: '?veaction=edit' },
        { name: 'missing parsed content', parser: false },
        { name: 'invalid current timestamp', config: { wgEQLTalkRevisionTimestamp: 'invalid' } },
        { name: 'zero revision', config: { wgRevisionId: 0, wgCurRevisionId: 0 } }
    ] ) {
        page = await visit( { ...gate, config: { ...talkConfig, ...gate.config } } );
        assert.equal( page.get( key() ), null, gate.name + ': does not mark read' );
        assert.equal( page.calls.length, 0, gate.name + ': no unnecessary history request' );
        checkCount( page, null, gate.name );
        page.close();
    }
    page = await visit( { config: talkConfig, visible: false } );
    assert.equal( page.get( key() ), null, 'Background Talk does not mark read' );
    await page.setVisible( true );
    assert.equal( JSON.parse( page.get( key() ) ).revision, 700, 'Visible Talk marks displayed revision read' );
    cases++; page.close();
    page = await visit( { config: talkConfig, local: stored( marker( 910, LATER, 44 ) ) } );
    assert.equal( JSON.parse( page.get( key() ) ).revision, 910, 'Talk read marker cannot regress' );
    page.content(); await page.tick();
    assert.equal( JSON.parse( page.get( key() ) ).revision, 910, 'Repeated marking remains monotonic' );
    cases++; page.close();

    page = await visit( { config: talkConfig, local: stored( marker( 910, LATER ) ) } );
    assert.equal( JSON.parse( page.get( key() ) ).revision, 910,
        'Accepted legacy marker without pageId cannot regress on an older current view' );
    cases++; page.close();
    const sharedLocal = { ...stored( marker( 910, LATER ) ),
        [ key( 12 ) ]: JSON.stringify( marker( 700, NEXT ) ),
        [ key( 34 ) ]: JSON.stringify( marker( 100 ) ),
        [ key( null, 'Talk:Another_page' ) ]: JSON.stringify( marker( 910, LATER ) ),
        [ key( null, TALK, 'anotherwiki' ) ]: JSON.stringify( marker( 910, LATER ) ) };
    for ( const [ user, count ] of [ [ null, null ], [ 0, null ], [ 12, '1' ], [ 34, '2' ], [ 56, '3' ] ] ) {
        page = await visit( { local: sharedLocal, config: { wgUserId: user } } );
        checkCount( page, count, 'Isolated visitor/account ' + user );
        assert.deepEqual( page.snapshot(), sharedLocal, 'No automatic account/visitor import or mutation' );
        page.close();
    }
    page = await visit( { local: sharedLocal, config: { ...talkConfig, wgUserId: 12, wgWikiID: 'fixture:other' } } );
    assert.equal( JSON.parse( page.get( key( 12, TALK, 'fixture:other' ) ) ).revision, 700, 'Wiki identity is escaped in state key' );
    assert.equal( page.get( key() ), sharedLocal[ key() ], 'Other wiki marking preserves anonymous state' );
    cases++; page.close();

    page = await visit( { local: stored( marker( 910, LATER, 9 ) ), responses: [
        response( [], { pageid: 44 } ), response( initialRows, { pageid: 44 } )
    ] } );
    checkCount( page, '3', 'Deleted/recreated Talk uses new page history' );
    assert.equal( page.calls.length, 2, 'Recreated-page fallback uses exactly two bounded requests' );
    checkQuery( page.calls[ 0 ], true ); checkQuery( page.calls[ 1 ], false );
    page.close();
    page = await visit( { local: stored( marker( 700, NEXT, 44 ) ) } );
    checkCount( page, '1', 'Same page identity uses timestamp query' );
    assert.equal( page.calls.length, 1 ); page.close();

    const destination = 'Talk:Redirect_destination';
    const destinationRows = [ row( 100 ), row( 700, NEXT ), row( 910, LATER ) ];
    const redirected = response( destinationRows, { title: 'Talk:Redirect destination', pageid: 55 } );
    const destinationLocal = { [ key( null, destination ) ]: JSON.stringify( marker( 700, NEXT, 55 ) ) };
    page = await visit( { local: destinationLocal, response: redirected, responses: [ redirected, redirected ] } );
    checkCount( page, '1', 'Talk redirect uses destination seen marker and normalizes spaces' );
    assert.equal( page.calls.length, 2, 'Talk redirect reruns one bounded destination query' );
    checkQuery( page.calls[ 0 ], false ); checkQuery( page.calls[ 1 ], true, destination );
    assert.equal( Date.parse( page.calls[ 1 ].rvend ), Date.parse( NEXT ) );
    assert.deepEqual( page.snapshot(), destinationLocal, 'Redirect counting does not rewrite seen state' );
    await page.storage( key( null, destination ), JSON.stringify( marker( 910, LATER, 55 ) ) );
    checkCount( page, null, 'Destination storage event refreshes redirected Talk badge' ); page.close();
    page = await visit( { local: stored( marker( 910, LATER, 44 ) ), responses: [ redirected, redirected ] } );
    checkCount( page, '3', 'Source marker cannot mark redirect destination read' );
    checkQuery( page.calls[ 1 ], false, destination ); page.close();
    page = await visit( { responses: [ response( initialRows, { ns: 0, title: 'Synthetic subject' } ) ] } );
    checkCount( page, null, 'Talk redirect to a subject namespace is ignored' );
    assert.equal( page.calls.length, 1, 'Subject redirect receives no destination query' ); page.close();
    page = await visit( { responses: [ redirected, response( destinationRows, { title: destination, actions: { read: false } } ) ] } );
    checkCount( page, null, 'Unreadable redirect destination stays quiet' ); page.close();
    for ( const bad of [ 'not-json', '{}', 'null', '{"revision":0,"timestamp":"2026-10-08T12:00:00Z"}',
        '{"revision":700,"timestamp":"invalid"}', '{"revision":-1,"timestamp":"2026-10-08T12:00:00Z"}' ] ) {
        page = await visit( { local: { [ key() ]: bad } } );
        checkCount( page, '3', 'Invalid stored marker falls back to unseen visit' );
        checkQuery( page.calls[ 0 ], false ); page.close();
    }
    page = await visit( { blockStorage: true } );
    checkCount( page, '3', 'Blocked storage permits browser-local unread fallback' );
    page.close();
    page = await visit( { config: talkConfig, blockStorage: true } );
    checkCount( page, null, 'Blocked storage does not break Talk view' ); page.close();

    for ( const badResponse of [ new Error( 'Synthetic API unavailable' ), {}, { error: { code: 'synthetic' } },
        { query: { pages: [] } }, response( [], { missing: true } ), response( [], { invalid: true } ),
        response( initialRows, { actions: { read: false } } ), response( initialRows, { actions: undefined } ),
        response( initialRows, { actions: { read: 'true' } } ), response( initialRows, { actions: {} } ),
        response( initialRows, { ns: 0 } ), response( initialRows, { ns: -1 } ),
        response( [ { revid: 700, timestamp: 'invalid' }, { revid: -1, timestamp: STAMP } ] ) ] ) {
        page = await visit( { response: badResponse, local: stored( marker() ) } );
        checkCount( page, null, 'Unavailable/invalid revision data is quiet' );
        assert.equal( page.get( key() ), JSON.stringify( marker() ), 'Failed query preserves valid seen state' );
        page.close();
    }
    for ( const skip of [ { config: { wgEQLTalkPageName: null } }, { config: { wgEQLTalkPageName: '' } },
        { markup: '<a href="/wiki/Other">Other</a>' }, { config: { wgAction: 'history' } }, { skin: 'skin-vector' } ] ) {
        page = await visit( skip );
        assert.equal( page.calls.length, 0, 'Unsupported view/link makes no query' );
        checkCount( page, null, 'Unsupported view/link stays quiet' ); page.close();
    }

    page = await visit();
    await page.storage( key( 12 ), JSON.stringify( marker( 910, LATER ) ) );
    assert.equal( page.calls.length, 1, 'Unrelated identity storage event ignored' );
    await page.storage( key(), JSON.stringify( marker( 910, LATER ) ) );
    checkCount( page, null, 'Another tab reading Talk clears unseen badge' );
    const afterStorage = page.calls.length;
    await page.pageshow( false );
    assert.equal( page.calls.length, afterStorage, 'Ordinary pageshow does not duplicate startup' );
    await page.pageshow( true );
    assert.equal( page.calls.length, afterStorage + 1, 'Back/forward restore refreshes count' );
    checkCount( page, null, 'Restored subject honors current seen marker' ); page.close();

    const pending = deferred();
    page = await visit( { response: pending.promise } );
    assert.equal( page.calls.length, 1 );
    checkCount( page, null, 'Pending API does not fabricate a count' );
    await page.storage( key(), JSON.stringify( marker( 910, LATER ) ) );
    pending.resolve( response( initialRows ) ); await page.tick();
    checkCount( page, null, 'Delayed API cannot restore changes already read in another tab' ); page.close();

    const oldRequest = deferred();
    page = await visit( { responses: [ oldRequest.promise, response( initialRows ) ] } );
    await page.storage( key(), JSON.stringify( marker( 910, LATER ) ) );
    checkCount( page, null, 'Newer refresh respects updated seen state' );
    oldRequest.resolve( response( initialRows ) ); await page.tick();
    checkCount( page, null, 'Stale earlier response cannot overwrite newer refresh' ); page.close();

    page = await visit( { markup: '<div id="p-views"><ul>' +
        '<li id="ca-talk"><a href="/wiki/Talk:Synthetic_example">Talk</a></li>' +
        '<li id="ca-view"><a href="/wiki/Synthetic_example">Read</a></li></ul></div>' +
        '<div id="p-cactions"><ul><li id="ca-more-talk">' +
        '<a href="/wiki/Talk:Synthetic_example">Talk</a></li></ul></div>' } );
    checkCount( page, '3', 'Talk before Read and its distinct overflow link receive the same count' );
    assert.equal( page.w.document.querySelector( '#ca-view .eql-talk-unread-badge' ), null,
        'Read action does not receive the Talk counter' );
    page.close();
    page = await visit( { markup: '<a id="ca-talk-sticky-header" href="/wiki/Talk:Synthetic_example">Talk</a>' } );
    checkCount( page, '3', 'Sticky-header Talk link receives accessible badge' ); page.close();
    page = await visit( { visible: false } );
    assert.equal( page.calls.length, 0, 'Hidden subject defers unread query' );
    await page.setVisible( true );
    checkCount( page, '3', 'Visible subject fetches deferred count' );
    assert.equal( page.calls.length, 1 ); page.close();

    page = await visit( { markup: '<li id="ca-talk"><a href="/wiki/Talk:Synthetic_example" ' +
        'aria-label="Open Talk" title="Visit discussion"><span>Talk</span></a></li>' } );
    checkCount( page, '3', 'Existing accessible labels retain unread description' );
    await page.storage( key(), JSON.stringify( marker( 910, LATER ) ) );
    checkCount( page, null, 'Clearing unread badge restores navigation attributes' );
    assert.equal( page.anchors()[ 0 ].getAttribute( 'aria-label' ), 'Open Talk' );
    assert.equal( page.anchors()[ 0 ].getAttribute( 'title' ), 'Visit discussion' );
    assert.equal( page.anchors()[ 0 ].querySelector( 'span' ).textContent, 'Talk' ); page.close();
    page = await visit( { reducedMotion: true } );
    checkCount( page, '3', 'Reduced-motion preference preserves count and access' ); page.close();
    console.log( 'Passed ' + cases + ' synthetic Talk unread-count, visit-state, identity, lifecycle and API cases.' );
    console.log( 'Visual animation, live ResourceLoader/namespace metadata, and real API permissions require browser integration checks.' );
}

main().catch( error => { console.error( error ); process.exitCode = 1; } );
