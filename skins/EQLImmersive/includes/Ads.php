<?php

namespace EQLImmersive;

/** Publishes an optional unit only on current, publicly readable production articles. */
final class Ads {
	/** @return array{client:string,slot:string}|null */
	public static function configurationForOutput( $out, bool $publicRead ): ?array {
		$config = $out->getConfig();
		$client = $config->get( 'EQLAdSenseClient' );
		$slot = $config->get( 'EQLAdSenseSlot' );
		if ( !is_string( $client ) || !preg_match( '/\Aca-pub-[0-9]{16}\z/', $client ) ||
			!is_string( $slot ) || !preg_match( '/\A[0-9]{1,20}\z/', $slot ) ||
			$config->get( 'Server' ) !== 'https://eqlwiki.com' ||
			$config->get( 'CanonicalServer' ) !== 'https://eqlwiki.com'
		) {
			return null;
		}
		$policy = $config->get( 'DefaultRobotPolicy' );
		$policy = is_array( $policy ) ? implode( ',', $policy ) : (string)$policy;
		$title = $out->getTitle();
		$request = $out->getRequest();
		if ( !$publicRead || !$title || $title->getNamespace() !== NS_MAIN ||
			!$title->exists() || $title->isRedirect() || !$out->isArticle() ||
			!$out->isRevisionCurrent() || $out->getContext()->getActionName() !== 'view' ||
			preg_match( '/\bnoindex\b/i', $policy . ',' . $out->getRobotPolicy() ) ||
			$request->getVal( 'oldid' ) !== null || $request->getVal( 'diff' ) !== null ||
			$request->getVal( 'veaction' ) !== null
		) {
			return null;
		}
		return [ 'client' => $client, 'slot' => $slot ];
	}
}
