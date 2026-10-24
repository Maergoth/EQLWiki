<?php
/**
 * Bounded, read-only recognition of authored NPC loot and item drop sources.
 * No MediaWiki services, template expansion, database access or page writes.
 */
final class EqlNpcLootWikitext {
	private const MAX_BYTES = 524288;

	public static function identity( string $value ): string {
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( class_exists( 'Normalizer' ) ) {
			$value = Normalizer::normalize( $value, Normalizer::FORM_KC ) ?: $value;
		}
		$value = str_replace( [ '_', '’', '‘', '`' ], [ ' ', "'", "'", "'" ], $value );
		$value = preg_replace( '/[\s\p{Z}]+/u', ' ', $value );
		if ( $value === null ) {
			return '';
		}
		return trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value ) );
	}

	/** @return array{names:array,zones:array,authoredItems:array}|null */
	public static function analyseNpcPage( string $source ): ?array {
		$document = self::document( $source );
		if ( $document === null ) {
			return null;
		}
		$result = [ 'names' => [], 'zones' => [], 'authoredItems' => [] ];
		$found = false;
		foreach ( $document['templates'] as $template ) {
			if ( $template['name'] !== 'namedmobpage' ) {
				continue;
			}
			$found = true;
			$name = self::title( $template['fields']['name'] ?? '' );
			if ( $name !== null ) {
				self::unique( $result['names'], $name );
			}
			$zoneText = self::fieldText( $template['fields']['zone'] ?? '', false );
			preg_match_all( '/\[\[([^\]|\r\n]+)(?:\|[^\]\r\n]*)?\]\]/', $zoneText, $links );
			foreach ( $links[1] as $link ) {
				$zone = self::title( explode( '#', $link, 2 )[0] );
				if ( $zone !== null ) {
					self::unique( $result['zones'], $zone );
				}
			}
			foreach ( [ 'known_loot', 'common_loot' ] as $field ) {
				$lootText = self::fieldText( $template['fields'][$field] ?? '', true );
				foreach ( self::roots( $lootText ) ?? [] as $transclusion ) {
					if ( str_starts_with( $transclusion['name'], ':' ) ) {
						$first = self::leadingReference( substr( $lootText, $transclusion['start'] ), true );
						if ( $first !== null ) { self::unique( $result['authoredItems'], $first['title'] ); }
					}
				}
				foreach ( self::lines( $lootText ) as $line ) {
					$line = preg_replace( '/^\s*[*#]+\s*/', '', $line );
					$reference = self::leadingReference( $line, true );
					if ( $reference !== null ) {
						self::unique( $result['authoredItems'], $reference['title'] );
					}
				}
			}
		}
		return $found ? $result : null;
	}

	/** @return array<array{npc:string,zone:string,instance:string}> */
	public static function itemSources( string $source ): array {
		$document = self::document( $source );
		if ( $document === null ) {
			return [];
		}
		$results = [];
		$seenScopes = [];
		foreach ( $document['templates'] as $template ) {
			if ( $template['name'] !== 'itempage' ) {
				continue;
			}
			$base = self::instanceAt( $document['outline'], $template['start'], 'normal' );
			if ( isset( $seenScopes[$base] ) ) {
				return [];
			}
			$seenScopes[$base] = true;
			$fields = $template['fields'];
			if ( isset( $fields['dropsfrom'], $fields['drops_from'] ) ) {
				return [];
			}
			$dropText = $fields['dropsfrom'] ?? $fields['drops_from'] ?? '';
			foreach ( self::structuredSources( $dropText, $base ) as $row ) {
				$results[$row['npc'] . '|' . $row['zone'] . '|' . $row['instance']] ??= $row;
			}
			$lines = self::lines( self::fieldText( $dropText, false ) );
			$events = [];
			$unscopedSafe = true;
			foreach ( $lines as $line ) {
				if ( preg_match( '/^(={1,6})[ \t]*(.*?)[ \t]*\1[ \t]*$/', trim( $line ), $heading ) ) {
					$events[] = [ 'kind' => 'section', 'level' => strlen( $heading[1] ), 'label' => $heading[2] ];
					continue;
				}
				$bullet = preg_match( '/^\s*[*#]+\s*/', $line, $prefix ) === 1;
				$reference = self::leadingReference( $bullet ? substr( $line, strlen( $prefix[0] ) ) : $line, false );
				if ( $reference === null || ( !$bullet && trim( $reference['tail'] ) !== '' ) ) {
					if ( trim( $line ) !== '' ) { $unscopedSafe = false; }
					continue;
				}
				$events[] = [ 'kind' => $bullet ? 'mob' : 'link', 'title' => $reference['title'], 'zoneHeading' => false ];
			}
			$hasZones = false;
			for ( $i = 0, $count = count( $events ); $i < $count; $i++ ) {
				if ( $events[$i]['kind'] === 'link' && isset( $events[$i + 1] ) && $events[$i + 1]['kind'] === 'mob' ) {
					$events[$i]['zoneHeading'] = true;
					$hasZones = true;
				}
			}
			if ( !$hasZones && !$unscopedSafe ) { continue; }
			$zone = '';
			$stack = [];
			$zoneScopes = [];
			foreach ( $events as $event ) {
				if ( $event['kind'] === 'section' ) {
					while ( $stack && end( $stack )['level'] >= $event['level'] ) {
						array_pop( $stack );
					}
					$stack[] = $event;
					$zone = '';
					continue;
				}
				$instance = self::stackInstance( $stack, $base );
				if ( $event['zoneHeading'] ?? false ) {
					$zone = $event['title'];
					$key = $instance . '|' . $zone;
					if ( isset( $zoneScopes[$key] ) ) {
						return [];
					}
					$zoneScopes[$key] = true;
					continue;
				}
				if ( $hasZones && ( $zone === '' || $event['kind'] !== 'mob' ) ) {
					continue;
				}
				$row = [ 'npc' => $event['title'], 'zone' => $zone, 'instance' => $instance ];
				$results[$row['npc'] . '|' . $zone . '|' . $instance] ??= $row;
			}
		}
		return array_values( $results );
	}

	private static function title( string $value ): ?string {
		$value = trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$value = str_replace( '_', ' ', $value );
		$value = preg_replace( '/[\s\p{Z}]+/u', ' ', $value );
		if ( !$value || in_array( strtolower( $value ), [ '?', 'none', 'unknown' ], true ) || strlen( $value ) > 240 || preg_match( '/[\x00-\x1f\x7f\[\]{}<>|:#~]/', $value ) || $value[0] === '.' ) {
			return null;
		}
		return $value;
	}

	private static function unique( array &$values, string $title ): void {
		foreach ( $values as $value ) {
			if ( $value === $title ) {
				return;
			}
		}
		$values[] = $title;
	}

	private static function mask( string $text ): string {
		return preg_replace( '/[^\r\n]/', ' ', $text );
	}

	private static function active( string $source ): string {
		$source = preg_replace_callback( '/<!--[\s\S]*?(?:-->|$)/', static fn ( $m ) => self::mask( $m[0] ), $source );
		$tags = 'nowiki|pre|source|syntaxhighlight|ref|code';
		$source = preg_replace_callback( '~<(?:' . $tags . ')\b[^>]*?/\s*>~i', static fn ( $m ) => self::mask( $m[0] ), $source );
		return preg_replace_callback( '~<(' . $tags . ')\b[^>]*>[\s\S]*?(?:</\1\s*>|$)~i', static fn ( $m ) => self::mask( $m[0] ), $source );
	}

	/** Top-level balanced templates only; nested examples never become pages. */
	private static function roots( string $source ): ?array {
		$roots = [];
		$stack = [];
		$start = 0;
		for ( $i = 0, $length = strlen( $source ); $i < $length - 1; $i++ ) {
			$triple = substr( $source, $i, 3 );
			$pair = substr( $source, $i, 2 );
			if ( $triple === '{{{' || $pair === '{{' ) {
				if ( !$stack ) {
					$start = $i;
				}
				$width = $triple === '{{{' ? 3 : 2;
				$stack[] = $width;
				$i += $width - 1;
			} elseif ( $pair === '}}' ) {
				if ( !$stack ) {
					return null;
				}
				$width = end( $stack );
				if ( $width === 3 && $triple !== '}}}' ) {
					return null;
				}
				array_pop( $stack );
				$i += $width - 1;
				if ( !$stack && $width === 2 ) {
					$parts = self::parts( substr( $source, $start + 2, $i - $start - 3 ) );
					if ( $parts === null ) {
						return null;
					}
					$name = strtolower( str_replace( '_', ' ', trim( array_shift( $parts ) ) ) );
					$name = preg_replace( '/^template\s*:\s*/', '', $name );
					$fields = [];
					foreach ( $parts as $part ) {
						if ( preg_match( '/^\s*([A-Za-z][A-Za-z0-9_ ]*)\s*=([\s\S]*)$/', $part, $field ) ) {
							$key = strtolower( trim( $field[1] ) );
							if ( array_key_exists( $key, $fields ) ) {
								return null;
							}
							$fields[$key] = $field[2];
						}
					}
					$roots[] = [ 'start' => $start, 'end' => $i + 1, 'name' => $name, 'parts' => $parts, 'fields' => $fields ];
				} elseif ( !$stack ) {
					$roots[] = [ 'start' => $start, 'end' => $i + 1, 'name' => '', 'parts' => [], 'fields' => [] ];
				}
			}
		}
		return $stack ? null : $roots;
	}

	private static function parts( string $body ): ?array {
		$parts = [];
		$start = 0;
		$braces = 0;
		$links = 0;
		for ( $i = 0, $length = strlen( $body ); $i < $length; $i++ ) {
			$pair = substr( $body, $i, 2 );
			if ( $pair === '{{' ) { $braces += 2; $i++; }
			elseif ( $pair === '}}' && $braces > 0 ) { $braces -= 2; $i++; }
			elseif ( $pair === '[[' ) { $links++; $i++; }
			elseif ( $pair === ']]' ) { if ( !$links ) { return null; } $links--; $i++; }
			elseif ( $body[$i] === '|' && !$braces && !$links ) { $parts[] = substr( $body, $start, $i - $start ); $start = $i + 1; }
		}
		if ( $braces || $links ) { return null; }
		$parts[] = substr( $body, $start );
		return $parts;
	}

	private static function document( string $source ): ?array {
		if ( strlen( $source ) > self::MAX_BYTES || preg_match( '//u', $source ) !== 1 ) { return null; }
		$active = self::active( $source );
		$templates = self::roots( $active );
		if ( $templates === null ) { return null; }
		$outline = $active;
		foreach ( array_reverse( $templates ) as $template ) {
			$outline = substr_replace( $outline, self::mask( substr( $outline, $template['start'], $template['end'] - $template['start'] ) ), $template['start'], $template['end'] - $template['start'] );
		}
		return [ 'templates' => $templates, 'outline' => $outline ];
	}

	private static function stackInstance( array $stack, string $fallback ): string {
		foreach ( $stack as $heading ) {
			if ( preg_match( '/\bRaid[ _]+(?:Instance|Zone)\b/i', $heading['label'] ) ) { $fallback = 'raid'; }
			elseif ( preg_match( '/\bNormal[ _]+(?:Instance|Zone)\b/i', $heading['label'] ) ) { $fallback = 'normal'; }
		}
		return $fallback;
	}

	private static function instanceAt( string $outline, int $offset, string $fallback ): string {
		preg_match_all( '/^(={1,6})[ \t]*(.*?)[ \t]*\1[ \t]*\r?$/m', substr( $outline, 0, $offset ), $headings, PREG_SET_ORDER );
		$stack = [];
		foreach ( $headings as $heading ) {
			$level = strlen( $heading[1] );
			while ( $stack && end( $stack )['level'] >= $level ) { array_pop( $stack ); }
			$stack[] = [ 'level' => $level, 'label' => $heading[2] ];
		}
		return self::stackInstance( $stack, $fallback );
	}

	private static function fieldText( string $field, bool $items, int $depth = 0 ): string {
		if ( $depth > 8 ) { return ''; }
		$roots = self::roots( $field );
		if ( $roots === null ) { return ''; }
		foreach ( array_reverse( $roots ) as $template ) {
			$replacement = self::mask( substr( $field, $template['start'], $template['end'] - $template['start'] ) );
			if ( $template['name'] === 'veliousgray' && count( $template['parts'] ) === 1 ) {
				$replacement = self::fieldText( $template['parts'][0], $items, $depth + 1 );
			} elseif ( $items && str_starts_with( $template['name'], ':' ) ) {
				continue;
			}
			$field = substr_replace( $field, $replacement, $template['start'], $template['end'] - $template['start'] );
		}
		return $field;
	}

	private static function lines( string $text ): array {
		$text = preg_replace_callback( '~<li\b[^>]*>([\s\S]*?)</li\s*>~i', static function ( $m ) {
			return stripos( $m[1], '<li' ) !== false ? "\n" : "\n* " . trim( $m[1] ) . "\n";
		}, $text );
		$text = preg_replace( '~</?(?:ul|ol)\b[^>]*>|<br\s*/?>~i', "\n", $text );
		return preg_split( '/\r\n|\n|\r/', $text );
	}

	private static function leadingReference( string $text, bool $items ): ?array {
		$text = ltrim( $text );
		if ( preg_match( '/^\[\[([^\]|\r\n]+)(?:\|[^\]\r\n]*)?\]\]/', $text, $match ) ||
			$items && preg_match( '/^\{\{\s*:\s*([^}|\r\n]+)(?:\|[^}\r\n]*)?\}\}/', $text, $match ) ) {
			$target = explode( '#', $match[1], 2 );
			$title = self::title( $target[0] );
			if ( $title !== null ) { return [ 'title' => $title, 'anchor' => $target[1] ?? '', 'tail' => substr( $text, strlen( $match[0] ) ) ]; }
		}
		return null;
	}

	/** Explicit structured rows are independent of surrounding bullet-zone scope. */
	private static function structuredSources( string $source, string $base, int $depth = 0 ): array {
		if ( $depth > 8 ) { return []; }
		$document = self::document( $source );
		if ( $document === null ) { return []; }
		$rows = [];
		foreach ( $document['templates'] as $template ) {
			$instance = self::instanceAt( $document['outline'], $template['start'], $base );
			if ( in_array( $template['name'], [ 'itemwheretable', 'veliousgray' ], true ) ) {
				if ( count( $template['parts'] ) === 1 ) {
					$part = preg_replace( '/^\s*1\s*=/', '', $template['parts'][0] );
					$rows = array_merge( $rows, self::structuredSources( $part, $instance, $depth + 1 ) );
				}
				continue;
			}
			if ( $template['name'] !== 'itemwhererow' ) { continue; }
			$arguments = [];
			$position = 1;
			foreach ( $template['parts'] as $part ) {
				if ( preg_match( '/^\s*(\d+)\s*=([\s\S]*)$/', $part, $named ) ) {
					$key = (int)$named[1];
					$value = $named[2];
				} else {
					$key = $position++;
					$value = $part;
				}
				if ( array_key_exists( $key, $arguments ) ) { $arguments = []; break; }
				$arguments[$key] = $value;
			}
			$zone = self::leadingReference( self::fieldText( $arguments[1] ?? '', false ), false );
			$npc = self::leadingReference( self::fieldText( $arguments[2] ?? '', false ), false );
			if ( $zone === null || $npc === null || trim( $zone['tail'] ) !== '' || trim( $npc['tail'] ) !== '' ) { continue; }
			$anchor = self::identity( $zone['anchor'] );
			if ( preg_match( '/\braid\s+(?:instance|zone)\b/', $anchor ) ) { $instance = 'raid'; }
			elseif ( preg_match( '/\bnormal\s+(?:instance|zone)\b/', $anchor ) ) { $instance = 'normal'; }
			$rows[] = [ 'npc' => $npc['title'], 'zone' => $zone['title'], 'instance' => $instance ];
		}
		return $rows;
	}
}
