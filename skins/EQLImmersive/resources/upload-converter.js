/* EQLImmersive: Upload image normalizer.
 * Re-encodes uploaded images via canvas before MediaWiki receives them.
 * Fixes PNGs that display in browsers but fail ImageMagick server-side. */
(function () {
	'use strict';

	var STORAGE_KEY = 'eqlPendingUploadPurge';

	function getParam( name ) {
		return new URLSearchParams( window.location.search ).get( name );
	}

	function normalizeTitle( title ) {
		return String( title || '' ).replace( /_/g, ' ' ).trim();
	}

	function normalizeTitleList( value ) {
		return String( value || '' )
			.split( '|' )
			.map( normalizeTitle )
			.filter( function ( title ) {
				return title !== '';
			} );
	}

	function uniqueTitles( titles ) {
		var seen = {};
		var out = [];

		titles.forEach( function ( title ) {
			title = normalizeTitle( title );

			if ( title && !seen[ title ] ) {
				seen[ title ] = true;
				out.push( title );
			}
		} );

		return out;
	}

	function getCurrentTitle() {
		return normalizeTitle( mw.config.get( 'wgPageName' ) );
	}

	function storePendingPurge( returnTo, destFile, alsoPurge ) {
		if ( !returnTo || !destFile ) {
			return;
		}

		sessionStorage.setItem( STORAGE_KEY, JSON.stringify( {
			returnTo: normalizeTitle( returnTo ),
			destFile: normalizeTitle( destFile ),
			alsoPurge: normalizeTitleList( alsoPurge ),
			timestamp: Date.now()
		} ) );
	}

	function readPendingPurge() {
		var raw = sessionStorage.getItem( STORAGE_KEY );

		if ( !raw ) {
			return null;
		}

		try {
			return JSON.parse( raw );
		} catch ( e ) {
			sessionStorage.removeItem( STORAGE_KEY );
			return null;
		}
	}

	function clearPendingPurge() {
		sessionStorage.removeItem( STORAGE_KEY );
	}

	function isExpired( pending ) {
		return !pending || !pending.timestamp || Date.now() - pending.timestamp > 15 * 60 * 1000;
	}

	function buildPageUrl( title, extraParams ) {
		var url = mw.util.getUrl( title );
		var sep = url.indexOf( '?' ) === -1 ? '?' : '&';
		return url + sep + new URLSearchParams( extraParams ).toString();
	}

	function purgeTitles( titles ) {
		titles = uniqueTitles( titles );

		if ( !titles.length ) {
			return $.Deferred().resolve().promise();
		}

		return new mw.Api().post( {
			action: 'purge',
			titles: titles.join( '|' ),
			forcelinkupdate: 1,
			format: 'json'
		} );
	}

	function initUploadPageTracker() {
		var returnTo;
		var destFile;
		var alsoPurge;
		var form;

		if ( mw.config.get( 'wgCanonicalSpecialPageName' ) !== 'Upload' ) {
			return;
		}

		returnTo = getParam( 'eqlReturnTo' );
		destFile = getParam( 'wpDestFile' );
		alsoPurge = getParam( 'eqlAlsoPurge' );

		if ( !returnTo || !destFile ) {
			return;
		}

		form = document.querySelector( 'form' );

		if ( !form ) {
			return;
		}

		form.addEventListener( 'submit', function () {
			storePendingPurge( returnTo, destFile, alsoPurge );
		}, true );
	}

	function initPostUploadPurge() {
		var pending;
		var returnTo;
		var destFile;
		var fileTitle;
		var currentTitle;
		var titlesToPurge;

		if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'Upload' ) {
			return;
		}

		pending = readPendingPurge();

		if ( !pending || isExpired( pending ) ) {
			clearPendingPurge();
			return;
		}

		returnTo = pending.returnTo;
		destFile = pending.destFile;
		fileTitle = /^File:/i.test( destFile ) ? destFile : 'File:' + destFile;

		if ( !returnTo || !destFile ) {
			clearPendingPurge();
			return;
		}

		currentTitle = getCurrentTitle();

		if ( getParam( 'eqlUploadPurged' ) === '1' ) {
			clearPendingPurge();
			return;
		}

		titlesToPurge = uniqueTitles(
			[ returnTo, fileTitle ].concat( pending.alsoPurge || [] )
		);

		purgeTitles( titlesToPurge )
			.then( function () {
				clearPendingPurge();

				if ( /^File:/i.test( currentTitle ) ) {
					window.location.href = buildPageUrl( returnTo, {
						eqlUploadPurged: '1',
						_: Date.now()
					} );
					return;
				}

				if ( currentTitle === returnTo ) {
					window.location.href = buildPageUrl( returnTo, {
						eqlUploadPurged: '1',
						_: Date.now()
					} );
				}
			} )
			.catch( function () {
				clearPendingPurge();

				if ( /^File:/i.test( currentTitle ) ) {
					window.location.href = mw.util.getUrl( returnTo, {
						action: 'purge'
					} );
				}
			} );
	}

	mw.loader.using( [ 'mediawiki.api', 'mediawiki.util' ] ).then( function () {
		initUploadPageTracker();
		initPostUploadPurge();
	} );
}() );

/* EQL Wiki - Add return/purge info to NPC upload links */