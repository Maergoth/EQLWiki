<?php
/** Verify every supplied source image survives exact-pixel cache grouping. */
require __DIR__ . '/host-bin/eql-icon-catalog.php';
if ( count( $argv ) !== 4 ) {
    throw new RuntimeException( 'Usage: php ops/verify-icon-coverage.php <library-directory> <index-json> <audit-manifest>' );
}
$audit = json_decode( file_get_contents( $argv[3] ), true, 512, JSON_THROW_ON_ERROR );
$existing = [];
foreach ( $audit as $row ) {
    if ( $row['status'] !== 'missing' ) { $existing[(int)$row['id']] = true; }
}
$payload = json_decode( file_get_contents( $argv[2] ), true, 512, JSON_THROW_ON_ERROR );
$covered = [];
foreach ( $payload['records'] as $record ) {
    foreach ( $record['aliases'] as $alias ) {
        if ( preg_match( '/^File:Item[ _]+([0-9]+)\.png$/D', $alias['title'], $match ) ) {
            $covered[(int)$match[1]][$record['pixelHash']] = true;
        }
    }
}
$count = 0;
foreach ( glob( $argv[1] . '/*.png' ) as $path ) {
    $id = (int)pathinfo( $path, PATHINFO_FILENAME );
    if ( !isset( $covered[$id] ) ||
        ( !isset( $existing[$id] ) && !isset( $covered[$id][eqlIconPixelHash( $path )] ) ) ) {
        throw new RuntimeException( 'Source icon missing from cache: ' . $id );
    }
    $count++;
}
echo "Verified all {$count} game IDs; new uploads match source pixels and existing artwork is retained.\n";
