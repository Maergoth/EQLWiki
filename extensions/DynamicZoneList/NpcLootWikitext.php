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

	/** Template titles fold the first letter, not the rest of the page name. */
	public static function templateInvocationKey( string $name ): string {
		$name = preg_replace( '/^template\s*:\s*/i', '', trim( $name ) );
		$name = self::title( $name, true );
		if ( $name === null ) { return ''; }
		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( mb_substr( $name, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $name, 1, null, 'UTF-8' );
		}
		return ucfirst( $name );
	}

	/** @return array{names:array,zones:array,authoredItems:array}|null */
	public static function analyseNpcPage( string $source, bool $globalExport = false ): ?array {
		$document = self::document( $source, $globalExport );
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
				foreach ( self::lootReferences( $template['fields'][$field] ?? '' ) as $title ) { self::unique( $result['authoredItems'], $title ); }
			}
		}
		return $found ? $result : null;
	}

	/**
	 * The global-export option permits colon punctuation for later namespace
	 * validation and reports unknown source syntax through $complete. Defaults
	 * retain the renderer's existing conservative field recognition.
	 * @return array{names:array,zones:array,existingSources:array}|null
	 */
	public static function analyseItemPage( string $source, ?bool &$complete = null, bool $allowMainColon = false ): ?array {
		$complete = true;
		$document = self::document( $source );
		if ( $document === null ) { $complete = false; return null; }
		$names = [];
		$found = false;
		foreach ( $document['templates'] as $template ) {
			if ( $template['name'] !== 'itempage' ) { continue; }
			$found = true;
			$name = self::title( $template['fields']['itemname'] ?? '', $allowMainColon );
			if ( $name !== null ) { self::unique( $names, $name ); }
		}
		if ( !$found ) { return null; }
		$sources = self::itemSources( $source, $complete, $allowMainColon );
		$zones = [];
		foreach ( $sources as $row ) { if ( $row['zone'] !== '' ) { self::unique( $zones, $row['zone'] ); } }
		return [ 'names' => $names, 'zones' => $zones, 'existingSources' => $sources ];
	}

	/**
	 * Global exports use the final repeated static Namedmobpage parameter, as
	 * MediaWiki does. All default renderer/helper calls retain strict parsing.
	 * @return array<array{item:string,npc:string,zone:string,instance:string,lootKind:string}>
	 */
	public static function npcLootSources( string $source, ?bool &$complete = null, bool $allowMainColon = false, bool $globalExport = false, array $verifiedItemTemplates = [] ): array {
		$complete = true;
		$document = self::document( $source, $globalExport );
		if ( $document === null ) { $complete = false; return []; }
		$rows = [];
		foreach ( $document['templates'] as $template ) {
			if ( $template['name'] !== 'namedmobpage' ) { continue; }
			$analysis = self::analyseNpcPage( substr( $source, $template['start'], $template['end'] - $template['start'] ), $globalExport );
			$npc = self::title( $template['fields']['name'] ?? '' ) ?? '';
			$instance = self::instanceAt( $document['outline'], $template['start'], 'normal' );
			foreach ( [ 'known_loot' => 'known', 'common_loot' => 'common' ] as $field => $kind ) {
				if ( $allowMainColon && !self::sourceSyntaxComplete( $template['fields'][$field] ?? '', true, 0, $globalExport, $verifiedItemTemplates ) ) { $complete = false; }
				foreach ( self::lootReferences( $template['fields'][$field] ?? '', $allowMainColon, $globalExport, $verifiedItemTemplates ) as $item ) {
					foreach ( ( $analysis['zones'] ?: [ '' ] ) as $zone ) {
						$row = [ 'item' => $item, 'npc' => $npc, 'zone' => $zone, 'instance' => $instance, 'lootKind' => $kind ];
						$rows[json_encode( $row )] = $row;
					}
				}
			}
		}
		return array_values( $rows );
	}

	/** Original-case zero-argument templates requiring public item verification. */
	public static function globalNpcLootTemplateNames( string $source ): array {
		$document = self::document( $source, true );
		if ( $document === null ) { return []; }
		$names = [];
		$visit = static function ( string $field, int $depth = 0 ) use ( &$visit, &$names ): void {
			if ( $depth > 8 ) { return; }
			$active = self::active( $field );
			foreach ( self::roots( $active ) ?? [] as $template ) {
				if ( str_starts_with( $template['name'], ':' ) || in_array( $template['name'], [ 'era', 'loc' ], true ) || self::globalLootMetadata( $template ) ) { continue; }
				if ( $template['name'] === 'veliousgray' ) { foreach ( $template['parts'] as $part ) { $visit( $part, $depth + 1 ); } continue; }
				if ( $template['parts'] || count( $names ) >= 20 ) { continue; }
				preg_match( '/^\{\{\s*([^{}|]+?)\s*\}\}$/', substr( $active, $template['start'], $template['end'] - $template['start'] ), $match );
				$name = preg_replace( '/^template\s*:/i', '', trim( $match[1] ?? '' ) );
				$key = self::templateInvocationKey( $name );
				if ( $key !== '' && strlen( $name ) <= 240 ) { $names[$key] = $name; }
			}
		};
		foreach ( $document['templates'] as $template ) {
			if ( $template['name'] !== 'namedmobpage' ) { continue; }
			foreach ( [ 'known_loot', 'common_loot' ] as $field ) { $visit( $template['fields'][$field] ?? '' ); }
		}
		return array_values( $names );
	}

	/** Literal members of public top-level Gear Set cards; never expands templates. */
	public static function globalGearSetItems( string $source, ?bool &$complete = null ): ?array {
		$complete = true;
		if ( strlen( $source ) > self::MAX_BYTES || preg_match( '//u', $source ) !== 1 ) { $complete = false; return null; }
		// Template links also include indirect transclusions on class/quest pages.
		// Their unrelated markup cannot turn the page into a literal set card.
		if ( !preg_match( '/\{\{\s*(?:(?i:template)\s*:\s*)?[Gg]ear[ _]+Set\s*(?=[|}]|$)/', self::active( $source ) ) ) { return null; }
		$document = self::document( $source, true );
		if ( $document === null ) { $complete = false; return null; }
		$found = false;
		$items = [];
		foreach ( $document['templates'] as $template ) {
			if ( ( $template['invocationKey'] ?? '' ) !== 'Gear Set' ) { continue; }
			$found = true;
			if ( count( $template['parts'] ) > 100 ) { $complete = false; }
			foreach ( array_slice( $template['parts'], 0, 100 ) as $part ) {
				$value = trim( self::active( $part ) );
				// Module:GearSet ignores blank numeric arguments.
				if ( $value === '' ) { continue; }
				if ( preg_match( '/\{\{|=/', $value ) ) { $complete = false; continue; }
				if ( preg_match( '/^\[\[([^\]|\r\n]+)(?:\|[^\[\]{}\r\n]*)?\]\]$/', $value, $link ) ) { $value = trim( $link[1] ); }
				$item = self::title( $value, true );
				if ( $item === null || count( $items ) >= 100 ) { $complete = false; continue; }
				self::unique( $items, $item );
			}
		}
		if ( $found && !$items ) { $complete = false; }
		return $found ? $items : null;
	}

	/** One unconditional legacy item hover, verified from public source by the caller. */
	public static function globalItemTemplateTarget( string $source ): ?string {
		$document = self::document( $source );
		if ( $document === null ) { return null; }
		$pages = array_values( array_filter( $document['templates'], static fn ( $template ) => $template['name'] === 'itempage' ) );
		if ( count( $pages ) !== 1 ) { return null; }
		$name = self::title( $pages[0]['fields']['itemname'] ?? '', true );
		if ( $name === null ) { return null; }
		$active = self::active( $source );
		if ( preg_match_all( '~<onlyinclude\s*>~i', $active ) !== 1 || preg_match_all( '~</onlyinclude\s*>~i', $active ) !== 1 ||
			!preg_match( '~<onlyinclude\s*>([\s\S]*?)</onlyinclude\s*>~i', $active, $include ) ) { return null; }
		$included = trim( $include[1] );
		if ( preg_match( '~^<includeonly\s*>([\s\S]*)</includeonly\s*>$~i', $included, $wrapped ) ) { $included = trim( $wrapped[1] ); }
		if ( preg_match( '~</?(?:includeonly|onlyinclude)\b~i', $included ) ||
			!preg_match( '~^<div\s+class=(["\'])hbdiv\1>\s*\[\[([^\]|\r\n]+)(?:\|[^\[\]{}\r\n]*)?\]\]\s*<span\s+class=(["\'])hb\3>\s*([\s\S]*?)\s*</span>\s*</div>\s*$~', $included, $hover ) ) { return null; }
		$link = self::title( $hover[2], true );
		if ( $link === null || self::templateInvocationKey( $link ) !== self::templateInvocationKey( $name ) ) { return null; }
		$body = $hover[4];
		$card = self::document( $body );
		if ( $card === null || count( $card['templates'] ) !== 1 ) { return null; }
		$template = $card['templates'][0];
		if ( $template['name'] !== 'itembox' || trim( substr( $body, 0, $template['start'] ) ) !== '' ||
			trim( substr( $body, $template['end'] ) ) !== '' ) { return null; }
		$inner = substr( $body, $template['start'] + 2, $template['end'] - $template['start'] - 4 );
		if ( preg_match( '/\{\{|\}\}/', $inner ) ) { return null; }
		$cardName = self::title( $template['fields']['itemname'] ?? '', true );
		return $cardName !== null && self::templateInvocationKey( $cardName ) === self::templateInvocationKey( $name ) ? $name : null;
	}

	/** Direct article references in an active native loot field, without expanding templates. */
	private static function lootReferences( string $field, bool $allowMainColon = false, bool $globalExport = false, array $verifiedItemTemplates = [] ): array {
		$items = [];
		$lootText = self::fieldText( $field, true, 0, $globalExport, $verifiedItemTemplates );
		$transclusions = self::roots( $lootText ) ?? [];
		foreach ( $transclusions as $transclusion ) {
			if ( str_starts_with( $transclusion['name'], ':' ) ) {
				$first = self::leadingReference( substr( $lootText, $transclusion['start'] ), true, $allowMainColon );
				if ( $first !== null ) { self::unique( $items, $first['title'] ); }
			}
		}
		// A reference can be bold, wrapped in hover markup or one of several
		// inline links. Unused template arguments are not displayed references.
		foreach ( array_reverse( $transclusions ) as $transclusion ) {
			$length = $transclusion['end'] - $transclusion['start'];
			$lootText = substr_replace( $lootText, self::mask( substr( $lootText, $transclusion['start'], $length ) ), $transclusion['start'], $length );
		}
		preg_match_all( '/\[\[([^\[\]|\r\n]+)(?:\|[^\[\]\r\n]*)?\]\]/', $lootText, $links );
		foreach ( $links[1] as $link ) {
			$link = preg_replace( '/^\s*:\s*/', '', $link );
			$title = self::title( explode( '#', $link, 2 )[0], $allowMainColon );
			if ( $title !== null ) { self::unique( $items, $title ); }
		}
		return $items;
	}

	/**
	 * Add renderer-validated loot rows to the native NPC loot fields in memory.
	 * Entry markup must already be safe HTML/wikitext. This helper never saves it.
	 * @param array<array{markup:string,contexts:array,field?:string}> $entries
	 * @return array{text:string,unplaced:int}
	 */
	public static function mergeNpcLoot( string $source, array $entries ): array {
		$document = self::document( $source );
		if ( $document === null ) { return [ 'text' => $source, 'unplaced' => count( $entries ) ]; }
		$cards = [];
		foreach ( $document['templates'] as $template ) {
			if ( $template['name'] !== 'namedmobpage' ) { continue; }
			$analysis = self::analyseNpcPage( substr( $source, $template['start'], $template['end'] - $template['start'] ) );
			$cards[] = [ 'template' => $template, 'zones' => $analysis['zones'] ?? [],
				'instance' => self::instanceAt( $document['outline'], $template['start'], 'normal' ),
				'shared' => self::instanceAt( $document['outline'], $template['start'], 'shared' ) === 'shared',
				'rows' => [ 'known_loot' => [], 'common_loot' => [] ] ];
		}
		if ( !$cards ) { return [ 'text' => $source, 'unplaced' => count( $entries ) ]; }
		$unplaced = 0;
		foreach ( $entries as $entry ) {
			$markup = $entry['markup'] ?? '';
			if ( !is_string( $markup ) || $markup === '' ) { $unplaced++; continue; }
			$field = ( $entry['field'] ?? 'known_loot' ) === 'common_loot' ? 'common_loot' : 'known_loot';
			$targets = [];
			$missing = false;
			if ( count( $cards ) === 1 && $cards[0]['shared'] ) {
				// An unpartitioned NPC card is shared by all verified source contexts.
				$targets[] = 0;
			} else {
				$contexts = $entry['contexts'] ?? [];
				if ( !$contexts ) { $missing = true; }
				foreach ( $contexts as $context ) {
					$instance = ( $context['instance'] ?? 'normal' ) === 'raid' ? 'raid' : 'normal';
					$zone = self::title( $context['zone'] ?? '' ) ?? '';
					$exact = $folded = $unknown = [];
					foreach ( $cards as $index => $card ) {
						if ( $card['instance'] !== $instance ) { continue; }
						if ( $zone === '' || !$card['zones'] ) { $unknown[] = $index; continue; }
						if ( in_array( $zone, $card['zones'], true ) ) { $exact[] = $index; continue; }
						foreach ( $card['zones'] as $cardZone ) {
							if ( self::identity( $zone ) === self::identity( $cardZone ) ) { $folded[] = $index; break; }
						}
					}
					$matches = $exact ?: ( $folded ?: $unknown );
					if ( count( $matches ) !== 1 ) { $missing = true; continue; }
					$targets[] = $matches[0];
				}
			}
			foreach ( array_unique( $targets ) as $index ) { $cards[$index]['rows'][$field][] = $markup; }
			if ( $missing || !$targets ) { $unplaced++; }
		}
		$replacements = [];
		foreach ( $cards as $card ) {
			if ( !$card['rows']['known_loot'] && !$card['rows']['common_loot'] ) { continue; }
			$template = $card['template'];
			$length = $template['end'] - $template['start'];
			$original = substr( $source, $template['start'], $length );
			$parts = self::parts( substr( self::active( $original ), 2, -2 ) );
			$offset = 2;
			$edits = [];
			$found = [];
			foreach ( $parts as $part ) {
				if ( preg_match( '/^(\s*(known_loot|common_loot)\s*=)/i', $part, $match ) ) {
					$field = strtolower( $match[2] );
					if ( $card['rows'][$field] ) {
						$start = $offset + strlen( $match[1] );
						$valueLength = strlen( $part ) - strlen( $match[1] );
						$edits[] = [ 'start' => $start, 'length' => $valueLength,
							'value' => self::appendLootRows( substr( $original, $start, $valueLength ), array_values( array_unique( $card['rows'][$field] ) ) ) ];
						$found[$field] = true;
					}
				}
				$offset += strlen( $part ) + 1;
			}
			usort( $edits, static fn ( $a, $b ) => $b['start'] <=> $a['start'] );
			foreach ( $edits as $edit ) { $original = substr_replace( $original, $edit['value'], $edit['start'], $edit['length'] ); }
			$missingFields = '';
			foreach ( $card['rows'] as $field => $rows ) {
				if ( $rows && !isset( $found[$field] ) ) { $missingFields .= "\n|{$field}=" . self::appendLootRows( '', array_values( array_unique( $rows ) ) ); }
			}
			if ( $missingFields !== '' ) { $original = substr( $original, 0, -2 ) . $missingFields . '}}'; }
			$replacements[] = [ 'start' => $template['start'], 'length' => $length, 'value' => $original ];
		}
		usort( $replacements, static fn ( $a, $b ) => $b['start'] <=> $a['start'] );
		foreach ( $replacements as $replacement ) { $source = substr_replace( $source, $replacement['value'], $replacement['start'], $replacement['length'] ); }
		return [ 'text' => $source, 'unplaced' => $unplaced ];
	}

	/** Preserve existing authored lists; replace only an exact empty-list placeholder. */
	private static function appendLootRows( string $value, array $rows ): string {
		if ( preg_match( '~^\s*(?:None|[*#]\s*None|<ul\b[^>]*>\s*<li\b[^>]*>\s*None\s*</li>\s*</ul>)\s*$~i', $value ) ) { $value = ''; }
		$active = self::active( $value );
		if ( preg_match( '~^\s*<ul\b[^>]*>~i', $active ) && preg_match( '~</ul\s*>\s*$~i', $active ) ) {
			preg_match_all( '~</?ul\b[^>]*>~i', $active, $tags, PREG_OFFSET_CAPTURE );
			$depth = 0;
			$closing = null;
			foreach ( $tags[0] as $index => $tag ) {
				$depth += str_starts_with( $tag[0], '</' ) ? -1 : 1;
				if ( $depth <= 0 && $index !== count( $tags[0] ) - 1 ) { $closing = null; break; }
				if ( $depth === 0 ) { $closing = $tag[1]; }
			}
			if ( $depth === 0 && $closing !== null ) { return substr( $value, 0, $closing ) . "\n" . implode( "\n", $rows ) . "\n" . substr( $value, $closing ); }
		}
		return $value . "\n<ul class=\"eql-recorded-loot\">\n" . implode( "\n", $rows ) . "\n</ul>\n";
	}

	/**
	 * Add verified reciprocal mob references to native Itempage drop sources.
	 * @param array<array{npc:string,zone:string,instance:string,lootKind?:string}> $entries
	 * @return array{text:string,unplaced:int}
	 */
	public static function mergeItemSources( string $source, array $entries ): array {
		$document = self::document( $source );
		if ( $document === null ) { return [ 'text' => $source, 'unplaced' => count( $entries ) ]; }
		$cards = [];
		foreach ( $document['templates'] as $template ) {
			if ( $template['name'] !== 'itempage' ) { continue; }
			$cards[] = [ 'template' => $template,
				'instance' => self::instanceAt( $document['outline'], $template['start'], 'normal' ),
				'shared' => self::instanceAt( $document['outline'], $template['start'], 'shared' ) === 'shared',
				'conflict' => isset( $template['fields']['dropsfrom'], $template['fields']['drops_from'] ), 'entries' => [] ];
		}
		if ( !$cards ) { return [ 'text' => $source, 'unplaced' => count( $entries ) ]; }
		$unplaced = 0;
		foreach ( $entries as $entry ) {
			$npc = self::title( $entry['npc'] ?? '' );
			$zone = ( $entry['zone'] ?? '' ) === '' ? '' : self::title( $entry['zone'] );
			if ( $npc === null || $zone === null ) { $unplaced++; continue; }
			$instance = ( $entry['instance'] ?? 'normal' ) === 'raid' ? 'raid' : 'normal';
			$targets = [];
			if ( count( $cards ) === 1 && $cards[0]['shared'] ) {
				$targets[] = 0;
			} else {
				foreach ( $cards as $index => $card ) { if ( $card['instance'] === $instance ) { $targets[] = $index; } }
			}
			if ( count( $targets ) !== 1 || $cards[$targets[0]]['conflict'] ) { $unplaced++; continue; }
			$row = [ 'npc' => $npc, 'zone' => $zone, 'instance' => $instance ];
			$cards[$targets[0]]['entries'][json_encode( $row )] = $row;
		}
		$replacements = [];
		foreach ( $cards as $card ) {
			if ( !$card['entries'] ) { continue; }
			$groups = [];
			foreach ( $card['entries'] as $entry ) { $groups[$entry['instance']][$entry['zone']][] = $entry['npc']; }
			$addition = '';
			foreach ( [ 'normal' => 'Normal', 'raid' => 'Raid' ] as $instance => $label ) {
				if ( !isset( $groups[$instance] ) ) { continue; }
				// Explicit groups cannot inherit an existing final Raid heading.
				$addition .= "\n=== {$label} Instance ===\n";
				foreach ( $groups[$instance] as $zone => $npcs ) {
					if ( $zone !== '' ) { $addition .= '[[' . $zone . "]]\n"; }
					foreach ( $npcs as $npc ) { $addition .= '* [[' . $npc . "]]\n"; }
				}
			}
			$template = $card['template'];
			$length = $template['end'] - $template['start'];
			$original = substr( $source, $template['start'], $length );
			$parts = self::parts( substr( self::active( $original ), 2, -2 ) );
			$offset = 2;
			$updated = false;
			foreach ( $parts as $part ) {
				if ( preg_match( '/^(\s*(?:dropsfrom|drops_from)\s*=)/i', $part, $match ) ) {
					$start = $offset + strlen( $match[1] );
					$valueLength = strlen( $part ) - strlen( $match[1] );
					$value = substr( $original, $start, $valueLength );
					if ( preg_match( '~^\s*(?:\?|None|[*#]\s*None|[*#]?\s*This item is not dropped by mobs\.)\s*$~i', $value ) ) { $value = ''; }
					$original = substr_replace( $original, $value . $addition, $start, $valueLength );
					$updated = true;
					break;
				}
				$offset += strlen( $part ) + 1;
			}
			if ( !$updated ) { $original = substr( $original, 0, -2 ) . "\n|dropsfrom=" . $addition . '}}'; }
			$replacements[] = [ 'start' => $template['start'], 'length' => $length, 'value' => $original ];
		}
		usort( $replacements, static fn ( $a, $b ) => $b['start'] <=> $a['start'] );
		foreach ( $replacements as $replacement ) { $source = substr_replace( $source, $replacement['value'], $replacement['start'], $replacement['length'] ); }
		return [ 'text' => $source, 'unplaced' => $unplaced ];
	}

	/** @return array<array{npc:string,zone:string,instance:string}> */
	public static function itemSources( string $source, ?bool &$complete = null, bool $allowMainColon = false ): array {
		$complete = true;
		$document = self::document( $source );
		if ( $document === null ) {
			$complete = false;
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
				$complete = false;
				return [];
			}
			$seenScopes[$base] = true;
			$fields = $template['fields'];
			if ( isset( $fields['dropsfrom'], $fields['drops_from'] ) ) {
				$complete = false;
				return [];
			}
			$dropText = $fields['dropsfrom'] ?? $fields['drops_from'] ?? '';
			if ( $allowMainColon && !self::sourceSyntaxComplete( $dropText, false ) ) { $complete = false; }
			foreach ( self::structuredSources( $dropText, $base, 0, $allowMainColon ) as $row ) {
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
				$reference = self::leadingReference( $bullet ? substr( $line, strlen( $prefix[0] ) ) : $line, false, $allowMainColon );
				if ( $reference === null || ( !$bullet && trim( $reference['tail'] ) !== '' ) ) {
					if ( trim( $line ) !== '' ) { $unscopedSafe = false; $complete = false; }
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
			$unscopedInstance = false;
			foreach ( $events as $event ) {
				if ( $event['kind'] === 'section' ) {
					$unscopedInstance = preg_match( '/\b(?:Normal|Raid)[ _]+(?:Instance|Zone)\b/i', $event['label'] ) === 1;
					if ( $unscopedInstance ) { $zoneScopes = []; }
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
					$unscopedInstance = false;
					$key = $instance . '|' . $zone;
					if ( isset( $zoneScopes[$key] ) ) {
						$complete = false;
						return [];
					}
					$zoneScopes[$key] = true;
					continue;
				}
				if ( $hasZones && ( $event['kind'] !== 'mob' || ( $zone === '' && !$unscopedInstance ) ) ) {
					continue;
				}
				$row = [ 'npc' => $event['title'], 'zone' => $zone, 'instance' => $instance ];
				$results[$row['npc'] . '|' . $zone . '|' . $instance] ??= $row;
			}
		}
		return array_values( $results );
	}

	private static function title( string $value, bool $allowMainColon = false ): ?string {
		$value = trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$value = str_replace( '_', ' ', $value );
		$value = preg_replace( '/[\s\p{Z}]+/u', ' ', $value );
		$invalid = $allowMainColon ? '/[\x00-\x1f\x7f\[\]{}<>|#~]/' : '/[\x00-\x1f\x7f\[\]{}<>|:#~]/';
		if ( !$value || in_array( strtolower( $value ), [ '?', 'none', 'unknown' ], true ) || strlen( $value ) > 240 || preg_match( $invalid, $value ) || $value[0] === '.' ||
			$allowMainColon && preg_match( '/^(?:File|Image|Category|Template|Special|Talk|User|MediaWiki|Help|Project|EQLWiki|Module|Media)(?:[ _]talk)?\s*:/i', $value ) ) {
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
	private static function roots( string $source, bool $globalExport = false ): ?array {
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
					if ( $globalExport ) { $i++; continue; }
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
					$rawName = array_shift( $parts );
					$name = strtolower( str_replace( '_', ' ', trim( $rawName ) ) );
					$name = preg_replace( '/^template\s*:\s*/', '', $name );
					$fields = [];
					$parameterNames = [];
					foreach ( $parts as $part ) {
						if ( preg_match( '/^\s*([A-Za-z][A-Za-z0-9_ ]*)\s*=([\s\S]*)$/', $part, $field ) ) {
							$key = strtolower( trim( $field[1] ) );
							if ( array_key_exists( $key, $fields ) && !( $globalExport && $name === 'namedmobpage' && $parameterNames[$key] === trim( $field[1] ) ) ) {
								return null;
							}
							$parameterNames[$key] = trim( $field[1] );
							$fields[$key] = $field[2];
						} elseif ( $globalExport && $name === 'namedmobpage' && preg_match( '/^\s*[^=]*\{\{[^=]*=/', $part ) ) {
							return null;
						}
					}
					$roots[] = [ 'start' => $start, 'end' => $i + 1, 'name' => $name, 'invocationKey' => self::templateInvocationKey( $rawName ), 'parts' => $parts, 'fields' => $fields ];
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

	private static function document( string $source, bool $globalExport = false ): ?array {
		if ( strlen( $source ) > self::MAX_BYTES || preg_match( '//u', $source ) !== 1 ) { return null; }
		$active = self::active( $source );
		$templates = self::roots( $active, $globalExport );
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

	/** Global counts cannot prove a small total through an unexpanded unknown wrapper. */
	private static function sourceSyntaxComplete( string $field, bool $items, int $depth = 0, bool $globalExport = false, array $verifiedItemTemplates = [] ): bool {
		if ( $depth > 8 ) { return false; }
		$roots = self::roots( self::active( $field ) );
		if ( $roots === null ) { return false; }
		foreach ( $roots as $template ) {
			if ( $items && str_starts_with( $template['name'], ':' ) ) { continue; }
			if ( in_array( $template['name'], [ 'era', 'loc' ], true ) ) { continue; }
			if ( $globalExport && $items && ( self::globalLootMetadata( $template ) || self::verifiedItemTemplate( $template, $verifiedItemTemplates ) !== null ) ) { continue; }
			if ( !in_array( $template['name'], $items ? [ 'veliousgray' ] : [ 'veliousgray', 'itemwheretable', 'itemwhererow' ], true ) ) { return false; }
			foreach ( $template['parts'] as $part ) {
				if ( !self::sourceSyntaxComplete( $part, $items, $depth + 1, $globalExport, $verifiedItemTemplates ) ) { return false; }
			}
		}
		return true;
	}

	private static function fieldText( string $field, bool $items, int $depth = 0, bool $globalExport = false, array $verifiedItemTemplates = [] ): string {
		if ( $depth > 8 ) { return ''; }
		$roots = self::roots( $field );
		if ( $roots === null ) { return ''; }
		foreach ( array_reverse( $roots ) as $template ) {
			$replacement = self::mask( substr( $field, $template['start'], $template['end'] - $template['start'] ) );
			if ( $template['name'] === 'veliousgray' && count( $template['parts'] ) === 1 ) {
				$replacement = self::fieldText( $template['parts'][0], $items, $depth + 1, $globalExport, $verifiedItemTemplates );
			} elseif ( $globalExport && $items && ( $verified = self::verifiedItemTemplate( $template, $verifiedItemTemplates ) ) !== null ) {
				$replacement = '{{:' . $verified . '}}';
			} elseif ( $items && str_starts_with( $template['name'], ':' ) ) {
				continue;
			}
			$field = substr_replace( $field, $replacement, $template['start'], $template['end'] - $template['start'] );
		}
		return $field;
	}

	private static function verifiedItemTemplate( array $template, array $verified ): ?string {
		if ( $template['parts'] ) { return null; }
		$title = $verified[$template['invocationKey'] ?? ''] ?? null;
		return is_string( $title ) ? self::title( $title, true ) : null;
	}

	/** Reviewed public templates emit badges, lore text or static warnings only. */
	private static function globalLootMetadata( array $template ): bool {
		if ( !in_array( $template['invocationKey'] ?? '', [ 'Chardok Revamp Era Inline', 'Item Lore', 'Unconfirmed Itemlist', 'P1999', 'Era Short', 'Lootcheck' ], true ) ) { return false; }
		foreach ( $template['parts'] as $part ) {
			if ( preg_match( '/\{\{|\[\[/', self::active( $part ) ) ) { return false; }
		}
		return true;
	}

	private static function lines( string $text ): array {
		$text = preg_replace_callback( '~<li\b[^>]*>([\s\S]*?)</li\s*>~i', static function ( $m ) {
			return stripos( $m[1], '<li' ) !== false ? "\n" : "\n* " . trim( $m[1] ) . "\n";
		}, $text );
		$text = preg_replace( '~</?(?:ul|ol)\b[^>]*>|<br\s*/?>~i', "\n", $text );
		return preg_split( '/\r\n|\n|\r/', $text );
	}

	private static function leadingReference( string $text, bool $items, bool $allowMainColon = false ): ?array {
		$text = ltrim( $text );
		if ( preg_match( '/^\[\[([^\]|\r\n]+)(?:\|[^\]\r\n]*)?\]\]/', $text, $match ) ||
			$items && preg_match( '/^\{\{\s*:\s*([^}|\r\n]+)(?:\|[^}\r\n]*)?\}\}/', $text, $match ) ) {
			$target = explode( '#', $match[1], 2 );
			$title = self::title( $target[0], $allowMainColon );
			if ( $title !== null ) { return [ 'title' => $title, 'anchor' => $target[1] ?? '', 'tail' => substr( $text, strlen( $match[0] ) ) ]; }
		}
		return null;
	}

	/** Explicit structured rows are independent of surrounding bullet-zone scope. */
	private static function structuredSources( string $source, string $base, int $depth = 0, bool $allowMainColon = false ): array {
		if ( $depth > 8 ) { return []; }
		$document = self::document( $source );
		if ( $document === null ) { return []; }
		$rows = [];
		foreach ( $document['templates'] as $template ) {
			$instance = self::instanceAt( $document['outline'], $template['start'], $base );
			if ( in_array( $template['name'], [ 'itemwheretable', 'veliousgray' ], true ) ) {
				if ( count( $template['parts'] ) === 1 ) {
					$part = preg_replace( '/^\s*1\s*=/', '', $template['parts'][0] );
					$rows = array_merge( $rows, self::structuredSources( $part, $instance, $depth + 1, $allowMainColon ) );
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
			$zone = self::leadingReference( self::fieldText( $arguments[1] ?? '', false ), false, $allowMainColon );
			$npc = self::leadingReference( self::fieldText( $arguments[2] ?? '', false ), false, $allowMainColon );
			if ( $zone === null || $npc === null || trim( $zone['tail'] ) !== '' || trim( $npc['tail'] ) !== '' ) { continue; }
			$anchor = self::identity( $zone['anchor'] );
			if ( preg_match( '/\braid\s+(?:instance|zone)\b/', $anchor ) ) { $instance = 'raid'; }
			elseif ( preg_match( '/\bnormal\s+(?:instance|zone)\b/', $anchor ) ) { $instance = 'normal'; }
			$rows[] = [ 'npc' => $npc['title'], 'zone' => $zone['title'], 'instance' => $instance ];
		}
		return $rows;
	}
}
