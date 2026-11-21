<?php

declare( strict_types = 1 );

namespace EQLClientData;

/** Public counts of explicit corpse reports. Reports are not distinct kills or drop chances. */
final class DropObservationSummary {
	public const MIN_REPORTS = 2;

	/**
	 * @param iterable $records Private accepted rows with eoo_actor and eoo_payload.
	 * @return array Public aggregates only; actor/event/session identifiers never leave this method.
	 */
	public static function summarize( iterable $records ): array {
		$groups = [];
		$totals = [];
		$seen = [];
		foreach ( $records as $record ) {
			$record = (array)$record;
			$actor = $record['eoo_actor'] ?? null;
			$json = $record['eoo_payload'] ?? null;
			if ( !is_numeric( $actor ) || (int)$actor < 1 || !is_string( $json ) ||
				strlen( $json ) > ObservationPayload::MAX_EVENT_BYTES ) {
				continue;
			}
			try {
				$raw = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
				if ( !is_array( $raw ) || array_is_list( $raw ) ) { continue; }
				$event = ObservationPayload::event( $raw );
			} catch ( \Throwable $error ) {
				continue;
			}
			// Named providers can be merchants; recent targets and kills are inference.
			// Only actual corpse wording supports this first public source summary.
			if ( $event['kind'] !== 'loot' || $event['sourceKind'] !== 'explicit-corpse' ) {
				continue;
			}
			$zone = self::subject( $event, 'zone' );
			$npc = self::subject( $event, 'npc' );
			$item = self::subject( $event, 'item' );
			if ( $zone === '' || $npc === '' || $item === '' ) { continue; }
			$eventKey = (int)$actor . ':' . $event['id'];
			if ( isset( $seen[$eventKey] ) ) { continue; }
			$seen[$eventKey] = true;
			$scope = json_encode( [ $event['server'], $zone, $npc, $event['instance'] ], JSON_THROW_ON_ERROR );
			$key = json_encode( [ $scope, $item, $event['tier'] ], JSON_THROW_ON_ERROR );
			$totals[$scope] = ( $totals[$scope] ?? 0 ) + 1;
			if ( !isset( $groups[$key] ) ) {
				$groups[$key] = [
					'server' => $event['server'], 'zone' => $zone, 'zoneTitle' => self::titleClaim( $event['zoneTitle'] ),
					'npc' => $npc, 'npcTitle' => self::titleClaim( $event['npcTitle'] ),
					'item' => $item, 'itemTitle' => self::titleClaim( $event['itemTitle'] ),
					'instance' => $event['instance'], 'tier' => $event['tier'],
					'reports' => 0, 'quantity' => 0, '_actors' => [], '_scope' => $scope
				];
			}
			$groups[$key]['reports']++;
			$groups[$key]['quantity'] += $event['quantity'];
			$groups[$key]['_actors'][(int)$actor] = true;
			// Prefer a supplied canonical title when raw and canonical names coincide.
			foreach ( [ 'zoneTitle', 'npcTitle', 'itemTitle' ] as $title ) {
				if ( $event[$title] !== null ) { $groups[$key][$title] = self::titleClaim( $event[$title] ); }
			}
		}
		$rows = [];
		foreach ( $groups as $group ) {
			if ( $group['reports'] < self::MIN_REPORTS ) { continue; }
			$group['contributors'] = count( $group['_actors'] );
			$group['totalReports'] = $totals[$group['_scope']];
			// Denominator includes hidden single reports and every tier in the scope.
            // Round once at presentation; rounding here can change the final displayed tenth.
            $group['reportShare'] = 100.0 * $group['reports'] / $group['totalReports'];
			unset( $group['_actors'], $group['_scope'] );
			$rows[] = $group;
		}
		usort( $rows, static fn ( array $first, array $second ): int =>
			[ $first['zone'], $first['npc'], $first['server'], $first['instance'], $first['item'], $first['tier'] ?? -1 ] <=>
			[ $second['zone'], $second['npc'], $second['server'], $second['instance'], $second['item'], $second['tier'] ?? -1 ] );
		return $rows;
	}

	private static function subject( array $event, string $field ): string {
		return self::titleClaim( $event[$field . 'Title'] ?? $event[$field] ) ?? '';
	}

	/** English wiki first-letter title normalization, preserving every internal letter. */
	private static function titleClaim( ?string $value ): ?string {
		if ( $value === null ) { return null; }
		$value = trim( preg_replace( '/ +/u', ' ', str_replace( '_', ' ', $value ) ) );
		return mb_strtoupper( mb_substr( $value, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $value, 1, null, 'UTF-8' );
	}
}
