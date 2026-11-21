/* On-demand, read-only submission details. Raw events and account identities stay private. */
( function () {
    'use strict';
    var mounted = new WeakSet(), PAGE_SIZE = 25;
    function element( tag, className, value ) {
        var node = document.createElement( tag );
        if ( className ) { node.className = className; }
        if ( value !== undefined ) { node.textContent = value; }
        return node;
    }
    function utc( value ) {
        if ( typeof value !== 'string' || !/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{3})?Z$/.test( value ) ) { return null; }
        var date = new Date( value );
        return Number.isFinite( date.getTime() ) ? date.toISOString().slice( 0, 19 ).replace( 'T', ' ' ) : null;
    }
    function parse( response, offset ) {
        if ( response && response.error ) { throw response.error.code || 'unavailable'; }
        var detail = response && response.eqldrops && response.eqldrops.detail;
        if ( !detail || !Array.isArray( detail.rows ) || detail.rows.length > PAGE_SIZE ||
            !Number.isSafeInteger( detail.totalRows ) || detail.totalRows < 0 || detail.offset !== offset ||
            !Number.isSafeInteger( detail.limit ) || detail.limit < 1 || detail.limit > PAGE_SIZE ||
            typeof detail.hasMore !== 'boolean' || detail.totalRows < offset + detail.rows.length ||
            detail.hasMore !== ( offset + detail.rows.length < detail.totalRows ) ||
            detail.hasMore && !detail.rows.length ) { throw new Error( 'invalid-response' ); }
        var rows = detail.rows.map( function ( row ) {
            if ( !row || !utc( row.observedAt ) || !utc( row.receivedAt ) ||
                !Number.isSafeInteger( row.quantity ) || row.quantity < 1 || row.quantity > 1000 ||
                row.characterName !== null && ( typeof row.characterName !== 'string' || Array.from( row.characterName ).length > 120 ) ) {
                throw new Error( 'invalid-row' );
            }
            return { observed: utc( row.observedAt ), received: utc( row.receivedAt ), quantity: row.quantity,
                character: row.characterName || 'Not provided' };
        } );
        return { rows: rows, totalRows: detail.totalRows, hasMore: detail.hasMore };
    }
    function mountEntry( entry ) {
        if ( mounted.has( entry ) ) { return; }mounted.add( entry );
        var content = entry.querySelector( '.eql-drop-detail-content' );
        var id = entry.dataset.entry, snapshot = entry.dataset.snapshot;
        if ( !content || !/^drop_[0-9a-f]{64}$/.test( id || '' ) || !/^[0-9a-f]{64}$/.test( snapshot || '' ) ) { return; }
        var state = { rows: [], total: 0, more: false, loading: false, loaded: false, started: false, error: null };
        function draw() {
            content.replaceChildren();content.setAttribute( 'aria-busy', String( state.loading ) );
            if ( state.rows.length ) {
                var scroll = element( 'div', 'eql-drop-detail-scroll' ), table = element( 'table', 'wikitable eql-drop-detail-table' );
                table.appendChild( element( 'caption', '', 'Individual submissions' ) );
                var head = element( 'thead' ), header = element( 'tr' );
                [ 'Character', 'Observed (UTC)', 'Submitted (UTC)', 'Quantity' ].forEach( function ( label ) {
                    var th = element( 'th', '', label );th.scope = 'col';header.appendChild( th );
                } );head.appendChild( header );table.appendChild( head );
                var body = element( 'tbody' );
                state.rows.forEach( function ( row ) {
                    var tr = element( 'tr' );
                    [ row.character, row.observed, row.received, String( row.quantity ) ].forEach( function ( value ) {
                        tr.appendChild( element( 'td', '', value ) );
                    } );body.appendChild( tr );
                } );table.appendChild( body );scroll.appendChild( table );content.appendChild( scroll );
            }
            var status = element( 'p', 'eql-drop-detail-status' );status.setAttribute( 'role', 'status' );
            if ( state.loading ) { status.textContent = 'Loading submissions…'; }
            else if ( state.loaded ) { status.textContent = 'Showing ' + state.rows.length + ' of ' + state.total + ' submissions.'; }
            content.appendChild( status );
            if ( state.error ) {
                var stale = String( state.error ).indexOf( 'eqldrops-detail-stale' ) !== -1;
                content.appendChild( element( 'p', 'eql-drop-detail-error', stale ?
                    'These submissions have changed. Reload the drop database to view the latest.' : 'Could not load submissions. Please try again.' ) );
                if ( stale ) {
                    var reload = element( 'a', 'eql-drop-detail-reload', 'Reload the drop database' );reload.href = window.location.href;content.appendChild( reload );
                } else {
                    var retry = element( 'button', 'eql-drop-detail-retry', 'Retry' );retry.type = 'button';
                    retry.addEventListener( 'click', load );content.appendChild( retry );
                }
            } else if ( state.more && !state.loading ) {
                var more = element( 'button', 'eql-drop-detail-more', 'Load more' );more.type = 'button';
                more.addEventListener( 'click', load );content.appendChild( more );
            }
        }
        function load( event ) {
            if ( state.loading || !entry.isConnected || !window.document ) { return; }
            var restoreFocus = event && event.currentTarget === document.activeElement;
            state.started = true;state.loading = true;state.error = null;draw();
            if ( restoreFocus ) { var waiting = content.querySelector( '.eql-drop-detail-status' );waiting.tabIndex = -1;waiting.focus(); }
            var offset = state.rows.length;
            Promise.resolve().then( function () {
                return new mw.Api().get( { action: 'eqldrops', detail: id, snapshot: snapshot,
                    offset: offset, limit: PAGE_SIZE, formatversion: 2 }, { timeout: 10000 } );
            } ).then( function ( response ) {
                var detail = parse( response, offset );
                state.rows = state.rows.concat( detail.rows );state.total = detail.totalRows;
                state.more = detail.hasMore;state.loaded = true;
            } ).catch( function ( error ) { state.error = error && error.code || error; } ).then( function () {
                state.loading = false;
                if ( entry.isConnected && window.document ) {
                    draw();
                    if ( restoreFocus ) {
                        var focus = content.querySelector( 'button, .eql-drop-detail-reload' ) || entry.querySelector( 'summary' );
                        if ( focus ) { focus.focus(); }
                    }
                }
            } );
        }
        entry.addEventListener( 'toggle', function () { if ( entry.open && !state.started ) { load(); } } );
        if ( entry.open ) { load(); }
    }
    function mount( content ) {
        var root = content && ( content.nodeType ? content : content[ 0 ] ) || document;
        if ( root.matches && root.matches( '.eql-drop-entry' ) ) { mountEntry( root ); }
        if ( root.querySelectorAll ) { Array.prototype.forEach.call( root.querySelectorAll( '.eql-drop-entry' ), mountEntry ); }
    }
    mw.hook( 'wikipage.content' ).add( mount );
    if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', function () { mount( document ); } ); }
    else { mount( document ); }
}() );
