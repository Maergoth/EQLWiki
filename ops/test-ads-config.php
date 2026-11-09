<?php
/** Synthetic eligibility checks; no private settings, database, or network. */
declare( strict_types = 1 );

define( 'NS_MAIN', 0 );
require_once __DIR__ . '/../skins/EQLImmersive/includes/Ads.php';

function fixture( array $options = [] ): object {
	return new class( $options ) {
		public function __construct( private array $options ) {}
		public function getConfig(): object {
			$values = array_replace( [
				'EQLAdSenseClient' => 'ca-pub-1234567890123456', 'EQLAdSenseFormat' => 'anchor',
				'Server' => 'https://eqlwiki.com', 'CanonicalServer' => 'https://eqlwiki.com',
				'DefaultRobotPolicy' => 'index,follow'
			], $this->options['config'] ?? [] );
			return new class( $values ) {
				public function __construct( private array $values ) {}
				public function get( string $key ): mixed { return $this->values[$key]; }
			};
		}
		public function getTitle(): ?object {
			if ( $this->options['missingTitle'] ?? false ) { return null; }
			return new class( $this->options ) {
				public function __construct( private array $options ) {}
				public function getNamespace(): int { return $this->options['namespace'] ?? 0; }
				public function exists(): bool { return $this->options['exists'] ?? true; }
				public function isRedirect(): bool { return $this->options['redirect'] ?? false; }
			};
		}
		public function getRequest(): object {
			return new class( $this->options['query'] ?? [] ) {
				public function __construct( private array $query ) {}
				public function getVal( string $key ): mixed { return $this->query[$key] ?? null; }
			};
		}
		public function getContext(): object {
			return new class( $this->options['action'] ?? 'view' ) {
				public function __construct( private string $action ) {}
				public function getActionName(): string { return $this->action; }
			};
		}
		public function isArticle(): bool { return $this->options['article'] ?? true; }
		public function isRevisionCurrent(): bool { return $this->options['current'] ?? true; }
		public function getRobotPolicy(): string { return $this->options['policy'] ?? 'index,follow'; }
	};
}

$checks = 0;
$valid = [ 'client' => 'ca-pub-1234567890123456', 'format' => 'anchor' ];
$check = static function ( array $options, bool $public, ?array $expected ) use ( &$checks ): void {
	$actual = EQLImmersive\Ads::configurationForOutput( fixture( $options ), $public );
	$checks++;
	if ( $actual !== $expected ) {
		throw new RuntimeException( 'Advertising eligibility failed: ' . json_encode( $options ) );
	}
};
$check( [], true, $valid );
$check( [], false, null );
foreach ( [
	[ 'missingTitle' => true ], [ 'namespace' => 1 ], [ 'namespace' => -1 ], [ 'namespace' => 2 ],
	[ 'exists' => false ], [ 'redirect' => true ], [ 'article' => false ], [ 'current' => false ],
	[ 'action' => 'edit' ], [ 'action' => 'history' ], [ 'action' => 'raw' ], [ 'action' => 'submit' ],
	[ 'query' => [ 'oldid' => '123' ] ], [ 'query' => [ 'oldid' => '0' ] ],
	[ 'query' => [ 'diff' => '' ] ], [ 'query' => [ 'veaction' => 'edit' ] ],
	[ 'policy' => 'noindex,follow' ], [ 'policy' => 'NOINDEX,nofollow' ]
] as $options ) { $check( $options, true, null ); }
foreach ( [
	[ 'EQLAdSenseClient' => '' ], [ 'EQLAdSenseFormat' => '' ], [ 'EQLAdSenseFormat' => 123 ],
	[ 'EQLAdSenseClient' => 'ca-pub-1234567890123456' . "\n" ],
	[ 'EQLAdSenseClient' => 'ca-pub-123' ], [ 'EQLAdSenseFormat' => 'display' ],
	[ 'EQLAdSenseFormat' => 'anchor' . "\n" ], [ 'EQLAdSenseFormat' => true ],
	[ 'EQLAdSenseFormat' => '', 'EQLAdSenseSlot' => '1234567890' ],
	[ 'Server' => 'https://test.eqlwiki.com' ], [ 'CanonicalServer' => 'https://test.eqlwiki.com' ],
	[ 'Server' => 'http://eqlwiki.com' ], [ 'Server' => 'http://127.0.0.1:8080' ],
	[ 'Server' => 'https://other.example' ], [ 'DefaultRobotPolicy' => 'noindex,follow' ],
	[ 'DefaultRobotPolicy' => [ 'index' => 'noindex', 'follow' => 'follow' ] ]
] as $values ) { $check( [ 'config' => $values ], true, null ); }

$staging = [
	'Server' => 'https://test.eqlwiki.com', 'CanonicalServer' => 'https://test.eqlwiki.com',
	'EQLAdSenseClient' => '', 'EQLAdSenseFormat' => '', 'DefaultRobotPolicy' => 'noindex,nofollow'
];
$preview = [ 'preview' => true ];
// Existing staging access policy remains authoritative: only an already rendered
// article receives a harmless preview, even if anonymous reading is disabled.
$check( [ 'config' => $staging, 'policy' => 'noindex,nofollow' ], false, $preview );
$check( [ 'config' => $staging ], true, $preview );
$check( [ 'config' => array_replace( $staging, [
	'EQLAdSenseClient' => $valid['client'], 'EQLAdSenseFormat' => $valid['format']
] ) ], false, $preview );
foreach ( [
	[ 'missingTitle' => true ], [ 'namespace' => 1 ], [ 'namespace' => -1 ], [ 'namespace' => 2 ],
	[ 'exists' => false ], [ 'redirect' => true ], [ 'article' => false ], [ 'current' => false ],
	[ 'action' => 'edit' ], [ 'action' => 'history' ], [ 'action' => 'raw' ], [ 'action' => 'submit' ],
	[ 'query' => [ 'oldid' => '123' ] ], [ 'query' => [ 'diff' => '' ] ],
	[ 'query' => [ 'veaction' => 'edit' ] ]
] as $options ) { $check( array_replace( $options, [ 'config' => $staging ] ), false, null ); }
foreach ( [
	[ 'Server' => 'http://test.eqlwiki.com' ], [ 'Server' => 'https://test.eqlwiki.com:443' ],
	[ 'Server' => 'https://www.test.eqlwiki.com' ], [ 'Server' => 'https://test.eqlwiki.com.evil.example' ],
	[ 'CanonicalServer' => 'https://eqlwiki.com' ], [ 'Server' => 'https://eqlwiki.com' ],
	[ 'CanonicalServer' => 'http://test.eqlwiki.com' ]
] as $values ) { $check( [ 'config' => array_replace( $staging, $values ) ], true, null ); }

// Staging's explicit URL rewrite list must not rewrite production allowlists.
$prepare = file_get_contents( __DIR__ . '/staging-prepare.php' );
if ( !preg_match( '/\$files\s*=\s*\[(.*?)\];/s', $prepare, $matches ) ||
	str_contains( $matches[1], 'includes/Ads.php' ) || str_contains( $matches[1], 'resources/ads.js' )
) { throw new RuntimeException( 'Advertising environment guards must stay outside staging URL substitution.' ); }
$checks++;

$manifest = json_decode( file_get_contents( __DIR__ . '/../skins/EQLImmersive/skin.json' ), true, 512, JSON_THROW_ON_ERROR );
foreach ( [ 'EQLAdSenseClient', 'EQLAdSenseFormat' ] as $setting ) {
	if ( $manifest['config'][$setting]['value'] !== '' ) { throw new RuntimeException( 'Advertising must default unconfigured.' ); }
	$checks++;
}
echo "$checks synthetic advertising eligibility checks passed; no live site tested.\n";
