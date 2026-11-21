<?php

declare( strict_types = 1 );

namespace EQLClientData;

use InvalidArgumentException;
use WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;

/** Bounded read-only projection. The existing private ingestion rows are never modified. */
final class DropDatabaseRepository {
	public const WINDOW_LIMIT = 100000;
	public const READ_CHUNK_SIZE = 500;
	public const CACHE_SECONDS = 300;

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly WANObjectCache $cache
	) {
	}

	/** Exact source filters plus a literal case-insensitive text search; paginate after grouping. */
	public function getSummary( array $filters = [] ): array {
		$filters = self::filters( $filters );
		$db = $this->dbProvider->getPrimaryDatabase();
		if ( !$db->tableExists( 'eql_eoz_observations', __METHOD__ ) ) {
			return self::unavailable( $filters );
		}
		$key = $this->cache->makeKey( 'eqldrops', 'summary1-v3', self::WINDOW_LIMIT );
		$snapshot = $this->cache->getWithSetCallback( $key, self::CACHE_SECONDS,
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
				$rows = DropObservationSummary::summarize( $bounded() );
				return [ 'rows' => $rows, 'windowed' => $windowed ];
			} );
		$rows = array_values( array_filter( $snapshot['rows'], static fn ( array $row ): bool => self::matches( $row, $filters ) ) );
		$total = count( $rows );
		return [
			'available' => true, 'windowLimit' => self::WINDOW_LIMIT, 'windowed' => $snapshot['windowed'],
			'rows' => array_slice( $rows, $filters['offset'], $filters['limit'] ), 'totalRows' => $total,
			'offset' => $filters['offset'], 'limit' => $filters['limit'],
			'hasMore' => $filters['offset'] + $filters['limit'] < $total
		];
	}

	private static function filters( array $filters ): array {
		if ( array_diff( array_keys( $filters ), [ 'zone', 'npc', 'item', 'server', 'instance', 'q', 'offset', 'limit' ] ) ) {
			throw new InvalidArgumentException( 'badvalue' );
		}
		foreach ( [ 'zone', 'npc', 'item', 'server', 'instance', 'q' ] as $name ) {
			$value = $filters[$name] ?? '';
			if ( !is_string( $value ) || !preg_match( '//u', $value ) ||
				mb_strlen( $value, 'UTF-8' ) > 230 || preg_match( '/[\x00-\x1f\x7f]/u', $value ) ) {
				throw new InvalidArgumentException( 'badvalue' );
			}
			$filters[$name] = trim( $value );
		}
		if ( $filters['instance'] !== '' && !in_array( $filters['instance'], [ 'normal', 'raid', 'unknown' ], true ) ) {
			throw new InvalidArgumentException( 'badvalue' );
		}
		foreach ( [ 'offset' => [ 0, 1000000, 0 ], 'limit' => [ 1, 500, 50 ] ] as $name => [ $min, $max, $default ] ) {
			$value = $filters[$name] ?? $default;
			if ( !is_int( $value ) || $value < $min || $value > $max ) { throw new InvalidArgumentException( 'badvalue' ); }
			$filters[$name] = $value;
		}
		return $filters;
	}

	private static function matches( array $row, array $filters ): bool {
		foreach ( [ 'zone', 'npc', 'item', 'server', 'instance' ] as $name ) {
			if ( $filters[$name] !== '' && $row[$name] !== $filters[$name] &&
				( $row[$name . 'Title'] ?? null ) !== $filters[$name] ) { return false; }
		}
		return $filters['q'] === '' || mb_stripos( implode( ' ', [ $row['zone'], $row['npc'], $row['item'], $row['server'] ] ), $filters['q'], 0, 'UTF-8' ) !== false;
	}

	private static function unavailable( array $filters ): array {
		return [ 'available' => false, 'windowLimit' => self::WINDOW_LIMIT, 'windowed' => false,
			'rows' => [], 'totalRows' => 0, 'offset' => $filters['offset'], 'limit' => $filters['limit'], 'hasMore' => false ];
	}
}
