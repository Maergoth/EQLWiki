/* EQLImmersive: Final TOC H1 override.
 * Authoritative custom side TOC rebuilder. Collects all article headings
 * (H1-H6), clears and rebuilds the side menu TOC, observes mutations
 * for dynamic content. Applies TOC limit classes. */
/* =====================================================
   EQL Wiki — FINAL TOC H1 override for custom side TOC
   Append this at the VERY BOTTOM of MediaWiki:Common.js.

   Purpose:
   - Rebuilds the EQL custom side TOC from the rendered article headings.
   - Includes content H1 headings (= Heading =), not only H2/H3.
   - Re-applies itself after late skin/Vector scripts repopulate the TOC.
   - Supports {{TOC limit|1}} through {{TOC limit|6}} via body classes.
   ===================================================== */

(function () {
	'use strict';

	var CONTAINER_MARK = 'data-eql-toc-h1-override';
	var ITEM_CLASS = 'eql-toc-h1-override-item';
	var rebuilding = false;
	var scheduled = false;
	var containerObserver = null;
	var pageObserver = null;
	var lastSignature = '';

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	function getArticleRoot() {
		return document.querySelector( '#mw-content-text .mw-parser-output' ) ||
			document.querySelector( '.mw-parser-output' );
	}

	function directChildHeading( node ) {
		var i;
		var child;
		var className;
		var match;

		if ( !node || node.nodeType !== 1 ) {
			return null;
		}

		if ( /^H[1-6]$/.test( node.tagName ) ) {
			return node;
		}

		className = String( node.className || '' );
		match = className.match( /\bmw-heading([1-6])\b/ );

		if ( match ) {
			for ( i = 0; i < node.children.length; i++ ) {
				child = node.children[ i ];

				if ( child && child.tagName === 'H' + match[ 1 ] ) {
					return child;
				}
			}

			return node.querySelector( 'h1, h2, h3, h4, h5, h6' );
		}

		return null;
	}

	function headingLevel( heading ) {
		if ( !heading || !/^H[1-6]$/.test( heading.tagName ) ) {
			return null;
		}

		return parseInt( heading.tagName.slice( 1 ), 10 );
	}

	function cleanHeadingText( heading ) {
		var clone;

		if ( !heading ) {
			return '';
		}

		clone = heading.cloneNode( true );

		Array.prototype.forEach.call(
			clone.querySelectorAll( '.mw-editsection, .mw-headline-number, .mw-editsection-bracket, .mw-editsection-divider' ),
			function ( node ) {
				if ( node.parentNode ) {
					node.parentNode.removeChild( node );
				}
			}
		);

		return String( clone.textContent || '' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function makeSafeId( text ) {
		var base = String( text || '' )
			.toLowerCase()
			.replace( /&/g, ' and ' )
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' );
		var id;
		var i = 2;

		if ( !base ) {
			base = 'heading';
		}

		id = 'eql-heading-' + base;

		while ( document.getElementById( id ) ) {
			id = 'eql-heading-' + base + '-' + i;
			i++;
		}

		return id;
	}

	function ensureHeadingId( heading, text ) {
		var headline;

		if ( !heading ) {
			return '';
		}

		if ( heading.id ) {
			return heading.id;
		}

		headline = heading.querySelector( '.mw-headline[id]' );

		if ( headline && headline.id ) {
			return headline.id;
		}

		heading.id = makeSafeId( text );

		return heading.id;
	}

	function shouldSkipHeading( heading, text ) {
		if ( !heading || !text ) {
			return true;
		}

		if (
			heading.closest( '#toc, .toc, .mw-toc, .vector-toc, .eql-main-menu, .eql-build-ability-panel-content' )
		) {
			return true;
		}

		return false;
	}

	function collectHeadings() {
		var root = getArticleRoot();
		var headings = [];

		if ( !root ) {
			return headings;
		}

		Array.prototype.forEach.call( root.children, function ( child ) {
			var heading = directChildHeading( child );
			var level = headingLevel( heading );
			var text;
			var id;

			if ( !level ) {
				return;
			}

			text = cleanHeadingText( heading );

			if ( shouldSkipHeading( heading, text ) ) {
				return;
			}

			id = ensureHeadingId( heading, text );

			if ( !id ) {
				return;
			}

			headings.push( {
				level: level,
				text: text,
				id: id
			} );
		} );

		return headings;
	}

	function findTocContainer() {
		var selectors = [
			'.eql-main-menu-toc',
			'#eql-main-menu-toc',
			'[data-eql-main-menu-toc]',
			'.eql-main-menu-toc-list',
			'.eql-menu-toc-list',
			'.eql-toc-list'
		];
		var i;
		var container;
		var item;
		var itemSelectors = [
			'.eql-main-menu-toc-level-1',
			'.eql-main-menu-toc-level-2',
			'.eql-main-menu-toc-level-3',
			'.eql-main-menu-toc-level-4',
			'.eql-main-menu-toc-level-5',
			'.eql-main-menu-toc-level-6',
			'.vector-toc-level-1',
			'.vector-toc-level-2',
			'.vector-toc-level-3',
			'.vector-toc-level-4',
			'.vector-toc-level-5',
			'.vector-toc-level-6'
		].join( ',' );

		for ( i = 0; i < selectors.length; i++ ) {
			container = document.querySelector( selectors[ i ] );

			if ( container ) {
				return container;
			}
		}

		item = document.querySelector( itemSelectors );

		if ( item && item.parentElement ) {
			return item.parentElement;
		}

		return null;
	}

	function clearContainer( container ) {
		while ( container.firstChild ) {
			container.removeChild( container.firstChild );
		}
	}

	function fragmentHref( id ) {
		return '#' + String( id || '' )
			.replace( /%/g, '%25' )
			.replace( / /g, '_' );
	}

	function makeTocEntry( heading, index, listMode ) {
		var wrapper;
		var link;
		var number;
		var text;

		if ( listMode ) {
			wrapper = document.createElement( 'li' );
			link = document.createElement( 'a' );
			wrapper.appendChild( link );
		} else {
			wrapper = document.createElement( 'a' );
			link = wrapper;
		}

		wrapper.className = [
			'eql-main-menu-toc-item',
			'eql-main-menu-toc-level-' + heading.level,
			'vector-toc-level-' + heading.level,
			ITEM_CLASS,
			'eql-toc-h' + heading.level
		].join( ' ' );

		wrapper.setAttribute( 'data-eql-toc-heading-id', heading.id );
		wrapper.setAttribute( 'data-eql-toc-heading-level', String( heading.level ) );

		link.className = 'eql-main-menu-toc-link eql-toc-h1-override-link';
		link.href = fragmentHref( heading.id );

		number = document.createElement( 'span' );
		number.className = 'eql-main-menu-toc-number eql-toc-h1-override-number';
		number.textContent = String( index + 1 );

		text = document.createElement( 'span' );
		text.className = 'eql-main-menu-toc-text eql-toc-h1-override-text';
		text.textContent = heading.text;

		link.appendChild( number );
		link.appendChild( text );

		return wrapper;
	}

	function signatureFor( headings ) {
		return headings.map( function ( heading ) {
			return heading.level + ':' + heading.id + ':' + heading.text;
		} ).join( '|' );
	}

	function currentContainerSignature( container ) {
		return Array.prototype.map.call(
			container.querySelectorAll( '.' + ITEM_CLASS ),
			function ( item ) {
				var link = item.querySelector( '.eql-toc-h1-override-link' );
				var text = item.querySelector( '.eql-toc-h1-override-text' );

				return item.getAttribute( 'data-eql-toc-heading-level' ) + ':' +
					item.getAttribute( 'data-eql-toc-heading-id' ) + ':' +
					( text ? text.textContent : '' ) + ':' +
					( link ? link.getAttribute( 'href' ) : '' );
			}
		).join( '|' );
	}

	function applyTocLimitClass() {
		var root = getArticleRoot();
		var marker;
		var match;
		var i;

		if ( !document.body ) {
			return;
		}

		for ( i = 1; i <= 6; i++ ) {
			document.body.classList.remove( 'eql-page-toclimit-' + i );
		}

		if ( !root ) {
			return;
		}

		marker = root.querySelector( '[class*="toclimit-"]' );

		if ( !marker ) {
			return;
		}

		match = String( marker.className || '' ).match( /\btoclimit-([1-6])\b/ );

		if ( match ) {
			document.body.classList.add( 'eql-page-toclimit-' + match[ 1 ] );
		}
	}

	function observeContainer( container ) {
		if ( containerObserver ) {
			containerObserver.disconnect();
			containerObserver = null;
		}

		if ( !container || !window.MutationObserver ) {
			return;
		}

		containerObserver = new MutationObserver( function () {
			if ( rebuilding ) {
				return;
			}

			scheduleRebuild();
		} );

		containerObserver.observe( container, {
			childList: true,
			subtree: true,
			characterData: true
		} );
	}

	function observePageContent() {
		var root = getArticleRoot();

		if ( pageObserver ) {
			pageObserver.disconnect();
			pageObserver = null;
		}

		if ( !root || !window.MutationObserver ) {
			return;
		}

		pageObserver = new MutationObserver( function () {
			if ( rebuilding ) {
				return;
			}

			scheduleRebuild();
		} );

		pageObserver.observe( root, {
			childList: true,
			subtree: false
		} );
	}

	function rebuildToc() {
		var headings = collectHeadings();
		var container = findTocContainer();
		var listMode;
		var signature;

		scheduled = false;
		applyTocLimitClass();

		if ( !container || headings.length === 0 ) {
			return;
		}

		signature = signatureFor( headings );

		if (
			container.getAttribute( CONTAINER_MARK ) === '1' &&
			lastSignature === signature &&
			currentContainerSignature( container ).indexOf( headings[ 0 ].id ) !== -1
		) {
			observeContainer( container );
			observePageContent();
			return;
		}

		rebuilding = true;

		try {
			listMode = /^(UL|OL)$/i.test( container.tagName );

			clearContainer( container );

			headings.forEach( function ( heading, index ) {
				container.appendChild( makeTocEntry( heading, index, listMode ) );
			} );

			container.setAttribute( CONTAINER_MARK, '1' );
			container.setAttribute( 'data-eql-toc-heading-count', String( headings.length ) );
			document.body.classList.add( 'eql-toc-h1-override-ready' );
			lastSignature = signature;
		} finally {
			rebuilding = false;
		}

		observeContainer( container );
		observePageContent();
	}

	function scheduleRebuild() {
		if ( scheduled ) {
			return;
		}

		scheduled = true;

		window.setTimeout( rebuildToc, 25 );
	}

	function install() {
		var delays = [ 0, 50, 150, 300, 600, 1000, 1600, 2500, 4000, 6500 ];

		delays.forEach( function ( delay ) {
			window.setTimeout( scheduleRebuild, delay );
		} );

		if ( window.requestAnimationFrame ) {
			window.requestAnimationFrame( scheduleRebuild );
		}

		window.addEventListener( 'load', function () {
			scheduleRebuild();
			window.setTimeout( scheduleRebuild, 250 );
			window.setTimeout( scheduleRebuild, 1000 );
		} );

		if ( window.mw && mw.hook ) {
			mw.hook( 'wikipage.content' ).add( function () {
				scheduleRebuild();
				window.setTimeout( scheduleRebuild, 200 );
			} );
		}
	}

	ready( install );
}());