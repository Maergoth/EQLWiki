<?php

namespace EQLImmersive;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** Public entity search labels; never changes the article H1, facts or indexing policy. */
class EntitySeo {

	public static function addToOutput( $out, bool $publicRead ): void {
		if ( !SiteIdentity::isEligible( $out, $publicRead ) || SiteIdentity::canonicalOrigin( $out ) === null ) {
			return;
		}
		$title = $out->getTitle()->getText();
		$summary = self::summarize( $out->getHTML(), $title, $out->getCategories() );
		if ( $summary === null ) {
			return;
		}

		// OutputPage stores plain text and core escapes <title> when emitting it.
		// setPageTitle is deliberately untouched: H1/display-title semantics remain native.
		$out->setHTMLTitle( $title . ' - ' . SiteIdentity::PREFERRED_NAME );
		foreach ( $out->getMetaTags() as [ $name, $value ] ) {
			if ( strtolower( $name ) === 'description' ) {
				return;
			}
		}
		$out->addMeta( 'description', self::shorten( $summary['description'], 200 ) );
	}

	/** @return array{kind:string,description:string}|null */
	public static function summarize( string $html, string $title, array $categories ): ?array {
		$kinds = [];
		foreach ( [ 'Items' => 'item', 'NPCs' => 'NPC', 'Zones' => 'zone' ] as $category => $kind ) {
			if ( in_array( $category, $categories, true ) ) {
				$kinds[] = $kind;
			}
		}
		if ( count( $kinds ) !== 1 ) {
			return null;
		}
		$kind = $kinds[0];
		if ( $kind === 'item' ) {
			$summary = ItemSeo::summarize( $html, $title );
			return $summary === null ? null : [ 'kind' => 'item', 'description' => $summary['text'] ];
		}
		if ( !str_contains( $html, 'mw-parser-output' ) ) {
			return null;
		}
		$doc = new DOMDocument();
		$errors = libxml_use_internal_errors( true );
		try {
			$loaded = $doc->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>',
				LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $errors );
		}
		if ( !$loaded ) {
			return null;
		}
		$xpath = new DOMXPath( $doc );
		$roots = $xpath->query( self::classQuery( 'mw-parser-output' ) );
		if ( $roots->length !== 1 ) {
			return null;
		}
		$root = $roots->item( 0 );
		$mobs = $xpath->query( self::classQuery( 'eql-mobpage' ), $root );
		$zones = $xpath->query( self::classQuery( 'zoneTopTable' ), $root );
		if ( $kind === 'NPC' ) {
			if ( $mobs->length !== 1 || $zones->length !== 0 ) {
				return null;
			}
			$card = $mobs->item( 0 );
			$stats = $xpath->query( self::classQuery( 'mobStatsBox' ), $card );
			if ( $card->parentNode !== $root || $stats->length !== 1 || self::hasForeignLead( $root, $card, $title ) ) {
				return null;
			}
		} else {
			if ( $zones->length !== 1 || $mobs->length !== 0 ) {
				return null;
			}
			$card = $zones->item( 0 );
			$anchor = self::zoneAnchor( $root, $card );
			if ( strtolower( $card->tagName ) !== 'table' || $anchor === null ||
				self::hasForeignLead( $root, $anchor, $title )
			) {
				return null;
			}
		}

		// NPC cards do not include their own title. Do not borrow their literal
		// stats or transcluded loot: the category-qualified reference is sufficient.
		$description = self::normalize( $title ) . ' ' . $kind . ' reference in ' .
			SiteIdentity::PREFERRED_NAME . ' (EQLegends).';
		if ( $kind === 'zone' ) {
			$intro = self::zoneIntro( $root, $anchor, $title );
			if ( $intro !== '' ) {
				$description .= ' ' . $intro;
			}
		}
		return [ 'kind' => $kind, 'description' => $description ];
	}

	/** XPath queries are relative so unrelated markup cannot establish an entity card. */
	private static function classQuery( string $class ): string {
		return './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]';
	}

	private static function normalize( string $text ): string {
		return trim( preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $text ) ) );
	}

	private static function isOwnIntro( string $text, string $title ): bool {
		$prefix = preg_quote( self::normalize( $title ), '/' );
		return preg_match( '/^(?:The\s+)?' . $prefix . '(?=\s|[,:.!?]|$)/iu', $text ) === 1;
	}

	/** Legacy zone cards sit inside an otherwise unadorned table layout. */
	private static function zoneAnchor( DOMNode $root, DOMNode $card ): ?DOMNode {
		$anchor = $card;
		for ( $parent = $card->parentNode; $parent; $parent = $parent->parentNode ) {
			if ( $parent === $root ) {
				return $anchor;
			}
			if ( !( $parent instanceof DOMElement ) ||
				!in_array( strtolower( $parent->tagName ), [ 'td', 'tr', 'tbody', 'table' ], true ) ||
				$parent->getAttribute( 'class' ) !== '' || $parent->getAttribute( 'id' ) !== ''
			) {
				return null;
			}
			$anchor = $parent;
		}
		return null;
	}

	private static function isNoticeOrImage( DOMNode $node ): bool {
		if ( !( $node instanceof DOMElement ) ) {
			return false;
		}
		if ( in_array( strtolower( $node->tagName ), [ 'style', 'script', 'figure' ], true ) ) {
			return true;
		}
		if ( preg_match( '/(?:^|\s)(?:right-float-era|eql-era|mw-empty-elt|thumb|thumbcaption)(?:\s|$)/', $node->getAttribute( 'class' ) ) ) {
			return true;
		}
		return $node->getElementsByTagName( 'img' )->length > 0;
	}

	/** A guide wrapped around a foreign entity must not inherit that entity's metadata. */
	private static function hasForeignLead( DOMNode $root, DOMNode $card, string $title ): bool {
		foreach ( $root->childNodes as $node ) {
			if ( $node === $card ) {
				return false;
			}
			if ( $node->nodeType === XML_COMMENT_NODE || self::isNoticeOrImage( $node ) ) {
				continue;
			}
			$text = self::normalize( $node->textContent );
			if ( $text !== '' ) {
				return !self::isOwnIntro( $text, $title );
			}
		}
		return true;
	}

	/** Only a direct, title-led paragraph before the zone card can supply descriptive prose. */
	private static function zoneIntro( DOMNode $root, DOMNode $card, string $title ): string {
		foreach ( $root->childNodes as $node ) {
			if ( $node === $card ) {
				break;
			}
			if ( !( $node instanceof DOMElement ) || strtolower( $node->tagName ) !== 'p' || self::isNoticeOrImage( $node ) ) {
				continue;
			}
			$text = self::normalize( $node->textContent );
			if ( self::isOwnIntro( $text, $title ) &&
				!preg_match( '/\b(?:description needed|need info|replace this|not documented)\b/i', $text )
			) {
				return $text;
			}
		}
		return '';
	}

	private static function shorten( string $text, int $limit ): string {
		if ( mb_strlen( $text, 'UTF-8' ) <= $limit ) {
			return $text;
		}
		$short = mb_substr( $text, 0, $limit - 1, 'UTF-8' );
		$space = mb_strrpos( $short, ' ', 0, 'UTF-8' );
		return ( $space === false ? $short : mb_substr( $short, 0, $space, 'UTF-8' ) ) . '…';
	}
}
