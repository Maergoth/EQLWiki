<?php
/**
 * Read-only integration smoke for an already deployed canonical sitemap handler.
 * Run through maintenance/run.php; this file may live outside the web root.
 * Uses real public page rows and native URL/XML generation, but a process-local
 * config overlay and memory cache. No page/config/persistent-cache writes.
 */
use EQLImmersive\CanonicalSitemapFileHandler;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\MultiConfig;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\SitemapGenerator;
use MediaWiki\Rest\ResponseFactory;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

class EQLCanonicalSitemapSmokeHandler extends CanonicalSitemapFileHandler {
	public array $smokeParams = [ 'indexId' => 0, 'fileId' => 0 ];
	public function getValidatedParams() { return $this->smokeParams; }
	public function getResponseFactory(): ResponseFactory { return new ResponseFactory( [] ); }
}

class EQLCheckCanonicalSitemap extends Maintenance {
	private int $checks = 0;
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Read-only native/canonical sitemap comparison using a memory-only config/cache.' );
		$this->addOption( 'all', 'Check every current file in index 0 (bounded to 100 files).' );
		$this->addOption( 'file-id', 'Check one file in index 0; defaults to 1.', false, true );
	}

	private function check( bool $condition, string $label ): void {
		$this->checks++;
		if ( !$condition ) { $this->fatalError( $label ); }
	}

	private function urls( string $xml ): array {
		$doc = new DOMDocument();
		$this->check( $doc->loadXML( $xml ), 'Generated XML must parse.' );
		$this->check( $doc->documentElement->namespaceURI === 'http://www.sitemaps.org/schemas/sitemap/0.9', 'Native sitemap XML namespace must survive.' );
		return array_map( static fn ( DOMNode $node ): string => $node->textContent,
			iterator_to_array( $doc->getElementsByTagName( 'loc' ) ) );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$actual = $services->getMainConfig();
		$api = $actual->get( 'SitemapApiConfig' );
		$api['enabled'] = true;
		$config = new MultiConfig( [ new HashConfig( [ 'SitemapApiConfig' => $api, 'SitemapNamespaces' => [ 0 ] ] ), $actual ] );
		$connection = $services->getConnectionProvider();
		$db = $connection->getReplicaDatabase();
		$lang = $services->getContentLanguage();
		$converter = $services->getLanguageConverterFactory();
		$gender = $services->getGenderCache();
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$size = (int)( ( $api['pagesPerSitemap'] ?? 10000 ) / ( count( SitemapGenerator::getVariants( $lang, $converter ) ) + 1 ) );
		$this->check( $size > 0, 'Configured sitemap range size must be positive.' );
		$maxId = (int)$db->newSelectQueryBuilder()->select( 'MAX(page_id)' )->from( 'page' )->caller( __METHOD__ )->fetchField();
		$maxFile = (int)floor( $maxId / $size );
		$this->check( $maxFile < 100, 'Integration smoke must remain bounded to 100 files.' );
		$file = filter_var( $this->getOption( 'file-id', '1' ), FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0, 'max_range' => $maxFile ] ] );
		$this->check( $file !== false, 'file-id must be a current nonnegative sitemap range.' );
		$files = $this->hasOption( 'all' ) ? range( 0, $maxFile ) : [ $file ];
		$allUrls = [];
		$totalAliases = 0;
		$origin = rtrim( $actual->get( 'CanonicalServer' ), '/' );
		$this->output( 'Installed sitemap enabled: ' . ( !empty( $actual->get( 'SitemapApiConfig' )['enabled'] ) ? 'yes' : 'no' ) . "; memory-only smoke origin: $origin\n" );
		foreach ( $files as $fileId ) {
			$start = $fileId * $size;
			$end = $start + $size;
			$native = ( new SitemapGenerator( $lang, $converter, $gender ) )->namespacesFromConfig( $config )->idRange( $start, $end )->getXml( $db );
			$nativeUrls = $this->urls( $native );
			$aliases = [];
			$rows = $db->newSelectQueryBuilder()->select( [ 'page_namespace', 'page_title' ] )->from( 'page' )
				->where( [ 'page_namespace' => 0, 'page_is_redirect' => 1, $db->expr( 'page_id', '>=', $start ), $db->expr( 'page_id', '<', $end ) ] )
				->caller( __METHOD__ )->fetchResultSet();
			foreach ( $rows as $row ) {
				$aliases[] = Title::makeTitle( $row->page_namespace, $row->page_title )->getCanonicalURL();
			}
			$handler = new EQLCanonicalSitemapSmokeHandler( $config, $converter, $lang, $services->getPermissionManager(), $connection, $gender, $cache );
			$handler->smokeParams['fileId'] = $fileId;
			$response = $handler->execute();
			$this->check( $response->getStatusCode() === 200, 'Memory-only enabled handler must return 200.' );
			$this->check( $response->getHeaderLine( 'Content-Type' ) === 'application/xml; charset=utf-8', 'Native XML response type must survive.' );
			$urls = $this->urls( (string)$response->getBody() );
			$this->check( $urls === array_values( array_diff( $nativeUrls, $aliases ) ), 'Filtered result must equal native eligible URLs minus redirect aliases.' );
			foreach ( $urls as $url ) {
				$this->check( str_starts_with( $url, $origin . '/' ), 'Every generated URL must use this environment canonical origin.' );
			}
			$removed = count( $nativeUrls ) - count( $urls );
			$totalAliases += $removed;
			$allUrls = array_merge( $allUrls, $urls );
			$this->output( "File $fileId: native=" . count( $nativeUrls ) . ', canonical=' . count( $urls ) . ", redirect aliases removed=$removed\n" );
		}
		$this->check( count( $allUrls ) === count( array_unique( $allUrls ) ), 'Canonical sitemap ranges must not duplicate URLs.' );
		$this->output( "Passed {$this->checks} read-only real-DB/native XML assertions; " . count( $allUrls ) . " canonical URLs, $totalAliases aliases excluded. No page/config/persistent-cache writes.\n" );
	}
}

$maintClass = EQLCheckCanonicalSitemap::class;
