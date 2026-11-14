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
				'EQLAdSenseStagingClient' => '', 'EQLAdSenseStagingFormat' => '', 'EQLAdSenseStagingSidebarSlot' => '', 'EQLAdSenseStagingBannerSlot' => '',
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
$valid = [ 'client' => 'ca-pub-1234567890123456', 'format' => 'anchor', 'environment' => 'production' ];
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
$preview = [ 'preview' => true, 'manual' => true ];
// Existing staging access policy remains authoritative: only an already rendered
// article receives a harmless preview, even if anonymous reading is disabled.
$check( [ 'config' => $staging, 'policy' => 'noindex,nofollow' ], false, $preview );
$check( [ 'config' => $staging ], true, $preview );
$check( [ 'config' => array_replace( $staging, [
	'EQLAdSenseClient' => $valid['client'], 'EQLAdSenseFormat' => $valid['format']
] ) ], false, $preview );
$stagingProvider = [ 'client' => 'ca-pub-9876543210987654', 'format' => 'anchor', 'environment' => 'staging' ];
$stagingSettings = [
	'EQLAdSenseStagingClient' => $stagingProvider['client'], 'EQLAdSenseStagingFormat' => 'anchor'
];
$configuredStaging = array_replace( $staging, $stagingSettings );
// Explicit staging provider settings do not open the existing access gate or
// remove noindex. Only the authorized rendered article receives this config.
$check( [ 'config' => $configuredStaging, 'policy' => 'noindex,nofollow' ], false, $stagingProvider );
$check( [ 'config' => array_replace( $configuredStaging, [
	'EQLAdSenseClient' => $valid['client'], 'EQLAdSenseFormat' => 'anchor'
] ) ], true, $stagingProvider );
// Staging settings cannot configure production, even when copied there.
$check( [ 'config' => array_replace( $stagingSettings, [
	'EQLAdSenseClient' => '', 'EQLAdSenseFormat' => ''
] ) ], true, null );
$check( [ 'config' => $stagingSettings ], true, $valid );
foreach ( [
	[ 'EQLAdSenseStagingClient' => '' ], [ 'EQLAdSenseStagingFormat' => '' ],
	[ 'EQLAdSenseStagingClient' => 123 ], [ 'EQLAdSenseStagingClient' => 'ca-pub-123' ],
	[ 'EQLAdSenseStagingClient' => $stagingProvider['client'] . "\n" ],
	[ 'EQLAdSenseStagingFormat' => 'display' ], [ 'EQLAdSenseStagingFormat' => true ],
	[ 'EQLAdSenseStagingFormat' => 'anchor' . "\n" ]
] as $values ) {
	$check( [ 'config' => array_replace( $configuredStaging, $values ) ], false, $preview );
}
foreach ( [
	[ 'missingTitle' => true ], [ 'namespace' => 1 ], [ 'namespace' => -1 ], [ 'namespace' => 2 ],
	[ 'exists' => false ], [ 'redirect' => true ], [ 'article' => false ], [ 'current' => false ],
	[ 'action' => 'edit' ], [ 'action' => 'history' ], [ 'action' => 'raw' ], [ 'action' => 'submit' ],
	[ 'query' => [ 'oldid' => '123' ] ], [ 'query' => [ 'diff' => '' ] ],
	[ 'query' => [ 'veaction' => 'edit' ] ]
] as $options ) {
	$check( array_replace( $options, [ 'config' => $staging ] ), false, null );
	$check( array_replace( $options, [ 'config' => $configuredStaging ] ), false, null );
}
foreach ( [
	[ 'Server' => 'http://test.eqlwiki.com' ], [ 'Server' => 'https://test.eqlwiki.com:443' ],
	[ 'Server' => 'https://www.test.eqlwiki.com' ], [ 'Server' => 'https://test.eqlwiki.com.evil.example' ],
	[ 'CanonicalServer' => 'https://eqlwiki.com' ], [ 'Server' => 'https://eqlwiki.com' ],
	[ 'CanonicalServer' => 'http://test.eqlwiki.com' ]
] as $values ) {
	$check( [ 'config' => array_replace( $staging, $values ) ], true, null );
	$check( [ 'config' => array_replace( $configuredStaging, $values ) ], true, null );
}

// Layout serving is an independent explicit staging setting, never a production fallback.
$layoutSettings = array_replace( $configuredStaging, [ 'EQLAdSenseStagingFormat' => 'layouts', 'EQLAdSenseStagingSidebarSlot' => '1234567890' ] );
$layoutProvider = array_replace( $stagingProvider, [ 'format' => 'layouts', 'sidebarSlot' => '1234567890' ] );
$check( [ 'config' => $layoutSettings ], false, $layoutProvider );
foreach ( [ '', '123', '1234567890' . "\n", 1234567890, false ] as $invalid ) {
    $check( [ 'config' => array_replace( $layoutSettings, [ 'EQLAdSenseStagingSidebarSlot' => $invalid ] ) ], false, $preview );
}
$check( [ 'config' => [ 'EQLAdSenseFormat' => 'layouts', 'EQLAdSenseStagingSidebarSlot' => '1234567890' ] ], true, null );
$check( [ 'config' => $layoutSettings, 'namespace' => 1 ], false, null );
$check( [ 'config' => $layoutSettings, 'action' => 'edit' ], false, null );
$manualSettings = array_replace( $layoutSettings, [ 'EQLAdSenseStagingFormat' => 'manual-layouts', 'EQLAdSenseStagingBannerSlot' => '0987654321' ] );
$manualProvider = array_replace( $layoutProvider, [ 'format' => 'manual-layouts', 'bannerSlot' => '0987654321' ] );
$check( [ 'config' => $manualSettings ], false, $manualProvider );
foreach ( [ '', '123', '0987654321' . "\n", 987654321, false ] as $invalid ) {
    $check( [ 'config' => array_replace( $manualSettings, [ 'EQLAdSenseStagingBannerSlot' => $invalid ] ) ], false, $preview );
}
$check( [ 'config' => array_replace( $manualSettings, [ 'EQLAdSenseStagingSidebarSlot' => '' ] ) ], false, $preview );
$check( [ 'config' => [ 'EQLAdSenseFormat' => 'manual-layouts' ] ], true, null );
$check( [ 'config' => $manualSettings, 'namespace' => 1 ], false, null );
$check( [ 'config' => $manualSettings, 'action' => 'edit' ], false, null );

// Staging's explicit URL rewrite list must not rewrite production allowlists.
$prepare = file_get_contents( __DIR__ . '/staging-prepare.php' );
if ( !preg_match( '/\$files\s*=\s*\[(.*?)\];/s', $prepare, $matches ) ||
	str_contains( $matches[1], 'includes/Ads.php' ) || str_contains( $matches[1], 'resources/ads.js' )
) { throw new RuntimeException( 'Advertising environment guards must stay outside staging URL substitution.' ); }
$checks++;

// Refresh must retain a separate optional private ads include after the existing
// Talk policy and before writing generated LocalSettings. Never run a refresh here.
$refresh = file_get_contents( __DIR__ . '/staging-refresh.php' );
$talkInclude = strpos( $refresh, '$talkSettings = var_export(' );
$adsInclude = strpos( $refresh, '$adsSettings = var_export("$state/ads-settings.php", true);' );
$settingsWrite = strpos( $refresh, 'file_put_contents("$root/LocalSettings.php", $wiki);' );
if ( $talkInclude === false || $adsInclude === false || $settingsWrite === false ||
	$talkInclude >= $adsInclude || $adsInclude >= $settingsWrite ||
	!str_contains( substr( $refresh, $adsInclude, $settingsWrite - $adsInclude ),
		'if (is_file($adsSettings)) { require_once $adsSettings; }' )
) { throw new RuntimeException( 'Staging refresh must preserve the separate optional private ads settings.' ); }
$checks++;

$manifest = json_decode( file_get_contents( __DIR__ . '/../skins/EQLImmersive/skin.json' ), true, 512, JSON_THROW_ON_ERROR );
foreach ( [ 'EQLAdSenseClient', 'EQLAdSenseFormat', 'EQLAdSenseStagingClient', 'EQLAdSenseStagingFormat', 'EQLAdSenseStagingSidebarSlot', 'EQLAdSenseStagingBannerSlot' ] as $setting ) {
	if ( $manifest['config'][$setting]['value'] !== '' ) { throw new RuntimeException( 'Advertising must default unconfigured.' ); }
	$checks++;
}
echo "$checks synthetic advertising eligibility checks passed; no live site tested.\n";
