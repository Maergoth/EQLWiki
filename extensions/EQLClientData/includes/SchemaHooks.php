<?php
declare( strict_types = 1 );
namespace EQLClientData;

final class SchemaHooks {
    public static function onLoadExtensionSchemaUpdates( $updater ): void {
        if ( $updater->getDB()->getType() !== 'mysql' ) {
            // This optional feature must not break unrelated SQLite/Postgres
            // wiki upgrades. The API remains unavailable without its tables.
            return;
        }
        $updater->addExtensionTable( 'eql_eoz_state', __DIR__ . '/../sql/mysql/observations.sql' );
    }
}
