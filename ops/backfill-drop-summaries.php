<?php
use EQLClientData\CumulativeDropStore;
require_once __DIR__ . '/DropSummaryMaintenance.php';

class BackfillDropSummaries extends DropSummaryMaintenance {
	public function __construct() {
		parent::__construct();$this->addDescription( 'Bounded resumable cumulative drop backfill. Default is read-only. Never modifies raw observations or receipts.' );
		$this->addOption( 'expected-db', 'Exact reviewed database name.', true, true );
		$this->addOption( 'expected-sha256', 'Reviewed SHA256 of drop-summaries.sql.', true, true );
		$this->addOption( 'backup', 'Fresh private projection-state recovery receipt; required with apply.', false, true );
		$this->addOption( 'apply', 'Explicitly project at most the selected number of bounded batches.', false, false );
		$this->addOption( 'deactivate', 'Rollback gate: disable cumulative readiness only; requires paused receiver and apply/backup.', false, false );
		$this->addOption( 'batches', 'Maximum chunks for this invocation (1-100; default 1).', false, true );
		$this->addOption( 'batch-size', 'Rows per transaction (1-1000; default 100).', false, true );
		$this->addOption( 'yield-ms', 'Pause outside each committed transaction (10-5000; default 100).', false, true );
	}
	public function execute() {
		$db = $this->database();$this->schema();$state = CumulativeDropStore::state( $db );
		if ( $state === null ) { $this->fatalError( 'Projection schema is not installed.' ); }
		$number = function ( $key, $default, $min, $max ) { $value = $this->getOption( $key, $default );if ( !ctype_digit( (string)$value ) || (int)$value < $min || (int)$value > $max ) { $this->fatalError( 'Invalid bounded maintenance option: ' . $key ); }return (int)$value; };
		$batches = $number( 'batches', 1, 1, 100 );$size = $number( 'batch-size', 100, 1, 1000 );$pause = $number( 'yield-ms', 100, 10, 5000 );
		$this->output( 'Projection state: ' . ( $state->dps_ready ? 'cumulative active' : 'backfill incomplete; recent view active' ) . ".\n" );
		if ( $this->hasOption( 'deactivate' ) && $this->getServiceContainer()->getMainConfig()->get( 'EQLClientDataEnableObservations' ) ) { $this->fatalError( 'Pause the receiver before deactivating cumulative reads.' ); }
		if ( !$this->hasOption( 'apply' ) ) { $this->output( "Dry run only; no observations, schema or projection counters changed.\n" );return; }
		$this->recoveryReceipt( [ 'database' => $db->getDBname(), 'projectionState' => (array)$state,
			'schemaSha256' => $this->getOption( 'expected-sha256' ), 'note' => 'Derived state receipt, not a raw database dump. Raw tables are never modified by this command.' ] );
		if ( $this->hasOption( 'deactivate' ) ) {
			CumulativeDropStore::deactivate( $db );$this->commitTransactionRound( __METHOD__ );
			$this->output( "Cumulative readiness disabled; existing counts and every observation/receipt retained.\n" );return;
		}
		for ( $batch = 0; $batch < $batches; $batch++ ) {
			$result = CumulativeDropStore::backfill( $db, $size );
			$this->commitTransactionRound( __METHOD__ );
			$this->output( 'Committed chunk: ' . $result['processed'] . ' classified loot records; ' . ( $result['complete'] ? 'cumulative view active' : 'backfill incomplete' ) . ".\n" );
			if ( $result['complete'] ) { break; }
			usleep( $pause * 1000 );
		}
	}
}
return BackfillDropSummaries::class;
