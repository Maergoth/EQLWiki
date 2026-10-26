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
require_once $root . '/extensions/DynamicZoneList/NpcLootWikitext.php';
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
    $method( 'rememberNpcLootSources', 'mergeNpcLootSources' ),
    $method( 'lootItemTemplateTarget', 'resolveLootItemTemplate' ),
    $method( 'loadLootSets', 'resolveLootTitle' ),
    $method( 'dropSourceMarkup', 'dropSourceNames' ),
    $method( 'detectZonesFromCategories', 'detectZonesFromField' ),
    $method( 'detectZonesFromField', 'detectZonesFromTitle' ),
    $method( 'detectZonesFromTitle', 'detectEra' ),
    $method( 'detectEra', 'parseClasses' ),
    $method( 'parseClasses', 'parseSlots' ),
    $method( 'parseItemStats', 'parseDropSources' ),
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
define( 'NS_MAIN', 0 );
define( 'NS_TEMPLATE', 10 );
class Title {
    private string $text;
    public function __construct( string $text ) { $this->text = ucfirst( str_replace( '_', ' ', $text ) ); }
    public static function makeTitle( $namespace, string $text ): self { return new self( $text ); }
    public static function newFromText( string $text, $namespace = 0 ): self { return new self( $text ); }
    public function getText(): string { return $this->text; }
    public function getNamespace(): int { return preg_match( '/^(?:File|Template|User|Talk|Category):/i', $this->text ) ? 1 : 0; }
    public function isExternal(): bool { return false; }
    public function getFragment(): string { return ''; }
}

// Execute the actual selected methods without loading the maintenance runner.
eval( 'class EozExporterFixture {
    private array $zoneCatalog = [];
    private array $zoneAliasMap = [];
    private array $wikiEntityNames = [];
    private array $npcLootSources = [];
    private array $lootSets = [];
    private array $lootSourceKinds = [];
    private array $setAliases = [];
    private object $lootCounts;
    ' . $classCodes[0] . implode( "\n", $methods ) . '
    public function fatalError( string $message ) { throw new RuntimeException( $message ); }
    public function classes( string $stats ): array { return $this->parseClasses( $stats ); }
    public function stats( string $stats ): array { return $this->parseItemStats( $stats ); }
    public function templateTarget( string $source ): ?string { return $this->lootItemTemplateTarget( $source ); }
    private function maybePause(): void {}
    private function publicLootText( $title, $page ): ?string { return $page->text; }
    private function resolveLootTitle( string $name, $factory ): array {
        return ["title"=>$this->setAliases[$name] ?? $name,"pageId"=>1,"public"=>!str_starts_with($name,"Hidden"),"complete"=>true];
    }
    public function sets( array $texts, array $aliases = [] ): array {
        $this->lootSets = []; $this->setAliases = $aliases;
        $this->lootCounts = new class { public function incomplete(): void {} };
        $rows = array_map( static fn ( $name ) => (object)["page_title"=>$name], array_keys( $texts ) );
        $db = new class( $rows ) {
            public function __construct( private array $rows ) {}
            public function select( ...$args ): array { return $this->rows; }
        };
        $factory = new class( $texts ) {
            public function __construct( private array $texts ) {}
            public function newFromTitle( $title ): object { return (object)["text"=>$this->texts[$title->getText()]]; }
        };
        $this->loadLootSets( $db, $factory );
        unset( $this->lootCounts );
        return $this->lootSets;
    }
    public function location( string $text ): ?array { return $this->parseLocation( $text ); }
    public function catalog(): array { $this->loadZoneCatalog(); return $this->zoneCatalog; }
    public function zone( string $alias ): array {
        $this->loadZoneCatalog();
        return [
            $this->detectZonesFromCategories( [ $alias ] ),
            $this->detectZonesFromField( "[[" . $alias . "]]" ),
            $this->detectZonesFromTitle( "Synthetic sentinel (" . $alias . ")" ),
        ];
    }
    public function zoneField( string $text ): array { return $this->detectZonesFromField( $text ); }
    public function zoneCategories( array $values ): array { return $this->detectZonesFromCategories( $values ); }
    public function lootSources(): array { return $this->npcLootSources; }
    public function npc( string $title, ?string $zoneField, array $categories, string $templateName ): array {
        $this->loadZoneCatalog();
        $zoneLine = $zoneField === null ? "" : "\n| zone = " . $zoneField;
        $text = "{{" . $templateName . $zoneLine . "\n| level = 10-12\n| race = Synthetic race\n| class = Synthetic class\n| description = [[Synthetic page|Synthetic description]]\n| location = {{loc|15|-27|-5}}\n| known_loot = <ul><li>{{:synthetic_fixture_drop}}</li></ul>\n}}";
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
// Literal weapon values must survive as independent base fields. The old
// exporter kept these lines for display while silently dropping their numbers.
$weapon = $fixture->stats( 'Slot: PRIMARY SECONDARY<br>Skill: Piercing Atk Delay: 22<br>DMG: 10 BACKSTAB: 15<br>DMG Bonus: 10<br>STR: +10 STA: +5 DEX: +10<br>SV FIRE: +5 SV COLD: +5<br>WT: 2.5 Size: SMALL<br>Class: ROG' );
foreach ( [ 'DMG'=>10, 'BACKSTAB_DMG'=>15, 'DELAY'=>22, 'DMG_BONUS'=>10,
    'STR'=>10, 'STA'=>5, 'DEX'=>10, 'SV_FIRE'=>5, 'SV_COLD'=>5, 'WT'=>2.5 ] as $key => $value ) {
    $expect( $weapon[$key] ?? null, $value, "Authored weapon base {$key}" );
}
foreach ( [ 'BACKSTAB', 'Backstab DMG', 'Backstab Damage' ] as $label ) {
    $expect( $fixture->stats( $label . " \t: 15" )['BACKSTAB_DMG'] ?? null, 15, "Backstab alias {$label} with colon whitespace" );
}
foreach ( [ 'Delay', 'Atk Delay', 'Attack Delay' ] as $label ) {
    $expect( $fixture->stats( $label . ' : 22' )['DELAY'] ?? null, 22, "Literal delay alias {$label}" );
}
foreach ( [ 'DMG Bonus', 'Damage Bonus', 'Dmg Bon', 'Damage Bon' ] as $label ) {
    $expect( $fixture->stats( $label . ' : 10' )['DMG_BONUS'] ?? null, 10, "Literal damage-bonus alias {$label}" );
}
$zeros = $fixture->stats( 'DMG: 10 BACKSTAB: 0<br>Atk Delay: 0<br>DMG Bonus: 0' );
foreach ( [ 'BACKSTAB_DMG', 'DELAY', 'DMG_BONUS' ] as $key ) {
    $expect( $zeros[$key] ?? null, 0, "Explicit zero {$key} remains authoritative" );
}
$expect( $fixture->stats( 'DMG: 10' ), [ 'DMG'=>10 ], 'Missing weapon fields are never guessed from damage' );
$expect( $fixture->stats( 'Cold DMG: 4<br>Backstab DMG: 15' ), [ 'COLD_DMG'=>4, 'BACKSTAB_DMG'=>15 ], 'Elemental and Backstab labels never become ordinary damage' );
$expect( $fixture->stats( "backstab\t damage : 15<br>attack\t delay : 22<br>dmg\t bonus : 10<br>DMG : 10" ),
    [ 'DELAY'=>22, 'DMG_BONUS'=>10, 'BACKSTAB_DMG'=>15, 'DMG'=>10 ], 'Multiword labels retain literal whitespace and case compatibility' );
foreach ( [ 'SV VOID', 'SV Corruption', 'SV_VOID', 'SV_CORRUPTION', 'SV.Void', 'SV.Corruption',
    'SV. Void', 'SV. Corruption', 'SV-Void', 'SV-Corruption',
    'SVVoid', 'SVCorruption', 'ResistVoid', 'ResistCorruption', 'Void', 'Corruption', "sv\tvoid" ] as $label ) {
    foreach ( [ '+30' => 30, '0' => 0, '-2' => -2, '0.5' => 0.5 ] as $written => $numeric ) {
        $expect( $fixture->stats( $label . ' : ' . $written ), [ 'SV_CORRUPTION' => $numeric ], "Literal intrinsic Void alias {$label} preserves {$written}" );
    }
}
foreach ( [ 'SV MAGIC: +5 SV FIRE: +5 SV VOID: +30', 'SV VOID: +30 SV FIRE: +5',
    'STR: 2 SV VOID: +30 DEX: 3', 'SV VOID: +30 SV Corruption: 30.0',
    'SV. MAGIC: +5 SV. FIRE: +5 SV. VOID: +30', 'SV._ MAGIC: +5 SV._ VOID: +30',
    'SV-VOID: +30 SV. COLD: +5' ] as $line ) {
    $expect( $fixture->stats( $line )['SV_CORRUPTION'] ?? null, 30, 'Void survives first/middle/last and consistent repeated grouped numeric fields' );
}
foreach ( [ 'SV VOID: 30<br>SV Corruption: 31', 'SV VOID: 0 SV_CORRUPTION: -2',
    'SV VOID: ?', 'SV VOID: unknown', 'SV VOID: 30?', 'SV VOID: 30 to 31', 'SV VOID: + 30',
    'SV FIRE: 5 SV VOID: ?', 'SV VOID: 30<br>SV VOID: unknown',
    'SV VOID: 9223372036854775808', 'SV VOID: -9223372036854775809',
    'SV VOID: ' . str_repeat( '9', 400 ) ] as $line ) {
    $stats = $fixture->stats( $line );
    $expect( array_key_exists( 'SV_CORRUPTION', $stats ), true, 'Explicit malformed/conflicting Void retains unknown field presence' );
    $expect( $stats['SV_CORRUPTION'], null, 'Malformed/conflicting intrinsic aliases cannot become a trusted numeric base' );
}
foreach ( [ 'HP: 12 STR: 2', 'Effect: SV VOID: 30', 'Notes: Corruption: 30',
    'SV FIRE: 5 and a note about SV VOID: 30', 'Bonus SV VOID: 30', 'SV VOIDLESS: 30',
    '<!-- SV VOID: 30 -->', "<!--\nSV VOID: 30\n-->", '<nowiki>SV VOID: 30</nowiki>',
    "<pre>\nSV VOID: 30\n</pre>", "{{Unknown\nSV VOID: 30\n}}" ] as $line ) {
    $expect( array_key_exists( 'SV_CORRUPTION', $fixture->stats( $line ) ), false, 'Absent, incidental or unexpanded content never invents a Void base' );
}
$expect( $fixture->stats( '<!-- SV VOID: 99 --><br>SV VOID: 30' ), [ 'SV_CORRUPTION' => 30 ], 'Commented pseudo-stat does not conflict with the literal field' );
$expect( $fixture->stats( 'SV VOID: 9007199254740991' ), [ 'SV_CORRUPTION' => 9007199254740991 ], 'The JavaScript-safe numeric boundary is preserved without integer saturation' );
$viewerIds = [];
foreach ( $fixture->catalog() as $zone ) {
    if ( !isset( $zone['viewerId'] ) ) { continue; }
    $expect( in_array( $zone['viewerId'], $zone['aliases'], true ), true, 'Explicit viewer ID is a supported exact zone alias' );
    $viewerIds[$zone['name']] = $zone['viewerId'];
}
$expect( $viewerIds, [ 'Plane of Sky' => 'airplane', 'North Ro' => 'nro', 'West Karana' => 'qey2hh1', 'South Ro' => 'sro' ], 'Catalog load preserves the four reviewed archive preferences' );
$legacyCard = '<onlyinclude><includeonly><div class="hbdiv">[[Synthetic Set Sleeve]]<span class="hb">{{Itembox|itemname=Synthetic Set Sleeve|statsblock=AC: 3}}</span></div></includeonly></onlyinclude>{{Itempage|itemname=Synthetic Set Sleeve|dropsfrom=* [[Synthetic Keeper]]}}';
$expect( $fixture->templateTarget( $legacyCard ), 'Synthetic Set Sleeve', 'Legacy template needs matching unconditional item hover' );
$expect( $fixture->templateTarget( str_replace( '[[Synthetic Set Sleeve]]', '[[Different Sleeve]]', $legacyCard ) ), null, 'Legacy item template with mismatched leading link is not verified' );
$expect( $fixture->templateTarget( str_replace( '<div class="hbdiv">', '{{#if:1|<div class="hbdiv">', $legacyCard ) ), null, 'Conditional legacy wrapper stays unverified' );
$expect( $fixture->templateTarget( $legacyCard . '<onlyinclude>extra</onlyinclude>' ), null, 'Multiple include sections are not guessed' );
$expect( $fixture->templateTarget( '{{Itempage|itemname=Synthetic Set Sleeve}}' ), null, 'Item metadata alone does not prove rendered template loot' );
$sets = $fixture->sets( [ 'Synthetic Armor' => '{{Gear Set|Synthetic Bracer|Synthetic Boots|Bracer alias}}', 'Synthetic Collection' => '{{Gear Set|Synthetic Armor|Synthetic Ring}}' ], [ 'Bracer alias'=>'Synthetic Bracer' ] );
$expect( $sets['Synthetic Armor'], [ 'items'=>[ 'Synthetic Boots', 'Synthetic Bracer' ], 'complete'=>true ], 'Explicit set members are canonical and deduplicated' );
$expect( $sets['Synthetic Collection'], [ 'items'=>[ 'Synthetic Boots', 'Synthetic Bracer', 'Synthetic Ring' ], 'complete'=>true ], 'Nested explicit sets flatten without name guesses' );
$sets = $fixture->sets( [ 'Synthetic Cycle' => '{{Gear Set|Synthetic Cycle|Synthetic Ring}}' ] );
$expect( $sets['Synthetic Cycle']['complete'], false, 'Cyclic sets cannot prove full membership' );
$sets = $fixture->sets( [ 'Synthetic Hidden' => '{{Gear Set|Hidden Member|Synthetic Ring}}', 'Synthetic Dynamic' => '{{Gear Set|{{Unknown wrapper|Synthetic Boots}}}}' ] );
$expect( $sets['Synthetic Hidden'], [ 'items'=>[ 'Synthetic Ring' ], 'complete'=>false ], 'Unreadable member identity is omitted and makes a set incomplete' );
$expect( $sets['Synthetic Dynamic']['complete'], false, 'Opaque set members remain incomplete' );

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
    [ 'kerraridge', 'Kerra Island' ],
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
        $expect( $record['zoneScope'] ?? null, $zoneField === 'Various' ? 'various' : null,
            "Only literal Various establishes unrestricted scope {$title}" );
        $expect( $record['categoryZones'] ?? [], $fixture->zoneCategories( $categories ),
            "Category-backed dynamic presence remains separate from explicit zones {$title}" );
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
$loot = $fixture->lootSources();
$expect( array_keys( $loot ), [ 'Synthetic fixture drop' ], 'Actual NPC-loot helper canonicalizes the first letter and spaces' );
$expect( $loot['Synthetic fixture drop']['sources'][0]['name'], 'Synthetic merchant', 'Actual NPC-loot helper retains the NPC source' );
$expect( $loot['Synthetic fixture drop']['sources'][0]['zone'], 'East Commonlands', 'NPC-loot helper keeps explicit source-zone provenance' );

echo "Eye of Zomm producer contracts passed: {$checks} synthetic checks.\n";
