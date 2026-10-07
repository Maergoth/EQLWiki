/**
 * EQL Wiki - Icon Finder
 *
 * Lazy-loaded from MediaWiki:BlueprintLoader.js.
 *
 * Matching workflow:
 * 1. User pastes a screen capture containing JUST the icon.
 * 2. Read the published catalog of uploaded item and spell icons.
 * 3. Resolve only existing uploaded files through imageinfo.
 * 4. Build compact perceptual fingerprints in the browser.
 * 5. Cache that fingerprint index in IndexedDB keyed to its generation.
 * 6. Rank possible matches by perceptual/pixel similarity.
 *
 * No icon-catalog requests or image downloads happen until the user actually
 * pastes an image into the Icon Finder.
 */
( function ( mw, $ ) {
	'use strict';

	var ICON_LIST_PAGE = 'Icon List';
	var DB_NAME = 'eql-icon-finder';
	var DB_VERSION = 1;
	var STORE_NAME = 'indexes';
	var ALGO_VERSION = 6;
	var COLOR_GRID = 16;
	var IMAGEINFO_BATCH = 50;
	var DOWNLOAD_CONCURRENCY = 8;
	var RESULT_COUNT = 10;

	var initializedHost = null;
	var memoryIndex = null;
	var currentPreviewUrl = null;

	function addStyles() {
		if ( document.getElementById( 'eql-icon-finder-styles' ) ) {
			return;
		}

		$( '<style>', {
			id: 'eql-icon-finder-styles',
			text:
				'.eql-iconfinder-shell{' +
					'display:grid;' +
					'grid-template-columns:minmax(230px,.62fr) minmax(0,1.38fr);' +
					'gap:1rem;' +
					'align-items:start;' +
				'}' +

				'.eql-iconfinder-paste{' +
					'min-height:160px;' +
					'display:flex;' +
					'flex-direction:column;' +
					'align-items:center;' +
					'justify-content:center;' +
					'gap:.45rem;' +
					'padding:1rem;' +
					'background:#080d13;' +
					'border:2px dashed rgba(216,183,92,.45);' +
					'border-radius:8px;' +
					'color:#dfe7ef;' +
					'text-align:center;' +
					'cursor:paste;' +
					'outline:none;' +
				'}' +

				'.eql-iconfinder-paste:focus{' +
					'border-color:#d8b75c;' +
					'box-shadow:0 0 0 2px rgba(216,183,92,.13);' +
				'}' +

				'.eql-iconfinder-instruction{' +
					'color:#fff0b5;' +
					'font-family:Georgia,Times New Roman,serif;' +
					'font-size:1.05rem;' +
					'font-weight:700;' +
				'}' +

				'.eql-iconfinder-subinstruction{' +
					'color:#9faaba;' +
					'font-size:.88rem;' +
					'line-height:1.35;' +
				'}' +

				'.eql-iconfinder-preview{' +
					'display:none;' +
					'width:72px;' +
					'height:72px;' +
					'object-fit:contain;' +
					'image-rendering:auto;' +
					'background:#000;' +
					'border:1px solid rgba(255,255,255,.14);' +
					'border-radius:4px;' +
				'}' +

				'.eql-iconfinder-preview.is-visible{display:block;}' +

				'.eql-iconfinder-status{' +
					'margin-top:.65rem;' +
					'color:#aeb8c7;' +
					'font-size:.86rem;' +
					'line-height:1.4;' +
				'}' +

				'.eql-iconfinder-results-title{' +
					'margin:0 0 .55rem;' +
					'color:#d8b75c;' +
					'font-family:Georgia,Times New Roman,serif;' +
					'font-size:1rem;' +
					'font-weight:700;' +
				'}' +

				'.eql-iconfinder-results-empty{' +
					'padding:.9rem;' +
					'background:rgba(255,255,255,.025);' +
					'border:1px solid rgba(255,255,255,.08);' +
					'border-radius:7px;' +
					'color:#96a2b2;' +
					'font-size:.9rem;' +
				'}' +

				'.eql-iconfinder-results-grid{' +
					'display:grid;' +
					'grid-template-columns:repeat(auto-fill,minmax(185px,1fr));' +
					'gap:.65rem;' +
				'}' +

				'.eql-iconfinder-result{' +
					'display:grid;' +
					'grid-template-columns:54px minmax(0,1fr);' +
					'gap:.65rem;' +
					'align-items:center;' +
					'padding:.65rem;' +
					'background:rgba(255,255,255,.03);' +
					'border:1px solid rgba(255,255,255,.09);' +
					'border-radius:7px;' +
				'}' +

				'.eql-iconfinder-result img{' +
					'width:52px;' +
					'height:52px;' +
					'object-fit:contain;' +
					'background:#000;' +
					'border:1px solid rgba(255,255,255,.1);' +
					'border-radius:4px;' +
				'}' +

				'.eql-iconfinder-result-name{' +
					'display:block;' +
					'margin-bottom:.18rem;' +
					'color:#b9c8ff;' +
					'font-size:.86rem;' +
					'line-height:1.2;' +
					'word-break:break-word;' +
				'}' +

				'.eql-iconfinder-result-score{' +
					'display:block;' +
					'margin-bottom:.28rem;' +
					'color:#d8b75c;' +
					'font-size:.8rem;' +
					'font-variant-numeric:tabular-nums;' +
				'}' +

				'.eql-iconfinder-result-meta{' +
					'display:block;' +
					'color:#8f9bac;' +
					'font-size:.76rem;' +
					'line-height:1.25;' +
				'}' +

				'.eql-iconfinder-result-id{' +
					'display:block;' +
					'margin:.2rem 0 .35rem;' +
					'color:#fff0b5;' +
					'font-size:1.05rem;' +
					'font-weight:800;' +
					'font-variant-numeric:tabular-nums;' +
				'}' +

				'.eql-iconfinder-copy-row{' +
					'display:flex;' +
					'flex-wrap:wrap;' +
					'gap:.3rem;' +
					'margin-top:.38rem;' +
				'}' +

				'.eql-iconfinder-copy{' +
					'min-height:1.8rem!important;' +
					'padding:.15rem .45rem!important;' +
					'font-size:.72rem!important;' +
				'}' +

				'.eql-iconfinder-note{' +
					'margin-top:.7rem;' +
					'color:#7f8b9b;' +
					'font-size:.78rem;' +
					'line-height:1.35;' +
				'}' +

				'@media(max-width:850px){' +
					'.eql-iconfinder-shell{grid-template-columns:1fr;}' +
				'}'
		} ).appendTo( document.head );
	}

	function openDb() {
		return new Promise( function ( resolve, reject ) {
			var request;

			if ( !window.indexedDB ) {
				reject( new Error( 'IndexedDB unavailable.' ) );
				return;
			}

			request = indexedDB.open( DB_NAME, DB_VERSION );

			request.onupgradeneeded = function () {
				var db = request.result;

				if ( !db.objectStoreNames.contains( STORE_NAME ) ) {
					db.createObjectStore( STORE_NAME, {
						keyPath: 'key'
					} );
				}
			};

			request.onsuccess = function () {
				resolve( request.result );
			};

			request.onerror = function () {
				reject( request.error || new Error( 'IndexedDB open failed.' ) );
			};
		} );
	}

	function idbGet( key ) {
		return openDb().then( function ( db ) {
			return new Promise( function ( resolve, reject ) {
				var tx = db.transaction( STORE_NAME, 'readonly' );
				var store = tx.objectStore( STORE_NAME );
				var request = store.get( key );

				request.onsuccess = function () {
					resolve( request.result || null );
				};

				request.onerror = function () {
					reject( request.error || new Error( 'IndexedDB read failed.' ) );
				};

				tx.oncomplete = function () {
					db.close();
				};
			} );
		} );
	}

	function idbPutAndPrune( value ) {
		return openDb().then( function ( db ) {
			return new Promise( function ( resolve, reject ) {
				var tx = db.transaction( STORE_NAME, 'readwrite' );
				var store = tx.objectStore( STORE_NAME );
				var cursorRequest;

				store.put( value );

				cursorRequest = store.openCursor();

				cursorRequest.onsuccess = function () {
					var cursor = cursorRequest.result;

					if ( !cursor ) {
						return;
					}

					if ( cursor.key !== value.key ) {
						cursor.delete();
					}

					cursor.continue();
				};

				tx.oncomplete = function () {
					db.close();
					resolve();
				};

				tx.onerror = function () {
					db.close();
					reject( tx.error || new Error( 'IndexedDB write failed.' ) );
				};
			} );
		} );
	}

	function normalizeFileTitle( title ) {
		title = String( title || '' ).trim();

		if ( !title ) {
			return '';
		}

		return /^File:/i.test( title )
			? title
			: 'File:' + title;
	}

	function fetchCatalog( api ) {
		return api.get( {
			action: 'parse',
			page: ICON_LIST_PAGE,
			prop: 'images|revid',
			formatversion: 2
		} ).then( function ( data ) {
			var parse = data && data.parse ? data.parse : {};
			var titles = Array.isArray( parse.images ) ? parse.images : [];
			var signature = 2166136261;
			function readUploads( prefix, continuation ) {
				return api.get( Object.assign( {
						action: 'query', list: 'allimages', aiprefix: prefix,
					aiprop: 'sha1', ailimit: 500, formatversion: 2
				}, continuation || {} ) ).then( function ( response ) {
					( response.query.allimages || [] ).forEach( function ( image ) {
						if ( !/^(?:Item_[1-9][0-9]*|Spellicon_[A-Za-z0-9]+)\.png$/.test( image.name ) ) {
							return;
						}
						titles.push( image.name );
						var key = image.name + ':' + image.sha1;
						for ( var i = 0; i < key.length; i++ ) {
							signature = Math.imul( signature ^ key.charCodeAt( i ), 16777619 ) >>> 0;
						}
					} );
					return response.continue ? readUploads( prefix, response.continue ) : null;
				} );
			}
			return readUploads( 'Item_' ).then( function () {
				return readUploads( 'Spellicon_' );
			} ).then( function () {

			titles = titles
				.map( normalizeFileTitle )
				.filter( Boolean )
				.filter( function ( title, index, list ) {
					return list.indexOf( title ) === index;
				} );

			return {
				revid: String( parse.revid || 0 ) + ':' + signature,
				titles: titles
			};
			} );
		} );
	}

	function chunk( list, size ) {
		var out = [];
		var i;

		for ( i = 0; i < list.length; i += size ) {
			out.push( list.slice( i, i + size ) );
		}

		return out;
	}

	function fetchImageInfo( api, titles, setStatus ) {
		var batches = chunk( titles, IMAGEINFO_BATCH );
		var records = [];
		var chain = Promise.resolve();

		batches.forEach( function ( batch, batchIndex ) {
			chain = chain.then( function () {
				if ( setStatus ) {
					setStatus(
						'Reading uploaded icon files… ' +
						Math.min( batchIndex * IMAGEINFO_BATCH, titles.length ) +
						' / ' + titles.length
					);
				}

				return api.get( {
					action: 'query',
					prop: 'imageinfo',
					titles: batch.join( '|' ),
					iiprop: 'url|size|sha1',
					formatversion: 2
				} ).then( function ( data ) {
					var pages =
						data &&
						data.query &&
						Array.isArray( data.query.pages )
							? data.query.pages
							: [];

					pages.forEach( function ( page ) {
						var info =
							page &&
							Array.isArray( page.imageinfo ) &&
							page.imageinfo.length
								? page.imageinfo[ 0 ]
								: null;

						if ( !info || !info.url ) {
							return;
						}

						records.push( {
							title: normalizeFileTitle( page.title ),
							url: String( info.url ),
							sha1: String( info.sha1 || '' ),
							width: Number( info.width ) || 0,
							height: Number( info.height ) || 0
						} );
					} );
				} );
			} );
		} );

		return chain.then( function () {
			return records;
		} );
	}

	function blobToDrawable( blob ) {
		if ( window.createImageBitmap ) {
			return createImageBitmap( blob );
		}

		return new Promise( function ( resolve, reject ) {
			var url = URL.createObjectURL( blob );
			var img = new Image();

			img.onload = function () {
				URL.revokeObjectURL( url );
				resolve( img );
			};

			img.onerror = function () {
				URL.revokeObjectURL( url );
				reject( new Error( 'Image decode failed.' ) );
			};

			img.src = url;
		} );
	}

	function fetchDrawable( url ) {
		return fetch( url, {
			credentials: 'same-origin',
			cache: 'force-cache'
		} )
			.then( function ( response ) {
				if ( !response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}

				return response.blob();
			} )
			.then( blobToDrawable );
	}

	function closeDrawable( drawable ) {
		if ( drawable && typeof drawable.close === 'function' ) {
			drawable.close();
		}
	}

	function drawScaled( drawable, width, height, cropFraction ) {
		var sourceCanvas = document.createElement( 'canvas' );
		var sourceWidth =
			Number( drawable.width || drawable.naturalWidth ) || 1;
		var sourceHeight =
			Number( drawable.height || drawable.naturalHeight ) || 1;
		var sourceCtx;
		var sourceImage;
		var sourceData;
		var pixelCount = sourceWidth * sourceHeight;
		var transparentCount = 0;
		var mask = new Uint8Array( pixelCount );
		var foregroundCount = 0;
		var borderR = [];
		var borderG = [];
		var borderB = [];
		var bgR = 0;
		var bgG = 0;
		var bgB = 0;
		var bgLuma = 0;
		var borderSpread = [];
		var tolerance;
		var darkCutoff;
		var minX = sourceWidth;
		var minY = sourceHeight;
		var maxX = -1;
		var maxY = -1;
		var cleanCanvas;
		var cleanCtx;
		var cleanImage;
		var targetCanvas;
		var targetCtx;
		var usableWidth;
		var usableHeight;
		var scale;
		var drawWidth;
		var drawHeight;
		var drawX;
		var drawY;
		var i;
		var p;
		var x;
		var y;
		var r;
		var g;
		var b;
		var a;
		var luma;
		var chroma;
		var maxChannel;
		var minChannel;
		var colorDistance;

		function median( values ) {
			var sorted;

			if ( !values.length ) {
				return 0;
			}

			sorted = values.slice().sort(
				function ( left, right ) {
					return left - right;
				}
			);

			return sorted[
				Math.floor( sorted.length / 2 )
			];
		}

		function addBorderPixel( px, py ) {
			var index =
				( py * sourceWidth + px ) * 4;

			if ( sourceData[ index + 3 ] < 16 ) {
				return;
			}

			borderR.push( sourceData[ index ] );
			borderG.push( sourceData[ index + 1 ] );
			borderB.push( sourceData[ index + 2 ] );
		}

		sourceCanvas.width = sourceWidth;
		sourceCanvas.height = sourceHeight;

		sourceCtx = sourceCanvas.getContext(
			'2d',
			{
				willReadFrequently: true
			}
		);

		/*
		 * Preserve real PNG transparency.
		 */
		sourceCtx.clearRect(
			0,
			0,
			sourceWidth,
			sourceHeight
		);

		sourceCtx.drawImage(
			drawable,
			0,
			0
		);

		sourceImage = sourceCtx.getImageData(
			0,
			0,
			sourceWidth,
			sourceHeight
		);

		sourceData = sourceImage.data;

		for ( i = 0; i < pixelCount; i++ ) {
			if ( sourceData[ i * 4 + 3 ] < 245 ) {
				transparentCount++;
			}
		}

		if (
			transparentCount >
			Math.max( 4, pixelCount * 0.01 )
		) {
			/*
			 * Uploaded transparent PNG:
			 * alpha is already an excellent mask.
			 */
			for ( i = 0; i < pixelCount; i++ ) {
				if (
					sourceData[ i * 4 + 3 ] > 24
				) {
					mask[ i ] = 1;
					foregroundCount++;
				}
			}
		} else {
			/*
			 * Pasted screenshot:
			 *
			 * Infer the UI background from a two-pixel
			 * border around the capture.
			 */
			for (
				x = 0;
				x < sourceWidth;
				x++
			) {
				for (
					y = 0;
					y < Math.min(
						2,
						sourceHeight
					);
					y++
				) {
					addBorderPixel(
						x,
						y
					);

					addBorderPixel(
						x,
						sourceHeight - 1 - y
					);
				}
			}

			for (
				y = 0;
				y < sourceHeight;
				y++
			) {
				for (
					x = 0;
					x < Math.min(
						2,
						sourceWidth
					);
					x++
				) {
					addBorderPixel(
						x,
						y
					);

					addBorderPixel(
						sourceWidth - 1 - x,
						y
					);
				}
			}

			bgR = median( borderR );
			bgG = median( borderG );
			bgB = median( borderB );

			bgLuma = luminance(
				bgR,
				bgG,
				bgB
			);

			for (
				i = 0;
				i < borderR.length;
				i++
			) {
				borderSpread.push(
					Math.sqrt(
						Math.pow(
							borderR[ i ] - bgR,
							2
						) +
						Math.pow(
							borderG[ i ] - bgG,
							2
						) +
						Math.pow(
							borderB[ i ] - bgB,
							2
						)
					)
				);
			}

			/*
			 * The old crop variants blindly trimmed the
			 * image square.
			 *
			 * Now they instead make background removal
			 * slightly more aggressive.
			 */
			tolerance = Math.max(
				20,
				Math.min(
					74,
					18 +
						median(
							borderSpread
						) * 2.5 +
						cropFraction * 80
				)
			);

			darkCutoff = Math.max(
				36,
				Math.min(
					88,
					bgLuma +
						22 +
						cropFraction * 100
				)
			);

			for (
				i = 0;
				i < pixelCount;
				i++
			) {
				p = i * 4;

				r = sourceData[ p ];
				g = sourceData[ p + 1 ];
				b = sourceData[ p + 2 ];
				a = sourceData[ p + 3 ];

				if ( a < 16 ) {
					continue;
				}

				maxChannel = Math.max(
					r,
					g,
					b
				);

				minChannel = Math.min(
					r,
					g,
					b
				);

				chroma =
					maxChannel -
					minChannel;

				luma = luminance(
					r,
					g,
					b
				);

				colorDistance =
					Math.sqrt(
						Math.pow(
							r - bgR,
							2
						) +
						Math.pow(
							g - bgG,
							2
						) +
						Math.pow(
							b - bgB,
							2
						)
					);

				/*
				 * EQ's editor/game icon background is
				 * normally very dark.
				 *
				 * Discard dark neutral pixels, but allow
				 * strongly colored pixels to survive at
				 * lower brightness.
				 */
				if ( bgLuma < 95 ) {
					if (
						(
							luma >=
								darkCutoff &&
							colorDistance >=
								tolerance *
								0.65
						) ||
						(
							chroma >= 24 &&
							luma >=
								bgLuma +
								7 +
								cropFraction *
								30 &&
							colorDistance >=
								tolerance *
								0.55
						)
					) {
						mask[ i ] = 1;
						foregroundCount++;
					}
				} else if (
					colorDistance >=
					tolerance
				) {
					mask[ i ] = 1;
					foregroundCount++;
				}
			}

			/*
			 * Fail soft for an unusual image instead of
			 * producing an empty fingerprint.
			 */
			if (
				foregroundCount < 6 ||
				foregroundCount >
					pixelCount * 0.90
			) {
				mask.fill( 0 );
				foregroundCount = 0;

				for (
					i = 0;
					i < pixelCount;
					i++
				) {
					if (
						sourceData[
							i * 4 + 3
						] > 24
					) {
						mask[ i ] = 1;
						foregroundCount++;
					}
				}
			}
		}

		/*
		 * Find the actual sprite bounds.
		 */
		for (
			y = 0;
			y < sourceHeight;
			y++
		) {
			for (
				x = 0;
				x < sourceWidth;
				x++
			) {
				i =
					y * sourceWidth +
					x;

				if ( !mask[ i ] ) {
					continue;
				}

				minX = Math.min(
					minX,
					x
				);

				minY = Math.min(
					minY,
					y
				);

				maxX = Math.max(
					maxX,
					x
				);

				maxY = Math.max(
					maxY,
					y
				);
			}
		}

		if (
			maxX < minX ||
			maxY < minY
		) {
			minX = 0;
			minY = 0;
			maxX = sourceWidth - 1;
			maxY = sourceHeight - 1;
		}

		/*
		 * Create a transparent copy containing only
		 * detected foreground pixels.
		 */
		cleanCanvas =
			document.createElement(
				'canvas'
			);

		cleanCanvas.width =
			sourceWidth;

		cleanCanvas.height =
			sourceHeight;

		cleanCtx =
			cleanCanvas.getContext(
				'2d',
				{
					willReadFrequently: true
				}
			);

		cleanImage =
			cleanCtx.createImageData(
				sourceWidth,
				sourceHeight
			);

		for (
			i = 0;
			i < pixelCount;
			i++
		) {
			if ( !mask[ i ] ) {
				continue;
			}

			p = i * 4;

			cleanImage.data[ p ] =
				sourceData[ p ];

			cleanImage.data[ p + 1 ] =
				sourceData[ p + 1 ];

			cleanImage.data[ p + 2 ] =
				sourceData[ p + 2 ];

			cleanImage.data[ p + 3 ] =
				255;
		}

		cleanCtx.putImageData(
			cleanImage,
			0,
			0
		);

		/*
		 * Normalize the detected sprite to the requested
		 * comparison size.
		 */
		targetCanvas =
			document.createElement(
				'canvas'
			);

		targetCanvas.width = width;
		targetCanvas.height = height;

		targetCtx =
			targetCanvas.getContext(
				'2d',
				{
					willReadFrequently: true
				}
			);

		targetCtx.fillStyle = '#000';

		targetCtx.fillRect(
			0,
			0,
			width,
			height
		);

		targetCtx.imageSmoothingEnabled =
			true;

		if (
			'imageSmoothingQuality' in
			targetCtx
		) {
			targetCtx.imageSmoothingQuality =
				'high';
		}

		usableWidth =
			Math.max(
				1,
				width - 2
			);

		usableHeight =
			Math.max(
				1,
				height - 2
			);

		scale = Math.min(
			usableWidth /
				Math.max(
					1,
					maxX - minX + 1
				),
			usableHeight /
				Math.max(
					1,
					maxY - minY + 1
				)
		);

		drawWidth = Math.max(
			1,
			Math.round(
				(
					maxX -
					minX +
					1
				) * scale
			)
		);

		drawHeight = Math.max(
			1,
			Math.round(
				(
					maxY -
					minY +
					1
				) * scale
			)
		);

		drawX = Math.floor(
			(
				width -
				drawWidth
			) / 2
		);

		drawY = Math.floor(
			(
				height -
				drawHeight
			) / 2
		);

		targetCtx.drawImage(
			cleanCanvas,
			minX,
			minY,
			maxX - minX + 1,
			maxY - minY + 1,
			drawX,
			drawY,
			drawWidth,
			drawHeight
		);

		return targetCtx.getImageData(
			0,
			0,
			width,
			height
		);
	}
	function luminance( r, g, b ) {
		return 0.2126 * r + 0.7152 * g + 0.0722 * b;
	}

	function computeDHash( drawable, cropFraction ) {
		var imageData = drawScaled( drawable, 9, 8, cropFraction );
		var data = imageData.data;
		var hi = 0;
		var lo = 0;
		var bit = 0;
		var y;
		var x;
		var left;
		var right;
		var li;
		var ri;

		for ( y = 0; y < 8; y++ ) {
			for ( x = 0; x < 8; x++ ) {
				li = ( y * 9 + x ) * 4;
				ri = ( y * 9 + x + 1 ) * 4;

				left = luminance(
					data[ li ],
					data[ li + 1 ],
					data[ li + 2 ]
				);

				right = luminance(
					data[ ri ],
					data[ ri + 1 ],
					data[ ri + 2 ]
				);

				if ( left > right ) {
					if ( bit < 32 ) {
						lo = ( lo | ( 1 << bit ) ) >>> 0;
					} else {
						hi = ( hi | ( 1 << ( bit - 32 ) ) ) >>> 0;
					}
				}

				bit++;
			}
		}

		return {
			hi: hi >>> 0,
			lo: lo >>> 0
		};
	}

	function computeColorGrid( drawable, cropFraction ) {
		var imageData = drawScaled(
			drawable,
			COLOR_GRID,
			COLOR_GRID,
			cropFraction
		);
		var data = imageData.data;
		var out = new Uint8Array( COLOR_GRID * COLOR_GRID * 3 );
		var source = 0;
		var target = 0;

		while ( source < data.length ) {
			out[ target++ ] = data[ source ];
			out[ target++ ] = data[ source + 1 ];
			out[ target++ ] = data[ source + 2 ];
			source += 4;
		}

		return out;
	}

	function fingerprintDrawable( drawable, cropFraction ) {
		var hash = computeDHash( drawable, cropFraction );

		return {
			hashHi: hash.hi,
			hashLo: hash.lo,
			rgb: computeColorGrid( drawable, cropFraction )
		};
	}

	function popcount32( value ) {
		value = value >>> 0;
		value = value - ( ( value >>> 1 ) & 0x55555555 );
		value = ( value & 0x33333333 ) + ( ( value >>> 2 ) & 0x33333333 );
		return (
			(
				(
					value + ( value >>> 4 )
				) & 0x0F0F0F0F
			) * 0x01010101
		) >>> 24;
	}

	function fingerprintDistance( a, b ) {
		var hashBits =
			popcount32(
				(
					a.hashHi ^
					b.hashHi
				) >>> 0
			) +
			popcount32(
				(
					a.hashLo ^
					b.hashLo
				) >>> 0
			);

		var hashDistance =
			hashBits / 64;

		var length = Math.min(
			a.rgb.length,
			b.rgb.length
		);

		var histogramA =
			new Uint16Array( 64 );

		var histogramB =
			new Uint16Array( 64 );

		var foregroundA = 0;
		var foregroundB = 0;
		var intersection = 0;
		var union = 0;
		var colorSum = 0;
		var i;
		var ar;
		var ag;
		var ab;
		var br;
		var bg;
		var bb;
		var aForeground;
		var bForeground;
		var dr;
		var dg;
		var db;
		var binA;
		var binB;
		var shapeDistance;
		var colorDistance;
		var histogramDistance = 0;

		for (
			i = 0;
			i + 2 < length;
			i += 3
		) {
			ar = a.rgb[ i ];
			ag = a.rgb[ i + 1 ];
			ab = a.rgb[ i + 2 ];

			br = b.rgb[ i ];
			bg = b.rgb[ i + 1 ];
			bb = b.rgb[ i + 2 ];

			/*
			 * Background is normalized to black by
			 * drawScaled().
			 */
			aForeground =
				Math.max(
					ar,
					ag,
					ab
				) > 8;

			bForeground =
				Math.max(
					br,
					bg,
					bb
				) > 8;

			if ( aForeground ) {
				foregroundA++;

				binA =
					(
						Math.min(
							3,
							ar >> 6
						) << 4
					) |
					(
						Math.min(
							3,
							ag >> 6
						) << 2
					) |
					Math.min(
						3,
						ab >> 6
					);

				histogramA[ binA ]++;
			}

			if ( bForeground ) {
				foregroundB++;

				binB =
					(
						Math.min(
							3,
							br >> 6
						) << 4
					) |
					(
						Math.min(
							3,
							bg >> 6
						) << 2
					) |
					Math.min(
						3,
						bb >> 6
					);

				histogramB[ binB ]++;
			}

			if (
				aForeground ||
				bForeground
			) {
				union++;
			}

			if (
				!aForeground ||
				!bForeground
			) {
				continue;
			}

			intersection++;

			dr = ar - br;
			dg = ag - bg;
			db = ab - bb;

			colorSum +=
				Math.sqrt(
					dr * dr +
					dg * dg +
					db * db
				) /
				441.67295593;
		}

		shapeDistance =
			union
				? 1 -
					intersection /
						union
				: 1;

		colorDistance =
			intersection
				? colorSum /
					intersection
				: 1;

		for (
			i = 0;
			i < 64;
			i++
		) {
			histogramDistance +=
				Math.abs(
					histogramA[ i ] /
						Math.max(
							1,
							foregroundA
						) -
					histogramB[ i ] /
						Math.max(
							1,
							foregroundB
						)
				);
		}

		/*
		 * Normalized histogram L1 range is 0..2.
		 */
		histogramDistance =
			Math.min(
				1,
				histogramDistance / 2
			);

		/*
		 * Foreground color is deliberately the largest
		 * contributor now.
		 */
		return Math.min(
			1,
			0.50 * colorDistance +
				0.25 * shapeDistance +
				0.15 * histogramDistance +
				0.10 * hashDistance
		);
	}
	function mapLimit( list, limit, worker ) {
		var nextIndex = 0;
		var workers = [];
		var i;

		function runWorker() {
			var index;

			if ( nextIndex >= list.length ) {
				return Promise.resolve();
			}

			index = nextIndex++;
			return Promise.resolve( worker( list[ index ], index ) )
				.then( runWorker );
		}

		for ( i = 0; i < Math.min( limit, list.length ); i++ ) {
			workers.push( runWorker() );
		}

		return Promise.all( workers );
	}

	function buildFingerprintIndex( records, setStatus ) {
		var complete = 0;
		var successful = [];
		var grouped = Object.create( null );
		var groups = [];

		/*
		 * If several icon filenames are byte-identical, imageinfo gives them
		 * the same SHA-1. Fingerprint that image only once and fan the result
		 * back out to every filename. This can substantially reduce the first
		 * build's static-file traffic without changing the visible results.
		 */
		records.forEach( function ( record ) {
			var key = record.sha1 || record.url;

			if ( !grouped[ key ] ) {
				grouped[ key ] = {
					key: key,
					representative: record,
					records: []
				};
				groups.push( grouped[ key ] );
			}

			grouped[ key ].records.push( record );
		} );

		return mapLimit(
			groups,
			DOWNLOAD_CONCURRENCY,
			function ( group ) {
				return fetchDrawable( group.representative.url )
					.then( function ( drawable ) {
						var fp = fingerprintDrawable( drawable, 0 );

						closeDrawable( drawable );

						group.records.forEach( function ( record ) {
							successful.push( {
								title: record.title,
								url: record.url,
								sha1: record.sha1,
								width: record.width,
								height: record.height,
								hashHi: fp.hashHi,
								hashLo: fp.hashLo,
								rgb: fp.rgb
							} );
						} );
					} )
					.catch( function () {
						/*
						 * A missing/corrupt/non-raster file should not prevent
						 * the rest of the icon index from becoming usable.
						 */
					} )
					.then( function () {
						complete++;

						if (
							setStatus &&
							(
								complete === groups.length ||
								complete % 25 === 0
							)
						) {
							setStatus(
								'Building pixel index… ' +
									complete + ' / ' + groups.length +
									' unique images (' +
									records.length + ' filenames)'
							);
						}
					} );
			}
		).then( function () {
			return successful;
		} );
	}

	function cacheKey( revid ) {
		return [
			'icon-list',
			ALGO_VERSION,
			revid
		].join( ':' );
	}

	function decodeServerRgb( base64 ) {
		var binary = window.atob( String( base64 || '' ) );
		var bytes = new Uint8Array( binary.length );
		var i;

		for ( i = 0; i < binary.length; i++ ) {
			bytes[ i ] = binary.charCodeAt( i ) & 0xFF;
		}

		return bytes;
	}

	function decodeServerRecords( records ) {
		return ( Array.isArray( records ) ? records : [] )
			.map( function ( record ) {
				return {
					title: String( record.title || '' ),
					url: String( record.url || '' ),
					sha1: String( record.sha1 || '' ),
					aliases: Array.isArray( record.aliases ) ? record.aliases : [],
					width: Number( record.width ) || 0,
					height: Number( record.height ) || 0,
					hashHi: Number( record.hashHi ) >>> 0,
					hashLo: Number( record.hashLo ) >>> 0,
					rgb: decodeServerRgb( record.rgb )
				};
			} )
			.filter( function ( record ) {
				return (
					record.title &&
					record.url &&
					record.rgb.length
				);
			} );
	}

	function fetchServerIconIndexMeta( api ) {
		return window.fetch(
			'/static/eql-icon-index/meta.json?ts=' + Date.now(),
			{
				credentials: 'same-origin',
				cache: 'no-store'
			}
		).then( function ( response ) {
			if ( !response.ok ) {
				return null;
			}

			return response.json();
		} ).catch( function () {
			return null;
		} );
	}

	function fetchServerIconIndex( api, generation ) {
		return window.fetch(
			'/static/eql-icon-index/index-' +
				encodeURIComponent( String( generation || '' ) ) +
				'.json',
			{
				credentials: 'same-origin',
				cache: 'force-cache'
			}
		).then( function ( response ) {
			if ( !response.ok ) {
				return null;
			}

			return response.json();
		} ).catch( function () {
			return null;
		} );
	}
	function serverCacheKey( generation ) {
		return [
			'server',
			ALGO_VERSION,
			String( generation || '' )
		].join( ':' );
	}
	function getOrBuildIndex( api, setStatus ) {
		if ( memoryIndex ) {
			return Promise.resolve( memoryIndex );
		}

		setStatus( 'Checking server icon index…' );

		/*
		 * Preferred path:
		 * 1. Ask for tiny server metadata.
		 * 2. Reuse IndexedDB if this immutable generation is already local.
		 * 3. Otherwise download the precomputed server fingerprints once.
		 *
		 * Any server-cache error falls through to the original fully
		 * client-side builder, preserving continuity.
		 */
		return fetchServerIconIndexMeta( api )
			.catch( function () {
				return null;
			} )
			.then( function ( meta ) {
				var key;

				if (
					!meta ||
					!meta.ready ||
					Number( meta.algorithm ) !== ALGO_VERSION ||
					!meta.generation
				) {
					return null;
				}

				key = serverCacheKey( meta.generation );

				return idbGet( key )
					.catch( function () {
						return null;
					} )
					.then( function ( cached ) {
						if (
							cached &&
							Array.isArray( cached.records ) &&
							cached.records.length
						) {
							memoryIndex = cached.records;

							setStatus(
								'Icon index ready: ' +
									memoryIndex.length +
									' icons (server generation cached locally).'
							);

							return memoryIndex;
						}

						setStatus(
							'Downloading precomputed server icon index…'
						);

						return fetchServerIconIndex(
							api,
							String( meta.generation )
						).then( function ( payload ) {
							var records;

							if (
								!payload ||
								!payload.ready ||
								String( payload.generation || '' ) !==
									String( meta.generation )
							) {
								return null;
							}

							records = decodeServerRecords(
								payload.records
							);

							if ( !records.length ) {
								return null;
							}

							memoryIndex = records;

							setStatus(
								'Icon index ready: ' +
									records.length +
									' icons (server cache).'
							);

							return idbPutAndPrune( {
								key: key,
								generation: String( meta.generation ),
								revid: Number( meta.iconListRevision ) || 0,
								algorithm: ALGO_VERSION,
								builtAt: Date.now(),
								records: records
							} )
								.catch( function () {
									/* Browser storage is optional. */
								} )
								.then( function () {
									return records;
								} );
						} );
					} );
			} )
			.then( function ( serverRecords ) {
				if ( serverRecords && serverRecords.length ) {
					return serverRecords;
				}

				/*
				 * Backwards-compatible fallback. If the extension is disabled,
				 * the job queue has not run yet, GD is unavailable, or the
				 * server cache is stale, the working browser implementation
				 * behaves exactly as it did before.
				 */
				setStatus(
					'Server icon index unavailable; checking browser cache…'
				);

				return fetchCatalog( api ).then( function ( catalog ) {
					var key = cacheKey( catalog.revid );

					return idbGet( key )
						.catch( function () {
							return null;
						} )
						.then( function ( cached ) {
							if (
								cached &&
								Array.isArray( cached.records ) &&
								cached.records.length
							) {
								memoryIndex = cached.records;
								setStatus(
									'Icon index ready: ' +
										memoryIndex.length +
										' uploaded icons (cached locally).'
								);
								return memoryIndex;
							}

							return fetchImageInfo(
								api,
								catalog.titles,
								setStatus
							).then( function ( imageRecords ) {
								setStatus(
									'Found ' + imageRecords.length +
										' uploaded icons. Building pixel index…'
								);

								return buildFingerprintIndex(
									imageRecords,
									setStatus
								);
							} ).then( function ( records ) {
								memoryIndex = records;

								setStatus(
									'Icon index ready: ' +
										records.length +
										' uploaded icons.'
								);

								return idbPutAndPrune( {
									key: key,
									revid: catalog.revid,
									algorithm: ALGO_VERSION,
									builtAt: Date.now(),
									records: records
								} )
									.catch( function () {
										/* Browser storage is optional. */
									} )
									.then( function () {
										return records;
									} );
							} );
						} );
				} );
			} );
	}
	function fingerprintPastedImage( drawable ) {
		/*
		 * Small centered crop variants make the matcher forgiving of a
		 * 1-2 pixel screen-capture border while still preferring an exact crop.
		 */
		return [
			fingerprintDrawable( drawable, 0 ),
			fingerprintDrawable( drawable, 0.035 ),
			fingerprintDrawable( drawable, 0.07 )
		];
	}

	function rankMatches( targetFingerprints, records ) {
		var ranked = records.map( function ( record ) {
			var candidate = {
				hashHi: record.hashHi,
				hashLo: record.hashLo,
				rgb: record.rgb
			};

			var distance = Infinity;

			targetFingerprints.forEach( function ( target ) {
				distance = Math.min(
					distance,
					fingerprintDistance( target, candidate )
				);
			} );

			return {
				record: record,
				distance: distance,
				similarity: Math.max(
					0,
					Math.min( 100, ( 1 - distance ) * 100 )
				)
			};
		} );

		ranked.sort( function ( a, b ) {
			return a.distance - b.distance;
		} );

		return ranked.slice( 0, RESULT_COUNT );
	}

	function cleanDisplayTitle( title ) {
		return String( title || '' ).replace( /^File:/i, '' );
	}

	function iconMetadata( title ) {
		var clean = cleanDisplayTitle( title );
		var itemMatch = clean.match( /^Item[\s_]+(\d+)\.png$/i );
		var spellMatch = clean.match( /^Spellicon[\s_]+([^.]*)\.png$/i );
		var id = '';
		var kind = 'Icon file';
		var parameter = '';

		if ( itemMatch ) {
			id = itemMatch[ 1 ];
			kind = 'Item icon';
			parameter = '|lucy_img_ID = ' + id;
		} else if ( spellMatch ) {
			id = spellMatch[ 1 ];
			kind = 'Spell icon';
			parameter = '|spellicon = ' + id;
		}

		return {
			kind: kind,
			id: id,
			filename: clean,
			parameter: parameter,
			rawWiki: '[[File:' + clean + ']]',
			staticWiki: '[[File:' + clean + '|32x32px]]',
			scaledWiki: '[[File:' + clean + '|frameless|upright=1.5]]'
		};
	}

	function copyText( text, button ) {
		var original = button.textContent;

		function done() {
			button.textContent = 'Copied';
			window.setTimeout( function () {
				button.textContent = original;
			}, 1000 );
		}

		if (
			navigator.clipboard &&
			typeof navigator.clipboard.writeText === 'function'
		) {
			navigator.clipboard.writeText( text )
				.then( done )
				.catch( function () {
					window.prompt( 'Copy:', text );
				} );
			return;
		}

		window.prompt( 'Copy:', text );
	}

	function makeCopyButton( label, value ) {
		var button = document.createElement( 'button' );

		button.className = 'eql-iconfinder-copy';
		button.type = 'button';
		button.textContent = label;

		button.addEventListener( 'click', function () {
			copyText( value, button );
		} );

		return button;
	}

	function renderMatches( container, matches ) {
		container.innerHTML = '';


		var grid = document.createElement( 'div' );
		grid.className = 'eql-iconfinder-results-grid';
		container.appendChild( grid );

		matches.forEach( function ( match ) {
			var record = match.record;
			var meta = iconMetadata( record.title );
			var card = document.createElement( 'div' );
			var img = document.createElement( 'img' );
			var textWrap = document.createElement( 'div' );
			var link = document.createElement( 'a' );
			var score = document.createElement( 'span' );
			var iconId = document.createElement( 'span' );
			var metadata = document.createElement( 'span' );
			var copyRow = document.createElement( 'div' );

			card.className = 'eql-iconfinder-result';

			img.src = record.url;
			img.alt = meta.filename;
			img.loading = 'lazy';

			link.className = 'eql-iconfinder-result-name';
			link.href = mw.util.getUrl( record.title );
			link.target = '_blank';
			link.rel = 'noopener';
			link.textContent = meta.filename;

			score.className = 'eql-iconfinder-result-score';
			score.textContent =
				'Match score ' + match.similarity.toFixed( 1 );

			iconId.className = 'eql-iconfinder-result-id';
			iconId.textContent = meta.id
				? 'Icon ' + meta.id
				: meta.filename;

			metadata.className = 'eql-iconfinder-result-meta';
			metadata.textContent = meta.kind;

			copyRow.className = 'eql-iconfinder-copy-row';

			if ( meta.id ) {
				copyRow.appendChild(
					makeCopyButton( 'Copy ID', meta.id )
				);
			}

			if ( meta.parameter ) {
				copyRow.appendChild(
					makeCopyButton( 'Copy Parameter', meta.parameter )
				);
			}

			copyRow.appendChild(
				makeCopyButton( 'Raw Wikicode', meta.rawWiki )
			);

			copyRow.appendChild(
				makeCopyButton( '32×32', meta.staticWiki )
			);

			copyRow.appendChild(
				makeCopyButton( 'Scaled', meta.scaledWiki )
			);

			textWrap.appendChild( link );
			textWrap.appendChild( iconId );
			textWrap.appendChild( score );
			textWrap.appendChild( metadata );
			textWrap.appendChild( copyRow );

			if ( record.aliases && record.aliases.length > 1 ) {
				var choices = document.createElement( 'select' );
				choices.setAttribute( 'aria-label', 'Filename for matching icon' );
				choices.style.maxWidth = '100%';
				record.aliases.forEach( function ( alias, index ) {
					var option = document.createElement( 'option' );
					option.value = String( index );
					option.textContent = cleanDisplayTitle( alias.title );
					choices.appendChild( option );
				} );
				choices.addEventListener( 'change', function () {
					var alias = record.aliases[ Number( choices.value ) ];
					var selected = iconMetadata( alias.title );
					link.href = mw.util.getUrl( alias.title );
					link.textContent = selected.filename;
					iconId.textContent = selected.id ? 'Icon ' + selected.id : selected.filename;
					img.src = alias.url;
					img.alt = selected.filename;
					copyRow.innerHTML = '';
					if ( selected.id ) {
						copyRow.appendChild( makeCopyButton( 'Copy ID', selected.id ) );
					}
					if ( selected.parameter ) {
						copyRow.appendChild( makeCopyButton( 'Copy Parameter', selected.parameter ) );
					}
					copyRow.appendChild( makeCopyButton( 'Raw Wikicode', selected.rawWiki ) );
					copyRow.appendChild( makeCopyButton( '32×32', selected.staticWiki ) );
					copyRow.appendChild( makeCopyButton( 'Scaled', selected.scaledWiki ) );
				} );
				textWrap.appendChild( choices );
				var aliasNote = document.createElement( 'span' );
				aliasNote.className = 'eql-iconfinder-result-meta';
				aliasNote.textContent = record.aliases.length + ' filenames share this artwork';
				textWrap.appendChild( aliasNote );
			}

			card.appendChild( img );
			card.appendChild( textWrap );
			grid.appendChild( card );
		} );

		var note = document.createElement( 'div' );
		note.className = 'eql-iconfinder-note';
		note.textContent =
			'The tool only identifies likely matches and copies values/wikicode to the clipboard; it never changes the editor. ' +
			'For the best result, capture only the square icon with as little surrounding UI as possible.';
		container.appendChild( note );
	}

	function imageFromClipboardEvent( event ) {
		var clipboard = event.clipboardData;
		var items;
		var i;

		if ( !clipboard || !clipboard.items ) {
			return null;
		}

		items = clipboard.items;

		for ( i = 0; i < items.length; i++ ) {
			if (
				items[ i ].kind === 'file' &&
				/^image\//i.test( items[ i ].type )
			) {
				return items[ i ].getAsFile();
			}
		}

		return null;
	}

	function setPreview( img, blob ) {
		if ( currentPreviewUrl ) {
			URL.revokeObjectURL( currentPreviewUrl );
		}

		currentPreviewUrl = URL.createObjectURL( blob );
		img.src = currentPreviewUrl;
		img.classList.add( 'is-visible' );
	}

	function processBlob( blob, preview, status, results, api ) {
		var setStatus = function ( text ) {
			status.textContent = text;
		};

		setPreview( preview, blob );
		results.innerHTML =
			'<div class="eql-iconfinder-results-empty">' +
				'Analyzing pasted icon…' +
			'</div>';

		return blobToDrawable( blob )
			.then( function ( drawable ) {
				var targets = fingerprintPastedImage( drawable );
				closeDrawable( drawable );

				return getOrBuildIndex( api, setStatus )
					.then( function ( index ) {
						var matches;

						if ( !index.length ) {
							throw new Error( 'No uploaded icons were found.' );
						}

						setStatus(
							'Comparing against ' +
								index.length +
								' uploaded icons…'
						);

						/*
						 * Yield once so the status message can paint before
						 * ranking several thousand in-memory fingerprints.
						 */
						return new Promise( function ( resolve ) {
							window.setTimeout( function () {
								matches = rankMatches( targets, index );
								resolve( matches );
							}, 0 );
						} );
					} );
			} )
			.then( function ( matches ) {
				renderMatches( results, matches );

				setStatus(
					'Done. Showing the ' +
						matches.length +
						' closest matches.'
				);
			} )
			.catch( function ( error ) {
				if ( window.console && console.error ) {
					console.error( 'EQL Icon Finder:', error );
				}

				results.innerHTML =
					'<div class="eql-iconfinder-results-empty">' +
						'The icon could not be matched.' +
					'</div>';

				setStatus(
					'Icon Finder error: ' +
						( error && error.message ? error.message : 'Unknown error' )
				);
			} );
	}

	function buildUi( host ) {
		var api = new mw.Api();

		host.innerHTML = '';

		var shell = document.createElement( 'div' );
		shell.className = 'eql-iconfinder-shell';

		var left = document.createElement( 'div' );
		var paste = document.createElement( 'div' );
		var instruction = document.createElement( 'div' );
		var subinstruction = document.createElement( 'div' );
		var preview = document.createElement( 'img' );
		var status = document.createElement( 'div' );

		paste.className = 'eql-iconfinder-paste';
		paste.tabIndex = 0;
		paste.setAttribute( 'role', 'button' );
		paste.setAttribute(
			'aria-label',
			'Paste a screen capture of an EverQuest icon'
		);

		instruction.className = 'eql-iconfinder-instruction';
		instruction.textContent =
			'Screen capture and paste JUST the Icon';

		subinstruction.className = 'eql-iconfinder-subinstruction';
		subinstruction.textContent =
			'Click this box, then press Ctrl+V. You can also drag an image here.';

		preview.className = 'eql-iconfinder-preview';
		preview.alt = 'Pasted icon preview';

		status.className = 'eql-iconfinder-status';
		status.setAttribute( 'aria-live', 'polite' );
		status.textContent =
			'The icon library loads when you paste an image.';

		paste.appendChild( instruction );
		paste.appendChild( subinstruction );
		paste.appendChild( preview );

		left.appendChild( paste );
		left.appendChild( status );

		var right = document.createElement( 'div' );
		var resultsTitle = document.createElement( 'div' );
		var results = document.createElement( 'div' );

		resultsTitle.className = 'eql-iconfinder-results-title';
		resultsTitle.textContent = 'Possible Matches';

		results.innerHTML =
			'<div class="eql-iconfinder-results-empty">' +
				'Paste an icon to search the uploaded item and spell library.' +
			'</div>';

		right.appendChild( resultsTitle );
		right.appendChild( results );

		shell.appendChild( left );
		shell.appendChild( right );
		host.appendChild( shell );

		paste.addEventListener( 'click', function () {
			paste.focus();
		} );

		paste.addEventListener( 'paste', function ( event ) {
			var file = imageFromClipboardEvent( event );

			if ( !file ) {
				status.textContent =
					'Clipboard does not contain an image. Screen capture the icon, then paste again.';
				return;
			}

			event.preventDefault();

			processBlob(
				file,
				preview,
				status,
				results,
				api
			);
		} );

		paste.addEventListener( 'dragover', function ( event ) {
			event.preventDefault();
		} );

		paste.addEventListener( 'drop', function ( event ) {
			var files;

			event.preventDefault();
			files = event.dataTransfer && event.dataTransfer.files;

			if (
				!files ||
				!files.length ||
				!/^image\//i.test( files[ 0 ].type )
			) {
				status.textContent = 'Drop an image file here.';
				return;
			}

			processBlob(
				files[ 0 ],
				preview,
				status,
				results,
				api
			);
		} );

		/*
		 * Focus the paste target immediately when the panel first opens so
		 * the normal workflow is: click Icon Finder -> Ctrl+V.
		 */
		window.setTimeout( function () {
			paste.focus();
		}, 0 );
	}

	window.EQLIconFinder = {
		init: function ( host ) {
			if ( !host ) {
				return Promise.reject(
					new Error( 'Icon Finder host element is missing.' )
				);
			}

			addStyles();

			if ( initializedHost !== host ) {
				buildUi( host );
				initializedHost = host;
			} else {
				var paste = host.querySelector( '.eql-iconfinder-paste' );

				if ( paste ) {
					window.setTimeout( function () {
						paste.focus();
					}, 0 );
				}
			}

			return Promise.resolve();
		}
	};

}( mediaWiki, jQuery ) );
