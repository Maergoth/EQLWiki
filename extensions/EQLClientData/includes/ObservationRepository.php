<?php
declare( strict_types = 1 );
namespace EQLClientData;

use MediaWiki\Config\Config;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserIdentity;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

/** Private append-only observations. Not wiki revisions or inferred loot rates. */
final class ObservationRepository {
    public const TABLES = [ 'eql_eoz_state', 'eql_eoz_batches', 'eql_eoz_observations' ];
    public function __construct(
        private readonly IConnectionProvider $dbProvider,
        private readonly ActorStore $actors,
        private readonly Config $config
    ) {}
    public function enabled(): bool { return (bool)$this->config->get( 'EQLClientDataEnableObservations' ); }
    public function available(): bool {
        if ( !$this->enabled() ) { return false; }
        $db = $this->dbProvider->getPrimaryDatabase();
        foreach ( self::TABLES as $table ) { if ( !$db->tableExists( $table, __METHOD__ ) ) { return false; } }
        return (bool)$db->selectField( 'eql_eoz_state', 'eos_id', [ 'eos_id' => 1 ], __METHOD__ );
    }
    public function limits(): array {
        return [ 'events' => ObservationPayload::MAX_EVENTS, 'bytes' => ObservationPayload::MAX_BYTES,
            'eventBytes' => ObservationPayload::MAX_EVENT_BYTES,
            'hourlyBatches' => max( 1, (int)$this->config->get( 'EQLClientDataObservationHourlyBatches' ) ) ];
    }
    private function quota( string $key ): int { return max( 1, (int)$this->config->get( $key ) ); }
    public function store( UserIdentity $user, array $batch ): array {
        if ( !$this->enabled() ) { throw new RuntimeException( 'eoz-disabled' ); }
        if ( !$this->available() ) { throw new RuntimeException( 'eoz-schema-unavailable' ); }
        $db = $this->dbProvider->getPrimaryDatabase();
        $db->startAtomic( __METHOD__, $db::ATOMIC_CANCELABLE );
        try {
            // One tiny global state row serializes quota checks and dedup races.
            $state = $db->newSelectQueryBuilder()->select( [ 'eos_events', 'eos_batches' ] )
                ->from( 'eql_eoz_state' )->where( [ 'eos_id' => 1 ] )->forUpdate()->caller( __METHOD__ )->fetchRow();
            if ( !$state ) { throw new RuntimeException( 'eoz-schema-unavailable' ); }
            $actor = $this->actors->acquireActorId( $user, $db );
            $prior = $db->selectRow( 'eql_eoz_batches', [ 'eob_hash', 'eob_receipt' ],
                [ 'eob_actor' => $actor, 'eob_key' => $batch['batchId'] ], __METHOD__ );
            if ( $prior ) {
                if ( !hash_equals( $prior->eob_hash, $batch['payloadHash'] ) ) { throw new RuntimeException( 'eoz-batch-conflict' ); }
                $receipt = json_decode( $prior->eob_receipt, true, 16, JSON_THROW_ON_ERROR );
                $receipt['duplicates'] = array_values( array_unique( array_merge( $receipt['accepted'], $receipt['duplicates'] ) ) );
                $receipt['accepted'] = [];
                // A wiki rename does not create a new actor/event identity.
                $receipt['account'] = [ 'id'=>$user->getId(), 'name'=>$user->getName() ];
                $db->endAtomic( __METHOD__ );return $receipt;
            }
            $since = gmdate( 'YmdHis', time() - 3600 );
            $hourly = (int)$db->selectField( 'eql_eoz_batches', 'COUNT(*)',
                [ 'eob_actor' => $actor, 'eob_received >= ' . $db->addQuotes( $since ) ], __METHOD__ );
            if ( $hourly >= $this->limits()['hourlyBatches'] ) { throw new RuntimeException( 'eoz-rate-limit' ); }
            $actorBatches = (int)$db->selectField( 'eql_eoz_batches', 'COUNT(*)', [ 'eob_actor' => $actor ], __METHOD__ );
            if ( (int)$state->eos_batches >= $this->quota( 'EQLClientDataObservationMaxBatches' ) ||
                $actorBatches >= $this->quota( 'EQLClientDataObservationActorMaxBatches' ) ) { throw new RuntimeException( 'eoz-capacity' ); }
            $actorEvents = (int)$db->selectField( 'eql_eoz_observations', 'COUNT(*)', [ 'eoo_actor' => $actor ], __METHOD__ );
            $ids = array_column( $batch['events'], 'id' ); $existing = [];
            if ( $ids ) {
                $rows = $db->select( 'eql_eoz_observations', [ 'eoo_key', 'eoo_hash' ],
                    [ 'eoo_actor' => $actor, 'eoo_key' => $ids ], __METHOD__ );
                foreach ( $rows as $row ) { $existing[$row->eoo_key] = $row->eoo_hash; }
            }
            $now = gmdate( 'YmdHis' ); $receipt = [ 'schemaVersion' => 1, 'batchId' => $batch['batchId'],
                'account' => [ 'id' => $user->getId(), 'name' => $user->getName() ],
                'accepted' => [], 'duplicates' => [], 'conflicts' => [], 'rejected' => $batch['rejected'],
                'receivedAt' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'limits' => $this->limits() ];
            $accepted = 0;
            foreach ( $batch['events'] as $event ) {
                $id = $event['id']; $hash = ObservationPayload::hash( $event );
                if ( isset( $existing[$id] ) ) {
                    $receipt[hash_equals( $existing[$id], $hash ) ? 'duplicates' : 'conflicts'][] = $id;continue;
                }
                if ( (int)$state->eos_events + $accepted >= $this->quota( 'EQLClientDataObservationMaxEvents' ) ||
                    $actorEvents + $accepted >= $this->quota( 'EQLClientDataObservationActorMaxEvents' ) ) {
                    $receipt['rejected'][] = [ 'id' => $id, 'code' => 'capacity' ];continue;
                }
                $db->insert( 'eql_eoz_observations', [ 'eoo_actor' => $actor, 'eoo_key' => $id,
                    'eoo_hash' => $hash, 'eoo_batch' => $batch['batchId'], 'eoo_kind' => $event['kind'],
                    'eoo_observed' => ( new \DateTimeImmutable( $event['observedAt'] ) )->format( 'YmdHis' ),
                    'eoo_received' => $now,
                    'eoo_payload' => json_encode( $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ], __METHOD__ );
                $receipt['accepted'][] = $id; $accepted++;
            }
            $db->insert( 'eql_eoz_batches', [ 'eob_actor' => $actor, 'eob_key' => $batch['batchId'],
                'eob_hash' => $batch['payloadHash'], 'eob_received' => $now,
                'eob_client' => $batch['clientVersion'], 'eob_receipt' => json_encode( $receipt, JSON_THROW_ON_ERROR ) ], __METHOD__ );
            $db->update( 'eql_eoz_state', [ 'eos_events' => (int)$state->eos_events + $accepted,
                'eos_batches' => (int)$state->eos_batches + 1 ], [ 'eos_id' => 1 ], __METHOD__ );
            $db->endAtomic( __METHOD__ );return $receipt;
        } catch ( \Throwable $error ) {
            $db->cancelAtomic( __METHOD__ );throw $error;
        }
    }
}
