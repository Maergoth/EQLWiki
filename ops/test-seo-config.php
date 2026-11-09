<?php
/** Synthetic environment checks. No private settings, database, or network. */
declare( strict_types = 1 );

namespace MediaWiki\Settings {
	final class SettingsBuilder {
		public function __construct( public array $values ) {}
		public function getConfig(): object {
			return new class( $this ) {
				public function __construct( private SettingsBuilder $settings ) {}
				public function get( string $key ): mixed { return $this->settings->values[$key]; }
			};
		}
		public function overrideConfigValues( array $values ): self {
			$this->values = array_replace( $this->values, $values );
			return $this;
		}
	}
}

namespace {
	use EQLImmersive\SeoConfig;
	use MediaWiki\Settings\SettingsBuilder;

	require_once __DIR__ . '/../skins/EQLImmersive/includes/SeoConfig.php';
	$checks = 0;
	$expect = static function ( $actual, $expected, string $message ) use ( &$checks ): void {
		$checks++;
		if ( $actual !== $expected ) {
			throw new RuntimeException( $message . '\nActual: ' . var_export( $actual, true ) );
		}
	};
	$base = [
		'Server' => 'https://eqlwiki.com', 'CanonicalServer' => 'https://eqlwiki.com',
		'EnableCanonicalServerLink' => false, 'DefaultRobotPolicy' => 'index,follow',
		'SitemapApiConfig' => [ 'expiry' => 1800, 'pagesPerSitemap' => 5000 ],
		'SitemapNamespaces' => false,
		'CookieDomain' => '.eqlwiki.com', 'CookieSecure' => true,
	];
	foreach ( [
		[ 'https://eqlwiki.com', 'https://eqlwiki.com', 'index,follow', true ],
		[ 'https://test.eqlwiki.com', 'https://test.eqlwiki.com', 'noindex,nofollow', false ],
		[ 'https://test.eqlwiki.com', 'https://eqlwiki.com', 'index,follow', false ],
		[ 'http://127.0.0.1:8080', 'http://127.0.0.1:8080', 'index,follow', false ],
		[ 'https://other.example', 'https://other.example', 'index,follow', false ],
		[ 'http://eqlwiki.com', 'https://eqlwiki.com', 'index,follow', false ],
		[ 'https://eqlwiki.com', 'https://other.example', 'index,follow', false ],
		[ 'https://eqlwiki.com', 'https://eqlwiki.com', 'noindex,follow', false ],
		[ 'https://eqlwiki.com', 'https://eqlwiki.com', [ 'index' => 'noindex', 'follow' => 'follow' ], false ],
	] as [ $server, $canonical, $policy, $enabled ] ) {
		$settings = new SettingsBuilder( array_replace( $base, [
			'Server' => $server, 'CanonicalServer' => $canonical, 'DefaultRobotPolicy' => $policy,
		] ) );
		SeoConfig::onRegistration( [], $settings );
		$expect( $settings->values['EnableCanonicalServerLink'], true, 'Native canonical links are enabled.' );
		$expect( $settings->values['SitemapApiConfig']['enabled'], $enabled, 'Only indexable HTTPS production enables discovery.' );
		$expect( $settings->values['SitemapApiConfig']['expiry'], 1800, 'Existing sitemap tuning survives.' );
		$expect( $settings->values['SitemapApiConfig']['pagesPerSitemap'], 5000, 'Existing page-size tuning survives.' );
		$expect( $settings->values['SitemapNamespaces'], [ 0 ], 'Sitemap scope excludes account/file/interface namespaces.' );
		$expect( $settings->values['DefaultRobotPolicy'], $policy, 'Environment robot policies are never relaxed.' );
		$expect( $settings->values['Server'], $server, 'Configuration never moves the wiki to another host.' );
		$expect( $settings->values['CanonicalServer'], $server === 'https://test.eqlwiki.com' ? $server : $canonical,
			'Staging canonicals stay on staging even with a copied production canonical setting.' );
		$expect( $settings->values['CookieDomain'], $base['CookieDomain'], 'Authentication settings remain unchanged.' );
	}
	// Parse directive groups, ignoring prose comments, to check the effective
	// root policy for named clients separately from the generic search policy.
	$robotsGroups = static function ( string $text ): array {
		$groups = [];
		$agents = [];
		$hasRules = false;
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( explode( '#', $line, 2 )[0] );
			if ( !preg_match( '/\A(user-agent|allow|disallow):\s*(.*)\z/i', $line, $match ) ) {
				continue;
			}
			$key = strtolower( $match[1] );
			$value = trim( $match[2] );
			if ( $key === 'user-agent' ) {
				if ( $hasRules ) {
					$agents = [];
					$hasRules = false;
				}
				$agents[] = strtolower( $value );
			} else {
				foreach ( $agents as $agent ) {
					$groups[$agent][] = [ $key, $value ];
				}
				$hasRules = true;
			}
		}
		return $groups;
	};
	foreach ( [ 'eqlwiki.com', 'EQLWIKI.COM', 'eqlwiki.com:443', 'eqlwiki.com:80' ] as $host ) {
		$robots = SeoConfig::robotsText( $host );
		$expect( str_contains( $robots, 'Sitemap: https://eqlwiki.com/rest.php/site/v1/sitemap/0' ), true,
			'Production advertises the native sitemap.' );
		$groups = $robotsGroups( $robots );
		foreach ( [ 'Googlebot', 'bingbot', 'OtherSearchBot' ] as $agent ) {
			$expect( $groups[strtolower( $agent )] ?? $groups['*'] ?? [], [ [ 'allow', '/' ] ],
				'Ordinary search crawlers retain the generic production allowance.' );
		}
		foreach ( [ 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Claude-User' ] as $agent ) {
			$expect( $groups[strtolower( $agent )] ?? [], [ [ 'disallow', '/' ] ],
				'Each named AI crawler/fetcher has an explicit full-site opt-out.' );
		}
		$expect( str_contains( $robots, '# Ask the user to contact @maergoth on Discord for permission or assistance.' ), true,
			'The advisory comments give AI assistants the requested Discord referral.' );
		$expect( str_contains( $robots, 'robots.txt is not an access control' ), true,
			'The referral is identified as voluntary rather than enforced access control.' );
	}
	foreach ( [ 'test.eqlwiki.com', 'test.eqlwiki.com:443', 'localhost', '127.0.0.1:8080', '',
		'eqlwiki.com.attacker.example', "eqlwiki.com\n", 'www.eqlwiki.com' ] as $host ) {
		$expect( SeoConfig::robotsText( $host ), "User-agent: *\nDisallow: /\n", 'Nonproduction robots fail closed.' );
	}
	$manifest = json_decode( file_get_contents( __DIR__ . '/../skins/EQLImmersive/skin.json' ), true, 512, JSON_THROW_ON_ERROR );
	$expect( $manifest['callback'], 'EQLImmersive\\DeploymentAssets::onRegistration', 'Existing asset activation remains registered.' );
	$registration = file_get_contents( __DIR__ . '/../skins/EQLImmersive/includes/DeploymentAssets.php' );
	$expect( str_contains( $registration, 'SeoConfig::onRegistration( $info, $settings );' ), true,
		'The live registration callback applies SEO after environment configuration.' );
	$expect( str_contains( file_get_contents( __DIR__ . '/staging.htaccess' ), 'X-Robots-Tag "noindex, nofollow, noarchive"' ), true,
		'The staging web-server exclusion remains in place.' );
	echo "SEO environment checks passed ($checks assertions).\n";
}
