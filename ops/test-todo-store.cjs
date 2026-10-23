'use strict';

// Synthetic persistence regression. No wiki requests, real accounts, or database writes.
// Run from the repository root: node ops/test-todo-store.cjs
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( path.join( __dirname,
    '../skins/EQLImmersive/resources/todo-store.js' ), 'utf8' );
const OPTION = 'userjs-eql-todo-v1';
const KEY = 'eql-todo-v1:syntheticwiki:anon';
const encode = items => JSON.stringify( { version: 1, items } );
const plain = value => JSON.parse( JSON.stringify( value ) );
let cases = 0;

function deferred() {
    let resolve, reject;
    const promise = new Promise( ( done, fail ) => { resolve = done; reject = fail; } );
    return { promise, resolve, reject };
}

function visit( options = {} ) {
    const config = { wgUserId: 0, wgUserName: null, wgUserIsTemp: false,
        wgWikiID: 'syntheticwiki', ...options.config };
    const local = new Map( Object.entries( options.local || {} ) );
    const events = {};
    const timers = new Map();
    const reads = [], writes = [], localReads = [], localWrites = [];
    let timerId = 0;
    let localBlocked = Boolean( options.localBlocked );
    let getResults = ( options.getResults || [] ).slice();
    let postResults = ( options.postResults || [] ).slice();
    const accountOptions = { ...options.accountOptions };
    const info = value => ( { query: { userinfo: {
        id: config.wgUserId, name: config.wgUserName, options: value || {}
    } } } );
    function result( value ) {
        return value instanceof Error ? Promise.reject( value ) : Promise.resolve( value );
    }
    const api = {
        get( params ) {
            reads.push( plain( params ) );
            return result( getResults.length ? getResults.shift() : info( accountOptions ) );
        },
        postWithToken( token, params ) {
            writes.push( { token, ...plain( params ) } );
            return result( postResults.length ? postResults.shift() : { options: 'success' } ).then( value => {
                if ( value && value.options === 'success' && !value.warnings && !value.error ) {
                    accountOptions[ params.optionname ] = params.optionvalue;
                }
                return value;
            } );
        }
    };
    const window = {
        location: { host: 'wiki.example' },
        setTimeout( callback ) { timers.set( ++timerId, callback ); return timerId; },
        clearTimeout( id ) { timers.delete( id ); },
        addEventListener( event, callback ) { events[ event ] = callback; },
        localStorage: {
            getItem( key ) {
                localReads.push( key );
                if ( localBlocked ) { throw new Error( 'Storage disabled' ); }
                return local.get( key ) || null;
            },
            setItem( key, value ) {
                if ( localBlocked ) { throw new Error( 'Storage disabled' ); }
                localWrites.push( { key, value } );
                local.set( key, value );
            }
        }
    };
    const mw = {
        config: { get: key => config[ key ] },
        Api: function () { return api; },
        log: { error() {} },
        user: { options: { get() { throw new Error( 'Cached account options are not authoritative' ); } } }
    };
    vm.runInNewContext( source, { window, mw, Promise, URL, console } );
    return { store: window.EQLTodoStore, config, local, events, reads, writes, localReads, localWrites, accountOptions,
        setLocalBlocked( value ) { localBlocked = value; },
        queueGet( value ) { getResults.push( value ); },
        queuePost( value ) { postResults.push( value ); },
        async debounce() {
            const callbacks = [ ...timers.values() ];
            timers.clear();
            callbacks.forEach( callback => callback() );
            // One turn also drains the store's native promise chain.
            await new Promise( resolve => setImmediate( resolve ) );
        }
    };
}

async function test( name, callback ) {
    await callback();
    cases++;
    console.log( 'ok ' + cases + ' - ' + name );
}

( async () => {
    await test( 'state is lazy, reads once, and refuses edits before ready', async () => {
        const f = visit();
        assert.equal( f.reads.length + f.localReads.length, 0 );
        assert.equal( f.store.getStatus().state, 'idle' );
        assert.throws( () => f.store.add( { title: 'Synthetic' } ), { code: 'not-ready' } );
        await Promise.all( [ f.store.ready(), f.store.ready() ] );
        assert.equal( f.localReads.length, 1 );
        assert.equal( f.store.getStatus().state, 'saved' );
        assert.equal( f.store.getStatus().canWrite, true );
        assert.equal( f.localWrites.length, 0 );
    } );

    await test( 'anonymous list spans pages and never writes the account API', async () => {
        const f = visit();
        await f.store.ready();
        const item = f.store.add( { title: 'Synthetic_Page' } );
        assert.equal( item.title, 'Synthetic Page' );
        await f.store.flush();
        assert.equal( f.localWrites[ 0 ].key, KEY );
        assert.equal( f.reads.length + f.writes.length, 0 );
        const next = visit( { local: Object.fromEntries( f.local ) } );
        await next.store.ready();
        assert.equal( next.store.getItems()[ 0 ].id, item.id );
    } );

    await test( 'canonical source IDs deduplicate pages and distinguish anchored headers', async () => {
        const f = visit();
        await f.store.ready();
        const page = f.store.add( { title: 'Synthetic_Page' } );
        const same = f.store.add( { title: ' Synthetic Page ' } );
        assert.equal( page.id, same.id );
        const section = f.store.add( { title: 'Synthetic Page', section: 'Heading_2', sectionTitle: 'Heading 2' } );
        assert.notEqual( page.id, section.id );
        assert.equal( f.store.getItems().length, 2 );
        await f.store.flush();
    } );

    await test( 'progress and checkbox state agree in both directions', async () => {
        const f = visit();
        await f.store.ready();
        const { id } = f.store.add( { title: 'Progress' } );
        assert.equal( f.store.update( id, { progress: 100, slider: true } ).done, true );
        assert.equal( f.store.update( id, { progress: 55 } ).done, false );
        assert.equal( f.store.update( id, { done: true } ).progress, 100 );
        assert.equal( f.store.update( id, { done: false } ).progress, 0 );
        const hidden = f.store.update( id, { collapsed: true, slider: false, notes: '<p>Own notes</p>' } );
        assert.equal( hidden.collapsed, true );
        assert.equal( hidden.notes, '<p>Own notes</p>' );
        await f.store.flush();
    } );

    await test( 'account state is loaded fresh and never imports anonymous state', async () => {
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' },
            local: { [ KEY ]: encode( [ { title: 'Anonymous only' } ] ) },
            accountOptions: { [ OPTION ]: encode( [ { title: 'Account only' } ] ) } } );
        await f.store.ready();
        assert.equal( f.store.getItems()[ 0 ].title, 'Account only' );
        assert.equal( f.localReads.length, 0 );
        assert.equal( f.reads[ 0 ].uiprop, 'options' );
        assert.equal( f.reads[ 0 ].assert, 'user' );
        assert.equal( f.reads[ 0 ].assertuser, 'Synthetic User' );
        assert.equal( f.store.getStatus().account, true );
    } );

    await test( 'account without a saved option starts empty without importing anonymous entries', async () => {
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' },
            local: { [ KEY ]: encode( [ { title: 'Anonymous only' } ] ) } } );
        await f.store.ready();
        assert.equal( f.store.getItems().length, 0 );
        assert.equal( f.localReads.length + f.writes.length, 0 );
    } );

    await test( 'account writes assert identity and preserve literal pipes in rich text', async () => {
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' } } );
        await f.store.ready();
        const entry = f.store.add( { title: 'Account page' } );
        f.store.update( entry.id, { notes: '<p>one | two = three</p>' } );
        assert.equal( f.writes.length, 0 );
        await f.debounce();
        assert.equal( f.writes.length, 1 );
        const write = f.writes[ 0 ];
        assert.equal( write.token, 'csrf' );
        assert.equal( write.optionname, OPTION );
        assert.equal( write.assert, 'user' );
        assert.equal( write.assertuser, 'Synthetic User' );
        assert.equal( 'change' in write, false );
        assert.equal( JSON.parse( write.optionvalue ).items[ 0 ].notes, '<p>one | two = three</p>' );
        assert.equal( f.store.getStatus().dirty, false );
    } );

    await test( 'failed load blocks all writes until an explicit successful retry', async () => {
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' },
            getResults: [ new Error( 'Network failed' ) ] } );
        await assert.rejects( f.store.ready() );
        assert.equal( f.store.getStatus().ready, false );
        assert.equal( f.store.getStatus().retry, true );
        assert.throws( () => f.store.add( { title: 'Blocked' } ), { code: 'not-ready' } );
        assert.equal( f.writes.length, 0 );
        await f.store.retry();
        assert.equal( f.store.getStatus().ready, true );
        assert.equal( f.reads.length, 2 );
    } );

    await test( 'wrong-account API result cannot replace or create account data', async () => {
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' },
            getResults: [ { query: { userinfo: { id: 8, name: 'Other User', options: {} } } } ] } );
        await assert.rejects( f.store.ready(), { code: 'identity' } );
        assert.equal( f.store.getStatus().canWrite, false );
        assert.equal( f.writes.length, 0 );
    } );

    await test( 'malformed saved state is left untouched and blocks replacement', async () => {
        for ( const value of [ '{broken', encode( [ { title: 'Duplicate' }, { title: 'Duplicate' } ] ),
            JSON.stringify( { version: 2, items: [] } ), encode( [ { title: 123 } ] ) ] ) {
            const f = visit( { local: { [ KEY ]: value } } );
            await assert.rejects( f.store.ready() );
            assert.equal( f.store.getStatus().canWrite, false );
            assert.equal( f.local.get( KEY ), value );
            assert.equal( f.localWrites.length, 0 );
        }
    } );

    await test( 'failed saves retain every local edit until explicit retry', async () => {
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' },
            postResults: [ new Error( 'Network failed' ) ] } );
        await f.store.ready();
        const entry = f.store.add( { title: 'Pending page' } );
        await assert.rejects( f.store.flush() );
        assert.equal( f.store.getStatus().state, 'error' );
        assert.equal( f.store.getStatus().dirty, true );
        f.store.update( entry.id, { notes: 'Retained after failure' } );
        await f.debounce();
        assert.equal( f.writes.length, 1 );
        await f.store.retry();
        assert.equal( f.writes.length, 2 );
        assert.equal( JSON.parse( f.writes[ 1 ].optionvalue ).items[ 0 ].notes, 'Retained after failure' );
        assert.equal( f.store.getStatus().dirty, false );
    } );

    await test( 'edits during a save are written in a second serialized request', async () => {
        const first = deferred();
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' }, postResults: [ first.promise ] } );
        await f.store.ready();
        const { id } = f.store.add( { title: 'Save race' } );
        const saving = f.store.flush();
        await new Promise( resolve => setImmediate( resolve ) );
        assert.equal( f.writes.length, 1 );
        f.store.update( id, { notes: 'Newer edit' } );
        const alsoSaving = f.store.flush();
        await Promise.resolve();
        assert.equal( f.writes.length, 1 );
        first.resolve( { options: 'success' } );
        await Promise.all( [ saving, alsoSaving ] );
        assert.equal( f.writes.length, 2 );
        assert.equal( JSON.parse( f.writes[ 1 ].optionvalue ).items[ 0 ].notes, 'Newer edit' );
        assert.equal( f.store.getStatus().state, 'saved' );
    } );

    await test( 'account baseline detects other-device edits and never overwrites them on retry', async () => {
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' } } );
        await f.store.ready();
        f.store.add( { title: 'Unsaved local draft', notes: 'Keep this text' } );
        const remote = encode( [ { title: 'Newer remote entry' } ] );
        f.accountOptions[ OPTION ] = remote;
        await assert.rejects( f.store.flush(), { code: 'conflict' } );
        assert.equal( f.store.getStatus().conflict, true );
        assert.equal( f.store.getStatus().canWrite, false );
        assert.equal( f.store.getStatus().retry, false );
        assert.equal( f.store.getItems()[ 0 ].notes, 'Keep this text' );
        await assert.rejects( f.store.retry(), { code: 'conflict' } );
        assert.equal( f.writes.length, 0 );
        assert.equal( f.accountOptions[ OPTION ], remote );
    } );

    await test( 'an edit made during a failed save cannot trigger an automatic retry', async () => {
        const first = deferred();
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' }, postResults: [ first.promise ] } );
        await f.store.ready();
        const { id } = f.store.add( { title: 'Save failure race' } );
        const saving = f.store.flush();
        await new Promise( resolve => setImmediate( resolve ) );
        f.store.update( id, { notes: 'Still keep this edit' } );
        first.reject( new Error( 'Request failed' ) );
        await assert.rejects( saving );
        await f.debounce();
        assert.equal( f.writes.length, 1 );
        assert.equal( f.store.getStatus().dirty, true );
        assert.equal( f.store.getItems()[ 0 ].notes, 'Still keep this edit' );
        await f.store.retry();
        assert.equal( f.writes.length, 2 );
    } );

    await test( 'anonymous baseline detects another-tab edits even without a storage event', async () => {
        const f = visit();
        await f.store.ready();
        f.store.add( { title: 'Local pending' } );
        const remote = encode( [ { title: 'Newer local storage' } ] );
        f.local.set( KEY, remote );
        await assert.rejects( f.store.flush(), { code: 'conflict' } );
        await assert.rejects( f.store.retry(), { code: 'conflict' } );
        assert.equal( f.localWrites.length, 0 );
        assert.equal( f.store.getItems()[ 0 ].title, 'Local pending' );
        assert.equal( f.local.get( KEY ), remote );
    } );

    await test( 'anonymous storage events refresh clean state and preserve dirty drafts', async () => {
        const f = visit();
        await f.store.ready();
        const remote = encode( [ { title: 'Other tab' } ] );
        f.local.set( KEY, remote );
        f.events.storage( { key: KEY, newValue: remote } );
        assert.equal( f.store.getItems()[ 0 ].title, 'Other tab' );
        const entry = f.store.getItems()[ 0 ];
        f.store.update( entry.id, { notes: 'Own pending notes' } );
        const newer = encode( [ { title: 'Changed elsewhere' } ] );
        f.local.set( KEY, newer );
        f.events.storage( { key: KEY, newValue: newer } );
        assert.equal( f.store.getItems()[ 0 ].notes, 'Own pending notes' );
        assert.equal( f.store.getStatus().conflict, true );
        await f.debounce();
        assert.equal( f.localWrites.length, 0 );
    } );

    await test( 'successful write with a lost response can be acknowledged without overwriting', async () => {
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' },
            postResults: [ new Error( 'Response lost' ) ] } );
        await f.store.ready();
        f.store.add( { title: 'Possibly saved' } );
        await assert.rejects( f.store.flush() );
        f.accountOptions[ OPTION ] = f.writes[ 0 ].optionvalue;
        await f.store.retry();
        assert.equal( f.writes.length, 1 );
        assert.equal( f.store.getStatus().state, 'saved' );
        assert.equal( f.store.getStatus().dirty, false );
    } );

    await test( 'options warning or API error is treated as an unsaved result', async () => {
        for ( const response of [ { options: 'success', warnings: { options: 'ignored' } },
            { error: { code: 'assertnameduserfailed' } }, {} ] ) {
            const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' }, postResults: [ response ] } );
            await f.store.ready();
            f.store.add( { title: 'Pending' } );
            await assert.rejects( f.store.flush() );
            assert.equal( f.store.getStatus().dirty, true );
        }
    } );

    await test( 'changed client identity blocks writes without moving data into anonymous storage', async () => {
        const f = visit( { config: { wgUserId: 7, wgUserName: 'Synthetic User' } } );
        await f.store.ready();
        f.store.add( { title: 'Pending' } );
        f.config.wgUserId = 8;
        f.config.wgUserName = 'Other User';
        await assert.rejects( f.store.flush(), { code: 'identity' } );
        assert.equal( f.store.getStatus().canWrite, false );
        assert.equal( f.localWrites.length + f.writes.length, 0 );
        assert.equal( f.store.getItems().length, 1 );
    } );

    await test( 'anonymous storage failures are visible and preserve unsaved edits', async () => {
        const f = visit();
        await f.store.ready();
        f.store.add( { title: 'Pending' } );
        f.setLocalBlocked( true );
        await assert.rejects( f.store.flush() );
        assert.equal( f.store.getStatus().state, 'error' );
        assert.equal( f.store.getStatus().dirty, true );
        f.setLocalBlocked( false );
        await f.store.retry();
        assert.equal( f.store.getStatus().state, 'saved' );
    } );

    await test( 'model returns defensive snapshots and rejects invalid updates atomically', async () => {
        const f = visit();
        await f.store.ready();
        const entry = f.store.add( { title: 'Immutable source' } );
        entry.title = 'External change';
        f.store.getItems()[ 0 ].notes = 'External mutation';
        assert.equal( f.store.getItems()[ 0 ].notes, '' );
        assert.equal( f.store.getItems()[ 0 ].title, 'Immutable source' );
        for ( const patch of [ { notes: false }, { progress: -1 }, { progress: 101 },
            { progress: NaN }, { done: 'yes' }, { collapsed: 1 }, { slider: 1 } ] ) {
            assert.throws( () => f.store.update( entry.id, patch ) );
        }
        assert.equal( f.store.getItems()[ 0 ].progress, 0 );
        await f.store.flush();
        const before = f.localWrites.length;
        f.store.update( entry.id, { title: 'Cannot change source', notes: '' } );
        f.store.remove( 'Missing' );
        await f.store.flush();
        assert.equal( f.localWrites.length, before );
    } );

    await test( 'entry and notes bounds reject excess data without truncation', async () => {
        const f = visit();
        await f.store.ready();
        for ( let i = 0; i < 100; i++ ) { f.store.add( { title: 'Page ' + i } ); }
        assert.throws( () => f.store.add( { title: 'Page 101' } ), { code: 'limit' } );
        const { id } = f.store.getItems()[ 0 ];
        assert.throws( () => f.store.update( id, { notes: 'x'.repeat( 16001 ) } ), { code: 'limit' } );
        assert.equal( f.store.getItems().length, 100 );
        assert.equal( f.store.getItems()[ 0 ].notes, '' );
        f.store.remove( id );
        assert.equal( f.store.getItems().length, 99 );
        await f.store.flush();
    } );

    await test( 'total limit counts UTF-8 bytes and preserves prior notes when exceeded', async () => {
        const f = visit();
        await f.store.ready();
        const first = f.store.add( { title: 'First' } );
        const second = f.store.add( { title: 'Second' } );
        f.store.update( first.id, { notes: '界'.repeat( 15000 ) } );
        assert.throws( () => f.store.update( second.id, { notes: '界'.repeat( 10000 ) } ), { code: 'limit' } );
        assert.equal( f.store.getItems()[ 1 ].notes, '' );
        await f.store.flush();
        assert.ok( Buffer.byteLength( f.localWrites[ 0 ].value, 'utf8' ) <= 60000 );
    } );

    await test( 'subscribers receive lifecycle and content changes and can unsubscribe', async () => {
        const f = visit();
        const seen = [];
        const unsubscribe = f.store.subscribe( value => seen.push( plain( value ) ) );
        await f.store.ready();
        f.store.add( { title: 'Subscribed' } );
        await f.store.flush();
        assert.equal( seen[ 0 ].status.state, 'idle' );
        assert.ok( seen.some( value => value.status.state === 'loading' ) );
        assert.ok( seen.some( value => value.status.dirty ) );
        assert.equal( seen.at( -1 ).status.state, 'saved' );
        unsubscribe();
        const count = seen.length;
        f.store.add( { title: 'After unsubscribe' } );
        await f.store.flush();
        assert.equal( seen.length, count );
    } );

    await test( 'leaving with unacknowledged changes warns until the save succeeds', async () => {
        const f = visit();
        await f.store.ready();
        let warned = false;
        const event = { preventDefault() { warned = true; } };
        f.events.beforeunload( event );
        assert.equal( warned, false );
        f.store.add( { title: 'Unsaved' } );
        f.events.beforeunload( event );
        assert.equal( warned, true );
        await f.store.flush();
        warned = false;
        f.events.beforeunload( event );
        assert.equal( warned, false );
    } );

    console.log( cases + ' synthetic to-do persistence regressions passed.' );
} )().catch( error => { console.error( error ); process.exitCode = 1; } );
