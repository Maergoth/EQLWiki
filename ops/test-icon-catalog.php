<?php
require __DIR__ . '/host-bin/eql-icon-catalog.php';
$root = dirname( __DIR__ );
$a = eqlIconPixelHash( $root . '/eql_icons/3470.png' );
$b = eqlIconPixelHash( $root . '/eql_icons/3471.png' );
$different = eqlIconPixelHash( $root . '/eql_icons/500.png' );
if ( $a !== $b || $a === $different ) {
    throw new RuntimeException( 'Pixel identity check failed.' );
}
$records = [
    [ 'title' => 'File:Item 3470.png', 'url' => '/a', 'pixelHash' => $a ],
    [ 'title' => 'File:Item 3471.png', 'url' => '/b', 'pixelHash' => $b ],
    [ 'title' => 'File:Item 500.png', 'url' => '/c', 'pixelHash' => $different ],
];
$groups = eqlIconGroupRecords( $records );
if ( count( $groups ) !== 2 || count( $groups[0]['aliases'] ) !== 2 ||
    $groups[0]['aliases'][1]['title'] !== 'File:Item 3471.png' ) {
    throw new RuntimeException( 'Deduplication lost IDs or merged different pixels.' );
}
echo "Exact duplicate grouping preserves both IDs and keeps different pixels separate.\n";
