<?php
/** Synthetic configuration/eligibility only; no settings, database or provider calls. */
declare( strict_types = 1 );

// Reuse and run the existing eligibility contract; analytics must remain a subset.
require __DIR__ . '/test-ads-config.php';
require_once __DIR__ . '/../skins/EQLImmersive/includes/Analytics.php';

$analyticsChecks = 0;
$analyticsCheck = static function ( array $options, bool $public, ?array $expected ) use ( &$analyticsChecks ): void {
	$options['config'] = array_replace( [ 'EQLAnalyticsMeasurementId' => 'G-1234567890' ], $options['config'] ?? [] );
	$base = fixture( $options );
	$out = new class( $base, $options ) {
		public function __construct( private object $base, private array $options ) {}
		public function getConfig(): object {
			return new class( $this->base->getConfig(), $this->options['missingRegistration'] ?? false ) {
				public function __construct( private object $config, private bool $missing ) {}
				public function get( string $key ): mixed { return $this->config->get( $key ); }
				public function has( string $key ): bool { return !$this->missing || $key !== 'EQLAnalyticsMeasurementId'; }
			};
		}
		public function getTitle(): ?object {
			$title = $this->base->getTitle();
			if ( !$title ) { return null; }
			return new class( $title, $this->options ) {
				public function __construct( private object $title, private array $options ) {}
				public function getNamespace(): int { return $this->title->getNamespace(); }
				public function getCanonicalURL(): string { return $this->options['canonical'] ?? 'https://eqlwiki.com/Synthetic_article'; }
				public function getPrefixedText(): string { return 'Synthetic article'; }
			};
		}
	};
	$ads = EQLImmersive\Ads::configurationForOutput( $base, $public );
	$actual = EQLImmersive\Analytics::configurationForOutput( $out, $ads, $public );
	$analyticsChecks++;
	if ( $actual !== $expected ) { throw new RuntimeException( 'Analytics eligibility failed: ' . json_encode( $options ) ); }
};
$analyticsValid = [ 'measurementId' => 'G-1234567890', 'origin' => 'https://eqlwiki.com',
	'pageLocation' => 'https://eqlwiki.com/Synthetic_article', 'pageTitle' => 'Synthetic article' ];
$analyticsCheck( [], true, $analyticsValid );
$analyticsCheck( [], false, null );
$analyticsCheck( [ 'missingRegistration' => true ], true, null );
foreach ( [
	[ 'namespace' => 1 ], [ 'namespace' => -1 ], [ 'namespace' => 2 ], [ 'namespace' => 14 ],
	[ 'missingTitle' => true ], [ 'exists' => false ], [ 'redirect' => true ], [ 'article' => false ],
	[ 'current' => false ], [ 'action' => 'edit' ], [ 'query' => [ 'diff' => '1' ] ],
	[ 'query' => [ 'oldid' => '2' ] ], [ 'query' => [ 'veaction' => 'edit' ] ], [ 'policy' => 'noindex' ]
] as $options ) { $analyticsCheck( $options, true, null ); }
foreach ( [
	[ 'EQLAnalyticsMeasurementId' => '' ], [ 'EQLAnalyticsMeasurementId' => null ],
	[ 'EQLAnalyticsMeasurementId' => true ], [ 'EQLAnalyticsMeasurementId' => "G-1234567890\n" ],
	[ 'EQLAnalyticsMeasurementId' => 'G-invalid' ], [ 'EQLAnalyticsMeasurementId' => 'UA-1234567890' ],
	[ 'EQLAdSenseClient' => '' ], [ 'Server' => 'https://eqlwiki.com:443' ],
	[ 'CanonicalServer' => 'https://test.eqlwiki.com' ], [ 'Server' => 'http://eqlwiki.com' ],
	[ 'DefaultRobotPolicy' => 'noindex,follow' ],
	[ 'Server' => 'https://test.eqlwiki.com', 'CanonicalServer' => 'https://test.eqlwiki.com',
		'EQLAdSenseStagingClient' => 'ca-pub-1234567890123456', 'EQLAdSenseStagingFormat' => 'anchor' ]
] as $config ) { $analyticsCheck( [ 'config' => $config ], true, null ); }
foreach ( [
	'https://other.example/Synthetic_article', 'http://eqlwiki.com/Synthetic_article',
	'https://eqlwiki.com/Synthetic_article?token=private', 'https://eqlwiki.com/Synthetic_article#private',
	'https://eqlwiki.com:443/Synthetic_article', 'https://user:password@eqlwiki.com/Synthetic_article'
] as $canonical ) { $analyticsCheck( [ 'canonical' => $canonical ], true, null ); }
// Visitor queries never become Analytics page URLs.
$analyticsCheck( [ 'query' => [ 'search' => 'private search', 'token' => 'private' ] ], true, $analyticsValid );
echo "Analytics configuration checks passed: $analyticsChecks\n";
