<?php

namespace EQLImmersive;

/** One concise search brand, with the site's established alternate spellings. */
final class SiteIdentity {

	public const PREFERRED_NAME = 'EQ Legends Wiki';
	public const ALTERNATE_NAMES = [
		'EQLegends Wiki', 'EverQuest Legends Wiki', 'EQ Legends', 'EQLegends',
	];

	/** Shared eligibility for metadata, not a change to robots or read permissions. */
	public static function isEligible( $out, bool $publicRead ): bool {
		$title = $out->getTitle();
		if ( !$publicRead || !$title || $title->getNamespace() !== NS_MAIN ||
			!$title->exists() || $title->isRedirect() || !$out->isArticle() ||
			!$out->isRevisionCurrent() || $out->getContext()->getActionName() !== 'view'
		) {
			return false;
		}
		$request = $out->getRequest();
		foreach ( [ 'oldid', 'diff', 'veaction' ] as $parameter ) {
			if ( $request->getVal( $parameter ) !== null ) {
				return false;
			}
		}
		return true;
	}

	/** URLs come only from matching trusted wiki settings, never request headers. */
	public static function canonicalOrigin( $out ): ?string {
		$config = $out->getConfig();
		$server = rtrim( (string)$config->get( 'Server' ), '/' );
		$canonical = rtrim( (string)$config->get( 'CanonicalServer' ), '/' );
		if ( !in_array( $server, [ 'https://eqlwiki.com', 'https://test.eqlwiki.com' ], true ) ||
			$canonical !== $server
		) {
			return null;
		}
		return $server;
	}

	public static function addToOutput( $out, bool $publicRead ): void {
		if ( !self::isEligible( $out, $publicRead ) || !$out->getTitle()->isMainPage() ) {
			return;
		}
		$origin = self::canonicalOrigin( $out );
		if ( $origin === null ) {
			return;
		}
		// OutputPage stores plain text and escapes it when rendering <title>.
		$out->setHTMLTitle( self::PREFERRED_NAME . ' - Items, NPCs and Zones' );
		$description = 'EQ Legends Wiki (EQLegends Wiki): item, NPC and zone references ' .
			'for EverQuest Legends, plus quests, class guides and player tools.';
		$hasDescription = false;
		foreach ( $out->getMetaTags() as [ $name, $value ] ) {
			$hasDescription = $hasDescription || strtolower( $name ) === 'description';
		}
		if ( !$hasDescription ) {
			$out->addMeta( 'description', $description );
		}
		$heads = $out->getHeadItemsArray();
		foreach ( $heads as $key => $head ) {
			if ( $key !== 'eql-site-identity' && self::containsWebsiteData( $head ) ) {
				return;
			}
		}
		$data = [
			'@context' => 'https://schema.org',
			'@type' => 'WebSite',
			'@id' => $origin . '/#website',
			'url' => $origin . '/',
			'name' => self::PREFERRED_NAME,
			'alternateName' => self::ALTERNATE_NAMES,
			'description' => $description,
			'inLanguage' => 'en',
		];
		$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR );
		$out->addHeadItem( 'eql-site-identity', '<script type="application/ld+json">' . $json . '</script>' );
	}

	/** Respect another installed provider's existing WebSite node. */
	private static function containsWebsiteData( string $head ): bool {
		preg_match_all( '~<script\b[^>]*\stype\s*=\s*(?:"application/ld\+json"|\'application/ld\+json\'|application/ld\+json(?=\s|>))[^>]*>(.*?)</script\s*>~is',
			$head, $matches );
		foreach ( $matches[1] as $json ) {
			$decoded = json_decode( $json, true, 32 );
			$pending = is_array( $decoded ) ? [ $decoded ] : [];
			while ( $pending ) {
				$node = array_pop( $pending );
				if ( in_array( 'WebSite', (array)( $node['@type'] ?? [] ), true ) ) {
					return true;
				}
				foreach ( $node as $value ) {
					if ( is_array( $value ) ) {
						$pending[] = $value;
					}
				}
			}
		}
		return false;
	}
}
