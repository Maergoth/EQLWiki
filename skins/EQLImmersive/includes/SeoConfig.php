<?php

namespace EQLImmersive;

use MediaWiki\Settings\SettingsBuilder;

/** Site SEO defaults, applied after the environment's private settings load. */
class SeoConfig {

	public static function onRegistration( array $credits, SettingsBuilder $settings ): void {
		$config = $settings->getConfig();
		$server = rtrim( (string)$config->get( 'Server' ), '/' );
		$canonicalServer = rtrim( (string)$config->get( 'CanonicalServer' ), '/' );
		$values = [ 'EnableCanonicalServerLink' => true ];

		// A staging clone must never canonicalize its articles to production.
		// Use trusted wiki configuration, not the incoming Host header.
		if ( $server === 'https://test.eqlwiki.com' ) {
			$canonicalServer = $server;
			$values['CanonicalServer'] = $server;
		}

		$policy = $config->get( 'DefaultRobotPolicy' );
		$policy = is_array( $policy ) ? implode( ',', $policy ) : (string)$policy;
		$sitemap = $config->get( 'SitemapApiConfig' );
		$sitemap['enabled'] = $server === 'https://eqlwiki.com' &&
			$canonicalServer === 'https://eqlwiki.com' &&
			!preg_match( '/\bnoindex\b/i', $policy );
		$values['SitemapApiConfig'] = $sitemap;
		// Article discovery should not enumerate users, files, or interface pages.
		$values['SitemapNamespaces'] = [ 0 ];
		$settings->overrideConfigValues( $values );
	}

	/** Public robots response; unknown hosts fail closed, including local clones. */
	public static function robotsText( string $host ): string {
		if ( !preg_match( '/\Aeqlwiki\.com(?::(?:80|443))?\z/i', $host ) ) {
			return "User-agent: *\nDisallow: /\n";
		}

		return "User-agent: *\nAllow: /\n\n" .
			"Sitemap: https://eqlwiki.com/rest.php/site/v1/sitemap/0\n";
	}
}
