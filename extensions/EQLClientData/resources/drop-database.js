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
    function wikiUrl( title ) {
        try {
            var url = new URL( mw.util.getUrl( title ), window.location.href );
            return url.origin === window.location.origin && /^https?:$/.test( url.protocol ) ? url.href : '';
        } catch ( error ) { return ''; }
    }
    function link( row ) {
        var title = cleanTitle( row.itemTitle ), label = text( row.item ) || title;
        if ( !title ) { return element( 'span', '', label ); }
        var href = wikiUrl( title );
        if ( !href ) { return element( 'span', '', label ); }
        var node = element( 'a', '', label );node.href = href;node.title = title;
        var hover = element( 'span', 'ih' );hover.appendChild( node );return hover;
    }
    function validRow( row ) {
        return row && typeof row === 'object' &&
            Number.isSafeInteger( row.reports ) && row.reports >= 1 &&
            Number.isSafeInteger( row.totalReports ) && row.totalReports >= row.reports &&
            [ 'normal', 'raid', 'unknown' ].indexOf( row.instance ) !== -1 &&
            ( text( row.item ) || cleanTitle( row.itemTitle ) );
    }
    function badge( row ) {
        var percent = ( row.reports / row.totalReports * 100 ).toFixed( 1 ).replace( /\.0$/, '' );
        var node = element( 'span', 'eql-drop-generated eql-drop-observed',
            ' (' + percent + '%)' );
        node.title = percent + '% share of reported loot; reported ' + row.reports + ' times out of ' + row.totalReports +
            ' recorded loot events; ' + scopeLabel( row ) + '. This is not a drop chance per kill.';
        node.setAttribute( 'aria-label', node.title );
        node.tabIndex = 0;
        return node;
    }
    function manualLists( root ) {
        return root.querySelectorAll( '.eql-drop-manual, .eql-mobpage-hero-loot ul, .eql-mobpage-loot ul, .eql-mobpage-common-loot ul' );
    }
    function plainItem( value ) {
        var label = value.trim(), rarity = /\s+\((?:common|uncommon|rare|very rare|ultra rare|unique|always)\)$/i.exec( label );
        if ( rarity ) { label = label.slice( 0, rarity.index ).trim(); }
        var title = label.replace( /[ \t]+/g, ' ' );
        // One whole literal title, never a partial match inside prose, URLs, markup or arbitrary notes.
        if ( !title || title.length > 230 || !/^[\p{L}\p{N}][\p{L}\p{N}\p{M} '’".+&-]*$/u.test( title ) ||
            /[.!?;]$/.test( title ) || /^(?:none|unknown|n\/a)$/i.test( title ) ||
            /^(?:(?:always|usually|often|sometimes|rarely|commonly|only|also)\s+drops?\b|(?:may|can|will)\s+drop\b|drops\s+(?!of\b)|drop\s+from\b|(?:common|rare|known|unique)\s+loot\b|(?:some|several|various|all|any)\s+(?:items?|drops?|loot|mobs?|monsters?|creatures?|humanoids?)\b|low[ -]level\s+(?:mobs?|monsters?|creatures?|humanoids?)\b|(?:see|notes?)\s+(?:also|below|above|that|the)\b|\d+\s+(?:pieces?|items?|copies?)\b)/i.test( title ) ) { return null; }
        return { label: label, title: title, start: value.indexOf( label ) };
    }
    function plainMarkup( node ) {
        return Array.prototype.every.call( node.childNodes, function ( child ) {
            return child.nodeType === 3 || child.nodeType === 8 || child.nodeType === 1 &&
                child.tagName === 'SPAN' && child.classList.contains( 'drare' ) && !child.children.length;
        } );
    }
    function primaryAnchor( li ) {
        return Array.prototype.find.call( li.querySelectorAll( 'a[href]' ), function ( candidate ) {
            if ( candidate.closest( '.hb, .ddb, .drare' ) || !anchorTitle( candidate ) ) { return false; }
            var before = document.createRange();before.selectNodeContents( li );before.setEndBefore( candidate );
            return !before.cloneContents().textContent.trim();
        } );
    }
    function normalizePlainBlocks( root ) {
        Array.prototype.forEach.call( root.querySelectorAll( '.eql-mobpage-hero-loot, .eql-mobpage-loot, .eql-mobpage-common-loot' ), function ( panel ) {
            Array.prototype.forEach.call( Array.prototype.slice.call( panel.childNodes ), function ( block ) {
                if ( block.nodeType !== 3 && !( block.nodeType === 1 && block.tagName === 'P' ) ) { return; }
                var lines = [ [] ], safe = true, nodes = block.nodeType === 3 ? [ block ] : block.childNodes;
                Array.prototype.forEach.call( nodes, function ( node ) {
                    if ( node.nodeType === 3 ) {
                        node.textContent.split( /\r?\n/ ).forEach( function ( part, index ) {
                            if ( index ) { lines.push( [] ); }lines[ lines.length - 1 ].push( part );
                        } );
                    } else if ( node.nodeType === 1 && node.tagName === 'BR' ) { lines.push( [] ); }
                    else if ( node.nodeType === 1 && ( node.tagName === 'A' || node.tagName === 'SPAN' &&
                        node.classList.contains( 'drare' ) && !node.children.length ) && !/[\r\n]/.test( node.textContent ) ) {
                        lines[ lines.length - 1 ].push( node );
                    } else { safe = false; }
                } );
                if ( !safe ) { return; }
                lines = lines.filter( function ( parts ) { return parts.some( function ( part ) { return ( typeof part === 'string' ? part : part.textContent ).trim(); } ); } );
                if ( !lines.length || !lines.every( function ( parts ) {
                    var test = element( 'li' );parts.forEach( function ( part ) { test.appendChild( typeof part === 'string' ? document.createTextNode( part ) : part.cloneNode( true ) ); } );
                    return Boolean( primaryAnchor( test ) || !test.querySelector( 'a' ) && plainMarkup( test ) && plainItem( test.textContent ) );
                } ) ) { return; }
                var list = element( 'ul', 'eql-drop-manual-list' );
                lines.forEach( function ( parts ) {
                    var li = element( 'li' );parts.forEach( function ( part ) { li.appendChild( typeof part === 'string' ? document.createTextNode( part ) : part ); } );list.appendChild( li );
                } );
                block.replaceWith( list );
            } );
        } );
    }
    function manualEntry( li ) {
        var anchor = primaryAnchor( li ), title = anchor && anchorTitle( anchor );
        if ( !title ) {
            var previous = li.querySelector( '.eql-drop-manual-name' );
            if ( previous ) { anchor = previous;title = cleanTitle( previous.dataset.dropTitle ); }
            else {
                if ( li.querySelector( 'a' ) || !plainMarkup( li ) ) { return null; }
                var parsed = plainItem( li.textContent );if ( !parsed ) { return null; }
                var walker = document.createTreeWalker( li, 4 ), offset = 0, range = document.createRange(), node, start = false;
                while ( ( node = walker.nextNode() ) ) {
                    var end = offset + node.textContent.length;
                    if ( !start && parsed.start < end ) { range.setStart( node, parsed.start - offset );start = true; }
                    if ( start && parsed.start + parsed.label.length <= end ) { range.setEnd( node, parsed.start + parsed.label.length - offset );break; }
                    offset = end;
                }
                var href = wikiUrl( parsed.title );anchor = element( href ? 'a' : 'span', 'eql-drop-manual-name' );
                if ( href ) { anchor.href = href;anchor.title = parsed.title; }anchor.dataset.dropTitle = parsed.title;
                anchor.appendChild( range.extractContents() );range.insertNode( anchor );title = parsed.title;
            }
        }
        if ( !title ) { return null; }
        li.classList.add( 'eql-drop-clean-item' );
        return { li: li, anchor: anchor, key: titleKey( title ), recorded: li.classList.contains( 'eql-recorded-loot-item' ) };
    }
    function moteName( value ) { return /^mote of(?:\s|$)/i.test( titleKey( value ).replace( /\s+/g, ' ' ) ); }
    function moteRow( row ) { return moteName( row.itemTitle ) || moteName( row.item ); }
    function manualItems( root ) {
        normalizePlainBlocks( root );
        var items = Object.create( null ), recorded = [];
        Array.prototype.forEach.call( manualLists( root ), function ( list ) {
            if ( list.closest( '.hb, .itembg, .itemtopbg, .itembotbg' ) ) { return; }
            Array.prototype.forEach.call( list.children, function ( li ) {
                if ( li.tagName !== 'LI' || li.classList.contains( 'eql-drop-generated' ) ) { return; }
                var entry = manualEntry( li );if ( !entry ) { return; }
                if ( entry.recorded ) { recorded.push( entry );return; }
                if ( !items[ entry.key ] ) { items[ entry.key ] = []; }items[ entry.key ].push( entry );
            } );
        } );
        recorded.forEach( function ( entry ) {
            var suppressed = moteName( entry.key ) || Boolean( items[ entry.key ] );
            entry.li.classList.toggle( 'eql-drop-suppressed-item', suppressed );
            if ( !suppressed ) { items[ entry.key ] = [ entry ]; }
        } );
        return items;
    }
    function rawAliases( rows ) {
        var aliases = Object.create( null );
        rows.forEach( function ( row ) {
            var raw = titleKey( cleanTitle( row.item ) ), canonical = titleKey( cleanTitle( row.itemTitle || row.item ) );
            if ( raw ) { aliases[ raw ] = aliases[ raw ] === undefined || aliases[ raw ] === canonical ? canonical : false; }
        } );return aliases;
    }
    function manualTarget( row, manual, aliases ) {
        var canonical = titleKey( cleanTitle( row.itemTitle || row.item ) ), raw = titleKey( cleanTitle( row.item ) );
        var entries = ( manual[ canonical ] || [] ).slice();
        if ( raw !== canonical && aliases[ raw ] === canonical ) { entries = entries.concat( manual[ raw ] || [] ); }
        var authored = entries.filter( function ( entry ) { return !entry.recorded; } );
        if ( authored.length ) {
            entries.forEach( function ( entry ) { if ( entry.recorded ) { entry.li.classList.add( 'eql-drop-suppressed-item' ); } } );return authored;
        }
        return entries.filter( function ( entry ) { return !entry.li.classList.contains( 'eql-drop-suppressed-item' ); } );
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
        var manual = manualItems( root );
        var npc = titleKey( root.dataset.dropNpc || mw.config.get( 'wgPageName' ) );
        rows = Array.isArray( rows ) ? rows.filter( function ( row ) {
            return validRow( row ) && ( !npc || titleKey( row.npcTitle || row.npc ) === npc );
        } ).slice( 0, 500 ) : [];
        var aliases = rawAliases( rows );
        rows = rows.filter( function ( row ) {
            if ( !moteRow( row ) ) { return true; }
            var authored = manualTarget( row, manual, aliases ).some( function ( entry ) { return !entry.recorded; } );
            [ row.itemTitle, row.item ].forEach( function ( value ) {
                ( manual[ titleKey( cleanTitle( value ) ) ] || [] ).forEach( function ( entry ) {
                    if ( entry.recorded ) { entry.li.classList.add( 'eql-drop-suppressed-item' ); }
                } );
            } );
            return authored;
        } );
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
        if ( contexts.length > 1 ) {
            var controls = element( 'div', 'eql-drop-generated eql-drop-observations' );
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
            var heading = panel.querySelector( 'h2, h3' );
            if ( heading && heading.parentNode === panel ) { heading.after( controls ); }
            else { panel.insertBefore( controls, panel.firstChild ); }
        }
        var selectedRows = groups[ selected ].rows;
        var appendList = panel.querySelector( 'ul:not(.eql-drop-generated-list)' );
        selectedRows.forEach( function ( row ) {
            var target = manualTarget( row, manual, aliases );
            if ( target.length ) {
                target.forEach( function ( entry ) {
                    var after = entry.anchor.closest( 'span.ih' ) || entry.anchor;
                    if ( !entry.li.contains( after ) ) { after = entry.anchor; }
                    while ( after.nextSibling && after.nextSibling.nodeType === 1 &&
                        after.nextSibling.classList.contains( 'eql-drop-observed' ) ) { after = after.nextSibling; }
                    after.after( badge( row ) );
                } );return;
            }
            if ( !appendList ) {
                appendList = element( 'ul', 'eql-drop-generated eql-drop-generated-list' );panel.appendChild( appendList );
            }
            var item = element( 'li', 'eql-drop-observed-item' );
            item.classList.add( 'eql-drop-generated' );
            item.appendChild( link( row ) );item.appendChild( badge( row ) );appendList.appendChild( item );
        } );
    }
    function load( root ) {
        if ( mounted.has( root ) ) { return; }
        mounted.add( root );
        manualItems( root );
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
