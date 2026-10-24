/* EQL_CHARACTER_SHEET_V1: progressively enhance sanitized template HTML only. */
( function () {
    'use strict';
    var initialized = new WeakSet(), nextId = 0, flyout, activeItem = null, hideTimer = null;
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
    function initialize( root ) {
        root = root && root.querySelectorAll ? root : document;
        var sheets = Array.from( root.querySelectorAll( '.eql-character-sheet-v1' ) );
        if ( root.matches && root.matches( '.eql-character-sheet-v1' ) ) { sheets.unshift( root ); }
        sheets.forEach( function ( sheet ) {
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
            toggle.setAttribute( 'aria-expanded', 'false' );
            while ( heading.firstChild ) { toggle.appendChild( heading.firstChild ); }
            heading.replaceWith( toggle );
            body.hidden = true;
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
    if ( typeof mw !== 'undefined' && mw.hook ) {
        mw.hook( 'wikipage.content' ).add( function ( content ) { initialize( content && content[ 0 ] || content ); } );
    }
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function () { initialize( document ); }, { once: true } );
    } else { initialize( document ); }
}() );
