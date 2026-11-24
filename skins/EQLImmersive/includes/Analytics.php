<?php

namespace EQLImmersive;

/** Independent measurement configuration for current, public reading views. */
final class Analytics {
	/** Compatibility for a caller awaiting replacement during file-by-file deployment. */
	public static function configurationForOutput( $out, ?array $ads, bool $publicRead ): ?array {
		return self::independentConfigurationForOutput( $out, $publicRead );
	}

	/** First-party choice only; safe on policy, account and other excluded pages. */
	public static function preferencesForOutput( $out ): ?array {
		$settings = self::siteConfiguration( $out );
		return $settings ? [ 'origin' => $settings['origin'] ] : null;
	}

	private static function siteConfiguration( $out ): ?array {
		$config = $out->getConfig();
		$origin = 'https://eqlwiki.com';
		if ( $config->get( 'Server' ) !== $origin || $config->get( 'CanonicalServer' ) !== $origin
		) {
			return null;
		}
		foreach ( [ 'EQLAnalyticsMeasurementId', 'EQLAnalyticsConsentPublisher' ] as $key ) {
			if ( method_exists( $config, 'has' ) && !$config->has( $key ) ) { return null; }
		}
		$id = $config->get( 'EQLAnalyticsMeasurementId' );
		$publisher = $config->get( 'EQLAnalyticsConsentPublisher' );
		if ( !is_string( $id ) || !preg_match( '/\AG-[A-Z0-9]{6,20}\z/', $id ) ||
			!is_string( $publisher ) || !preg_match( '/\Apub-[0-9]{16}\z/', $publisher )
		) {
			return null;
		}
		return [ 'measurementId' => $id, 'origin' => $origin, 'consentPublisher' => $publisher ];
	}

	public static function independentConfigurationForOutput( $out, bool $publicRead ): ?array {
		$settings = self::siteConfiguration( $out );
		if ( !$publicRead || !$settings ) { return null; }
		$config = $out->getConfig();
		// Use the public article's canonical URL, never the request's query string,
		// fragment, logged-in identity, edit context or a visitor's search terms.
		$title = $out->getTitle();
		$request = $out->getRequest();
		if ( !$title || !in_array( $title->getNamespace(), [ NS_MAIN, NS_CATEGORY, NS_HELP ], true ) ||
			!$title->exists() || $title->isRedirect() || !$out->isArticle() ||
			!$out->isRevisionCurrent() || $out->getContext()->getActionName() !== 'view' ||
			$request->getVal( 'oldid' ) !== null || $request->getVal( 'diff' ) !== null ||
			$request->getVal( 'veaction' ) !== null
		) {
			return null;
		}
		$policy = $config->get( 'DefaultRobotPolicy' );
		$policy = is_array( $policy ) ? implode( ',', $policy ) : (string)$policy;
		if ( preg_match( '/\bnoindex\b/i', $policy . ',' . $out->getRobotPolicy() ) ) {
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
		return $settings + [
			'pageLocation' => $location, 'pageTitle' => $title->getPrefixedText(),
			'pageNamespace' => $title->getNamespace() ];
	}
}
