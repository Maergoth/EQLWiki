/* Read-only observed-loot enrichment. Authored item labels and article source stay intact. */
( function () {
    'use strict';

    var mounted = new WeakSet(), selectedSources = new WeakMap(), requests = Object.create( null );
    var ROOTS = '.eql-mobpage';

    function text( value ) { return typeof value === 'string' ? value.slice( 0, 230 ) : ''; }
    function titleKey( value ) {
        value = text( value ).replace( /_/g, ' ' ).trim();
        return value ? value.charAt( 0 ).toUpperCase() + value.slice( 1 ) : '';
    }
    function element( tag, className, value ) {
        var node = document.createElement( tag );
        if ( className ) { node.className = className; }
        if ( value !== undefined ) { node.textContent = value; }
        return node;
    }
    function cleanTitle( value ) {
        value = text( value );
        return value && !/[\[\]{}<>|#~:]/.test( value ) && !/^\./.test( value ) ? value : '';
    }
    function link( row ) {
        var title = cleanTitle( row.itemTitle ), label = text( row.item ) || title;
        if ( !title ) { return element( 'span', '', label ); }
        var node = element( 'a', '', label );
        try {
            var url = new URL( mw.util.getUrl( title ), window.location.href );
            if ( url.origin !== window.location.origin || !/^https?:$/.test( url.protocol ) ) {
                return element( 'span', '', label );
            }
            node.href = url.href;
            node.title = title;
        } catch ( error ) { return element( 'span', '', label ); }
        var hover = element( 'span', 'ih' );hover.appendChild( node );return hover;
    }
    function validRow( row ) {
        return row && typeof row === 'object' &&
            Number.isSafeInteger( row.reports ) && row.reports >= 2 &&
            [ 'normal', 'raid', 'unknown' ].indexOf( row.instance ) !== -1 &&
            ( text( row.item ) || cleanTitle( row.itemTitle ) );
    }
    function manualLists( root ) {
        return root.querySelectorAll( '.eql-drop-manual, .eql-mobpage-hero-loot ul, .eql-mobpage-loot ul, .eql-mobpage-common-loot ul' );
    }
    function manualItems( lists ) {
        var items = Object.create( null );
        Array.prototype.forEach.call( lists, function ( list ) {
            Array.prototype.forEach.call( list.children, function ( li ) {
                if ( li.tagName !== 'LI' || li.classList.contains( 'eql-drop-generated' ) ) { return; }
                var anchor = Array.prototype.find.call( li.querySelectorAll( 'a[href]' ), function ( candidate ) {
                    return Boolean( anchorTitle( candidate ) );
                } );
                var key = anchor && titleKey( anchorTitle( anchor ) );
                if ( key ) {
                    li.classList.add( 'eql-drop-clean-item' );
                    if ( !items[ key ] ) { items[ key ] = []; }items[ key ].push( { li: li, anchor: anchor } );
                }
            } );
        } );
        return items;
    }
    function anchorTitle( anchor ) {
        try {
            var url = new URL( anchor.getAttribute( 'href' ), window.location.href );
            if ( url.origin !== window.location.origin || !/^https?:$/.test( url.protocol ) ) { return ''; }
            if ( url.searchParams.has( 'title' ) ) { return cleanTitle( url.searchParams.get( 'title' ) ); }
            var parts = ( mw.config.get( 'wgArticlePath' ) || '/$1' ).split( '$1' );
            if ( parts.length === 2 && url.pathname.indexOf( parts[ 0 ] ) === 0 &&
                ( !parts[ 1 ] || url.pathname.slice( -parts[ 1 ].length ) === parts[ 1 ] ) ) {
                return cleanTitle( decodeURIComponent( url.pathname.slice( parts[ 0 ].length,
                    parts[ 1 ] ? -parts[ 1 ].length : undefined ) ) );
            }
        } catch ( error ) { /* Invalid links cannot establish an item identity. */ }
        return '';
    }
    function scopeKey( row ) {
        return JSON.stringify( [ row.zoneTitle || row.zone, row.npcTitle || row.npc, row.instance ] );
    }
    function scopeLabel( row ) {
        var instance = row.instance === 'unknown' ? 'instance unknown' : row.instance === 'raid' ? 'Raid' : 'Normal';
        return [ text( row.zoneTitle || row.zone ) || 'Zone unknown', instance ].join( ' · ' );
    }
    function preferredInstance( root ) {
        if ( [ 'normal', 'raid' ].indexOf( root.dataset.dropInstance ) !== -1 ) { return root.dataset.dropInstance; }
        var article = root.closest( '.mw-parser-output' );
        if ( !article ) { return ''; }
        var last = '', foundPartition = false;
        Array.prototype.forEach.call( article.querySelectorAll( 'h2' ), function ( heading ) {
            if ( root.contains( heading ) ) { return; }
            var label = heading.textContent.trim().replace( /[ _]+/g, ' ' ).toLowerCase();
            var instance = label === 'raid instance' || label === 'raid zone' ? 'raid' :
                label === 'normal instance' || label === 'normal zone' ? 'normal' : '';
            if ( instance ) { foundPartition = true; }
            if ( heading.compareDocumentPosition( root ) & 4 ) { last = instance; }
        } );
        // Only an explicit enclosing heading proves the card's preferred context.
        return foundPartition ? last : '';
    }
    function render( root, rows ) {
        Array.prototype.forEach.call( root.querySelectorAll( '.eql-drop-generated' ), function ( node ) { node.remove(); } );
        var manual = manualItems( manualLists( root ) );
        var npc = titleKey( root.dataset.dropNpc || mw.config.get( 'wgPageName' ) );
        rows = Array.isArray( rows ) ? rows.filter( function ( row ) {
            return validRow( row ) && ( !npc || titleKey( row.npcTitle || row.npc ) === npc );
        } ).slice( 0, 500 ) : [];
        if ( !rows.length ) { return; }
        var groups = Object.create( null ), seen = Object.create( null );
        var panel = root.querySelector( '.eql-mobpage-hero-loot, .eql-mobpage-loot' ) || root;
        rows.forEach( function ( row ) {
            var key = scopeKey( row );
            var itemKey = JSON.stringify( [ key, titleKey( row.itemTitle || row.item ) ] );
            if ( seen[ itemKey ] ) { return; }seen[ itemKey ] = true;
            if ( !groups[ key ] ) { groups[ key ] = { key: key, row: row, reports: 0, rows: [] }; }
            groups[ key ].reports += row.reports;groups[ key ].rows.push( row );
        } );
        var contexts = Object.keys( groups ).map( function ( key ) { return groups[ key ]; } );
        contexts.sort( function ( a, b ) { return b.reports - a.reports || ( a.key < b.key ? -1 : a.key > b.key ? 1 : 0 ); } );
        var selected = selectedSources.get( root );
        if ( !groups[ selected ] ) {
            var preferred = preferredInstance( root );
            var candidate = contexts.find( function ( context ) { return context.row.instance === preferred; } );
            selected = ( candidate || contexts[ 0 ] ).key;selectedSources.set( root, selected );
        }
        var controls = element( 'div', 'eql-drop-generated eql-drop-observations' );
        if ( contexts.length > 1 ) {
            var label = element( 'label', 'eql-drop-source-label', 'Source ' );
            var selector = element( 'select', 'eql-drop-source-select' );
            selector.setAttribute( 'aria-label', 'Observation source: zone and instance' );
            contexts.forEach( function ( context ) {
                var option = element( 'option', '', scopeLabel( context.row ) );option.value = context.key;
                option.selected = context.key === selected;selector.appendChild( option );
            } );
            selector.addEventListener( 'change', function () {
                selectedSources.set( root, selector.value );render( root, rows );
                var replacement = root.querySelector( '.eql-drop-source-select' );if ( replacement ) { replacement.focus(); }
            } );
            label.appendChild( selector );controls.appendChild( label );
        }
        var info = element( 'details', 'eql-drop-info' );
        info.appendChild( element( 'summary', '', 'Per-kill rates unavailable' ) );
        info.appendChild( element( 'p', 'eql-drop-legend',
            'Complete kill and loot coverage, including empty corpses, is required to show per-kill drop chances. ' +
            'Grey items have at least two loot reports from ' + scopeLabel( groups[ selected ].row ) + '.' ) );
        var detail = element( 'a', 'eql-drop-database-link', 'Browse the drop database' );
        detail.href = mw.util.getUrl( 'Special:DropDatabase' );
        info.appendChild( detail );controls.appendChild( info );
        var heading = panel.querySelector( 'h2, h3' );
        if ( heading && heading.parentNode === panel ) { heading.after( controls ); }
        else { panel.insertBefore( controls, panel.firstChild ); }
        var selectedRows = groups[ selected ].rows;
        var appendList = panel.querySelector( 'ul:not(.eql-drop-generated-list)' );
        selectedRows.forEach( function ( row ) {
            var target = cleanTitle( row.itemTitle || row.item ) && manual[ titleKey( row.itemTitle || row.item ) ];
            if ( target ) { return; }
            if ( !appendList ) {
                appendList = element( 'ul', 'eql-drop-generated eql-drop-generated-list' );panel.appendChild( appendList );
            }
            var item = element( 'li', 'eql-drop-observed-item' );
            item.classList.add( 'eql-drop-generated' );
            item.appendChild( link( row ) );appendList.appendChild( item );
        } );
    }
    function load( root ) {
        if ( mounted.has( root ) ) { return; }
        mounted.add( root );
        manualItems( manualLists( root ) );
        var npc = titleKey( root.dataset.dropNpc || mw.config.get( 'wgPageName' ) );
        if ( !npc || !mw.config.get( 'wgIsArticle' ) || mw.config.get( 'wgAction' ) !== 'view' ) { return; }
        if ( !requests[ npc ] ) {
            requests[ npc ] = Promise.resolve().then( function () { return new mw.Api().get( {
                action: 'eqldrops', npc: npc, limit: 500, formatversion: 2
            }, { timeout: 10000 } ); } ).then( function ( response ) {
                return response && response.eqldrops && response.eqldrops.rows || [];
            } ).catch( function () { return []; } );
        }
        requests[ npc ].then( function ( rows ) { if ( root.isConnected ) { render( root, rows ); } } );
    }
    function mount( content ) {
        var node = content && ( content.nodeType ? content : content[ 0 ] ) || document;
        if ( node.matches && node.matches( ROOTS ) ) { load( node ); }
        if ( node.querySelectorAll ) { Array.prototype.forEach.call( node.querySelectorAll( ROOTS ), load ); }
    }
    window.EQLDropDatabase = { render: render };
    mw.hook( 'wikipage.content' ).add( mount );
    if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', function () { mount( document ); } ); }
    else { mount( document ); }
}() );
