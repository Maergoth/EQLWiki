<?php
/**
 * Source-only Eye of Zomm export contract regression.
 *
 * Run: php ops/test-eoz-exporter.php
 * Evaluates actual NPC-record and pure parsing/catalog methods with synthetic
 * page/title stubs. Never boots MediaWiki, reads settings, connects to a database, runs
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
    $method( 'buildNpcRecords', 'buildItemRecord' ),
    $method( 'detectZonesFromCategories', 'detectZonesFromField' ),
    $method( 'detectZonesFromField', 'detectZonesFromTitle' ),
    $method( 'detectZonesFromTitle', 'detectEra' ),
    $method( 'detectEra', 'parseClasses' ),
    $method( 'parseClasses', 'parseSlots' ),
    $method( 'parseLevelRange', 'parseLocation' ),
    $method( 'parseLocation', 'extractBalancedTemplate' ),
    $method( 'extractBalancedTemplate', 'stripTemplateWrapper' ),
    $method( 'stripTemplateWrapper', 'extractParamByLine' ),
    $method( 'extractParamByLine', 'cleanWikiText' ),
    $method( 'cleanWikiText', 'normalizeZone' ),
    $method( 'normalizeZone', 'intIfWhole' ),
    $method( 'intIfWhole', 'maybePause' ),
];

// Only the title/page API surface used by buildNpcRecords is supplied. These
// stubs contain no real wiki content and cannot load MediaWiki or a database.
class Title {
    private string $text;
    public function __construct( string $text ) { $this->text = str_replace( '_', ' ', $text ); }
    public static function makeTitle( $namespace, string $text ): self { return new self( $text ); }
    public function getText(): string { return $this->text; }
}

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
    public function npc( string $title, ?string $zoneField, array $categories, string $templateName ): array {
        $this->loadZoneCatalog();
        $zoneLine = $zoneField === null ? "" : "\n| zone = " . $zoneField;
        $text = "{{" . $templateName . $zoneLine . "\n| level = 10-12\n| race = Synthetic race\n| class = Synthetic class\n| description = [[Synthetic page|Synthetic description]]\n| location = {{loc|15|-27|-5}}\n}}";
        $page = new class( $text ) {
            private string $text;
            public function __construct( string $text ) { $this->text = $text; }
            public function getContent() {
                return new class( $this->text ) {
                    private string $text;
                    public function __construct( string $text ) { $this->text = $text; }
                    public function getText(): string { return $this->text; }
                };
            }
            public function getRevisionRecord() {
                return new class { public function getId(): int { return 777; } };
            }
        };
        $factory = new class( $page ) {
            private $page;
            public function __construct( $page ) { $this->page = $page; }
            public function newFromTitle( $title ) { return $this->page; }
        };
        return $this->buildNpcRecords( $factory, (object)[
            "page_namespace" => 0,
            "page_title" => str_replace( " ", "_", $title ),
        ], $categories );
    }
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

// Zone provenance is tested through the actual NPC export method, not merely
// alias resolution: stale categories must not replace explicit spawn fields,
// nor discard one member of a multi-zone field. Every row remains synthetic.
foreach ( [
    [ 'Synthetic merchant', 'East Commonlands', [ 'West Commonlands' ], [ 'East Commonlands' ], 'MerchantPage' ],
    [ 'Synthetic travelling guard', '[[West Karana]], [[Qeynos Hills]]', [ 'West Karana' ], [ 'West Karana', 'Qeynos Hills' ], 'Namedmobpage' ],
    [ 'Synthetic cave pet', '[[Clan Runnyeye]]', [ 'Gorge of King Xorbb' ], [ 'Runnyeye Citadel' ], 'NPCpage' ],
    [ 'Synthetic merchant in two zones', '[[East Commonlands]] or [[West Commonlands]]', [ 'Qeynos Hills' ], [ 'East Commonlands', 'West Commonlands' ], 'MerchantPage' ],
    [ 'Synthetic category fallback', null, [ 'Northern Karana' ], [ 'North Karana' ], 'Namedmobpage' ],
    [ 'Synthetic empty field', '', [ 'Burning Woods' ], [ 'Burning Wood' ], 'MerchantPage' ],
    [ 'Synthetic unmapped field', 'An unmapped place', [ 'Clan Runnyeye' ], [ 'Runnyeye Citadel' ], 'Namedmobpage' ],
    [ 'Synthetic explicit field (Qeynos Hills)', 'East Commonlands', [ 'West Commonlands' ], [ 'East Commonlands' ], 'Namedmobpage' ],
    [ 'Synthetic category before title (East Commonlands)', 'Various', [ 'Qeynos Hills' ], [ 'Qeynos Hills' ], 'NPCpage' ],
    [ 'Synthetic title fallback (Northern Karana)', null, [ 'Unmapped place' ], [ 'North Karana' ], 'Namedmobpage' ],
    [ 'Synthetic title after unknown field (Clan Runnyeye)', 'Various', [], [ 'Runnyeye Citadel' ], 'MerchantPage' ],
    [ 'Synthetic unknown guardian (Unmapped place)', 'Various', [ 'Unmapped place' ], [ '' ], 'Namedmobpage' ],
    [ 'Synthetic partially mapped field', 'Various or [[Qeynos Hills]]', [ 'West Karana' ], [ 'Qeynos Hills' ], 'Namedmobpage' ],
    [ 'Synthetic deduplicated aliases', '[[Clan Runnyeye]], [[Runnyeye Citadel]]', [ 'Qeynos Hills' ], [ 'Runnyeye Citadel' ], 'Namedmobpage' ],
    [ 'Synthetic piped spawn field', '[[Neriak|Neriak Commons]], [[Neriak|Neriak Third Gate]]', [ 'Qeynos Hills' ], [ 'Neriak - Commons', 'Neriak - Third Gate' ], 'MerchantPage' ],
] as [ $title, $zoneField, $categories, $expectedZones, $templateName ] ) {
    $records = $fixture->npc( $title, $zoneField, array_merge( $categories, [ 'Kunark' ] ), $templateName );
    $expect( array_column( $records, 'zone' ), $expectedZones, "NPC zone provenance {$title}" );
    foreach ( $records as $record ) {
        $expect( [
            $record['name'], $record['wikiTitle'], $record['revisionId'],
            $record['levelMin'], $record['levelMax'], $record['avgLevel'],
            $record['race'], $record['class'], $record['era'],
            $record['description'], $record['loc'],
        ], [
            $title, $title, 777, 10, 12, 11,
            'Synthetic race', 'Synthetic class', 'Kunark',
            'Synthetic description', [ 15, -27, -5 ],
        ], "NPC metadata retained {$title}" );
    }
}
$expect( $fixture->npc( 'Synthetic non-NPC page', 'Qeynos Hills', [ 'Qeynos Hills' ], 'UnrelatedTemplate' ), [], 'Non-NPC pages remain excluded' );

echo "Eye of Zomm producer contracts passed: {$checks} synthetic checks.\n";
