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
		return self::project( $records )['rows'];
	}

	/** Internal admission shared by incremental ingestion and legacy backfill. Never expose actor. */
	public static function cumulativeRecord( array $record ): ?array {
		$event = self::eligibleEvent( $record );
		if ( $event === null ) { return null; }
		$id = self::entryId( $event );
		$detail = self::detail( $record, $id );
		if ( $detail === null ) { throw new \RuntimeException( 'Invalid accepted observation receive time.' ); }
		$zone = self::subject( $event, 'zone' );$npc = self::subject( $event, 'npc' );$item = self::subject( $event, 'item' );
		return [ 'id' => substr( $id, 5 ),
			'scope' => hash( 'sha256', json_encode( [ $zone, $npc, $event['instance'] ], JSON_THROW_ON_ERROR ) ),
			'zone' => $zone, 'npc' => $npc, 'item' => $item, 'instance' => $event['instance'],
			'zoneTitle' => self::titleClaim( $event['zoneTitle'] ), 'npcTitle' => self::titleClaim( $event['npcTitle'] ),
			'itemTitle' => self::titleClaim( $event['itemTitle'] ), 'actor' => (int)$record['eoo_actor'], 'detail' => $detail ];
	}

	/** Internal projection: members contains private database cursors and must never be returned by an API. */
	public static function project( iterable $records ): array {
		$groups = [];
		$totals = [];
		$seen = [];
		$members = [];
		foreach ( $records as $record ) {
			$record = (array)$record;
			$event = self::eligibleEvent( $record );
			if ( $event === null ) { continue; }
			$actor = (int)$record['eoo_actor'];
			$zone = self::subject( $event, 'zone' );
			$npc = self::subject( $event, 'npc' );
			$item = self::subject( $event, 'item' );
			if ( $zone === '' || $npc === '' || $item === '' ) { continue; }
			$eventKey = (int)$actor . ':' . $event['id'];
			if ( isset( $seen[$eventKey] ) ) { continue; }
			$seen[$eventKey] = true;
			$scope = json_encode( [ $zone, $npc, $event['instance'] ], JSON_THROW_ON_ERROR );
			$key = self::entryId( $event );
			$totals[$scope] = ( $totals[$scope] ?? 0 ) + 1;
			if ( !isset( $groups[$key] ) ) {
				$groups[$key] = [
					'entryId' => $key, 'zone' => $zone, 'zoneTitle' => self::titleClaim( $event['zoneTitle'] ),
					'npc' => $npc, 'npcTitle' => self::titleClaim( $event['npcTitle'] ),
					'item' => $item, 'itemTitle' => self::titleClaim( $event['itemTitle'] ),
					'instance' => $event['instance'],
					'reports' => 0, 'quantity' => 0, '_actors' => [], '_scope' => $scope
				];
			}
			$groups[$key]['reports']++;
			$groups[$key]['quantity'] += $event['quantity'];
			$groups[$key]['_actors'][(int)$actor] = true;
			if ( isset( $record['eoo_id'] ) && (int)$record['eoo_id'] > 0 ) {
				$members[$key][] = (int)$record['eoo_id'];
			}
			// Prefer a supplied canonical title when raw and canonical names coincide.
			foreach ( [ 'zoneTitle', 'npcTitle', 'itemTitle' ] as $title ) {
				if ( $event[$title] !== null ) { $groups[$key][$title] = self::titleClaim( $event[$title] ); }
			}
		}
		$rows = [];
		foreach ( $groups as $group ) {
			if ( $group['reports'] < self::MIN_REPORTS ) { unset( $members[$group['entryId']] ); continue; }
			$group['contributors'] = count( $group['_actors'] );
			$group['totalReports'] = $totals[$group['_scope']];
			// Denominator includes hidden singles, every server and every tier in the scope.
			// Round once at presentation; rounding here can change the final displayed tenth.
			$group['reportShare'] = 100.0 * $group['reports'] / $group['totalReports'];
			unset( $group['_actors'], $group['_scope'] );
			$rows[] = $group;
		}
		usort( $rows, static fn ( array $first, array $second ): int =>
			[ $first['zone'], $first['npc'], $first['instance'], $first['item'] ] <=>
			[ $second['zone'], $second['npc'], $second['instance'], $second['item'] ] );
		return [ 'rows' => $rows, 'members' => $members ];
	}

	/** Public detail allowlist for a row already admitted through a published group. */
	public static function detail( array $record, string $entryId ): ?array {
		$event = self::eligibleEvent( $record );
		if ( $event === null || !hash_equals( self::entryId( $event ), $entryId ) ) { return null; }
		$received = $record['eoo_received'] ?? '';
		if ( !is_string( $received ) || !preg_match( '/^[0-9]{14}$/D', $received ) ) { return null; }
		$time = \DateTimeImmutable::createFromFormat( '!YmdHis', $received, new \DateTimeZone( 'UTC' ) );
		if ( !$time || $time->format( 'YmdHis' ) !== $received ) { return null; }
		$character = $event['characterName'] ?? null;
		// Optional names have already passed the versioned ingestion validator.
		// Never derive a historical character name from account identity or tokens.
		if ( !is_string( $character ) ) { $character = null; }
		return [ 'observedAt' => $event['observedAt'], 'receivedAt' => $time->format( 'Y-m-d\\TH:i:s\\Z' ),
			'quantity' => $event['quantity'], 'characterName' => $character, 'sourceKind' => 'explicit-corpse' ];
	}

	private static function eligibleEvent( array $record ): ?array {
		$actor = $record['eoo_actor'] ?? null;
		$json = $record['eoo_payload'] ?? null;
		if ( !is_numeric( $actor ) || (int)$actor < 1 || !is_string( $json ) || strlen( $json ) > ObservationPayload::MAX_EVENT_BYTES ) { return null; }
		try {
			$raw = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
			if ( !is_array( $raw ) || array_is_list( $raw ) ) { return null; }
			$event = ObservationPayload::event( $raw );
		} catch ( \Throwable $error ) { return null; }
		// Capability messages were admitted as zones by old clients. Quarantine both
		// raw and canonical claims; never reassign or modify the saved observations.
		foreach ( [ $event['zone'], $event['zoneTitle'] ] as $zone ) {
			if ( $zone !== null && preg_match( '/^an? area where\\b/i', self::titleClaim( $zone ) ?? '' ) ) { return null; }
		}
		// Named providers can be merchants. Inferred targets are not corpse evidence.
		if ( $event['kind'] !== 'loot' || $event['sourceKind'] !== 'explicit-corpse' ||
			self::subject( $event, 'zone' ) === '' || self::subject( $event, 'npc' ) === '' || self::subject( $event, 'item' ) === '' ) { return null; }
		return $event;
	}

	private static function entryId( array $event ): string {
		return 'drop_' . hash( 'sha256', json_encode( [ self::subject( $event, 'zone' ), self::subject( $event, 'npc' ),
			self::subject( $event, 'item' ), $event['instance'] ], JSON_THROW_ON_ERROR ) );
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
