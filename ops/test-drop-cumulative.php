<?php
/** Synthetic SQL regression. Default SQLite memory DB; --mysql-fixture uses ONLY local port3319 / fixed disposable DB. */
declare( strict_types = 1 );
namespace Wikimedia\Rdbms { interface IConnectionProvider {} }
namespace MediaWiki\User {
	interface UserIdentity { public function getId();public function getName(); }
	class ActorStore { public function acquireActorId( $user, $db ): int { return $user->getId() + 100; } }
}
namespace MediaWiki\Config { abstract class Config { abstract public function get( $key ); } }
namespace MediaWiki\Maintenance {
	class Maintenance {
		public array $options = [];public $services;public int $commits = 0;
		public function __construct() {}public function addDescription( $x ): void {}public function addOption( ...$x ): void {}
		public function getOption( $key, $default = null ) { return $this->options[$key] ?? $default; }
		public function hasOption( $key ): bool { return isset( $this->options[$key] ); }
		public function getServiceContainer() { return $this->services; }public function output( $x ): void {}
		public function fatalError( $x ): void { throw new \RuntimeException( $x ); }
		protected function commitTransactionRound( string $caller ): void { $this->commits++; }
	}
}
namespace {
use EQLClientData\CumulativeDropStore as Store;
require __DIR__ . '/../extensions/EQLClientData/includes/ObservationPayload.php';
require __DIR__ . '/../extensions/EQLClientData/includes/DropObservationSummary.php';
require __DIR__ . '/../extensions/EQLClientData/includes/CumulativeDropStore.php';
require __DIR__ . '/../extensions/EQLClientData/includes/CumulativeDropReader.php';
require __DIR__ . '/../extensions/EQLClientData/includes/ObservationRepository.php';
require __DIR__ . '/../extensions/EQLClientData/includes/DropDatabaseRepository.php';
$checks = 0;
function check( bool $value, string $label ): void { global $checks;if ( !$value ) { throw new \RuntimeException( $label ); }$checks++; }
function refuses( callable $call, string $code ): void { try { $call(); } catch ( \Throwable $error ) { check( $error->getMessage() === $code, 'Expected ' . $code . ', got ' . $error->getMessage() );return; }throw new \RuntimeException( 'Expected refusal ' . $code ); }
class WANObjectCache {
	public array $values = [];public bool $fail = false;
	public function makeKey( ...$parts ): string { return implode( ':', $parts ); }
	public function set( $key, $value, $ttl ): bool { if ( $this->fail ) { return false; }$this->values[$key] = $value;return true; }
	public function get( $key ) { return $this->values[$key] ?? false; }
	public function getWithSetCallback( $key, $ttl, $callback ) { if ( !isset( $this->values[$key] ) ) { $opts = [];$this->values[$key] = $callback( false, $ttl, $opts ); }return $this->values[$key]; }
}
class SQLFixture {
	public const ATOMIC_CANCELABLE = 'cancelable';public array $selects = [];public bool $recordSelects = false;public ?string $failInsert = null;
	private array $levels = [];private array $prepared = [];
	public function __construct( public \PDO $pdo, public bool $mysql = false ) { $pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION ); }
	public function tableExists( $table, $caller ): bool {
		return $this->mysql ? (bool)$this->pdo->query( 'SHOW TABLES LIKE ' . $this->addQuotes( $table ) )->fetchColumn()
			: (bool)$this->pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name=" . $this->addQuotes( $table ) )->fetchColumn();
	}
	public function addQuotes( $value ): string { return $this->pdo->quote( (string)$value ); }
	public function anyString(): object { return (object)[ 'wildcard' => true ]; }
	public function buildLike( ...$parts ): string {
		$text = '';foreach ( $parts as $part ) { $text .= is_object( $part ) ? '%' : strtr( $part, [ '\\' => '\\\\', '%' => '\\%', '_' => '\\_' ] ); }
		return 'LIKE ' . $this->addQuotes( $text ) . ( $this->mysql ? '' : " ESCAPE '\\'" );
	}
	private function where( array $conditions ): string {
		$parts = [];foreach ( $conditions as $key => $value ) {
			if ( is_int( $key ) ) { $parts[] = $value; }
			elseif ( $value === null ) { $parts[] = $key . ' IS NULL'; }
			elseif ( is_array( $value ) ) { $parts[] = $key . ' IN (' . implode( ',', array_map( fn ( $x ) => $this->addQuotes( $x ), $value ) ) . ')'; }
			else { $parts[] = $key . '=' . $this->addQuotes( $value ); }
		}return $parts ? ' WHERE ' . implode( ' AND ', $parts ) : '';
	}
	public function select( $tables, $fields, $conditions, $caller, $options = [], $joins = [] ) {
		if ( !is_array( $tables ) ) { $tables = [ $tables ]; }
		$from = '';foreach ( $tables as $alias => $table ) {
			$name = is_int( $alias ) ? $table : $table . ' ' . $alias;
			if ( !$from ) { $from = $name; }else { $join = $joins[$alias];$from .= ' ' . $join[0] . ' ' . $name . ' ON ' . $join[1]; }
		}
		$sql = 'SELECT ' . ( is_array( $fields ) ? implode( ',', $fields ) : $fields ) . ' FROM ' . $from . $this->where( $conditions );
		if ( isset( $options['ORDER BY'] ) ) { $sql .= ' ORDER BY ' . $options['ORDER BY']; }
		if ( isset( $options['LIMIT'] ) ) { $sql .= ' LIMIT ' . (int)$options['LIMIT']; }
		if ( isset( $options['OFFSET'] ) ) { $sql .= ' OFFSET ' . (int)$options['OFFSET']; }
		if ( $this->mysql && in_array( 'FOR UPDATE', $options, true ) ) { $sql .= ' FOR UPDATE'; }
		if ( $this->recordSelects ) { $this->selects[] = [ 'tables' => $tables, 'fields' => $fields, 'options' => $options ]; }
		return $this->pdo->query( $sql, \PDO::FETCH_OBJ );
	}
	public function selectRow( $table, $fields, $conditions, $caller, $options = [] ) { return $this->select( $table, $fields, $conditions, $caller, $options )->fetch(); }
	public function selectField( $table, $field, $conditions, $caller, $options = [], $joins = [] ) { return $this->select( $table, $field, $conditions, $caller, $options, $joins )->fetchColumn(); }
	public function newSelectQueryBuilder() { return new class( $this ) {
		private $fields;private $table;private $where;private array $options = [];
		public function __construct( private SQLFixture $db ) {}
		public function select( $x ) { $this->fields = $x;return $this; }public function from( $x ) { $this->table = $x;return $this; }
		public function where( $x ) { $this->where = $x;return $this; }public function forUpdate() { $this->options[] = 'FOR UPDATE';return $this; }
		public function caller( $x ) { return $this; }public function fetchRow() { return $this->db->selectRow( $this->table, $this->fields, $this->where, __METHOD__, $this->options ); }
	}; }
	public function insert( $table, $row, $caller ): void {
		if ( $this->failInsert === $table ) { throw new \RuntimeException( 'synthetic-transaction-failure' ); }
		$sql = 'INSERT INTO ' . $table . '(' . implode( ',', array_keys( $row ) ) . ') VALUES(' . implode( ',', array_fill( 0, count( $row ), '?' ) ) . ')';
		( $this->prepared[$sql] ??= $this->pdo->prepare( $sql ) )->execute( array_values( $row ) );
	}
	public function update( $table, $row, $conditions, $caller ): void {
		$sql = 'UPDATE ' . $table . ' SET ' . implode( ',', array_map( static fn ( $key ) => $key . '=?', array_keys( $row ) ) ) . $this->where( $conditions );
		( $this->prepared[$sql] ??= $this->pdo->prepare( $sql ) )->execute( array_values( $row ) );
	}
	public function insertId(): int { return (int)$this->pdo->lastInsertId(); }
	public function getType(): string { return 'mysql'; }public function getDBname(): string { return 'eql_cumulative_fixture'; }
	public function startAtomic( $caller, $cancelable ): void {
		if ( $this->pdo->inTransaction() ) { $level = 's' . count( $this->levels );$this->pdo->exec( 'SAVEPOINT ' . $level );$this->levels[] = $level; }
		else { $this->pdo->beginTransaction();$this->levels[] = null; }
	}
	public function endAtomic( $caller ): void { $level = array_pop( $this->levels );if ( $level === null ) { $this->pdo->commit(); }else { $this->pdo->exec( 'RELEASE SAVEPOINT ' . $level ); } }
	public function cancelAtomic( $caller ): void { $level = array_pop( $this->levels );if ( $level === null ) { $this->pdo->rollBack(); }else { $this->pdo->exec( 'ROLLBACK TO SAVEPOINT ' . $level );$this->pdo->exec( 'RELEASE SAVEPOINT ' . $level ); } }
}
class Provider implements \Wikimedia\Rdbms\IConnectionProvider { public function __construct( private SQLFixture $db ) {}public function getPrimaryDatabase(): SQLFixture { return $this->db; } }
class Config extends \MediaWiki\Config\Config { public function get( $key ) { return match ( $key ) { 'EQLClientDataEnableObservations' => true,'EQLClientDataObservationHourlyBatches' => 120,default => 0 }; } }
class User implements \MediaWiki\User\UserIdentity { public function getId() { return 7; }public function getName() { return 'Synthetic Observer'; } }
function event( int $id, array $changes = [] ): array { return array_replace( [ 'id' => 'synthetic_event_' . $id,'kind' => 'loot','observedAt' => '2026-10-24T10:00:00.000Z',
	'zone' => 'Legacy zone','npc' => 'a legacy guardian','item' => 'Legacy blade','quantity' => 1,'instance' => 'normal','sourceKind' => 'explicit-corpse' ], $changes ); }
function raw( SQLFixture $db, int $id, array $changes = [], int $actor = 107 ): array {
	$event = event( $id, $changes );$record = [ 'eoo_id' => $id,'eoo_actor' => $actor,'eoo_key' => $event['id'],'eoo_hash' => hash( 'sha256', json_encode( $event ) ),'eoo_batch' => 'synthetic_legacy_batch',
		'eoo_kind' => $event['kind'],'eoo_observed' => '20261024100000','eoo_received' => '20261024100100','eoo_payload' => json_encode( $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ];
	$db->insert( 'eql_eoz_observations', $record, __METHOD__ );return $record;
}
function cumulativeDDL( SQLFixture $db ): void {
	$sql = file_get_contents( __DIR__ . '/../extensions/EQLClientData/sql/mysql/drop-summaries.sql' );
	if ( !$db->mysql ) {
		$sql = preg_replace( '/ENGINE=InnoDB DEFAULT CHARSET=binary/', '', $sql );
		$sql = preg_replace( '/\b(?:BIGINT|TINYINT|INT) UNSIGNED\b/', 'INTEGER', $sql );
		$sql = preg_replace( '/\b(?:VAR)?BINARY\(\d+\)/', 'TEXT', $sql );
		$sql = preg_replace( '/^\s*KEY [^\n]+\n/m', '', $sql );
		$sql = preg_replace( '/,\s*\)/', ')', $sql );
	}
	$db->pdo->exec( $sql );
	if ( !$db->mysql ) {
		$db->pdo->exec( 'CREATE INDEX dpr_entry_order ON eql_drop_records(dpr_entry,dpr_observation);CREATE INDEX dpr_entry_sequence ON eql_drop_records(dpr_entry,dpr_sequence);CREATE INDEX den_scope ON eql_drop_entries(den_scope)' );
	}
}
$mysql = in_array( '--mysql-fixture', $argv, true );
if ( $mysql ) {
	$admin = new \PDO( 'mysql:host=127.0.0.1;port=3319;charset=utf8mb4', 'root', 'synthetic-drop-summary-fixture', [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
	// Fixed disposable name only. No settings, application DB or real account credentials are read.
	$admin->exec( 'DROP DATABASE IF EXISTS eql_cumulative_fixture;CREATE DATABASE eql_cumulative_fixture CHARACTER SET utf8mb4' );
	$connect = static fn () => new \PDO( 'mysql:host=127.0.0.1;port=3319;dbname=eql_cumulative_fixture;charset=utf8mb4', 'root', 'synthetic-drop-summary-fixture' );
	$db = new SQLFixture( $connect(), true );
	$db->pdo->exec( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
} else { $db = new SQLFixture( new \PDO( 'sqlite::memory:' ) ); }
$auto = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$db->pdo->exec( "CREATE TABLE eql_eoz_state(eos_id INTEGER PRIMARY KEY,eos_events INTEGER,eos_batches INTEGER);INSERT INTO eql_eoz_state VALUES(1,0,0);
CREATE TABLE eql_eoz_observations(eoo_id $auto,eoo_actor INTEGER,eoo_key VARCHAR(120),eoo_hash VARCHAR(64),eoo_batch VARCHAR(120),eoo_kind VARCHAR(8),eoo_observed VARCHAR(14),eoo_received VARCHAR(14),eoo_payload TEXT,UNIQUE(eoo_actor,eoo_key));
CREATE TABLE eql_eoz_batches(eob_id $auto,eob_actor INTEGER,eob_key VARCHAR(120),eob_hash VARCHAR(64),eob_received VARCHAR(14),eob_client VARCHAR(160),eob_receipt TEXT,UNIQUE(eob_actor,eob_key));" );
check( Store::state( $db ) === null, 'Missing optional schema is safe.' );
$notInstalled = ( new \EQLClientData\DropDatabaseRepository( new Provider( $db ), new WANObjectCache() ) )->getSummary();
check( $notInstalled['historyMode'] === 'recent-window' && $notInstalled['cumulativeStatus'] === 'not-installed', 'Missing cumulative schema preserves the existing recent view.' );
cumulativeDDL( $db );
$cache = new WANObjectCache();$repository = new \EQLClientData\DropDatabaseRepository( new Provider( $db ), $cache );
check( Store::state( $db )->dps_ready == 0, 'New schema cannot publish partial cumulative totals.' );
$building = $repository->getSummary();check( $building['historyMode'] === 'recent-window' && $building['cumulativeStatus'] === 'backfilling', 'Incomplete projection exposes its explicit fallback status.' );
$db->pdo->beginTransaction();
for ( $i = 1; $i <= 100003; $i++ ) {
	if ( $i === 2 ) { continue; } // Leave an intentional low-id hole for late-commit reconciliation.
	raw( $db, $i, $i === 1 ? [ 'quantity' => 2, 'characterName' => 'Consented Character' ] : [] );
}
raw( $db, 100004, [ 'item' => 'Hidden singleton' ] );
raw( $db, 100005, [ 'sourceKind' => 'recent-kill' ] );raw( $db, 100006, [ 'zone' => 'an area where binding is forbidden' ] );
raw( $db, 100007, [ 'server' => 'Other server','tier' => 7 ], 108 );
$db->pdo->commit();
$rawProof = $db->pdo->query( 'SELECT COUNT(*) FROM eql_eoz_observations' )->fetchColumn();
$rawFirst = $db->pdo->query( 'SELECT eoo_payload,eoo_hash FROM eql_eoz_observations WHERE eoo_id=1' )->fetch( \PDO::FETCH_ASSOC );
$partial = Store::backfill( $db, 500 );check( !$partial['complete'] && $partial['processed'] === 500, 'A bounded chunk stays incomplete.' );
$db->startAtomic( __METHOD__, $db::ATOMIC_CANCELABLE );
$db->newSelectQueryBuilder()->select( 'eos_id' )->from( 'eql_eoz_state' )->where( [ 'eos_id' => 1 ] )->forUpdate()->caller( __METHOD__ )->fetchRow();
$ahead = $db->selectRow( 'eql_eoz_observations', '*', [ 'eoo_id' => 100003 ], __METHOD__ );
Store::accepted( $db, [ $ahead ] );$db->endAtomic( __METHOD__ );
check( Store::state( $db )->dps_ready == 0, 'New-writer projection ahead of a running backfill never activates partial totals.' );
$seen = $db->pdo->query( 'SELECT COUNT(*) FROM eql_drop_records' )->fetchColumn();$revision = Store::state( $db )->dps_revision;
$db->failInsert = 'eql_drop_entries';
// A new item forces an entry insert; failure must undo marker, source and contributor writes.
$failing = raw( $db, 200000, [ 'item' => 'Rollback item' ] );
$db->startAtomic( __METHOD__, $db::ATOMIC_CANCELABLE );
try { Store::accepted( $db, [ $failing ] );throw new \RuntimeException( 'Failure injection did not fire.' ); }
catch ( \Throwable $error ) { $db->cancelAtomic( __METHOD__ );check( $error->getMessage() === 'synthetic-transaction-failure', 'Projection errors propagate to the owning transaction.' ); }
$db->failInsert = null;
check( Store::state( $db )->dps_revision == $revision && $db->pdo->query( 'SELECT COUNT(*) FROM eql_drop_records' )->fetchColumn() == $seen, 'Failed projection rolls back its complete chunk.' );
$steps = 0;$maximumChunkMs = 0;$fenceMs = 0;
do { $at = microtime( true );$result = Store::backfill( $db, 1000 );$elapsed = ( microtime( true ) - $at ) * 1000;
	$maximumChunkMs = max( $maximumChunkMs, $elapsed );if ( $result['complete'] ) { $fenceMs = $elapsed; }
	$steps++;if ( $steps % 50 === 0 ) { echo "Synthetic backfill committed $steps chunks.\n"; } } while ( !$result['complete'] );
check( $steps > 100 && Store::state( $db )->dps_ready == 1, 'More than 100k legacy rows are fully projected without an entry lifetime cap.' );
$db->recordSelects = true;
$summary = $repository->getSummary( [ 'item' => 'Legacy blade' ] );
check( $summary['historyMode'] === 'cumulative' && !$summary['windowed'] && $summary['rows'][0]['reports'] === 100003, 'All-history total retains the earliest valid evidence beyond 100k.' );
check( $summary['rows'][0]['quantity'] === 100004 && $summary['rows'][0]['contributors'] === 2 && $summary['rows'][0]['totalReports'] === 100005,
	'Quantities, private distinct actors and singleton-inclusive source denominator are exact across servers/tiers.' );
check( $db->pdo->query( 'SELECT COUNT(*) FROM eql_eoz_observations' )->fetchColumn() == $rawProof + 1 && $db->pdo->query( 'SELECT eoo_payload,eoo_hash FROM eql_eoz_observations WHERE eoo_id=1' )->fetch( \PDO::FETCH_ASSOC ) === $rawFirst,
	'Backfill preserves raw legacy payload bytes and hashes.' );
// A different connection commits a missing LOWER raw ID after the scan cursor has passed it.
$extraRR = 0;
if ( $mysql ) {
	$db->pdo->beginTransaction();$db->pdo->query( 'SELECT COUNT(*) FROM eql_drop_records' )->fetchColumn();
	$other = new SQLFixture( $connect(), true );
	$other->startAtomic( __METHOD__, $other::ATOMIC_CANCELABLE );
	$newer = raw( $other, 200010 );Store::accepted( $other, [ $newer ] );$late = raw( $other, 2 );
	$other->endAtomic( __METHOD__ );$extraRR = 1;
} else { $late = raw( $db, 2 ); }
$reconcile = Store::backfill( $db, 100 );
check( $reconcile['processed'] === 1 && !$reconcile['complete'], 'Final current-read anti-join finds a late lower ID despite a stale RR snapshot.' );
if ( $mysql ) { $db->pdo->commit(); }
Store::backfill( $db, 100 );$afterLate = $repository->getSummary( [ 'item' => 'Legacy blade' ] );
check( $afterLate['rows'][0]['reports'] === 100004 + $extraRR, 'Late lower IDs cannot be skipped or erase another connection\'s newer committed counters under RR.' );
$beforeReplay = Store::state( $db )->dps_revision;
$db->startAtomic( __METHOD__, $db::ATOMIC_CANCELABLE );Store::accepted( $db, [ $late, $late ] );$db->endAtomic( __METHOD__ );
check( Store::state( $db )->dps_revision == $beforeReplay, 'Unique raw-row marker makes replay/backfill exact once.' );
$entry = $afterLate['rows'][0]['entryId'];$token = $afterLate['snapshot'];
$deepOffset = 100003 + $extraRR;$at = microtime( true );
$history = $repository->getDetail( $entry, $token, $deepOffset, 25 );$deepMs = ( microtime( true ) - $at ) * 1000;
check( count( $history['rows'] ) === 1 && $history['rows'][0]['characterName'] === 'Consented Character' && $history['rows'][0]['quantity'] === 2,
	'Bounded deep history page still reaches the oldest consented submission.' );
$new = raw( $db, 200001 );$db->startAtomic( __METHOD__, $db::ATOMIC_CANCELABLE );Store::accepted( $db, [ $new ] );$db->endAtomic( __METHOD__ );
check( $repository->getDetail( $entry, $token, $deepOffset, 25 ) === $history, 'New ingestion cannot change the count or membership of an open revision-bound history.' );
$rawReads = count( array_filter( $db->selects, static fn ( $select ) => in_array( 'eql_eoz_observations', $select['tables'], true ) ) );
$page = $repository->getSummary( [ 'q' => 'LEGACY', 'limit' => 1 ] );$at = microtime( true );$repository->getDetail( $entry, $page['snapshot'], 0, 25 );$firstMs = ( microtime( true ) - $at ) * 1000;
$pageQueries = static fn () => count( array_filter( $db->selects, static fn ( $select ) => is_array( $select['fields'] ) && in_array( $select['fields'][0], [ 'e.*','dpr_observed' ], true ) ) );
$beforeEmpty = $pageQueries();
check( $repository->getSummary( [ 'offset' => PHP_INT_MAX ] )['rows'] === [] && $pageQueries() === $beforeEmpty, 'Out-of-range summary offsets skip the SQL page scan.' );
check( $repository->getDetail( $entry, $page['snapshot'], PHP_INT_MAX, 25 )['rows'] === [] && $pageQueries() === $beforeEmpty, 'Out-of-range history offsets skip the SQL page scan.' );
check( count( array_filter( $db->selects, static fn ( $select ) => in_array( 'eql_eoz_observations', $select['tables'], true ) ) ) === $rawReads, 'Public cumulative requests never scan or parse raw history.' );
foreach ( $cache->values as $manifest ) { check( count( $manifest ) <= 500 && !str_contains( json_encode( $manifest ), 'eoo_' ), 'Snapshot cache holds only bounded displayed-entry revisions/counts.' ); }
$json = json_encode( $history );foreach ( [ 'eoo_', 'dpr_', 'actor', 'account', 'synthetic_event', 'sourceToken', 'sessionToken' ] as $private ) { check( !str_contains( $json, $private ), 'Detail privacy excludes ' . $private ); }
$single = 'drop_' . hash( 'sha256', json_encode( [ 'Legacy zone', 'A legacy guardian', 'Hidden singleton', 'normal' ] ) );
refuses( fn () => $repository->getDetail( $single, $page['snapshot'] ), 'eqldrops-detail-unavailable' );
$cache->fail = true;check( !$repository->getSummary()['detailsAvailable'], 'Cache write failure leaves cumulative counts available with history disabled.' );$cache->fail = false;$cache->values = [];
refuses( fn () => $repository->getDetail( $entry, $token ), 'eqldrops-detail-stale' );
// Real ingestion owns raw event, projection, receipt and counters in one transaction.
$ingestion = new \EQLClientData\ObservationRepository( new Provider( $db ), new \MediaWiki\User\ActorStore(), new Config() );
$batch = \EQLClientData\ObservationPayload::batch( json_encode( [ 'schemaVersion' => 1,'batchId' => 'synthetic_atomic_batch','clientVersion' => 'fixture','events' => [ event( 300000 ) ] ] ) );
$db->failInsert = 'eql_eoz_batches';
refuses( fn () => $ingestion->store( new User(), $batch ), 'synthetic-transaction-failure' );$db->failInsert = null;
check( !$db->selectRow( 'eql_eoz_observations', '*', [ 'eoo_key' => 'synthetic_event_300000' ], __METHOD__ ), 'Receipt failure also rolls back the new raw row.' );
$prior = $repository->getSummary( [ 'item' => 'Legacy blade' ] )['rows'][0]['reports'];
$receipt = $ingestion->store( new User(), $batch );check( count( $receipt['accepted'] ) === 1 && $repository->getSummary( [ 'item' => 'Legacy blade' ] )['rows'][0]['reports'] === $prior + 1, 'Successful ingestion updates all-history totals atomically.' );
$replay = $ingestion->store( new User(), $batch );check( count( $replay['duplicates'] ) === 1 && $repository->getSummary( [ 'item' => 'Legacy blade' ] )['rows'][0]['reports'] === $prior + 1, 'Batch retry preserves exact counters and receipt identity.' );
$wide = "\u{0390}" . str_repeat( "\u{1f642}", 229 );$normalizedWide = mb_strtoupper( mb_substr( $wide, 0, 1 ) ) . mb_substr( $wide, 1 );
check( mb_strlen( $wide ) === 230 && strlen( $normalizedWide ) === 922, 'Regression input is a valid230-codepoint title whose first-letter case expansion exceeds920bytes.' );
foreach ( [ 'zone', 'npc', 'item', 'all' ] as $index => $field ) {
	$changes = $field === 'all' ? [ 'zone' => $wide,'npc' => $wide,'item' => $wide ] : [ $field => $wide,$field . 'Title' => $wide ];
	$records = [ raw( $db, 400000 + $index * 2, $changes ),raw( $db, 400001 + $index * 2, $changes ) ];
	$db->startAtomic( __METHOD__, $db::ATOMIC_CANCELABLE );Store::accepted( $db, $records );$db->endAtomic( __METHOD__ );
	$filter = $field === 'all' ? [ 'zone' => $normalizedWide,'npc' => $normalizedWide,'item' => $normalizedWide ] : [ $field => $normalizedWide ];
	$row = $repository->getSummary( $filter )['rows'][0];
	check( $row['reports'] === 2 && strlen( $row[$field === 'all' ? 'zone' : $field] ) === 922, 'Normalized Unicode identity is preserved without truncation in ' . $field );
}
$beforeDeactivate = $db->pdo->query( 'SELECT SUM(den_reports) FROM eql_drop_entries' )->fetchColumn();
Store::deactivate( $db );check( Store::state( $db )->dps_ready == 0 && $db->pdo->query( 'SELECT SUM(den_reports) FROM eql_drop_entries' )->fetchColumn() == $beforeDeactivate,
	'Deactivation changes only readiness and preserves every count.' );
Store::backfill( $db );
require __DIR__ . '/backfill-drop-summaries.php';
$command = new \BackfillDropSummaries();$command->services = new class( $db ) {
	public function __construct( private SQLFixture $db ) {}public function getConnectionProvider() { return new Provider( $this->db ); }
	public function getMainConfig() { return new Config(); }
};
$receiptFile = sys_get_temp_dir() . '/eql-drop-derived-receipt-' . bin2hex( random_bytes( 8 ) ) . '.json';
$IP = dirname( __DIR__ );$command->options = [ 'expected-db' => 'eql_cumulative_fixture', 'expected-sha256' => hash_file( 'sha256', __DIR__ . '/../extensions/EQLClientData/sql/mysql/drop-summaries.sql' ) ];
$command->execute();check( $command->commits === 0, 'Maintenance defaults to a read-only review.' );
$command->options += [ 'apply' => true,'backup' => $receiptFile,'batch-size' => '100','batches' => '1','yield-ms' => '10' ];
try { $command->execute();check( $command->commits === 1 && is_file( $receiptFile ), 'Maintenance commits each complete chunk through the supported transaction-round API.' ); }
finally { if ( is_file( $receiptFile ) ) { unlink( $receiptFile ); } }
$command->options['deactivate'] = true;refuses( fn () => $command->execute(), 'Pause the receiver before deactivating cumulative reads.' );
echo 'Cumulative drops: ' . $checks . ' checks passed using ' . ( $mysql ? 'isolated MariaDB/InnoDB REPEATABLE READ' : 'SQLite SQL fixture' ) . ".\n";
echo 'Measured 1000-row maximum chunk: ' . round( $maximumChunkMs, 1 ) . 'ms; final completeness fence: ' . round( $fenceMs, 1 ) . "ms. Fixture timing is not production capacity evidence.\n";
echo 'DB version: ' . $db->pdo->getAttribute( PDO::ATTR_SERVER_VERSION ) . '; first history25: ' . round( $firstMs, 1 ) . 'ms; deep history@' . $deepOffset . ': ' . round( $deepMs, 1 ) . "ms.\n";
}
