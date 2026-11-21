<?php
use MediaWiki\Maintenance\Maintenance;

/** Shared guards for deliberately invoked projection maintenance, never web routes. */
abstract class DropSummaryMaintenance extends Maintenance {
	protected function database() {
		$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		if ( $db->getType() !== 'mysql' || $db->getDBname() !== $this->getOption( 'expected-db' ) ) { $this->fatalError( 'Use only the exact reviewed MySQL/MariaDB database.' ); }
		return $db;
	}
	protected function schema(): string {
		$file = __DIR__ . '/../extensions/EQLClientData/sql/mysql/drop-summaries.sql';
		if ( !hash_equals( hash_file( 'sha256', $file ), (string)$this->getOption( 'expected-sha256' ) ) ) { $this->fatalError( 'The projection schema differs from its reviewed SHA256.' ); }
		return $file;
	}
	protected function recoveryReceipt( array $value ): void {
		global $IP;
		$path = (string)$this->getOption( 'backup', '' );$directory = realpath( dirname( $path ) );
		if ( !$directory || !is_writable( $directory ) || in_array( basename( $path ), [ '', '.', '..' ], true ) ) { $this->fatalError( 'Use a fresh file in an existing private backup directory.' ); }
		$path = $directory . DIRECTORY_SEPARATOR . basename( $path );
		$normal = static fn ( $x ) => str_replace( '\\', '/', DIRECTORY_SEPARATOR === '\\' ? strtolower( $x ) : $x );
		$root = $normal( realpath( $IP ) );
		if ( str_starts_with( $normal( $path ), $root . '/' ) && !str_starts_with( $normal( $path ), $root . '/.local/' ) ) { $this->fatalError( 'Recovery receipt must be outside the served root or in blocked .local.' ); }
		$text = json_encode( [ 'savedAt' => gmdate( DATE_ATOM ) ] + $value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n";
		$mask = umask( 0077 );try { $handle = @fopen( $path, 'xb' ); } finally { umask( $mask ); }
		if ( !$handle ) { $this->fatalError( 'Recovery receipt already exists or cannot be created.' ); }
		$ok = DIRECTORY_SEPARATOR === '\\' || chmod( $path, 0600 );
		$ok = $ok && fwrite( $handle, $text ) === strlen( $text ) && fflush( $handle ) && fsync( $handle );fclose( $handle );
		if ( !$ok || file_get_contents( $path ) !== $text ) { $this->fatalError( 'Recovery receipt verification failed.' ); }
	}
}
