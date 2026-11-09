<?php

namespace EQLImmersive;

/** Publishes real production units or a synthetic staging placement preview. */
final class Ads {
	public static function isPreviewEnvironment( $config ): bool {
		return $config->get( 'Server' ) === 'https://test.eqlwiki.com' &&
			$config->get( 'CanonicalServer' ) === 'https://test.eqlwiki.com';
	}

	/** @return array{client:string,slot:string}|array{preview:true}|null */
	public static function configurationForOutput( $out, bool $publicRead ): ?array {
		$config = $out->getConfig();
		$server = $config->get( 'Server' );
		$canonical = $config->get( 'CanonicalServer' );
		$preview = self::isPreviewEnvironment( $config );
		$production = $server === 'https://eqlwiki.com' && $canonical === $server;
		if ( !$preview && !$production ) {
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
		// The existing staging gate controls who can view the article. Its
		// noindex/private policy stays intact; a preview exposes no provider IDs.
		if ( $preview ) {
			return [ 'preview' => true ];
		}
		$client = $config->get( 'EQLAdSenseClient' );
		$slot = $config->get( 'EQLAdSenseSlot' );
		$policy = $config->get( 'DefaultRobotPolicy' );
		$policy = is_array( $policy ) ? implode( ',', $policy ) : (string)$policy;
		if ( !$publicRead || !is_string( $client ) || !preg_match( '/\Aca-pub-[0-9]{16}\z/', $client ) ||
			!is_string( $slot ) || !preg_match( '/\A[0-9]{1,20}\z/', $slot ) ||
			preg_match( '/\bnoindex\b/i', $policy . ',' . $out->getRobotPolicy() )
		) {
			return null;
		}
		return [ 'client' => $client, 'slot' => $slot ];
	}
}
