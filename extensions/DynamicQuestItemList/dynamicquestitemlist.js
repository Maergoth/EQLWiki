/* =====================================================
   DynamicQuestItemList filters and chunk loader
   ===================================================== */

(function () {
	'use strict';

	var CACHE_VERSION = 'v8';
	var CACHE_TTL_MS = 12 * 60 * 60 * 1000;
	var MAX_EMPTY_CHUNKS_IN_A_ROW = 10;

	function normalizeText( text ) {
		return String( text || '' )
			.replace( /\s+/g, ' ' )
			.toLowerCase()
			.trim();
	}

	function getStorage() {
		try {
			var key = 'dqil-storage-test';
			window.localStorage.setItem( key, '1' );
			window.localStorage.removeItem( key );
			return window.localStorage;
		} catch ( e ) {}

		try {
			var skey = 'dqil-session-storage-test';
			window.sessionStorage.setItem( skey, '1' );
			window.sessionStorage.removeItem( skey );
			return window.sessionStorage;
		} catch ( e2 ) {}

		return null;
	}

	function safeGet( key ) {
		var storage = getStorage();

		if ( !storage ) {
			return null;
		}

		try {
			return storage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function safeSet( key, value ) {
		var storage = getStorage();

		if ( !storage ) {
			return false;
		}

		try {
			storage.setItem( key, value );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	function safeRemove( key ) {
		var storage = getStorage();

		if ( !storage ) {
			return;
		}

		try {
			storage.removeItem( key );
		} catch ( e ) {}
	}

	function getTableCacheBase( table ) {
		var category = table.getAttribute( 'data-dqil-category' ) || 'Quest Items';
		var total = table.getAttribute( 'data-dqil-total' ) || '0';
		var limit = table.getAttribute( 'data-dqil-limit' ) || '500';

		return 'dqil:' + CACHE_VERSION + ':' + category + ':total:' + total + ':limit:' + limit;
	}

	function getManifestKey( table ) {
		return getTableCacheBase( table ) + ':manifest';
	}

	function getChunkKey( table, offset ) {
		return getTableCacheBase( table ) + ':chunk:' + offset;
	}

	function readManifest( table ) {
		var raw = safeGet( getManifestKey( table ) );
		var manifest;

		if ( !raw ) {
			return null;
		}

		try {
			manifest = JSON.parse( raw );
		} catch ( e ) {
			return null;
		}

		if (
			!manifest ||
			!manifest.complete ||
			!manifest.offsets ||
			!manifest.created ||
			Date.now() - manifest.created > CACHE_TTL_MS
		) {
			return null;
		}

		return manifest;
	}

	function writeManifest( table, offsets ) {
		var manifest = {
			complete: true,
			created: Date.now(),
			offsets: offsets
		};

		safeSet( getManifestKey( table ), JSON.stringify( manifest ) );
	}

	function getCellText( row, selector, fallbackIndex ) {
		var cell = row.querySelector( selector );
		var cells;

		if ( cell ) {
			return normalizeText( cell.textContent || '' );
		}

		cells = row.children || [];

		if ( cells[ fallbackIndex ] ) {
			return normalizeText( cells[ fallbackIndex ].textContent || '' );
		}

		return '';
	}

	function getTableBody( table ) {
		return table.querySelector( 'tbody.dqil-body' ) || table.tBodies[ 0 ] || table;
	}

	function getStatusForTable( table ) {
		if ( !table || !table.id ) {
			return null;
		}

		return document.querySelector( '.dqil-load-status[data-dqil-table-id="' + table.id + '"]' );
	}

	function setStatus( table, text, isError ) {
		var status = getStatusForTable( table );

		if ( !status ) {
			return;
		}

		status.textContent = text;
		status.classList.toggle( 'dqil-load-status-error', !!isError );
	}

	function applyTableFilter( table ) {
		var bar = document.querySelector( '.dqil-filterbar[data-dqil-table-id="' + table.id + '"]' );
		var searchInput = bar ? bar.querySelector( '[data-dqil-column="item-or-quest"]' ) : null;
		var countEl = bar ? bar.querySelector( '.dqil-filter-count' ) : null;
		var needle = normalizeText( searchInput ? searchInput.value : '' );
		var rows = Array.prototype.slice.call( table.querySelectorAll( 'tr.dqil-row' ) );
		var visible = 0;

		rows.forEach( function ( row ) {
			var itemText = getCellText( row, '.dqil-item-cell', 0 );
			var questText = getCellText( row, '.dqil-quest-cell', 2 );
			var show = !needle ||
				itemText.indexOf( needle ) !== -1 ||
				questText.indexOf( needle ) !== -1;

			row.classList.toggle( 'dqil-hidden', !show );

			if ( show ) {
				visible++;
			}
		} );

		if ( countEl ) {
			countEl.textContent = 'Showing ' + visible + ' of ' + rows.length + ' loaded';
		}
	}

	function initFilterBar( bar ) {
		var tableId = bar.getAttribute( 'data-dqil-table-id' );
		var table = tableId ? document.getElementById( tableId ) : null;
		var searchInput = bar.querySelector( '[data-dqil-column="item-or-quest"]' );
		var clearButton = bar.querySelector( '.dqil-filter-clear' );

		if ( !table || !searchInput || bar.dataset.dqilFilterReady === '1' ) {
			return;
		}

		bar.dataset.dqilFilterReady = '1';

		searchInput.addEventListener( 'input', function () {
			applyTableFilter( table );
		} );

		if ( clearButton ) {
			clearButton.addEventListener( 'click', function () {
				searchInput.value = '';
				applyTableFilter( table );
				searchInput.focus();
			} );
		}

		applyTableFilter( table );
	}

	function getChunkUrl( table ) {
		var params = new URLSearchParams();
		var limit = parseInt( table.getAttribute( 'data-dqil-limit' ) || '500', 10 );
		var offset = parseInt( table.getAttribute( 'data-dqil-next-offset' ) || '0', 10 );
		var category = table.getAttribute( 'data-dqil-category' ) || 'Quest Items';

		params.set( 'dqil_action', 'chunk' );
		params.set( 'category', category );
		params.set( 'offset', String( offset ) );
		params.set( 'limit', String( limit ) );

		return mw.util.getUrl( 'Special:DynamicQuestItemList' ) + '?' + params.toString();
	}

	function extractRowsFromHtml( html ) {
		var temp = document.createElement( 'div' );

		temp.innerHTML = html || '';
		return Array.prototype.slice.call( temp.querySelectorAll( 'tr.dqil-row' ) );
	}

	function appendChunkRows( table, html ) {
		var tbody = getTableBody( table );
		var rows = extractRowsFromHtml( html );

		rows.forEach( function ( row ) {
			tbody.appendChild( row );
		} );

		return rows.length;
	}

	function updateTablesorter( table ) {
		if ( window.jQuery && jQuery.fn && jQuery.fn.tablesorter ) {
			try {
				jQuery( table ).trigger( 'update' );
			} catch ( e ) {}
		}
	}

	function restoreCompleteCache( table ) {
		var manifest = readManifest( table );
		var restored = 0;

		if ( !manifest ) {
			return false;
		}

		for ( var i = 0; i < manifest.offsets.length; i++ ) {
			var offset = manifest.offsets[ i ];
			var html = safeGet( getChunkKey( table, offset ) );

			if ( !html ) {
				return false;
			}

			restored += appendChunkRows( table, html );
		}

		table.dataset.dqilDone = '1';
		applyTableFilter( table );
		updateTablesorter( table );
		setStatus( table, 'Loaded ' + restored + ' rows from cache.', false );

		return true;
	}

	function loadNextChunk( table ) {
		var url;
		var total = parseInt( table.getAttribute( 'data-dqil-total' ) || '0', 10 );
		var limit = parseInt( table.getAttribute( 'data-dqil-limit' ) || '500', 10 );
		var nextOffset = parseInt( table.getAttribute( 'data-dqil-next-offset' ) || '0', 10 );
		var loadedRows = table.querySelectorAll( 'tr.dqil-row' ).length;
		var loadedOffsets = table.dataset.dqilLoadedOffsets ?
			table.dataset.dqilLoadedOffsets.split( ',' ).filter( Boolean ).map( function ( value ) {
				return parseInt( value, 10 );
			} ) :
			[];

		if (
			table.dataset.dqilLoading === '1' ||
			table.dataset.dqilDone === '1'
		) {
			return;
		}

		if ( total > 0 && nextOffset >= total ) {
			table.dataset.dqilDone = '1';
			writeManifest( table, loadedOffsets );
			setStatus( table, 'Loaded ' + loadedRows + ' rows.', false );
			return;
		}

		table.dataset.dqilLoading = '1';
		setStatus( table, 'Loading rows ' + ( nextOffset + 1 ) + '–' + Math.min( nextOffset + limit, total || nextOffset + limit ) + '…', false );

		url = getChunkUrl( table );

		fetch( url, {
			credentials: 'same-origin'
		} )
			.then( function ( response ) {
				if ( !response.ok ) {
					throw new Error( 'Request failed: ' + response.status );
				}

				return response.json();
			} )
			.then( function ( data ) {
				var addedRows;

				if ( !data || !data.ok ) {
					throw new Error( 'Chunk response was not valid.' );
				}

				addedRows = appendChunkRows( table, data.html );
				table.setAttribute( 'data-dqil-next-offset', String( data.nextOffset || nextOffset ) );

				safeSet( getChunkKey( table, nextOffset ), data.html || '' );
				loadedOffsets.push( nextOffset );
				table.dataset.dqilLoadedOffsets = loadedOffsets.join( ',' );

				if ( addedRows === 0 ) {
					table.dataset.dqilEmptyChunks = String( ( parseInt( table.dataset.dqilEmptyChunks || '0', 10 ) || 0 ) + 1 );
				} else {
					table.dataset.dqilEmptyChunks = '0';
				}

				applyTableFilter( table );
				updateTablesorter( table );

				loadedRows = table.querySelectorAll( 'tr.dqil-row' ).length;

				if (
					data.done ||
					parseInt( table.dataset.dqilEmptyChunks || '0', 10 ) >= MAX_EMPTY_CHUNKS_IN_A_ROW
				) {
					table.dataset.dqilDone = '1';
					writeManifest( table, loadedOffsets );
					setStatus( table, 'Loaded ' + loadedRows + ' rows.', false );
					table.dataset.dqilLoading = '0';
					return;
				}

				setStatus( table, 'Loaded ' + loadedRows + ' rows. Continuing…', false );

				/*
				 * Start the next request as soon as this chunk is fully appended
				 * and filtered. There is intentionally no fixed interval between
				 * chunk requests.
				 */
				table.dataset.dqilLoading = '0';
				loadNextChunk( table );
			} )
			.catch( function ( error ) {
				table.dataset.dqilLoading = '0';
				setStatus( table, 'Could not load quest item rows. ' + error.message, true );
			} );
	}

	function initChunkedTable( table ) {
		if ( table.dataset.dqilChunkReady === '1' ) {
			return;
		}

		table.dataset.dqilChunkReady = '1';

		if ( restoreCompleteCache( table ) ) {
			return;
		}

		loadNextChunk( table );
	}

	function initDynamicQuestItemLists( root ) {
		var scope = root || document;

		if ( !scope.querySelectorAll ) {
			return;
		}

		scope.querySelectorAll( '.dqil-filterbar' ).forEach( initFilterBar );
		scope.querySelectorAll( 'table.dqil-table[data-dqil-category]' ).forEach( initChunkedTable );
	}

	function init() {
		initDynamicQuestItemLists( document );

		if ( window.mw && mw.hook ) {
			mw.hook( 'wikipage.content' ).add( function ( $content ) {
				if ( $content && $content[ 0 ] ) {
					initDynamicQuestItemLists( $content[ 0 ] );
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}());
