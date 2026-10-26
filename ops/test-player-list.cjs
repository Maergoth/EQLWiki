'use strict';
// Entirely synthetic public records; no wiki, account, browser storage or network.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/resources/player-list.js' ), 'utf8' );
const css = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/resources/player-list.css' ), 'utf8' );
const url = title => '/wiki/' + encodeURIComponent( title.replace( / /g, '_' ) );
const player = ( id, name, extra = {} ) => ( { id, name, server: 'Pine', level: 50, classes: [ 'WAR' ], aa: 4, totalTier: 40, equippedSlots: 20, tieredSlots: 20, stats: {}, updated: '20261025010203', pageTitle: 'User:Owner' + id, pageUrl: url( 'User:Owner' + id ) + '#Character', ...extra } );
const players = [
    player( 'a', 'Aster', { classes: [ 'Warrior', 'Cleric' ], aa: 9, totalTier: 140, stats: { hp: 1000, mp: null, atk_accuracy: 500, accuracy: 5 }, pageTitle: 'User:Alice', pageUrl: url( 'User:Alice' ) + '#Aster' } ),
    player( 'b', 'Bracken', { level: 49, aa: 0, totalTier: 90, stats: { hp: 0, mp: 300 } } ),
    player( 'c', 'Cinder', { server: 'Cedar', classes: [ 'PAL', 'CLR' ], totalTier: null, stats: { hp: null } } ),
    player( 'd', 'Dusk', { server: 'Cedar', classes: [ 'WAR', 'CLR', 'DRU' ], totalTier: 140, stats: { hp: 1500 } } ),
    player( 'e', '<img src=x onerror="window.attacked=1">', { server: '<b>Realm</b>', classes: [ 'Rogue' ], totalTier: 0, aa: null, stats: { hp: null }, pageUrl: 'javascript:window.attacked=1' } ),
    player( 'f', 'Fenn', { server: 'Cedar', level: null, classes: [ 'Shadow Knight', 'Enchanter' ], totalTier: 30, aa: '', stats: { hp: 50, mp: '', useful_counter: 7 } } )
];
const response = ( records = players, continuation ) => ( { eqlplayers: { players: records, maxLevel: 50, ...( continuation === undefined ? {} : { continue: continuation } ) } } );
const turn = () => new Promise( resolve => setImmediate( resolve ) );
async function until( predicate, label ) { for ( let i = 0; i < 40; i++ ) { if ( predicate() ) { return; } await turn(); } assert.fail( 'Did not settle: ' + label ); }
function fixture( get = () => response() ) {
    const dom = new JSDOM( '<head><style>' + css + '</style></head><body><main><div id="eql-player-list" class="eql-player-list"><noscript>Enable JavaScript.</noscript></div></main></body>', { url: 'https://wiki.example/Player_List', runScripts: 'outside-only' } );
    const window = dom.window, document = window.document, calls = [], hooks = [];
    window.mw = { hook: name => { assert.equal( name, 'wikipage.content' ); return { add: fn => hooks.push( fn ) }; }, util: { getUrl: url }, Api: function () { this.get = params => { calls.push( { ...params } ); return get( params, calls.length ); }; } };
    window.eval( source ); document.dispatchEvent( new window.Event( 'DOMContentLoaded' ) );
    const root = () => document.getElementById( 'eql-player-list' );
    const rows = () => [ ...root().querySelectorAll( 'tbody tr' ) ];
    const ids = () => rows().map( row => row.dataset.playerId );
    const set = ( selector, value, event = 'change' ) => { const control = root().querySelector( selector ); assert( control, selector ); if ( control.type === 'checkbox' ) { control.checked = value; } else { control.value = value; } control.dispatchEvent( new window.Event( event, { bubbles: true } ) ); return control; };
    const loaded = () => until( () => root().getAttribute( 'aria-busy' ) === 'false', 'directory response' );
    return { dom, window, document, root, rows, ids, calls, hooks, set, loaded, close: () => dom.window.close() };
}
( async () => {
    const f = fixture(); await f.loaded();
    assert.deepEqual( f.ids(), [ 'a', 'd', 'b', 'f', 'e', 'c' ], 'Complete default tier ranking, stable name ties, unknown last.' );
    assert.equal( f.root().querySelectorAll( '[data-player-class]' ).length, 16 );
    assert.equal( f.root().querySelector( '[data-filter=match-all]' ).checked, true );
    assert.match( f.root().querySelector( '.eql-pl-maximum' ).textContent, /50/ );
    assert.match( f.root().querySelector( '.eql-pl-summary' ).textContent, /6characters3servers/ );
    assert.equal( f.rows().find( row => row.dataset.playerId === 'b' ).children[ 4 ].textContent, '0', 'Reported zero stays zero.' );
    assert.equal( f.rows().find( row => row.dataset.playerId === 'f' ).children[ 4 ].textContent, '—', 'Empty strings never invent numeric zero.' );
    assert.equal( f.rows().find( row => row.dataset.playerId === 'c' ).children[ 0 ].textContent, '—', 'Unknown total tier is visible but unranked.' );
    f.set( 'input[type=search]', 'alice', 'input' ); assert.deepEqual( f.ids(), [ 'a' ], 'Search includes public wiki user title.' );
    f.set( 'input[type=search]', 'DUSK', 'input' ); assert.deepEqual( f.ids(), [ 'd' ] );
    f.set( 'input[type=search]', '', 'input' ); f.set( '.eql-pl-filter-row select', 'Cedar' ); assert.deepEqual( f.ids(), [ 'd', 'f', 'c' ] );
    f.set( '[data-filter=maximum]', true ); assert.deepEqual( f.ids(), [ 'd', 'c' ], 'Unknown levels and below-cap levels are excluded.' );
    f.set( '.eql-pl-filter-row select', '' ); f.set( '[data-filter=maximum]', false );
    f.set( '[data-player-class=WAR]', true ); f.set( '[data-player-class=CLR]', true ); assert.deepEqual( f.ids(), [ 'a', 'd' ], 'AND allows a selected multi-class combination, including additional classes.' );
    f.set( '[data-filter=match-all]', false ); assert.deepEqual( f.ids(), [ 'a', 'd', 'b', 'c' ], 'Unchecked means any selected class.' );
    f.root().querySelector( '.eql-pl-class-actions button' ).click(); f.set( '[data-player-class=SHD]', true ); assert.deepEqual( f.ids(), [ 'f' ], 'Full backend class names normalize to canonical codes.' );
    f.root().querySelector( '.eql-pl-class-actions button' ).click();
    f.set( '.eql-pl-ordering select', 'hp' ); assert.deepEqual( f.ids().slice( 0, 4 ), [ 'd', 'a', 'f', 'b' ] );
    assert.equal( f.rows().find( row => row.dataset.playerId === 'c' ).children[ 0 ].textContent, '—', 'Unknown HP is unranked in descending order.' );
    assert.equal( f.root().querySelector( 'thead th:last-child' ).getAttribute( 'aria-sort' ), 'descending' );
    f.root().querySelector( '.eql-pl-direction' ).click(); assert.deepEqual( f.ids().slice( 0, 4 ), [ 'b', 'f', 'a', 'd' ] ); assert.deepEqual( new Set( f.ids().slice( 4 ) ), new Set( [ 'c', 'e' ] ), 'Nulls remain last in ascending order.' );
    assert.equal( f.rows().find( row => row.dataset.playerId === 'c' ).children[ 0 ].textContent, '—', 'Unknown HP remains unranked in ascending order.' );
    f.root().querySelector( '[data-sort=level]' ).focus(); f.root().querySelector( '[data-sort=level]' ).click(); assert.equal( f.document.activeElement.dataset.sort, 'level', 'Changing table columns preserves the activated header keyboard focus.' ); assert.equal( f.root().querySelector( '[data-sort=level]' ).parentNode.getAttribute( 'aria-sort' ), 'descending' ); assert.equal( f.ids().at( -1 ), 'f' );
    assert.equal( f.root().querySelectorAll( 'thead th' ).length, 6, 'Selecting an existing character column removes the additional stat column.' );
    f.set( '.eql-pl-ordering select', 'atk_accuracy' ); assert.match( f.root().querySelector( 'thead' ).textContent, /ATK accuracy/ ); assert.equal( f.ids()[ 0 ], 'a' );
    f.set( '.eql-pl-ordering select', 'accuracy' ); assert.match( f.root().querySelector( 'thead' ).textContent, /Accuracy bonus/ );
    f.set( '.eql-pl-ordering select', 'useful_counter' ); assert.equal( f.ids()[ 0 ], 'f', 'Additional published numeric keys are selectable.' );
    f.set( 'input[type=search]', 'no matching character', 'input' ); assert.equal( f.rows().length, 0 ); assert.equal( f.root().querySelector( '.eql-pl-empty' ).hidden, false );
    f.root().querySelector( '.eql-pl-empty button' ).click(); assert.equal( f.rows().length, 6 ); assert.equal( f.root().querySelector( '[data-filter=match-all]' ).checked, true ); assert.equal( f.document.activeElement.type, 'search' );
    assert.equal( f.root().querySelectorAll( 'script,img,b' ).length, 0, 'Untrusted names/server strings remain text, never markup.' );
    assert( f.root().textContent.includes( '<img src=x' ) ); assert( f.root().textContent.includes( '<b>Realm</b>' ) ); assert.equal( f.window.attacked, undefined );
    for ( const link of f.root().querySelectorAll( 'a' ) ) { assert.equal( new URL( link.href ).origin, 'https://wiki.example' ); assert.equal( new URL( link.href ).protocol, 'https:' ); }
    const requests = f.calls.length; f.hooks.forEach( hook => { hook( [ f.root() ] ); hook( [ f.document.querySelector( 'main' ) ] ); } ); f.window.eval( source ); f.hooks.forEach( hook => hook( [ f.root() ] ) ); await turn(); assert.equal( f.calls.length, requests, 'Repeated content hooks/module evaluation do not duplicate requests or mounts.' ); assert.equal( f.root().querySelectorAll( '.eql-pl-hero' ).length, 1 );
    f.close();

    let releaseLast;
    const paged = fixture( ( params, index ) => index === 1 ? response( players.slice( 0, 2 ), 'first' ) : index === 2 ? response( [], 'second' ) : new Promise( resolve => { releaseLast = resolve; } ) );
    await until( () => paged.calls.length === 3, 'all continuation pages requested' ); assert.equal( paged.rows().length, 0, 'A pending last page cannot expose partial rankings.' );
    assert.equal( paged.calls[ 1 ].continue, 'first' ); assert.equal( paged.calls[ 2 ].continue, 'second' ); assert.equal( paged.calls[ 0 ].limit, 25 );
    releaseLast( response( [ ...players.slice( 2 ), { ...players[ 0 ], totalTier: 10000 } ] ) ); await paged.loaded(); assert.equal( paged.rows().length, 6, 'Overlap between pages never duplicates characters.' ); assert.deepEqual( paged.ids().slice( 0, 2 ), [ 'a', 'd' ] ); paged.close();

    const failure = fixture( ( params, index ) => index === 1 ? Promise.reject( Error( 'private backend detail' ) ) : response( [ players[ 1 ] ] ) ); await failure.loaded();
    assert.equal( failure.rows().length, 0 ); assert.equal( failure.root().querySelector( '.eql-pl-retry' ).hidden, false ); assert.doesNotMatch( failure.root().textContent, /private backend detail/ );
    failure.root().querySelector( '.eql-pl-retry' ).click(); await failure.loaded(); assert.deepEqual( failure.ids(), [ 'b' ] ); assert.equal( failure.calls[ 1 ].continue, undefined ); failure.close();

    const partial = fixture( ( params, index ) => index === 1 ? response( players.slice( 0, 2 ), 'next' ) : index === 2 ? Promise.reject( Error( 'network' ) ) : response( [ players[ 3 ] ] ) ); await partial.loaded(); assert.equal( partial.rows().length, 0 ); assert.match( partial.root().querySelector( '.eql-pl-status' ).textContent, /No partial rankings/ ); partial.root().querySelector( '.eql-pl-retry' ).click(); await partial.loaded(); assert.deepEqual( partial.ids(), [ 'd' ], 'Retry starts a fresh list, without retaining partial previous results.' ); partial.close();

    const looping = fixture( () => response( [ players[ 0 ] ], 'same-token' ) ); await looping.loaded(); assert.equal( looping.calls.length, 2 ); assert.equal( looping.rows().length, 0 ); assert.equal( looping.root().querySelector( '.eql-pl-status' ).classList.contains( 'is-error' ), true ); looping.close();

    let oldResponse;
    const stale = fixture( ( params, index ) => index === 1 ? new Promise( resolve => { oldResponse = resolve; } ) : response( [ players[ 1 ] ] ) ); await until( () => stale.calls.length === 1, 'old request begun' );
    const oldRoot = stale.root(); oldRoot.remove(); const replacement = stale.document.createElement( 'div' ); replacement.id = 'eql-player-list'; replacement.className = 'eql-player-list'; stale.document.querySelector( 'main' ).appendChild( replacement ); stale.hooks.forEach( hook => hook( [ replacement ] ) ); await stale.loaded();
    oldResponse( response( [ players[ 0 ] ], 'must-not-read' ) ); await turn(); await turn(); assert.deepEqual( stale.ids(), [ 'b' ] ); assert.equal( stale.calls.length, 2, 'Detached response cannot overwrite a replacement or request another continuation.' );
    replacement.remove(); stale.document.querySelector( 'main' ).appendChild( oldRoot ); stale.hooks.forEach( hook => hook( [ oldRoot ] ) ); await stale.loaded(); assert.deepEqual( stale.ids(), [ 'b' ], 'A retired mount can initialize again if its original element returns.' ); stale.close();

    const empty = fixture( () => response( [] ) ); await empty.loaded(); assert.match( empty.root().querySelector( '.eql-pl-empty' ).textContent, /No public character snapshots yet/ ); assert.match( empty.root().querySelector( '.eql-pl-result-count' ).textContent, /0 of 0/ ); empty.close();
    const many = fixture( () => response( Array.from( { length: 51 }, ( _, index ) => player( 'many-' + index, 'Character ' + index, { totalTier: index } ) ) ) ); await many.loaded(); assert.equal( many.rows().length, 50 ); many.root().querySelector( '.eql-pl-pagination button:last-child' ).click(); assert.equal( many.rows().length, 1 ); assert.equal( many.rows()[ 0 ].children[ 0 ].textContent, '51' ); many.set( 'input[type=search]', 'Character 50', 'input' ); assert.equal( many.rows().length, 1 ); assert.equal( many.rows()[ 0 ].children[ 0 ].textContent, '1' ); many.close();

    assert.doesNotMatch( source, /innerHTML|localStorage|fetch\s*\(/, 'Directory uses secure DOM construction and only the registered read-only wiki API.' );
    assert.match( css, /\.eql-pl-table-scroll\{[^}]*overflow-x:auto/ ); assert.match( css, /@media\(max-width:700px\)/ ); assert.doesNotMatch( css, /@import|url\(/ );
    console.log( 'Player List DOM passed: complete paginated rankings, all stats and distinct Accuracy fields, class AND/OR/server/name/user/max-level filters, stable ties/null-last sorting, bounded result pages, secure local links, errors/retry, detached-response guards and dynamic idempotence.' );
} )().catch( error => { console.error( error ); process.exitCode = 1; } );
