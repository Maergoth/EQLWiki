<?php

declare( strict_types = 1 );

namespace EQLClientData;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/** Extract player-reported schema-1 snapshots, never item-derived estimates. */
final class CharacterSheetSnapshot {
	public const MAX_HTML_BYTES = 2097152;
	public const MAX_SHEETS = 50;
	public const STAT_LABELS = [
		'hp' => 'HP', 'mp' => 'Mana', 'end' => 'Endurance',
		'ac_mitigation' => 'Mitigation AC', 'ac_mitigation_softcap' => 'AC softcap',
		'ac_evasion' => 'Evasion AC', 'atk_offense' => 'Offense', 'atk_accuracy' => 'Accuracy',
		'hp_regen' => 'HP regen', 'mana_regen' => 'Mana regen', 'end_regen' => 'End. regen',
		'velocity' => 'Velocity', 'primary_dps' => 'Primary DPS', 'secondary_dps' => 'Secondary DPS',
		'ranged_dps' => 'Ranged DPS', 'attack_speed' => 'Attack speed %', 'haste' => 'Haste %',
		'ac' => 'AC', 'atk' => 'Attack', 'str' => 'STR', 'sta' => 'STA', 'agi' => 'AGI',
		'dex' => 'DEX', 'wis' => 'WIS', 'int' => 'INT', 'cha' => 'CHA',
		'sv_magic' => 'Magic resist', 'sv_fire' => 'Fire resist', 'sv_cold' => 'Cold resist',
		'sv_poison' => 'Poison resist', 'sv_disease' => 'Disease resist', 'sv_corruption' => 'Void resist',
		'accuracy' => 'Accuracy bonus', 'avoidance' => 'Avoidance', 'combat_effects' => 'Combat effects',
		'strikethrough' => 'Strikethrough', 'stun_resist' => 'Stun resist', 'damage_shield' => 'Damage shield',
		'damage_shield_mitigation' => 'Damage shield mitigation', 'dot_shielding' => 'DoT shielding',
		'melee_shielding' => 'Melee shielding', 'spell_shielding' => 'Spell shielding'
	];
	public const SLOT_LABELS = [
		'Left ear', 'Neck', 'Face', 'Head', 'Right ear', 'Left finger', 'Left wrist', 'Arms',
		'Hands', 'Right wrist', 'Right finger', 'Shoulders', 'Chest', 'Back', 'Waist', 'Legs',
		'Feet', 'Primary', 'Secondary', 'Range', 'Ammo', 'Charm', 'Any 1', 'Any 2'
	];
	private const CLASSES = [
		'WAR', 'CLR', 'PAL', 'RNG', 'SHD', 'DRU', 'MNK', 'BRD',
		'ROG', 'SHM', 'NEC', 'WIZ', 'MAG', 'ENC', 'BST', 'BER'
	];

	/** @return list<array> Pure rendered-DOM parsing; no URLs or markup are returned. */
	public static function extract( string $html, int $maxLevel = 50 ): array {
		if ( strlen( $html ) > self::MAX_HTML_BYTES ) {
			throw new RuntimeException( 'player-list-output-limit' );
		}
		$document = new DOMDocument();
		$oldErrors = libxml_use_internal_errors( true );
		try {
			$loaded = $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $oldErrors );
		}
		if ( !$loaded ) {
			throw new RuntimeException( 'player-list-output-invalid' );
		}
		$xpath = new DOMXPath( $document );
		$sheets = $xpath->query( '//*[' . self::hasClass( 'eql-character-sheet' ) . ' and ' .
			self::hasClass( 'eql-character-sheet-v1' ) . ' and not(ancestor::*[' . self::hasClass( 'eql-character-sheet' ) . '])]' );
		if ( $sheets->length > self::MAX_SHEETS ) {
			throw new RuntimeException( 'player-list-sheet-limit' );
		}
		$rows = [];
		foreach ( $sheets as $sheet ) {
			$heading = self::one( $xpath, './div[' . self::hasClass( 'eql-character-heading' ) . ']', $sheet );
			$name = $heading ? self::one( $xpath, './strong', $heading ) : null;
			$metadata = $heading ? self::one( $xpath, './span[' . self::hasClass( 'eql-character-metadata' ) . ']', $heading ) : null;
			if ( !$name || !$metadata || !preg_match( '/^(.{1,100}) \(([^()]{1,100})\)$/uD', self::text( $name ), $identity ) ) {
				continue;
			}
			[ , $character, $server ] = $identity;
			if ( $character === 'Unnamed character' || in_array( $server, [ '?', 'Unknown' ], true ) || trim( $character ) !== $character || trim( $server ) !== $server ) {
				continue;
			}
			// Parser-function trimming in the published template can render "AAs· race".
			// Structural separators, rather than incidental adjacent spaces, delimit fields.
			if ( !preg_match( '/^Level\s+(.+?)\s*·\s*(.+?)\s*·\s*(.+?)\s+AAs(?:\s*·\s*.*)?$/uD', self::text( $metadata ), $parts ) ) {
				continue;
			}
			$level = self::integer( trim( $parts[1] ), 1, $maxLevel );
			$aa = self::integer( trim( $parts[3] ), 0, 1000000000 );
			$classes = preg_split( '/\s*\/\s*/u', trim( $parts[2] ) ) ?: [];
			if ( count( $classes ) > 3 || count( array_unique( $classes ) ) !== count( $classes ) || array_diff( $classes, self::CLASSES ) ) {
				$classes = [];
			}
			$body = self::one( $xpath, './div[' . self::hasClass( 'eql-character-body' ) . ']', $sheet );
			$layout = $body ? self::one( $xpath, './div[' . self::hasClass( 'eql-character-layout' ) . ']', $body ) : null;
			$totals = $layout ? self::one( $xpath, './div[' . self::hasClass( 'eql-character-totals' ) . ']', $layout ) : null;
			$stats = array_fill_keys( array_keys( self::STAT_LABELS ), null );
			$seen = [];
			if ( $totals ) {
				$keys = array_flip( self::STAT_LABELS );
				foreach ( $xpath->query( './dl/div', $totals ) as $pair ) {
					$label = self::one( $xpath, './dt', $pair );
					$value = self::one( $xpath, './dd', $pair );
					$key = $label ? ( $keys[self::text( $label )] ?? null ) : null;
					if ( $key !== null ) {
						$stats[$key] = isset( $seen[$key] ) || !$value ? null : self::number( self::text( $value ) );
						$seen[$key] = true;
					}
				}
			}
			$equipment = $layout ? self::one( $xpath, './div[' . self::hasClass( 'eql-character-equipment' ) . ']', $layout ) : null;
			[ $totalTier, $equippedSlots, $tieredSlots ] = self::tiers( $xpath, $equipment );
			$note = $body ? self::one( $xpath, './p[' . self::hasClass( 'eql-character-note' ) . ']', $body ) : null;
			$updated = null;
			if ( $note && preg_match( '/Updated: (.{1,100})$/uD', self::text( $note ), $match ) && !in_array( $match[1], [ '?', 'Unknown' ], true ) ) {
				$updated = $match[1];
			}
			$rows[] = [
				'name' => $character, 'server' => $server, 'level' => $level, 'classes' => $classes, 'aa' => $aa,
				'totalTier' => $totalTier, 'equippedSlots' => $equippedSlots, 'tieredSlots' => $tieredSlots,
				'stats' => $stats, 'updated' => $updated
			];
		}
		// A page can repeat a template. Identical repetitions are one snapshot; conflicting
		// repetitions have no trustworthy order and must not manufacture leaderboard ranks.
		$unique = [];
		foreach ( $rows as $row ) {
			$key = $row['name'] . "\0" . $row['server'];
			if ( !isset( $unique[$key] ) ) {
				$unique[$key] = $row;
			} elseif ( $unique[$key] !== $row ) {
				$unique[$key] = array_replace( $unique[$key], [ 'level' => null, 'aa' => null,
					'totalTier' => null, 'stats' => array_fill_keys( array_keys( self::STAT_LABELS ), null ),
					'snapshotConflict' => true ] );
			}
		}
		return array_values( $unique );
	}

	private static function tiers( DOMXPath $xpath, ?DOMNode $equipment ): array {
		$equipped = 0;
		$tiered = 0;
		$total = 0;
		$seen = [];
		$valid = $equipment !== null;
		if ( $equipment ) {
			foreach ( $xpath->query( './div[' . self::hasClass( 'eql-character-equipment-row' ) . ']/div[' . self::hasClass( 'eql-character-slot' ) . ']', $equipment ) as $slot ) {
				$labelNode = self::one( $xpath, './span[' . self::hasClass( 'eql-character-slot-label' ) . ']', $slot );
				$label = $labelNode ? self::text( $labelNode ) : '';
				if ( !in_array( $label, self::SLOT_LABELS, true ) || isset( $seen[$label] ) ) {
					$valid = false;
					continue;
				}
				$seen[$label] = true;
				$cards = $xpath->query( './div[' . self::hasClass( 'eql-character-item' ) . ']', $slot );
				$empty = $xpath->query( './span[' . self::hasClass( 'eql-character-empty' ) . ']', $slot );
				if ( $cards->length === 0 && $empty->length === 1 ) {
					continue;
				}
				if ( $cards->length !== 1 || $empty->length ) {
					$valid = false;
					continue;
				}
				$equipped++;
				$tier = self::one( $xpath, './span[' . self::hasClass( 'eql-character-tier' ) . ']', $cards[0] );
				$value = $tier && preg_match( '/^\+(0|[1-9]|10)$/D', self::text( $tier ), $match ) ? (int)$match[1] : null;
				if ( $value !== null ) {
					$tiered++;
					$total += $value;
				}
			}
		}
		$valid = $valid && count( $seen ) === count( self::SLOT_LABELS ) && $equipped === $tiered;
		return [ $valid ? $total : null, $equipped, $tiered ];
	}

	private static function number( string $value ): int|float|null {
		if ( !preg_match( '/^[+-]?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D', $value ) ) {
			return null;
		}
		$number = (float)$value;
		return is_finite( $number ) ? ( str_contains( $value, '.' ) ? $number : (int)$value ) : null;
	}

	private static function integer( string $value, int $min, int $max ): ?int {
		$number = self::number( $value );
		return is_int( $number ) && $number >= $min && $number <= $max ? $number : null;
	}

	private static function text( DOMNode $node ): string {
		return trim( preg_replace( '/\s+/u', ' ', str_replace( "\u{00a0}", ' ', $node->textContent ) ) ?? '' );
	}

	private static function one( DOMXPath $xpath, string $query, DOMNode $context ): ?DOMElement {
		$nodes = $xpath->query( $query, $context );
		return $nodes->length === 1 && $nodes[0] instanceof DOMElement ? $nodes[0] : null;
	}

	private static function hasClass( string $name ): string {
		return 'contains(concat(" ",normalize-space(@class)," ")," ' . $name . ' ")';
	}
}
