<?php

namespace EQLImmersive;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** Summarizes the article's own rendered Itembox without changing stored content. */
class ItemSeo {
	private const SKILLS = [
		'1H Slashing' => 'a one-handed slashing weapon',
		'2H Slashing' => 'a two-handed slashing weapon',
		'1H Blunt' => 'a one-handed blunt weapon',
		'2H Blunt' => 'a two-handed blunt weapon',
		'Piercing' => 'a piercing weapon',
		'1H Piercing' => 'a one-handed piercing weapon',
		'2H Piercing' => 'a two-handed piercing weapon',
		'Hand to Hand' => 'a hand-to-hand weapon',
		'Archery' => 'an archery weapon',
		'Throwing' => 'a throwing weapon'
	];
	private const CLASSES = [
		'WAR' => 'Warrior', 'CLR' => 'Cleric', 'PAL' => 'Paladin', 'RNG' => 'Ranger',
		'SHD' => 'Shadow Knight', 'DRU' => 'Druid', 'MNK' => 'Monk', 'BRD' => 'Bard',
		'ROG' => 'Rogue', 'SHM' => 'Shaman', 'NEC' => 'Necromancer', 'WIZ' => 'Wizard',
		'MAG' => 'Magician', 'ENC' => 'Enchanter', 'BST' => 'Beastlord', 'BER' => 'Berserker'
	];

	public static function addToOutput( $out, bool $publicRead ): void {
		$title = $out->getTitle();
		$request = $out->getRequest();
		if (
			!$publicRead || !$title || $title->getNamespace() !== NS_MAIN ||
			!$title->exists() || $title->isRedirect() || !$out->isArticle() ||
			!$out->isRevisionCurrent() ||
			$out->getContext()->getActionName() !== 'view' ||
			$request->getVal( 'oldid' ) !== null || $request->getVal( 'diff' ) !== null ||
			$request->getVal( 'veaction' ) !== null ||
			!in_array( 'Items', $out->getCategories(), true )
		) {
			return;
		}

		$html = $out->getHTML();
		$summary = self::summarize( $html, $title->getText() );
		if ( $summary === null ) {
			return;
		}

		$hasDescription = false;
		foreach ( $out->getMetaTags() as [ $name, $value ] ) {
			if ( strtolower( $name ) === 'description' ) {
				$hasDescription = true;
				break;
			}
		}
		if ( !$hasDescription ) {
			$out->addMeta( 'description', self::shorten( $summary['text'], 200 ) );
		}

		// Keep authored introductions and notices. Insert into the existing
		// wrapper without reserializing template markup, links or attributes.
		if ( $summary['addLead'] && !str_contains( $html, 'eql-item-summary' ) ) {
			$lead = '<p class="eql-item-summary">' .
				htmlspecialchars( $summary['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</p>';
			$updated = preg_replace_callback(
				'/<div\b[^>]*\sclass\s*=\s*([\'"])(?:[^\'"]*\s)?mw-parser-output(?:\s[^\'"]*)?\1[^>]*>/i',
				static fn ( $match ) => $match[0] . $lead,
				$html,
				1,
				$count
			);
			if ( $count === 1 ) {
				$out->clearHTML();
				$out->addHTML( $updated );
			}
		}
	}

	/** @return array{text:string,addLead:bool}|null */
	public static function summarize( string $html, string $title ): ?array {
		if ( !str_contains( $html, 'itemdata' ) || !str_contains( $html, 'mw-parser-output' ) ) {
			return null;
		}
		$doc = new DOMDocument();
		$previousErrors = libxml_use_internal_errors( true );
		try {
			$loaded = $doc->loadHTML(
				'<?xml encoding="UTF-8"><div>' . $html . '</div>',
				LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
			);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previousErrors );
		}
		if ( !$loaded ) {
			return null;
		}

		$xpath = new DOMXPath( $doc );
		$roots = $xpath->query( self::classQuery( 'mw-parser-output' ) );
		$cards = $xpath->query( self::classQuery( 'itemdata' ) );
		$names = $xpath->query( self::classQuery( 'itemtitle' ) );
		// Exclude transcluded/multiple cards: a linked item's stats must not
		// describe the current article.
		if ( $roots->length !== 1 || $cards->length !== 1 || $names->length !== 1 ||
			self::text( $names->item( 0 ) ) !== self::normalize( $title )
		) {
			return null;
		}
		$root = $roots->item( 0 );
		$card = $cards->item( 0 );
		$name = $names->item( 0 );
		if ( !self::isWithin( $card, $root ) || !self::isWithin( $name, $root ) ) {
			return null;
		}

		$lines = self::statLines( $card );
		$skill = self::field( $lines, '/^Skill:\s*(.+?)(?:\s+Atk Delay:.*)?$/i' );
		$kind = self::SKILLS[$skill] ?? 'an item';
		$brand = class_exists( SiteIdentity::class ) ? SiteIdentity::PREFERRED_NAME : 'EverQuest Legends Wiki';
		$text = self::normalize( $title ) . ' is ' . $kind . ' in ' . $brand . '.';
		// Distinguishing facts come before generic slot/source context so the
		// bounded description retains an item's effect, haste or armor value.
		// Links may name an effect, but never supply another field's numbers.
		$effect = self::effect( self::statLines( $card, true ) );
		if ( $effect !== '' ) {
			$text .= ' Effect: ' . $effect . '.';
		}
		$haste = self::uniqueField( $lines, 'Haste', '/^([+\-]?\d+(?:\.\d+)?%)$/' );
		if ( $haste !== '' ) {
			$text .= ' Haste: ' . $haste . '.';
		}
		$armor = self::uniqueField( $lines, 'AC', '/^([+\-]?\d+)$/' );
		if ( $armor !== '' ) {
			$text .= ' AC: ' . $armor . '.';
		}
		$damage = self::field( $lines, '/^DMG:\s*(\d+(?:\.\d+)?)(?:\s|$)/i' );
		$delay = self::field( $lines, '/^(?:Skill:.*?\s+)?Atk Delay:\s*(\d+(?:\.\d+)?)(?:\s|$)/i' );
		if ( isset( self::SKILLS[$skill] ) && $damage !== '' && $delay !== '' ) {
			$text .= ' Base DMG: ' . $damage . '; delay: ' . $delay . '.';
		}
		$classes = self::classes( $lines );
		if ( $classes !== '' ) {
			$text .= ' Class: ' . $classes . '.';
		}
		$slot = self::field( $lines, '/^Slot:\s*([A-Z]+(?:\s+[A-Z]+)*)$/' );
		if ( $slot !== '' ) {
			$text .= ' Slot: ' . $slot . '.';
		}
		$zones = self::dropZones( $xpath, $root );
		if ( $zones !== [] ) {
			$text .= ' Listed drop location' . ( count( $zones ) > 1 ? 's' : '' ) . ': ' .
				implode( ', ', $zones ) . '.';
		}

		$top = $name->parentNode;
		$addLead = $top && $top->parentNode === $root;
		if ( $addLead ) {
			for ( $node = $top->previousSibling; $node; $node = $node->previousSibling ) {
				if ( $node->nodeType === XML_COMMENT_NODE ||
					( $node instanceof DOMElement && in_array( strtolower( $node->tagName ), [ 'style', 'script' ], true ) )
				) {
					continue;
				}
				if ( self::text( $node ) !== '' ) {
					$addLead = false;
					break;
				}
			}
		}
		return [ 'text' => $text, 'addLead' => $addLead ];
	}

	private static function classQuery( string $class ): string {
		return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]';
	}

	private static function isWithin( DOMNode $node, DOMNode $root ): bool {
		for ( $parent = $node->parentNode; $parent; $parent = $parent->parentNode ) {
			if ( $parent === $root ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize( string $text ): string {
		return trim( preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $text ) ) );
	}

	private static function text( DOMNode $node ): string {
		return self::normalize( $node->textContent );
	}

	private static function statLines( DOMNode $node, bool $includeLinks = false ): array {
		$text = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( in_array( strtolower( $child->tagName ), [ 'script', 'style' ], true ) ||
					( !$includeLinks && strtolower( $child->tagName ) === 'a' ) ||
					preg_match( '/(?:^|\s)(?:itemicon|hb|spelldata)(?:\s|$)/', $child->getAttribute( 'class' ) ) ||
					( !$includeLinks && preg_match( '/(?:^|\s)itemeff(?:\s|$)/', $child->getAttribute( 'class' ) ) )
				) {
					continue;
				}
				if ( strtolower( $child->tagName ) === 'br' ) {
					$text .= "\n";
					continue;
				}
				$text .= implode( "\n", self::statLines( $child, $includeLinks ) );
				if ( in_array( strtolower( $child->tagName ), [ 'p', 'div' ], true ) ) {
					$text .= "\n";
				}
			} else {
				$text .= $child->textContent;
			}
		}
		return array_values( array_filter( array_map( [ self::class, 'normalize' ], explode( "\n", $text ) ) ) );
	}

	private static function field( array $lines, string $pattern ): string {
		foreach ( $lines as $line ) {
			if ( preg_match( $pattern, $line, $match ) ) {
				return $match[1];
			}
		}
		return '';
	}

	/** Contradictory/repeated fields are not an unambiguous summary fact. */
	private static function uniqueField( array $lines, string $label, string $pattern ): string {
		$values = [];
		foreach ( $lines as $line ) {
			if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(.*)$/i', $line, $match ) ) {
				$values[] = $match[1];
			}
		}
		return count( $values ) === 1 && preg_match( $pattern, $values[0], $match ) ? $match[1] : '';
	}

	private static function classes( array $lines ): string {
		$value = self::uniqueField( $lines, 'Class', '/^([A-Z]+(?:\s+[A-Z]+)*)$/' );
		if ( $value === 'ALL' ) {
			return 'ALL';
		}
		$codes = $value === '' ? [] : explode( ' ', $value );
		foreach ( $codes as $code ) {
			if ( !isset( self::CLASSES[$code] ) ) {
				return '';
			}
		}
		if ( count( array_unique( $codes ) ) !== count( $codes ) ) {
			return '';
		}
		// Long class lists already have a compact, exact representation in the card.
		return count( $codes ) > 4 ? $value : implode( ', ', array_map( static fn ( $code ) => self::CLASSES[$code], $codes ) );
	}

	private static function effect( array $lines ): string {
		$value = self::uniqueField( $lines, 'Effect', '/^([^<>]{1,160})$/u' );
		if ( $value === '' || preg_match( '/\?|\b(?:unknown|none|need info|not documented)\b/i', $value ) ) {
			return '';
		}
		// Compact only this explicit known format. Keep trigger and stated level;
		// do not infer proc levels, spell power, availability or focus effects.
		if ( preg_match( '/^([^():]+)\s+\((Combat|Worn|Any Slot|Must Equip|Expendable|Inventory), Casting Time: [^()]+\)(?: at Level (\d+))?$/u', $value, $match ) ) {
			return trim( $match[1] ) . ' (' . $match[2] .
				( isset( $match[3] ) ? ', level ' . $match[3] : '' ) . ')';
		}
		return !str_contains( $value, ':' ) && mb_strlen( $value, 'UTF-8' ) <= 100 ? $value : '';
	}

	/** Only a linked zone paragraph immediately under the item's drop heading. */
	private static function dropZones( DOMXPath $xpath, DOMNode $root ): array {
		$headings = $xpath->query( './/h2[@id="Drops_From"]', $root );
		if ( $headings->length !== 1 ) {
			return [];
		}
		$heading = $headings->item( 0 );
		$section = $heading->parentNode instanceof DOMElement &&
			preg_match( '/(?:^|\s)mw-heading(?:\s|$)/', $heading->parentNode->getAttribute( 'class' ) )
			? $heading->parentNode : $heading;
		$paragraph = $section->nextSibling;
		while ( $paragraph && ( $paragraph->nodeType === XML_TEXT_NODE || $paragraph->nodeType === XML_COMMENT_NODE ) ) {
			if ( $paragraph->nodeType === XML_TEXT_NODE && self::text( $paragraph ) !== '' ) {
				return [];
			}
			$paragraph = $paragraph->nextSibling;
		}
		if ( !( $paragraph instanceof DOMElement ) || strtolower( $paragraph->tagName ) !== 'p' ) {
			return [];
		}
		$links = $xpath->query( './a[@href and not(contains(concat(" ", normalize-space(@class), " "), " new "))]', $paragraph );
		if ( $links->length < 1 || $links->length > 3 ) {
			return [];
		}
		$zones = [];
		foreach ( $links as $link ) {
			$href = $link->getAttribute( 'href' );
			$label = self::text( $link );
			if ( !preg_match( '~^/(?!/|index\.php(?:[/?]|$))[^?#]+$~i', $href ) ||
				$label === '' || mb_strlen( $label, 'UTF-8' ) > 80
			) {
				return [];
			}
			$zones[] = $label;
		}
		// Prose, placeholders and NPC lists are not zone facts.
		if ( self::text( $paragraph ) !== implode( ' ', $zones ) ) {
			return [];
		}
		return array_values( array_unique( $zones ) );
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
