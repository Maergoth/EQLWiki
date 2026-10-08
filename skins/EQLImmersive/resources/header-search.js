/* EQLImmersive — Header Search Toggle
 *
 * Uses the native Vector/Codex header search already present in the DOM:
 * #p-search, .search-toggle, form#searchform, input#searchInput.
 *
 * Wide screens are inline-only. Compact screens use the native search icon as
 * a toggle and render the form as a fixed overlay so it can cover header tools
 * without changing header justification.
 */
(function () {
	'use strict';

	var OPEN_CLASS = 'eql-search-open';
	var EXPANDED_CLASS = 'eql-search-expanded';
	// Keep in sync with the compact-mode CSS breakpoint.
	var COMPACT_QUERY = '(max-width: 1500px)';
	var initialized = false;
	var resizeTimer = null;

	function getSearch() {
		return document.getElementById( 'p-search' );
	}

	function getToggle( search ) {
		return search ? search.querySelector( '.search-toggle' ) : null;
	}

	function getForm( search ) {
		return search ? search.querySelector( 'form#searchform' ) : null;
	}

	function getInput( search ) {
		return search ? search.querySelector( '#searchInput, input[name="search"]' ) : null;
	}

	function getSuggestions() {
		return document.getElementById( 'eql-header-search-suggestions' ) ||
			document.getElementById( 'eql-floating-search-suggestions' );
	}

	function isCompact() {
		return !!( window.matchMedia && window.matchMedia( COMPACT_QUERY ).matches );
	}

	function isOpen() {
		return !!( document.body && document.body.classList.contains( OPEN_CLASS ) );
	}

	function placeCursorAtEnd( input ) {
		var length;

		if ( !input ) {
			return;
		}

		length = input.value ? input.value.length : 0;

		try {
			input.focus( { preventScroll: true } );
		} catch ( e1 ) {
			input.focus();
		}

		try {
			input.setSelectionRange( length, length );
		} catch ( e2 ) {}
	}

	function focusInputRepeatedly( input ) {
		[ 0, 16, 45, 100 ].forEach( function ( delay ) {
			window.setTimeout( function () {
				if ( isOpen() || !isCompact() ) {
					placeCursorAtEnd( input );
				}
			}, delay );
		} );
	}

	function clamp( value, min, max ) {
		return Math.max( min, Math.min( max, value ) );
	}

	function positionOverlay() {
		var search = getSearch();
		var toggle = getToggle( search );
		var form = getForm( search );
		var suggestions = getSuggestions();
		var toggleRect;
		var formRect;
		var gutter = 8;
		var width;
		var left;
		var top;

		if ( !search || !form ) {
			return;
		}

		/* Inline desktop search does not need fixed positioning. */
		if ( !isCompact() ) {
			search.style.removeProperty( '--eql-search-overlay-left' );
			search.style.removeProperty( '--eql-search-overlay-top' );
			search.style.removeProperty( '--eql-search-overlay-width' );
			return;
		}

		width = Math.min( 576, window.innerWidth - ( gutter * 2 ) );
		toggleRect = toggle && toggle.getBoundingClientRect ? toggle.getBoundingClientRect() : null;

		if ( toggleRect && toggleRect.width > 0 && toggleRect.height > 0 ) {
			left = clamp( Math.round( toggleRect.left ), gutter, window.innerWidth - width - gutter );
			top = Math.round( toggleRect.top );
		} else {
			left = gutter;
			top = gutter;
		}

		if ( window.innerWidth <= 640 ) {
			left = gutter;
			width = window.innerWidth - ( gutter * 2 );
		}

		search.style.setProperty( '--eql-search-overlay-left', left + 'px' );
		search.style.setProperty( '--eql-search-overlay-top', top + 'px' );
		search.style.setProperty( '--eql-search-overlay-width', width + 'px' );

		if ( suggestions ) {
			formRect = form.getBoundingClientRect();

			if ( formRect.width > 0 && formRect.height > 0 ) {
				suggestions.style.setProperty( '--eql-search-suggest-left', Math.round( formRect.left ) + 'px' );
				suggestions.style.setProperty( '--eql-search-suggest-top', Math.round( formRect.bottom ) + 'px' );
				suggestions.style.setProperty( '--eql-search-suggest-width', Math.round( formRect.width ) + 'px' );
			}
		}
	}

	function openSearch() {
		var search = getSearch();
		var toggle = getToggle( search );
		var input = getInput( search );

		if ( !document.body || !search ) {
			return;
		}

		document.body.classList.add( OPEN_CLASS );
		search.classList.add( EXPANDED_CLASS );

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'true' );
		}

		positionOverlay();
		focusInputRepeatedly( input );

		document.dispatchEvent( new CustomEvent( 'eqlSearchOpen' ) );
	}

	function closeSearch() {
		var search = getSearch();
		var toggle = getToggle( search );

		if ( !document.body || !search ) {
			return;
		}

		document.body.classList.remove( OPEN_CLASS );
		search.classList.remove( EXPANDED_CLASS );

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'false' );
		}

		document.dispatchEvent( new CustomEvent( 'eqlSearchClose' ) );
	}

	function targetIsInsideSearchUi( target ) {
		var search = getSearch();
		var suggestions = getSuggestions();

		return !!(
			target &&
			(
				( search && search.contains( target ) ) ||
				( suggestions && suggestions.contains( target ) ) ||
				( target.closest && target.closest( '.cdx-menu, .cdx-typeahead-search__menu, .suggestions' ) )
			)
		);
	}

	function handleResizeOrScroll() {
		window.clearTimeout( resizeTimer );
		positionOverlay();

		resizeTimer = window.setTimeout( function () {
			positionOverlay();

			if ( !isCompact() && isOpen() ) {
				closeSearch();
			}
		}, 80 );
	}

	function init() {
		var search;
		var toggle;
		var form;
		var input;

		if ( initialized || !document.body || !document.body.classList.contains( 'skin-eqlimmersive' ) ) {
			return;
		}

		search = getSearch();
		form = getForm( search );
		input = getInput( search );
		toggle = getToggle( search );

		if ( !search || !form || !input ) {
			return;
		}

		initialized = true;
		search.classList.add( 'eql-search-native-ready' );

		if ( toggle ) {
			toggle.setAttribute( 'role', 'button' );
			toggle.setAttribute( 'aria-controls', 'searchform' );
			toggle.setAttribute( 'aria-expanded', 'false' );

			toggle.addEventListener( 'click', function ( event ) {
				if ( !isCompact() ) {
					return;
				}

				event.preventDefault();
				event.stopPropagation();

				if ( isOpen() ) {
					closeSearch();
				} else {
					openSearch();
				}
			} );
		}

		input.addEventListener( 'focus', function () {
			if ( isCompact() && !isOpen() ) {
				openSearch();
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			if ( !input.value.trim() ) {
				event.preventDefault();
				openSearch();
			}
		} );

		document.addEventListener( 'pointerdown', function ( event ) {
			if ( !isOpen() || targetIsInsideSearchUi( event.target ) ) {
				return;
			}

			closeSearch();
		}, true );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Escape' || !isOpen() ) {
				return;
			}

			closeSearch();

			if ( toggle ) {
				toggle.focus();
			}
		} );

		window.addEventListener( 'resize', handleResizeOrScroll );
		window.addEventListener( 'scroll', handleResizeOrScroll, true );
		positionOverlay();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}());

/* =========================================================
 * EQLImmersive â€” search suggestion request throttle
 *
 * Keep MediaWiki's native mediawiki.searchSuggest UI/results,
 * but reduce PHP/API churn:
 *
 * - fewer than 2 characters: no API request
 * - 2+ characters: wait for 3000 ms of typing inactivity
 * - continuing to type resets MediaWiki's pending timer
 * - existing in-flight requests continue to use the core
 *   cancellation behavior
 * - Enter / form submission remains immediate
 *
 * EQL_SEARCH_SUGGEST_THROTTLE_V1
 * ========================================================= */
(function () {
	'use strict';

	var MIN_CHARS = 3;
	var IDLE_DELAY_MS = 1000;
	var installed = false;
	var configuring = false;

	function findHeaderInput() {
		return document.querySelector(
			'#p-search #searchInput, #searchInput'
		);
	}

	function configureNativeSuggestions( input ) {
		var attempts = 0;
		var maxAttempts = 50;

		if (
			installed ||
			configuring ||
			!input ||
			!window.mw ||
			!mw.loader ||
			!window.jQuery
		) {
			return;
		}

		configuring = true;

		/*
		 * Use MediaWiki's real searchSuggest module.
		 * We are not replacing its rendering, keyboard support,
		 * caching, navigation, or request cancellation.
		 */
		mw.loader.using( 'mediawiki.searchSuggest' ).then( function () {
			function tryConfigure() {
				var $input = window.jQuery( input );
				var context = $input.data( 'suggestions-context' );
				var originalFetch;

				/*
				 * mediawiki.searchSuggest attaches jquery.suggestions
				 * from a DOM-ready callback. The ResourceLoader promise
				 * can resolve just before that callback has completed,
				 * so briefly poll for its context.
				 */
				if (
					!context ||
					!context.config ||
					typeof context.config.fetch !== 'function'
				) {
					attempts++;

					if ( attempts < maxAttempts ) {
						window.setTimeout( tryConfigure, 40 );
						return;
					}

					configuring = false;
					return;
				}

				if ( context.config.eqlSearchThrottleV1 ) {
					installed = true;
					configuring = false;
					return;
				}

				/*
				 * Directly modify the live suggestions context.
				 *
				 * jquery.suggestions' public "delay" setter clamps
				 * values to 1200 ms, but its actual timer reads this
				 * context value when scheduling a fetch.
				 */
				context.config.delay = IDLE_DELAY_MS;

				/*
				 * Preserve MediaWiki's native fetch implementation,
				 * but short-circuit one-character searches locally.
				 */
				originalFetch = context.config.fetch;

				context.config.fetch = function (
					query,
					response,
					maxRows
				) {
					var normalized = String(
						query || ''
					).trim();

					if ( normalized.length < MIN_CHARS ) {
						response(
							[],
							{
								query: normalized,
								type: 'eql-min-chars',
								searchId: null
							}
						);
						return;
					}

					return originalFetch.call(
						this,
						query,
						response,
						maxRows
					);
				};

				context.config.eqlSearchThrottleV1 = true;

				installed = true;
				configuring = false;
			}

			tryConfigure();
		} ).catch( function () {
			configuring = false;
		} );
	}

	function initThrottle() {
		var input = findHeaderInput();

		if ( !input ) {
			return;
		}

		/*
		 * Do not eagerly load searchSuggest on every page.
		 * Preserve MediaWiki's lazy behavior: configure it only
		 * once the user actually focuses the search field.
		 */
		input.addEventListener(
			'focus',
			function () {
				configureNativeSuggestions( input );
			}
		);

		/*
		 * Handle the unusual case where the input gained focus
		 * before this script finished executing.
		 */
		if ( document.activeElement === input ) {
			configureNativeSuggestions( input );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener(
			'DOMContentLoaded',
			initThrottle
		);
	} else {
		initThrottle();
	}
}());
