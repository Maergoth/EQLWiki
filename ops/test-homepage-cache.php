<?php
/** Source-only homepage/news cache checks; synthetic clock/pages, no database or network. */
declare( strict_types = 1 );

namespace MediaWiki\Title {
	final class Title {
		public function __construct(
			private string $key,
			private int $namespace = 0,
			private bool $mainPage = false
		) {}
		public function getDBkey(): string { return $this->key; }
		public function getNamespace(): int { return $this->namespace; }
		public function isMainPage(): bool { return $this->mainPage; }
	}
}

namespace MediaWiki {
	use MediaWiki\Title\Title;

	final class MediaWikiServices {
		public array $purges = [];
		public int $factoryCalls = 0;
		public bool $failPurge = false;
		public function __construct( public Title $mainPage ) {}
		public function getTitleFactory(): object {
			$this->factoryCalls++;
			return new class( $this->mainPage ) {
				public function __construct( private Title $mainPage ) {}
				public function newMainPage(): Title { return $this->mainPage; }
			};
		}
		public function getWikiPageFactory(): object {
			$this->factoryCalls++;
			return new class( $this ) {
				public function __construct( private MediaWikiServices $services ) {}
				public function newFromTitle( Title $title ): object {
					return new class( $this->services, $title ) {
						public function __construct( private MediaWikiServices $services, private Title $title ) {}
						public function doPurge(): bool {
							if ( $this->services->failPurge ) {
								throw new \RuntimeException( 'Synthetic private failure detail' );
							}
							$this->services->purges[] = $this->title->getDBkey();
							return true;
						}
					};
				}
			};
		}
	}
}

namespace {
	use EQLImmersive\HomepageCache;
	use MediaWiki\MediaWikiServices;
	use MediaWiki\Title\Title;

	define( 'NS_MAIN', 0 );
	require_once __DIR__ . '/../includes/parser/Hook/ParserAfterParseHook.php';
	require_once __DIR__ . '/../includes/parser/Hook/RejectParserCacheValueHook.php';
	require_once __DIR__ . '/../includes/Output/Hook/BeforePageDisplayHook.php';
	require_once __DIR__ . '/../includes/Output/Hook/OutputPageCheckLastModifiedHook.php';
	require_once __DIR__ . '/../includes/Storage/Hook/PageSaveCompleteHook.php';
	require_once __DIR__ . '/../skins/EQLImmersive/includes/HomepageCache.php';

	$logs = [];
	function wfDebugLog( $channel, $message ): void {
		$GLOBALS['logs'][] = [ $channel, $message ];
	}

	final class HomepageParserOutput {
		public array $data = [];
		public function __construct( public int $ttl = 86400 ) {}
		public function updateCacheExpiry( int $expiry ): void { $this->ttl = min( $this->ttl, $expiry ); }
		public function setExtensionData( string $name, mixed $value ): void { $this->data[$name] = $value; }
		public function getExtensionData( string $name ): mixed { return $this->data[$name] ?? null; }
	}
	final class HomepageParser {
		public function __construct( private ?Title $title, private HomepageParserOutput $output ) {}
		public function getTitle(): ?Title { return $this->title; }
		public function getOutput(): HomepageParserOutput { return $this->output; }
	}
	final class HomepagePage {
		public function __construct( private ?Title $title ) {}
		public function getTitle(): ?Title { return $this->title; }
	}
	final class HomepageOutput {
		public int $limit = PHP_INT_MAX;
		public function __construct( private ?Title $title, public int $cdnTtl = 3600 ) {}
		public function getTitle(): ?Title { return $this->title; }
		public function lowerCdnMaxage( int $expiry ): void {
			$this->limit = min( $this->limit, $expiry );
			$this->setCdnMaxage( $this->cdnTtl );
		}
		public function setCdnMaxage( int $expiry ): void { $this->cdnTtl = min( $this->limit, $expiry ); }
	}

	$checks = 0;
	$expect = static function ( mixed $actual, mixed $expected, string $message ) use ( &$checks ): void {
		$checks++;
		if ( $actual !== $expected ) {
			throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
		}
	};
	$window = 1800000000;
	$now = $window + 1;
	$main = new Title( 'Main_Page', NS_MAIN, true );
	$news = new Title( 'News' );
	$customMain = new Title( 'Home_Portal', NS_MAIN, true );
	$ordinary = [ new Title( 'A_Guardian' ), new Title( 'News', 2 ), new Title( 'News_archive' ), null ];
	$services = new MediaWikiServices( $main );
	$clock = static function () use ( &$now ): int { return $now; };
	$handler = new HomepageCache( $services, $clock );
	$expect( HomepageCache::CACHE_SECONDS, 300, 'The policy uses one five-minute window.' );

	foreach ( [ $main, $news, $customMain ] as $title ) {
		$output = new HomepageParserOutput();
		$text = '<p>Synthetic content</p>';
		$handler->onParserAfterParse( new HomepageParser( $title, $output ), $text, null );
		$expect( $output->ttl, 299, 'Main page and News expire at the same upcoming shared boundary.' );
		$expect( $output->getExtensionData( HomepageCache::MARKER ), $window, 'Fresh parser entries record their shared cache window.' );
		$expect( $text, '<p>Synthetic content</p>', 'The cache policy never rewrites rendered article content.' );
		$expect( $handler->onRejectParserCacheValue( $output, new HomepagePage( $title ), null ) === false, false, 'Fresh scoped parser output remains reusable within its window.' );
		foreach ( [ null, $window - 300, $window + 300 ] as $marker ) {
			$legacy = new HomepageParserOutput();
			if ( $marker !== null ) { $legacy->setExtensionData( HomepageCache::MARKER, $marker ); }
			$expect( $handler->onRejectParserCacheValue( $legacy, new HomepagePage( $title ), null ), false, 'Missing and different-window parser entries refresh lazily for these pages only.' );
		}
		$strict = new HomepageParserOutput( 30 );
		$handler->onParserAfterParse( new HomepageParser( $title, $strict ), $text, null );
		$expect( $strict->ttl, 30, 'The homepage policy cannot raise a stricter parser expiry.' );
		$http = new HomepageOutput( $title );
		$handler->onBeforePageDisplay( $http, null );
		$expect( $http->cdnTtl, 299, 'HTTP shared-cache expiry ends on the same parser boundary.' );
		$http->setCdnMaxage( 86400 );
		$expect( $http->cdnTtl, 299, 'Later output processing cannot raise the established shared-cache cap.' );
		foreach ( [ 0, 15 ] as $ttl ) {
			$strictHttp = new HomepageOutput( $title, $ttl );
			$handler->onBeforePageDisplay( $strictHttp, null );
			$expect( $strictHttp->cdnTtl, $ttl, 'Private/uncacheable or stricter shared-cache output stays at its original limit.' );
		}
	}

	$baseline = [ 'page' => '20200101000000', 'user' => '20200102000000',
		'epoch' => '20190101000000', 'eql-deployment' => '20260101000000' ];
	foreach ( $ordinary as $title ) {
		$output = new HomepageParserOutput();
		$text = 'Unrelated';
		$handler->onParserAfterParse( new HomepageParser( $title, $output ), $text, null );
		$expect( [ $output->ttl, $output->data ], [ 86400, [] ], 'Ordinary pages, namespaces and missing title retain their parser cache.' );
		$expect( $handler->onRejectParserCacheValue( $output, new HomepagePage( $title ), null ) === false, false, 'Ordinary parser entries are never rejected by homepage rollout.' );
		$http = new HomepageOutput( $title );
		$handler->onBeforePageDisplay( $http, null );
		$expect( [ $http->cdnTtl, $http->limit ], [ 3600, PHP_INT_MAX ], 'Unrelated HTTP cache policy is untouched.' );
		$times = $baseline;
		$handler->onOutputPageCheckLastModified( $times, $http );
		$expect( $times, $baseline, 'Unrelated browser validators, including deployment activation, are untouched.' );
	}

	$firstOutput = new HomepageParserOutput();
	$text = 'Boundary test';
	foreach ( [ $window => 300, $window + 299 => 1, $window + 300 => 300 ] as $timestamp => $remaining ) {
		$now = $timestamp;
		$currentWindow = intdiv( $now, 300 ) * 300;
		$output = new HomepageParserOutput();
		$handler->onParserAfterParse( new HomepageParser( $main, $output ), $text, null );
		$expect( $output->ttl, $remaining, 'Parser TTL respects exact shared boundaries without sleeps or polling.' );
		$times = $baseline;
		$handler->onOutputPageCheckLastModified( $times, new HomepageOutput( $main ) );
		$expect( $times, $baseline + [ 'eql-homepage-window' => gmdate( 'YmdHis', $currentWindow ) ], 'Last-Modified rolls once per shared window while preserving all native/deployment timestamps.' );
		$newsTimes = $baseline;
		$handler->onOutputPageCheckLastModified( $newsTimes, new HomepageOutput( $news ) );
		$expect( $newsTimes, $times, 'Homepage and News share the exact same browser validator window.' );
		if ( $timestamp === $window ) { $firstOutput = $output; }
	}
	$expect( $handler->onRejectParserCacheValue( $firstOutput, new HomepagePage( $main ), null ), false, 'A real clock-window transition rejects formerly valid parser output.' );

	foreach ( array_merge( [ $main, $customMain ], $ordinary ) as $title ) {
		$handler->onPageSaveComplete( new HomepagePage( $title ), null, '', 0, null, null );
	}
	$expect( [ $services->purges, $services->factoryCalls ], [ [], 0 ], 'Only a main-namespace News save invokes invalidation services.' );
	$handler->onPageSaveComplete( new HomepagePage( $news ), null, 'Synthetic news save', 0, null, null );
	$expect( $services->purges, [ 'Main_Page' ], 'News save immediately calls native homepage purge without waiting for window expiry.' );
	$services->mainPage = $customMain;
	$handler->onPageSaveComplete( new HomepagePage( $news ), null, '', 0, null, null );
	$expect( $services->purges, [ 'Main_Page', 'Home_Portal' ], 'News invalidation respects the configured main page instead of hard-coding Main_Page.' );
	$services->failPurge = true;
	$handler->onPageSaveComplete( new HomepagePage( $news ), null, '', 0, null, null );
	$expect( $services->purges, [ 'Main_Page', 'Home_Portal' ], 'A purge failure cannot interrupt or reject the completed News save.' );
	$expect( [ count( $logs ), str_contains( json_encode( $logs ), 'RuntimeException' ) ], [ 1, true ], 'Purge failure records a safe exception-class diagnostic.' );
	$expect( str_contains( json_encode( $logs ), 'Synthetic private failure detail' ), false, 'Failure diagnostics never disclose exception details.' );

	$manifest = json_decode( file_get_contents( __DIR__ . '/../skins/EQLImmersive/skin.json' ), true, 512, JSON_THROW_ON_ERROR );
	foreach ( [ 'ParserAfterParse', 'RejectParserCacheValue', 'BeforePageDisplay', 'OutputPageCheckLastModified', 'PageSaveComplete' ] as $hook ) {
		$classes = array_map( static fn ( $handlerName ) => $manifest['HookHandlers'][$handlerName]['class'] ?? null,
			(array)( $manifest['Hooks'][$hook] ?? [] ) );
		$expect( in_array( HomepageCache::class, $classes, true ), true, "The active skin registers the $hook homepage handler." );
	}
	$validators = (array)$manifest['Hooks']['OutputPageCheckLastModified'];
	$expect( in_array( 'EQLDeploymentAssets', $validators, true ), true, 'The existing deployment cache validator remains registered alongside homepage expiry.' );
	foreach ( [ 'ParserAfterParse', 'BeforePageDisplay' ] as $hook ) {
		$expect( in_array( 'EQLImmersiveHooks', (array)$manifest['Hooks'][$hook], true ), true, "Existing $hook skin behavior remains registered." );
	}
	echo "Homepage cache: {$checks} source-only expiry, clock boundary, validator, invalidation and registration checks passed.\n";
}
