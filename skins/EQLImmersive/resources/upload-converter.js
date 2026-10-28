/* EQLImmersive: native upload return/cache tracking.
 * This is navigation context, never evidence of file ownership or permission. */
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

	function currentIdentity() {
		return JSON.stringify( [ window.location.origin, mw.config.get( 'wgDBname' ) || '',
			Number( mw.config.get( 'wgUserId' ) ) || 0 ] );
	}

	function validTitle( title ) {
		return typeof title === 'string' && title.length > 0 && title.length <= 512 &&
			!/[\x00-\x1f\x7f|]/.test( title );
	}

	function storePendingPurge( returnTo, destFile, alsoPurge ) {
		returnTo = normalizeTitle( returnTo );
		destFile = normalizeTitle( destFile );
		alsoPurge = normalizeTitleList( alsoPurge );
		if ( !validTitle( returnTo ) || !validTitle( destFile ) || alsoPurge.length > 20 ||
			!alsoPurge.every( validTitle )
		) {
			clearPendingPurge();
			return;
		}

		try {
			sessionStorage.setItem( STORAGE_KEY, JSON.stringify( {
				returnTo: returnTo, destFile: destFile, alsoPurge: alsoPurge,
				identity: currentIdentity(), timestamp: Date.now()
			} ) );
		} catch ( e ) {
			// Storage denial must not stop MediaWiki's native upload submission.
		}
	}

	function readPendingPurge() {
		var raw;
		var pending;

		try {
			raw = sessionStorage.getItem( STORAGE_KEY );
			if ( !raw ) {
				return null;
			}
			pending = raw.length <= 16384 ? JSON.parse( raw ) : null;
		} catch ( e ) {
			clearPendingPurge();
			return null;
		}
		if ( !pending || pending.identity !== currentIdentity() || isExpired( pending ) ||
			!validTitle( pending.returnTo ) || !validTitle( pending.destFile ) ||
			!Array.isArray( pending.alsoPurge ) || pending.alsoPurge.length > 20 ||
			!pending.alsoPurge.every( validTitle )
		) {
			clearPendingPurge();
			return null;
		}
		return pending;
	}

	function clearPendingPurge() {
		try {
			sessionStorage.removeItem( STORAGE_KEY );
		} catch ( e ) {
			// The optional return step must remain harmless without browser storage.
		}
	}

	function isExpired( pending ) {
		return !pending || !Number.isFinite( pending.timestamp ) || pending.timestamp <= 0 ||
			Date.now() < pending.timestamp || Date.now() - pending.timestamp > 15 * 60 * 1000;
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
		var alsoPurge;
		var form;
		var pending;
		var identity;

		if ( mw.config.get( 'wgCanonicalSpecialPageName' ) !== 'Upload' ) {
			return;
		}

		returnTo = getParam( 'eqlReturnTo' );
		alsoPurge = getParam( 'eqlAlsoPurge' );
		form = document.getElementById( 'mw-upload-form' );
		if ( !form || form.dataset.eqlUploadReturnBound === '1' ) {
			return;
		}
		// Core warning/stash forms can lose the original GET query. Reuse only
		// this account's recent context, and still snapshot the new destination.
		if ( !returnTo && form.querySelector( '[name="wpUploadIgnoreWarning"],[name="wpSessionKey"]' ) ) {
			pending = readPendingPurge();
			if ( pending ) {
				returnTo = pending.returnTo;
				alsoPurge = pending.alsoPurge.join( '|' );
			}
		}
		if ( !returnTo ) {
			return;
		}

		form.dataset.eqlUploadReturnBound = '1';
		identity = currentIdentity();
		form.addEventListener( 'submit', function ( event ) {
			var destination;
			if ( identity !== currentIdentity() || event.defaultPrevented ||
				event.submitter && event.submitter.name === 'wpCancelUpload'
			) {
				clearPendingPurge();
				return;
			}
			destination = form.elements.namedItem( 'wpDestFile' );
			if ( !destination || destination.disabled || typeof destination.value !== 'string' ) {
				clearPendingPurge();
				return;
			}
			storePendingPurge( returnTo, destination.value, alsoPurge );
		}, true );
		// Native form.submit() bypasses submit handlers, but still constructs the
		// final form data. Observe that destination without altering any field,
		// File/FileList, conversion handler, FormData entry or submission method.
		form.addEventListener( 'formdata', function ( event ) {
			var data = event.formData;
			// Supplement capture after script-driven FormData handlers. This is
			// best-effort navigation context, not server/file authority, and it
			// never delays or intercepts native submission.
			Promise.resolve().then( function () {
				var destinations;
				if ( identity !== currentIdentity() || !data || typeof data.getAll !== 'function' ||
					typeof data.has !== 'function' || data.has( 'wpCancelUpload' )
				) {
					clearPendingPurge();
					return;
				}
				destinations = data.getAll( 'wpDestFile' );
				if ( destinations.length !== 1 || typeof destinations[ 0 ] !== 'string' ) {
					clearPendingPurge();
					return;
				}
				storePendingPurge( returnTo, destinations[ 0 ], alsoPurge );
			} );
		}, { passive: true } );
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
		if ( currentTitle !== normalizeTitle( fileTitle ) && currentTitle !== returnTo ) {
			return;
		}

		titlesToPurge = uniqueTitles(
			[ returnTo, fileTitle ].concat( pending.alsoPurge || [] )
		);

		purgeTitles( titlesToPurge )
			.then( function () {
				if ( pending.identity !== currentIdentity() || getCurrentTitle() !== currentTitle ) {
					return;
				}
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
				if ( pending.identity !== currentIdentity() || getCurrentTitle() !== currentTitle ) {
					return;
				}
				clearPendingPurge();

				if ( /^File:/i.test( currentTitle ) ) {
					window.location.href = mw.util.getUrl( returnTo, {
						action: 'purge'
					} );
				}
			} );
	}

	mw.loader.using( [ 'mediawiki.api', 'mediawiki.util' ] ).then( function () {
		function initialize() {
			initUploadPageTracker();
			initPostUploadPurge();
		}
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', initialize, { once: true } );
		} else {
			initialize();
		}
	} );
}() );

/* EQL Wiki - Add return/purge info to NPC upload links */
