<?php
/** Source-only synthetic raid/presence exporter checks. No MediaWiki, DB or writes. */
if ( PHP_SAPI !== 'cli' ) { exit( "CLI only.\n" ); }
class Maintenance {}
class Title {
    private string $text;
    public function __construct( string $text ) { $this->text = ucfirst( str_replace( '_', ' ', $text ) ); }
    public static function makeTitle( $namespace, string $text ): self { return new self( $text ); }
    public static function newFromText( string $text, $namespace = 0 ): self { return new self( $text ); }
    public function getText(): string { return $this->text; }
    public function exists(): bool { return isset( $GLOBALS['fixtures'][$this->text] ); }
}
define( 'NS_MAIN', 0 );
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/maintenance/BuildEyeOfZommPack.php' );
$source = str_replace( "require_once __DIR__ . '/Maintenance.php';", '', $source );
$source = str_replace( "__DIR__ . '/ItemLootSourceCounts.php'", var_export( $root . '/maintenance/ItemLootSourceCounts.php', true ), $source );
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
$structuredNpc = 'Synthetic mischievous batling';
$fixtures['The Warrens'] = [ 'text' => "= Raid Instance =\n! Types of Monsters\n|[[{$structuredNpc}]]\n|-" ];
$fixtures[$structuredNpc] = [ 'text' => $npc( 'The Warrens', 12 ) ];
$fixtures['Synthetic anchored shuriken'] = [ 'text' => $item( 'Synthetic anchored shuriken', "{{ItemWhereRow|[[The Warrens#Raid_Instance|The Warrens]]|[[{$structuredNpc}]]|Behind Muglwump|()}}\n= Raid Instance =\n{{ItemWhereRow|[[The Warrens#Normal_Instance|The Warrens]]|[[{$structuredNpc}]]|Behind Muglwump|()}}" ) ];
$fixtures['Synthetic structured shuriken'] = [ 'text' => $item( 'Synthetic structured shuriken', "= Normal Instance =\n[[West Karana]]\n* [[Synthetic western NPC]]\n{{ItemWhereTable|\n{{ItemWhereRow\n | [[The Warrens|Displayed zone]]\n | [[{$structuredNpc}|Displayed merchant]]\n | Behind [[Synthetic false area]]\n | {{Loc|The Warrens|-15,40|location label}}\n}}\n}}\n* [[Synthetic trailing western NPC]]\n{{ItemWhereRow|Unmapped place|[[Synthetic false NPC]]|Area|()}}\n= Raid Instance =\n{{ItemWhereTable|{{ItemWhereRow|1=[[The Warrens]]|2=[[{$structuredNpc}]]|3=Behind Muglwump|4=()}}}}" ) ];
$withLoot = static fn ( string $text, string $loot ) => preg_replace( '/\n}}$/', "\n|known_loot=\n{$loot}\n}}", $text );
$fixtures['Fire Giant Warrior']['text'] = $withLoot( $fixtures['Fire Giant Warrior']['text'], '<ul><li>{{:Synthetic item}}</li><li>{{:Synthetic NPC-only wand}}</li></ul>' );
$fixtures['Synthetic loot priest'] = [ 'text' => $withLoot( $npc( $zone, 60 ), "<ul><li>{{:Synthetic canonical helm}}</li><li>[[Synthetic linked shield|Displayed shield]] (rare; see [[Synthetic note item]])</li><li>{{VeliousGray|{{:Synthetic wand alias}}}}</li><li>{{Unknown|{{:Synthetic note item}}}}</li><li>{{:File:Synthetic note item}}</li><li>{{:Synthetic no-stat drop}}</li></ul>\nText mentioning [[Synthetic prose item]] is not loot.\n<!-- <li>{{:Synthetic note item}}</li> -->\n<nowiki>{{:Synthetic note item}}</nowiki>" ) ];
$fixtures['Synthetic canonical helm'] = [ 'text' => $item( 'Synthetic displayed helm', '' ) ];
$fixtures['Synthetic linked shield'] = [ 'text' => $item( 'Synthetic linked shield', "[[{$zone}]]\n* [[Synthetic loot priest]]" ) ];
$fixtures['Synthetic NPC-only wand'] = [ 'text' => $item( 'Synthetic NPC-only wand', '' ) ];
$fixtures['Synthetic wand alias'] = [ 'text' => '#REDIRECT [[Synthetic NPC-only wand]]', 'redirect' => 'Synthetic NPC-only wand' ];
$fixtures['Synthetic no-stat drop'] = [ 'text' => "{{Itempage\n|itemname=Synthetic no-stat drop\n}}" ];
$fixtures['Synthetic note item'] = [ 'text' => $item( 'Synthetic note item', '' ) ];
$fixtures['Synthetic prose item'] = [ 'text' => $item( 'Synthetic prose item', '' ) ];
$fixtures['Synthetic display collision'] = [ 'text' => $item( 'Synthetic canonical helm', '' ) ];
$fixtures['Synthetic scoped looter'] = [ 'text' => $withLoot( $npc( $zone ), '* {{:Synthetic normal-only loot}}' ) . "\n= Raid Instance =\n" . $withLoot( $npc( $zone, 60 ), '* {{:Synthetic raid-only loot}}' ) ];
$fixtures['Synthetic normal-only loot'] = [ 'text' => $item( 'Synthetic normal-only loot', '' ) ];
$fixtures['Synthetic raid-only loot'] = [ 'text' => $item( 'Synthetic raid-only loot', '' ) ];
$fixtures['Synthetic nested looter'] = [ 'text' => $withLoot( $npc( $zone, 50 ), "* {{:Synthetic normal-only loot}}\n== Raid Instance ==\n* {{:Synthetic raid-only loot}}\n== Other drops ==\n* {{:Synthetic NPC-only wand}}" ) . "\n= Raid Instance =\n" . $npc( $zone, 60 ) ];
$fixtures['Synthetic case looter'] = [ 'text' => $withLoot( $npc( $zone, 40 ), '* {{:synthetic_case_charm}}' ) ];
$fixtures['Synthetic case charm'] = [ 'text' => $item( 'Synthetic case charm', '' ) ];
$fixtures['Synthetic Case charm'] = [ 'text' => $item( 'Synthetic Case charm', '' ) ];
$fixtures['Synthetic loot-only raid looter'] = [ 'text' => $withLoot( $npc( $zone, 50 ), "* {{:Synthetic normal-only loot}}\n== Raid Instance ==\n* {{:Synthetic raid-only loot}}\n== Other drops ==\n* {{:Synthetic NPC-only wand}}" ) ];
$fixtures['Synthetic outer raid looter'] = [ 'text' => "= Raid Instance =\n" . $withLoot( $npc( $zone, 60 ), "* {{:Synthetic raid-only loot}}\n== Normal Instance ==\n* {{:Synthetic normal-only loot}}\n== Other drops ==\n* {{:Synthetic NPC-only wand}}" ) ];
$fixtures['Synthetic various normal'] = [ 'text' => str_replace( '[[Various]]', 'Various', $npc( 'Various', 10 ) ) . "\n= Raid Instance =\n" . $npc( $zone, 60 ) ];
$fixtures['Synthetic various raid'] = [ 'text' => $npc( $zone, 10 ) . "\n= Raid Instance =\n" . str_replace( '[[Various]]', 'Various', $npc( 'Various', 60 ) ) ];
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
            public function getRedirectTarget() { return empty( $this->fixture['redirect'] ) ? null : Title::newFromText( $this->fixture['redirect'] ); }
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
$items = $call( 'mergeNpcLootSources', $items, $factory );
$lootCounts = ( new ReflectionProperty( $builder, 'npcLootSourceCounts' ) )->getValue( $builder );
$items = $call( 'mergeNpcLootSources', $items, $factory );
$repeatLootCounts = ( new ReflectionProperty( $builder, 'npcLootSourceCounts' ) )->getValue( $builder );
$checks = 0;
$expect = static function ( $actual, $expected, string $label ) use ( &$checks ): void {
    if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( $actual ) ); }
    $checks++;
};
$records = static fn ( string $name ) => array_values( array_filter( $npcs, static fn ( $record ) => $record['name'] === $name ) );
$variants = static fn ( string $name ) => array_column( $records( $name ), 'raidInstance' );
$scopes = static function ( string $name ) use ( $records ): array {
    $result = [];
    foreach ( $records( $name ) as $record ) { $result[$record['instanceType']] = $record['zoneScope'] ?? null; }
    ksort( $result ); return $result;
};
$expect( $scopes( 'Synthetic various normal' ), [ 'normal' => 'various', 'raid' => null ], 'Normal Various scope cannot leak into the raid template' );
$expect( $scopes( 'Synthetic various raid' ), [ 'normal' => null, 'raid' => 'various' ], 'Raid Various scope cannot leak into the normal template' );
$sourceTuples = static fn ( string $name ) => array_map(
    static fn ( $record ) => [ $record['zone'], $record['name'], $record['raidInstance'] ], $items[$name]['dropSources'] );
$byTitle = static fn ( string $name ) => array_values( array_filter( $items, static fn ( $record ) => $record['wikiTitle'] === $name ) )[0];
$npcDrops = static fn ( string $itemName, string $npcName ) => array_values( array_filter( $items[$itemName]['dropSources'], static fn ( $drop ) => $drop['name'] === $npcName ) );
$expect( $byTitle( 'Synthetic canonical helm' )['dropSources'][0]['name'], 'Synthetic loot priest', 'NPC loot joins canonical item page despite a differing display name' );
$expect( $byTitle( 'Synthetic display collision' )['dropSources'], [], 'An item display-name collision cannot replace another page identity' );
$expect( $byTitle( 'Synthetic case charm' )['dropSources'][0]['name'], 'Synthetic case looter', 'First-letter and underscore normalization retains the canonical item' );
$expect( $byTitle( 'Synthetic Case charm' )['dropSources'], [], 'Internal title case distinguishes real item pages' );
$expect( count( $npcDrops( 'Synthetic linked shield', 'Synthetic loot priest' ) ), 1, 'Both documented source directions deduplicate the same identity' );
$expect( $npcDrops( 'Synthetic linked shield', 'Synthetic loot priest' )[0]['lootSources'][0]['field'], 'known_loot', 'NPC field provenance is retained' );
$expect( $npcDrops( 'Synthetic linked shield', 'Synthetic loot priest' )[0]['lootSources'][0]['revisionId'], 123, 'NPC public revision provenance is retained' );
$expect( array_column( $npcDrops( 'Synthetic NPC-only wand', 'Fire Giant Warrior' ), 'raidInstance' ), [ false, true ], 'Shared NPC-only loot follows its normal and raid variants' );
$expect( count( $npcDrops( 'Synthetic NPC-only wand', 'Synthetic loot priest' ) ), 1, 'Local item redirect joins the canonical item' );
$expect( array_column( $npcDrops( 'Synthetic normal-only loot', 'Synthetic scoped looter' ), 'raidInstance' ), [ false ], 'Explicit normal template loot stays normal' );
$expect( $npcDrops( 'Synthetic normal-only loot', 'Synthetic scoped looter' )[0]['lootSources'][0]['section'], '', 'Unlabeled public template does not invent a Normal Instance header' );
$expect( array_column( $npcDrops( 'Synthetic raid-only loot', 'Synthetic scoped looter' ), 'raidInstance' ), [ true ], 'Explicit raid template loot stays raid' );
$expect( array_column( $npcDrops( 'Synthetic raid-only loot', 'Synthetic nested looter' ), 'raidInstance' ), [ true ], 'Nested known_loot Raid Instance scopes sources' );
$expect( array_column( $npcDrops( 'Synthetic NPC-only wand', 'Synthetic nested looter' ), 'raidInstance' ), [ false ], 'Sibling heading ends nested loot raid scope' );
$expect( $variants( 'Synthetic loot-only raid looter' ), [ false ], 'Loot section cannot invent a missing raid stat block' );
$expect( array_column( $npcDrops( 'Synthetic raid-only loot', 'Synthetic loot-only raid looter' ), 'raidInstance' ), [ true ], 'Explicit raid loot survives without raid stats' );
$expect( $npcDrops( 'Synthetic raid-only loot', 'Synthetic loot-only raid looter' )[0]['lootSources'][0]['section'], 'Raid Instance', 'Missing-instance source retains explicit loot-section evidence' );
$expect( array_column( $npcDrops( 'Synthetic normal-only loot', 'Synthetic loot-only raid looter' ), 'raidInstance' ), [ false ], 'Unlabeled normal loot beside a raid section remains normal' );
$expect( array_column( $npcDrops( 'Synthetic NPC-only wand', 'Synthetic loot-only raid looter' ), 'raidInstance' ), [ false ], 'Sibling loot returns to enclosing normal template scope' );
$expect( array_column( $npcDrops( 'Synthetic NPC-only wand', 'Synthetic outer raid looter' ), 'raidInstance' ), [ true ], 'Sibling loot returns to enclosing explicit raid template after inner normal section' );
$expect( $items['Synthetic note item']['dropSources'], [], 'Notes, comments, nowiki, namespaces and unknown templates cannot invent drops' );
$expect( $items['Synthetic prose item']['dropSources'], [], 'Non-list prose cannot establish loot' );
$expect( isset( $items['Synthetic no-stat drop'] ), false, 'NPC loot never synthesizes missing item stats' );
$expect( $lootCounts['sourcesAdded'] > 0 && $lootCounts['itemsEnriched'] > 0, true, 'Reconciliation reports unique source and item additions' );
$expect( $lootCounts['unresolvedItems'] > 0, true, 'Unresolved/statless candidates are counted without creating records' );
$expect( $repeatLootCounts['sourcesAdded'], 0, 'Existing source identities never count as new known_loot sources' );
$expect( $repeatLootCounts['itemsEnriched'], 0, 'No newly enriched items when no source identities are added' );
$expect( strpos( $source, "'npcLootSourcesIncluded' => true" ) !== false, true, 'Production pack marks completed NPC loot reconciliation' );
$expect( strpos( $source, '$items = $this->mergeNpcLootSources( $items, $wikiPageFactory );' ) !== false, true, 'Production uses tested NPC loot reconciliation' );
foreach ( $items as $itemRecord ) {
    foreach ( $itemRecord['dropSources'] as $drop ) {
        if ( !isset( $drop['lootSources'] ) ) { continue; }
        $siblings = array_filter( $records( $drop['name'] ), static fn ( $record ) => $record['zone'] === $drop['zone'] );
        $joins = array_filter( $siblings, static fn ( $record ) => $record['raidInstance'] === $drop['raidInstance'] );
        $explicitLootWithoutStats = !$joins && $siblings && array_filter( $drop['lootSources'], static fn ( $entry ) => $entry['section'] === ( $drop['raidInstance'] ? 'Raid Instance' : 'Normal Instance' ) );
        $expect( count( $joins ) === 1 || (bool)$explicitLootWithoutStats, true, 'NPC source joins one exact variant or has explicit loot evidence beside other-instance stats' );
    }
}
$expect( $call( 'parseDropSourceLines', "<!-- Hidden structured row\n{{ItemWhereRow|[[The Warrens]]|[[Synthetic comment ghost]]|Area|()}}\n-->", false, false ), [], 'Multiline HTML comments are removed before direct structured-row tokenization' );
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
$expect( $sourceTuples( 'Synthetic structured shuriken' ), [
    [ 'West Karana', 'Synthetic western NPC', false ],
    [ 'The Warrens', $structuredNpc, false ],
    [ 'West Karana', 'Synthetic trailing western NPC', false ],
    [ 'The Warrens', $structuredNpc, true ],
], 'Balanced multiline ItemWhereRow keeps piped/numeric fields, ignores area/Loc/unmapped zones and does not change surrounding zone scope' );
$expect( $sourceTuples( 'Synthetic anchored shuriken' ), [
    [ 'The Warrens', $structuredNpc, true ], [ 'The Warrens', $structuredNpc, false ],
], 'Explicit structured row zone anchors override an opposite normal/raid section' );
foreach ( $items['Synthetic structured shuriken']['dropSources'] as $drop ) {
    if ( $drop['name'] !== $structuredNpc ) { continue; }
    $joins = array_filter( $records( $drop['name'] ), static fn ( $record ) =>
        $record['zone'] === $drop['zone'] && $record['raidInstance'] === $drop['raidInstance'] );
    $expect( count( $joins ), 1, 'Structured source joins exactly one normal/raid NPC variant' );
}
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
