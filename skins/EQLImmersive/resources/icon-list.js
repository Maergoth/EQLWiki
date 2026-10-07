/* Browse every uploaded ID while keeping at most 100 image cells in the DOM. */
( function () {
	'use strict';
	var PAGE_SIZE = 100;
	function init( root ) {
		if ( root.dataset.initialized ) { return; }
		root.dataset.initialized = '1';
		var icons = [], page = 0, filtered = [];
		function element( tag, text ) {
			var node = document.createElement( tag );
			if ( text !== undefined ) { node.textContent = text; }
			return node;
		}
		var toolbar = element( 'div' );
		toolbar.className = 'eql-icon-catalog-toolbar';
		var kind = element( 'select' );
		kind.id = 'eql-icon-kind';
		[ [ 'item', 'Item icons' ], [ 'spell', 'Spell icons' ] ].forEach( function ( option ) {
			var node = element( 'option', option[1] ); node.value = option[0]; kind.appendChild( node );
		} );
		var kindLabel = element( 'label', 'Library ' );
		kindLabel.htmlFor = kind.id; kindLabel.appendChild( kind );
		var input = element( 'input' );
		input.id = 'eql-icon-id'; input.type = 'search'; input.placeholder = 'e.g. 12091';
		var inputLabel = element( 'label', 'Find exact ID ' );
		inputLabel.htmlFor = input.id; inputLabel.appendChild( input );
		toolbar.append( kindLabel, inputLabel );
		var status = element( 'p', 'Loading icon catalog…' );
		status.setAttribute( 'role', 'status' ); status.setAttribute( 'aria-live', 'polite' );
		var previous = element( 'button', 'Previous' ), next = element( 'button', 'Next' );
		previous.type = next.type = 'button';
		var navigation = element( 'div' ); navigation.className = 'eql-icon-catalog-navigation';
		navigation.append( previous, next );
		var grid = element( 'div' ); grid.className = 'eql-icon-catalog-grid';
		root.replaceChildren( toolbar, status, navigation, grid );
		function render() {
			filtered = icons.filter( function ( icon ) {
				return icon[0] === kind.value && ( !input.value.trim() || icon[1].toLowerCase() === input.value.trim().toLowerCase() );
			} );
			var pages = Math.max( 1, Math.ceil( filtered.length / PAGE_SIZE ) );
			page = Math.min( page, pages - 1 );
			grid.replaceChildren();
			filtered.slice( page * PAGE_SIZE, ( page + 1 ) * PAGE_SIZE ).forEach( function ( icon ) {
				var cell = element( 'a' ); cell.href = mw.util.getUrl( icon[2] ); cell.title = icon[2];
				var image = element( 'img' ); image.src = icon[3]; image.alt = icon[2];
				image.width = image.height = 40; image.loading = 'lazy'; image.decoding = 'async';
				cell.append( image, element( 'span', icon[1] ) ); grid.appendChild( cell );
			} );
			previous.disabled = page === 0; next.disabled = page + 1 >= pages;
			status.textContent = filtered.length ?
				'Icons ' + ( page * PAGE_SIZE + 1 ) + '–' + Math.min( ( page + 1 ) * PAGE_SIZE, filtered.length ) +
				' of ' + filtered.length.toLocaleString() + ' · Page ' + ( page + 1 ) + ' of ' + pages :
				'No uploaded icon has that ID.';
		}
		kind.addEventListener( 'change', function () { page = 0; render(); } );
		input.addEventListener( 'input', function () { page = 0; render(); } );
		previous.addEventListener( 'click', function () { page--; render(); } );
		next.addEventListener( 'click', function () { page++; render(); } );
		function json( url ) {
			return fetch( url, { credentials: 'same-origin' } ).then( function ( response ) {
				if ( !response.ok ) { throw new Error( 'Catalog unavailable' ); } return response.json();
			} );
		}
		// API fallback reads metadata only; never downloads the fingerprint cache.
		function fallback() {
			var api = new mw.Api(), result = [];
			function read( prefix, continuation ) {
				return api.get( Object.assign( {
					action: 'query', list: 'allimages', aiprefix: prefix,
					aiprop: 'url', ailimit: 500, formatversion: 2
				}, continuation || {} ) ).then( function ( response ) {
					( response.query.allimages || [] ).forEach( function ( image ) {
						var match = image.name.match( /^(Item|Spellicon)_([A-Za-z0-9]+)\.png$/ );
						if ( match && ( match[1] !== 'Item' || /^[1-9][0-9]*$/.test( match[2] ) ) ) {
							result.push( [ match[1] === 'Item' ? 'item' : 'spell', match[2], 'File:' + image.name, image.url ] );
						}
					} );
					return response.continue ? read( prefix, response.continue ) : null;
				} );
			}
			return read( 'Item_' ).then( function () { return read( 'Spellicon_' ); } ).then( function () {
				result.sort( function ( a, b ) { return a[1].localeCompare( b[1], undefined, { numeric: true } ); } );
				return { icons: result };
			} );
		}
		function load() {
			root.setAttribute( 'aria-busy', 'true' ); kind.disabled = input.disabled = previous.disabled = next.disabled = true;
			var base = mw.config.get( 'wgScriptPath' ) + '/static/eql-icon-index/';
			json( base + 'meta.json?catalog=1' ).then( function ( meta ) {
				if ( !/^catalog-[a-f0-9]{24}\.json$/.test( meta.catalog || '' ) ) { throw new Error( 'Old catalog' ); }
				return json( base + meta.catalog );
			} ).catch( fallback ).then( function ( data ) {
				icons = data.icons; root.setAttribute( 'aria-busy', 'false' ); kind.disabled = input.disabled = false; render();
			} ).catch( function () {
				root.setAttribute( 'aria-busy', 'false' ); status.textContent = 'Could not load the icon catalog. ';
				var retry = element( 'button', 'Retry' ); retry.type = 'button';
				retry.addEventListener( 'click', function () { retry.remove(); load(); } ); status.appendChild( retry );
			} );
		}
		load();
	}
	mw.hook( 'wikipage.content' ).add( function ( content ) {
		var root = content[0].querySelector( '#eql-icon-catalog' ); if ( root ) { init( root ); }
	} );
}() );
