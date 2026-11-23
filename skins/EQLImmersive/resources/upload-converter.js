/* EQLImmersive: normalize local images before native upload, with return/cache
 * tracking that never grants file ownership or permission. */
(function () {
	'use strict';

	var STORAGE_KEY = 'eqlPendingUploadPurge';
	var IMAGE_TYPES = {
		png: 'image/png',
		jpg: 'image/jpeg',
		jpeg: 'image/jpeg',
		webp: 'image/webp',
		gif: 'image/gif'
	};

	function destinationType( name ) {
		var extension = /\.(png|jpe?g|webp|gif)$/i.exec( name.trim() );
		return extension ? IMAGE_TYPES[ extension[ 1 ].toLowerCase() ] : '';
	}

	function imageType( file ) {
		// The browser's File.type follows the source extension, which can be wrong.
		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onerror = function () {
				reject( new Error( 'The selected image could not be read. Please select it again.' ) );
			};
			reader.onload = function () {
				var bytes = new Uint8Array( reader.result );
				var header = Array.from( bytes ).map( function ( byte ) {
					return String.fromCharCode( byte );
				} ).join( '' );
				if ( header.slice( 0, 8 ) === '\x89PNG\r\n\x1a\n' ) {
					resolve( 'image/png' );
				} else if ( bytes[ 0 ] === 255 && bytes[ 1 ] === 216 && bytes[ 2 ] === 255 ) {
					resolve( 'image/jpeg' );
				} else if ( /^GIF8[79]a/.test( header ) ) {
					resolve( 'image/gif' );
				} else if ( header.slice( 0, 4 ) === 'RIFF' && header.slice( 8, 12 ) === 'WEBP' ) {
					resolve( 'image/webp' );
				} else {
					resolve( '' );
				}
			};
			reader.readAsArrayBuffer( file.slice( 0, 12 ) );
		} );
	}

	function convertImage( file, type, name ) {
		return new Promise( function ( resolve, reject ) {
			var image = new Image();
			var url = URL.createObjectURL( file );
			image.onerror = function () {
				URL.revokeObjectURL( url );
				reject( new Error( 'This browser could not read the image. Please choose another image or convert it first.' ) );
			};
			image.onload = function () {
				URL.revokeObjectURL( url );
				try {
					var canvas = document.createElement( 'canvas' );
					canvas.width = image.naturalWidth;
					canvas.height = image.naturalHeight;
					var context = canvas.getContext( '2d' );
					if ( type === 'image/jpeg' ) {
						// Preserve the original converter's black JPEG backdrop.
						context.fillStyle = '#000';
						context.fillRect( 0, 0, canvas.width, canvas.height );
					}
					context.drawImage( image, 0, 0 );
					if ( type === 'image/gif' ) {
						// Canvas has no GIF encoder. Load the bundled encoder only
						// for a mismatched GIF destination; matching GIFs stay intact.
						Promise.resolve( mw.loader.using( [ 'skins.EQLImmersive.gifEncoder' ] ) ).then( function ( require ) {
							if ( canvas.width > 65535 || canvas.height > 65535 ) {
								throw new Error( 'The image dimensions are too large for GIF.' );
							}
							var gifenc = require( 'skins.EQLImmersive.gifEncoder' );
							var pixels = context.getImageData( 0, 0, canvas.width, canvas.height ).data;
							// Quantization and palette mapping must see the same
							// one-bit alpha, including semitransparent source edges.
							for ( var offset = 0; offset < pixels.length; offset += 4 ) {
								if ( pixels[ offset + 3 ] <= 127 ) {
									pixels[ offset ] = pixels[ offset + 1 ] = pixels[ offset + 2 ] = pixels[ offset + 3 ] = 0;
								} else {
									pixels[ offset + 3 ] = 255;
								}
							}
							var palette = gifenc.quantize( pixels, 256, { format: 'rgba4444', oneBitAlpha: true } );
							var indexed = gifenc.applyPalette( pixels, palette, 'rgba4444' );
							var transparent = palette.findIndex( function ( color ) {
								return color[ 3 ] === 0;
							} );
							var gif = gifenc.GIFEncoder();
							gif.writeFrame( indexed, canvas.width, canvas.height, {
								palette: palette, transparent: transparent !== -1, transparentIndex: transparent
							} );
							gif.finish();
							resolve( new File( [ gif.bytes() ], name, { type: type, lastModified: file.lastModified } ) );
						} ).catch( reject ).finally( function () {
							canvas.width = canvas.height = 0;
						} );
						return;
					}
					canvas.toBlob( function ( blob ) {
						canvas.width = canvas.height = 0;
						// Browsers can silently return PNG for an unsupported encoder.
						if ( !blob || !blob.size || blob.type !== type ) {
							reject( new Error( 'This browser could not convert the image to the destination format. Please convert it first.' ) );
							return;
						}
						try {
							resolve( new File( [ blob ], name, { type: type, lastModified: file.lastModified } ) );
						} catch ( error ) {
							reject( error );
						}
					}, type, 0.92 );
				} catch ( error ) {
					reject( new Error( 'The image could not be converted. Please convert it first or choose a smaller image.' ) );
				}
			};
			image.src = url;
		} );
	}

	function initUploadConverter() {
		var form = document.getElementById( 'mw-upload-form' );
		var input = document.getElementById( 'wpUploadFile' );
		var destination = document.getElementById( 'wpDestFile' );
		var busy = false;
		var sequence = 0;
		var readyFile;
		var readyName;
		var status;

		if ( mw.config.get( 'wgCanonicalSpecialPageName' ) !== 'Upload' || !form || !input || !destination ||
			form.dataset.eqlUploadConverterBound === '1'
		) {
			return;
		}
		form.dataset.eqlUploadConverterBound = '1';

		function showStatus( message, error ) {
			if ( !status ) {
				status = document.createElement( 'p' );
				status.id = 'eql-upload-conversion-status';
				status.setAttribute( 'role', 'status' );
				form.prepend( status );
			}
			status.className = 'mw-message-box ' + ( error ? 'mw-message-box-error' : 'mw-message-box-notice' );
			status.textContent = message;
		}

		form.addEventListener( 'submit', function ( event ) {
			var submitter = event.submitter || form.querySelector( '[name="wpUpload"]' );
			var file = input.files && input.files[ 0 ];
			var name = destination.value;
			var type = destinationType( name );
			var attempt;
			var identity;

			if ( event.defaultPrevented ) {
				return;
			}
			if ( submitter && /^(wpCancelUpload|wpReUpload)$/.test( submitter.name ) ) {
				sequence++;
				busy = false;
				readyFile = null;
				if ( status ) {
					status.remove();
					status = null;
				}
				return;
			}
			if ( busy ) {
				event.preventDefault();
				event.stopImmediatePropagation();
				return;
			}
			if ( input.disabled || !file || !type ) {
				return;
			}
			if ( file === readyFile && name === readyName ) {
				readyFile = null;
				return;
			}

			event.preventDefault();
			event.stopImmediatePropagation();
			busy = true;
			attempt = ++sequence;
			identity = currentIdentity();
			showStatus( 'Preparing image for upload…', false );

			imageType( file ).then( function ( sourceType ) {
				// Keep the old PNG repair behavior, without recompressing matching
				// JPEGs or flattening animations in matching GIF/WebP uploads.
				if ( attempt !== sequence || ( sourceType === type && type !== 'image/png' ) ) {
					return file;
				}
				return convertImage( file, type, name.trim() );
			} ).then( function ( converted ) {
				if ( attempt !== sequence ) {
					return;
				}
				if ( identity !== currentIdentity() ) {
					throw new Error( 'Your account changed while preparing the image. Please reload before uploading.' );
				}
				if ( input.disabled || input.files[ 0 ] !== file || destination.value !== name ) {
					throw new Error( 'The source or destination changed while preparing the image. Please upload again.' );
				}
				if ( converted !== file ) {
					var transfer = new DataTransfer();
					transfer.items.add( converted );
					input.files = transfer.files;
					if ( input.files[ 0 ] !== converted ) {
						throw new Error( 'This browser could not replace the selected image. Please convert it first.' );
					}
				}
				readyFile = input.files[ 0 ];
				readyName = name;
				busy = false;
				status.remove();
				status = null;
				// Preserve wpUpload/wpUploadIgnoreWarning and normal form validation.
				if ( submitter ) {
					form.requestSubmit( submitter );
				} else {
					form.requestSubmit();
				}
			} ).catch( function ( error ) {
				if ( attempt !== sequence ) {
					return;
				}
				busy = false;
				readyFile = null;
				showStatus( error.message || 'The image could not be prepared. Please convert it first.', true );
			} );
		}, true );
	}


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
			initUploadConverter();
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
