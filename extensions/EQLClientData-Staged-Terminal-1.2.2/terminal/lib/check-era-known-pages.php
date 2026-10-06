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
    fwrite( STDERR, "eqlmetadata returned an API error:\n" );
    fwrite( STDERR, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 1 );
}

$pages = $payload['eqlmetadata']['pages'] ?? null;
if ( !is_array( $pages ) ) {
    fwrite( STDERR, "eqlmetadata pages are missing.\n" );
    fwrite( STDERR, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 1 );
}

$byTitle = [];
foreach ( $pages as $page ) {
    if ( !is_array( $page ) ) {
        continue;
    }
    $title = (string)( $page['title'] ?? '' );
    if ( $title !== '' ) {
        $byTitle[$title] = $page;
    }
}

$expectations = [
    'Necklace_of_Superiority' => true,
    'Green_Silken_Drape' => false,
];

foreach ( $expectations as $title => $expected ) {
    if ( !isset( $byTitle[$title] ) ) {
        fwrite( STDERR, "Missing expected page in eqlmetadata response: $title\n" );
        fwrite( STDERR, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
        exit( 1 );
    }

    $actual = (bool)( $byTitle[$title]['outOfEra'] ?? false );
    if ( $actual !== $expected ) {
        fwrite(
            STDERR,
            sprintf(
                "Era classification mismatch for %s: expected %s, got %s\n",
                $title,
                $expected ? 'out-of-era' : 'in-era',
                $actual ? 'out-of-era' : 'in-era'
            )
        );
        fwrite( STDERR, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
        exit( 1 );
    }
}

echo "Known-page era classification OK: Necklace_of_Superiority=out, Green_Silken_Drape=in\n";
