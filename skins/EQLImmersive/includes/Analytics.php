<?php

namespace EQLImmersive;

/** Measurement configuration is published only for the existing public ad cohort. */
final class Analytics {
	public static function configurationForOutput( $out, ?array $ads, bool $publicRead ): ?array {
		$config = $out->getConfig();
		$origin = 'https://eqlwiki.com';
		if ( !$publicRead || !$ads || !empty( $ads['preview'] ) ||
			( $ads['environment'] ?? null ) !== 'production' ||
			$config->get( 'Server' ) !== $origin || $config->get( 'CanonicalServer' ) !== $origin
		) {
			return null;
		}
		if ( method_exists( $config, 'has' ) && !$config->has( 'EQLAnalyticsMeasurementId' ) ) {
			return null;
		}
		$id = $config->get( 'EQLAnalyticsMeasurementId' );
		if ( !is_string( $id ) || !preg_match( '/\AG-[A-Z0-9]{6,20}\z/', $id ) ) {
			return null;
		}
		// Use the public article's canonical URL, never the request's query string,
		// fragment, logged-in identity, edit context or a visitor's search terms.
		$title = $out->getTitle();
		if ( !$title || $title->getNamespace() !== NS_MAIN ) {
			return null;
		}
		$location = $title->getCanonicalURL();
		$parts = parse_url( $location );
		if ( !$parts || ( $parts['scheme'] ?? '' ) !== 'https' ||
			( $parts['host'] ?? '' ) !== 'eqlwiki.com' ||
			isset( $parts['port'] ) ||
			isset( $parts['user'] ) || isset( $parts['pass'] ) ||
			isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
			!isset( $parts['path'] ) || !str_starts_with( $parts['path'], '/' )
		) {
			return null;
		}
		return [ 'measurementId' => $id, 'origin' => $origin,
			'pageLocation' => $location, 'pageTitle' => $title->getPrefixedText() ];
	}
}
