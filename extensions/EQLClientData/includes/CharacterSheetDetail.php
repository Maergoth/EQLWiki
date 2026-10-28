<?php

declare( strict_types = 1 );

namespace EQLClientData;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/** Bounded typed projection of one already-public rendered schema-1 sheet.
 * No source, arbitrary HTML, achievements payload, modules or configuration. */
final class CharacterSheetDetail {
	public const MAX_BYTES = 1048576;
	private int $visited = 0;

	private function __construct( private readonly string $server ) {
	}

	public static function extract( string $html, string $identity, int $maxLevel, string $server ): ?array {
		if ( strlen( $html ) > CharacterSheetSnapshot::MAX_HTML_BYTES ) {
			throw new RuntimeException( 'eqlplayers-detail-unavailable' );
		}
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$loaded = $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		} finally {
			libxml_clear_errors(); libxml_use_internal_errors( $previous );
		}
		if ( !$loaded ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		$xpath = new DOMXPath( $document );
		$sheets = $xpath->query( '//*[' . self::hasClass( 'eql-character-sheet' ) . ' and ' .
			self::hasClass( 'eql-character-sheet-v1' ) . ' and not(ancestor::*[' . self::hasClass( 'eql-character-sheet' ) . '])]' );
		if ( $sheets->length > CharacterSheetSnapshot::MAX_SHEETS ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		$reader = new self( $server ); $selected = null;
		foreach ( $sheets as $sheet ) {
			// Reuse the exact directory identity/metadata rules, including public
			// unknown values. A matching class name alone is insufficient.
			$rows = CharacterSheetSnapshot::extract( $document->saveHTML( $sheet ), $maxLevel );
			if ( count( $rows ) !== 1 || substr( hash( 'sha256', $rows[0]['name'] . "\0" . $rows[0]['server'] ), 0, 16 ) !== $identity ) { continue; }
			$detail = $reader->sheet( $xpath, $sheet, $rows[0] );
			if ( $selected !== null && $selected !== $detail ) { throw new RuntimeException( 'eqlplayers-detail-conflict' ); }
			$selected = $detail;
		}
		if ( $selected !== null && strlen( json_encode( $selected, JSON_THROW_ON_ERROR ) ) > self::MAX_BYTES ) {
			throw new RuntimeException( 'eqlplayers-detail-unavailable' );
		}
		return $selected;
	}

	private function sheet( DOMXPath $xpath, DOMElement $sheet, array $row ): array {
		$heading = self::one( $xpath, './div[' . self::hasClass( 'eql-character-heading' ) . ']', $sheet );
		$metadata = $heading ? self::one( $xpath, './span[' . self::hasClass( 'eql-character-metadata' ) . ']', $heading ) : null;
		$body = self::one( $xpath, './div[' . self::hasClass( 'eql-character-body' ) . ']', $sheet );
		$layout = $body ? self::one( $xpath, './div[' . self::hasClass( 'eql-character-layout' ) . ']', $body ) : null;
		$totalsNode = $layout ? self::one( $xpath, './div[' . self::hasClass( 'eql-character-totals' ) . ']', $layout ) : null;
		$equipmentNode = $layout ? self::one( $xpath, './div[' . self::hasClass( 'eql-character-equipment' ) . ']', $layout ) : null;
		if ( !$metadata || !$totalsNode || !$equipmentNode ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		$keys = array_flip( CharacterSheetSnapshot::STAT_LABELS ); $totals = []; $seenTotals = [];
		foreach ( $xpath->query( './dl/div', $totalsNode ) as $pair ) {
			$label = self::one( $xpath, './dt', $pair ); $value = self::one( $xpath, './dd', $pair );
			$key = $label ? ( $keys[self::text( $label )] ?? null ) : null;
			if ( $key !== null && $value ) {
				if ( isset( $seenTotals[$key] ) ) { throw new RuntimeException( 'eqlplayers-detail-conflict' ); }
				$seenTotals[$key] = true;
				$totals[] = [ 'key' => $key, 'label' => CharacterSheetSnapshot::STAT_LABELS[$key],
					'text' => self::text( $value, 100 ), 'value' => $row['stats'][$key] ];
			}
			if ( count( $totals ) > 64 ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		}
		$equipment = [];
		foreach ( $xpath->query( './div[' . self::hasClass( 'eql-character-equipment-row' ) . ']/div[' . self::hasClass( 'eql-character-slot' ) . ']', $equipmentNode ) as $slot ) {
			$label = self::one( $xpath, './span[' . self::hasClass( 'eql-character-slot-label' ) . ']', $slot );
			$name = $label ? self::text( $label ) : '';
			if ( !in_array( $name, CharacterSheetSnapshot::SLOT_LABELS, true ) || isset( $equipment[$name] ) ) { throw new RuntimeException( 'eqlplayers-detail-conflict' ); }
			$cards = $xpath->query( './div[' . self::hasClass( 'eql-character-item' ) . ']', $slot );
			$empty = $xpath->query( './span[' . self::hasClass( 'eql-character-empty' ) . ']', $slot );
			if ( $cards->length === 0 && $empty->length === 1 ) { $item = null; }
			elseif ( $cards->length === 1 && $empty->length === 0 ) { $item = $this->item( $xpath, $cards[0] ); }
			else { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
			$equipment[$name] = [ 'slot' => $name, 'item' => $item ];
		}
		if ( count( $equipment ) !== count( CharacterSheetSnapshot::SLOT_LABELS ) ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		$photo = $layout ? self::one( $xpath, './div[' . self::hasClass( 'eql-character-screenshot-v1' ) . ']', $layout ) : null;
		$screenshot = null;
		if ( $photo ) {
			$images = $xpath->query( './/img', $photo );
			if ( $images->length === 1 && ( $image = $this->image( $images[0] ) ) ) {
				$title = $photo->getAttribute( 'data-file-title' );
				if ( preg_match( '/^File:[^\x00-\x1f\x7f|{}\[\]<>#]{1,235}$/uD', $title ) && strlen( $title ) <= 240 ) {
					$link = $xpath->query( './/a[@href]', $photo )->item( 0 );
					$screenshot = [ 'fileTitle' => $title, 'href' => $link ? $this->localUrl( $link->getAttribute( 'href' ) ) : null ] + $image;
				}
			}
		}
		return [ 'name' => $row['name'], 'server' => $row['server'], 'metadataText' => self::text( $metadata, 1000 ),
			'stats' => $row['stats'], 'totals' => $totals, 'equipment' => array_map( static fn ( $slot ) => $equipment[$slot], CharacterSheetSnapshot::SLOT_LABELS ),
			'screenshot' => $screenshot, 'achievementCount' => $row['achievementCount'], 'updated' => $row['updated'] ];
	}

	private function item( DOMXPath $xpath, DOMElement $card ): array {
		$name = self::one( $xpath, './span[' . self::hasClass( 'eql-character-item-name' ) . ']', $card );
		$inspect = self::one( $xpath, './div[' . self::hasClass( 'eql-character-inspect-v1' ) . ']', $card );
		if ( !$name || !$inspect ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		$link = self::one( $xpath, './a', $name );
		$tierNode = self::one( $xpath, './span[' . self::hasClass( 'eql-character-tier' ) . ']', $card );
		$tier = $tierNode && preg_match( '/^\+(0|[1-9]|10)$/D', self::text( $tierNode ), $match ) ? (int)$match[1] : null;
		$icons = $xpath->query( './span[' . self::hasClass( 'eql-character-item-icon' ) . ']//img', $card );
		$stats = self::one( $xpath, './div[' . self::hasClass( 'eql-character-item-stats' ) . ']', $inspect );
		$details = self::one( $xpath, './div[' . self::hasClass( 'eql-character-item-details' ) . ']', $inspect );
		$augmentNode = self::one( $xpath, './div[' . self::hasClass( 'eql-character-augments' ) . ']', $inspect );
		$augments = [];
		if ( $augmentNode ) {
			$children = $xpath->query( './ul/li', $augmentNode );
			if ( $children->length > 8 ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
			foreach ( $children as $socket => $augment ) {
				$strong = self::one( $xpath, './strong', $augment );
				if ( !$strong ) { continue; } // Legacy explicit empty sockets are not items.
				$anchor = self::one( $xpath, './a', $strong );
				$effects = self::one( $xpath, './span[' . self::hasClass( 'eql-character-item-details' ) . ']', $augment );
				$lines = $effects ? $this->inlineLines( $effects ) : [];
				$lines = array_values( array_filter( $lines, static fn ( $line ) => preg_match( '/^(?:Effect|(?:Combat|Proc|Click|Worn|Focus)\s+Effect|(?:Focus|Proc|Rune)\s+Exaltation)\s*:\s*\S/iu', implode( '', array_column( $line, 'text' ) ) ) ) );
				$augmentTitle = self::text( $strong, 240 );
				if ( $augmentTitle === '' ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
				$augments[] = [ 'socket' => $socket, 'title' => $augmentTitle,
					'href' => $anchor ? $this->localUrl( $anchor->getAttribute( 'href' ) ) : null, 'effects' => $lines ];
			}
		}
		$itemTitle = self::text( $name, 240 );
		if ( $itemTitle === '' ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		return [ 'title' => $itemTitle, 'href' => $link ? $this->localUrl( $link->getAttribute( 'href' ) ) : null,
			'tier' => $tier, 'icon' => $icons->length === 1 ? $this->image( $icons[0] ) : null,
			'statLines' => $stats ? array_map( static fn ( $line ) => implode( '', array_column( $line, 'text' ) ), $this->inlineLines( $stats ) ) : [],
			'details' => $details ? $this->inlineLines( $details ) : [], 'augments' => $augments ];
	}

	private function inlineLines( DOMElement $root ): array {
		$lines = [ [] ]; $characters = 0;
		$walk = function ( DOMNode $node, int $depth = 0, ?array $link = null ) use ( &$walk, &$lines, &$characters ): void {
			if ( ++$this->visited > 20000 || $depth > 32 || count( $lines ) > 64 ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
			if ( $node->nodeType === XML_TEXT_NODE ) {
				$text = str_replace( "\u{00a0}", ' ', $node->textContent ); $characters += strlen( $text );
				if ( $characters > 8192 ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
				if ( $text !== '' ) { $lines[count( $lines ) - 1][] = [ 'text' => $text ] + ( $link ?? [] ); }
			} elseif ( $node instanceof DOMElement ) {
				$tag = strtolower( $node->tagName );
				if ( in_array( $tag, [ 'script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math' ], true ) ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
				if ( $tag === 'br' ) { $lines[] = []; return; }
				$block = in_array( $tag, [ 'div', 'p', 'li' ], true );
				if ( $block && $lines[count( $lines ) - 1] ) { $lines[] = []; }
				if ( $tag === 'a' ) {
					$url = $this->localUrl( $node->getAttribute( 'href' ) );
					$link = $url ? [ 'href' => $url ] : null;
					if ( $link && $node->hasAttribute( 'title' ) ) { $link['title'] = self::attribute( $node->getAttribute( 'title' ), 240 ); }
				}
				foreach ( $node->childNodes as $child ) { $walk( $child, $depth + 1, $link ); }
				if ( $block && $lines[count( $lines ) - 1] ) { $lines[] = []; }
			}
		};
		foreach ( $root->childNodes as $child ) { $walk( $child ); }
		return array_values( array_filter( $lines, static fn ( $line ) => trim( implode( '', array_column( $line, 'text' ) ) ) !== '' ) );
	}

	private function image( DOMElement $image ): ?array {
		$url = $this->localUrl( $image->getAttribute( 'src' ) );
		if ( !$url ) { return null; }
		$dimension = static fn ( $value ) => preg_match( '/^[1-9][0-9]{0,3}$/D', $value ) && (int)$value <= 8192 ? (int)$value : null;
		return [ 'url' => $url, 'width' => $dimension( $image->getAttribute( 'width' ) ),
			'height' => $dimension( $image->getAttribute( 'height' ) ), 'alt' => self::attribute( $image->getAttribute( 'alt' ), 240 ) ];
	}

	private function localUrl( string $url ): ?string {
		if ( $url === '' || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]|%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url ) ) { return null; }
		if ( str_starts_with( $url, '/' ) && !str_starts_with( $url, '//' ) ) { return $url; }
		$parts = parse_url( $url ); $server = parse_url( $this->server );
		if ( !$parts || !$server || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
			!in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) ||
			( $parts['scheme'] ?? '' ) !== ( $server['scheme'] ?? '' ) ||
			strtolower( $parts['host'] ?? '' ) !== strtolower( $server['host'] ?? '' ) ||
			( $parts['port'] ?? null ) !== ( $server['port'] ?? null ) ) { return null; }
		return $url;
	}

	private static function attribute( string $value, int $limit ): string {
		if ( strlen( $value ) > $limit || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		return $value;
	}
	private static function text( DOMNode $node, int $limit = 8192 ): string {
		return self::attribute( trim( preg_replace( '/\s+/u', ' ', str_replace( "\u{00a0}", ' ', $node->textContent ) ) ?? '' ), $limit );
	}
	private static function one( DOMXPath $xpath, string $query, DOMNode $node ): ?DOMElement {
		$matches = $xpath->query( $query, $node );
		return $matches->length === 1 && $matches[0] instanceof DOMElement ? $matches[0] : null;
	}
	private static function hasClass( string $name ): string {
		return 'contains(concat(" ",normalize-space(@class)," ")," ' . $name . ' ")';
	}
}
