<?php

declare( strict_types = 1 );

$input = stream_get_contents( STDIN );

try {
    $payload = json_decode( $input, true, 512, JSON_THROW_ON_ERROR );
} catch ( Throwable $e ) {
    fwrite( STDERR, "Invalid JSON from siteinfo: {$e->getMessage()}\n" );
    exit( 1 );
}

$extensions = $payload['query']['extensions'] ?? [];
foreach ( $extensions as $extension ) {
    if ( ( $extension['name'] ?? '' ) === 'EQLClientData' ) {
        echo 'Loaded extension: EQLClientData ' . ( $extension['version'] ?? 'unknown' ) . PHP_EOL;
        exit( 0 );
    }
}

fwrite( STDERR, "EQLClientData is not listed by MediaWiki.\n" );
exit( 1 );
