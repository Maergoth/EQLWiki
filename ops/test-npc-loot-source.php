<?php
/** Synthetic, source-only checks. No wiki services, database or page writes. */
require_once dirname( __DIR__ ) . '/extensions/DynamicZoneList/NpcLootWikitext.php';
$checks = 0;
$expect = static function ( $actual, $expected, string $label ) use ( &$checks ): void {
	$checks++;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . "\nExpected " . var_export( $expected, true ) . "\nActual " . var_export( $actual, true ) ); }
};
$npc = static fn ( string $name, string $zone, string $loot = '', string $common = '' ): string => "{{Namedmobpage\n|name={$name}\n|zone={$zone}\n|known_loot={$loot}\n|common_loot={$common}\n}}";
$item = static fn ( string $drops ): string => "<onlyinclude>{{Itempage\n|itemname=Synthetic Gem\n|statsblock=HP: 5\n|dropsfrom=\n{$drops}\n}}</onlyinclude>";
$row = static fn ( string $name, string $zone = '', string $instance = 'normal' ): array => [ 'npc' => $name, 'zone' => $zone, 'instance' => $instance ];
$expect( EqlNpcLootWikitext::identity( "  A_SYNTHETIC_M`Kari\u{00a0} " ), "a synthetic m'kari", 'Comparison identity preserves normalized apostrophes, case and spaces' );
$expect( EqlNpcLootWikitext::identity( 'A synthetic M’Kari' ), "a synthetic m'kari", 'Unicode apostrophe shares the same identity' );
$manual = $npc( 'A synthetic keeper', '[[Synthetic Vale]]' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $manual ), [ 'names' => [ 'A synthetic keeper' ], 'zones' => [ 'Synthetic Vale' ], 'authoredItems' => [] ], 'A newly created ordinary wiki NPC is recognized without EoZ metadata' );
$expect( EqlNpcLootWikitext::itemSources( $item( '[[A synthetic keeper]]' ) ), [ $row( 'A synthetic keeper' ) ], 'Previously published direct item source works before or after the NPC exists' );
$cards = $npc( 'A synthetic keeper', '[[Synthetic Vale|Vale]] / [[Other Vale]]', "<ul><li>{{:Authored Gem}} (see [[A false note]])</li><li>[[Authored Shield|Shield]]</li></ul>", '* {{:Common Gem}}' ) . "\n== Raid Instance ==\n" . $npc( 'a_SYNTHETIC_keeper', '[[Synthetic Vale]]', "* {{VeliousGray|{{:Raid Gem}}}}\n* {{:Authored_Gem}}\nProse [[Not Loot]]", '* [[Common Gem]]' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $cards ), [ 'names' => [ 'A synthetic keeper', 'a SYNTHETIC keeper' ], 'zones' => [ 'Synthetic Vale', 'Other Vale' ], 'authoredItems' => [ 'Authored Gem', 'Authored Shield', 'Common Gem', 'Raid Gem' ] ], 'Every card contributes authored Common/Unique exclusions without harvesting rarity notes or prose' );
foreach ( [ '<!-- ' . $manual . ' -->', '<nowiki>' . $manual . '</nowiki>', '{{Example|' . $manual . '}}', '{{Namedmobpage|name=Broken', 'Just prose', str_repeat( 'x', 524289 ) ] as $source ) {
	$expect( EqlNpcLootWikitext::analyseNpcPage( $source ), null, 'Only active balanced top-level NPC cards qualify' );
}
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', "<!-- * {{:Fake Gem}} -->\n<nowiki>* {{:Hidden Gem}}</nowiki>\n{{Example|\n* {{:Nested Gem}}\n}}\n* {{:Real Gem}}" ) )['authoredItems'], [ 'Real Gem' ], 'Protected and nested examples never suppress a real recovered item' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', '{{:One Gem}} {{:Two Gems}}' ) )['authoredItems'], [ 'One Gem', 'Two Gems' ], 'All direct authored transclusions are excluded, even on a single line' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', "{{{parameter|\n* {{:Nested Gem}}\n}}}\n* {{:Real Gem}}" ) )['authoredItems'], [ 'Real Gem' ], 'An unresolved nested parameter is not a directly authored loot row' );
$sources = "[[Synthetic Vale]]\n* [[A synthetic keeper|Display]] (see [[Not A Source]])\n** {{Loc|Synthetic Vale|-1,2,3|Location}}\n[[Other Vale]]\n* [[Another keeper]]\n== Raid Instance ==\n[[Synthetic Vale]]\n* [[A synthetic keeper]]\n== Normal Zone ==\n[[Third Vale]]\n<ul><li>[[Third keeper]] (Rare)</li></ul>";
$expected = [ $row( 'A synthetic keeper', 'Synthetic Vale' ), $row( 'Another keeper', 'Other Vale' ), $row( 'A synthetic keeper', 'Synthetic Vale', 'raid' ), $row( 'Third keeper', 'Third Vale' ) ];
foreach ( [ "\n", "\r\n" ] as $eol ) {
	$expect( EqlNpcLootWikitext::itemSources( str_replace( "\n", $eol, $item( $sources ) ) ), $expected, 'Zone and normal/raid contexts remain independent for both line ending styles' );
}
$expect( EqlNpcLootWikitext::itemSources( "== Raid Instance ==\n" . $item( "[[Synthetic Vale]]\n* [[Raid keeper]]" ) ), [ $row( 'Raid keeper', 'Synthetic Vale', 'raid' ) ], 'External Raid Instance header applies to the actual item card' );
$expect( EqlNpcLootWikitext::itemSources( $item( "[[Synthetic Vale]]\n* [[Normal keeper]]" ) . "\n== Raid Instance ==\n" . $item( "[[Synthetic Vale]]\n* [[Raid keeper]]" ) ), [ $row( 'Normal keeper', 'Synthetic Vale' ), $row( 'Raid keeper', 'Synthetic Vale', 'raid' ) ], 'Separate outer instance cards preserve both source contexts' );
$expect( EqlNpcLootWikitext::itemSources( $item( "[[Synthetic Vale]]\n* {{VeliousGray|[[A synthetic keeper]] (Hate 2.0)}}\n* {{Unknown|[[False keeper]]}}" ) ), [ $row( 'A synthetic keeper', 'Synthetic Vale' ) ], 'Known presentation wrapper retains the real linked source; unknown wrappers do not invent sources' );
$structured = "[[Other Vale]]\n* [[Old keeper]]\n{{ItemWhereTable|\n{{ItemWhereRow\n|[[Synthetic Vale|Displayed Vale]]\n|[[A synthetic keeper|Displayed keeper]]\n|Behind [[Not a source]]\n|{{Loc|Synthetic Vale|1,2,3|Location}}\n}}\n}}\n* [[Trailing old keeper]]\n== Raid Instance ==\n{{ItemWhereRow|1=[[Third Vale#Normal_Instance]]|2=[[Third keeper]]|3=Area|4=()}}\n{{ItemWhereRow|[[Synthetic Vale#Raid_Instance]]|[[Raid keeper]]|Area|()}}";
$expect( EqlNpcLootWikitext::itemSources( $item( $structured ) ), [ $row( 'A synthetic keeper', 'Synthetic Vale' ), $row( 'Third keeper', 'Third Vale' ), $row( 'Raid keeper', 'Synthetic Vale', 'raid' ), $row( 'Old keeper', 'Other Vale' ), $row( 'Trailing old keeper', 'Other Vale' ) ], 'Multiline structured fields ignore Area/Loc, preserve the surrounding zone and honor explicit row instance anchors' );
$expect( EqlNpcLootWikitext::itemSources( $item( '{{ItemWhereRow|[[Synthetic Vale]] [[Other Vale]]|[[False keeper]]|Area|()}}' ) ), [], 'An ambiguous structured zone cannot establish a source' );
$expect( EqlNpcLootWikitext::itemSources( $item( '{{Unknown|{{ItemWhereRow|[[Synthetic Vale]]|[[False keeper]]|Area|()}}}}' ) ), [], 'Unknown wrappers cannot promote a structured example into a source' );
$expect( EqlNpcLootWikitext::itemSources( $item( '{{ItemWhereTable|Empty table|{{ItemWhereRow|[[Synthetic Vale]]|[[False keeper]]|Area|()}}}}' ) ), [], 'Unused or ambiguous extra wrapper arguments cannot promote hidden structured examples' );
foreach ( [
	'An ordinary mention of [[A synthetic keeper]].',
	"<!-- [[Synthetic Vale]]\n* [[Fake keeper]] -->",
	"<nowiki>[[Synthetic Vale]]\n* [[Fake keeper]]</nowiki>",
	"{{Example|\n[[Synthetic Vale]]\n* [[Fake keeper]]\n}}",
	"<pre>[[Synthetic Vale]]\n* [[Fake keeper]]</pre>",
	"[[Synthetic Vale]]\nA passing mention of [[Fake keeper]].",
	"[[Synthetic Vale]]\n* [[Fake keeper]]\n[[Synthetic_Vale]]\n* [[Another keeper]]"
] as $drops ) {
	$expect( EqlNpcLootWikitext::itemSources( $item( $drops ) ), [], 'Protected, prose-only or ambiguous source sections are not evidence' );
}
$expect( EqlNpcLootWikitext::itemSources( $item( "[[Synthetic Vale]]\n* [[A synthetic keeper]]\n* [[A_synthetic_keeper]]" ) ), [ $row( 'A synthetic keeper', 'Synthetic Vale' ) ], 'Exact cleaned source titles dedupe within their context' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A Synthetic Mob', '[[Synthetic Vale]]', "* [[Synthetic Relic]]\n* [[Synthetic relic]]" ) )['authoredItems'], [ 'Synthetic Relic', 'Synthetic relic' ], 'Case-distinct authored item pages remain available for canonical identity checks' );
$expect( EqlNpcLootWikitext::itemSources( $item( "[[Synthetic Vale]]\n* [[A Synthetic Mob]]\n* [[A synthetic mob]]\n[[Synthetic vale]]\n* [[Another mob]]" ) ), [ $row( 'A Synthetic Mob', 'Synthetic Vale' ), $row( 'A synthetic mob', 'Synthetic Vale' ), $row( 'Another mob', 'Synthetic vale' ) ], 'Case-distinct NPC and zone page targets are not collapsed by forgiving comparison keys' );
foreach ( [ '{{Itempage|dropsfrom=[[Broken]]', $item( '[[A keeper]]' ) . $item( '[[Another keeper]]' ), '{{Itempage|dropsfrom=[[A keeper]]|dropsfrom=[[Another keeper]]}}', '{{Itempage|dropsfrom=[[A keeper]]|drops_from=[[Another keeper]]}}', str_repeat( 'x', 524289 ) ] as $source ) {
	$expect( EqlNpcLootWikitext::itemSources( $source ), [], 'Malformed, oversized or conflicting item templates fail closed' );
}
echo "NPC loot wikitext: {$checks} source-only checks passed.\n";
