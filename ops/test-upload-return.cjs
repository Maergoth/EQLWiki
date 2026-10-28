'use strict';
// Synthetic native-form/cache/navigation doubles; no network, accounts or uploads.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname, '../skins/EQLImmersive/resources/upload-converter.js' ), 'utf8' );
const key = 'eqlPendingUploadPurge';
const now = 1800000000000;
const turn = () => new Promise( resolve => setImmediate( resolve ) );
const form = '<form id="searchform"><input name="search"></form><form id="mw-upload-form"><input name="wpDestFile" value="Changed_name.png"><button name="wpUpload">Upload</button><button name="wpCancelUpload">Cancel</button></form>';
function storage( faults = {} ) {
    const values = new Map(), writes = [];
    return { values, writes,
        getItem( name ) { if ( faults.get ) { throw Error( 'Storage get denied' ); } return values.get( name ) ?? null; },
        setItem( name, value ) { if ( faults.set ) { throw Error( 'Storage set denied' ); } values.set( name, value ); writes.push( [ name, value ] ); },
        removeItem( name ) { if ( faults.remove ) { throw Error( 'Storage remove denied' ); } values.delete( name ); }
    };
}
function fixture( options = {} ) {
    const errors = [], console = new VirtualConsole(); console.on( 'jsdomError', e => errors.push( e ) );
    const dom = new JSDOM( '<body>' + ( options.html ?? form ) + '</body>', { url: 'https://wiki.example/', virtualConsole: console } );
    const document = dom.window.document, store = options.storage || storage(), calls = [], navigations = [];
    let ready = options.ready || 'complete'; Object.defineProperty( document, 'readyState', { get: () => ready } );
    const config = { wgCanonicalSpecialPageName: 'Upload', wgPageName: 'Special:Upload', wgDBname: 'syntheticwiki', wgUserId: 7, ...options.config };
    const url = new URL( options.url || 'https://wiki.example/index.php?title=Special:Upload&eqlReturnTo=NPC_One&wpDestFile=Original.png&eqlAlsoPurge=Item_One%7CNPC_One' );
    const location = { origin: url.origin, search: url.search }; Object.defineProperty( location, 'href', { get: () => url.href, set: value => navigations.push( value ) } );
    const mw = { config: { get: name => config[ name ] }, loader: { using: () => Promise.resolve() }, util: { getUrl: ( title, params ) => '/wiki/' + encodeURIComponent( title.replace( / /g, '_' ) ) + ( params ? '?' + new URLSearchParams( params ) : '' ) }, Api: function () {
        this.post = params => { calls.push( JSON.parse( JSON.stringify( params ) ) ); return options.post ? options.post( params ) : Promise.resolve( {} ); };
    } };
    const context = vm.createContext( { window: { location }, document, sessionStorage: store, mw, URLSearchParams, Date: class extends Date { static now() { return now; } } } );
    const evaluate = () => vm.runInContext( source, context );
    if ( options.javascript !== false ) { evaluate(); }
    const submit = ( id = 'mw-upload-form', button = 'wpUpload', canceled = false ) => {
        const node = document.getElementById( id ), event = new dom.window.SubmitEvent( 'submit', { bubbles: true, cancelable: true, submitter: node.querySelector( '[name="' + button + '"]' ) } );
        if ( canceled ) { event.preventDefault(); } node.dispatchEvent( event );
    };
    const formData = ( change, id = 'mw-upload-form' ) => {
        const node = document.getElementById( id ), data = new dom.window.FormData( node );
        if ( change ) { change( data ); }
        const event = new dom.window.Event( 'formdata', { bubbles: true } ); Object.defineProperty( event, 'formData', { value: data } ); node.dispatchEvent( event ); return data;
    };
    return { document, window: dom.window, store, config, calls, navigations, errors, evaluate, submit, formData,
        ready: () => { ready = 'complete'; document.dispatchEvent( new dom.window.Event( 'DOMContentLoaded' ) ); },
        settle: async () => { await turn(); await turn(); assert.deepEqual( errors, [] ); },
        close: () => dom.window.close()
    };
}
async function seed( extra = {} ) {
    const f = fixture( extra ); await f.settle(); f.submit(); await f.settle();
    const raw = f.store.values.get( key ); assert( raw, 'Native upload submission snapshots its context.' ); f.close(); return f.store;
}
function filePage( store, extra = {} ) {
    return fixture( { storage: store, config: { wgCanonicalSpecialPageName: false, wgPageName: 'File:Changed_name.png', ...extra.config }, url: 'https://wiki.example/wiki/File:Changed_name.png', ...extra } );
}
( async () => {
    const correct = fixture(); await correct.settle(); correct.submit( 'searchform', '' ); assert.equal( correct.store.values.size, 0, 'Header search never seeds upload tracking.' );
    correct.document.querySelector( '[name="wpDestFile"]' ).value = 'Final destination.png'; correct.submit(); await correct.settle();
    const pending = JSON.parse( correct.store.values.get( key ) ); assert.equal( pending.destFile, 'Final destination.png', 'Snapshot submitted destination, not the original URL.' ); assert.equal( pending.returnTo, 'NPC One' ); assert.deepEqual( pending.alsoPurge, [ 'Item One', 'NPC One' ] ); assert.equal( pending.identity, JSON.stringify( [ 'https://wiki.example', 'syntheticwiki', 7 ] ) ); assert.equal( correct.calls.length, 0 ); correct.close();

    const lazy = fixture( { html: '<form id="searchform"></form>', ready: 'loading' } ); await lazy.settle(); lazy.document.body.insertAdjacentHTML( 'beforeend', form.slice( form.indexOf( '<form id="mw-upload-form"' ) ) ); lazy.ready(); await lazy.settle(); lazy.evaluate(); await lazy.settle(); lazy.submit(); await lazy.settle(); assert.equal( lazy.store.writes.length, 1, 'Delayed DOM initialization binds once to the actual upload form.' ); lazy.close();
    const noJs = fixture( { javascript: false } ); noJs.submit(); assert.equal( noJs.store.values.size, 0 ); assert.equal( noJs.calls.length, 0, 'Without JavaScript the native form remains untouched.' ); noJs.close();

    // Protocol integration only: the site's reported external converter has
    // not been located. Do not claim a synthetic FormData event ran an encoder.
    const finalData = fixture( { html: form.replace( '<input name="wpDestFile"', '<input type="file" name="wpUploadFile" accept="image/*"><input name="wpDestFile"' ) } ); await finalData.settle();
    const nativeForm = finalData.document.getElementById( 'mw-upload-form' ), destination = nativeForm.elements.namedItem( 'wpDestFile' ), fileInput = nativeForm.elements.namedItem( 'wpUploadFile' );
    const originalList = fileInput.files, nativeSubmit = nativeForm.submit, method = nativeForm.method, action = nativeForm.action, accept = fileInput.accept;
    const originalFile = new finalData.window.File( [ 'Synthetic file bytes' ], 'native-choice.jpg', { type: 'image/jpeg' } );
    for ( const chosen of [ 'native-choice.jpg', 'Custom_NPC_name.png' ] ) {
        destination.value = chosen; const before = destination.value;
        const data = finalData.formData( d => d.set( 'wpUploadFile', originalFile ) );
        await finalData.settle();
        assert.equal( JSON.parse( finalData.store.values.get( key ) ).destFile, chosen.replace( /_/g, ' ' ), 'Native basename/custom destination supplies context; it is never renamed.' );
        assert.equal( destination.value, before ); assert.equal( data.get( 'wpDestFile' ), before ); assert.equal( data.get( 'wpUploadFile' ), originalFile ); assert.equal( originalFile.name, 'native-choice.jpg' ); assert.equal( originalFile.type, 'image/jpeg' ); assert.equal( fileInput.files, originalList ); assert.equal( fileInput.value, '' );
        assert.equal( nativeForm.submit, nativeSubmit ); assert.equal( nativeForm.method, method ); assert.equal( nativeForm.action, action ); assert.equal( fileInput.accept, accept );
    }
    let submitEvents = 0; nativeForm.addEventListener( 'submit', event => { submitEvents++; event.preventDefault(); } );
    destination.value = 'Before asynchronous handler.jpg'; finalData.submit(); await finalData.settle();
    assert.equal( JSON.parse( finalData.store.values.get( key ) ).destFile, 'Before asynchronous handler.jpg' );
    await turn(); destination.value = 'Final native submission.png'; const submitted = finalData.formData( d => d.set( 'wpUploadFile', originalFile ) ); await finalData.settle();
    assert.equal( submitEvents, 1, 'The later FormData protocol does not require a second submit event.' ); assert.equal( JSON.parse( finalData.store.values.get( key ) ).destFile, 'Final native submission.png', 'Final FormData destination wins over the earlier capture fallback.' ); assert.equal( submitted.get( 'wpUploadFile' ), originalFile ); assert.equal( fileInput.files, originalList ); assert.equal( nativeForm.submit, nativeSubmit ); assert.equal( finalData.calls.length, 0 );
    const laterHandler = event => { event.formData.set( 'wpDestFile', 'Chosen by later native handler.png' ); }; nativeForm.addEventListener( 'formdata', laterHandler );
    destination.value = 'Before later handler.jpg'; const finalEntries = finalData.formData( d => d.set( 'wpUploadFile', originalFile ) ); await finalData.settle();
    assert.equal( JSON.parse( finalData.store.values.get( key ) ).destFile, 'Chosen by later native handler.png', 'Script-driven dispatch includes a synchronous FormData handler registered after the tracker.' ); assert.equal( finalEntries.get( 'wpDestFile' ), 'Chosen by later native handler.png' ); assert.equal( destination.value, 'Before later handler.jpg' ); assert.equal( finalEntries.get( 'wpUploadFile' ), originalFile ); assert.equal( fileInput.files, originalList ); nativeForm.removeEventListener( 'formdata', laterHandler );
    finalData.formData( d => d.append( 'wpCancelUpload', 'Cancel' ) ); await finalData.settle(); assert.equal( finalData.store.values.has( key ), false, 'Cancel FormData clears pending context without altering native data.' );
    finalData.formData( d => d.append( 'wpDestFile', 'Ambiguous second.png' ) ); await finalData.settle(); assert.equal( finalData.store.values.has( key ), false, 'Ambiguous submitted destination is not guessed.' );
    finalData.config.wgUserId = 8; finalData.formData(); finalData.submit(); await finalData.settle(); assert.equal( finalData.store.values.has( key ), false, 'An old account form cannot seed context for a newly active account.' ); finalData.close();

    for ( const mode of [ 'cancel', 'prevented', 'empty', 'disabled' ] ) {
        const s = await seed(), f = fixture( { storage: s } ); await f.settle();
        if ( mode === 'empty' ) { f.document.querySelector( '[name="wpDestFile"]' ).value = ''; }
        if ( mode === 'disabled' ) { f.document.querySelector( '[name="wpDestFile"]' ).disabled = true; }
        f.submit( 'mw-upload-form', mode === 'cancel' ? 'wpCancelUpload' : 'wpUpload', mode === 'prevented' ); await f.settle(); assert.equal( s.values.has( key ), false, mode + ' must not retain an old upload-return intent.' ); assert.equal( f.calls.length, 0 ); f.close();
    }
    const warningStore = await seed();
    const warning = fixture( { storage: warningStore, url: 'https://wiki.example/index.php?title=Special:Upload', html: form.replace( 'name="wpUpload"', 'name="wpUploadIgnoreWarning"' ).replace( 'value="Changed_name.png"', 'value="Warning destination.png"' ) } ); await warning.settle(); warning.submit( 'mw-upload-form', 'wpUploadIgnoreWarning' ); await warning.settle(); assert.equal( JSON.parse( warningStore.values.get( key ) ).destFile, 'Warning destination.png', 'Native warning resubmit preserves context but captures the new destination.' ); warning.close();
    const noWarning = fixture( { storage: await seed(), url: 'https://wiki.example/index.php?title=Special:Upload' } ); await noWarning.settle(); const previous = noWarning.store.values.get( key ); noWarning.submit(); assert.equal( noWarning.store.values.get( key ), previous, 'An unrelated fresh upload does not adopt an abandoned return context.' ); noWarning.close();

    for ( const faults of [ { get: true }, { set: true }, { remove: true }, { get: true, set: true, remove: true } ] ) {
        const s = storage( faults ), f = fixture( { storage: s } ); await f.settle(); assert.doesNotThrow( () => f.submit() ); assert.doesNotThrow( () => f.formData() ); await f.settle(); f.close();
        const after = filePage( s ); await after.settle(); assert.equal( after.navigations.length, faults.set ? 0 : faults.get ? 0 : 1 ); after.close();
    }

    const successStore = await seed(), success = filePage( successStore ); await success.settle(); assert.equal( success.calls.length, 1 ); assert.deepEqual( success.calls[ 0 ], { action: 'purge', titles: 'NPC One|File:Changed name.png|Item One', forcelinkupdate: 1, format: 'json' } );
    assert.equal( successStore.values.has( key ), false ); assert.equal( success.navigations.length, 1 ); const returned = new URL( success.navigations[ 0 ], 'https://wiki.example' ); assert.equal( decodeURIComponent( returned.pathname ), '/wiki/NPC_One' ); assert.equal( returned.searchParams.get( 'eqlUploadPurged' ), '1' ); success.close();
    const failed = filePage( await seed(), { post: () => Promise.reject( Error( 'Synthetic purge unavailable' ) ) } ); await failed.settle(); assert.equal( failed.calls.length, 1 ); assert.equal( new URL( failed.navigations[ 0 ], 'https://wiki.example' ).searchParams.get( 'action' ), 'purge', 'Purge failure preserves the ordinary native manual-purge fallback.' ); assert.equal( failed.store.values.has( key ), false ); failed.close();

    const atReturn = filePage( await seed(), { config: { wgCanonicalSpecialPageName: false, wgPageName: 'NPC_One' } } ); await atReturn.settle(); assert.equal( atReturn.calls.length, 1 ); assert.equal( atReturn.navigations.length, 1 ); atReturn.close();
    const unrelated = filePage( await seed(), { config: { wgCanonicalSpecialPageName: false, wgPageName: 'File:Unrelated.png' } } ); await unrelated.settle(); assert.equal( unrelated.calls.length, 0 ); assert.equal( unrelated.navigations.length, 0 ); unrelated.close();
    const marked = filePage( await seed(), { url: 'https://wiki.example/wiki/NPC_One?eqlUploadPurged=1', config: { wgCanonicalSpecialPageName: false, wgPageName: 'NPC_One' } } ); await marked.settle(); assert.equal( marked.calls.length, 0 ); assert.equal( marked.store.values.size, 0 ); marked.close();

    for ( const mutate of [ p => { p.timestamp = now - 900001; }, p => { p.timestamp = now + 1; }, p => { delete p.identity; }, p => { p.identity = JSON.stringify( [ 'https://other.example', 'syntheticwiki', 7 ] ); }, p => { p.identity = JSON.stringify( [ 'https://wiki.example', 'otherwiki', 7 ] ); }, p => { p.identity = JSON.stringify( [ 'https://wiki.example', 'syntheticwiki', 8 ] ); }, p => { p.returnTo = 'NPC One|Unexpected page'; }, p => { p.alsoPurge = 'Unexpected'; } ] ) {
        const s = await seed(), p = JSON.parse( s.values.get( key ) ); mutate( p ); s.values.set( key, JSON.stringify( p ) ); const f = filePage( s ); await f.settle(); assert.equal( f.calls.length, 0 ); assert.equal( f.navigations.length, 0 ); assert.equal( s.values.has( key ), false ); f.close();
    }
    let resolvePurge; const late = filePage( await seed(), { post: () => new Promise( resolve => { resolvePurge = resolve; } ) } ); await late.settle(); assert.equal( late.calls.length, 1 ); late.config.wgUserId = 8; resolvePurge( {} ); await late.settle(); assert.equal( late.navigations.length, 0, 'A late response cannot navigate the newly active account.' ); late.close();
    console.log( 'Upload return DOM passed: real native form after header, passive final FormData/current destination without file/form mutation, delayed/idempotent binding, warnings/cancel, blocked storage, File/NPC return and failure fallback, expiration, account/wiki isolation and no-JS preservation; synthetic protocol only, no located converter or uploads/network/account writes.' );
} )().catch( error => { console.error( error ); process.exitCode = 1; } );
