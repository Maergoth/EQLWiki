(function () {
	'use strict';

	var PAGE_ERA_TEMPLATE_TITLE = 'Template:PageEra';
	var CACHE_PREFIX = 'eql-era-filter-v2:';
	var OUT_ERA_KEYS_CACHE = CACHE_PREFIX + 'out-era-keys';
	var MODE_STORAGE_KEY = 'eql-era-filter-mode-v1';
	var LEGACY_TOGGLE_STORAGE_KEY = 'eql-era-filter-enabled-v1';
	var MAX_TITLES_PER_REQUEST = 50;

	var MODE_OFF = 'off';
	var MODE_ON = 'on';
	var MODE_OUTLINE = 'outline';

	/*
	 * Fallbacks based on the current Template:PageEra switch.
	 * The script will try to parse Template:PageEra first, but these
	 * keep it working if that API request fails.
	 */
	var FALLBACK_OUT_ERA_KEYS = [
		'kunark',
		'velious',
		'luclin',
		'chardok',
		'chardokrevamp',
		'holevp',
		'temple',
		'warrens',
		'warrensfearhatererevamp',
		'epics',
		'epicquests',
		'unknown'
	];

	/*
	 * Mixed/hub pages should not be treated as fully out of era just
	 * because part of the page contains an out-of-era section.
	 */
	var ERA_FILTER_EXEMPT_TITLES = [
		'Tradeskills',
		'Zones',
		'Character_Classes',
		'Equipment',
		'Spells',
		'Class_Guides',
		'Newbie_Guide',
		'Travel_Guide',
		'Game_Mechanics',
		'Tools'
	];

	var OUT_ERA_CATEGORY_ALIASES = {
		kunark: [
			'kunark era',
			'kunark'
		],
		velious: [
			'velious era',
			'velious'
		],
		luclin: [
			'luclin era',
			'luclin'
		],
		chardok: [
			'chardok era',
			'chardok'
		],
		chardokrevamp: [
			'chardok revamp era',
			'chardok revamp',
			'chardokrevamp'
		],
		holevp: [
			'hole vp era',
			'hole/vp era',
			'holevp'
		],
		temple: [
			'temple era',
			'temple'
		],
		warrens: [
			'warrens era',
			'warrens'
		],
		warrensfearhatererevamp: [
			'warrens fear hate revamp era',
			'warrens/fear/hate revamp era',
			'warrensfearhatererevamp'
		],
		epics: [
			'epics era',
			'epics',
			'epic era'
		],
		epicquests: [
			'epic quests era',
			'epic quests',
			'epicquests'
		],
		unknown: [
			'unknown era',
			'unknown'
		]
	};

	function isEQLImmersive() {
		return !!(
			document.body &&
			document.body.classList.contains( 'skin-eqlimmersive' )
		);
	}

	function normalizeText( value ) {
		return String( value || '' )
			.trim()
			.replace( /^Category:/i, '' )
			.replace( /_/g, ' ' )
			.replace( /\s+/g, ' ' )
			.toLowerCase();
	}

	function normalizeTitle( title ) {
		return String( title || '' )
			.trim()
			.replace( / /g, '_' )
			.replace( /^:+/, '' );
	}

	function isExcludedTitle( title ) {
		title = normalizeTitle( title );

		return !title ||
			ERA_FILTER_EXEMPT_TITLES.indexOf( title ) !== -1 ||
			title.indexOf( '#' ) === 0 ||
			/^Special:/i.test( title ) ||
			/^File:/i.test( title ) ||
			/^Image:/i.test( title ) ||
			/^Media:/i.test( title ) ||
			/^Category:/i.test( title ) ||
			/^Help:/i.test( title ) ||
			/^MediaWiki:/i.test( title ) ||
			/^Template:/i.test( title );
	}

	function titleFromHref( href ) {
		var url;
		var title;
		var path;

		if ( !href ) {
			return null;
		}

		try {
			url = new URL( href, window.location.href );
		} catch ( e ) {
			return null;
		}

		if ( url.origin !== window.location.origin ) {
			return null;
		}

		if (
			url.searchParams.has( 'action' ) ||
			url.searchParams.has( 'veaction' ) ||
			url.searchParams.has( 'oldid' ) ||
			url.searchParams.has( 'diff' )
		) {
			return null;
		}

		if ( url.searchParams.has( 'title' ) ) {
			title = url.searchParams.get( 'title' );
		} else {
			path = decodeURIComponent( url.pathname || '' );
			title = path.replace( /^\/+/, '' );

			if ( title === '' || title === 'index.php' ) {
				return null;
			}
		}

		title = normalizeTitle( title );

		if ( isExcludedTitle( title ) ) {
			return null;
		}

		return title;
	}

	function chunkArray( values, size ) {
		var chunks = [];
		var i;

		for ( i = 0; i < values.length; i += size ) {
			chunks.push( values.slice( i, i + size ) );
		}

		return chunks;
	}

	function getCachedStatus( title ) {
		var value;

		try {
			value = window.sessionStorage.getItem( CACHE_PREFIX + 'page:' + title );
		} catch ( e ) {
			return null;
		}

		if ( value === '1' ) {
			return true;
		}

		if ( value === '0' ) {
			return false;
		}

		return null;
	}

	function setCachedStatus( title, isOutOfEra ) {
		try {
			window.sessionStorage.setItem(
				CACHE_PREFIX + 'page:' + title,
				isOutOfEra ? '1' : '0'
			);
		} catch ( e ) {
			/* Ignore storage failures. */
		}
	}

	function normalizeMode( mode ) {
		mode = String( mode || '' ).toLowerCase();

		if ( mode === MODE_OFF || mode === MODE_ON || mode === MODE_OUTLINE ) {
			return mode;
		}

		return null;
	}

	function getFilterMode() {
		var stored;
		var legacy;
		var normalized;

		try {
			stored = window.localStorage.getItem( MODE_STORAGE_KEY );
			normalized = normalizeMode( stored );

			if ( normalized ) {
				return normalized;
			}

			legacy = window.localStorage.getItem( LEGACY_TOGGLE_STORAGE_KEY );

			if ( legacy === '0' ) {
				return MODE_OFF;
			}
		} catch ( e ) {
			return MODE_ON;
		}

		/* Default on, matching the previous two-state behavior. */
		return MODE_ON;
	}

	function modeToNextMode( mode ) {
		mode = normalizeMode( mode ) || MODE_ON;

		if ( mode === MODE_OFF ) {
			return MODE_ON;
		}

		if ( mode === MODE_ON ) {
			return MODE_OUTLINE;
		}

		return MODE_OFF;
	}

	function modeToLabel( mode ) {
		if ( mode === MODE_OFF ) {
			return 'Off';
		}

		if ( mode === MODE_OUTLINE ) {
			return 'Outline';
		}

		return 'On';
	}

	function applyModeClasses( mode ) {
		if ( !document.body ) {
			return;
		}

		mode = normalizeMode( mode ) || MODE_ON;

		document.body.classList.toggle( 'eql-era-filter-off', mode === MODE_OFF );
		document.body.classList.toggle( 'eql-era-filter-on', mode === MODE_ON );
		document.body.classList.toggle( 'eql-era-filter-outline', mode === MODE_OUTLINE );

		/* Legacy body classes retained for older rules, but only full overlay mode is enabled. */
		document.body.classList.toggle( 'eql-era-filter-enabled', mode === MODE_ON );
		document.body.classList.toggle( 'eql-era-filter-disabled', mode === MODE_OFF );
	}

	function updateEraToggleButton( mode ) {
		var button = document.getElementById( 'eql-era-filter-toggle' );
		var label;

		if ( !button ) {
			return;
		}

		mode = normalizeMode( mode ) || MODE_ON;
		label = modeToLabel( mode );

		button.classList.toggle( 'eql-era-filter-toggle-off', mode === MODE_OFF );
		button.classList.toggle( 'eql-era-filter-toggle-on', mode === MODE_ON );
		button.classList.toggle( 'eql-era-filter-toggle-outline', mode === MODE_OUTLINE );

		/* Legacy active class retained only for the full overlay mode. */
		button.classList.toggle( 'eql-era-filter-toggle-active', mode === MODE_ON );

		button.setAttribute( 'data-eql-era-filter-mode', mode );
		button.setAttribute( 'aria-pressed', mode === MODE_OFF ? 'false' : ( mode === MODE_ON ? 'true' : 'mixed' ) );
		button.setAttribute( 'title', 'Out of Era Filter: ' + label );
		button.setAttribute( 'aria-label', 'Out of Era Filter: ' + label );
	}

	function setFilterMode( mode ) {
		mode = normalizeMode( mode ) || MODE_ON;

		try {
			window.localStorage.setItem( MODE_STORAGE_KEY, mode );

			/* Keep old skin bootstrap/localStorage checks approximately compatible. */
			window.localStorage.setItem(
				LEGACY_TOGGLE_STORAGE_KEY,
				mode === MODE_OFF ? '0' : '1'
			);
		} catch ( e ) {
			/* Ignore storage failures. */
		}

		applyModeClasses( mode );
		updateEraToggleButton( mode );
	}

	function cycleFilterMode() {
		setFilterMode( modeToNextMode( getFilterMode() ) );
	}

	function bindEraToggleButton( button ) {
		if ( !button || button.dataset.eqlEraBound === '1' ) {
			return;
		}

		button.dataset.eqlEraBound = '1';

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();

			cycleFilterMode();
		} );
	}

	function ensureEraToggleButton() {
		var existing = document.getElementById( 'eql-era-filter-toggle' );
		var headerEnd;
		var userLinks;
		var button;
		var icon;

		if ( existing ) {
			bindEraToggleButton( existing );
			updateEraToggleButton( getFilterMode() );
			return existing;
		}

		headerEnd = document.querySelector( '.vector-header-end' );

		if ( !headerEnd ) {
			return null;
		}

		userLinks = headerEnd.querySelector( '.vector-user-links' );

		var initialMode = normalizeMode( getFilterMode() ) || MODE_ON;

		button = document.createElement( 'button' );
		button.id = 'eql-era-filter-toggle';
		button.type = 'button';
		button.className = 'eql-era-filter-toggle eql-era-filter-toggle-' + initialMode + ( initialMode === MODE_ON ? ' eql-era-filter-toggle-active' : '' );
		button.setAttribute( 'data-eql-era-filter-mode', initialMode );
		button.setAttribute( 'title', 'Out of Era Filter: ' + modeToLabel( initialMode ) );
		button.setAttribute( 'aria-label', 'Out of Era Filter: ' + modeToLabel( initialMode ) );
		button.setAttribute( 'aria-pressed', initialMode === MODE_OFF ? 'false' : ( initialMode === MODE_ON ? 'true' : 'mixed' ) );

		icon = document.createElement( 'span' );
		icon.className = 'eql-era-filter-toggle-icon';
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.textContent = '◷';

		button.appendChild( icon );

		if ( userLinks ) {
			headerEnd.insertBefore( button, userLinks );
		} else {
			headerEnd.appendChild( button );
		}

		bindEraToggleButton( button );
		updateEraToggleButton( getFilterMode() );

		return button;
	}

	function parseOutEraKeysFromPageEraTemplate( content ) {
		var keys = [];
		var regex = /\|\s*([a-zA-Z0-9_ -]+)\s*=\s*out\b/g;
		var match;

		while ( ( match = regex.exec( content ) ) !== null ) {
			keys.push(
				String( match[ 1 ] )
					.trim()
					.replace( /[\s_-]+/g, '' )
					.toLowerCase()
			);
		}

		return keys.length ? keys : FALLBACK_OUT_ERA_KEYS.slice();
	}

	function fetchOutEraKeys() {
		var cached;

		try {
			cached = window.sessionStorage.getItem( OUT_ERA_KEYS_CACHE );

			if ( cached ) {
				return Promise.resolve( JSON.parse( cached ) );
			}
		} catch ( e ) {
			/* Continue to API lookup. */
		}

		return new mw.Api().get( {
			action: 'query',
			titles: PAGE_ERA_TEMPLATE_TITLE,
			prop: 'revisions',
			rvprop: 'content',
			rvslots: '*',
			formatversion: 2
		} ).then( function ( data ) {
			var page = data.query.pages && data.query.pages[ 0 ];
			var content = '';
			var keys;

			if (
				page &&
				page.revisions &&
				page.revisions[ 0 ] &&
				page.revisions[ 0 ].slots &&
				page.revisions[ 0 ].slots.main
			) {
				content = page.revisions[ 0 ].slots.main.content || '';
			}

			keys = parseOutEraKeysFromPageEraTemplate( content );

			try {
				window.sessionStorage.setItem( OUT_ERA_KEYS_CACHE, JSON.stringify( keys ) );
			} catch ( e ) {}

			return keys;
		} ).catch( function () {
			return FALLBACK_OUT_ERA_KEYS.slice();
		} );
	}

	function buildOutCategorySet( outEraKeys ) {
		var set = new Set();

		outEraKeys.forEach( function ( key ) {
			var aliases = OUT_ERA_CATEGORY_ALIASES[ key ] || [];

			aliases.forEach( function ( alias ) {
				set.add( normalizeText( alias ) );
			} );

			set.add( normalizeText( key + ' era' ) );
			set.add( normalizeText( key ) );
		} );

		return set;
	}

	function isCategoryOutOfEra( categoryTitle, outCategorySet ) {
		var normalized = normalizeText( categoryTitle );

		return outCategorySet.has( normalized );
	}

	function apiGetOutOfEraStatuses( titles, outCategorySet ) {
		var api = new mw.Api();

		return api.get( {
			action: 'query',
			formatversion: 2,
			prop: 'categories',
			titles: titles.join( '|' ),
			cllimit: 'max'
		} ).then( function ( data ) {
			var result = {};

			( data.query.pages || [] ).forEach( function ( page ) {
				var title = normalizeTitle( page.title );
				var categories = page.categories || [];
				var isOutOfEra = categories.some( function ( category ) {
					return isCategoryOutOfEra( category.title, outCategorySet );
				} );

				result[ title ] = isOutOfEra;
				setCachedStatus( title, isOutOfEra );
			} );

			return result;
		} );
	}

	function getOverlayTarget( link ) {
		return link.closest(
			[
				'.eql-card-link',
				'.eql-world-map-stage > div[title]',
				'#eql-main-menu-host .mw-list-item',
				'.eql-main-menu-toc-item'
			].join( ', ' )
		) || link;
	}

	function clearOutOfEraMark( link ) {
		var target = getOverlayTarget( link );

		link.classList.remove( 'eql-era-out-link' );
		link.removeAttribute( 'data-eql-out-of-era-target' );

		if ( target ) {
			target.classList.remove( 'eql-era-out-overlay-target' );
			target.removeAttribute( 'data-eql-out-of-era-target' );
		}
	}

	function markOutOfEraLink( link, title ) {
		var target = getOverlayTarget( link );

		link.classList.add( 'eql-era-out-link' );
		link.setAttribute( 'data-eql-out-of-era-target', title );

		target.classList.add( 'eql-era-out-overlay-target' );
		target.setAttribute( 'data-eql-out-of-era-target', title );
	}

	function getRootElement( root ) {
		if ( root && root.jquery && root[ 0 ] ) {
			return root[ 0 ];
		}

		if ( root && root.nodeType === 1 ) {
			return root;
		}

		return document;
	}

	function collectLinks( root ) {
		var linkMap = new Map();
		var rootElement = getRootElement( root );
		var links = [];

		if ( rootElement.nodeType === 1 && rootElement.matches && rootElement.matches( 'a[href]' ) ) {
			links.push( rootElement );
		}

		links = links.concat( Array.prototype.slice.call( rootElement.querySelectorAll( 'a[href]' ) ) );

		links.forEach( function ( link ) {
			var title;

			if (
				link.closest( '#ca-edit, #ca-ve-edit, #ca-history, #ca-eql-watch' ) ||
				link.closest( '.mw-editsection' ) ||
				link.closest( '#footer' ) ||
				link.closest( '.mw-footer-container' )
			) {
				return;
			}

			title = titleFromHref( link.href );

			if ( !title ) {
				return;
			}

			if ( !linkMap.has( title ) ) {
				linkMap.set( title, [] );
			}

			linkMap.get( title ).push( link );
		} );

		return linkMap;
	}

	function applyStatuses( linkMap, statuses ) {
		Object.keys( statuses ).forEach( function ( title ) {
			( linkMap.get( title ) || [] ).forEach( function ( link ) {
				if ( statuses[ title ] ) {
					markOutOfEraLink( link, title );
				} else {
					clearOutOfEraMark( link );
				}
			} );
		} );
	}

	function filterEraLinks( root ) {
		var linkMap;
		var immediateStatuses = {};
		var titlesToFetch = [];

		if ( !isEQLImmersive() ) {
			return;
		}

		ensureEraToggleButton();
		setFilterMode( getFilterMode() );

		linkMap = collectLinks( root );

		fetchOutEraKeys().then( function ( outEraKeys ) {
			var outCategorySet = buildOutCategorySet( outEraKeys );

			linkMap.forEach( function ( links, title ) {
				var cached = getCachedStatus( title );

				if ( cached === true ) {
					immediateStatuses[ title ] = true;
				} else if ( cached === false ) {
					immediateStatuses[ title ] = false;
				} else {
					titlesToFetch.push( title );
				}
			} );

			applyStatuses( linkMap, immediateStatuses );

			chunkArray( titlesToFetch, MAX_TITLES_PER_REQUEST ).forEach( function ( chunk ) {
				apiGetOutOfEraStatuses( chunk, outCategorySet ).then( function ( statuses ) {
					applyStatuses( linkMap, statuses );
				} ).catch( function () {
					/* Fail open. */
				} );
			} );
		} );
	}

	if ( window.mw && mw.loader ) {
		mw.loader.using( [ 'mediawiki.api' ] ).then( function () {
			window.eqlEraFilterRefresh = function ( root ) {
				filterEraLinks( root );
			};

			window.eqlEraFilter = {
				refresh: function ( root ) {
					filterEraLinks( root );
				},
				getMode: getFilterMode,
				setMode: setFilterMode
			};

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', function () {
					filterEraLinks();
				} );
			} else {
				filterEraLinks();
			}

			if ( mw.hook ) {
				mw.hook( 'wikipage.content' ).add( function ( $content ) {
					filterEraLinks( $content );
				} );
			}
		} );
	}
}());
