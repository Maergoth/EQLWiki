/* EQLImmersive: Spell list lazy loader.
 * On Category:Spells, loads each class spell section via API parse
 * only when its collapsible section is expanded. */
/* =====================================================
   EQL Spellpage item-hover stabilizer

   Item transclusions inside Spellpage "Items with Spell Effect"
   use .hbdiv > span.hb.

   Behavior:
   - only one item hover can be open at a time
   - position once when opened
   - keep open while mouse is over the item link or tooltip
   - close when mouse leaves both
   - close on scroll, resize, click elsewhere, or Escape
   ===================================================== */

(function () {
	'use strict';

	var activeWrapper = null;
	var activeHover = null;
	var closeTimer = null;
	var closeDelay = 90;

	function getDirectHoverSpan( wrapper ) {
		var children = wrapper ? wrapper.children : [];
		var i;

		for ( i = 0; i < children.length; i++ ) {
			if (
				children[ i ].tagName === 'SPAN' &&
				children[ i ].classList &&
				children[ i ].classList.contains( 'hb' )
			) {
				return children[ i ];
			}
		}

		return null;
	}

	function clearCloseTimer() {
		if ( closeTimer ) {
			window.clearTimeout( closeTimer );
			closeTimer = null;
		}
	}

	function closeActiveHover() {
		clearCloseTimer();

		if ( activeWrapper ) {
			activeWrapper.classList.remove( 'eql-spellpage-item-hover-active' );
			delete activeWrapper.dataset.eqlSpellpageItemHoverAnchored;
		}

		activeWrapper = null;
		activeHover = null;
	}

	function isInsideActiveHoverArea( node ) {
		if ( !node || !activeWrapper || !activeHover ) {
			return false;
		}

		return activeWrapper.contains( node ) || activeHover.contains( node );
	}

	function scheduleCloseActiveHover() {
		clearCloseTimer();

		closeTimer = window.setTimeout( function () {
			closeActiveHover();
		}, closeDelay );
	}

	function positionHoverOnce( wrapper, hover ) {
		var padding = 18;
		var offsetX = 30;
		var offsetY = 20;
		var rect = wrapper.getBoundingClientRect();
		var previousDisplay = hover.style.display;
		var previousVisibility = hover.style.visibility;
		var width;
		var height;
		var x;
		var y;

		/*
		 * Ensure the hover is measurable before positioning.
		 * CSS still controls the final visible state through the active class.
		 */
		hover.style.visibility = 'hidden';
		hover.style.display = 'block';

		width = hover.offsetWidth || 460;
		height = hover.offsetHeight || 260;

		x = rect.left + offsetX;
		y = rect.bottom + offsetY;

		if ( x + width + padding > window.innerWidth ) {
			x = rect.right - width - offsetX;
		}

		if ( y + height + padding > window.innerHeight ) {
			y = rect.top - height - offsetY;
		}

		if ( x < padding ) {
			x = padding;
		}

		if ( y < padding ) {
			y = padding;
		}

		hover.style.left = x + 'px';
		hover.style.top = y + 'px';

		hover.style.display = previousDisplay;
		hover.style.visibility = previousVisibility;
	}

	function suppressNativeTitle( wrapper ) {
		var link = wrapper.querySelector( 'a' );

		if ( link && link.getAttribute( 'title' ) ) {
			link.dataset.eqlOriginalTitle = link.getAttribute( 'title' );
			link.removeAttribute( 'title' );
		}
	}

	function openHover( wrapper, hover ) {
		clearCloseTimer();

		if ( activeWrapper && activeWrapper !== wrapper ) {
			closeActiveHover();
		}

		activeWrapper = wrapper;
		activeHover = hover;

		positionHoverOnce( wrapper, hover );

		wrapper.dataset.eqlSpellpageItemHoverAnchored = '1';
		wrapper.classList.add( 'eql-spellpage-item-hover-active' );
	}

	function onWrapperEnter( wrapper, hover ) {
		openHover( wrapper, hover );
	}

	function onWrapperLeave( event ) {
		if ( isInsideActiveHoverArea( event.relatedTarget ) ) {
			clearCloseTimer();
			return;
		}

		scheduleCloseActiveHover();
	}

	function onHoverEnter() {
		clearCloseTimer();
	}

	function onHoverLeave( event ) {
		if ( isInsideActiveHoverArea( event.relatedTarget ) ) {
			clearCloseTimer();
			return;
		}

		scheduleCloseActiveHover();
	}

	function onDocumentMouseOver( event ) {
		if ( !activeWrapper ) {
			return;
		}

		if ( isInsideActiveHoverArea( event.target ) ) {
			clearCloseTimer();
			return;
		}

		scheduleCloseActiveHover();
	}

	function initSpellpageItemHovers( root ) {
		root = root || document;

		root.querySelectorAll( '.eql-spellpage-items .hbdiv' ).forEach( function ( wrapper ) {
			var hover = getDirectHoverSpan( wrapper );

			if ( !hover || wrapper.dataset.eqlSpellpageItemHoverReady === '1' ) {
				return;
			}

			wrapper.dataset.eqlSpellpageItemHoverReady = '1';
			suppressNativeTitle( wrapper );

			wrapper.addEventListener( 'mouseenter', function () {
				onWrapperEnter( wrapper, hover );
			} );

			wrapper.addEventListener( 'mouseleave', onWrapperLeave );

			hover.addEventListener( 'mouseenter', onHoverEnter );
			hover.addEventListener( 'mouseleave', onHoverLeave );
		} );
	}

	function init() {
		initSpellpageItemHovers( document );

		document.addEventListener( 'mouseover', onDocumentMouseOver, true );

		document.addEventListener( 'click', function ( event ) {
			if ( !isInsideActiveHoverArea( event.target ) ) {
				closeActiveHover();
			}
		}, true );
		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				closeActiveHover();
			}
		} );

		window.addEventListener( 'scroll', closeActiveHover, true );
		window.addEventListener( 'resize', closeActiveHover );

		if ( window.mw && mw.hook ) {
			mw.hook( 'wikipage.content' ).add( function ( $content ) {
				initSpellpageItemHovers( $content && $content[ 0 ] ? $content[ 0 ] : document );
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}());
