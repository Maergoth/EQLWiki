#!/usr/bin/env bash
(
set -e

cd "$HOME/public_html"

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$HOME/eqlclientdata-stage-backups/icon-finder-server-cache-$STAMP"
ICON="$BACKUP/MediaWiki_IconFinder.server-cache.js"
LOADER="$BACKUP/MediaWiki_BlueprintLoader.server-cache.js"
PKG_ICON="$HOME/public_html/extensions/EQL-Editor-Tools-Icon-Finder/MediaWiki_IconFinder.js"
PKG_LOADER="$HOME/public_html/extensions/EQL-Editor-Tools-Icon-Finder/MediaWiki_BlueprintLoader.js"

mkdir -p "$BACKUP"

php maintenance/run.php getText \
    "MediaWiki:IconFinder.js" \
    > "$BACKUP/MediaWiki_IconFinder.before.js"

php maintenance/run.php getText \
    "MediaWiki:BlueprintLoader.js" \
    > "$BACKUP/MediaWiki_BlueprintLoader.before.js"

cp "$BACKUP/MediaWiki_IconFinder.before.js" "$ICON"
cp "$BACKUP/MediaWiki_BlueprintLoader.before.js" "$LOADER"

export ICON LOADER

php <<'PHP'
<?php

$iconFile = getenv( 'ICON' );
$loaderFile = getenv( 'LOADER' );

$source = file_get_contents( $iconFile );

if ( $source === false ) {
	fwrite( STDERR, "Could not read IconFinder.js\n" );
	exit( 1 );
}

if ( strpos( $source, 'fetchServerIconIndexMeta' ) === false ) {
	$needle = "\n\tfunction getOrBuildIndex( api, setStatus ) {";
	$pos = strpos( $source, $needle );

	if ( $pos === false ) {
		fwrite( STDERR, "Could not locate getOrBuildIndex().\n" );
		exit( 1 );
	}

	$helpers = <<<'JS'

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
		return api.get( {
			action: 'eqliconindex',
			meta: 1,
			formatversion: 2
		} ).then( function ( data ) {
			return (
				data &&
				data.eqliconindex
			)
				? data.eqliconindex
				: null;
		} );
	}

	function fetchServerIconIndex( api, generation ) {
		return api.get( {
			action: 'eqliconindex',
			generation: generation,
			formatversion: 2
		} ).then( function ( data ) {
			return (
				data &&
				data.eqliconindex
			)
				? data.eqliconindex
				: null;
		} );
	}

	function serverCacheKey( generation ) {
		return [
			'server',
			ALGO_VERSION,
			String( generation || '' )
		].join( ':' );
	}
JS;

	$source =
		substr( $source, 0, $pos ) .
		$helpers .
		substr( $source, $pos );
}

$start = strpos(
	$source,
	"\tfunction getOrBuildIndex( api, setStatus ) {"
);

$end = $start === false
	? false
	: strpos(
		$source,
		"\n\tfunction fingerprintPastedImage( drawable ) {",
		$start
	);

if ( $start === false || $end === false ) {
	fwrite( STDERR, "Could not locate complete getOrBuildIndex() block.\n" );
	exit( 1 );
}

$newFunction = <<<'JS'
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
JS;

$source =
	substr( $source, 0, $start ) .
	$newFunction .
	substr( $source, $end );

file_put_contents( $iconFile, $source );

$loader = file_get_contents( $loaderFile );

if ( $loader === false ) {
	fwrite( STDERR, "Could not read BlueprintLoader.js\n" );
	exit( 1 );
}

/*
 * Bump only the IconFinder script URL. The matching algorithm remains v6,
 * so existing local fingerprints stay usable as a fallback.
 */
if ( preg_match( "/v:\\s*'([0-9]+)'/", $loader, $m ) ) {
	$next = (int)$m[1] + 1;
	$loader = preg_replace(
		"/v:\\s*'[0-9]+'/",
		"v: '" . $next . "'",
		$loader,
		1
	);
	echo "BlueprintLoader cache-buster bumped to v$next.\n";
} else {
	echo "No explicit IconFinder cache-buster found; publishing loader unchanged.\n";
}

file_put_contents( $loaderFile, $loader );

echo "Server-cache client path installed.\n";
PHP

if command -v node >/dev/null 2>&1; then
	node --check "$ICON"
	node --check "$LOADER"
fi

php maintenance/run.php edit \
	-s "Use EQLIconIndex server cache with browser fallback" \
	"MediaWiki:IconFinder.js" \
	< "$ICON"

php maintenance/run.php edit \
	-s "Refresh Icon Finder server-cache client" \
	"MediaWiki:BlueprintLoader.js" \
	< "$LOADER"

if [ -f "$PKG_ICON" ]; then
	cp "$ICON" "$PKG_ICON"
fi

if [ -f "$PKG_LOADER" ]; then
	cp "$LOADER" "$PKG_LOADER"
fi

echo
echo "Client patch published."
echo "Backup: $BACKUP"
echo "No PHP restart or APCu flush is needed."
)
