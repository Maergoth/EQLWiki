/* EQLImmersive: out-of-era link filter.
 *
 * Performance design:
 * - The filter still defaults to On.
 * - One batched eqlmetadata request replaces many prop=categories requests.
 * - Statuses are cached across tabs for a short, revision-scoped TTL.
 * - Concurrent/duplicate scans share the same in-flight title requests.
 * - Configuration comes from EQLClientData and changes immediately after the
 *   PageEra configuration page is edited.
 */
(function () {
	'use strict';

	var CACHE_PREFIX = 'eql-era-status-v4:';
	var MODE_STORAGE_KEY = 'eql-era-filter-mode-v1';
	var LEGACY_TOGGLE_STORAGE_KEY = 'eql-era-filter-enabled-v1';
	var API_ACTION = mw.config.get( 'wgEQLMetadataApiAction', 'eqlmetadata' );
	var API_MAX_TITLES = 450;
	var FALLBACK_API_MAX_TITLES = 50;
	var CACHE_TTL_MS = Math.max(
		5000,
		Number( mw.config.get( 'wgEQLEraStatusClientTtlSeconds', 30 ) ) * 1000
	);
	var currentEraRevision = Number(
		mw.config.get( 'wgEQLEraConfigRevision', 0 )
	) || 0;

	var MODE_OFF = 'off';
	var MODE_ON = 'on';
	var MODE_OUTLINE = 'outline';
	var MODE_HIDE = 'hide';

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

	var EXCLUDED_ROOT_PATHS = {
		'api.php': true,
		'load.php': true,
		'rest.php': true,
		'index.php': true,
		'eql_logout.php': true,
		'wiki_auth_bridge.php': true,
		'wiki_session_bridge.php': true,
		'favicon.ico': true,
		'bb': true,
		'images': true,
		'resources': true,
		'skins': true,
		'extensions': true,
		'cgi-bin': true,
		'.well-known': true
	};

	var EXCLUDED_FILE_EXTENSION = /\.(?:php|js|css|map|json|xml|png|jpe?g|gif|svg|webp|ico|zip|gz|tar|pdf|mp3|mp4|webm|woff2?|ttf|otf)$/i;
	var memoryCache = new Map();
	var pendingByTitle = new Map();
	var queuedTitles = new Set();
	var queueTimer = null;
	var refreshRoots = new Set();
	var refreshTimer = null;

	function isEQLImmersive() {
		return !!(
			document.body &&
			document.body.classList.contains( 'skin-eqlimmersive' )
		);
	}

	function normalizeTitle( title ) {
		return String( title || '' )
			.trim()
			.replace( / /g, '_' )
			.replace( /^:+/, '' );
	}

	function normalizeMode( mode ) {
		mode = String( mode || '' ).toLowerCase();

		if (
			mode === MODE_OFF ||
			mode === MODE_ON ||
			mode === MODE_OUTLINE ||
			mode === MODE_HIDE
		) {
			return mode;
		}

		return null;
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
		var firstSegment;

		if ( !href ) {
			return null;
		}

		try {
			url = new URL( href, window.location.href );
		} catch ( error ) {
			return null;
		}

		if ( url.origin !== window.location.origin ) {
			return null;
		}

		if (
			url.searchParams.has( 'action' ) ||
			url.searchParams.has( 'veaction' ) ||
			url.searchParams.has( 'oldid' ) ||
			url.searchParams.has( 'diff' ) ||
			url.searchParams.has( 'curid' )
		) {
			return null;
		}

		path = decodeURIComponent( url.pathname || '' )
			.replace( /^\/+/, '' );
		firstSegment = path.split( '/' )[ 0 ].toLowerCase();

		if ( EXCLUDED_ROOT_PATHS[ firstSegment ] ) {
			/* /index.php?title=Article is handled below. */
			if ( firstSegment !== 'index.php' || !url.searchParams.has( 'title' ) ) {
				return null;
			}
		}

		if ( url.searchParams.has( 'title' ) ) {
			title = url.searchParams.get( 'title' );
		} else {
			title = path;
		}

		if ( !title || EXCLUDED_FILE_EXTENSION.test( title ) ) {
			return null;
		}

		title = normalizeTitle( title );

		return isExcludedTitle( title ) ? null : title;
	}

	function getFilterMode() {
		var stored;
		var normalized;
		var legacy;

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
		} catch ( error ) {
			return MODE_ON;
		}

		/* Default On, preserving the existing wiki behavior. */
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

		if ( mode === MODE_OUTLINE ) {
			return MODE_HIDE;
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

		if ( mode === MODE_HIDE ) {
			return 'Hide';
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
		document.body.classList.toggle( 'eql-era-filter-hide', mode === MODE_HIDE );
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
		button.classList.toggle( 'eql-era-filter-toggle-hide', mode === MODE_HIDE );
		button.classList.toggle( 'eql-era-filter-toggle-active', mode === MODE_ON );
		button.setAttribute( 'data-eql-era-filter-mode', mode );
		button.setAttribute( 'aria-pressed', mode === MODE_OFF ? 'false' : 'true' );
		button.setAttribute( 'title', 'Out of Era Filter: ' + label );
		button.setAttribute( 'aria-label', 'Out of Era Filter: ' + label );
	}

	function setFilterMode( mode ) {
		mode = normalizeMode( mode ) || MODE_ON;

		try {
			window.localStorage.setItem( MODE_STORAGE_KEY, mode );
			window.localStorage.setItem(
				LEGACY_TOGGLE_STORAGE_KEY,
				mode === MODE_OFF ? '0' : '1'
			);
		} catch ( error ) {
			/* Ignore storage failures. */
		}

		applyModeClasses( mode );
		updateEraToggleButton( mode );
		updateHiddenRows( mode );

		if ( mode !== MODE_OFF ) {
			queueRefresh( document );
		}
	}

	function updateHiddenRows( mode ) {
		var rows = document.querySelectorAll(
			'table.eoTable tr, table.eoTable2 tr, table.eoTable3 tr, ' +
			'table.wikitable tr, table.sortable tr'
		);
		var i;

		function isTooltipOrPreviewContent( element ) {
			return !!(
				element &&
				element.closest &&
				element.closest(
					[
						'.hb',
						'.itembg',
						'.itemtopbg',
						'.itembotbg',
						'.spell-examine-window',
						'#itemHoverContainer',
						'#eqlItemEffectSpellHoverContainer'
					].join( ', ' )
				)
			);
		}

		function isOuterCollapsibleWrapperRow( row, table ) {
			return !!(
				table &&
				table.classList &&
				table.classList.contains( 'mw-collapsible' ) &&
				(
					table.classList.contains( 'eql-build-ability-panel' ) ||
					table.closest( '.eql-spell-lazy-section' )
				)
			);
		}

		function rowOwnsOutOfEraTarget( row ) {
			var table = row.closest( 'table' );
			var targets;
			var j;
			var target;

			if ( !table || isOuterCollapsibleWrapperRow( row, table ) ) {
				return false;
			}

			targets = row.querySelectorAll(
				'.eql-era-out-link, .eql-era-out-overlay-target'
			);

			for ( j = 0; j < targets.length; j++ ) {
				target = targets[ j ];

				if ( isTooltipOrPreviewContent( target ) ) {
					continue;
				}

				if (
					target.closest( 'tr' ) === row &&
					target.closest( 'table' ) === table
				) {
					return true;
				}
			}

			return false;
		}

		for ( i = 0; i < rows.length; i++ ) {
			if ( mode === MODE_HIDE && rowOwnsOutOfEraTarget( rows[ i ] ) ) {
				rows[ i ].classList.add( 'eql-era-hidden-row' );
			} else {
				rows[ i ].classList.remove( 'eql-era-hidden-row' );
			}
		}
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
		var initialMode;

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
		initialMode = normalizeMode( getFilterMode() ) || MODE_ON;
		button = document.createElement( 'button' );
		button.id = 'eql-era-filter-toggle';
		button.type = 'button';
		button.className = 'eql-era-filter-toggle eql-era-filter-toggle-' +
			initialMode +
			( initialMode === MODE_ON ? ' eql-era-filter-toggle-active' : '' );
		button.setAttribute( 'data-eql-era-filter-mode', initialMode );
		button.setAttribute( 'title', 'Out of Era Filter: ' + modeToLabel( initialMode ) );
		button.setAttribute( 'aria-label', 'Out of Era Filter: ' + modeToLabel( initialMode ) );
		button.setAttribute( 'aria-pressed', initialMode === MODE_OFF ? 'false' : 'true' );

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

	function getCacheKey( title, eraRevision ) {
		return CACHE_PREFIX + eraRevision + ':' + title;
	}

	function getCachedStatus( title ) {
		var now = Date.now();
		var memory = memoryCache.get( title );
		var raw;
		var parsed;

		if (
			memory &&
			memory.revision === currentEraRevision &&
			memory.expiresAt > now
		) {
			return memory.value;
		}

		try {
			raw = window.localStorage.getItem(
				getCacheKey( title, currentEraRevision )
			);

			if ( !raw ) {
				return null;
			}

			parsed = JSON.parse( raw );

			if (
				typeof parsed.value !== 'boolean' ||
				Number( parsed.expiresAt ) <= now
			) {
				window.localStorage.removeItem(
					getCacheKey( title, currentEraRevision )
				);
				return null;
			}

			memoryCache.set( title, {
				value: parsed.value,
				expiresAt: Number( parsed.expiresAt ),
				revision: currentEraRevision
			} );

			return parsed.value;
		} catch ( error ) {
			return null;
		}
	}

	function pruneLocalCache() {
		var entries = [];
		var now = Date.now();
		var i;
		var key;
		var raw;
		var parsed;

		try {
			for ( i = window.localStorage.length - 1; i >= 0; i-- ) {
				key = window.localStorage.key( i );

				if ( !key || key.indexOf( CACHE_PREFIX ) !== 0 ) {
					continue;
				}

				raw = window.localStorage.getItem( key );

				try {
					parsed = JSON.parse( raw || 'null' );
				} catch ( error ) {
					parsed = null;
				}

				if ( !parsed || Number( parsed.expiresAt ) <= now ) {
					window.localStorage.removeItem( key );
					continue;
				}

				entries.push( {
					key: key,
					expiresAt: Number( parsed.expiresAt )
				} );
			}

			/* Bound long-lived browser storage even after extensive browsing. */
			if ( entries.length > 2000 ) {
				entries.sort( function ( first, second ) {
					return first.expiresAt - second.expiresAt;
				} );

				entries.slice( 0, entries.length - 2000 ).forEach(
					function ( entry ) {
						window.localStorage.removeItem( entry.key );
					}
				);
			}
		} catch ( error ) {
			/* Ignore private-mode and quota-related storage failures. */
		}
	}

	function scheduleLocalCacheMaintenance() {
		if ( typeof window.requestIdleCallback === 'function' ) {
			window.requestIdleCallback( pruneLocalCache, { timeout: 5000 } );
			return;
		}

		window.setTimeout( pruneLocalCache, 3000 );
	}

	function setCachedStatus( title, value, eraRevision ) {
		var expiresAt = Date.now() + CACHE_TTL_MS;
		var record;

		eraRevision = Number( eraRevision ) || currentEraRevision;
		record = {
			value: !!value,
			expiresAt: expiresAt
		};

		memoryCache.set( title, {
			value: !!value,
			expiresAt: expiresAt,
			revision: eraRevision
		} );

		try {
			window.localStorage.setItem(
				getCacheKey( title, eraRevision ),
				JSON.stringify( record )
			);
		} catch ( error ) {
			/* Ignore quota/private-mode failures. */
		}
	}

	function chunkArray( values, size ) {
		var chunks = [];
		var i;

		for ( i = 0; i < values.length; i += size ) {
			chunks.push( values.slice( i, i + size ) );
		}

		return chunks;
	}

	function requestCustomMetadata( titles ) {
		return new mw.Api().post( {
			action: API_ACTION,
			format: 'json',
			formatversion: 2,
			titles: titles.join( '|' )
		} ).then( function ( data ) {
			var payload = data && data[ API_ACTION ] ? data[ API_ACTION ] : {};
			var responseRevision = Number( payload.eraRevision ) || currentEraRevision;
			var statuses = {};

			if ( responseRevision !== currentEraRevision ) {
				currentEraRevision = responseRevision;
				memoryCache.clear();
			}

			( payload.pages || [] ).forEach( function ( page ) {
				var aliases = Array.isArray( page.requested ) ?
					page.requested :
					[ page.title ];

				aliases.forEach( function ( alias ) {
					var title = normalizeTitle( alias );

					if ( title ) {
						statuses[ title ] = !!page.outOfEra;
						setCachedStatus( title, !!page.outOfEra, responseRevision );
					}
				} );
			} );

			return statuses;
		} );
	}

	/*
	 * Compatibility fallback. It is used only when EQLClientData is missing or
	 * the custom API action fails, so the filter keeps working during a partial
	 * deployment. Normal production traffic should never reach this path.
	 */
	function requestFallbackMetadata( titles ) {
		var statuses = {};
		var chunks = chunkArray( titles, FALLBACK_API_MAX_TITLES );
		var requests = chunks.map( function ( chunk ) {
			return new mw.Api().get( {
				action: 'query',
				format: 'json',
				formatversion: 2,
				prop: 'categories',
				titles: chunk.join( '|' ),
				cllimit: 'max'
			} ).then( function ( data ) {
				var outKeys = mw.config.get( 'wgEQLEraOutKeys', [] ) || [];
				var normalizedKeys = outKeys.map( function ( value ) {
					return String( value || '' )
						.toLowerCase()
						.replace( /[\s_-]+/g, '' );
				} );

				( data.query && data.query.pages ? data.query.pages : [] ).forEach(
					function ( page ) {
						var title = normalizeTitle( page.title );
						var isOut = ( page.categories || [] ).some( function ( category ) {
							var normalized = String( category.title || '' )
								.replace( /^Category:/i, '' )
								.toLowerCase()
								.replace( /[\s_\/-]+/g, '' )
								.replace( /era$/, '' );

							return normalizedKeys.indexOf( normalized ) !== -1;
						} );

						statuses[ title ] = isOut;
						setCachedStatus( title, isOut, currentEraRevision );
					}
				);
			} );
		} );

		return Promise.all( requests ).then( function () {
			return statuses;
		} );
	}

	function requestMetadataChunk( titles ) {
		return requestCustomMetadata( titles ).catch( function () {
			return requestFallbackMetadata( titles );
		} );
	}

	function settlePendingTitle( title, value ) {
		var pending = pendingByTitle.get( title );

		if ( !pending ) {
			return;
		}

		pendingByTitle.delete( title );
		pending.resolve( !!value );
	}

	function flushMetadataQueue() {
		var titles = Array.from( queuedTitles );
		var chunks;

		queuedTitles.clear();
		queueTimer = null;

		if ( !titles.length ) {
			return;
		}

		chunks = chunkArray( titles, API_MAX_TITLES );

		chunks.forEach( function ( chunk ) {
			requestMetadataChunk( chunk ).then( function ( statuses ) {
				chunk.forEach( function ( title ) {
					settlePendingTitle(
						title,
						Object.prototype.hasOwnProperty.call( statuses, title ) ?
							statuses[ title ] :
							false
					);
				} );
			} ).catch( function () {
				chunk.forEach( function ( title ) {
					settlePendingTitle( title, false );
				} );
			} );
		} );
	}

	function fetchStatus( title ) {
		var cached = getCachedStatus( title );
		var pending;

		if ( cached !== null ) {
			return Promise.resolve( cached );
		}

		pending = pendingByTitle.get( title );

		if ( pending ) {
			return pending.promise;
		}

		pending = {};
		pending.promise = new Promise( function ( resolve ) {
			pending.resolve = resolve;
		} );
		pendingByTitle.set( title, pending );
		queuedTitles.add( title );

		if ( !queueTimer ) {
			queueTimer = window.setTimeout( flushMetadataQueue, 20 );
		}

		return pending.promise;
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

		if ( root && ( root.nodeType === 1 || root.nodeType === 9 ) ) {
			return root;
		}

		return document;
	}

	function isControlLink( link ) {
		return !!link.closest( [
			'#ca-edit, #ca-ve-edit, #ca-history, #ca-eql-watch',
			'.mw-editsection',
			'.mw-header',
			'.vector-page-toolbar',
			'.editOptions',
			'.ve-ui-toolbar',
			'.ve-ui-overlay',
			'.oo-ui-windowManager',
			'#footer, .mw-footer-container'
		].join( ', ' ) );
	}

	function collectLinks( root ) {
		var linkMap = new Map();
		var rootElement = getRootElement( root );
		var links = [];

		if (
			rootElement.nodeType === 1 &&
			rootElement.matches &&
			rootElement.matches( 'a[href]' )
		) {
			links.push( rootElement );
		}

		links = links.concat(
			Array.prototype.slice.call(
				rootElement.querySelectorAll( 'a[href]' )
			)
		);

		links.forEach( function ( link ) {
			var title;

			if ( isControlLink( link ) ) {
				clearOutOfEraMark( link );
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

	function applyStatus( links, title, isOutOfEra ) {
		links.forEach( function ( link ) {
			/* A link may have moved into editor UI while metadata was loading. */
			if ( isOutOfEra && !isControlLink( link ) ) {
				markOutOfEraLink( link, title );
			} else {
				clearOutOfEraMark( link );
			}
		} );
	}

	function filterEraLinks( root ) {
		var linkMap;
		var tasks = [];
		var mode;

		if ( !isEQLImmersive() ) {
			return Promise.resolve();
		}

		ensureEraToggleButton();
		mode = getFilterMode();
		applyModeClasses( mode );
		updateEraToggleButton( mode );

		if ( mode === MODE_OFF ) {
			updateHiddenRows( mode );
			return Promise.resolve();
		}

		linkMap = collectLinks( root );

		linkMap.forEach( function ( links, title ) {
			var cached = getCachedStatus( title );

			if ( cached !== null ) {
				applyStatus( links, title, cached );
				return;
			}

			tasks.push(
				fetchStatus( title ).then( function ( isOutOfEra ) {
					applyStatus( links, title, isOutOfEra );
				} )
			);
		} );

		updateHiddenRows( mode );

		return Promise.all( tasks ).then( function () {
			updateHiddenRows( getFilterMode() );
		} );
	}

	function flushRefreshQueue() {
		var roots = Array.from( refreshRoots );

		refreshRoots.clear();
		refreshTimer = null;

		/* A document scan subsumes all smaller roots. */
		if ( roots.indexOf( document ) !== -1 ) {
			filterEraLinks( document );
			return;
		}

		roots.forEach( function ( root ) {
			filterEraLinks( root );
		} );
	}

	function queueRefresh( root ) {
		refreshRoots.add( getRootElement( root ) );

		if ( !refreshTimer ) {
			refreshTimer = window.setTimeout( flushRefreshQueue, 0 );
		}
	}

	mw.loader.using( [ 'mediawiki.api' ] ).then( function () {
		scheduleLocalCacheMaintenance();
		window.eqlEraFilterRefresh = queueRefresh;
		window.eqlEraFilter = {
			refresh: queueRefresh,
			getMode: getFilterMode,
			setMode: setFilterMode
		};

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', function () {
				queueRefresh( document );
			} );
		} else {
			queueRefresh( document );
		}

		if ( mw.hook ) {
			mw.hook( 'wikipage.content' ).add( function ( $content ) {
				queueRefresh( $content );
			} );
		}
	} );
}());
