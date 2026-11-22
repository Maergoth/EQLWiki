<?php

declare( strict_types = 1 );

namespace EQLClientData;

use InvalidArgumentException;
use RuntimeException;
use WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;

/** Bounded read-only projection. The existing private ingestion rows are never modified. */
final class DropDatabaseRepository {
	public const WINDOW_LIMIT = 100000;
	public const READ_CHUNK_SIZE = 500;
	public const CACHE_SECONDS = 300;
	public const FILTER_MAX_CHARS = 256;

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly WANObjectCache $cache
	) {
	}

	/** Exact source filters plus a literal case-insensitive text search; paginate after grouping. */
	public function getSummary( array $filters = [] ): array {
		return $this->summary( self::filters( $filters ) );
	}

	/** Browser fields search within each subject; API identity filters stay exact. */
	public function searchSummary( array $filters = [] ): array {
		$filters = self::filters( $filters );$contains = [];
		foreach ( [ 'zone', 'npc', 'item' ] as $field ) {
			if ( $filters[$field] !== '' ) { $contains[$field] = $filters[$field];$filters[$field] = ''; }
		}
		return $this->summary( $filters, $contains );
	}

	private function summary( array $filters, array $contains = [] ): array {
		$db = $this->dbProvider->getPrimaryDatabase();
		$cumulative = CumulativeDropStore::state( $db );
		if ( $cumulative && $cumulative->dps_ready ) { return ( new CumulativeDropReader( $this->cache ) )->summary( $db, $filters, $contains ); }
		$snapshot = $this->snapshot();
		if ( !$snapshot['available'] ) { return self::unavailable( $filters ); }
		$rows = array_values( array_filter( $snapshot['rows'], static fn ( array $row ): bool => self::matches( $row, $filters, $contains ) ) );
		$total = count( $rows );
		return [
			'available' => true, 'snapshot' => $snapshot['snapshot'], 'historyMode' => 'recent-window',
			'cumulativeStatus' => $cumulative ? 'backfilling' : 'not-installed', 'windowLimit' => self::WINDOW_LIMIT, 'windowed' => $snapshot['windowed'],
			'rows' => array_slice( $rows, $filters['offset'], $filters['limit'] ), 'totalRows' => $total,
			'offset' => $filters['offset'], 'limit' => $filters['limit'],
			'hasMore' => $filters['offset'] + $filters['limit'] < $total
		];
	}

	/** Publish only a bounded allowlist from a currently public, snapshot-bound entry. */
	public function getDetail( string $entryId, string $token, int $offset = 0, int $limit = 25 ): array {
		if ( !preg_match( '/^drop_[a-f0-9]{64}$/D', $entryId ) || !preg_match( '/^[a-f0-9]{64}$/D', $token ) ||
			$offset < 0 || $limit < 1 || $limit > 50 ) { throw new InvalidArgumentException( 'badvalue' ); }
		$db = $this->dbProvider->getPrimaryDatabase();
		$cumulative = CumulativeDropStore::state( $db );
		if ( $cumulative && $cumulative->dps_ready ) { return ( new CumulativeDropReader( $this->cache ) )->detail( $db, $entryId, $token, $offset, $limit ); }
		$snapshot = $this->snapshot();
		if ( !$snapshot['available'] ) { throw new RuntimeException( 'eqldrops-detail-unavailable' ); }
		if ( !hash_equals( $snapshot['snapshot'], $token ) ) { throw new RuntimeException( 'eqldrops-detail-stale' ); }
		$members = $snapshot['members'][$entryId] ?? null;
		if ( !$members ) { throw new RuntimeException( 'eqldrops-detail-unavailable' ); }
		$ids = array_slice( $members, $offset, $limit );
		$rows = [];
		if ( $ids ) {
			$db = $this->dbProvider->getPrimaryDatabase();
			$records = $db->select( 'eql_eoz_observations', [ 'eoo_id', 'eoo_actor', 'eoo_payload', 'eoo_received' ],
				[ 'eoo_kind' => 'loot', 'eoo_id' => $ids ], __METHOD__, [ 'ORDER BY' => 'eoo_id DESC', 'LIMIT' => $limit ] );
			foreach ( $records as $record ) {
				$detail = DropObservationSummary::detail( (array)$record, $entryId );
				if ( $detail === null ) { throw new RuntimeException( 'eqldrops-detail-stale' ); }
				$rows[] = $detail;
			}
			if ( count( $rows ) !== count( $ids ) ) { throw new RuntimeException( 'eqldrops-detail-stale' ); }
		}
		return [ 'rows' => $rows, 'totalRows' => count( $members ), 'offset' => $offset, 'limit' => $limit,
			'hasMore' => $offset + $limit < count( $members ) ];
	}

	private function snapshot(): array {
		$db = $this->dbProvider->getPrimaryDatabase();
		if ( !$db->tableExists( 'eql_eoz_observations', __METHOD__ ) ) {
			return [ 'available' => false ];
		}
		// Admission policy belongs in the read cache, not the stored cumulative format.
		$key = $this->cache->makeKey( 'eqldrops', 'summary2-details1', self::WINDOW_LIMIT, DropObservationSummary::MIN_REPORTS );
		return $this->cache->getWithSetCallback( $key, self::CACHE_SECONDS,
			function ( $oldValue, &$ttl, array &$setOpts ) use ( $db ): array {
				// Keyset chunks bound raw payload memory and share one descending snapshot.
				// Newly received rows cannot enter once the first chunk establishes the cursor.
				$windowed = false;
				$bounded = static function () use ( $db, &$windowed ): \Generator {
					$count = 0;
					$cursor = null;
					while ( $count <= self::WINDOW_LIMIT ) {
						$conditions = [ 'eoo_kind' => 'loot' ];
						if ( $cursor !== null ) { $conditions[] = 'eoo_id < ' . $cursor; }
						$limit = min( self::READ_CHUNK_SIZE, self::WINDOW_LIMIT + 1 - $count );
						$records = $db->select( 'eql_eoz_observations', [ 'eoo_id', 'eoo_actor', 'eoo_payload' ],
							$conditions, __METHOD__, [ 'ORDER BY' => 'eoo_id DESC', 'LIMIT' => $limit ] );
						$inChunk = 0;
						foreach ( $records as $record ) {
							if ( ++$count > self::WINDOW_LIMIT ) { $windowed = true; return; }
							$id = (int)( (array)$record )['eoo_id'];
							if ( $id < 1 || $cursor !== null && $id >= $cursor ) {
								throw new \RuntimeException( 'drop-database-cursor' );
							}
							$cursor = $id;
							$inChunk++;
							yield $record;
						}
						if ( $inChunk < $limit ) { return; }
					}
				};
				$projection = DropObservationSummary::project( $bounded() );
				$token = hash( 'sha256', json_encode( [ 'schema2-details1', DropObservationSummary::MIN_REPORTS, $projection ], JSON_THROW_ON_ERROR ) );
				return [ 'available' => true, 'snapshot' => $token, 'windowed' => $windowed ] + $projection;
			} );
	}

	private static function filters( array $filters ): array {
		if ( array_diff( array_keys( $filters ), [ 'zone', 'npc', 'item', 'instance', 'q', 'offset', 'limit' ] ) ) {
			throw new InvalidArgumentException( 'badvalue' );
		}
		foreach ( [ 'zone', 'npc', 'item', 'instance', 'q' ] as $name ) {
			$value = $filters[$name] ?? '';
			if ( !is_string( $value ) || !preg_match( '//u', $value ) ||
				mb_strlen( $value, 'UTF-8' ) > self::FILTER_MAX_CHARS || preg_match( '/[\x00-\x1f\x7f]/u', $value ) ) {
				throw new InvalidArgumentException( 'badvalue' );
			}
			$filters[$name] = trim( $value );
		}
		if ( $filters['instance'] !== '' && !in_array( $filters['instance'], [ 'normal', 'raid', 'unknown' ], true ) ) {
			throw new InvalidArgumentException( 'badvalue' );
		}
		foreach ( [ 'offset' => [ 0, PHP_INT_MAX, 0 ], 'limit' => [ 1, 500, 50 ] ] as $name => [ $min, $max, $default ] ) {
			$value = $filters[$name] ?? $default;
			if ( !is_int( $value ) || $value < $min || $value > $max ) { throw new InvalidArgumentException( 'badvalue' ); }
			$filters[$name] = $value;
		}
		return $filters;
	}

	private static function matches( array $row, array $filters, array $contains = [] ): bool {
		foreach ( [ 'zone', 'npc', 'item', 'instance' ] as $name ) {
			if ( $filters[$name] !== '' && $row[$name] !== $filters[$name] &&
				( $row[$name . 'Title'] ?? null ) !== $filters[$name] ) { return false; }
		}
		foreach ( $contains as $field => $needle ) {
			if ( mb_stripos( $row[$field], $needle, 0, 'UTF-8' ) === false &&
				mb_stripos( $row[$field . 'Title'] ?? '', $needle, 0, 'UTF-8' ) === false ) { return false; }
		}
		return $filters['q'] === '' || mb_stripos( implode( ' ', [ $row['zone'], $row['npc'], $row['item'] ] ), $filters['q'], 0, 'UTF-8' ) !== false;
	}

	private static function unavailable( array $filters ): array {
		return [ 'available' => false, 'snapshot' => null, 'windowLimit' => self::WINDOW_LIMIT, 'windowed' => false,
			'rows' => [], 'totalRows' => 0, 'offset' => $filters['offset'], 'limit' => $filters['limit'], 'hasMore' => false ];
	}
}
