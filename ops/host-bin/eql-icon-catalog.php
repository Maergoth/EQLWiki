<?php
/** Shared exact-pixel grouping for the CLI static cache builder. */
function eqlIconPixelHash( string $path ): string {
    $image = imagecreatefromstring( file_get_contents( $path ) );
    if ( !$image ) {
        throw new RuntimeException( 'Cannot decode icon: ' . $path );
    }
    imagepalettetotruecolor( $image );
    $bytes = pack( 'NN', imagesx( $image ), imagesy( $image ) );
    for ( $y = 0; $y < imagesy( $image ); $y++ ) {
        for ( $x = 0; $x < imagesx( $image ); $x++ ) {
            $color = imagecolorat( $image, $x, $y );
            // Invisible RGB is irrelevant; alpha and all visible pixels remain exact.
            $bytes .= pack( 'N', ( ( $color >> 24 ) & 127 ) === 127
                ? 0x7F000000 : $color );
        }
    }
    imagedestroy( $image );
    return hash( 'sha256', $bytes );
}

function eqlIconGroupRecords( array $records ): array {
    $groups = [];
    foreach ( $records as $record ) {
        $key = $record['pixelHash'];
        $alias = array_intersect_key( $record,
            array_flip( [ 'title', 'url', 'sha1', 'width', 'height' ] ) );
        if ( !isset( $groups[$key] ) ) {
            $record['aliases'] = [];
            $groups[$key] = $record;
        }
        $groups[$key]['aliases'][] = $alias;
    }
    return array_values( $groups );
}
