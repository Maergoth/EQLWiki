<?php
/**
 * Native generator/REST/router tests with synthetic database, language and title
 * doubles. No private settings, live database, network or persistent cache writes.
 * Run: php ops/test-canonical-sitemap.php
 */
namespace MediaWiki\Language {
	class Language {
		public function getCode(): string { return 'en'; }
		public function needsGenderDistinction(): bool { return false; }
	}
}
namespace MediaWiki\Languages {
	class LanguageConverterFactory {
		public function getLanguageConverter( $language ): object {
			return new class {
				public function getVariants(): array { return [ 'en' ]; }
			};
		}
	}
}
namespace MediaWiki\Cache { class GenderCache {} }
namespace MediaWiki\Permissions {
	class PermissionManager {
		public function __construct( private bool $public = true ) {}
		public function isEveryoneAllowed( string $right ): bool { return $this->public; }
	}
}
namespace MediaWiki\Title {
	class Title {
		public static function makeTitle( int $namespace, string $text ): self {
			return new self( $namespace, $text );
		}
		public function __construct( private int $namespace, private string $text ) {}
		public function getCanonicalURL( string $query = '' ): string {
			return 'https://synthetic.invalid/' . rawurlencode( $this->text ) . ( $query === '' ? '' : '?' . $query );
		}
	}
}
namespace Wikimedia\Rdbms {
	interface IReadableDatabase {}
	interface IConnectionProvider {}
}
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( "Run this fixture from the command line.\n" ); }
	$root = dirname( __DIR__ );
	require_once $root . '/includes/AutoLoader.php';
	require_once $root . '/skins/EQLImmersive/includes/CanonicalSitemapFileHandler.php';
	function wfTimestamp( $format, $value ) {
		return DateTimeImmutable::createFromFormat( '!YmdHis', $value, new DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d\TH:i:s\Z' );
	}
	use EQLImmersive\CanonicalSitemapFileHandler;
	use MediaWiki\Config\HashConfig;
	use MediaWiki\Rest\ResponseFactory;
	use Wikimedia\ObjectCache\HashBagOStuff;
	use Wikimedia\ObjectCache\WANObjectCache;
	use Wikimedia\ParamValidator\TypeDef\IntegerDef;

	class SitemapDatabase implements \Wikimedia\Rdbms\IReadableDatabase, \Wikimedia\Rdbms\IConnectionProvider {
		public int $queries = 0;
		public function __construct( public array $rows ) {}
		public function getReplicaDatabase(): self { return $this; }
		public function expr( string $key, string $op, mixed $value ): Closure {
			return static fn ( object $row ): bool => $op === '>=' ? $row->$key >= $value : $row->$key < $value;
		}
		public function newSelectQueryBuilder(): object {
			return new class( $this ) {
				private array $conditions = [];
				public function __construct( private SitemapDatabase $db ) {}
				public function select( $fields ): self { return $this; }
				public function from( $table ): self { return $this; }
				public function leftJoin( ...$args ): self { return $this; }
				public function orderBy( ...$args ): self { return $this; }
				public function caller( ...$args ): self { return $this; }
				public function where( $condition ): self { $this->conditions[] = $condition; return $this; }
				public function fetchResultSet(): array {
					$this->db->queries++;
					return array_values( array_filter( $this->db->rows, function ( object $row ): bool {
						foreach ( $this->conditions as $condition ) {
							if ( $condition instanceof Closure ) {
								if ( !$condition( $row ) ) { return false; }
							} else {
								foreach ( $condition as $key => $value ) {
									if ( is_array( $value ) ? !in_array( $row->$key, $value, true ) : $row->$key !== $value ) { return false; }
								}
							}
						}
						return true;
					} ) );
				}
			};
		}
	}
	class SitemapProbe extends CanonicalSitemapFileHandler {
		public array $params = [ 'indexId' => 0, 'fileId' => 0 ];
		public function getValidatedParams() { return $this->params; }
		public function getResponseFactory(): ResponseFactory { return new ResponseFactory( [] ); }
		public function modified(): mixed { return $this->getLastModified(); }
	}
	$checks = 0;
	function sitemapCheck( bool $ok, string $label ): void {
		global $checks; $checks++;
		if ( !$ok ) { throw new RuntimeException( $label ); }
	}
	function sitemapUrls( string $xml ): array {
		$doc = new DOMDocument();
		sitemapCheck( $doc->loadXML( $xml ), 'Generated XML is well formed.' );
		sitemapCheck( $doc->documentElement->namespaceURI === 'http://www.sitemaps.org/schemas/sitemap/0.9', 'Native XML namespace survives.' );
		return array_map( static fn ( DOMNode $node ): string => $node->textContent, iterator_to_array( $doc->getElementsByTagName( 'loc' ) ) );
	}
	$row = static fn ( int $id, string $title, int $ns = 0, int $redirect = 0, ?string $noindex = null ): object => (object)[
		'page_id' => $id, 'page_title' => $title, 'page_namespace' => $ns, 'page_is_redirect' => $redirect,
		'page_touched' => '20261121150000', 'pp_propname' => $noindex,
	];
	$db = new SitemapDatabase( [
		$row( 1, 'Synthetic_Gem' ), $row( 2, 'Alias_Gem', 0, 1 ), $row( 3, 'Category_Page', 14 ),
		$row( 4, 'Private_Gem', 0, 0, 'noindex' ), $row( 5, 'Boundary_Gem' ),
		$row( 7, 'Second_Alias', 0, 1 ), $row( 9, 'Synthetic_&_Gem' ),
	] );
	$values = [ 'SitemapApiConfig' => [ 'enabled' => true, 'pagesPerSitemap' => 5, 'sitemapsPerIndex' => 3, 'expiry' => 60 ],
		'SitemapNamespaces' => [ 0 ], 'DefaultRobotPolicy' => 'index,follow', 'NamespaceRobotPolicies' => [],
		'CanonicalServer' => 'https://synthetic.invalid', 'ArticlePath' => '/$1' ];
	$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
	$make = static function ( array $override = [], bool $public = true, int $index = 0, int $file = 0 ) use ( $values, $db, $cache ): SitemapProbe {
		$handler = new SitemapProbe( new HashConfig( array_replace( $values, $override ) ), new \MediaWiki\Languages\LanguageConverterFactory(),
			new \MediaWiki\Language\Language(), new \MediaWiki\Permissions\PermissionManager( $public ), $db, new \MediaWiki\Cache\GenderCache(), $cache );
		$handler->params = [ 'indexId' => $index, 'fileId' => $file ];
		return $handler;
	};
	$cache->getWithSetCallback( $cache->makeKey( 'sitemap', 0, 5 ), 60,
		static fn () => [ 'xml' => '<old-alias-cache/>', 'timestamp' => time() ], [ 'version' => 1 ] );
	$handler = $make();
	$response = $handler->execute();
	sitemapCheck( $response->getStatusCode() === 200, 'Enabled public production produces XML.' );
	sitemapCheck( $response->getHeaderLine( 'Content-Type' ) === 'application/xml; charset=utf-8', 'Native content type survives.' );
	sitemapCheck( $response->getHeaderLine( 'Cache-Control' ) === 'public', 'Native cache policy survives.' );
	sitemapCheck( sitemapUrls( (string)$response->getBody() ) === [ 'https://synthetic.invalid/Synthetic_Gem' ], 'Keep genuine article; exclude alias, noindex and other namespaces.' );
	sitemapCheck( str_contains( (string)$response->getBody(), '<lastmod>2026-11-21T15:00:00Z</lastmod>' ), 'Preserve native page-touched lastmod.' );
	sitemapCheck( abs( strtotime( $response->getHeaderLine( 'Expires' ) ) - time() - 60 ) < 5, 'Preserve configured cache lifetime.' );
	$modified = $handler->modified();
	$make()->execute();
	sitemapCheck( $db->queries === 1 && $modified !== null, 'Filtered cache is reused across handlers and last-modified calls.' );
	sitemapCheck( sitemapUrls( (string)$make( [], true, 0, 1 )->execute()->getBody() ) === [
		'https://synthetic.invalid/Boundary_Gem', 'https://synthetic.invalid/Synthetic_%26_Gem',
	], 'Native half-open ID ranges retain the boundary article and escaped title exactly once.' );
	sitemapCheck( sitemapUrls( (string)$make( [], true, 0, 2 )->execute()->getBody() ) === [], 'Empty filtered ID ranges remain valid XML.' );
	sitemapCheck( sitemapUrls( (string)$make( [], true, 1, 0 )->execute()->getBody() ) === [], 'Index offsets do not repeat earlier pages.' );
	$before = $db->queries;
	$disabled = $make( [ 'SitemapApiConfig' => [ 'enabled' => false ] ] );
	sitemapCheck( $disabled->modified() === null && $disabled->execute()->getStatusCode() === 404 && $db->queries === $before, 'Disabled staging/local routes never generate data, even during preconditions.' );
	$private = $make( [], false );
	sitemapCheck( $private->modified() === null && $private->execute()->getStatusCode() === 403 && $db->queries === $before, 'Restricted read access never generates sitemap data.' );
	$make( [ 'NamespaceRobotPolicies' => [ 14 => 'noindex' ] ] )->execute();
	sitemapCheck( $db->queries === $before + 1, 'A policy-scope change cannot reuse an older sitemap cache.' );
	$make( [ 'CanonicalServer' => 'https://changed.invalid' ] )->execute();
	sitemapCheck( $db->queries === $before + 2, 'An origin change cannot reuse sitemap URLs from an older origin.' );
	foreach ( $handler->getParamSettings() as $settings ) {
		sitemapCheck( $settings[IntegerDef::PARAM_MIN] === 0, 'Negative sitemap offsets are rejected by the native validator.' );
	}

	$manifest = json_decode( file_get_contents( "$root/skins/EQLImmersive/skin.json" ), true, 512, JSON_THROW_ON_ERROR );
	sitemapCheck( in_array( 'rest-sitemap.json', $manifest['RestModuleFiles'], true ), 'The active skin registers the supported REST module file.' );
	$spec = json_decode( file_get_contents( "$root/skins/EQLImmersive/rest-sitemap.json" ), true, 512, JSON_THROW_ON_ERROR );
	$core = json_decode( file_get_contents( "$root/includes/Rest/coreRoutes.json" ), true, 512, JSON_THROW_ON_ERROR );
	$corePaths = array_values( array_filter( array_column( $core, 'path' ), static fn ( string $path ): bool => str_starts_with( $path, '/site/v1/' ) ) );
	sitemapCheck( $corePaths === array_map( static fn ( string $path ): string => '/site/v1' . $path, array_keys( $spec['paths'] ) ), 'Fail on upstream site/v1 additions rather than silently hiding another core route.' );
	sitemapCheck( $spec['paths']['/sitemap/{indexId}']['get']['handler']['class'] === \MediaWiki\Rest\Handler\SitemapIndexHandler::class, 'The sitemap index retains the native handler and URLs.' );
	$router = ( new ReflectionClass( \MediaWiki\Rest\Router::class ) )->newInstanceWithoutConstructor();
	foreach ( [ 'routeFiles' => [ "$root/includes/Rest/coreRoutes.json", "$root/skins/EQLImmersive/rest-sitemap.json" ], 'extraRoutes' => [], 'rootPath' => '/rest.php', 'scriptPath' => '' ] as $property => $value ) {
		( new ReflectionProperty( $router, $property ) )->setValue( $router, $value );
	}
	$map = ( new ReflectionMethod( $router, 'buildModuleMap' ) )->invoke( $router );
	( new ReflectionProperty( $router, 'moduleMap' ) )->setValue( $router, $map );
	sitemapCheck( ( new ReflectionMethod( $router, 'splitPath' ) )->invoke( $router, '/rest.php/site/v1/sitemap/0/page/1' ) === [ 'site/v1', '/sitemap/0/page/1' ], 'Real native router selects the explicit module over prefix-less fallback.' );
	$module = ( new ReflectionClass( \MediaWiki\Rest\Module\SpecBasedModule::class ) )->newInstanceWithoutConstructor();
	( new ReflectionProperty( $module, 'definitionFile' ) )->setValue( $module, "$root/skins/EQLImmersive/rest-sitemap.json" );
	( new ReflectionProperty( \MediaWiki\Rest\Module\Module::class, 'responseFactory' ) )->setValue( $module, new ResponseFactory( [] ) );
	$match = $module->findHandlerMatch( '/sitemap/0/page/1', 'GET' );
	sitemapCheck( $match['found'] && $match['spec']['class'] === CanonicalSitemapFileHandler::class, 'Real native module matcher selects the canonical child handler.' );
	sitemapCheck( !$module->findHandlerMatch( '/unrelated', 'GET' )['found'], 'The custom module exposes no unrelated route.' );
	echo "Canonical sitemap checks passed ($checks assertions; native generator/REST/router with synthetic inputs).\n";
}
