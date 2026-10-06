<?php

declare( strict_types = 1 );

$input = stream_get_contents( STDIN );

try {
    $payload = json_decode( $input, true, 512, JSON_THROW_ON_ERROR );
} catch ( Throwable $e ) {
    fwrite( STDERR, "Invalid JSON from eqlmetadata: {$e->getMessage()}\n" );
    fwrite( STDERR, $input . "\n" );
    exit( 1 );
}

if ( isset( $payload['error'] ) ) {
    $error = $payload['error'];
    fwrite(
        STDERR,
        'eqlmetadata API error: ' .
        ( $error['code'] ?? 'unknown' ) .
        ' — ' .
        ( $error['info'] ?? 'No details' ) .
        PHP_EOL
    );
    fwrite( STDERR, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 1 );
}

$result = $payload['eqlmetadata'] ?? null;
if ( !is_array( $result ) ) {
    fwrite( STDERR, "Invalid eqlmetadata response.\n" );
    fwrite( STDERR, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 1 );
}

echo 'eqlmetadata OK; records: ' . count( $result['pages'] ?? [] ) .
    '; eraRevision: ' . ( $result['eraRevision'] ?? 'unknown' ) . PHP_EOL;
