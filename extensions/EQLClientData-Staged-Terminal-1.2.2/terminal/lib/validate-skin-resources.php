<?php

declare( strict_types = 1 );

if ( $argc < 3 ) {
    fwrite( STDERR, "Usage: php validate-skin-resources.php <skin.json> <live-skin-root>\n" );
    exit( 2 );
}

$skinJson = $argv[1];
$skinRoot = rtrim( $argv[2], '/' );

$data = json_decode(
    file_get_contents( $skinJson ),
    true,
    512,
    JSON_THROW_ON_ERROR
);

$missing = [];
foreach ( $data['ResourceModules'] ?? [] as $module ) {
    foreach ( [ 'scripts', 'styles' ] as $key ) {
        $values = $module[$key] ?? [];
        if ( is_array( $values ) && !array_is_list( $values ) ) {
            $values = array_keys( $values );
        }
        if ( !is_array( $values ) ) {
            continue;
        }
        foreach ( $values as $value ) {
            if ( !is_string( $value ) ) {
                continue;
            }
            if ( !is_file( $skinRoot . '/' . $value ) ) {
                $missing[$value] = true;
            }
        }
    }
}

if ( $missing ) {
    fwrite( STDERR, "Missing ResourceLoader files:\n" );
    foreach ( array_keys( $missing ) as $value ) {
        fwrite( STDERR, " - $value\n" );
    }
    exit( 1 );
}

echo "All staged ResourceLoader file paths exist.\n";
