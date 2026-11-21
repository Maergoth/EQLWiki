<?php
declare( strict_types = 1 );
namespace EQLClientData;

/** Rebuildable, exact all-history projection. Every write shares ingestion's transaction/lock. */
final class CumulativeDropStore {
	public const TABLES = [ 'eql_drop_state', 'eql_drop_scopes', 'eql_drop_entries', 'eql_drop_records', 'eql_drop_contributors' ];
	public const VERSION = 'cumulative-v1';

	public static function state( $db ): ?object {
		$present = 0;
		foreach ( self::TABLES as $table ) { $present += (int)$db->tableExists( $table, __METHOD__ ); }
		if ( !$present ) { return null; }
		if ( $present !== count( self::TABLES ) ) { throw new \RuntimeException( 'eoz-schema-unavailable' ); }
		$state = $db->selectRow( 'eql_drop_state', '*', [ 'dps_id' => 1 ], __METHOD__ );
		if ( !$state ) { throw new \RuntimeException( 'eoz-schema-unavailable' ); }
		return $state;
	}

	/** Called before the raw event/batch/counter transaction commits; never catches failures. */
	public static function accepted( $db, array $records ): void {
		if ( !$records || self::state( $db ) === null ) { return; }
		$state = self::lockState( $db );
		self::apply( $db, $state, $records );
		$db->update( 'eql_drop_state', [ 'dps_revision' => $state->dps_revision ], [ 'dps_id' => 1 ], __METHOD__ );
	}

	/** Owner-reviewed rollback gate; leaves every raw/derived record and counter intact. */
	public static function deactivate( $db ): void {
		if ( self::state( $db ) === null ) { throw new \RuntimeException( 'Projection schema is not installed.' ); }
		$db->startAtomic( __METHOD__, $db::ATOMIC_CANCELABLE );
		try {
			$rawState = $db->newSelectQueryBuilder()->select( 'eos_id' )->from( 'eql_eoz_state' )->where( [ 'eos_id' => 1 ] )
				->forUpdate()->caller( __METHOD__ )->fetchRow();
			if ( !$rawState ) { throw new \RuntimeException( 'Observation state missing.' ); }
			self::lockState( $db );
			$db->update( 'eql_drop_state', [ 'dps_ready' => 0 ], [ 'dps_id' => 1 ], __METHOD__ );
			$db->endAtomic( __METHOD__ );
		} catch ( \Throwable $error ) { $db->cancelAtomic( __METHOD__ );throw $error; }
	}

	/** Bounded resumable backfill. The final anti-join catches holes BELOW the scan cursor. */
	public static function backfill( $db, int $limit = 500 ): array {
		if ( $limit < 1 || $limit > 1000 ) { throw new \InvalidArgumentException( 'Use a backfill batch from 1 to 1000.' ); }
		if ( self::state( $db ) === null ) { throw new \RuntimeException( 'Projection schema is not installed.' ); }
		$db->startAtomic( __METHOD__, $db::ATOMIC_CANCELABLE );
		try {
			// Same order as ingestion: raw state first, projection state second.
			$rawState = $db->newSelectQueryBuilder()->select( 'eos_id' )->from( 'eql_eoz_state' )
				->where( [ 'eos_id' => 1 ] )->forUpdate()->caller( __METHOD__ )->fetchRow();
			if ( !$rawState ) { throw new \RuntimeException( 'Observation state missing.' ); }
			$state = self::lockState( $db );
			$records = self::missing( $db, $limit, (int)$state->dps_cursor );
			if ( !$records ) {
				// A high-water mark is an optimization, never proof of completeness.
				$records = self::missing( $db, $limit, null );
			}
			if ( $records ) {
				$state->dps_ready = 0;
				self::apply( $db, $state, $records );
				foreach ( $records as $record ) { $state->dps_cursor = max( (int)$state->dps_cursor, (int)$record->eoo_id ); }
			} else { $state->dps_ready = 1; }
			$db->update( 'eql_drop_state', [ 'dps_cursor' => $state->dps_cursor, 'dps_revision' => $state->dps_revision,
				'dps_ready' => $state->dps_ready ], [ 'dps_id' => 1 ], __METHOD__ );
			$db->endAtomic( __METHOD__ );
			return [ 'processed' => count( $records ), 'complete' => (bool)$state->dps_ready ];
		} catch ( \Throwable $error ) { $db->cancelAtomic( __METHOD__ );throw $error; }
	}

	private static function lockState( $db ): object {
		$state = $db->newSelectQueryBuilder()->select( '*' )->from( 'eql_drop_state' )->where( [ 'dps_id' => 1 ] )
			->forUpdate()->caller( __METHOD__ )->fetchRow();
		if ( !$state ) { throw new \RuntimeException( 'Projection state missing.' ); }
		return $state;
	}

	private static function missing( $db, int $limit, ?int $after ): array {
		$conditions = [ 'o.eoo_kind' => 'loot', 'r.dpr_observation IS NULL' ];
		if ( $after !== null ) { $conditions[] = 'o.eoo_id > ' . $after; }
		$rows = $db->select( [ 'o' => 'eql_eoz_observations', 'r' => 'eql_drop_records' ],
			[ 'o.eoo_id', 'o.eoo_actor', 'o.eoo_kind', 'o.eoo_payload', 'o.eoo_received' ], $conditions,
			__METHOD__, [ 'ORDER BY' => 'o.eoo_id ASC', 'LIMIT' => $limit, 'FOR UPDATE' ],
			[ 'r' => [ 'LEFT JOIN', 'r.dpr_observation=o.eoo_id' ] ] );
		$result = [];foreach ( $rows as $row ) { $result[] = $row; }return $result;
	}

	private static function apply( $db, object $state, iterable $records ): void {
		foreach ( $records as $record ) {
			$record = (array)$record;
			if ( ( $record['eoo_kind'] ?? '' ) !== 'loot' ) { continue; }
			$id = (int)$record['eoo_id'];
			if ( $id < 1 ) { throw new \RuntimeException( 'Invalid observation cursor.' ); }
			if ( $db->selectField( 'eql_drop_records', 'dpr_observation', [ 'dpr_observation' => $id ], __METHOD__, [ 'FOR UPDATE' ] ) !== false ) { continue; }
			$entry = DropObservationSummary::cumulativeRecord( $record );
			$sequence = ++$state->dps_revision;
			// Ineligible rows get a durable classification marker, without public detail.
			$db->insert( 'eql_drop_records', [ 'dpr_observation' => $id, 'dpr_entry' => $entry['id'] ?? null,
				'dpr_sequence' => $sequence, 'dpr_observed' => $entry['detail']['observedAt'] ?? null,
				'dpr_received' => $entry['detail']['receivedAt'] ?? null, 'dpr_quantity' => $entry['detail']['quantity'] ?? 0,
				'dpr_character' => $entry['detail']['characterName'] ?? null ], __METHOD__ );
			if ( $entry === null ) { continue; }
			$scope = $db->selectRow( 'eql_drop_scopes', '*', [ 'dsc_id' => $entry['scope'] ], __METHOD__, [ 'FOR UPDATE' ] );
			if ( $scope ) { $db->update( 'eql_drop_scopes', [ 'dsc_reports' => (int)$scope->dsc_reports + 1 ], [ 'dsc_id' => $entry['scope'] ], __METHOD__ ); }
			else { $db->insert( 'eql_drop_scopes', [ 'dsc_id' => $entry['scope'], 'dsc_reports' => 1 ], __METHOD__ ); }
			$actorKey = [ 'dco_entry' => $entry['id'], 'dco_actor' => $entry['actor'] ];
			$newActor = $db->selectField( 'eql_drop_contributors', 'dco_actor', $actorKey, __METHOD__, [ 'FOR UPDATE' ] ) === false;
			if ( $newActor ) { $db->insert( 'eql_drop_contributors', $actorKey, __METHOD__ ); }
			$prior = $db->selectRow( 'eql_drop_entries', '*', [ 'den_id' => $entry['id'] ], __METHOD__, [ 'FOR UPDATE' ] );
			$values = [ 'den_scope' => $entry['scope'], 'den_zone' => $entry['zone'], 'den_npc' => $entry['npc'],
				'den_item' => $entry['item'], 'den_instance' => $entry['instance'],
				'den_search' => mb_strtolower( $entry['zone'] . ' ' . $entry['npc'] . ' ' . $entry['item'], 'UTF-8' ),
				'den_reports' => (int)( $prior->den_reports ?? 0 ) + 1,
				'den_quantity' => (int)( $prior->den_quantity ?? 0 ) + $entry['detail']['quantity'],
				'den_contributors' => (int)( $prior->den_contributors ?? 0 ) + (int)$newActor, 'den_revision' => $sequence ];
			foreach ( [ 'zone', 'npc', 'item' ] as $field ) {
				$column = 'den_' . $field . '_title';$values[$column] = $entry[$field . 'Title'] ?? ( $prior->$column ?? null );
			}
			if ( $prior ) { $db->update( 'eql_drop_entries', $values, [ 'den_id' => $entry['id'] ], __METHOD__ ); }
			else { $db->insert( 'eql_drop_entries', [ 'den_id' => $entry['id'] ] + $values, __METHOD__ ); }
		}
	}
}
