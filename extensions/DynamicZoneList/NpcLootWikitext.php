<?php
/**
 * Bounded, read-only recognition of authored NPC loot and item drop sources.
 * No MediaWiki services, template expansion, database access or page writes.
 */
final class EqlNpcLootWikitext {
	private const MAX_BYTES = 524288;

	/** Whether the bounded rich card exposes all authored item-card metadata. */
	public static function itemContributionCard( string $source, ?array $displayLines = null ): array {
		$result = [ 'schemaVersion' => 1, 'complete' => false, 'questContext' => false ];
		$document = self::document( $source );
		if ( $document === null ) { return $result; }
		$cards = array_values( array_filter( $document['templates'], static fn ( $template ) => $template['name'] === 'itempage' ) );
		if ( count( $cards ) !== 1 ) { return $result; }
		$card = $cards[0];
		if ( $card['invocationKey'] !== 'Itempage' ) { return $result; }
		// Keep the literal invocation, rather than treating a masked example or
		// stripped dynamic value as proof that no modifier was authored.
		$literal = substr( $source, $card['start'], $card['end'] - $card['start'] );
		$literal = preg_replace_callback( '/<!--[\s\S]*?(?:-->|$)/', static fn ( $m ) => self::mask( $m[0] ), $literal );
		if ( preg_match( '~</?(?:nowiki|pre|source|syntaxhighlight|ref|code)\b~i', $literal ) ) { return $result; }
		$parts = self::parts( substr( $literal, 2, -2 ) );
		if ( $parts === null ) { return $result; }
		array_shift( $parts );
		$fields = [];
		$parameterNames = [];
		foreach ( $parts as $part ) {
			if ( trim( $part ) === '' ) { continue; }
			if ( !preg_match( '/^\s*([A-Za-z][A-Za-z0-9_ ]*)\s*=([\s\S]*)$/', $part, $field ) ) { return $result; }
			$key = strtolower( trim( $field[1] ) );
			if ( array_key_exists( $key, $fields ) ) { return $result; }
			$fields[$key] = $field[2];
			$parameterNames[$key] = trim( $field[1] );
		}
		$result['questContext'] = ( $parameterNames['relatedquests'] ?? '' ) === 'relatedquests' && trim( $fields['relatedquests'] ?? '' ) !== '';
		$known = [ 'itemname', 'statsblock', 'dropsfrom', 'drops_from', 'lucy_img_id', 'lucy_img_ID', 'icon', 'icon_id',
			'notes', 'soldby', 'merchant_value', 'recipes', 'relatedquests', 'foraged' ];
		if ( trim( $fields['itemname'] ?? '' ) === '' || trim( $fields['statsblock'] ?? '' ) === '' ||
			( isset( $fields['dropsfrom'] ) && isset( $fields['drops_from'] ) ) ) { return $result; }
		foreach ( $fields as $key => $value ) {
			if ( trim( $value ) === '' ) { continue; }
			// MediaWiki named parameters are case-sensitive. A differently cased
			// ignored field cannot borrow literal metadata proof from a real one.
			if ( !in_array( $parameterNames[$key], $known, true ) ) { return $result; }
			if ( in_array( $key, [ 'itemname', 'lucy_img_id', 'icon', 'icon_id' ], true ) && preg_match( '/\{\{|\}\}/', $value ) ) { return $result; }
			if ( $key !== 'statsblock' && !self::literalCardContext( $value ) ) { return $result; }
		}
		$stats = $fields['statsblock'];
		$literalMarkup = preg_replace( '~<(?:br\s*/?\s*|/?(?:b|strong|i|em|p)\s*)>~i', '', $stats );
		if ( preg_match( '/\{\{|\}\}/', $stats ) || strpbrk( $literalMarkup, '<>' ) !== false ) { return $result; }
		$lines = [];
		foreach ( preg_split( '/\r\n|\n|\r/', preg_replace( '/<br\s*\/?\s*>/i', "\n", $stats ) ) as $line ) {
			$line = preg_replace( '/\[\[([^\]|]+)\|([^\]]+)\]\]/', '$2', $line );
			$line = preg_replace( '/\[\[([^\]]+)\]\]/', '$1', $line );
			$line = trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $line ), ENT_QUOTES | ENT_HTML5 ) ) );
			if ( $line !== '' ) { $lines[] = $line; }
		}
		// The rich exporter exposes at most sixteen nonempty lines. A hidden
		// seventeenth effect/custom modifier cannot become an absence proof.
		if ( !$lines || count( $lines ) > 16 || ( $displayLines !== null && $lines !== $displayLines ) ) { return $result; }
		$result['complete'] = true;
		return $result;
	}

	/** Only reviewed literal layout wrappers are allowed inside context fields. */
	private static function literalCardContext( string $value, int $depth = 0 ): bool {
		if ( $depth >= 8 || str_contains( $value, '{{{' ) ) { return false; }
		$templates = self::roots( $value );
		if ( $templates === null || count( $templates ) > 1000 ) { return false; }
		foreach ( $templates as $template ) {
			if ( !in_array( $template['invocationKey'], [ 'ItemWhereTable', 'ItemWhereRow', 'Loc', 'VeliousGray', 'Era' ], true ) ) { return false; }
			foreach ( $template['parts'] as $part ) {
				if ( !self::literalCardContext( $part, $depth + 1 ) ) { return false; }
			}
		}
		return true;
	}

	/** Page kind is independent of whether an affected field is editable/complete. */
	public static function authoredPageKind( string $source ): string {
		$document = self::document( $source, false, true );
		if ( $document === null ) { return 'unknown'; }
		$kinds = [];
		foreach ( $document['templates'] as $template ) {
			$kind = [ 'itempage' => 'item', 'namedmobpage' => 'npc', 'npcpage' => 'npc',
				'merchantpage' => 'merchant', 'spellpagesmart' => 'spell', 'spellpage' => 'spell' ][$template['name']] ?? null;
			if ( $kind !== null ) { $kinds[$kind] = true; }
		}
		return count( $kinds ) === 1 ? array_key_first( $kinds ) : ( $kinds ? 'unknown' : 'other' );
	}

	/** Possible literal members constrain uncertainty only; never positive loot facts. */
	public static function possibleLootItems( string $source, ?bool &$bounded = null ): array {
		$bounded = strlen( $source ) <= self::MAX_BYTES && preg_match( '//u', $source ) === 1;
		if ( !$bounded ) { return []; }
		$active = self::active( $source );
		$document = self::document( $active, false, true );
		if ( $document === null ) { $bounded = false; }
		$items = self::lootReferences( $active, true, true );
		$inspect = static function ( string $text, int $depth = 0 ) use ( &$inspect, &$bounded, &$items ): void {
			if ( $depth >= 8 ) { $bounded = false; return; }
			$roots = self::roots( $text, false, true );
			if ( $roots === null ) { $bounded = false; return; }
			foreach ( $roots as $template ) {
				if ( str_starts_with( $template['name'], ':' ) ) {
					// Preserve actual title case from the source reference instead of the normalized name.
					$reference = self::leadingReference( substr( $text, $template['start'] ), true, true );
					if ( $reference ) { self::unique( $items, $reference['title'] ); }
					if ( $template['parts'] ) { $bounded = false; }
					continue;
				}
				if ( in_array( $template['name'], [ 'itempage', 'namedmobpage', 'npcpage', 'merchantpage', 'gear set', 'veliousgray', 'itemwheretable', 'itemwhererow', 'loc' ], true ) ||
					!$template['parts'] && preg_match( '/(?:^| )era$/', $template['name'] ) || self::globalLootMetadata( $template ) ) {
					foreach ( $template['parts'] as $part ) {
						if ( $template['name'] === 'gear set' ) {
							$literal = preg_replace( '/^\s*[0-9]+\s*=/', '', $part );
							$title = self::title( $literal, true );
							if ( $title !== null && !str_contains( $literal, '=' ) ) { self::unique( $items, $title ); }
						}
						$inspect( $part, $depth + 1 );
					}
				} else { $bounded = false; }
			}
		};
		$inspect( $active );
		preg_match_all( '/\[\[([^\[\]|\r\n]+)(?:\|[^\[\]\r\n]*)?\]\]/', $active, $links );
		foreach ( $links[1] as $link ) { $title = self::title( explode( '#', $link, 2 )[0], true ); if ( $title !== null ) { self::unique( $items, $title ); } }
		if ( count( $items ) > 1000 ) { $bounded = false; $items = array_slice( $items, 0, 1000 ); }
		return $items;
	}

	/** Localize malformed NPC loot uncertainty without reading class/faction/quest links. */
	public static function possibleNpcLootItems( string $source, ?bool &$bounded = null ): array {
		$document = self::document( $source, false, true );
		$bounded = $document !== null;
		if ( !$document ) { return []; }
		$items = [];
		foreach ( $document['templates'] as $template ) {
			if ( $template['name'] !== 'namedmobpage' ) { continue; }
			$last = [];
			foreach ( $template['parts'] as $position => $part ) {
				if ( preg_match( '/^\s*(known_loot|common_loot)\s*=/i', $part, $named ) ) { $last[strtolower( $named[1] )] = $position; }
			}
			foreach ( $template['parts'] as $position => $part ) {
				if ( preg_match( '/^\s*(known_loot|common_loot)\s*=/i', $part, $named ) && $last[strtolower( $named[1] )] !== $position ) { continue; }
				if ( preg_match( '/^\s*(?:known_loot|common_loot)(?:\s*=|[ \t]*(?=\r?\n|<|\[\[|\{\{|[*#]))([\s\S]*)$/i', $part, $match ) ) {
					self::possibleLootItems( $match[1], $fieldBounded );
					$possible = [];
					foreach ( self::lines( self::active( $match[1] ) ) as $line ) {
						$lineItems = self::npcSourceReferences( $line, true, true );
						// A standalone prose group may describe unknown materials. It is
						// uncertainty only, unlike a note after a proven literal loot row.
						if ( !$lineItems ) { $lineItems = self::possibleLootItems( $line, $lineBounded ); }
						foreach ( $lineItems as $item ) { self::unique( $possible, $item ); }
					}
					$bounded = $bounded && $fieldBounded;
					foreach ( $possible as $item ) { self::unique( $items, $item ); }
				} elseif ( trim( $part ) !== '' && !preg_match( '/^\s*[A-Za-z][A-Za-z0-9_ ]*\s*=/', $part ) ) {
					$bounded = false;
				}
			}
		}
		if ( count( $items ) > 1000 ) { $bounded = false; return array_slice( $items, 0, 1000 ); }
		return $items;
	}

	/** Affected authored fields only; positive facts and edit capability are separate. */
	public static function contributionCoverage( string $source, string $kind, array $verifiedItemTemplates = [] ): array {
		if ( $kind === 'zone' ) { return self::zoneContributionCoverage( $source ); }
		$result = [ 'schemaVersion' => 1, 'kind' => $kind, 'state' => 'unsupported', 'complete' => false,
			'edges' => [], 'scopes' => [], 'collectionRefs' => [] ];
		$document = self::document( $source, false, true );
		if ( $document === null ) { return $result + [ 'reason' => 'malformed-or-conflicting-source' ]; }
		$names = $kind === 'item' ? [ 'itempage' ] : ( $kind === 'npc' ? [ 'namedmobpage' ] : [ 'zonetoptable' ] );
		$cards = array_values( array_filter( $document['templates'], static fn ( $card ) => in_array( $card['name'], $names, true ) ) );
		$seen = [];
		$variant = false;
		foreach ( $cards as $index => $card ) {
			$instance = self::instanceAt( $document['outline'], $card['start'], 'normal' );
			if ( isset( $seen[$instance] ) ) { $variant = true; }
			$seen[$instance] = true;
			if ( $kind === 'item' && count( $cards ) > 1 ) { $variant = true; }
			$fields = $kind === 'item' ? [ 'dropsfrom', 'drops_from' ] : ( $kind === 'npc' ? [ 'known_loot', 'common_loot' ] : [ 'unique_items', 'common_items' ] );
			$present = array_values( array_filter( $fields, static fn ( $field ) => array_key_exists( $field, $card['fields'] ) ) );
			$fieldCounts = [];
			foreach ( $card['parts'] as $part ) { if ( preg_match( '/^\s*([A-Za-z][A-Za-z0-9_ ]*)\s*=/', $part, $named ) ) { $key = strtolower( trim( $named[1] ) ); $fieldCounts[$key] = ( $fieldCounts[$key] ?? 0 ) + 1; } }
			if ( !$present ) {
				$result['scopes'][] = [ 'zone' => '', 'instance' => $instance, 'field' => $fields[0], 'state' => 'unsupported', 'reason' => 'missing-field' ];
			}
			foreach ( $present as $field ) {
				$value = $card['fields'][$field];
				$scopes = self::coverageFieldScopes( $value, $kind !== 'item', $instance, $verifiedItemTemplates );
				$conflict = ( $fieldCounts[$field] ?? 0 ) > 1 || $kind === 'item' && count( $present ) > 1;
				if ( $conflict ) { foreach ( $scopes as &$scope ) { $scope['state'] = 'unsupported'; $scope['reason'] = 'conflicting-source-fields'; } unset( $scope ); }
				foreach ( $scopes as $scope ) { $result['scopes'][] = $scope + [ 'field' => $field, 'card' => $index ]; }
				if ( $conflict ) { continue; }
				$cardSource = ( $instance === 'raid' ? "== Raid Instance ==\n" : '' ) . substr( $source, $card['start'], $card['end'] - $card['start'] );
				if ( $kind === 'item' ) {
					if ( $conflict ) { continue; }
					$cardSource = ( $instance === 'raid' ? "== Raid Instance ==\n" : '' ) . '{{Itempage|dropsfrom=' . $value . '}}';
					$zoneLabels = [];
					preg_match_all( '/^\s*\[\[([^\]|\r\n]+)\|([^\]\r\n]+)\]\]\s*$/m', self::active( $value ), $headings, PREG_SET_ORDER );
					foreach ( $headings as $heading ) { $zoneLabels[$heading[1]][$heading[2]] = true; }
					foreach ( $result['scopes'] as &$scope ) {
						if ( ( $scope['card'] ?? null ) === $index && $scope['field'] === $field && count( $zoneLabels[$scope['zone']] ?? [] ) > 1 ) { $scope['state'] = 'unsupported'; $scope['reason'] = 'ambiguous-zone-display'; }
					} unset( $scope );
					foreach ( self::itemSources( $cardSource, $complete, true ) as $edge ) {
						if ( count( $zoneLabels[$edge['zone']] ?? [] ) > 1 ) { $edge['zone'] = ''; }
						$result['edges'][] = $edge + [ 'precision' => $edge['zone'] === '' ? 'npc' : 'zone-npc', 'field' => $field ];
					}
				} else {
					$zoneText = self::fieldText( $card['fields']['zone'] ?? '', false );
					preg_match_all( '/\[\[([^\]|\r\n]+)(?:\|[^\]\r\n]*)?\]\]/', $zoneText, $zones );
					$zoneNames = array_values( array_filter( array_map( static fn ( $name ) => self::title( explode( '#', $name, 2 )[0], true ), $zones[1] ) ) ) ?: [ '' ];
					$stack = [];
					$referenceRows = [];
					foreach ( self::lines( self::active( $value ) ) as $line ) {
						if ( preg_match( '/^(={1,6})\s*(.*?)\s*\1$/', trim( $line ), $heading ) ) {
							while ( $stack && end( $stack )['level'] >= strlen( $heading[1] ) ) { array_pop( $stack ); }
							$stack[] = [ 'level' => strlen( $heading[1] ), 'label' => $heading[2] ];
							continue;
						}
						foreach ( self::npcSourceReferences( $line, true, true, $verifiedItemTemplates ) as $title ) {
							$referenceRows[] = [ 'title' => $title, 'instance' => self::stackInstance( $stack, $instance ), 'section' => $stack ? end( $stack )['label'] : '' ];
						}
					}
					foreach ( $referenceRows as $reference ) {
						foreach ( $zoneNames as $zone ) {
							$title = $reference['title'];
							$edge = [ 'item' => $title, 'zone' => $zone, 'instance' => $reference['instance'], 'section' => $reference['section'], 'lootKind' => $field === 'common_loot' || $field === 'common_items' ? 'common' : 'known', 'field' => $field ];
							$result['edges'][] = $edge;
							$result['collectionRefs'][] = [ 'title' => $title ] + $edge;
						}
					}
					foreach ( $result['scopes'] as &$scope ) {
						if ( ( $scope['card'] ?? null ) === $index && $scope['field'] === $field && count( $zoneNames ) === 1 ) { $scope['zone'] = $zoneNames[0]; }
					} unset( $scope );
				}
			}
		}
		if ( !$cards ) { return $result + [ 'reason' => 'missing-supported-card' ]; }
		$states = array_column( $result['scopes'], 'state' );
		$result['state'] = $variant ? 'variant' : ( in_array( 'unsupported', $states, true ) ? 'unsupported' : ( in_array( 'broad', $states, true ) ? 'broad' : 'exact' ) );
		$result['complete'] = $result['state'] === 'exact';
		if ( $variant ) {
			foreach ( $result['scopes'] as &$scope ) { $scope['state'] = 'variant'; $scope['reason'] = 'multiple-variants'; } unset( $scope );
			foreach ( $result['edges'] as &$edge ) { $edge['ambiguous'] = true; } unset( $edge );
		}
		$result['edges'] = array_values( array_unique( $result['edges'], SORT_REGULAR ) );
		$result['collectionRefs'] = array_values( array_unique( $result['collectionRefs'], SORT_REGULAR ) );
		if ( count( $result['edges'] ) > 1000 || count( $result['collectionRefs'] ) > 1000 || count( $result['scopes'] ) > 1000 ) {
			$result['state'] = 'unsupported'; $result['complete'] = false; $result['reason'] = 'coverage-limit';
			foreach ( $result['scopes'] as &$scope ) { $scope['state'] = 'unsupported'; $scope['reason'] = 'coverage-limit'; } unset( $scope );
		}
		$result['edges'] = array_slice( $result['edges'], 0, 1000 );
		$result['collectionRefs'] = array_slice( $result['collectionRefs'], 0, 1000 );
		$result['scopes'] = array_slice( $result['scopes'], 0, 1000 );
		return $result;
	}

	private static function coverageFieldScopes( string $value, bool $items, string $base, array $verified ): array {
		$scopes = [ [ 'zone' => '', 'instance' => $base, 'state' => 'exact' ] ];
		$current = 0;
		$stack = [];
		$syntaxComplete = self::sourceSyntaxComplete( $value, $items, 0, true, $verified );
		foreach ( self::lines( self::fieldText( self::active( $value ), $items, 0, true, $verified ) ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || preg_match( '/^(?:\?|None|[*#]?\s*None|This item is not dropped by mobs\.)$/i', $line ) ) { continue; }
			if ( preg_match( '/^(={1,6})\s*(.*?)\s*\1$/', $line, $heading ) ) {
				while ( $stack && end( $stack )['level'] >= strlen( $heading[1] ) ) { array_pop( $stack ); }
				$stack[] = [ 'level' => strlen( $heading[1] ), 'label' => $heading[2] ];
				$current = count( $scopes );
				$scopes[] = [ 'zone' => '', 'instance' => self::stackInstance( $stack, $base ), 'section' => $heading[2], 'state' => 'exact' ];
				continue;
			}
			$bullet = preg_match( '/^[*#]+\s*/', $line, $prefix ) === 1;
			$reference = self::leadingReference( $bullet ? substr( $line, strlen( $prefix[0] ) ) : $line, $items, true );
			if ( !$items && !$bullet && $reference && trim( $reference['tail'] ) === '' ) {
				$current = count( $scopes );
				$scopes[] = [ 'zone' => $reference['title'], 'instance' => self::stackInstance( $stack, $base ), 'state' => 'exact' ];
				continue;
			}
			$residual = preg_replace( '/\[\[[^\[\]\r\n]*\]\]|\{\{\s*:[^{}]*\}\}/', '', $line );
			$residual = trim( preg_replace( '/[*#]|<[^>]*>|\x27{2,}/', '', $residual ) );
			if ( $residual === '' ) { continue; }
			$broad = preg_match( '/\b(?:various|many|most|all|any|random|newbie|mobs?|monsters?|types|wing)\b|\b(?:common\s+)?drops?\s+from\s+low[ -]level\s+humanoids?\b/i', str_replace( '_', ' ', $residual ) ) === 1;
			$state = $broad ? 'broad' : 'unsupported';
			if ( $scopes[$current]['state'] !== 'unsupported' ) { $scopes[$current]['state'] = $state; $scopes[$current]['reason'] = $broad ? 'generic-source-group' : 'unparsed-or-qualified-source'; }
		}
		if ( !$syntaxComplete ) { $scopes[] = [ 'zone' => '', 'instance' => $base, 'state' => 'unsupported', 'reason' => 'unknown-template' ]; }
		return $scopes;
	}

	/** Finite unconditional list candidates; caller must verify every public member. */
	public static function finiteCollectionItems( string $source, ?bool &$complete = null ): array {
		$complete = false;
		$document = self::document( $source );
		if ( $document === null ) { return []; }
		$sets = array_values( array_filter( $document['templates'], static fn ( $t ) => $t['name'] === 'gear set' ) );
		if ( $sets ) { return count( $sets ) === 1 ? self::globalGearSetItems( $source, $complete ) : []; }
		$active = self::active( $source );
		if ( preg_match( '/\{\||\|\}|^\s*=|\b(?:only|except|depending|conditional|male|female|slots?)\b/im', $active ) ) { return []; }
		$body = $active;
		if ( preg_match_all( '~<ul\b[^>]*>([\s\S]*?)</ul\s*>~i', $active, $lists, PREG_OFFSET_CAPTURE ) ) {
			if ( count( $lists[0] ) !== 1 || preg_match( '~<(?:ul|ol)\b~i', $lists[1][0][0] ) ) { return []; }
			$body = $lists[0][0][0];
			$start = $lists[0][0][1];
		} elseif ( preg_match( '/^(?:[^\r\n]*\r?\n)*?([*#][\s\S]*)$/', $active, $list, PREG_OFFSET_CAPTURE ) ) { $body = $list[1][0]; $start = $list[1][1]; }
		else { return []; }
		foreach ( $document['templates'] as $template ) {
			if ( $template['start'] < $start && $template['end'] > $start ) { return []; }
			$inside = $template['start'] >= $start && $template['end'] <= $start + strlen( $body );
			if ( !$inside && !( !$template['parts'] && preg_match( '/(?:^| )era$/', $template['name'] ) ) ) { return []; }
		}
		$members = [];
		foreach ( self::lines( $body ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) { continue; }
			if ( !preg_match( '/^[*#]\s*/', $line, $prefix ) ) { return []; }
			$reference = self::leadingReference( substr( $line, strlen( $prefix[0] ) ), true, true );
			if ( !$reference || trim( $reference['tail'] ) !== '' || count( $members ) >= 1000 ) { return []; }
			self::unique( $members, $reference['title'] );
		}
		$complete = count( $members ) > 0;
		return $members;
	}

	/** Existing zone summary cells and dynamic category consumers, never whole-page prose. */
	public static function zoneContributionCoverage( string $source ): array {
		$result = [ 'schemaVersion' => 1, 'kind' => 'zone', 'state' => 'unsupported', 'complete' => false,
			'edges' => [], 'scopes' => [], 'collectionRefs' => [], 'dynamicLists' => [] ];
		$document = self::document( $source );
		if ( $document === null ) { return $result + [ 'reason' => 'malformed-or-conflicting-source' ]; }
		$active = self::active( $source );
		preg_match_all( '/^[ \t]*(\{\|[^\r\n]*|\|\})[^\r\n]*\r?$/m', $active, $markers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$stack = [];
		$tables = [];
		$balanced = true;
		foreach ( $markers as $marker ) {
			if ( str_starts_with( $marker[1][0], '{|' ) ) { $stack[] = $marker; }
			elseif ( !$stack ) { $balanced = false; }
			else {
				$start = array_pop( $stack );
				if ( preg_match( '/\bzoneTopTable\b/', $start[0][0] ) ) {
					$tables[] = [ 'start' => $start[0][1], 'end' => $marker[0][1] + strlen( $marker[0][0] ) ];
				}
			}
		}
		if ( $stack ) { $balanced = false; }
		usort( $tables, static fn ( $a, $b ) => $a['start'] <=> $b['start'] );
		$instances = [ 'normal' => true ];
		$counts = [];
		foreach ( $tables as $card => $table ) {
			$instance = self::instanceAt( $document['outline'], $table['start'], 'normal' );
			$instances[$instance] = true;
			$text = substr( $active, $table['start'], $table['end'] - $table['start'] );
			preg_match_all( '/^![ \t]*([^\r\n]+)\r?\n\|[ \t]*([\s\S]*?)(?=^\|-|^\|\}|\z)/m', $text, $rows, PREG_SET_ORDER );
			foreach ( $rows as $row ) {
				$label = self::identity( preg_replace( '/:[ \t]*$/', '', str_replace( [ "'''", "''" ], '', trim( $row[1] ) ) ) );
				$field = [ 'unique items' => 'unique_items', 'common loot' => 'common_loot', 'notable npcs' => 'notable_npcs' ][$label] ?? null;
				if ( $field === null ) { continue; }
				$counts[$instance][$field] = ( $counts[$instance][$field] ?? 0 ) + 1;
				$isItems = $field !== 'notable_npcs';
				$scopes = self::coverageFieldScopes( $row[2], $isItems, $instance, [] );
				foreach ( $scopes as $scope ) {
					if ( preg_match( '/^\s*(?:n\/?a|none|\?|-)\s*$/i', trim( $row[2] ) ) ) { $scope['state'] = 'exact'; unset( $scope['reason'] ); }
					$result['scopes'][] = $scope + [ 'field' => $field, 'card' => $card ];
				}
				foreach ( self::lines( $row[2] ) as $line ) {
					foreach ( self::lootReferences( $line, true, $isItems ) as $name ) {
						$result['edges'][] = [ $isItems ? 'item' : 'npc' => $name, 'zone' => '', 'instance' => $instance, 'field' => $field ];
					}
				}
			}
		}
		preg_match_all( '/\{\{[ \t]*Special[ \t]*:[ \t]*DynamicZoneList\/([^{}|\r\n]+?)[ \t]*\}\}/i', $active, $dynamic, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $dynamic as $row ) {
			$zone = self::title( trim( $row[1][0] ), true );
			if ( $zone === null ) { continue; }
			$instance = self::instanceAt( $document['outline'], $row[0][1], 'normal' );
			$instances[$instance] = true;
			$result['dynamicLists'][] = [ 'zone' => $zone, 'instance' => $instance, 'field' => 'dynamic_population' ];
			$result['scopes'][] = [ 'zone' => $zone, 'instance' => $instance, 'field' => 'dynamic_population', 'state' => 'exact' ];
		}
		foreach ( array_keys( $instances ) as $instance ) {
			foreach ( [ 'unique_items', 'common_loot', 'notable_npcs' ] as $field ) {
				$count = $counts[$instance][$field] ?? 0;
				if ( $count === 1 && $balanced ) { continue; }
				foreach ( $result['scopes'] as &$scope ) {
					if ( $scope['instance'] === $instance && $scope['field'] === $field ) {
						$scope['state'] = $count > 1 ? 'variant' : 'unsupported';
						$scope['reason'] = !$balanced ? 'unbalanced-zone-table' : 'multiple-summary-cells';
					}
				} unset( $scope );
				if ( !$count ) { $result['scopes'][] = [ 'zone' => '', 'instance' => $instance, 'field' => $field, 'state' => 'unsupported', 'reason' => 'missing-section' ]; }
			}
		}
		$states = array_column( $result['scopes'], 'state' );
		$result['state'] = !$balanced || in_array( 'unsupported', $states, true ) ? 'unsupported' : ( in_array( 'variant', $states, true ) ? 'variant' : ( in_array( 'broad', $states, true ) ? 'broad' : 'exact' ) );
		$result['complete'] = $result['state'] === 'exact';
		$limited = count( $result['edges'] ) > 1000 || count( $result['dynamicLists'] ) > 100 || count( $result['scopes'] ) > 1000;
		$result['edges'] = array_slice( array_values( array_unique( $result['edges'], SORT_REGULAR ) ), 0, 1000 );
		$result['dynamicLists'] = array_slice( array_values( array_unique( $result['dynamicLists'], SORT_REGULAR ) ), 0, 100 );
		$result['scopes'] = array_slice( $result['scopes'], 0, 1000 );
		if ( $limited ) {
			$result['state'] = 'unsupported'; $result['complete'] = false; $result['reason'] = 'coverage-limit';
			foreach ( $result['scopes'] as &$scope ) { $scope['state'] = 'unsupported'; $scope['reason'] = 'coverage-limit'; } unset( $scope );
		}
		return $result;
	}

	/** Structural possible members only: prose links do not recursively define a loot catalog. */
	public static function possibleCollectionItems( string $source, ?bool &$bounded = null ): array {
		$bounded = strlen( $source ) <= self::MAX_BYTES && preg_match( '//u', $source ) === 1;
		if ( !$bounded ) { return []; }
		$active = self::active( $source );
		$regions = [];
		$stack = [];
		preg_match_all( '/^[ \t]*(\{\|[^\r\n]*|\|\})[^\r\n]*\r?$/m', $active, $markers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $markers as $marker ) {
			if ( str_starts_with( $marker[1][0], '{|' ) ) { $stack[] = $marker[0][1]; }
			elseif ( !$stack ) { $bounded = false; }
			else {
				$start = array_pop( $stack );
				if ( !$stack ) { $regions[] = [ $start, $marker[0][1] + strlen( $marker[0][0] ) ]; }
			}
		}
		if ( $stack ) { $bounded = false; $regions[] = [ $stack[0], strlen( $active ) ]; }
		$stack = [];
		preg_match_all( '~</?(ul|ol|table)\b[^>]*>~i', $active, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $tags as $tag ) {
			if ( !str_starts_with( $tag[0][0], '</' ) ) { $stack[] = [ 'name' => strtolower( $tag[1][0] ), 'start' => $tag[0][1] ]; }
			elseif ( !$stack || end( $stack )['name'] !== strtolower( $tag[1][0] ) ) { $bounded = false; }
			else {
				$open = array_pop( $stack );
				if ( !$stack ) { $regions[] = [ $open['start'], $tag[0][1] + strlen( $tag[0][0] ) ]; }
			}
		}
		if ( $stack ) { $bounded = false; $regions[] = [ $stack[0]['start'], strlen( $active ) ]; }
		preg_match_all( '/^(?:[ \t]*[*#][^\r\n]*(?:\r?\n|$))+/m', $active, $lists, PREG_OFFSET_CAPTURE );
		foreach ( $lists[0] as $list ) { $regions[] = [ $list[1], $list[1] + strlen( $list[0] ) ]; }
		$items = [];
		$outside = $active;
		usort( $regions, static fn ( $a, $b ) => $b[0] <=> $a[0] );
		foreach ( $regions as [ $start, $end ] ) {
			foreach ( self::possibleLootItems( substr( $active, $start, $end - $start ), $regionBounded ) as $item ) { self::unique( $items, $item ); }
			$bounded = $bounded && $regionBounded;
			$outside = substr_replace( $outside, self::mask( substr( $outside, $start, $end - $start ) ), $start, $end - $start );
		}
		// Literal prose links such as a forge or zone describe the page, not a
		// recursively included catalog. Unknown generating templates can still
		// conceal members and therefore prevent a bounded negative conclusion.
		$roots = self::roots( $outside, false, true );
		if ( $roots === null || !$regions ) { $bounded = false; }
		foreach ( $roots ?? [] as $template ) {
			if ( !$template['parts'] && preg_match( '/(?:^| )era$/', $template['name'] ) || self::globalLootMetadata( $template ) ) { continue; }
			$bounded = false;
		}
		if ( count( $items ) > 1000 ) { $bounded = false; $items = array_slice( $items, 0, 1000 ); }
		return $items;
	}

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
	public static function analyseNpcPage( string $source, bool $globalExport = false, bool $allowMainColon = false ): ?array {
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
			$name = self::title( $template['fields']['name'] ?? '', $allowMainColon );
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
				foreach ( self::lootReferences( $template['fields'][$field] ?? '', $allowMainColon ) as $title ) { self::unique( $result['authoredItems'], $title ); }
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
				$stack = [];
				foreach ( self::lines( self::fieldText( $template['fields'][$field] ?? '', true, 0, $globalExport, $verifiedItemTemplates ) ) as $line ) {
					if ( preg_match( '/^(={1,6})\s*(.*?)\s*\1$/', trim( $line ), $heading ) ) {
						while ( $stack && end( $stack )['level'] >= strlen( $heading[1] ) ) { array_pop( $stack ); }
						$stack[] = [ 'level' => strlen( $heading[1] ), 'label' => $heading[2] ];
						continue;
					}
				foreach ( self::npcSourceReferences( $line, $allowMainColon, $globalExport, $verifiedItemTemplates ) as $item ) {
					foreach ( ( $analysis['zones'] ?: [ '' ] ) as $zone ) {
						$row = [ 'item' => $item, 'npc' => $npc, 'zone' => $zone, 'instance' => self::stackInstance( $stack, $instance ), 'lootKind' => $kind ];
						$rows[json_encode( $row )] = $row;
					}
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
	/** Literal loot rows and explicit quantity prose, excluding incidental note links. */
	private static function npcSourceReferences( string $field, bool $allowMainColon = false, bool $globalExport = false, array $verifiedItemTemplates = [] ): array {
		$items = [];
		foreach ( self::lines( self::fieldText( self::active( $field ), true, 0, $globalExport, $verifiedItemTemplates ) ) as $line ) {
			$line = preg_replace( '/^[\s*#\']+/', '', strip_tags( $line ) );
			$line = preg_replace( '/^(?:always\s+)?drops?\s+(?:(?:one|two|three|four|five|six|[0-9]+)\s+)?(?:pieces?\s+of\s+)?(?=\[\[|\{\{)/i', '', $line );
			while ( ( $reference = self::leadingReference( $line, true, $allowMainColon ) ) !== null ) {
				self::unique( $items, $reference['title'] );
				$tail = preg_replace( '/^[\s\']+/', '', $reference['tail'] );
				if ( preg_match( '/^(?:[,;\/&]|and\b|or\b)\s*/i', $tail, $separator ) ) { $tail = substr( $tail, strlen( $separator[0] ) ); }
				$line = ltrim( $tail );
			}
		}
		return $items;
	}

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
	public static function mergeItemSources( string $source, array $entries, array $zoneAliases = [] ): array {
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
			$npc = self::title( $entry['npc'] ?? '', true );
			$zone = ( $entry['zone'] ?? '' ) === '' ? '' : self::title( $entry['zone'], true );
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
					$original = substr_replace( $original, self::mergeSourceField( $value, array_values( $card['entries'] ), $card['instance'], $zoneAliases, $unplaced ), $start, $valueLength );
					$updated = true;
					break;
				}
				$offset += strlen( $part ) + 1;
			}
			if ( !$updated ) { $original = substr( $original, 0, -2 ) . "\n|dropsfrom=" . self::mergeSourceField( '', array_values( $card['entries'] ), $card['instance'], $zoneAliases, $unplaced ) . '}}'; }
			$replacements[] = [ 'start' => $template['start'], 'length' => $length, 'value' => $original ];
		}
		usort( $replacements, static fn ( $a, $b ) => $b['start'] <=> $a['start'] );
		foreach ( $replacements as $replacement ) { $source = substr_replace( $source, $replacement['value'], $replacement['start'], $replacement['length'] ); }
		return [ 'text' => $source, 'unplaced' => $unplaced ];
	}

	/**
	 * Reuse authored simple zone lists and instance sections. Only insert bytes:
	 * prose, list labels, aliases, comments and structured tables stay intact.
	 * Zone aliases are current public redirect proofs supplied by the lookup.
	 */
	private static function mergeSourceField( string $value, array $entries, string $base, array $zoneAliases, int &$unplaced ): string {
		$canonicalZone = static function ( string $zone ) use ( $zoneAliases ): string {
			$zone = self::title( $zone ) ?? '';
			$target = count( $zoneAliases ) <= 200 ? ( $zoneAliases[$zone] ?? $zone ) : $zone;
			return is_string( $target ) ? ( self::title( $target ) ?? $zone ) : $zone;
		};
		$existing = self::itemSources( ( $base === 'raid' ? "== Raid Instance ==\n" : '' ) . '{{Itempage|dropsfrom=' . $value . '}}' );
		$pending = [];
		foreach ( $entries as $entry ) {
			$found = false;
			foreach ( $existing as $row ) {
				// An unscoped authored NPC already states this relationship without a zone.
				if ( $row['npc'] === $entry['npc'] && $row['instance'] === $entry['instance'] &&
					( $row['zone'] === '' || $canonicalZone( $row['zone'] ) === $canonicalZone( $entry['zone'] ) ) ) { $found = true; break; }
			}
			if ( !$found ) { $pending[] = $entry; }
		}
		if ( !$pending ) { return $value; }
		$active = self::active( $value );
		// Nested template arguments cannot establish a line/heading context.
		foreach ( self::roots( $active ) ?? [] as $template ) {
			$active = substr_replace( $active, self::mask( substr( $active, $template['start'], $template['end'] - $template['start'] ) ), $template['start'], $template['end'] - $template['start'] );
		}
		preg_match_all( '/[^\r\n]*(?:\r\n|\r|\n|$)/', $active, $matches, PREG_OFFSET_CAPTURE );
		$lines = [];
		$stack = [];
		$sections = [ [ 'instance' => $base, 'start' => 0, 'end' => strlen( $value ), 'explicit' => false ] ];
		$section = 0;
		foreach ( $matches[0] as [ $line, $offset ] ) {
			if ( $line === '' ) { continue; }
			if ( preg_match( '/^(={1,6})[ \t]*(.*?)[ \t]*\1[ \t]*$/', trim( $line ), $heading ) ) {
				$sections[$section]['end'] = $offset;
				while ( $stack && end( $stack )['level'] >= strlen( $heading[1] ) ) { array_pop( $stack ); }
				$stack[] = [ 'level' => strlen( $heading[1] ), 'label' => $heading[2] ];
				$section = count( $sections );
				$sections[] = [ 'instance' => self::stackInstance( $stack, $base ), 'start' => $offset + strlen( $line ),
					'end' => strlen( $value ), 'explicit' => preg_match( '/\b(?:Normal|Raid)[ _]+(?:Instance|Zone)\b/i', $heading[2] ) === 1 ];
				$lines[] = [ 'kind' => 'section', 'section' => $section, 'offset' => $offset, 'end' => $offset + strlen( $line ) ];
				continue;
			}
			$bullet = preg_match( '/^\s*[*#]+\s*/', $line, $prefix ) === 1;
			$ref = self::leadingReference( $bullet ? substr( $line, strlen( $prefix[0] ) ) : $line, false );
			$lines[] = [ 'kind' => trim( $line ) === '' ? 'blank' : ( $ref && ( $bullet || trim( $ref['tail'] ) === '' ) ? ( $bullet ? 'mob' : 'link' ) : 'other' ),
				'title' => $ref['title'] ?? '', 'section' => $section, 'offset' => $offset, 'end' => $offset + strlen( $line ) ];
		}
		$blocks = [];
		for ( $i = 0; $i < count( $lines ); $i++ ) {
			if ( $lines[$i]['kind'] !== 'link' ) { continue; }
			$j = $i + 1;
			while ( isset( $lines[$j] ) && $lines[$j]['kind'] === 'blank' ) { $j++; }
			if ( !isset( $lines[$j] ) || $lines[$j]['kind'] !== 'mob' || $lines[$j]['section'] !== $lines[$i]['section'] ) { continue; }
			$end = $lines[$j]['end'];
			while ( isset( $lines[++$j] ) && in_array( $lines[$j]['kind'], [ 'mob', 'blank' ], true ) && $lines[$j]['section'] === $lines[$i]['section'] ) {
				if ( $lines[$j]['kind'] === 'mob' ) { $end = $lines[$j]['end']; }
			}
			$blocks[] = [ 'zone' => $canonicalZone( $lines[$i]['title'] ), 'instance' => $sections[$lines[$i]['section']]['instance'], 'end' => $end ];
		}
		$insertions = [];
		// Adding the first zone must not silently re-scope an authored unscoped
		// list. A minimal explicit instance boundary keeps those bullets unscoped.
		$unscoped = array_values( array_filter( $existing, static fn ( $row ) => $row['zone'] === '' ) );
		$hasNewZone = count( array_filter( $pending, static fn ( $row ) => $row['zone'] !== '' ) ) > 0;
		if ( $unscoped && $hasNewZone ) {
			$plain = array_column( array_filter( $lines, static fn ( $line ) => $line['kind'] === 'link' ), 'title' );
			if ( array_intersect( array_column( $unscoped, 'npc' ), $plain ) ) {
				// Bare unscoped links have no safe list boundary. Do not reinterpret them.
				$unplaced += count( $pending );
				return $value;
			}
			if ( !$blocks ) {
				$names = array_column( $unscoped, 'npc' );
				foreach ( $lines as $line ) {
					$scope = $sections[$line['section']];
					if ( $line['kind'] === 'mob' && in_array( $line['title'], $names, true ) && !$scope['explicit'] ) {
						$insertions[$scope['start']] = [ "\n=== " . ucfirst( $scope['instance'] ) . " Instance ===\n" ];
					}
				}
			}
		}
		$new = [];
		foreach ( $pending as $entry ) {
			$targets = array_values( array_filter( $blocks, static fn ( $block ) => $block['zone'] === $canonicalZone( $entry['zone'] ) && $block['instance'] === $entry['instance'] ) );
			if ( count( $targets ) > 1 ) { $unplaced++; continue; }
			if ( $entry['zone'] !== '' && count( $targets ) === 1 ) {
				$insertions[$targets[0]['end']][] = '* [[' . $entry['npc'] . "]]\n";
			} else { $new[$entry['instance']][$entry['zone']][] = $entry['npc']; }
		}
		foreach ( $new as $instance => $zones ) {
			// Emit unknown-zone references before the first zone heading in this group.
			if ( isset( $zones[''] ) ) { $zones = [ '' => $zones[''] ] + $zones; }
			$targets = array_values( array_filter( $sections, static fn ( $scope ) => $scope['instance'] === $instance ) );
			$target = $targets ? end( $targets ) : null;
			$offset = $target ? $target['end'] : strlen( $value );
			$addition = '';
			// Unknown-zone mobs need a reset to avoid inheriting the previous zone.
			if ( !$target || isset( $zones[''] ) ) { $addition .= "\n=== " . ucfirst( $instance ) . " Instance ===\n"; }
			foreach ( $zones as $zone => $npcs ) {
				if ( $zone !== '' ) { $addition .= "\n[[" . $zone . "]]\n"; }
				foreach ( $npcs as $npc ) { $addition .= '* [[' . $npc . "]]\n"; }
			}
			$insertions[$offset][] = $addition;
		}
		krsort( $insertions, SORT_NUMERIC );
		foreach ( $insertions as $offset => $parts ) {
			$prefix = $offset > 0 && !in_array( $value[$offset - 1], [ "\r", "\n" ], true ) ? "\n" : '';
			$value = substr_replace( $value, $prefix . implode( '', $parts ), $offset, 0 );
		}
		return $value;
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
						// Repeated/aliased display blocks do not erase other verified positives.
						continue;
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
	private static function roots( string $source, bool $globalExport = false, bool $duplicateStaticFields = false ): ?array {
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
							if ( array_key_exists( $key, $fields ) && !$duplicateStaticFields && !( $globalExport && $name === 'namedmobpage' && $parameterNames[$key] === trim( $field[1] ) ) ) {
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

	private static function document( string $source, bool $globalExport = false, bool $duplicateStaticFields = false ): ?array {
		if ( strlen( $source ) > self::MAX_BYTES || preg_match( '//u', $source ) !== 1 ) { return null; }
		$active = self::active( $source );
		$templates = self::roots( $active, $globalExport, $duplicateStaticFields );
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
