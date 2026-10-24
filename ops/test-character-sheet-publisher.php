<?php
/** Source-only comparison checks; no settings, MediaWiki bootstrap or database. */
namespace MediaWiki\Maintenance {
    class Maintenance {}
}
namespace {
    require __DIR__ . '/sync-character-sheets.php';
    $normalize = new \ReflectionMethod( EQLSyncCharacterSheets::class, 'comparableSource' );
    $comparable = static fn ( string $source ): string => $normalize->invoke( null, $source );
    $checks = 0;
    $check = static function ( bool $condition, string $message ) use ( &$checks ): void {
        if ( !$condition ) { throw new \RuntimeException( $message ); }
        $checks++;
    };
    $reviewed = "<includeonly>\nReviewed source\n</includeonly>\n";
    $saved = "<includeonly>\nReviewed source\n</includeonly>";
    $check( $comparable( $reviewed ) === $comparable( $saved ), 'Normal pre-save terminal LF removal must remain current.' );
    $check( $comparable( str_replace( "\n", "\r\n", $reviewed ) ) === $comparable( $saved ), 'CRLF checkout normalization must remain current.' );
    $check( $comparable( $saved ) === $saved, 'Missing final LF remains unchanged.' );
    $check( $comparable( $reviewed . "\n" ) !== $comparable( $saved ), 'Do not silently trim arbitrary extra final blank lines.' );
    $check( $comparable( str_replace( 'Reviewed source', 'Reviewed source ', $reviewed ) ) !== $comparable( $saved ), 'Preserve trailing spaces as a meaningful source difference.' );
    $check( $comparable( str_replace( 'Reviewed source', "Reviewed source\t", $reviewed ) ) !== $comparable( $saved ), 'Preserve trailing tabs.' );
    $check( $comparable( str_replace( 'Reviewed source', 'Different source', $reviewed ) ) !== $comparable( $saved ), 'Never accept semantic source changes.' );
    $check( $comparable( str_replace( "\n", "\r", $reviewed ) ) !== $comparable( $saved ), 'Do not broaden normalization to unexpected bare CR content.' );
    $check( $comparable( "A\n\nB\n" ) === "A\n\nB", 'Internal blank lines remain intact.' );
    echo "Character sheet publisher: $checks source-only newline comparison checks passed; no database access.\n";
}
