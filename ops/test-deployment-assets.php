<?php
/** Source-only deployment asset URL/activation checks; no wiki, accounts or writes. */
declare( strict_types = 1 );

namespace MediaWiki\Output\Hook {
	interface OutputPageCheckLastModifiedHook {
		public function onOutputPageCheckLastModified( &$modifiedTimes, $out );
	}
}

namespace MediaWiki\Settings {
	final class SettingsBuilder {
		public array $overrides = [];
		public function __construct( public array $values ) {}
		public function getConfig(): object {
			return new class( $this ) {
				public function __construct( private SettingsBuilder $settings ) {}
				public function get( string $name ): mixed { return $this->settings->values[$name] ?? null; }
			};
		}
		public function overrideConfigValue( string $name, mixed $value ): self {
			$this->overrides[] = [ $name, $value ];
			$this->values[$name] = $value;
			return $this;
		}
	}
}

namespace {
	use EQLImmersive\DeploymentAssets;
	use MediaWiki\Settings\SettingsBuilder;

	require_once __DIR__ . '/../skins/EQLImmersive/includes/DeploymentAssets.php';
	$checks = 0;
	$expect = static function ( mixed $actual, mixed $expected, string $message ) use ( &$checks ): void {
		$checks++;
		if ( $actual !== $expected ) {
			throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
		}
	};
	$first = str_repeat( 'a', 40 );
	$second = str_repeat( 'b', 40 );
	foreach ( [
		'load.php' => 'eql-assets/' . $first . '/load.php',
		'/load.php' => '/eql-assets/' . $first . '/load.php',
		'/w/load.php' => '/w/eql-assets/' . $first . '/load.php',
		'https://wiki.example/w/load.php' => 'https://wiki.example/w/eql-assets/' . $first . '/load.php',
		'//wiki.example/load.php' => '//wiki.example/eql-assets/' . $first . '/load.php',
		'/w/eql-assets/' . $second . '/load.php' => '/w/eql-assets/' . $first . '/load.php'
	] as $input => $expected ) {
		$actual = DeploymentAssets::versionedLoadScript( $input, $first );
		$expect( $actual, $expected, 'Preserve the configured origin/install path and replace any previous release alias.' );
		$expect( DeploymentAssets::versionedLoadScript( $actual, $first ), $actual, 'The release URL transformation must be idempotent.' );
		$expect( strpbrk( $actual, '?#' ), false, 'The load endpoint remains query-free for ResourceLoader, Parsoid and mw.util consumers.' );
	}
	foreach ( [ '/load.php?custom=1', '/load.php?version=abcde', '/load.php#fragment', '/custom.php', '/load.php/extra', '/autoload.php', '/load.php.css', '' ] as $custom ) {
		$expect( DeploymentAssets::versionedLoadScript( $custom, $first ), $custom, 'Leave custom endpoints, queries and fragments unchanged.' );
	}
	foreach ( [ '', str_repeat( 'a', 39 ), str_repeat( 'a', 41 ), str_repeat( 'A', 40 ), '../load.php', $first . "\n" ] as $invalid ) {
		$expect( DeploymentAssets::versionedLoadScript( '/load.php', $invalid ), '/load.php', 'An invalid release identifier must not alter the configured endpoint.' );
	}

	$marker = tempnam( sys_get_temp_dir(), 'eql-asset-fixture-' );
	if ( $marker === false ) { throw new RuntimeException( 'Cannot create a synthetic release marker.' ); }
	$base = [ 'LoadScript' => '/w/load.php', 'CookiePrefix' => 'synthetic_',
		'WhitelistRead' => [ 'Synthetic Public Page' ], 'ResourceLoaderStorageVersion' => 7,
		'ResourceLoaderMaxage' => [ 'unversioned' => 300, 'versioned' => 2592000 ] ];
	$modifiedTimes = [ 'page' => '20200101000000', 'user' => '20200102000000', 'epoch' => '20190101000000' ];
	$handler = new DeploymentAssets();
	try {
		$settings = new SettingsBuilder( $base );
		DeploymentAssets::configure( $settings, $marker . '.missing' );
		$times = $modifiedTimes;
		$handler->onOutputPageCheckLastModified( $times, new stdClass() );
		$expect( [ $settings->values, $settings->overrides, $times ], [ $base, [], $modifiedTimes ], 'Missing marker leaves URLs, unrelated configuration and page cache timestamps unchanged.' );
		foreach ( [ '', str_repeat( 'a', 39 ), str_repeat( 'A', 40 ), $first . "\n\n", $first . ' extra', $first . "\0", str_repeat( 'b', 256 ) ] as $invalid ) {
			file_put_contents( $marker, $invalid );
			clearstatcache( true, $marker );
			$settings = new SettingsBuilder( $base );
			DeploymentAssets::configure( $settings, $marker );
			$times = $modifiedTimes;
			$handler->onOutputPageCheckLastModified( $times, new stdClass() );
			$expect( [ $settings->values, $settings->overrides, $times ], [ $base, [], $modifiedTimes ], 'Malformed or oversized marker never activates a URL or timestamp override.' );
		}
		foreach ( [ $first, $first . "\n", $first . "\r\n" ] as $valid ) {
			file_put_contents( $marker, $valid );
			// A restored archive can have an older mtime than its activation ctime.
			touch( $marker, time() - 86400 );
			clearstatcache( true, $marker );
			$stat = stat( $marker );
			$activation = gmdate( 'YmdHis', max( $stat['mtime'], $stat['ctime'] ) );
			$settings = new SettingsBuilder( $base );
			DeploymentAssets::configure( $settings, $marker );
			$times = $modifiedTimes;
			$handler->onOutputPageCheckLastModified( $times, new stdClass() );
			$expected = $base; $expected['LoadScript'] = '/w/eql-assets/' . $first . '/load.php';
			$expect( $settings->values, $expected, 'Only LoadScript changes; authentication boundaries and native ResourceLoader versions/cache settings remain untouched.' );
			$expect( $settings->overrides, [ [ 'LoadScript', $expected['LoadScript'] ] ], 'A valid archive marker activates exactly one supported config override.' );
			$expect( $times, $modifiedTimes + [ 'eql-deployment' => $activation ], 'Activation adds max(mtime,ctime) in MediaWiki timestamp form without replacing page/user/epoch timestamps.' );
			DeploymentAssets::configure( $settings, $marker );
			$again = $modifiedTimes;
			$handler->onOutputPageCheckLastModified( $again, new stdClass() );
			$expect( [ $settings->values, $settings->overrides, $again ], [ $expected, [ [ 'LoadScript', $expected['LoadScript'] ] ], $times ], 'Repeated configuration of the same release must retain activation protection without duplicate overrides.' );
		}
		$settings = new SettingsBuilder( $base );
		file_put_contents( $marker, $first . "\n" );
		clearstatcache( true, $marker );
		DeploymentAssets::configure( $settings, $marker );
		$firstUrl = $settings->values['LoadScript'];
		file_put_contents( $marker, $second . "\n" );
		clearstatcache( true, $marker );
		DeploymentAssets::configure( $settings, $marker );
		$expect( $settings->values['LoadScript'], '/w/eql-assets/' . $second . '/load.php', 'A new archive release changes the asset cache path without nesting aliases.' );
		$expect( $settings->values['LoadScript'] !== $firstUrl && !str_contains( $settings->values['LoadScript'], 'version=' ), true, 'The release cache key changes independently of ResourceLoader\'s reserved version query.' );
		$settings = new SettingsBuilder( array_replace( $base, [ 'LoadScript' => '/load.php?custom=1' ] ) );
		$before = $settings->values;
		DeploymentAssets::configure( $settings, $marker );
		$times = $modifiedTimes;
		$handler->onOutputPageCheckLastModified( $times, new stdClass() );
		$expect( [ $settings->values, $settings->overrides, $times ], [ $before, [], $modifiedTimes ], 'Unsupported custom endpoint disables activation handling as well as URL modification.' );
	} finally {
		unlink( $marker );
	}

	$manifest = json_decode( file_get_contents( __DIR__ . '/../skins/EQLImmersive/skin.json' ), true, 512, JSON_THROW_ON_ERROR );
	$expect( $manifest['callback'] ?? null, DeploymentAssets::class . '::onRegistration', 'The active skin invokes the deployment asset callback during configuration.' );
	$handlerNames = (array)( $manifest['Hooks']['OutputPageCheckLastModified'] ?? [] );
	$handlerClasses = array_map( static fn ( $name ) => $manifest['HookHandlers'][$name]['class'] ?? null, $handlerNames );
	$expect( in_array( DeploymentAssets::class, $handlerClasses, true ), true, 'The active skin retains activation timestamps alongside other page-cache hook handlers.' );
	$expect( $manifest['AutoloadNamespaces']['EQLImmersive\\'] ?? null, 'includes/', 'The callback and hook class use the registered skin namespace.' );
	echo "Deployment assets: {$checks} source-only URL, activation, configuration and registration checks passed.\n";
}
