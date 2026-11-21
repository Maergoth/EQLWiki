<?php
use EQLClientData\CumulativeDropStore;
require_once __DIR__ . '/DropSummaryMaintenance.php';

class InstallDropSummaries extends DropSummaryMaintenance {
	public function __construct() {
		parent::__construct();$this->addDescription( 'Review/install only five absent cumulative drop projection tables. Does not alter raw observations, receipts, settings or core.' );
		$this->addOption( 'expected-db', 'Exact reviewed database name.', true, true );
		$this->addOption( 'expected-sha256', 'Reviewed SHA256 of drop-summaries.sql.', true, true );
		$this->addOption( 'backup', 'Fresh private recovery receipt; required with apply.', false, true );
		$this->addOption( 'apply', 'Explicitly create only the five absent tables.', false, false );
	}
	public function execute() {
		$db = $this->database();$file = $this->schema();
		if ( $this->getServiceContainer()->getMainConfig()->get( 'EQLClientDataEnableObservations' ) ) { $this->fatalError( 'Disable the receiver and drain old requests before installing projection tables.' ); }
		$lock = 'eql-drop-schema-' . substr( hash( 'sha256', $db->getDBname() ), 0, 32 );
		if ( (int)$db->query( 'SELECT GET_LOCK(' . $db->addQuotes( $lock ) . ',0) AS acquired', __METHOD__ )->fetchObject()->acquired !== 1 ) { $this->fatalError( 'Another projection schema operation owns the lock.' ); }
		try {
			$present = [];foreach ( CumulativeDropStore::TABLES as $table ) { $present[$table] = $db->tableExists( $table, __METHOD__ ); }
			$count = count( array_filter( $present ) );
			if ( $count && $count !== count( $present ) ) { $this->fatalError( 'Partial projection schema exists. Inspect privately; this tool never alters or drops tables.' ); }
			if ( $count ) { CumulativeDropStore::state( $db );$this->output( "All five tables exist; no schema or counters changed.\n" );return; }
			$this->output( "All five projection tables are absent. Raw observations and receipts will remain unchanged.\n" );
			if ( !$this->hasOption( 'apply' ) ) { $this->output( "Dry run only.\n" );return; }
			$this->recoveryReceipt( [ 'database' => $db->getDBname(), 'tables' => $present,
				'schemaSha256' => hash_file( 'sha256', $file ), 'schema' => file_get_contents( $file ) ] );
			if ( $db->sourceFile( $file ) !== true || CumulativeDropStore::state( $db ) === null ) { $this->fatalError( 'Schema creation failed. Inspect possible partial DDL; keep the receiver disabled.' ); }
			$this->output( "Created five empty projection tables. Public reads still use the recent view until backfill completes. Receiver activation is a separate owner-reviewed step.\n" );
		} finally { $db->query( 'SELECT RELEASE_LOCK(' . $db->addQuotes( $lock ) . ')', __METHOD__ ); }
	}
}
return InstallDropSummaries::class;
