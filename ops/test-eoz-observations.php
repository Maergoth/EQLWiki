<?php
/** Pure payload + transactional repository fixture. No wiki/DB/profile writes. */
declare( strict_types = 1 );
namespace Wikimedia\Rdbms { interface IConnectionProvider {} }
namespace MediaWiki\User {
    interface UserIdentity { public function getId(); public function getName(); }
    class ActorStore { public function acquireActorId( $user, $db ): int { return $user->getId() + 100; } }
}
namespace MediaWiki\Config { abstract class Config { abstract public function get( $key ); } }
namespace Wikimedia\ParamValidator { class ParamValidator { public const PARAM_TYPE='type';public const PARAM_DEFAULT='default'; } }
namespace MediaWiki\Api {
    class ApiMain { public array $data=[];public function setCacheMode( $mode ) { \check( $mode==='private', 'Authenticated capability has private cache mode.' ); } }
    class ApiBase {
        public array $params=[];public $user;public bool $right=true;
        public function __construct( protected ApiMain $main, protected string $action ) {}
        public function extractRequestParams() { return $this->params; }public function getUser() { return $this->user; }
        public function checkUserRightsAny( $right ) { \check( $right==='eoz-submit-observations', 'Use a dedicated ordinary account right.' );if ( !$this->right ) { throw new \RuntimeException( 'permissiondenied' ); } }
        public function dieWithError( $message, $code ) { throw new \RuntimeException( $code ); }
        public function getMain() { return $this->main; }public function getModuleName() { return $this->action; }
        public function getResult() { return new class( $this->main ) { public function __construct( private ApiMain $main ) {}public function addValue( $path, $name, $value ) { $this->main->data[$name]=$value; } }; }
    }
}
namespace {
use EQLClientData\ObservationPayload as Payload;
use EQLClientData\ObservationRepository as Repository;
require __DIR__ . '/../extensions/EQLClientData/includes/ObservationPayload.php';
require __DIR__ . '/../extensions/EQLClientData/includes/ObservationRepository.php';
require __DIR__ . '/../extensions/EQLClientData/includes/ApiObservations.php';
$checks = 0;
function check( bool $test, string $label ): void { global $checks; if ( !$test ) { throw new \RuntimeException( $label ); } $checks++; }
function rejects( callable $fn, string $code ): void { try { $fn(); } catch ( \Throwable $error ) { check( $error->getMessage() === $code, "Expected $code; got {$error->getMessage()}" );return; } throw new \RuntimeException( "Expected rejection $code" ); }
class FixtureConfig extends \MediaWiki\Config\Config {
    public array $values = [ 'EQLClientDataEnableObservations' => true, 'EQLClientDataObservationHourlyBatches' => 12,
        'EQLClientDataObservationRequireConfirmedEmail' => true,
        'EQLClientDataObservationMaxEvents' => 100000, 'EQLClientDataObservationActorMaxEvents' => 10000,
        'EQLClientDataObservationMaxBatches' => 20000, 'EQLClientDataObservationActorMaxBatches' => 2000 ];
    public function get( $key ) { return $this->values[$key]; }
}
class FixtureDB {
    public const ATOMIC_CANCELABLE='cancelable';
    public array $tables = [ 'eql_eoz_state' => [ [ 'eos_id' => 1, 'eos_events' => 0, 'eos_batches' => 0 ] ], 'eql_eoz_batches' => [], 'eql_eoz_observations' => [] ];
    public array $saved = [];public int $writes = 0;public bool $locked = false;public bool $fail = false;
    public function tableExists( $table, $caller ) { return isset( $this->tables[$table] ); }
    public function startAtomic( $caller,$cancelable=null ) { check( $cancelable===self::ATOMIC_CANCELABLE,'Expected API refusals must use a cancelable atomic section.' );$this->saved = $this->tables; }
    public function endAtomic( $caller ) { check( $this->locked, 'Quota and duplicate processing must lock the common state row.' );$this->locked = false; }
    public function cancelAtomic( $caller ) { $this->tables = $this->saved;$this->locked = false; }
    public function newSelectQueryBuilder() { return new class( $this ) {
        public function __construct( private FixtureDB $db ) {} public function select( $x ) { return $this; }
        public function from( $x ) { check( $x === 'eql_eoz_state', 'Lock only own state.' );return $this; }
        public function where( $x ) { return $this; } public function forUpdate() { $this->db->locked = true;return $this; }
        public function caller( $x ) { return $this; } public function fetchRow() { return (object)$this->db->tables['eql_eoz_state'][0]; }
    }; }
    public function addQuotes( $x ) { return "'" . $x . "'"; }
    public function select( $table, $fields, $conditions, $caller ) {
        return array_map( static fn( $row ) => (object)$row, array_values( array_filter( $this->tables[$table], static function ( $row ) use ( $conditions ) {
            foreach ( $conditions as $key => $value ) {
                if ( is_int( $key ) ) { if ( preg_match( "/^(\\w+) >= '([0-9]+)'$/", $value, $m ) && $row[$m[1]] < $m[2] ) { return false; } }
                elseif ( is_array( $value ) ? !in_array( $row[$key], $value, true ) : $row[$key] !== $value ) { return false; }
            }return true;
        } ) ) );
    }
    public function selectRow( $table, $fields, $conditions, $caller ) { return $this->select( $table, $fields, $conditions, $caller )[0] ?? false; }
    public function selectField( $table, $field, $conditions, $caller ) { $rows = $this->select( $table, [], $conditions, $caller );return $field === 'COUNT(*)' ? count( $rows ) : ( $rows[0]->$field ?? false ); }
    public function insert( $table, $row, $caller ) { $this->writes++;if ( $this->fail ) { throw new \RuntimeException( 'fixture-failure' ); }$this->tables[$table][] = $row; }
    public function update( $table, $row, $conditions, $caller ) { check( $table === 'eql_eoz_state', 'Only quota state is mutable.' );$this->tables[$table][0] = [ 'eos_id' => 1 ] + $row;$this->writes++; }
}
class FixtureProvider implements \Wikimedia\Rdbms\IConnectionProvider { public function __construct( public FixtureDB $db ) {} public function getPrimaryDatabase() { return $this->db; } }
class FixtureUser implements \MediaWiki\User\UserIdentity { public function __construct( private int $id = 7, private ?string $name=null ) {} public function getId() { return $this->id; } public function getName() { return $this->name ?? 'Fixture ' . $this->id; } }
$event = [ 'id' => 'event_00001', 'kind' => 'loot', 'observedAt' => '2026-10-24T10:00:00Z', 'item' => "Guardian's Relic", 'quantity' => 1, 'sourceKind' => 'unknown' ];
$now = strtotime( '2026-10-24T11:00:00Z' );
$normalized = Payload::event( $event, $now );check( $normalized['observedAt'] === '2026-10-24T10:00:00.000Z', 'Normalize UTC milliseconds.' );
check( Payload::hash( $normalized ) === Payload::hash( array_reverse( $normalized, true ) ), 'Object order cannot alter an event hash.' );
foreach ( [ [ 'observedAt', '2026-99-99T99:99:99Z', 'invalid-time' ], [ 'observedAt', '2026-02-30T10:00:00Z', 'invalid-time' ], [ 'observedAt', '2026-10-24T11:05:01Z', 'invalid-time' ], [ 'id', 'bad', 'invalid-id' ], [ 'quantity', 0, 'invalid-quantity' ], [ 'tier', 11, 'invalid-tier' ], [ 'sourceKind', 'provider', 'invalid-provenance' ], [ 'itemTitle', 'User:Someone', 'invalid-title' ], [ 'item', "Bad\nname", 'invalid-text' ], [ 'sourceToken', '/private/log.txt', 'invalid-token' ] ] as [ $key, $value, $code ] ) { rejects( fn() => Payload::event( array_replace( $event, [ $key => $value ] ), $now ), $code ); }
rejects( fn() => Payload::event( $event + [ 'playerName' => 'Private' ], $now ), 'unsupported-field' );
check( Payload::event( array_replace( $event, [ 'observedAt' => '2020-01-01T00:00:00Z' ] ), $now )['item'] === $event['item'], 'Offline observations have no past expiry.' );
foreach ( [ 'explicit-corpse', 'explicit-source', 'recent-kill', 'encounter-target' ] as $kind ) { rejects( fn() => Payload::event( array_replace( $event, [ 'sourceKind' => $kind ] ), $now ), 'invalid-subject' );check( Payload::event( array_replace( $event, [ 'sourceKind' => $kind, 'npc' => 'a guardian' ] ), $now )['sourceKind'] === $kind, 'Keep explicit provenance separate.' ); }
$batch = static fn( $events, $id = 'batch_00001' ) => Payload::batch( json_encode( [ 'schemaVersion' => 1, 'batchId' => $id, 'clientVersion' => 'fixture', 'events' => $events ] ), $now );
$mixed = $batch( [ $event, array_replace( $event, [ 'id' => 'event_00002', 'observedAt' => '2026-99-99T00:00:00Z' ] ) ] );check( count( $mixed['events'] ) === 1 && $mixed['rejected'] === [ [ 'id' => 'event_00002', 'code' => 'invalid-time' ] ], 'One invalid event cannot discard valid peers.' );
rejects( fn() => $batch( [ $event, $event ] ), 'duplicate-batch-id' );rejects( fn() => $batch( array_fill( 0, 101, $event ) ), 'invalid-batch' );
$config = new FixtureConfig();$db = new FixtureDB();$repository = new Repository( new FixtureProvider( $db ), new \MediaWiki\User\ActorStore(), $config );$user = new FixtureUser();
$config->values['EQLClientDataEnableObservations'] = false;check( !$repository->available() && $db->writes === 0, 'Disabled endpoint touches no schema/data.' );rejects( fn() => $repository->store( $user, $mixed ), 'eoz-disabled' );$config->values['EQLClientDataEnableObservations'] = true;
$receipt = $repository->store( $user, $mixed );check( $receipt['accepted'] === [ 'event_00001' ] && $receipt['account'] === [ 'id' => 7, 'name' => 'Fixture 7' ], 'Server-derived attribution and per-event acceptance.' );
$before = $db->writes;$retry = $repository->store( $user, $mixed );check( $retry['accepted'] === [] && $retry['duplicates'] === [ 'event_00001' ] && $db->writes === $before, 'Exact batch replay adds no data.' );
$renamed=$repository->store( new FixtureUser( 7, 'Renamed Fixture' ), $mixed );check( $renamed['duplicates']===[ 'event_00001' ] && $renamed['account']['name']==='Renamed Fixture' && $db->writes===$before, 'Renamed account replay keeps actor identity and returns current canonical name.' );
rejects( fn() => $repository->store( $user, $batch( [ array_replace( $event, [ 'quantity' => 2 ] ) ] ) ), 'eoz-batch-conflict' );
$conflict = $repository->store( $user, $batch( [ array_replace( $event, [ 'quantity' => 2 ] ) ], 'batch_00002' ) );check( $conflict['conflicts'] === [ 'event_00001' ] && $db->tables['eql_eoz_state'][0]['eos_events'] === 1, 'Changed same-ID payload never overwrites prior facts.' );
$other = $repository->store( new FixtureUser( 8 ), $batch( [ $event ], 'batch_00003' ) );check( $other['accepted'] === [ 'event_00001' ], 'Different actors retain independent attributed reports.' );
$config->values['EQLClientDataObservationMaxEvents'] = 2;$full = $repository->store( $user, $batch( [ array_replace( $event, [ 'id' => 'event_00003' ] ) ], 'batch_00004' ) );check( $full['rejected'] === [ [ 'id' => 'event_00003', 'code' => 'capacity' ] ], 'Capacity is explicit, no eviction or silent acknowledgement.' );
$config->values['EQLClientDataObservationHourlyBatches'] = 1;rejects( fn() => $repository->store( $user, $batch( [ $event ], 'batch_00005' ) ), 'eoz-rate-limit' );
$config->values['EQLClientDataObservationHourlyBatches'] = 12;$config->values['EQLClientDataObservationMaxEvents'] = 100;$before = $db->tables;$db->fail = true;rejects( fn() => $repository->store( $user, $batch( [ array_replace( $event, [ 'id' => 'event_00006' ] ) ], 'batch_00006' ) ), 'fixture-failure' );check( $db->tables === $before, 'Failed transaction leaves neither partial events nor receipt/counters.' );
echo "Observation payload/repository fixture: $checks checks passed; no real database writes.\n";
$apiUser=new class implements \MediaWiki\User\UserIdentity {
    public bool $named=true;public bool $email=true;public bool $blocked=false;
    public function isNamed() { return $this->named; }public function isEmailConfirmed() { return $this->email; }public function getBlock() { return $this->blocked; }
    public function getId() { return 42; }public function getName() { return 'Fixture API'; }
};
$main=new \MediaWiki\Api\ApiMain();$api=new \EQLClientData\ApiObservations( $main, 'eozobservations', $repository, $config );$api->user=$apiUser;$api->params=[ 'operation'=>'status','batch'=>'' ];
check( $api->mustBePosted() && $api->isWriteMode() && $api->needsToken()==='csrf', 'Real ApiBase dispatch must enforce POST and CSRF before execution.' );
$apiUser->named=false;rejects( fn()=>$api->execute(), 'eoz-login' );$apiUser->named=true;
$api->right=false;rejects( fn()=>$api->execute(), 'permissiondenied' );$api->right=true;
$apiUser->email=false;rejects( fn()=>$api->execute(), 'eoz-email' );$apiUser->email=true;
$apiUser->blocked=true;rejects( fn()=>$api->execute(), 'eoz-blocked' );$apiUser->blocked=false;
$config->values['EQLClientDataEnableObservations']=false;$before=$db->writes;$api->execute();check( $main->data['eozobservations']['enabled']===false && !$main->data['eozobservations']['available'] && $db->writes===$before, 'Disabled authenticated capability writes nothing.' );
$api->params['operation']='submit';rejects( fn()=>$api->execute(), 'eoz-disabled' );
echo "Observation API authorization fixture: $checks total checks passed; core HTTP/token dispatcher is declared, not simulated as a real login.\n";
}
