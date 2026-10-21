<?php
/**
 * Source-only Eye of Zomm export contract regression.
 *
 * Run: php ops/test-eoz-exporter.php
 * Evaluates only the producer's pure parsing/catalog methods against synthetic
 * records. Never boots MediaWiki, reads settings, connects to a database, runs
 * maintenance, writes a pack, or publishes the dataset.
 *
 * @license GPL-2.0-or-later
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( "This source-only test must run from the command line.\n" );
}

$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/maintenance/BuildEyeOfZommPack.php' );
if ( $source === false ) {
    throw new RuntimeException( 'Unable to read the exporter source.' );
}

$method = static function ( string $name, string $next ) use ( $source ): string {
    $pattern = '/    private function ' . preg_quote( $name, '/' ) . '\b[\s\S]*?(?=\n    private function ' . preg_quote( $next, '/' ) . '\b)/';
    if ( !preg_match( $pattern, $source, $match ) ) {
        throw new RuntimeException( "Missing tested exporter method: {$name}" );
    }
    return $match[0];
};
if ( !preg_match( '/    private const CLASS_CODES = \[[\s\S]*?\];/', $source, $classCodes ) ) {
    throw new RuntimeException( 'Missing exporter class-code allow list.' );
}
$catalogPath = var_export( $root . '/maintenance/zone-catalog.json', true );
$methods = [
    str_replace( "__DIR__ . '/zone-catalog.json'", $catalogPath, $method( 'loadZoneCatalog', 'listPagesInCategory' ) ),
    $method( 'detectZonesFromCategories', 'detectZonesFromField' ),
    $method( 'detectZonesFromField', 'detectZonesFromTitle' ),
    $method( 'detectZonesFromTitle', 'detectEra' ),
    $method( 'parseClasses', 'parseSlots' ),
    $method( 'parseLocation', 'extractBalancedTemplate' ),
    $method( 'cleanWikiText', 'normalizeZone' ),
    $method( 'normalizeZone', 'intIfWhole' ),
    $method( 'intIfWhole', 'maybePause' ),
];

// Execute the actual selected methods without loading the maintenance runner.
eval( 'class EozExporterFixture {
    private array $zoneCatalog = [];
    private array $zoneAliasMap = [];
    ' . $classCodes[0] . implode( "\n", $methods ) . '
    public function fatalError( string $message ) { throw new RuntimeException( $message ); }
    public function classes( string $stats ): array { return $this->parseClasses( $stats ); }
    public function location( string $text ): ?array { return $this->parseLocation( $text ); }
    public function zone( string $alias ): array {
        $this->loadZoneCatalog();
        return [
            $this->detectZonesFromCategories( [ $alias ] ),
            $this->detectZonesFromField( "[[" . $alias . "]]" ),
            $this->detectZonesFromTitle( "Synthetic sentinel (" . $alias . ")" ),
        ];
    }
    public function zoneField( string $text ): array { return $this->detectZonesFromField( $text ); }
}' );

$checks = 0;
$expect = static function ( $actual, $expected, string $name ) use ( &$checks ): void {
    $checks++;
    if ( $actual !== $expected ) {
        throw new RuntimeException( $name . ': expected ' . json_encode( $expected ) . ', got ' . json_encode( $actual ) );
    }
};
$fixture = new EozExporterFixture();

foreach ( [
    [ 'Class: WAR CLR', [ 'WAR', 'CLR' ] ],
    [ 'Class: ALL', [ 'ALL' ] ],
    [ 'Class: ALL except NEC WIZ MAG ENC', [ 'WAR', 'CLR', 'PAL', 'RNG', 'SHD', 'DRU', 'MNK', 'BRD', 'ROG', 'SHM', 'BST', 'BER' ] ],
    [ 'Class: ALL except [[Wizard|WIZ]]<br>Race: ALL', [ 'WAR', 'CLR', 'PAL', 'RNG', 'SHD', 'DRU', 'MNK', 'BRD', 'ROG', 'SHM', 'NEC', 'MAG', 'ENC', 'BST', 'BER' ] ],
    [ 'Class: WAR WAR invalid CLR', [ 'WAR', 'CLR' ] ],
    [ 'Class: all except mnk XYZ', [ 'WAR', 'CLR', 'PAL', 'RNG', 'SHD', 'DRU', 'BRD', 'ROG', 'SHM', 'NEC', 'WIZ', 'MAG', 'ENC', 'BST', 'BER' ] ],
    [ 'Class: None', [ 'NONE' ] ],
    [ 'Class: ALL except WAR CLR PAL RNG SHD DRU MNK BRD ROG SHM NEC WIZ MAG ENC BST BER', [ 'NONE' ] ],
    [ 'Class: XYZ', [] ],
    [ 'No class field', [] ],
] as [ $stats, $expected ] ) {
    $expect( $fixture->classes( $stats ), $expected, "Class parsing {$stats}" );
}

foreach ( [
    [ 'Kael Drakkel', 'Kael Drakkal' ],
    [ 'Neriak Foreign Quarter', 'Neriak - Foreign Quarter' ],
    [ 'Neriak Commons', 'Neriak - Commons' ],
    [ 'Neriak Third Gate', 'Neriak - Third Gate' ],
    [ 'Northern Karana', 'North Karana' ],
    [ 'Northern Plains of Karana', 'North Karana' ],
    [ 'Southern Karana', 'South Karana' ],
    [ 'Southern Plains of Karana', 'South Karana' ],
    [ 'Burning Woods', 'Burning Wood' ],
    [ 'Crypt of Dalnir', 'Dalnir' ],
    [ "Beholder's Maze", 'Gorge of King Xorbb' ],
    [ 'Clan Runnyeye', 'Runnyeye Citadel' ],
    [ 'Kerra Isle', 'Kerra Island' ],
    [ 'Qeynos Catacombs', 'Qeynos Aqueducts' ],
    [ 'Southern Ro', 'South Ro' ],
    [ 'North Felwithe', 'Northern Felwithe' ],
    [ 'The Lair of the Splitpaw', 'Splitpaw Lair' ],
    [ 'Infected Paw', 'Splitpaw Lair' ],
] as [ $alias, $canonical ] ) {
    $expect( $fixture->zone( $alias ), [ [ $canonical ], [ $canonical ], [ $canonical ] ], "Zone alias {$alias}" );
}
$expect( $fixture->zoneField( '[[Neriak|Neriak Commons]], [[Neriak|Neriak Third Gate]]' ), [ 'Neriak - Commons', 'Neriak - Third Gate' ], 'Piped multiple zones' );
$expect( $fixture->zoneField( 'Various' ), [], 'Unknown zone remains unmapped' );
$expect( $fixture->zone( 'Unmapped place' ), [ [], [], [] ], 'No fuzzy zone guessing' );
$expect( $fixture->location( '{{loc|-73|41|-20}}' ), [ -73, 41, -20 ], 'Wiki location preserves displayed Y/X/Z' );
$expect( $fixture->location( '(15, -27), (42, 63)' ), [ 15, -27 ], 'A second spawn tuple is not elevation' );

echo "Eye of Zomm producer contracts passed: {$checks} synthetic checks.\n";
