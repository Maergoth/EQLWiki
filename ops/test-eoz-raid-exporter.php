<?php
/** Source-only synthetic raid/presence exporter checks. No MediaWiki, DB or writes. */
if ( PHP_SAPI !== 'cli' ) { exit( "CLI only.\n" ); }
class Maintenance {}
class Title {
    private string $text;
    public function __construct( string $text ) { $this->text = str_replace( '_', ' ', $text ); }
    public static function makeTitle( $namespace, string $text ): self { return new self( $text ); }
    public static function newFromText( string $text, $namespace = 0 ): self { return new self( $text ); }
    public function getText(): string { return $this->text; }
    public function exists(): bool { return isset( $GLOBALS['fixtures'][$this->text] ); }
}
define( 'NS_MAIN', 0 );
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/maintenance/BuildEyeOfZommPack.php' );
$source = str_replace( "require_once __DIR__ . '/Maintenance.php';", '', $source );
$source = preg_replace( '/^use [^;]+;\r?$/m', '', $source );
$source = str_replace( "__DIR__ . '/zone-catalog.json'", var_export( $root . '/maintenance/zone-catalog.json', true ), $source );
$source = preg_replace( '/\$maintClass = BuildEyeOfZommPack::class;[\s\S]*$/', '', $source );
eval( substr( $source, 5 ) );
$npc = static fn ( string $zone, int $level = 10 ) => "{{Namedmobpage\n|zone=[[{$zone}]]\n|level={$level}\n|location=(10,20,30)\n}}";
$item = static fn ( string $name, string $sources ) => "{{Itempage\n|itemname={$name}\n|statsblock=HP: 5<br>Class: WAR<br>Slot: HEAD\n|dropsfrom=\n{$sources}\n}}";
$zone = "Nagafen's Lair";
$fixtures = [
    $zone => [ 'text' => "[[King Tranix]] (Raid Instance Only)\n= Raid Instance =\nEntrance: [[Voidling]]\n! Types of Monsters\n|[[Fire Giant Warrior]]\n|-\n! Raid Boss NPCs\n|[[King Tranix]]\n|-\n= Other =\n! Types of Monsters\n|[[Synthetic public guard]]" ],
    'King Tranix' => [ 'text' => $npc( $zone, 52 ) ],
    'Fire Giant Warrior' => [ 'text' => $npc( $zone, 49 ) ],
    'Voidling' => [ 'text' => $npc( $zone ) ],
    'Synthetic public guard' => [ 'text' => $npc( $zone ) ],
    'Synthetic future zone' => [ 'text' => "[[Synthetic future boss]] (Raid Instance Only)\n== Raid Instance ==\n=== Bosses ===\n! Raid Boss NPCs\n|[[Synthetic future boss]]\n|-\n== Normal Instance ==\n! Types of Monsters\n|[[Synthetic future public]]" ],
    'Synthetic future boss' => [ 'text' => $npc( 'Synthetic future zone', 55 ) ],
    'Synthetic future public' => [ 'text' => $npc( 'Synthetic future zone', 15 ) ],
    'Synthetic item' => [ 'text' => $item( 'Synthetic item', "[[{$zone}]]\n* [[Fire Giant Warrior]]" ) ],
    'Synthetic section item' => [ 'text' => $item( 'Synthetic section item', "[[{$zone}]]\n* [[Synthetic public guard]]\n== Raid Instance ==\n[[{$zone}]]\n* [[Synthetic public guard]]" ) ],
    'Synthetic skipped item' => [ 'text' => "{{Itempage\n|itemname=Synthetic alternate item\n}}" ],
    'Synthetic skipped NPC' => [ 'text' => 'A public categorized NPC without a recognized stat template.' ],
];
$hateNpc = 'Synthetic Grandmaster R`Tal';
$fixtures['Plane of Hate'] = [ 'text' => "= Raid Instance =\n! Raid Boss NPCs\n|[[{$hateNpc}]]\n|-" ];
$fixtures[$hateNpc] = [ 'text' => $npc( 'Plane of Hate', 54 ) ];
$fixtures['Synthetic styled Hate gem'] = [ 'text' => $item( 'Synthetic styled Hate gem', "[[Plane of Hate]]\n* {{VeliousGray| [[{$hateNpc}|Unrelated display label]] (Hate 2.0)}}\n** {{Loc|Plane of Hate|-12,+34,56|(-12,+34,56)}}\n== Raid Instance ==\n[[Plane of Hate]]\n* {{VeliousGray| [[{$hateNpc}]] (Hate 2.0)}}" ) ];
$fixtures['Synthetic ground book'] = [ 'text' => $item( 'Synthetic ground book', "[[Plane of Hate]]\n* Ground Spawn\n** {{Loc|Plane of Hate|-12,+34,56|(-12,+34,56)}}" ) ];
$fixtures['Synthetic wrapped zones'] = [ 'text' => $item( 'Synthetic wrapped zones', "[[West Karana]]\n* [[Synthetic western NPC]]\n{{VeliousGray| [[The Warrens]] }}\n* {{VeliousGray| [[Synthetic warrens NPC]] }}\n[[Timorous Deep]] {{Era|Kunark}}\n* [[Synthetic ocean NPC|Display name]] {{Era|Kunark}} (see [[Synthetic false note]])\n** [[Synthetic nested NPC]]\n** {{UnknownSource|[[Synthetic false NPC]]}}\n** [[File:Map Icon.png]]" ) ];
$factory = new class {
    public function newFromTitle( $title ) {
        return new class( $GLOBALS['fixtures'][$title->getText()] ?? null ) {
            private $fixture;
            public function __construct( $fixture ) { $this->fixture = $fixture; }
            public function getContent() {
                if ( !$this->fixture ) { return null; }
                return new class( $this->fixture['text'] ) {
                    private string $text;
                    public function __construct( string $text ) { $this->text = $text; }
                    public function getText(): string { return $this->text; }
                };
            }
            public function getRevisionRecord() { return new class { public function getId(): int { return 123; } }; }
            public function getRedirectTarget() { return null; }
        };
    }
};
$builder = ( new ReflectionClass( BuildEyeOfZommPack::class ) )->newInstanceWithoutConstructor();
$call = static fn ( string $method, ...$args ) => ( new ReflectionMethod( $builder, $method ) )->invoke( $builder, ...$args );
$call( 'loadZoneCatalog' );
$call( 'loadRaidInstanceIndex', $factory, [ (object)[ 'page_title' => 'Synthetic_future_zone' ] ] );
$npcRows = []; $itemRows = []; $titleRows = [];
foreach ( $fixtures as $name => $fixture ) {
    $row = (object)[ 'page_namespace' => 0, 'page_title' => str_replace( ' ', '_', $name ) ];
    $titleRows[] = $row;
    if ( strpos( $fixture['text'], '{{Namedmobpage' ) !== false || $name === 'Synthetic skipped NPC' ) { $npcRows[] = $row; }
    if ( strpos( $fixture['text'], '{{Itempage' ) !== false ) { $itemRows[] = $row; }
}
$titleRows[] = (object)[ 'page_title' => 'Synthetic_public_redirect' ];
$call( 'indexWikiEntityCandidates', $npcRows, $itemRows, $titleRows );
$npcs = [];
foreach ( $npcRows as $row ) { $npcs = array_merge( $npcs, $call( 'buildNpcRecords', $factory, $row, [] ) ); }
$npcs = $call( 'applyNpcInstanceVariants', $npcs );
$npcs = $call( 'sortNpcRecords', $npcs );
$items = [];
foreach ( $itemRows as $row ) {
    $record = $call( 'buildItemRecord', $factory, $row, [] );
    if ( $record ) { $items[$record['name']] = $record; }
}
$checks = 0;
$expect = static function ( $actual, $expected, string $label ) use ( &$checks ): void {
    if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( $actual ) ); }
    $checks++;
};
$records = static fn ( string $name ) => array_values( array_filter( $npcs, static fn ( $record ) => $record['name'] === $name ) );
$variants = static fn ( string $name ) => array_column( $records( $name ), 'raidInstance' );
$sourceTuples = static fn ( string $name ) => array_map(
    static fn ( $record ) => [ $record['zone'], $record['name'], $record['raidInstance'] ], $items[$name]['dropSources'] );
$expect( $sourceTuples( 'Synthetic styled Hate gem' ), [
    [ 'Plane of Hate', $hateNpc, false ], [ 'Plane of Hate', $hateNpc, true ],
], 'Styled Hate links use exact NPC destinations, without display labels/revamp annotations' );
foreach ( $items['Synthetic styled Hate gem']['dropSources'] as $drop ) {
    $joins = array_filter( $records( $drop['name'] ), static fn ( $record ) =>
        $record['zone'] === $drop['zone'] && $record['raidInstance'] === $drop['raidInstance'] );
    $expect( count( $joins ), 1, 'Styled drop joins exactly one exported NPC instance variant' );
}
$expect( $sourceTuples( 'Synthetic ground book' ), [ [ 'Plane of Hate', 'Ground Spawn', false ] ], 'Location-only nested bullets never create monsters/drop sources' );
$expect( $sourceTuples( 'Synthetic wrapped zones' ), [
    [ 'West Karana', 'Synthetic western NPC', false ],
    [ 'The Warrens', 'Synthetic warrens NPC', false ],
    [ 'Timorous Deep', 'Synthetic ocean NPC', false ],
    [ 'Timorous Deep', 'Synthetic nested NPC', false ],
], 'Wrapped/era headings set the correct zone; real nested NPCs survive; unresolved templates/file links do not' );
foreach ( $items as $itemRecord ) {
    foreach ( $itemRecord['dropSources'] as $drop ) {
        $expect( (bool)preg_match( '/[{}\[\]|]/', $drop['name'] ), false, 'Source identities never contain raw template/link markup' );
    }
}
$expect( $variants( 'King Tranix' ), [ true ], 'Explicit raid-only boss' );
$expect( $variants( 'Fire Giant Warrior' ), [ true, false ], 'Shared NPC emits raid before normal for legacy clients' );
$expect( isset( $records( 'Fire Giant Warrior' )[0]['loc'] ), false, 'No borrowed public raid coordinate' );
$legacy = [];
foreach ( $npcs as $record ) { $legacy[strtolower( $record['zone'] . '|' . $record['name'] )] = $record; }
$legacyShared = $legacy[strtolower( $records( 'Fire Giant Warrior' )[0]['zone'] . '|Fire Giant Warrior' )];
$expect( $legacyShared['loc'], [ 10, 20, 30 ], 'Legacy last-wins projection keeps normal coordinate' );
$expect( $legacyShared['raidInstance'], false, 'Legacy last-wins projection keeps normal variant' );
$expect( $legacy[strtolower( $records( 'King Tranix' )[0]['zone'] . '|King Tranix' )]['raidInstance'], true, 'Legacy projection retains raid-only boss' );
$expect( strpos( $source, '$npcs = $this->sortNpcRecords( $npcs );' ) !== false, true, 'Production uses tested sort helper' );
$expect( $variants( 'Voidling' ), [ false ], 'Entrance prose does not establish membership' );
$expect( $variants( 'Synthetic public guard' ), [ false ], 'Sibling heading ends raid scope' );
$expect( $variants( 'Synthetic future boss' ), [ true ], 'Future uncatalogued zone needs no allowlist' );
$expect( $records( 'Synthetic future boss' )[0]['zone'], 'Synthetic future zone', 'Dynamic zone field resolves' );
$expect( $variants( 'Synthetic future public' ), [ false ], 'Nested raid heading preserves public sibling' );
$expect( array_column( $items['Synthetic item']['dropSources'], 'raidInstance' ), [ false, true ], 'Shared item source variants' );
$expect( array_column( $items['Synthetic section item']['dropSources'], 'raidInstance' ), [ false, true ], 'Item source sections do not bleed' );
$presence = $call( 'wikiEntityIndex' );
$expect( in_array( 'Synthetic skipped NPC', $presence['npcs'], true ), true, 'Skipped NPC remains present' );
$expect( in_array( 'Synthetic skipped item', $presence['items'], true ), true, 'Skipped item remains present' );
$expect( in_array( 'Synthetic alternate item', $presence['items'], true ), true, 'Statless template alias remains present' );
$expect( in_array( 'Synthetic public redirect', $presence['titles'], true ), true, 'Public redirect fallback' );
$expect( strpos( $source, "'wikiEntitiesComplete' => \$limit === 0" ) !== false, true, 'Limited exports never authorize absence' );
echo "Raid/presence producer contracts passed: {$checks} synthetic checks.\n";
