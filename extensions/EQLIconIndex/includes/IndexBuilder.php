<?php

namespace MediaWiki\Extension\EQLIconIndex;

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class IndexBuilder {

	private const SCHEMA = 1;
	private const RGB_GRID = 16;

	private $services;
	private $config;
	private string $cacheDir;
	private string $indexFile;
	private string $metaFile;
	private string $staleFile;
	private string $lockFile;
	private int $algorithm;
	private string $iconListPage;

	public function __construct() {
		$this->services = MediaWikiServices::getInstance();
		$this->config = $this->services->getMainConfig();

		$this->algorithm = (int)$this->config->get( 'EQLIconIndexAlgorithm' );
		$this->iconListPage = (string)$this->config->get( 'EQLIconIndexPage' );

		$base = $this->config->get( 'CacheDirectory' );
		if ( !$base ) {
			$base = wfTempDir();
		}

		$subdir = trim(
			(string)$this->config->get( 'EQLIconIndexCacheSubdir' ),
			'/\\'
		);

		$this->cacheDir = rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . $subdir;
		$this->indexFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'index.json';
		$this->metaFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'meta.json';
		$this->staleFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'stale.json';
		$this->lockFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'index.lock';

		$this->ensureCacheDir();
	}

	public function markStale( string $reason ): void {
		$this->atomicWrite(
			$this->staleFile,
			json_encode(
				[
					'reason' => $reason,
					'at' => gmdate( 'c' ),
				],
				JSON_UNESCAPED_SLASHES
			) . "\n"
		);
	}

	public function isListedFile( string $fileTitle ): bool {
		$normalized = $this->normalizeFileTitle( $fileTitle );
		if ( !$normalized ) {
			return false;
		}

		$meta = $this->readMetaUnlocked();
		if ( !$meta ) {
			return false;
		}

		foreach ( $meta['listed'] ?? [] as $listed ) {
			if ( $listed === $normalized ) {
				return true;
			}
		}

		return false;
	}

	public function rebuild(): array {
		return $this->withLock( function () {
			return $this->rebuildUnlocked();
		} );
	}

	public function updateFile( string $fileTitle ): array {
		return $this->withLock( function () use ( $fileTitle ) {
			$current = $this->readIndexUnlocked();

			if (
				!$current ||
				(int)( $current['schema'] ?? 0 ) !== self::SCHEMA ||
				(int)( $current['algorithm'] ?? 0 ) !== $this->algorithm
			) {
				return $this->rebuildUnlocked();
			}

			$listed = array_fill_keys( $current['listed'] ?? [], true );
			$normalized = $this->normalizeFileTitle( $fileTitle );

			if ( !$normalized || !isset( $listed[$normalized] ) ) {
				/*
				 * This upload is unrelated to Icon List. Clear a stale marker
				 * that may have been written by the upload hook, but do not
				 * change the index generation.
				 */
				@unlink( $this->staleFile );
				return $current;
			}

			$records = [];
			foreach ( $current['records'] ?? [] as $record ) {
				if ( isset( $record['title'] ) ) {
					$records[$record['title']] = $record;
				}
			}

			$newRecord = $this->buildRecordForTitle( $normalized );

			if ( $newRecord ) {
				$records[$normalized] = $newRecord;
			} else {
				unset( $records[$normalized] );
			}

			$ordered = [];
			foreach ( $current['listed'] as $title ) {
				if ( isset( $records[$title] ) ) {
					$ordered[] = $records[$title];
				}
			}

			$current['records'] = $ordered;
			$current['count'] = count( $ordered );
			$current['generation'] = $this->computeGeneration(
				(int)$current['iconListRevision'],
				$current['listed'],
				$ordered
			);
			$current['builtAt'] = gmdate( 'c' );

			$this->writeIndexUnlocked( $current );
			return $current;
		} );
	}

	public function getPublicState( bool $includeRecords = true ): array {
		$currentRevision = $this->getCurrentIconListRevisionId();
		$meta = $this->readMetaUnlocked();
		$stale = is_file( $this->staleFile );

		if (
			!$meta ||
			$stale ||
			(int)( $meta['schema'] ?? 0 ) !== self::SCHEMA ||
			(int)( $meta['algorithm'] ?? 0 ) !== $this->algorithm ||
			(int)( $meta['iconListRevision'] ?? 0 ) !== $currentRevision
		) {
			return [
				'ready' => false,
				'stale' => $stale,
				'algorithm' => $this->algorithm,
				'iconListRevision' => $currentRevision,
				'count' => 0,
			];
		}

		$state = [
			'ready' => true,
			'stale' => false,
			'schema' => self::SCHEMA,
			'algorithm' => (int)$meta['algorithm'],
			'iconListRevision' => (int)$meta['iconListRevision'],
			'generation' => (string)$meta['generation'],
			'builtAt' => (string)$meta['builtAt'],
			'listedCount' => (int)( $meta['listedCount'] ?? 0 ),
			'count' => (int)$meta['count'],
		];

		if ( !$includeRecords ) {
			return $state;
		}

		$index = $this->readIndexUnlocked();

		if (
			!$index ||
			(string)( $index['generation'] ?? '' ) !== $state['generation']
		) {
			return [
				'ready' => false,
				'stale' => true,
				'algorithm' => $this->algorithm,
				'iconListRevision' => $currentRevision,
				'count' => 0,
			];
		}

		$state['records'] = $index['records'] ?? [];
		return $state;
	}

	public function getStatus(): array {
		$state = $this->getPublicState( false );
		$state['cacheDir'] = $this->cacheDir;
		$state['gd'] = extension_loaded( 'gd' ) && function_exists( 'imagecreatefromstring' );
		return $state;
	}

	private function rebuildUnlocked(): array {
		$this->assertImageProcessor();

		$catalog = $this->readCatalog();
		$previous = $this->readIndexUnlocked();

		$oldByTitle = [];
		if (
			$previous &&
			(int)( $previous['algorithm'] ?? 0 ) === $this->algorithm
		) {
			foreach ( $previous['records'] ?? [] as $record ) {
				if ( isset( $record['title'] ) ) {
					$oldByTitle[$record['title']] = $record;
				}
			}
		}

		$records = [];

		foreach ( $catalog['titles'] as $title ) {
			$file = $this->services->getRepoGroup()->findFile(
				$title,
				[ 'latest' => true ]
			);

			if ( !$file ) {
				continue;
			}

			$sha1 = (string)( $file->getSha1() ?: '' );

			if (
				isset( $oldByTitle[$title] ) &&
				(string)( $oldByTitle[$title]['sha1'] ?? '' ) === $sha1
			) {
				$records[] = $oldByTitle[$title];
				continue;
			}

			$record = $this->buildRecordFromFile( $title, $file );
			if ( $record ) {
				$records[] = $record;
			}
		}

		$index = [
			'schema' => self::SCHEMA,
			'algorithm' => $this->algorithm,
			'iconListPage' => $this->iconListPage,
			'iconListRevision' => $catalog['revision'],
			'generation' => $this->computeGeneration(
				$catalog['revision'],
				$catalog['titles'],
				$records
			),
			'builtAt' => gmdate( 'c' ),
			'listedCount' => count( $catalog['titles'] ),
			'count' => count( $records ),
			'listed' => $catalog['titles'],
			'records' => $records,
		];

		$this->writeIndexUnlocked( $index );
		return $index;
	}

	private function readCatalog(): array {
		$title = Title::newFromText( $this->iconListPage );

		if ( !$title ) {
			throw new \RuntimeException(
				'Invalid Icon List page title: ' . $this->iconListPage
			);
		}

		$revision = $this->services
			->getRevisionLookup()
			->getRevisionByTitle( $title );

		if ( !$revision ) {
			throw new \RuntimeException(
				'Icon List page does not exist: ' . $this->iconListPage
			);
		}

		$content = $revision->getContent( SlotRecord::MAIN );

		if ( !$content ) {
			throw new \RuntimeException( 'Could not read Icon List content.' );
		}

		if ( !method_exists( $content, 'getText' ) ) {
			throw new \RuntimeException( 'Icon List is not text content.' );
		}

		$text = $content->getText();

		$parser = $this->services->getParserFactory()->create();
		$options = ParserOptions::newFromAnon();
		$output = $parser->parse( $text, $title, $options );

		$titles = [];
		$seen = [];

		foreach (
			$output->getLinkList( ParserOutputLinkTypes::MEDIA, NS_FILE )
			as $item
		) {
			$link = $item['link'] ?? null;
			if ( !$link ) {
				continue;
			}

			$dbKey = $link->getDBkey();
			$fileTitle = Title::makeTitle( NS_FILE, $dbKey )->getPrefixedText();
			$fileTitle = $this->normalizeFileTitle( $fileTitle );

			if ( !$fileTitle || isset( $seen[$fileTitle] ) ) {
				continue;
			}

			$seen[$fileTitle] = true;
			$titles[] = $fileTitle;
		}

		return [
			'revision' => $revision->getId(),
			'titles' => $titles,
		];
	}

	private function getCurrentIconListRevisionId(): int {
		$title = Title::newFromText( $this->iconListPage );
		if ( !$title ) {
			return 0;
		}

		$revision = $this->services
			->getRevisionLookup()
			->getRevisionByTitle( $title );

		return $revision ? (int)$revision->getId() : 0;
	}

	private function buildRecordForTitle( string $title ): ?array {
		$this->assertImageProcessor();

		$file = $this->services->getRepoGroup()->findFile(
			$title,
			[ 'latest' => true ]
		);

		return $file ? $this->buildRecordFromFile( $title, $file ) : null;
	}

	private function buildRecordFromFile( string $title, $file ): ?array {
		$path = $file->getLocalRefPath();

		if ( !$path || !is_file( $path ) ) {
			return null;
		}

		$size = @filesize( $path );
		if ( $size !== false && $size > 8 * 1024 * 1024 ) {
			return null;
		}

		$fingerprint = $this->fingerprintFile( $path );

		if ( !$fingerprint ) {
			return null;
		}

		return [
			'title' => $title,
			'url' => (string)$file->getUrl(),
			'sha1' => (string)( $file->getSha1() ?: '' ),
			'width' => (int)$file->getWidth(),
			'height' => (int)$file->getHeight(),
			'hashHi' => $fingerprint['hashHi'],
			'hashLo' => $fingerprint['hashLo'],
			'rgb' => base64_encode( $fingerprint['rgb'] ),
		];
	}

	private function fingerprintFile( string $path ): ?array {
		$raw = @file_get_contents( $path );
		if ( $raw === false ) {
			return null;
		}

		$src = @imagecreatefromstring( $raw );
		if ( !$src ) {
			return null;
		}

		if ( function_exists( 'imagepalettetotruecolor' ) && !imageistruecolor( $src ) ) {
			@imagepalettetotruecolor( $src );
		}

		$width = imagesx( $src );
		$height = imagesy( $src );

		if ( $width < 1 || $height < 1 || $width > 2048 || $height > 2048 ) {
			imagedestroy( $src );
			return null;
		}

		$bounds = $this->foregroundBounds( $src, $width, $height );

		$rgbImage = $this->renderNormalized(
			$src,
			$bounds,
			self::RGB_GRID,
			self::RGB_GRID
		);

		$hashImage = $this->renderNormalized(
			$src,
			$bounds,
			9,
			8
		);

		$rgb = '';
		for ( $y = 0; $y < self::RGB_GRID; $y++ ) {
			for ( $x = 0; $x < self::RGB_GRID; $x++ ) {
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
				$left = $this->pixelLuminance(
					imagecolorat( $hashImage, $x, $y )
				);
				$right = $this->pixelLuminance(
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
			'rgb' => $rgb,
		];
	}

	private function foregroundBounds( $src, int $width, int $height ): array {
		$minX = $width;
		$minY = $height;
		$maxX = -1;
		$maxY = -1;
		$found = false;

		/*
		 * Uploaded Icon List assets are expected to be transparent PNGs.
		 * Alpha is therefore authoritative. If an image has no meaningful
		 * alpha at all, use its full canvas rather than guessing.
		 */
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$c = imagecolorat( $src, $x, $y );
				$alpha = ( $c >> 24 ) & 0x7F;

				if ( $alpha >= 112 ) {
					continue;
				}

				$found = true;
				$minX = min( $minX, $x );
				$minY = min( $minY, $y );
				$maxX = max( $maxX, $x );
				$maxY = max( $maxY, $y );
			}
		}

		if ( !$found ) {
			return [ 0, 0, $width - 1, $height - 1 ];
		}

		return [ $minX, $minY, $maxX, $maxY ];
	}

	private function renderNormalized(
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
	}

	private function pixelLuminance( int $color ): float {
		$r = ( $color >> 16 ) & 0xFF;
		$g = ( $color >> 8 ) & 0xFF;
		$b = $color & 0xFF;
		return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
	}

	private function computeGeneration(
		int $revision,
		array $listed,
		array $records
	): string {
		$shaByTitle = [];
		foreach ( $records as $record ) {
			if ( isset( $record['title'] ) ) {
				$shaByTitle[$record['title']] = $record['sha1'] ?? '';
			}
		}

		$parts = [
			'schema=' . self::SCHEMA,
			'algorithm=' . $this->algorithm,
			'revision=' . $revision,
		];

		foreach ( $listed as $title ) {
			$parts[] = $title . '=' . ( $shaByTitle[$title] ?? '!' );
		}

		return substr(
			hash( 'sha256', implode( "\n", $parts ) ),
			0,
			24
		);
	}

	private function normalizeFileTitle( string $title ): string {
		$title = trim( str_replace( '_', ' ', $title ) );
		if ( $title === '' ) {
			return '';
		}

		if ( stripos( $title, 'File:' ) !== 0 ) {
			$title = 'File:' . $title;
		}

		$obj = Title::newFromText( $title );
		if ( !$obj || $obj->getNamespace() !== NS_FILE ) {
			return '';
		}

		return $obj->getPrefixedText();
	}

	private function readMetaUnlocked(): ?array {
		if ( !is_file( $this->metaFile ) ) {
			return null;
		}

		$json = @file_get_contents( $this->metaFile );
		if ( $json === false || $json === '' ) {
			return null;
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	private function readIndexUnlocked(): ?array {
		if ( !is_file( $this->indexFile ) ) {
			return null;
		}

		$json = @file_get_contents( $this->indexFile );
		if ( $json === false || $json === '' ) {
			return null;
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	private function writeIndexUnlocked( array $index ): void {
		$json = json_encode(
			$index,
			JSON_UNESCAPED_SLASHES |
			JSON_UNESCAPED_UNICODE
		);

		if ( $json === false ) {
			throw new \RuntimeException( 'Could not encode icon index JSON.' );
		}

		$meta = [
			'schema' => self::SCHEMA,
			'algorithm' => (int)$index['algorithm'],
			'iconListPage' => (string)$index['iconListPage'],
			'iconListRevision' => (int)$index['iconListRevision'],
			'generation' => (string)$index['generation'],
			'builtAt' => (string)$index['builtAt'],
			'listedCount' => (int)( $index['listedCount'] ?? count( $index['listed'] ?? [] ) ),
			'count' => (int)$index['count'],
			'listed' => array_values( $index['listed'] ?? [] ),
		];

		$metaJson = json_encode(
			$meta,
			JSON_UNESCAPED_SLASHES |
			JSON_UNESCAPED_UNICODE
		);

		if ( $metaJson === false ) {
			throw new \RuntimeException( 'Could not encode icon index metadata.' );
		}

		/*
		 * Index first, metadata second. A reader never sees metadata for a
		 * generation whose full index has not been written yet.
		 */
		$this->atomicWrite( $this->indexFile, $json . "\n" );
		$this->atomicWrite( $this->metaFile, $metaJson . "\n" );
		@unlink( $this->staleFile );
	}

	private function atomicWrite( string $path, string $data ): void {
		$this->ensureCacheDir();

		$tmp = $path . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );

		if ( file_put_contents( $tmp, $data, LOCK_EX ) === false ) {
			throw new \RuntimeException( 'Could not write temporary cache file.' );
		}

		@chmod( $tmp, 0644 );

		if ( !@rename( $tmp, $path ) ) {
			@unlink( $tmp );
			throw new \RuntimeException( 'Could not atomically replace cache file.' );
		}
	}

	private function withLock( callable $callback ) {
		$this->ensureCacheDir();

		$handle = fopen( $this->lockFile, 'c' );
		if ( !$handle ) {
			throw new \RuntimeException( 'Could not open icon-index lock.' );
		}

		try {
			if ( !flock( $handle, LOCK_EX ) ) {
				throw new \RuntimeException( 'Could not acquire icon-index lock.' );
			}

			return $callback();
		} finally {
			@flock( $handle, LOCK_UN );
			@fclose( $handle );
		}
	}

	private function ensureCacheDir(): void {
		if ( is_dir( $this->cacheDir ) ) {
			return;
		}

		if ( !@mkdir( $this->cacheDir, 0755, true ) && !is_dir( $this->cacheDir ) ) {
			throw new \RuntimeException(
				'Could not create icon cache directory: ' . $this->cacheDir
			);
		}
	}

	private function assertImageProcessor(): void {
		if (
			!extension_loaded( 'gd' ) ||
			!function_exists( 'imagecreatefromstring' )
		) {
			throw new \RuntimeException(
				'EQLIconIndex requires the PHP GD extension for server-side fingerprint generation.'
			);
		}
	}
}
