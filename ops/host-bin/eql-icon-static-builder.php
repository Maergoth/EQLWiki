<?php
use MediaWiki\Maintenance\Maintenance;
require_once __DIR__ . '/eql-icon-catalog.php';

class EQLIconStaticBuilder extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription(
            'Build the static EQL Icon Finder fingerprint cache.'
        );
    }

    public function execute() {
        /*
         * EQL Icon Static Cache Builder
         *
         * Executed through:
         *   php maintenance/run.php eval < /home/eqlwikdq/bin/eql-icon-static-builder.eval.php
         *
         * It runs only from CLI. It does not register hooks, API modules, jobs, or
         * web-request callbacks.
         */
        
        $algorithm = 6;
        $iconListPage = 'Icon List';
        
        $privateDir = getenv( 'EQL_ICON_PRIVATE_DIR' ) ?: '/home/eqlwikdq/private-cache/mediawiki/eql-icon-static';
        $publicDir = getenv( 'EQL_ICON_PUBLIC_DIR' ) ?: '/home/eqlwikdq/public_html/static/eql-icon-index';
        
        $stateFile = $privateDir . '/state.json';
        $publicMetaFile = $publicDir . '/meta.json';
        
        @mkdir( $privateDir, 0755, true );
        @mkdir( $publicDir, 0755, true );
        
        $atomicWrite = static function ( string $path, string $data ): void {
        	$tmp = $path . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
        
        	if ( file_put_contents( $tmp, $data, LOCK_EX ) === false ) {
        		throw new RuntimeException( 'Could not write temporary file: ' . $tmp );
        	}
        
        	@chmod( $tmp, 0644 );
        
        	if ( !@rename( $tmp, $path ) ) {
        		@unlink( $tmp );
        		throw new RuntimeException( 'Could not atomically replace: ' . $path );
        	}
        };
        
        $readJson = static function ( string $path ): ?array {
        	if ( !is_file( $path ) ) {
        		return null;
        	}
        
        	$raw = @file_get_contents( $path );
        	if ( $raw === false || $raw === '' ) {
        		return null;
        	}
        
        	$data = json_decode( $raw, true );
        	return is_array( $data ) ? $data : null;
        };
        
        $luminance = static function ( int $color ): float {
        	$r = ( $color >> 16 ) & 0xFF;
        	$g = ( $color >> 8 ) & 0xFF;
        	$b = $color & 0xFF;
        	return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };
        
        $foregroundBounds = static function ( $src, int $width, int $height ): array {
        	$minX = $width;
        	$minY = $height;
        	$maxX = -1;
        	$maxY = -1;
        	$found = false;
        
        	for ( $y = 0; $y < $height; $y++ ) {
        		for ( $x = 0; $x < $width; $x++ ) {
        			$c = imagecolorat( $src, $x, $y );
        			$alpha = ( $c >> 24 ) & 0x7F;
        
        			/*
        			 * JS candidate fingerprints treat pixels above roughly 10%
        			 * opacity as foreground. GD alpha runs 0=opaque .. 127=clear.
        			 */
        			if ( $alpha >= 115 ) {
        				continue;
        			}
        
        			$found = true;
        			$minX = min( $minX, $x );
        			$minY = min( $minY, $y );
        			$maxX = max( $maxX, $x );
        			$maxY = max( $maxY, $y );
        		}
        	}
        
        	return $found
        		? [ $minX, $minY, $maxX, $maxY ]
        		: [ 0, 0, $width - 1, $height - 1 ];
        };
        
        $renderNormalized = static function (
        	$src,
        	array $bounds,
        	int $targetWidth,
        	int $targetHeight
        ) {
        	[ $minX, $minY, $maxX, $maxY ] = $bounds;
        
        	$sourceWidth = max( 1, $maxX - $minX + 1 );
        	$sourceHeight = max( 1, $maxY - $minY + 1 );
        
        	$target = imagecreatetruecolor( $targetWidth, $targetHeight );
        
        	imagealphablending( $target, true );
        	$black = imagecolorallocate( $target, 0, 0, 0 );
        	imagefill( $target, 0, 0, $black );
        
        	$usableWidth = max( 1, $targetWidth - 2 );
        	$usableHeight = max( 1, $targetHeight - 2 );
        
        	$scale = min(
        		$usableWidth / $sourceWidth,
        		$usableHeight / $sourceHeight
        	);
        
        	$drawWidth = max( 1, (int)round( $sourceWidth * $scale ) );
        	$drawHeight = max( 1, (int)round( $sourceHeight * $scale ) );
        
        	$drawX = (int)floor( ( $targetWidth - $drawWidth ) / 2 );
        	$drawY = (int)floor( ( $targetHeight - $drawHeight ) / 2 );
        
        	imagecopyresampled(
        		$target,
        		$src,
        		$drawX,
        		$drawY,
        		$minX,
        		$minY,
        		$drawWidth,
        		$drawHeight,
        		$sourceWidth,
        		$sourceHeight
        	);
        
        	return $target;
        };
        
        $fingerprintFile = static function (
        	string $path
        ) use (
        	$foregroundBounds,
        	$renderNormalized,
        	$luminance
        ): ?array {
        	$raw = @file_get_contents( $path );
        	if ( $raw === false ) {
        		return null;
        	}
        
        	$src = @imagecreatefromstring( $raw );
        	if ( !$src ) {
        		return null;
        	}
        
        	if (
        		function_exists( 'imagepalettetotruecolor' ) &&
        		!imageistruecolor( $src )
        	) {
        		@imagepalettetotruecolor( $src );
        	}
        
        	$width = imagesx( $src );
        	$height = imagesy( $src );
        
        	if ( $width < 1 || $height < 1 || $width > 512 || $height > 512 ) {
        		imagedestroy( $src );
        		return null;
        	}
        
        	$bounds = $foregroundBounds( $src, $width, $height );
        
        	$rgbImage = $renderNormalized( $src, $bounds, 16, 16 );
        	$hashImage = $renderNormalized( $src, $bounds, 9, 8 );
        
        	$rgb = '';
        
        	for ( $y = 0; $y < 16; $y++ ) {
        		for ( $x = 0; $x < 16; $x++ ) {
        			$c = imagecolorat( $rgbImage, $x, $y );
        
        			$rgb .= chr( ( $c >> 16 ) & 0xFF );
        			$rgb .= chr( ( $c >> 8 ) & 0xFF );
        			$rgb .= chr( $c & 0xFF );
        		}
        	}
        
        	$hi = 0;
        	$lo = 0;
        	$bit = 0;
        
        	for ( $y = 0; $y < 8; $y++ ) {
        		for ( $x = 0; $x < 8; $x++ ) {
        			$left = $luminance(
        				imagecolorat( $hashImage, $x, $y )
        			);
        
        			$right = $luminance(
        				imagecolorat( $hashImage, $x + 1, $y )
        			);
        
        			if ( $left > $right ) {
        				if ( $bit < 32 ) {
        					$lo |= ( 1 << $bit );
        				} else {
        					$hi |= ( 1 << ( $bit - 32 ) );
        				}
        			}
        
        			$bit++;
        		}
        	}
        
        	imagedestroy( $rgbImage );
        	imagedestroy( $hashImage );
        	imagedestroy( $src );
        
        	return [
        		'hashHi' => $hi,
        		'hashLo' => $lo,
        		'rgb' => base64_encode( $rgb ),
        	];
        };
        
        $services = \MediaWiki\MediaWikiServices::getInstance();
        
        $title = \MediaWiki\Title\Title::newFromText( $iconListPage );
        
        if ( !$title ) {
        	throw new RuntimeException( 'Invalid Icon List title.' );
        }
        
        $revision = $services
        	->getRevisionLookup()
        	->getRevisionByTitle( $title );
        
        if ( !$revision ) {
        	throw new RuntimeException( 'Icon List does not exist.' );
        }
        
        $revisionId = (int)$revision->getId();
        
        $previousState = $readJson( $stateFile );
        
        // Upload replacements/deletions need detection even when Icon List is unchanged.
        $force = getenv( 'EQL_ICON_FORCE' ) === '1';
        
        $content = $revision->getContent(
        	\MediaWiki\Revision\SlotRecord::MAIN
        );
        
        if ( !$content || !method_exists( $content, 'getText' ) ) {
        	throw new RuntimeException( 'Icon List is not text content.' );
        }
        
        $text = $content->getText();
        
        $parser = $services->getParserFactory()->create();
        $options = \MediaWiki\Parser\ParserOptions::newFromAnon();
        $output = $parser->parse( $text, $title, $options );
        
        $catalog = [];
        $seen = [];
        
        foreach (
        	$output->getLinkList(
        		\MediaWiki\Parser\ParserOutputLinkTypes::MEDIA,
        		NS_FILE
        	) as $item
        ) {
        	$link = $item['link'] ?? null;
        
        	if ( !$link ) {
        		continue;
        	}
        
        	/*
        	 * Critical v0.2 fix:
        	 * pass a real Title object to RepoGroup::findFile().
        	 * Do NOT pass "File:..." as a string.
        	 */
        	$fileTitle = \MediaWiki\Title\Title::makeTitle(
        		NS_FILE,
        		$link->getDBkey()
        	);
        
        	$key = $fileTitle->getPrefixedText();
        
        	if ( isset( $seen[$key] ) ) {
        		continue;
        	}
        
        	$seen[$key] = true;
        	$catalog[] = $fileTitle;
        }
        
        // The display page may intentionally omit ranges. Enumerate actual uploads.
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $uploaded = $dbr->newSelectQueryBuilder()
            ->select( 'img_name' )->from( 'image' )
            ->where( $dbr->expr( 'img_name', 'LIKE', new \Wikimedia\Rdbms\LikeValue( 'Item_', $dbr->anyString() ) )
                ->or( 'img_name', 'LIKE', new \Wikimedia\Rdbms\LikeValue( 'Spellicon_', $dbr->anyString() ) ) )
            ->orderBy( 'img_name' )->caller( __METHOD__ )->fetchResultSet();
        foreach ( $uploaded as $row ) {
            if ( !preg_match( '/^(?:Item_[1-9][0-9]*|Spellicon_[A-Za-z0-9]+)\.png$/D', $row->img_name ) ) {
                continue;
            }
            $fileTitle = \MediaWiki\Title\Title::makeTitle( NS_FILE, $row->img_name );
            $key = $fileTitle->getPrefixedText();
            if ( !isset( $seen[$key] ) ) {
                $seen[$key] = true;
                $catalog[] = $fileTitle;
            }
        }

        if ( !$catalog ) {
        	throw new RuntimeException( 'Icon List produced no media links.' );
        }
        
        /*
         * Self-test a known live icon before beginning a catalog build.
         */
        $selfTitle = \MediaWiki\Title\Title::newFromText( 'File:Item 500.png' );
        $selfFile = $selfTitle
        	? $services->getRepoGroup()->findFile( $selfTitle, [ 'latest' => true ] )
        	: false;
        
        if ( !$selfFile ) {
        	throw new RuntimeException( 'Self-test failed: File:Item 500.png was not found.' );
        }
        
        $selfWidth = (int)$selfFile->getWidth();
        $selfHeight = (int)$selfFile->getHeight();
        $selfPath = $selfFile->getLocalRefPath();
        
        if (
        	$selfWidth < 1 ||
        	$selfHeight < 1 ||
        	$selfWidth > 512 ||
        	$selfHeight > 512 ||
        	!is_string( $selfPath ) ||
        	!is_file( $selfPath )
        ) {
        	throw new RuntimeException( 'Self-test failed: Item 500 has no usable local image path.' );
        }
        
        $selfFingerprint = $fingerprintFile( $selfPath );
        
        if ( !$selfFingerprint ) {
        	throw new RuntimeException( 'Self-test failed: GD could not fingerprint Item 500.' );
        }
        
        echo "Self-test OK: Item 500 {$selfWidth}x{$selfHeight}.\n";
        
        $oldRecords = [];
        
        if (
        	$previousState &&
            (int)( $previousState['algorithm'] ?? 0 ) === $algorithm &&
            (int)( $previousState['schema'] ?? 0 ) === 3
        ) {
            foreach ( $previousState['sourceRecords'] ?? [] as $record ) {
        		if ( isset( $record['title'] ) ) {
        			$oldRecords[$record['title']] = $record;
        		}
        	}
        }
        
        $records = [];
        $listedForGeneration = [];
        
        $reused = 0;
        $built = 0;
        $missing = 0;
        $skipped = 0;
        
        $repoGroup = $services->getRepoGroup();
        
        foreach ( $catalog as $index => $fileTitle ) {
        	$prefixed = $fileTitle->getPrefixedText();
        	$listedForGeneration[] = $prefixed;
        
        	$file = $repoGroup->findFile(
        		$fileTitle,
        		[ 'latest' => true ]
        	);
        
        	if ( !$file ) {
        		$missing++;
        		continue;
        	}
        
        	$width = (int)$file->getWidth();
        	$height = (int)$file->getHeight();
        	$size = (int)$file->getSize();
        
        	/*
        	 * Validate MediaWiki metadata BEFORE asking GD to decode anything.
        	 */
        	if (
        		$width < 1 ||
        		$height < 1 ||
        		$width > 512 ||
        		$height > 512 ||
        		$size < 1 ||
        		$size > 2 * 1024 * 1024
        	) {
        		$skipped++;
        		continue;
        	}
        
        	$sha1 = (string)( $file->getSha1() ?: '' );
        
        	if (
        		isset( $oldRecords[$prefixed] ) &&
        		(string)( $oldRecords[$prefixed]['sha1'] ?? '' ) === $sha1
        	) {
        		$records[] = $oldRecords[$prefixed];
        		$reused++;
        		continue;
        	}
        
        	$path = $file->getLocalRefPath();
        
        	if ( !is_string( $path ) || !is_file( $path ) ) {
        		$skipped++;
        		continue;
        	}
        
        	$fingerprint = $fingerprintFile( $path );
        
        	if ( !$fingerprint ) {
        		$skipped++;
        		continue;
        	}
        
        	$records[] = [
        		'title' => $prefixed,
        		'url' => (string)$file->getUrl(),
        		'sha1' => $sha1,
        		'width' => $width,
        		'height' => $height,
        		'hashHi' => $fingerprint['hashHi'],
        		'hashLo' => $fingerprint['hashLo'],
        		'rgb' => $fingerprint['rgb'],
                'pixelHash' => eqlIconPixelHash( $path ),
        	];
        
        	$built++;
        
        	/*
        	 * Deliberate micro-throttle: the initial 4k-icon build should yield CPU
        	 * frequently instead of creating a short, sharp CPU burst.
        	 */
        	if ( ( $built % 10 ) === 0 ) {
        		usleep( 5000 );
        	}
        }
        
        if ( !$records ) {
        	throw new RuntimeException(
        		'Build produced zero records; refusing to publish.'
        	);
        }
        
        $generationParts = [
            'schema=3;catalog=1',
        	'algorithm=' . $algorithm,
        	'revision=' . $revisionId,
        ];
        
        foreach ( $records as $record ) {
        	$generationParts[] =
        		$record['title'] . '=' . $record['sha1'];
        }
        
        $generation = substr(
        	hash( 'sha256', implode( "\n", $generationParts ) ),
        	0,
        	24
        );
        if ( !$force && $previousState &&
            ( $previousState['generation'] ?? '' ) === $generation &&
            is_file( $publicMetaFile ) ) {
            echo "EQL Icon cache unchanged; checked " . count( $catalog ) . " files.\n";
            return;
        }
        $sourceRecords = $records;
        $records = eqlIconGroupRecords( $sourceRecords );
        // Small display catalog: every filename, without search fingerprints.
        $displayRecords = [];
        foreach ( $sourceRecords as $record ) {
            $name = str_replace( ' ', '_', substr( $record['title'], 5 ) );
            if ( preg_match( '/^(Item|Spellicon)_([A-Za-z0-9]+)\.png$/D', $name, $match ) ) {
                $displayRecords[] = [ $match[1] === 'Item' ? 'item' : 'spell', $match[2], $record['title'], $record['url'] ];
            }
        }
        usort( $displayRecords, static function ( array $a, array $b ): int {
            return strcmp( $a[0], $b[0] ) ?: strnatcasecmp( $a[1], $b[1] );
        } );
        
        $builtAt = gmdate( 'c' );
        
        $payload = [
        	'ready' => true,
            'schema' => 3,
        	'algorithm' => $algorithm,
        	'iconListRevision' => $revisionId,
        	'generation' => $generation,
        	'builtAt' => $builtAt,
        	'listedCount' => count( $catalog ),
        	'count' => count( $records ),
            'fileCount' => count( $sourceRecords ),
        	'records' => $records,
        ];
        
        $meta = [
            'catalog' => 'catalog-' . $generation . '.json',
        	'ready' => true,
            'schema' => 3,
        	'algorithm' => $algorithm,
        	'iconListRevision' => $revisionId,
        	'generation' => $generation,
        	'builtAt' => $builtAt,
        	'listedCount' => count( $catalog ),
        	'count' => count( $records ),
            'fileCount' => count( $sourceRecords ),
        ];
        
        $state = $payload;
        $state['sourceRecords'] = $sourceRecords;
        
        $payloadJson = json_encode(
        	$payload,
        	JSON_UNESCAPED_SLASHES |
        	JSON_UNESCAPED_UNICODE
        );
        
        $metaJson = json_encode(
        	$meta,
        	JSON_UNESCAPED_SLASHES |
        	JSON_UNESCAPED_UNICODE
        );
        
        $stateJson = json_encode(
        	$state,
        	JSON_UNESCAPED_SLASHES |
        	JSON_UNESCAPED_UNICODE
        );
        
        if (
        	$payloadJson === false ||
        	$metaJson === false ||
        	$stateJson === false
        ) {
        	throw new RuntimeException( 'Could not encode static icon index.' );
        }
        
        $generationFile =
        	$publicDir .
        	'/index-' .
        	$generation .
        	'.json';
        
        /*
         * Publish generation first and meta last.
         * Browsers therefore never discover a generation before its file exists.
         */
        $atomicWrite( $generationFile, $payloadJson . "\n" );
        $atomicWrite( $publicDir . '/catalog-' . $generation . '.json',
            json_encode( [ 'generation' => $generation, 'icons' => $displayRecords ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
        $atomicWrite( $stateFile, $stateJson . "\n" );
        $atomicWrite( $publicMetaFile, $metaJson . "\n" );
        
        /*
         * Keep the newest three immutable generations. This allows browsers already
         * fetching a previous generation to finish cleanly during publication.
         */
        $generationFiles = glob( $publicDir . '/index-*.json' ) ?: [];
        
        usort(
        	$generationFiles,
        	static function ( string $a, string $b ): int {
        		return filemtime( $b ) <=> filemtime( $a );
        	}
        );
        
        foreach ( array_slice( $generationFiles, 3 ) as $oldFile ) {
        	@unlink( $oldFile );
            @unlink( str_replace( '/index-', '/catalog-', $oldFile ) );
        }
        
        echo "EQL Icon static cache published.\n";
        echo "Icon List revision: {$revisionId}\n";
        echo "Listed: " . count( $catalog ) . "\n";
        echo "Indexed: " . count( $records ) . "\n";
        echo "Reused: {$reused}\n";
        echo "New/changed fingerprints: {$built}\n";
        echo "Missing files: {$missing}\n";
        echo "Skipped files: {$skipped}\n";
        echo "Generation: {$generation}\n";
    }
}

return EQLIconStaticBuilder::class;
