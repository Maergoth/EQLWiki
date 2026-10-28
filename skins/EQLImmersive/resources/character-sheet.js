/* EQL_CHARACTER_SHEET_V1: progressively enhance sanitized template HTML only. */
( function () {
    'use strict';
    var initialized = new WeakSet(), nextId = 0, flyout, activeItem = null, hideTimer = null;
    var effectCache = new Map(), effectQueue = [], effectBusy = false, effectApi, effectFailed = false;
    function cancelHide() {
        if ( hideTimer !== null ) { window.clearTimeout( hideTimer ); hideTimer = null; }
    }
    function hideInspect() {
        cancelHide();
        if ( flyout ) { flyout.hidden = true; flyout.replaceChildren(); }
        activeItem = null;
    }
    function deferHide() {
        cancelHide();
        hideTimer = window.setTimeout( function () {
            hideTimer = null;
            if ( flyout && flyout.contains( document.activeElement ) || activeItem && activeItem.contains( document.activeElement ) ) { return; }
            hideInspect();
        }, 200 );
    }
    function showInspect( item ) {
        cancelHide();
        var source = item.querySelector( ':scope > .eql-character-inspect-v1' );
        if ( !source ) { return; }
        simplifyAugments( source );
        if ( !flyout ) {
            flyout = document.createElement( 'div' );
            flyout.className = 'eql-character-inspect-flyout';
            flyout.setAttribute( 'role', 'dialog' );
            flyout.setAttribute( 'aria-label', 'Item details' );
            document.body.appendChild( flyout );
        }
        if ( activeItem !== item ) {
            var card = source.cloneNode( true );
            card.querySelectorAll( '[id]' ).forEach( function ( node ) { node.removeAttribute( 'id' ); } );
            flyout.replaceChildren( card );
            activeItem = item;
        }
        flyout.hidden = false;
        var bounds = item.getBoundingClientRect(), width = flyout.offsetWidth,
            height = flyout.offsetHeight, gap = 10;
        var left = bounds.right + gap;
        if ( left + width > window.innerWidth - gap ) { left = bounds.left - width - gap; }
        flyout.style.left = Math.max( gap, Math.min( left, window.innerWidth - width - gap ) ) + 'px';
        flyout.style.top = Math.max( gap, Math.min( bounds.top, window.innerHeight - height - gap ) ) + 'px';
    }
    function localUrl( value ) {
        if ( typeof value !== 'string' || !value.trim() ) { return null; }
        try {
            var url = new URL( value, window.location.href );
            return /^https?:$/.test( url.protocol ) && url.origin === window.location.origin ? url.href : null;
        } catch ( error ) { return null; }
    }
    function augmentEffects( source ) {
        var lines = [ document.createDocumentFragment() ], visited = 0, characters = 0;
        function read( node ) {
            if ( ++visited > 1000 || characters > 8192 || lines.length > 64 ) { return; }
            if ( node.nodeType === 3 ) {
                characters += node.data.length;
                if ( characters <= 8192 ) { lines[ lines.length - 1 ].appendChild( document.createTextNode( node.data ) ); }
            } else if ( node.nodeType === 1 ) {
                if ( node.tagName === 'BR' ) { lines.push( document.createDocumentFragment() ); }
                else if ( node.tagName === 'A' ) {
                    var href = localUrl( node.getAttribute( 'href' ) ), text = node.textContent;
                    characters += text.length;
                    if ( characters > 8192 ) { return; }
                    if ( href ) {
                        var link = document.createElement( 'a' );
                        link.href = href;link.textContent = text;
                        if ( node.hasAttribute( 'title' ) ) { link.title = node.getAttribute( 'title' ); }
                        lines[ lines.length - 1 ].appendChild( link );
                    } else { lines[ lines.length - 1 ].appendChild( document.createTextNode( text ) ); }
                } else if ( /^(SPAN|STRONG|EM|B|I|SMALL)$/.test( node.tagName ) ) {
                    Array.from( node.childNodes ).forEach( read );
                }
            }
        }
        Array.from( source.childNodes ).forEach( read );
        return lines.filter( function ( line ) {
            return /^(?:Effect|(?:Combat|Proc|Click|Worn|Focus)\s+Effect|(?:Focus|Proc|Rune)\s+Exaltation)\s*:\s*\S/i.test( line.textContent.trim() );
        } );
    }
    function simplifyAugments( root ) {
        root.querySelectorAll( '.eql-character-augments' ).forEach( function ( group ) {
            var heading = group.querySelector( ':scope > strong' );
            if ( heading ) { heading.textContent = 'Exaltations'; }
            group.querySelectorAll( ':scope > ul > li' ).forEach( function ( item ) {
                if ( item.classList.contains( 'eql-character-empty-socket' ) ) { item.remove();return; }
                if ( item.classList.contains( 'is-simplified' ) && !item.querySelector( '.eql-character-item-details,.eql-character-augment-stats,.eql-character-tier,.eql-character-augment-icon' ) ) { return; }
                var name = item.querySelector( 'strong' ), details = item.querySelector( '.eql-character-item-details' ) || item.querySelector( '.eql-character-augment-effects' );
                if ( !name ) { item.remove();return; }
                var effects = details ? augmentEffects( details ) : [], content = document.createElement( 'span' );
                content.className = 'eql-character-augment-effects';
                effects.forEach( function ( effect, index ) {
                    if ( index ) { content.appendChild( document.createElement( 'br' ) ); }
                    content.appendChild( effect );
                } );
                item.replaceChildren( name.cloneNode( true ) );
                if ( effects.length ) { item.appendChild( content ); }
                item.classList.add( 'is-simplified' );
            } );
            if ( !group.querySelector( ':scope > ul > li' ) ) { group.remove(); }
        } );
    }
    function wikiUrl( title ) {
        var url, articlePath, encoded = encodeURIComponent( title.replace( / /g, '_' ) );
        if ( typeof mw !== 'undefined' ) {
            try {
                if ( mw.util && typeof mw.util.getUrl === 'function' ) {
                    url = localUrl( mw.util.getUrl( title ) );
                    if ( url ) { return url; }
                }
            } catch ( error ) { /* The article-path fallback also works without mediawiki.util. */ }
            try {
                articlePath = mw.config && mw.config.get( 'wgArticlePath' );
                if ( typeof articlePath === 'string' && articlePath.indexOf( '$1' ) !== -1 ) {
                    url = localUrl( articlePath.replace( /\$1/g, encoded ) );
                    if ( url ) { return url; }
                }
            } catch ( error ) { /* Keep a local navigation link when configuration is unavailable. */ }
        }
        return new URL( '/index.php?title=' + encoded, window.location.href ).href;
    }
    function titleText( value ) {
        if ( typeof value !== 'string' ) { return ''; }
        value = value.replace( /_/g, ' ' ).replace( /\s+/g, ' ' ).trim();
        return value && value.length <= 240 && !/[\u0000-\u001f\u007f\[\]{}|#<>]/.test( value ) ? value : '';
    }
    function titleKey( title ) { return title.charAt( 0 ).toUpperCase() + title.slice( 1 ); }
    function linkTitle( link ) {
        if ( !link || !localUrl( link.getAttribute( 'href' ) ) ) { return ''; }
        var url = new URL( link.href ), title = url.searchParams.get( 'title' );
        if ( !title ) { title = link.getAttribute( 'title' ) || link.textContent; }
        return titleText( title );
    }
    function effectLabel( label ) {
        var labels = { effect:'Effect', 'focus effect':'Focus effect', 'combat effect':'Combat effect', 'click effect':'Click effect', 'worn effect':'Worn effect', 'proc effect':'Proc effect' };
        return labels[ String( label ).replace( /\s+/g, ' ' ).trim().toLowerCase() ] || '';
    }
    function runEffectBatch() {
        if ( effectBusy || !effectQueue.length ) { return; }
        effectBusy = true;
        var batch = effectQueue.splice( 0, 50 ), request;
        try {
            if ( effectFailed ) { throw new Error( 'Effect metadata unavailable.' ); }
            effectApi = effectApi || new mw.Api();
            request = effectApi.post( { action:'eqlcharactereffects', titles:batch.map( function ( item ) { return item.title; } ).join( '|' ), formatversion:2 } );
        } catch ( error ) { request = Promise.reject( error ); }
        Promise.resolve( request ).then( function ( data ) {
            var rows = data && data.eqlcharactereffects && data.eqlcharactereffects.items;
            batch.forEach( function ( item ) {
                var record = Array.isArray( rows ) && rows.slice( 0, 50 ).find( function ( row ) { return row && titleKey( titleText( row.title ) ) === item.key; } );
                var effects = record && Array.isArray( record.effects ) ? record.effects.slice( 0, 32 ).filter( function ( row ) {
                    return row && effectLabel( row.label ) && typeof row.value === 'string' && row.value.trim() && row.value.length <= 1000 && !/[\u0000-\u001f\u007f]/.test( row.value );
                } ) : [];
                item.resolve( effects );
            } );
        } ).catch( function () {
            effectFailed = true;batch.forEach( function ( item ) { item.resolve( [] ); } );
        } ).then( function () { effectBusy = false;runEffectBatch(); } );
    }
    function requestEffects( title ) {
        var key = titleKey( title ), cached = effectCache.get( key );
        if ( cached ) { return cached; }
        // One bounded pass per document. A hover never starts an API request.
        if ( effectCache.size >= 2000 || effectFailed ) { return Promise.resolve( [] ); }
        var resolve, promise = new Promise( function ( done ) { resolve = done; } );
        effectCache.set( key, promise );effectQueue.push( { title:title, key:key, resolve:resolve } );
        Promise.resolve().then( runEffectBatch );
        return promise;
    }
    function appendEffect( details, row ) {
        if ( details.textContent.trim() ) { details.appendChild( document.createElement( 'br' ) ); }
        details.appendChild( document.createTextNode( effectLabel( row.label ) + ': ' ) );
        var title = titleText( row.linkTitle ), value = row.value.trim();
        if ( title ) {
            var at = value.indexOf( title ), link = document.createElement( 'a' );
            link.href = wikiUrl( title );link.title = title;
            if ( at >= 0 ) {
                details.appendChild( document.createTextNode( value.slice( 0, at ) ) );link.textContent = title;
                details.appendChild( link );details.appendChild( document.createTextNode( value.slice( at + title.length ) ) );
            } else { link.textContent = value;details.appendChild( link ); }
        } else { details.appendChild( document.createTextNode( value ) ); }
    }
    function hydrateEffects( sheet ) {
        if ( typeof mw === 'undefined' || typeof mw.Api !== 'function' ) { return; }
        var targets = [];
        sheet.querySelectorAll( '.eql-character-inspect-v1' ).forEach( function ( inspect ) {
            targets.push( { node:inspect, link:inspect.querySelector( ':scope > strong > a' ), socket:false } );
            inspect.querySelectorAll( '.eql-character-augments > ul > li' ).forEach( function ( socket ) {
                targets.push( { node:socket, link:socket.querySelector( ':scope > strong > a' ), socket:true } );
            } );
        } );
        targets.forEach( function ( target ) {
            var title = linkTitle( target.link );
            if ( !title ) { return; }
            requestEffects( title ).then( function ( rows ) {
                if ( !target.node.isConnected || !sheet.contains( target.node ) ) { return; }
                // Content hooks may reuse an inspect or socket while replacing its item.
                // Read the current direct name link, not the possibly detached old anchor.
                if ( titleKey( linkTitle( target.node.querySelector( ':scope > strong > a' ) ) ) !== titleKey( title ) ) { return; }
                var selector = target.socket ? '.eql-character-augment-effects' : '.eql-character-item-details';
                var details = target.node.querySelector( ':scope > ' + selector ), known = new Set();
                if ( details ) { augmentEffects( details ).forEach( function ( line ) { known.add( ( effectLabel( line.textContent.split( ':', 1 )[ 0 ] ) || line.textContent.split( ':', 1 )[ 0 ].trim() ).toLowerCase() ); } ); }
                var added = false;
                rows.forEach( function ( row ) {
                    var label = effectLabel( row.label );
                    if ( known.has( label.toLowerCase() ) ) { return; }
                    if ( !details ) { details = document.createElement( 'span' );details.className = selector.slice( 1 );target.node.appendChild( details ); }
                    var key = label.toLowerCase() + ':' + row.value.replace( /\s+/g, ' ' ).trim().toLowerCase();
                    if ( Array.from( details.childNodes ).some( function ( node ) { return node.nodeType === 1 && node.dataset && node.dataset.eqlEffect === key; } ) ) { return; }
                    var line = document.createElement( 'span' );line.dataset.eqlEffect = key;appendEffect( line, row );
                    if ( details.textContent.trim() ) { details.appendChild( document.createElement( 'br' ) ); }
                    details.appendChild( line );added = true;
                } );
                if ( added && activeItem && activeItem.contains( target.node ) && flyout && !flyout.contains( document.activeElement ) ) {
                    var item = activeItem;activeItem = null;showInspect( item );
                }
            } );
        } );
    }
    function addPlayerLink( sheet ) {
        var equipment = sheet.querySelector( '.eql-character-equipment' );
        if ( !equipment || sheet.querySelector( '.eql-character-players-cta' ) ) { return; }
        var link = document.createElement( 'a' );
        link.className = 'eql-character-players-cta';
        link.href = wikiUrl( 'Player_List' );
        link.setAttribute( 'aria-label', 'View Other Players' );
        // Original decorative artwork; no uploaded item icons or game art are changed.
        link.innerHTML = '<span class="eql-character-players-inlay" aria-hidden="true"></span>' +
            '<span class="eql-character-players-gem" aria-hidden="true"></span>' +
            '<span class="eql-character-players-gem eql-character-players-gem-right" aria-hidden="true"></span>' +
            '<span class="eql-character-players-crest" aria-hidden="true">' +
            '<svg viewBox="0 0 80 90" focusable="false" aria-hidden="true">' +
            '<path d="m40 3 27 12 10 30-10 30-27 12-27-12L3 45l10-30Z" fill="#101b25" stroke="currentColor" stroke-width="1.5"/>' +
            '<circle cx="40" cy="45" r="34" fill="none" stroke="currentColor" stroke-width="1.5"/>' +
            '<circle cx="40" cy="45" r="29" fill="none" stroke="currentColor" opacity=".45"/>' +
            '<g fill="currentColor"><circle cx="24" cy="40" r="5.5"/><circle cx="56" cy="40" r="5.5"/>' +
            '<path d="M17 57v-6a7 7 0 0 1 14 0v6ZM49 57v-6a7 7 0 0 1 14 0v6Z" opacity=".65"/>' +
            '<circle cx="40" cy="33" r="8"/><path d="M29 62V49a11 11 0 0 1 22 0v13Z"/></g>' +
            '<path d="m36 70 4-3 4 3-4 3Z" fill="currentColor"/></svg></span>' +
            '<span class="eql-character-players-copy"><strong>View Other Players</strong></span>';
        equipment.appendChild( link );
    }
    function achievementCount( section, sheet ) {
        var summaries = Array.from( section.querySelectorAll( ':scope > .eql-character-achievements-body > p.eql-character-achievements-summary' ) ).filter( function ( node ) {
            return node.closest( '.eql-character-achievements-v1' ) === section && node.closest( '.eql-character-sheet-v1' ) === sheet;
        } );
        if ( summaries.length !== 1 || summaries[ 0 ].textContent.length > 100 ) { return null; }
        var match = summaries[ 0 ].textContent.replace( /\s+/g, ' ' ).trim().match( /^(0|[1-9]\d{0,9})\s*\/\s*(0|[1-9]\d{0,9}) achievements complete$/ );
        if ( !match ) { return null; }
        var completed = Number( match[ 1 ] ), total = Number( match[ 2 ] );
        return completed <= total && total <= 1000000000 ? completed : null;
    }
    function achievementBadge( heading, count ) {
        if ( !heading ) { return; }
        var badge = heading.querySelector( ':scope > .eql-character-achievement-count' );
        if ( count === null ) { if ( badge ) { badge.remove(); } return; }
        if ( !badge ) {
            badge = document.createElement( 'span' );
            badge.className = 'eql-character-achievement-count';
            var number = document.createElement( 'span' ), label = document.createElement( 'small' );
            number.className = 'eql-character-achievement-count-number';
            label.textContent = heading.classList.contains( 'eql-character-achievements-disclosure' ) ? 'Complete' : 'Achievements';
            badge.append( number, label );
            heading.appendChild( badge );
        }
        badge.setAttribute( 'aria-label', count.toLocaleString() + ' completed achievements' );
        badge.querySelector( '.eql-character-achievement-count-number' ).textContent = count.toLocaleString();
    }
    // Static template content is readable without JavaScript. Enhancement adds
    // independent native buttons; neither achievements nor categories save any
    // account/browser state or change the completion recorded in the export.
    function enhanceAchievements( sheet ) {
        function disclosure( section, headingClass, bodyClass ) {
            if ( section.querySelector( ':scope > .eql-character-achievements-disclosure' ) ) { return; }
            var heading = section.querySelector( ':scope > .' + headingClass ),
                body = section.querySelector( ':scope > .' + bodyClass );
            if ( !heading || !body ) { return; }
            var toggle = document.createElement( 'button' );
            toggle.type = 'button';
            toggle.className = 'eql-character-achievements-disclosure';
            body.id = 'eql-character-achievements-body-' + ( ++nextId );
            toggle.setAttribute( 'aria-controls', body.id );
            toggle.setAttribute( 'aria-expanded', 'false' );
            while ( heading.firstChild ) { toggle.appendChild( heading.firstChild ); }
            heading.replaceWith( toggle );
            body.hidden = true;
            toggle.addEventListener( 'click', function () {
                body.hidden = !body.hidden;
                toggle.setAttribute( 'aria-expanded', String( !body.hidden ) );
            } );
        }
        var sections = Array.from( sheet.querySelectorAll( ':scope > .eql-character-body > .eql-character-achievements-v1' ) ).filter( function ( section ) {
            return section.closest( '.eql-character-sheet-v1' ) === sheet;
        } );
        achievementBadge( sheet.querySelector( ':scope > .eql-character-heading, :scope > .eql-character-disclosure' ), sections.length === 1 ? achievementCount( sections[ 0 ], sheet ) : null );
        sections.forEach( function ( section ) {
            disclosure( section, 'eql-character-achievements-heading', 'eql-character-achievements-body' );
            achievementBadge( section.querySelector( ':scope > .eql-character-achievements-disclosure' ), achievementCount( section, sheet ) );
            section.querySelectorAll( '.eql-character-achievement-category' ).forEach( function ( category ) {
                disclosure( category, 'eql-character-achievement-category-heading', 'eql-character-achievement-list' );
            } );
        } );
    }
    function initialize( root ) {
        root = root && root.querySelectorAll ? root : document;
        var sheets = Array.from( root.querySelectorAll( '.eql-character-sheet-v1' ) );
        if ( root.matches && root.matches( '.eql-character-sheet-v1' ) ) { sheets.unshift( root ); }
        var parentSheet = root.closest && root.closest( '.eql-character-sheet-v1' );
        if ( parentSheet && sheets.indexOf( parentSheet ) === -1 ) { sheets.unshift( parentSheet ); }
        // A refreshed fragment may contain one sheet on a page with several.
        // Include detached previews too, without changing initialized disclosures.
        var pageSheets = Array.from( document.querySelectorAll( '.eql-character-sheet-v1:not([data-eql-player-preview="1"])' ) );
        sheets.forEach( function ( sheet ) { if ( sheet.dataset.eqlPlayerPreview !== '1' && pageSheets.indexOf( sheet ) === -1 ) { pageSheets.push( sheet ); } } );
        var initiallyExpanded = pageSheets.length === 1;
        sheets.forEach( function ( sheet ) {
            // Typed directory previews share item inspection only, not profile disclosures,
            // directory links or per-item effect requests. They have their own lazy lifecycle.
            if ( sheet.dataset.eqlPlayerPreview === '1' ) { return; }
            simplifyAugments( sheet );
            addPlayerLink( sheet );
            hydrateEffects( sheet );
            enhanceAchievements( sheet );
            if ( initialized.has( sheet ) ) { return; }
            var heading = sheet.querySelector( ':scope > .eql-character-heading' ),
                body = sheet.querySelector( ':scope > .eql-character-body' );
            if ( !heading || !body ) { return; }
            initialized.add( sheet );
            sheet.classList.add( 'is-enhanced' );
            var toggle = document.createElement( 'button' );
            toggle.type = 'button';
            toggle.className = 'eql-character-disclosure';
            body.id = 'eql-character-sheet-body-' + ( ++nextId );
            toggle.setAttribute( 'aria-controls', body.id );
            toggle.setAttribute( 'aria-expanded', String( initiallyExpanded ) );
            while ( heading.firstChild ) { toggle.appendChild( heading.firstChild ); }
            heading.replaceWith( toggle );
            body.hidden = !initiallyExpanded;
            toggle.addEventListener( 'click', function () {
                body.hidden = !body.hidden;
                toggle.setAttribute( 'aria-expanded', String( !body.hidden ) );
                hideInspect();
            } );
        } );
    }
    document.addEventListener( 'pointerover', function ( event ) {
        var item = event.target.closest( '.eql-character-sheet-v1 .eql-character-item' );
        if ( item ) { showInspect( item ); }
        else if ( flyout && flyout.contains( event.target ) ) { cancelHide(); }
        else { deferHide(); }
    } );
    document.addEventListener( 'focusin', function ( event ) {
        var item = event.target.closest( '.eql-character-sheet-v1 .eql-character-item' );
        if ( item ) { showInspect( item ); }
        else if ( flyout && flyout.contains( event.target ) ) { cancelHide(); }
        else { hideInspect(); }
    } );
    document.addEventListener( 'keydown', function ( event ) {
        if ( event.key === 'ArrowDown' && activeItem && activeItem.contains( event.target ) && flyout && !flyout.hidden ) {
            var firstLink = flyout.querySelector( 'a' );
            if ( firstLink ) { event.preventDefault();firstLink.focus(); }
        }
        if ( event.key === 'Escape' ) {
            var returnLink = flyout && flyout.contains( document.activeElement ) && activeItem && activeItem.querySelector( 'a' );
            if ( returnLink ) { returnLink.focus(); }
            hideInspect();
        }
    } );
    document.addEventListener( 'scroll', function ( event ) {
        if ( !flyout || !flyout.contains( event.target ) ) { hideInspect(); }
    }, true );
    window.addEventListener( 'resize', hideInspect );
    document.addEventListener( 'eql-character-preview-close', hideInspect );
    if ( typeof mw !== 'undefined' && mw.hook ) {
        mw.hook( 'wikipage.content' ).add( function ( content ) { initialize( content && content[ 0 ] || content ); } );
    }
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function () { initialize( document ); }, { once: true } );
    } else { initialize( document ); }
}() );
