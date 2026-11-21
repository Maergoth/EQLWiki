<?php
/** Synthetic aggregate/repository/API checks. No wiki bootstrap or database/account writes. */
declare( strict_types = 1 );

namespace Wikimedia\Rdbms { interface IConnectionProvider {} }
namespace Wikimedia\ParamValidator { class ParamValidator { public const PARAM_TYPE = 'type'; public const PARAM_DEFAULT = 'default'; } }
namespace Wikimedia\ParamValidator\TypeDef { class IntegerDef { public const PARAM_MIN = 'min'; public const PARAM_MAX = 'max'; } }
namespace MediaWiki\Api {
	class ApiMain {
		public array $data = [];
		public function setCacheMode( $mode ): void { \check( $mode === 'public', 'Aggregate API contains only public rows.' ); }
		public function setCacheMaxAge( $age ): void { \check( $age === 60, 'HTTP aggregate cache is bounded.' ); }
	}
	class ApiBase {
		public array $params = [];
		public function __construct( protected ApiMain $main, protected string $action ) {}
		public function extractRequestParams(): array { return $this->params; }
		public function getMain(): ApiMain { return $this->main; }
		public function getModuleName(): string { return $this->action; }
		public function dieWithError( $message, $code ): void { throw new \InvalidArgumentException( $code ); }
		public function getResult() { return new class( $this->main ) {
			public function __construct( private ApiMain $main ) {}
			public function addValue( $path, $name, $value ): void { $this->main->data[$name] = $value; }
		}; }
	}
}
namespace {
	use EQLClientData\ApiDrops;
	use EQLClientData\DropDatabaseRepository;
	use EQLClientData\DropObservationSummary;

	require __DIR__ . '/../extensions/EQLClientData/includes/ObservationPayload.php';
	require __DIR__ . '/../extensions/EQLClientData/includes/DropObservationSummary.php';
	require __DIR__ . '/../extensions/EQLClientData/includes/DropDatabaseRepository.php';
	require __DIR__ . '/../extensions/EQLClientData/includes/ApiDrops.php';

	$checks = 0;
	function check( bool $condition, string $label ): void {
		global $checks;
		if ( !$condition ) { throw new \RuntimeException( $label ); }
		$checks++;
	}
	function invalid( callable $call ): void {
		try { $call(); } catch ( \InvalidArgumentException $error ) { check( $error->getMessage() === 'badvalue', 'Bad filters fail explicitly.' ); return; }
		throw new \RuntimeException( 'Expected bad filter rejection.' );
	}
	class WANObjectCache {
		public array $values = [];
		public function makeKey( ...$parts ): string { return implode( ':', $parts ); }
		public function getWithSetCallback( $key, $ttl, $callback ) {
			check( $ttl === 300, 'Shared projection cache is five minutes.' );
			if ( !isset( $this->values[$key] ) ) { $options = []; $this->values[$key] = $callback( false, $ttl, $options ); }
			return $this->values[$key];
		}
	}
	class DropFixtureDB {
		public bool $available = true;
		public int $reads = 0;
		public array $rows;
		public function __construct( array $rows ) {
			$count = count( $rows );
			$this->rows = array_map( static fn ( $row, $index ) => [ 'eoo_id' => $count - $index ] + $row, $rows, array_keys( $rows ) );
		}
		public function tableExists( $table, $caller ): bool {
			check( $table === 'eql_eoz_observations', 'Read only the observation table.' ); return $this->available;
		}
		public function select( $table, $fields, $conditions, $caller, $options ): array {
			check( $fields === [ 'eoo_id', 'eoo_actor', 'eoo_payload' ], 'Select only private cursor, attribution and event payload for the projection.' );
			check( $conditions['eoo_kind'] === 'loot', 'Only loot candidates enter the summary window.' );
			check( $options['ORDER BY'] === 'eoo_id DESC' && $options['LIMIT'] <= 500, 'Read descending keyset chunks with at most 500 raw payloads.' );
			$cursor = null;
			if ( isset( $conditions[0] ) ) {
				check( preg_match( '/^eoo_id < ([1-9][0-9]*)$/D', $conditions[0], $match ) === 1, 'Continue with a bounded numeric private cursor.' );
				$cursor = (int)$match[1];
			}
			$this->reads++;
			$rows = $cursor === null ? $this->rows : array_values( array_filter( $this->rows, static fn ( $row ) => $row['eoo_id'] < $cursor ) );
			return array_slice( $rows, 0, $options['LIMIT'] );
		}
	}
	class DropFixtureProvider implements \Wikimedia\Rdbms\IConnectionProvider {
		public function __construct( private DropFixtureDB $db ) {}
		public function getPrimaryDatabase(): DropFixtureDB { return $this->db; }
	}
	function record( int $number, array $changes = [], int $actor = 70001 ): array {
		$event = array_replace( [
			'id' => 'fixture_event_' . $number, 'kind' => 'loot', 'observedAt' => '2026-10-24T10:00:00.000Z',
			'server' => 'Fixture server', 'zone' => 'Fixture zone', 'zoneTitle' => 'Fixture zone',
			'npc' => 'a fixture guardian', 'npcTitle' => 'A fixture guardian',
			'item' => 'Fixture blade', 'itemTitle' => 'Fixture blade', 'quantity' => 1,
			'tier' => 0, 'instance' => 'normal', 'sourceKind' => 'explicit-corpse',
			'sourceToken' => 'private_source_987654', 'sessionToken' => 'private_session_987654', 'sequence' => $number
		], $changes );
		return [ 'eoo_actor' => $actor, 'eoo_payload' => json_encode( $event, JSON_THROW_ON_ERROR ) ];
	}
	$records = [
		record( 1, [ 'item' => 'an alias for the blade', 'quantity' => 2 ] ), record( 2 ),
		record( 3, [ 'tier' => 1 ] ), record( 4, [ 'tier' => 1 ], 70002 ),
		record( 5, [ 'item' => 'Hidden single', 'itemTitle' => null ] ),
		record( 6, [ 'instance' => 'raid' ] ), record( 7, [ 'instance' => 'raid' ] ),
		record( 8, [ 'server' => 'Other server' ] ), record( 9, [ 'server' => 'Other server' ] ),
		record( 10, [ 'sourceKind' => 'recent-kill' ] ), record( 11, [ 'sourceKind' => 'recent-kill' ] ),
		record( 12, [ 'sourceKind' => 'explicit-source' ] ), record( 13, [ 'sourceKind' => 'explicit-source' ] ),
		record( 14, [ 'zone' => '', 'zoneTitle' => null ] ), record( 15, [ 'zone' => '', 'zoneTitle' => null ] ),
		record( 16, [ 'kind' => 'kill', 'sourceKind' => 'kill-line', 'item' => '', 'itemTitle' => null ] ),
		[ 'eoo_actor' => 70001, 'eoo_payload' => '{invalid JSON' ],
		record( 17, [ 'playerName' => 'private_player_987654' ] )
	];
	$records[] = $records[0];
	$rows = DropObservationSummary::summarize( $records );
	check( count( $rows ) === 4, 'Publish repeated exact source/item/tier rows only.' );
	$normal = array_values( array_filter( $rows, static fn ( $row ) => $row['instance'] === 'normal' && $row['server'] === 'Fixture server' ) );
	check( count( $normal ) === 2 && $normal[0]['tier'] === 0 && $normal[1]['tier'] === 1, 'Tier groups stay separate and ordered.' );
	check( $normal[0]['reports'] === 2 && $normal[0]['quantity'] === 3 && $normal[0]['contributors'] === 1, 'Two submissions from one actor qualify; quantities are distinct from reports.' );
	check( $normal[1]['contributors'] === 2, 'Expose contributor count without contributor identities.' );
	check( $normal[0]['totalReports'] === 5 && $normal[0]['reportShare'] === 40.0 && $normal[1]['reportShare'] === 40.0,
		'Every eligible item/tier and hidden singleton remains in denominator.' );
	check( $normal[0]['item'] === 'Fixture blade', 'Canonical title claims group raw-name aliases exactly.' );
	$encoded = json_encode( $rows, JSON_THROW_ON_ERROR );
	foreach ( [ '70001', '70002', 'fixture_event', 'private_source', 'private_session', 'private_player', 'eoo_', 'dropRate' ] as $private ) {
		check( !str_contains( $encoded, $private ), 'Public aggregate excludes ' . $private );
	}
	$overlap = DropObservationSummary::summarize( [ record( 91 ), record( 91, [], 70002 ) ] );
	check( $overlap[0]['reports'] === 2 && $overlap[0]['contributors'] === 2, 'Same event key from another account is a report, never proven unique kill.' );
	$case = DropObservationSummary::summarize( [ record( 92 ), record( 93, [ 'itemTitle' => 'Fixture Blade' ] ) ] );
	check( $case === [], 'Do not fuzzy-merge internal title case.' );
	$firstLetter = DropObservationSummary::summarize( [ record( 94 ), record( 95, [ 'npcTitle' => 'a fixture guardian', 'zoneTitle' => 'Fixture_zone' ] ) ] );
	check( $firstLetter[0]['reports'] === 2 && $firstLetter[0]['npcTitle'] === 'A fixture guardian', 'Wiki first-letter and underscore normalization groups exact canonical titles.' );
	$roundingRecords = static function (): \Generator {
		for ( $i = 1; $i <= 617; $i++ ) {
			yield record( 1000 + $i, $i <= 273 ? [] : [ 'item' => 'Other item', 'itemTitle' => 'Other item' ] );
		}
	};
	$rounding = DropObservationSummary::summarize( $roundingRecords() );
	check( number_format( $rounding[0]['reportShare'], 1 ) === '44.2',
		'273/617 displays 44.2% in both database and overlay; intermediate rounding must not produce 44.3%.' );

	$db = new DropFixtureDB( $records );
	$cache = new WANObjectCache();
	$repository = new DropDatabaseRepository( new DropFixtureProvider( $db ), $cache );
	$result = $repository->getSummary( [ 'server' => 'Fixture server', 'instance' => 'normal', 'limit' => 1 ] );
	check( $result['available'] && !$result['windowed'] && $result['totalRows'] === 2 && $result['hasMore'] && count( $result['rows'] ) === 1,
		'Filter and paginate the public projection.' );
	check( $result['rows'][0]['totalReports'] === 5, 'Filtering and paging cannot renormalize the denominator.' );
	$next = $repository->getSummary( [ 'q' => 'FIXTURE BLADE', 'offset' => 1, 'limit' => 1 ] );
	check( count( $next['rows'] ) === 1 && $next['totalRows'] === 4 && $db->reads === 1, 'Literal search is case insensitive and all filters share the cached projection.' );
	check( $repository->getSummary( [ 'npc' => 'a fixture guardian' ] )['totalRows'] === 0, 'Exact source filtering does not fuzzy merge case.' );
	check( $repository->getSummary( [ 'item' => 'Fixture blade' ] )['totalRows'] === 4, 'Exact canonical item filters work.' );
	foreach ( [ [ 'offset' => -1 ], [ 'limit' => 501 ], [ 'limit' => '50' ], [ 'q' => [] ], [ 'q' => "bad\nquery" ], [ 'instance' => 'mythic' ], [ 'actor' => '70001' ] ] as $bad ) {
		invalid( static fn () => $repository->getSummary( $bad ) );
	}
	$db->available = false;
	check( !$repository->getSummary()['available'] && $db->reads === 1, 'Missing schema is unavailable, never a write or installation attempt.' );
	$large = new DropFixtureDB( array_fill( 0, 100002, record( 100 ) ) );
	$window = ( new DropDatabaseRepository( new DropFixtureProvider( $large ), new WANObjectCache() ) )->getSummary();
	check( $window['windowed'] && $window['windowLimit'] === 100000 && $large->reads === 201, 'Candidate truncation is explicit after 200 chunks and a one-row lookahead.' );

	$db->available = true;
	$main = new \MediaWiki\Api\ApiMain();
	$api = new ApiDrops( $main, 'eqldrops', $repository );
	$api->params = [ 'q' => 'blade', 'limit' => 50, 'offset' => 0 ];
	$api->execute();
	check( $main->data['eqldrops']['metric'] === 'loot-report-share' && str_contains( $main->data['eqldrops']['coverage'], 'not a drop chance' ),
		'API documents the metric and unknown coverage alongside the rows.' );
	check( !$api->isWriteMode() && !$api->mustBePosted() && !$api->isInternal(), 'Public API is read-only GET.' );
	$api->params = [ 'q' => [] ];
	invalid( static fn () => $api->execute() );
	check( isset( $api->getAllowedParams()['q'] ), 'Document the supported text-search parameter.' );
	echo "Drop database: $checks synthetic checks passed.\n";
}
