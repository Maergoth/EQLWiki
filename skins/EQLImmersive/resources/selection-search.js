/* Search selected article text through the wiki's normal search page.
 * @license GPL-2.0-or-later
 */
( function () {
	'use strict';
	function init() {
		if ( !document.body.classList.contains( 'skin-eqlimmersive' ) || mw.config.get( 'wgAction' ) !== 'view' ) { return; }
		var action = document.createElement( 'a' );
		action.id = 'eql-selection-search';
		action.className = 'eql-selection-search';
		action.hidden = true;
		document.body.appendChild( action );
		var scheduled = false;
		var dismissed = '';
		var excluded = 'input, textarea, select, button, [contenteditable], .mw-editsection, .hb, .item-hoverbox, .toc, nav, script, style, [hidden], [aria-hidden="true"]';
		function hide() { action.hidden = true; }
		function articleRoot( node ) {
			var element = node && ( node.nodeType === 1 ? node : node.parentElement );
			if ( !element || element.closest( excluded ) ) { return null; }
			return element.closest( '#mw-content-text .mw-parser-output' );
		}
		function refresh() {
			scheduled = false;
			if ( document.activeElement === action ) { return; }
			if ( document.documentElement.classList.contains( 've-activated' ) ) { hide(); return; }
			var selection = window.getSelection();
			if ( !selection || selection.isCollapsed || selection.rangeCount !== 1 ) { dismissed = ''; hide(); return; }
			var text = selection.toString().replace( /\s+/g, ' ' ).trim();
			var start = articleRoot( selection.anchorNode );
			if ( !text || text.length > 120 || text === dismissed || !start || start !== articleRoot( selection.focusNode ) ) { hide(); return; }
			var range = selection.getRangeAt( 0 );
			var common = range.commonAncestorContainer;
			if ( common.nodeType === 1 && Array.from( common.querySelectorAll( excluded ) ).some( function ( node ) { return range.intersectsNode( node ); } ) ) { hide(); return; }
			var rect = range.getBoundingClientRect();
			if ( !rect || !rect.width || rect.bottom < 0 || rect.top > window.innerHeight ) { hide(); return; }
			action.textContent = 'Search wiki: “' + ( text.length > 36 ? text.slice( 0, 36 ) + '…' : text ) + '”';
			action.setAttribute( 'aria-label', 'Search wiki for ' + text );
			action.title = 'Search wiki for ' + text;
			action.href = mw.util.getUrl( 'Special:Search', { search: text, fulltext: 1 } );
			action.hidden = false;
			var box = action.getBoundingClientRect();
			var header = document.querySelector( '.vector-header-container:not(.vector-sticky-header-container)' );
			var ceiling = Math.max( 8, header ? header.getBoundingClientRect().bottom + 8 : 8 );
			if ( ceiling + box.height > window.innerHeight - 48 ) { hide(); return; }
			var left = Math.max( 8, Math.min( rect.left, window.innerWidth - box.width - 8 ) );
			var top = rect.bottom + 8;
			if ( top + box.height > window.innerHeight - 48 ) { top = rect.top - box.height - 8; }
			top = Math.max( ceiling, Math.min( top, window.innerHeight - box.height - 48 ) );
			action.style.left = Math.round( left ) + 'px';
			action.style.top = Math.round( top ) + 'px';
		}
		function schedule() {
			if ( !scheduled ) { scheduled = true; window.requestAnimationFrame( refresh ); }
		}
		function dismiss() { dismissed = String( window.getSelection() ).replace( /\s+/g, ' ' ).trim(); hide(); }
		document.addEventListener( 'selectionchange', schedule );
		document.addEventListener( 'mouseup', schedule );
		document.addEventListener( 'touchend', schedule, { passive: true } );
		document.addEventListener( 'keyup', function ( event ) {
			if ( event.key === 'Escape' ) { dismiss(); }
			else { schedule(); }
		} );
		document.addEventListener( 'pointerdown', function ( event ) {
			if ( !action.contains( event.target ) ) { hide(); }
		} );
		action.addEventListener( 'mousedown', function ( event ) { event.preventDefault(); } );
		window.addEventListener( 'scroll', dismiss, { passive: true } );
		window.addEventListener( 'resize', dismiss );
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
}() );
