<?php
declare( strict_types = 1 );
namespace EQLClientData;

/** Indexed aggregate pages and bounded submission reads; no raw history or member-list cache. */
final class CumulativeDropReader {
	public const SNAPSHOT_TTL = 300;
	public function __construct( private readonly \WANObjectCache $cache ) {}

	public function summary( $db, array $filters, array $contains = [] ): array {
		$conditions = [ 'e.den_reports >= ' . DropObservationSummary::MIN_REPORTS ];
		foreach ( [ 'zone', 'npc', 'item', 'instance' ] as $field ) {
			if ( $filters[$field] !== '' ) { $conditions['e.den_' . $field] = $filters[$field]; }
		}
		foreach ( [ 'zone', 'npc', 'item' ] as $field ) {
			if ( !isset( $contains[$field] ) ) { continue; }
			// Stored names are VARBINARY; LOWER(binary) alone would not fold UTF-8 text.
			$conditions[] = 'LOWER(CONVERT(e.den_' . $field . ' USING utf8mb4)) COLLATE utf8mb4_bin ' .
				$db->buildLike( $db->anyString(), mb_strtolower( $contains[$field], 'UTF-8' ), $db->anyString() );
		}
		if ( $filters['q'] !== '' ) {
			$conditions[] = 'e.den_search ' . $db->buildLike( $db->anyString(), mb_strtolower( $filters['q'], 'UTF-8' ), $db->anyString() );
		}
		$tables = [ 'e' => 'eql_drop_entries', 's' => 'eql_drop_scopes' ];
		$join = [ 's' => [ 'INNER JOIN', 's.dsc_id=e.den_scope' ] ];
		$total = (int)$db->selectField( $tables, 'COUNT(*)', $conditions, __METHOD__, [], $join );
		$records = $filters['offset'] >= $total ? [] : $db->select( $tables, [ 'e.*', 's.dsc_reports' ], $conditions, __METHOD__,
			[ 'ORDER BY' => 'e.den_zone,e.den_npc,e.den_instance,e.den_item,e.den_id', 'LIMIT' => $filters['limit'], 'OFFSET' => $filters['offset'] ], $join );
		$rows = []; $details = [];
		foreach ( $records as $record ) {
			$id = 'drop_' . $record->den_id;
			$rows[] = [ 'entryId' => $id, 'zone' => $record->den_zone, 'npc' => $record->den_npc, 'item' => $record->den_item,
				'zoneTitle' => $record->den_zone_title, 'npcTitle' => $record->den_npc_title, 'itemTitle' => $record->den_item_title,
				'instance' => $record->den_instance, 'reports' => (int)$record->den_reports, 'quantity' => (int)$record->den_quantity,
				'contributors' => (int)$record->den_contributors, 'totalReports' => (int)$record->dsc_reports,
				'reportShare' => 100.0 * (int)$record->den_reports / (int)$record->dsc_reports ];
			// At most one tiny boundary per displayed entry, never one ID per submission.
			$details[$id] = [ 'revision' => (int)$record->den_revision, 'reports' => (int)$record->den_reports ];
		}
		$identity = [ CumulativeDropStore::VERSION, DropObservationSummary::MIN_REPORTS, $filters, $rows, $details ];
		if ( $contains ) { $identity[] = $contains; }
		$token = hash( 'sha256', json_encode( $identity, JSON_THROW_ON_ERROR ) );
		try { $saved = $this->cache->set( $this->key( $token ), $details, self::SNAPSHOT_TTL ); }
		catch ( \Throwable $error ) { $saved = false; }
		return [ 'available' => true, 'snapshot' => $token, 'historyMode' => 'cumulative', 'cumulativeStatus' => 'ready',
			'detailsAvailable' => (bool)$saved,
			'windowLimit' => null, 'windowed' => false, 'rows' => $rows, 'totalRows' => $total,
			'offset' => $filters['offset'], 'limit' => $filters['limit'], 'hasMore' => $filters['offset'] + count( $rows ) < $total ];
	}

	public function detail( $db, string $entryId, string $token, int $offset, int $limit ): array {
		try { $manifest = $this->cache->get( $this->key( $token ) ); }
		catch ( \Throwable $error ) { $manifest = false; }
		if ( !is_array( $manifest ) ) { throw new \RuntimeException( 'eqldrops-detail-stale' ); }
		$boundary = $manifest[$entryId] ?? null;
		if ( !$boundary ) { throw new \RuntimeException( 'eqldrops-detail-unavailable' ); }
		$id = substr( $entryId, 5 );
		$entry = $db->selectRow( 'eql_drop_entries', [ 'den_reports', 'den_revision' ], [ 'den_id' => $id ], __METHOD__ );
		if ( !$entry || (int)$entry->den_reports < DropObservationSummary::MIN_REPORTS ||
			(int)$entry->den_revision < $boundary['revision'] ) { throw new \RuntimeException( 'eqldrops-detail-stale' ); }
		if ( $offset >= $boundary['reports'] ) {
			return [ 'rows' => [], 'totalRows' => $boundary['reports'], 'offset' => $offset, 'limit' => $limit, 'hasMore' => false ];
		}
		$records = $db->select( 'eql_drop_records', [ 'dpr_observed', 'dpr_received', 'dpr_quantity', 'dpr_character' ],
			[ 'dpr_entry' => $id, 'dpr_sequence <= ' . (int)$boundary['revision'] ], __METHOD__,
			[ 'ORDER BY' => 'dpr_observation DESC', 'LIMIT' => $limit, 'OFFSET' => $offset ] );
		$rows = [];
		foreach ( $records as $record ) {
			$rows[] = [ 'observedAt' => $record->dpr_observed, 'receivedAt' => $record->dpr_received,
				'quantity' => (int)$record->dpr_quantity, 'characterName' => $record->dpr_character, 'sourceKind' => 'explicit-corpse' ];
		}
		if ( count( $rows ) !== max( 0, min( $limit, $boundary['reports'] - $offset ) ) ) { throw new \RuntimeException( 'eqldrops-detail-stale' ); }
		return [ 'rows' => $rows, 'totalRows' => $boundary['reports'], 'offset' => $offset, 'limit' => $limit,
			'hasMore' => $offset + count( $rows ) < $boundary['reports'] ];
	}

	private function key( string $token ): string { return $this->cache->makeKey( 'eqldrops', CumulativeDropStore::VERSION, 'detail', $token ); }
}
