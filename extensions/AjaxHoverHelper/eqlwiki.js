(function ( $ ) {
	'use strict';

	var hoverCache = {};
	var hideDelay = 0;
	var trigDelay = 250;
	var hideTimer = null;
	var trigTimer = null;
	var ajax = null;
	var container = null;

	function getHoverContainer() {
		if ( container ) {
			return container;
		}

		container = $(
			'<div id="itemHoverContainer">' +
				'<div id="itemHoverContent"></div>' +
			'</div>'
		);

		$( 'body' ).append( container );

		container.on( 'mouseover', function () {
			if ( hideTimer ) {
				clearTimeout( hideTimer );
			}
		} );

		container.on( 'mouseout', function () {
			hideHover();
		} );

		return container;
	}

	function normalizeTitle( title ) {
		title = $.trim( title || '' );

		if ( title === '' || title === 'undefined' ) {
			return '';
		}

		return title;
	}

	function getLinkTitle( link ) {
		var $link = $( link );
		var title = normalizeTitle( $link.attr( 'title' ) );

		if ( title !== '' ) {
			return title;
		}

		title = normalizeTitle( $link.text() );

		return title;
	}

	function getHoverType( link ) {
		var $link = $( link );

		if ( $link.closest( 'span.sh, .sh, .spellhover, .spell-hover' ).length ) {
			return 'spell';
		}

		if ( $link.closest( 'span.ih, .ih' ).length ) {
			return 'item';
		}

		return '';
	}

	function buildUrl( title, type ) {
		var base;

		if ( window.mw && mw.util ) {
			base = mw.util.getUrl( 'Special:AjaxHoverHelper/' + title );
		} else {
			base = '/index.php/Special:AjaxHoverHelper/' + encodeURIComponent( title );
		}

		return base + '?type=' + encodeURIComponent( type );
	}

	function positionHover( e ) {
		var $container = getHoverContainer();
		var mousex = e.pageX + 20;
		var mousey = e.pageY + 20;
		var tipWidth = $container.outerWidth();
		var tipHeight = $container.outerHeight();
		var viewportRight = $( window ).scrollLeft() + $( window ).width();
		var viewportBottom = $( window ).scrollTop() + $( window ).height();

		if ( mousex + tipWidth + 20 > viewportRight ) {
			if ( tipWidth > e.pageX - $( window ).scrollLeft() - 20 ) {
				mousex = $( window ).scrollLeft() + 8;
			} else {
				mousex = e.pageX - tipWidth - 20;
			}
		}

		if ( mousey + tipHeight + 20 > viewportBottom ) {
			mousey = e.pageY - tipHeight - 20;
		}

		$container.css( {
			top: mousey,
			left: mousex
		} );
	}

	function hideHover() {
		if ( hideTimer ) {
			clearTimeout( hideTimer );
		}

		hideTimer = setTimeout( function () {
			getHoverContainer().hide();
		}, hideDelay );
	}

	function showLoading( type ) {
		if ( type === 'spell' ) {
			$( '#itemHoverContent' ).html(
				'<div class="spelltopbg"><div class="spelltitle">Loading...</div></div>' +
				'<div class="spellbg"><div class="spelldata">Loading spell details...</div></div>' +
				'<div class="spellbotbg"></div>'
			);
			return;
		}

		$( '#itemHoverContent' ).html( '&nbsp;' );
	}

	function loadHover( title, type ) {
		var cacheKey = type + ':' + title;
		var url = buildUrl( title, type );

		if ( hoverCache[cacheKey] ) {
			$( '#itemHoverContent' ).html( hoverCache[cacheKey] );
			return;
		}

		if ( ajax ) {
			ajax.abort();
			ajax = null;
		}

		showLoading( type );

		ajax = $.ajax( {
			url: url,
			cache: true,
			success: function ( html ) {
				hoverCache[cacheKey] = html;
				$( '#itemHoverContent' ).html( html );
			},
			error: function () {
				$( '#itemHoverContent' ).html(
					'<div class="spelltopbg"><div class="spelltitle">Hover Error</div></div>' +
					'<div class="spellbg"><div class="spelldata">Could not load hover details.</div></div>' +
					'<div class="spellbotbg"></div>'
				);
			}
		} );
	}

	function bindAjaxHovers() {
		$( document ).on( 'mouseover', 'span.ih a, span.sh a, .ih a, .sh a, .spellhover a, .spell-hover a', function ( e ) {
			var link = this;
			var title = getLinkTitle( link );
			var type = getHoverType( link );

			if ( title === '' || type === '' ) {
				return;
			}

			if ( hideTimer ) {
				clearTimeout( hideTimer );
			}

			if ( trigTimer ) {
				clearTimeout( trigTimer );
			}

			positionHover( e );

			trigTimer = setTimeout( function () {
				loadHover( title, type );
				getHoverContainer().show();
			}, trigDelay );
		} );

		$( document ).on( 'mousemove', 'span.ih a, span.sh a, .ih a, .sh a, .spellhover a, .spell-hover a', function ( e ) {
			positionHover( e );
		} );

		$( document ).on( 'mouseout', 'span.ih a, span.sh a, .ih a, .sh a, .spellhover a, .spell-hover a', function () {
			if ( trigTimer ) {
				clearTimeout( trigTimer );
			}

			hideHover();
		} );
	}

	function bindMageloHovers() {
		$( document ).on( 'mousemove', '.magelohb', function ( e ) {
			var childContainer = $( this ).children( 'span.hb' );
			var tipWidth = childContainer.width();
			var tipHeight = childContainer.height();
			var mousex = e.pageX + 20;
			var mousey = e.pageY + 20;
			var viewportRight = $( window ).scrollLeft() + $( window ).width();
			var viewportBottom = $( window ).scrollTop() + $( window ).height();

			if ( mousex + tipWidth + 20 > viewportRight ) {
				if ( tipWidth > e.pageX - $( window ).scrollLeft() - 20 ) {
					mousex = $( window ).scrollLeft() + 8;
				} else {
					mousex = e.pageX - tipWidth - 20;
				}
			}

			if ( mousey + tipHeight + 20 > viewportBottom ) {
				mousey = e.pageY - tipHeight - 20;
			}

			childContainer.css( {
				top: mousey,
				left: mousex,
				'z-index': '999'
			} );
		} );

		$( '.magelohb span.hb' ).css( {
			position: 'fixed'
		} );
	}

	$( function () {
		getHoverContainer();
		bindAjaxHovers();
		bindMageloHovers();
	} );

}( jQuery ) );