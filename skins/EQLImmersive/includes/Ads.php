<?php

namespace EQLImmersive;

/** Publishes independently configured native anchors or a staging dock preview. */
final class Ads {
	public static function isStagingEnvironment( $config ): bool {
		return $config->get( 'Server' ) === 'https://test.eqlwiki.com' &&
			$config->get( 'CanonicalServer' ) === 'https://test.eqlwiki.com';
	}

	/** @return array{client:string,format:string,environment:string}|array{preview:true}|null */
	public static function configurationForOutput( $out, bool $publicRead ): ?array {
		$config = $out->getConfig();
		$server = $config->get( 'Server' );
		$canonical = $config->get( 'CanonicalServer' );
		$staging = self::isStagingEnvironment( $config );
		$production = $server === 'https://eqlwiki.com' && $canonical === $server;
		if ( !$staging && !$production ) {
			return null;
		}
		$title = $out->getTitle();
		$request = $out->getRequest();
		if ( !$title || $title->getNamespace() !== NS_MAIN ||
			!$title->exists() || $title->isRedirect() || !$out->isArticle() ||
			!$out->isRevisionCurrent() || $out->getContext()->getActionName() !== 'view' ||
			$request->getVal( 'oldid' ) !== null || $request->getVal( 'diff' ) !== null ||
			$request->getVal( 'veaction' ) !== null
		) {
			return null;
		}
		// The existing staging gate controls who can view the article. Keep its
		// noindex/private policy; only separate, explicit staging settings load ads.
		if ( $staging ) {
			return self::anchorConfiguration( $config, 'EQLAdSenseStaging', 'staging' ) ??
				[ 'preview' => true ];
		}
		$policy = $config->get( 'DefaultRobotPolicy' );
		$policy = is_array( $policy ) ? implode( ',', $policy ) : (string)$policy;
		if ( !$publicRead ||
			preg_match( '/\bnoindex\b/i', $policy . ',' . $out->getRobotPolicy() )
		) {
			return null;
		}
		return self::anchorConfiguration( $config, 'EQLAdSense', 'production' );
	}

	private static function anchorConfiguration( $config, string $prefix, string $environment ): ?array {
		$client = $config->get( $prefix . 'Client' );
		$format = $config->get( $prefix . 'Format' );
		if ( !is_string( $client ) || !preg_match( '/\Aca-pub-[0-9]{16}\z/', $client ) ||
			$format !== 'anchor'
		) {
			return null;
		}
		return [ 'client' => $client, 'format' => $format, 'environment' => $environment ];
	}
}
