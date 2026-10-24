<?php
/** Fixed optional extension schema installer; default is read-only. */
use MediaWiki\Maintenance\Maintenance;
use EQLClientData\ObservationRepository;
class EQLInstallObservations extends Maintenance {
    public function __construct() {
        parent::__construct();$this->addDescription( 'Review/install only the three EQLClientData observation tables. No core update or data reset.' );
        $this->addOption( 'expected-db', 'Exact reviewed database name.', true, true );
        $this->addOption( 'expected-sha256', 'Reviewed SHA256 of the fixed observations.sql.', true, true );
        $this->addOption( 'backup', 'Fresh private schema-state receipt; required with apply.', false, true );
        $this->addOption( 'apply', 'Explicitly create the three absent tables after review/backup.', false, false );
    }
    private function receipt( array $value ): void {
        global $IP;$requested=$this->getOption( 'backup', '' );$directory=realpath( dirname( $requested ) );
        if ( !$directory || !is_writable( $directory ) || in_array( basename( $requested ), [ '', '.', '..' ], true ) ) { $this->fatalError( 'Use a fresh file in an existing private backup directory.' ); }
        $file=$directory . DIRECTORY_SEPARATOR . basename( $requested );$normal=static fn( $x ) => str_replace( '\\', '/', DIRECTORY_SEPARATOR === '\\' ? strtolower( $x ) : $x );
        if ( str_starts_with( $normal( $file ), $normal( realpath( $IP ) ) . '/' ) && !str_starts_with( $normal( $file ), $normal( realpath( $IP ) ) . '/.local/' ) ) { $this->fatalError( 'Schema receipt must be outside the served root or in blocked .local.' ); }
        $json=json_encode( $value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n";$mask=umask( 0077 );try { $handle=@fopen( $file, 'xb' ); } finally { umask( $mask ); }
        if ( !$handle ) { $this->fatalError( 'Backup already exists or cannot be created.' ); }
        if ( DIRECTORY_SEPARATOR !== '\\' && !chmod( $file, 0600 ) ) { fclose( $handle );$this->fatalError( 'Could not secure schema receipt.' ); }
        $written=fwrite( $handle, $json );$flushed=fflush( $handle );fclose( $handle );
        if ( $written !== strlen( $json ) || !$flushed || file_get_contents( $file ) !== $json ) { $this->fatalError( 'Schema receipt verification failed.' ); }
    }
    public function execute() {
        $services=$this->getServiceContainer();$db=$services->getConnectionProvider()->getPrimaryDatabase();
        if ( $db->getType() !== 'mysql' || $db->getDBname() !== $this->getOption( 'expected-db' ) ) { $this->fatalError( 'Only the exact reviewed MySQL/MariaDB database is supported.' ); }
        if ( $services->getMainConfig()->get( 'EQLClientDataEnableObservations' ) ) { $this->fatalError( 'Disable observation collection before schema installation.' ); }
        $file=__DIR__ . '/../extensions/EQLClientData/sql/mysql/observations.sql';$sha=hash_file( 'sha256', $file );
        if ( !hash_equals( $sha, (string)$this->getOption( 'expected-sha256' ) ) ) { $this->fatalError( 'The fixed schema differs from its reviewed hash.' ); }
        $lock='eql-eoz-schema-' . substr( hash( 'sha256', $db->getDBname() ), 0, 32 );
        $locked=$db->query( 'SELECT GET_LOCK(' . $db->addQuotes( $lock ) . ',0) AS acquired', __METHOD__ )->fetchObject();
        if ( (int)( $locked->acquired ?? 0 ) !== 1 ) { $this->fatalError( 'Another observation schema operation owns the lock.' ); }
        try {
            $present=[];foreach ( ObservationRepository::TABLES as $table ) { $present[$table]=$db->tableExists( $table, __METHOD__ ); }
            $count=count( array_filter( $present ) );
            if ( $count && $count !== 3 ) { $this->fatalError( 'Partial schema exists. Inspect privately; this installer never alters or drops existing tables.' ); }
            if ( $count === 3 ) {
                foreach ( [ 'eql_eoz_state'=>[ 'eos_id','eos_events','eos_batches' ],'eql_eoz_batches'=>[ 'eob_actor','eob_key','eob_hash','eob_receipt' ],'eql_eoz_observations'=>[ 'eoo_actor','eoo_key','eoo_hash','eoo_payload' ] ] as $table=>$fields ) { foreach ( $fields as $field ) { if ( !$db->fieldExists( $table, $field, __METHOD__ ) ) { $this->fatalError( 'Existing schema differs. No changes made.' ); } } }
                if ( !$db->selectRow( 'eql_eoz_state', '*', [ 'eos_id'=>1 ], __METHOD__ ) ) { $this->fatalError( 'Schema state row missing. Never reset counters automatically.' ); }
                $this->output( "All three tables exist; no changes made. Review their indices/counters before enabling collection.\n" );return;
            }
            $this->output( 'All three tables absent. Fixed schema SHA256: ' . $sha . "\n" );
            if ( !$this->hasOption( 'apply' ) ) { $this->output( "Dry run only. No schema/data/configuration changed.\n" );return; }
            $this->receipt( [ 'savedAt'=>gmdate( DATE_ATOM ),'database'=>$db->getDBname(),'tables'=>$present,'schemaSha256'=>$sha,'schema'=>file_get_contents( $file ) ] );
            // DDL can partially commit on failure. Never retry over partial tables;
            // retain this receipt and inspect. No DROP/ALTER/core update occurs.
            if ( $db->sourceFile( $file ) !== true ) { $this->fatalError( 'Schema creation failed; inspect possible partial tables and retain the receipt.' ); }
            foreach ( ObservationRepository::TABLES as $table ) { if ( !$db->tableExists( $table, __METHOD__ ) ) { $this->fatalError( 'Schema verification failed. Keep collection disabled.' ); } }
            if ( !$db->selectRow( 'eql_eoz_state', '*', [ 'eos_id'=>1 ], __METHOD__ ) ) { $this->fatalError( 'Schema state verification failed. Keep collection disabled.' ); }
            $this->output( "Created only the three reviewed observation tables. Collection remains disabled; enabling requires separate reviewed configuration.\n" );
        } finally { $db->query( 'SELECT RELEASE_LOCK(' . $db->addQuotes( $lock ) . ')', __METHOD__ ); }
    }
}
return EQLInstallObservations::class;
