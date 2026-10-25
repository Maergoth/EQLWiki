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
$expect( EqlNpcLootWikitext::analyseNpcPage( $cards ), [ 'names' => [ 'A synthetic keeper', 'a SYNTHETIC keeper' ], 'zones' => [ 'Synthetic Vale', 'Other Vale' ], 'authoredItems' => [ 'Authored Gem', 'A false note', 'Authored Shield', 'Common Gem', 'Raid Gem', 'Not Loot' ] ], 'Every direct item reference within authored Common/Unique fields is available for canonical deduplication' );
foreach ( [ '<!-- ' . $manual . ' -->', '<nowiki>' . $manual . '</nowiki>', '{{Example|' . $manual . '}}', '{{Namedmobpage|name=Broken', 'Just prose', str_repeat( 'x', 524289 ) ] as $source ) {
	$expect( EqlNpcLootWikitext::analyseNpcPage( $source ), null, 'Only active balanced top-level NPC cards qualify' );
}
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', "<!-- * {{:Fake Gem}} -->\n<nowiki>* {{:Hidden Gem}}</nowiki>\n{{Example|\n* {{:Nested Gem}}\n}}\n* {{:Real Gem}}" ) )['authoredItems'], [ 'Real Gem' ], 'Protected and nested examples never suppress a real recovered item' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', '{{:One Gem}} {{:Two Gems}}' ) )['authoredItems'], [ 'One Gem', 'Two Gems' ], 'All direct authored transclusions are excluded, even on a single line' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', "{{{parameter|\n* {{:Nested Gem}}\n}}}\n* {{:Real Gem}}" ) )['authoredItems'], [ 'Real Gem' ], 'An unresolved nested parameter is not a directly authored loot row' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', '* <span class="ih">[[Wrapped Gem|Gem]]</span>' ) )['authoredItems'], [ 'Wrapped Gem' ], 'Hover-span item links are excluded regardless of their HTML wrapper' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', "* '''[[Bold Gem]]'''" ) )['authoredItems'], [ 'Bold Gem' ], 'Bold item links are excluded even when the link does not begin the row' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', '* [[First Gem]], [[Second Gem|Second]] and [[Third Gem#Details|Third]]' ) )['authoredItems'], [ 'First Gem', 'Second Gem', 'Third Gem' ], 'Every direct inline item link is excluded, including fragment links' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', '* [[:Colon_Gem|Gem]] / [[:Other Gem]]' ) )['authoredItems'], [ 'Colon Gem', 'Other Gem' ], 'An explicit leading namespace separator still addresses the item article' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', '* [[Alias Gem]] and [[Synthetic Gem]] / [[Synthetic gem]]', '<li><span class="ih">[[Common Alias Gem]]</span></li>' ) )['authoredItems'], [ 'Alias Gem', 'Synthetic Gem', 'Synthetic gem', 'Common Alias Gem' ], 'Preserve redirect spellings and real case-distinct titles from both authored loot fields' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', '<!-- [[Comment Gem]] --><nowiki>[[Quoted Gem]]</nowiki>{{Example|[[Nested Gem]]}} {{:Real Gem|unused=[[Unused Argument Gem]]}} [[:Category:Items]] [[File:Item_1.png]]' ) )['authoredItems'], [ 'Real Gem' ], 'Protected text, unknown template arguments, unused transclusion arguments and non-item namespaces stay excluded' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A real keeper', '[[Synthetic Vale]]', '[[Listed Gem]]' ) . "\nOutside the loot fields [[Outside Gem]] {{:Outside Card}}" )['authoredItems'], [ 'Listed Gem' ], 'Only the authored Common/Unique fields contribute exclusion references, never arbitrary page prose' );
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
	"[[Synthetic Vale]]\nA passing mention of [[Fake keeper]]."
] as $drops ) {
	$expect( EqlNpcLootWikitext::itemSources( $item( $drops ) ), [], 'Protected, prose-only or ambiguous source sections are not evidence' );
}
$expect( EqlNpcLootWikitext::itemSources( $item( "[[Synthetic Vale]]\n* [[A synthetic keeper]]\n* [[A_synthetic_keeper]]" ) ), [ $row( 'A synthetic keeper', 'Synthetic Vale' ) ], 'Exact cleaned source titles dedupe within their context' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $npc( 'A Synthetic Mob', '[[Synthetic Vale]]', "* [[Synthetic Relic]]\n* [[Synthetic relic]]" ) )['authoredItems'], [ 'Synthetic Relic', 'Synthetic relic' ], 'Case-distinct authored item pages remain available for canonical identity checks' );
$expect( EqlNpcLootWikitext::itemSources( $item( "[[Synthetic Vale]]\n* [[A Synthetic Mob]]\n* [[A synthetic mob]]\n[[Synthetic vale]]\n* [[Another mob]]" ) ), [ $row( 'A Synthetic Mob', 'Synthetic Vale' ), $row( 'A synthetic mob', 'Synthetic Vale' ), $row( 'Another mob', 'Synthetic vale' ) ], 'Case-distinct NPC and zone page targets are not collapsed by forgiving comparison keys' );
foreach ( [ '{{Itempage|dropsfrom=[[Broken]]', $item( '[[A keeper]]' ) . $item( '[[Another keeper]]' ), '{{Itempage|dropsfrom=[[A keeper]]|dropsfrom=[[Another keeper]]}}', '{{Itempage|dropsfrom=[[A keeper]]|drops_from=[[Another keeper]]}}', str_repeat( 'x', 524289 ) ] as $source ) {
	$expect( EqlNpcLootWikitext::itemSources( $source ), [], 'Malformed, oversized or conflicting item templates fail closed' );
}
$entry = static fn ( string $label, string $zone = 'Synthetic Vale', string $instance = 'normal', string $field = 'known_loot' ): array => [
	'markup' => '<li>{{:' . $label . '}} <small>source context</small></li>',
	'contexts' => [ [ 'zone' => $zone, 'instance' => $instance ] ], 'field' => $field
];
$oldList = '<ul class="authored"><li>{{:Original Gem}}</li></ul>';
$native = "Before card\n" . $npc( 'A synthetic keeper', '[[Synthetic Vale]]', $oldList, '* [[Original Common Gem]]' ) . "\nAfter card";
$merge = EqlNpcLootWikitext::mergeNpcLoot( $native, [ $entry( 'New Gem' ) ] );
$expect( $merge, [ 'text' => str_replace( '</ul>', "\n" . $entry( 'New Gem' )['markup'] . "\n</ul>", $native ), 'unplaced' => 0 ], 'Extend the existing native list without changing headings, common loot or surrounding source' );
$expect( EqlNpcLootWikitext::analyseNpcPage( $merge['text'] )['authoredItems'], [ 'Original Gem', 'New Gem', 'Original Common Gem' ], 'Generated items are inside known_loot rather than outside the native card' );
$missingField = '{{Namedmobpage|name=A synthetic keeper|zone=[[Synthetic Vale]]|common_loot=* [[Original Common Gem]]}}';
$merge = EqlNpcLootWikitext::mergeNpcLoot( $missingField, [ $entry( 'New Gem' ) ] );
$expect( str_starts_with( $merge['text'], substr( $missingField, 0, -2 ) ) && str_contains( $merge['text'], '|known_loot=' ) && $merge['unplaced'] === 0, true, 'Add a missing known_loot parameter without rewriting existing parameters' );
$emptyList = $npc( 'A synthetic keeper', '[[Synthetic Vale]]', '<ul><li>None</li></ul>' );
$expect( str_contains( EqlNpcLootWikitext::mergeNpcLoot( $emptyList, [ $entry( 'New Gem' ) ] )['text'], 'None' ), false, 'Remove an exact None placeholder only when adding native loot' );
$writtenNone = $npc( 'A synthetic keeper', '[[Synthetic Vale]]', 'None verified yet; review the original note.' );
$expect( str_contains( EqlNpcLootWikitext::mergeNpcLoot( $writtenNone, [ $entry( 'New Gem' ) ] )['text'], 'None verified yet; review the original note.' ), true, 'Keep original explanatory text that merely mentions None' );
$merge = EqlNpcLootWikitext::mergeNpcLoot( $native, [ $entry( 'New Common Gem', 'Synthetic Vale', 'normal', 'common_loot' ) ] );
$expect( str_contains( $merge['text'], '|known_loot=' . $oldList . "\n|common_loot=* [[Original Common Gem]]" ) && str_contains( $merge['text'], '{{:New Common Gem}}' ), true, 'Explicit common-loot metadata goes into common_loot without changing known_loot' );
$shared = $entry( 'Shared Gem' );
$shared['contexts'][] = [ 'zone' => 'Synthetic Vale', 'instance' => 'raid' ];
$merge = EqlNpcLootWikitext::mergeNpcLoot( $native, [ $shared ] );
$expect( [ substr_count( $merge['text'], $shared['markup'] ), $merge['unplaced'] ], [ 1, 0 ], 'A single shared card receives a multi-context row once' );
$normal = $npc( 'A synthetic keeper', '[[Synthetic Vale]]', '{{:Normal Authored Gem}}' );
$raid = $npc( 'A synthetic keeper', '[[Synthetic Vale]]', '{{:Raid Authored Gem}}' );
$split = $normal . "\n== Raid Instance ==\n" . $raid;
$merge = EqlNpcLootWikitext::mergeNpcLoot( $split, [ $entry( 'Normal New Gem' ), $entry( 'Raid New Gem', 'Synthetic Vale', 'raid' ) ] );
$halves = explode( '== Raid Instance ==', $merge['text'] );
$expect( [ str_contains( $halves[0], '{{:Normal New Gem}}' ), str_contains( $halves[0], '{{:Raid New Gem}}' ), str_contains( $halves[1], '{{:Raid New Gem}}' ), str_contains( $halves[1], '{{:Normal New Gem}}' ), $merge['unplaced'] ], [ true, false, true, false, 0 ], 'Normal and raid loot go into their corresponding native cards' );
$merge = EqlNpcLootWikitext::mergeNpcLoot( $split, [ $shared ] );
$expect( [ substr_count( $merge['text'], $shared['markup'] ), $merge['unplaced'] ], [ 2, 0 ], 'A multi-context row is shown once in each matching instance card' );
$onlyRaid = "== Raid Instance ==\n" . $raid;
$expect( EqlNpcLootWikitext::mergeNpcLoot( $onlyRaid, [ $entry( 'Normal New Gem' ) ] ), [ 'text' => $onlyRaid, 'unplaced' => 1 ], 'A sole explicitly scoped raid card must not receive normal loot' );
$merge = EqlNpcLootWikitext::mergeNpcLoot( $onlyRaid, [ $entry( 'Raid New Gem', 'Synthetic Vale', 'raid' ) ] );
$expect( [ str_contains( $merge['text'], '{{:Raid New Gem}}' ), $merge['unplaced'] ], [ true, 0 ], 'A sole explicitly scoped raid card receives matching raid loot' );
$zones = $normal . "\n" . $npc( 'A synthetic keeper', '[[Other Vale]]', '{{:Other Authored Gem}}' );
$merge = EqlNpcLootWikitext::mergeNpcLoot( $zones, [ $entry( 'Other New Gem', 'Other Vale' ) ] );
$expect( [ strpos( $merge['text'], '{{:Other New Gem}}' ) > strpos( $merge['text'], '[[Other Vale]]' ), $merge['unplaced'] ], [ true, 0 ], 'Multiple cards with the same instance are matched by their explicit source zone' );
$ambiguous = $normal . "\n" . $normal;
$expect( EqlNpcLootWikitext::mergeNpcLoot( $ambiguous, [ $entry( 'Unplaced Gem' ) ] ), [ 'text' => $ambiguous, 'unplaced' => 1 ], 'Ambiguous duplicate cards never receive arbitrarily placed loot' );
$expect( EqlNpcLootWikitext::mergeNpcLoot( $zones, [ $entry( 'Unplaced Gem', 'Unknown Vale' ) ] ), [ 'text' => $zones, 'unplaced' => 1 ], 'An explicit conflicting zone does not force a row into an unrelated card' );
$withoutZones = '{{Namedmobpage|name=Normal keeper}}' . "\n== Raid Instance ==\n{{Namedmobpage|name=Raid keeper}}";
$merge = EqlNpcLootWikitext::mergeNpcLoot( $withoutZones, [ $entry( 'Raid New Gem', 'Synthetic Vale', 'raid' ) ] );
$expect( [ strpos( $merge['text'], '{{:Raid New Gem}}' ) > strpos( $merge['text'], 'Raid Instance' ), $merge['unplaced'] ], [ true, 0 ], 'A unique card without a zone can receive a verified row from its matching instance' );
$commented = '{{Namedmobpage<!-- |fake=comment -->|name=A keeper|known_loot={{:Original Gem|unused=1}}<!-- |keep=this -->|common_loot=unchanged}}';
$merge = EqlNpcLootWikitext::mergeNpcLoot( $commented, [ $entry( 'New Gem' ) ] );
$expect( str_contains( $merge['text'], '{{:Original Gem|unused=1}}<!-- |keep=this -->' ) && str_contains( $merge['text'], '<!-- |fake=comment -->' ) && str_contains( $merge['text'], '|common_loot=unchanged' ), true, 'Insertion offsets preserve comments and nested argument delimiters exactly' );
foreach ( [ 'No active NPC card.', '<nowiki>' . $normal . '</nowiki>', '{{Namedmobpage|name=Broken', '{{Namedmobpage|known_loot=One|known_loot=Two}}' ] as $invalid ) {
	$expect( EqlNpcLootWikitext::mergeNpcLoot( $invalid, [ $entry( 'Unplaced Gem' ) ] ), [ 'text' => $invalid, 'unplaced' => 1 ], 'Inactive, malformed or conflicting cards remain byte-for-byte unchanged' );
}
$expect( EqlNpcLootWikitext::analyseItemPage( $item( '' ) ), [ 'names' => [ 'Synthetic Gem' ], 'zones' => [], 'existingSources' => [] ], 'A newly created item with no sources still qualifies for reciprocal discovery' );
$existing = $item( "[[Synthetic Vale]]\n* [[Old keeper]]" );
$expect( EqlNpcLootWikitext::analyseItemPage( $existing ), [ 'names' => [ 'Synthetic Gem' ], 'zones' => [ 'Synthetic Vale' ], 'existingSources' => [ $row( 'Old keeper', 'Synthetic Vale' ) ] ], 'Item analysis preserves authored sources and their declared zones' );
$expect( EqlNpcLootWikitext::analyseItemPage( '<nowiki>' . $item( '' ) . '</nowiki>' ), null, 'Quoted item examples do not activate reciprocal discovery' );
$lootRow = static fn ( string $itemName, string $npcName, string $zoneName, string $instance = 'normal', string $kind = 'known' ): array => [ 'item' => $itemName, 'npc' => $npcName, 'zone' => $zoneName, 'instance' => $instance, 'lootKind' => $kind ];
$npcFirst = $npc( 'Old keeper', '[[Synthetic Vale]]', '* <span class="ih">[[Missing Gem]]</span>', '* {{:Common Missing Gem}}' );
$expect( EqlNpcLootWikitext::npcLootSources( $npcFirst ), [ $lootRow( 'Missing Gem', 'Old keeper', 'Synthetic Vale' ), $lootRow( 'Common Missing Gem', 'Old keeper', 'Synthetic Vale', 'normal', 'common' ) ], 'An older manual mob exposes both native loot kinds for an item created later' );
$expect( EqlNpcLootWikitext::npcLootSources( "== Raid Instance ==\n" . $npc( 'Raid keeper', '[[Synthetic Vale]] / [[Other Vale]]', '* {{VeliousGray|{{:Missing Gem}}}}' ) ), [ $lootRow( 'Missing Gem', 'Raid keeper', 'Synthetic Vale', 'raid' ), $lootRow( 'Missing Gem', 'Raid keeper', 'Other Vale', 'raid' ) ], 'Reciprocal NPC evidence keeps every declared zone and the outer instance' );
$expect( EqlNpcLootWikitext::npcLootSources( '{{Namedmobpage|known_loot=[[Missing Gem]]}}' ), [ $lootRow( 'Missing Gem', '', '' ) ], 'Absent NPC and zone literals remain blank for canonical service title fallback' );
$expect( EqlNpcLootWikitext::npcLootSources( '{{Namedmobpage|known_loot=* [[Missing Gem]], [[Second Gem]] (for [[Research]])}}' ), [ $lootRow( 'Missing Gem', '', '' ), $lootRow( 'Second Gem', '', '' ) ], 'Literal inline loot groups stop before incidental parenthetical links' );
$expect( EqlNpcLootWikitext::npcLootSources( $npc( 'Old keeper', '[[Synthetic Vale]]', '<nowiki>[[Quoted Gem]]</nowiki>{{Example|{{:Nested Gem}}}} [[Actual Gem]]' ) . '\nOutside [[Outside Gem]]' ), [ $lootRow( 'Actual Gem', 'Old keeper', 'Synthetic Vale' ) ], 'Protected examples and prose outside native loot fields cannot establish reciprocal item evidence' );
$merge = EqlNpcLootWikitext::mergeItemSources( $existing, [ $row( 'New keeper', 'Synthetic Vale' ), $row( 'Raid keeper', 'Synthetic Vale', 'raid' ) ] );
$expect( str_starts_with( $merge['text'], substr( $existing, 0, strpos( $existing, '}}</onlyinclude>' ) ) ) && $merge['unplaced'] === 0, true, 'Reciprocal item source insertion preserves the original card and authored drop list prefix' );
$expect( EqlNpcLootWikitext::itemSources( $merge['text'] ), [ $row( 'Old keeper', 'Synthetic Vale' ), $row( 'New keeper', 'Synthetic Vale' ), $row( 'Raid keeper', 'Synthetic Vale', 'raid' ) ], 'An unheaded shared item card preserves old sources and displays later normal and raid groups distinctly' );
$merge = EqlNpcLootWikitext::mergeItemSources( $existing, [ $row( 'Keeper with unknown zone', '' ) ] );
$expect( EqlNpcLootWikitext::itemSources( $merge['text'] ), [ $row( 'Old keeper', 'Synthetic Vale' ), $row( 'Keeper with unknown zone', '' ) ], 'An explicit reciprocal instance group with no declared zone does not inherit the previous source zone' );
$missingSources = '<onlyinclude>{{Itempage|itemname=New Gem|statsblock=HP: 5}}</onlyinclude>';
$merge = EqlNpcLootWikitext::mergeItemSources( $missingSources, [ $row( 'Old keeper', 'Synthetic Vale' ) ] );
$expect( [ EqlNpcLootWikitext::itemSources( $merge['text'] ), str_contains( $merge['text'], '|statsblock=HP: 5' ), $merge['unplaced'] ], [ [ $row( 'Old keeper', 'Synthetic Vale' ) ], true, 0 ], 'A new Itempage can receive its first dropsfrom field without changing stats' );
$itemNormal = $item( "[[Synthetic Vale]]\n* [[Old normal keeper]]" );
$itemRaid = $item( "[[Synthetic Vale]]\n* [[Old raid keeper]]" );
$itemSplit = $itemNormal . "\n== Raid Instance ==\n" . $itemRaid;
$merge = EqlNpcLootWikitext::mergeItemSources( $itemSplit, [ $row( 'New normal keeper', 'Synthetic Vale' ), $row( 'New raid keeper', 'Synthetic Vale', 'raid' ) ] );
$expect( EqlNpcLootWikitext::itemSources( $merge['text'] ), [ $row( 'Old normal keeper', 'Synthetic Vale' ), $row( 'New normal keeper', 'Synthetic Vale' ), $row( 'Old raid keeper', 'Synthetic Vale', 'raid' ), $row( 'New raid keeper', 'Synthetic Vale', 'raid' ) ], 'Separate native item instance cards receive only their corresponding reciprocal mob sources' );
$itemOnlyRaid = "== Raid Instance ==\n" . $itemRaid;
$expect( EqlNpcLootWikitext::mergeItemSources( $itemOnlyRaid, [ $row( 'Wrong normal keeper', 'Synthetic Vale' ) ] ), [ 'text' => $itemOnlyRaid, 'unplaced' => 1 ], 'Explicitly scoped raid item cards reject normal reciprocal sources' );
$conflictingFields = '{{Itempage|itemname=New Gem|dropsfrom=[[Old keeper]]|drops_from=[[Other keeper]]}}';
$expect( EqlNpcLootWikitext::mergeItemSources( $conflictingFields, [ $row( 'New keeper', 'Synthetic Vale' ) ] ), [ 'text' => $conflictingFields, 'unplaced' => 1 ], 'Conflicting source aliases remain untouched instead of adding to an arbitrary field' );
$aliasField = '{{Itempage|itemname=New Gem|drops_from=}}';
$merge = EqlNpcLootWikitext::mergeItemSources( $aliasField, [ $row( 'New keeper', 'Synthetic Vale' ) ] );
$expect( [ str_contains( $merge['text'], '|dropsfrom=' ), EqlNpcLootWikitext::itemSources( $merge['text'] ) ], [ false, [ $row( 'New keeper', 'Synthetic Vale' ) ] ], 'An existing historical drops_from field is extended without creating a second alias' );
$structuredItem = $item( '{{ItemWhereTable|{{ItemWhereRow|[[Synthetic Vale]]|[[Old keeper]]|Area|{{Loc|Synthetic Vale|1,2,3|Spawn}}}}}}' );
$merge = EqlNpcLootWikitext::mergeItemSources( $structuredItem, [ $row( 'New keeper', 'Synthetic Vale' ) ] );
$expect( [ str_contains( $merge['text'], '{{ItemWhereTable|{{ItemWhereRow|[[Synthetic Vale]]|[[Old keeper]]|Area|{{Loc|Synthetic Vale|1,2,3|Spawn}}}}}}' ), EqlNpcLootWikitext::itemSources( $merge['text'] ) ], [ true, [ $row( 'Old keeper', 'Synthetic Vale' ), $row( 'New keeper', 'Synthetic Vale' ) ] ], 'Existing structured source tables and location arguments stay unchanged while reciprocal rows are added' );
$ambiguousItems = $itemNormal . "\n" . $itemNormal;
$expect( EqlNpcLootWikitext::mergeItemSources( $ambiguousItems, [ $row( 'New keeper', 'Synthetic Vale' ) ] ), [ 'text' => $ambiguousItems, 'unplaced' => 1 ], 'Ambiguous same-instance item cards do not receive guessed reciprocal rows' );
$expect( EqlNpcLootWikitext::mergeItemSources( $missingSources, [ $row( 'Unsafe|name', 'Synthetic Vale' ) ] ), [ 'text' => $missingSources, 'unplaced' => 1 ], 'Unsafe literal source titles cannot inject item parameters' );
$expect( [ substr_count( $merge = EqlNpcLootWikitext::mergeItemSources( $existing, [ $row( 'New keeper', 'Synthetic Vale' ) ] )['text'], '[[Synthetic Vale]]' ), substr_count( $merge, 'Normal Instance' ) ], [ 1, 0 ], 'A matching simple source list is extended without another zone or instance heading' );
$expect( EqlNpcLootWikitext::mergeItemSources( $merge, [ $row( 'Old keeper', 'Synthetic Vale' ), $row( 'New keeper', 'Synthetic Vale' ) ] ), [ 'text' => $merge, 'unplaced' => 0 ], 'Source merging is idempotent even when the caller repeats already authored rows' );
$authored = $item( "Lead prose remains.\n[[Alias Vale]]\n* [[Old keeper]]<!-- preserve -->\n\nTrailing authored prose.\n=== Raid Instance ===\n[[Alias Vale]]\n* [[Raid old keeper]]\n" );
$merge = EqlNpcLootWikitext::mergeItemSources( $authored, [ $row( 'New keeper', 'Canonical Vale' ), $row( 'Raid new keeper', 'Canonical Vale', 'raid' ), $row( 'Other keeper', 'Other Vale' ) ], [ 'Alias Vale' => 'Canonical Vale' ] );
$expect( [ substr_count( $merge['text'], '[[Alias Vale]]' ), substr_count( $merge['text'], '[[Canonical Vale]]' ), substr_count( $merge['text'], 'Raid Instance' ), substr_count( $merge['text'], 'Normal Instance' ) ], [ 2, 0, 1, 0 ], 'Verified canonical aliases reuse authored zone labels separately in the existing normal and raid lists' );
$expect( str_contains( $merge['text'], '* [[Old keeper]]<!-- preserve -->' ) && str_contains( $merge['text'], 'Trailing authored prose.' ) && strpos( $merge['text'], '[[Other Vale]]' ) < strpos( $merge['text'], 'Raid Instance' ), true, 'New normal zones stay before the authored raid boundary and surrounding prose/comments survive exactly' );
$expect( EqlNpcLootWikitext::mergeItemSources( $authored, [ $row( 'Old keeper', 'Canonical Vale' ) ], [ 'Alias Vale' => 'Canonical Vale' ] ), [ 'text' => $authored, 'unplaced' => 0 ], 'A canonical zone proof also deduplicates an already authored NPC alias-zone row' );
$merge = EqlNpcLootWikitext::mergeItemSources( $existing, [ $row( 'Known keeper', 'Other Vale' ), $row( 'Unknown keeper', '' ) ] );
$expect( EqlNpcLootWikitext::itemSources( $merge['text'] ), [ $row( 'Old keeper', 'Synthetic Vale' ), $row( 'Unknown keeper', '' ), $row( 'Known keeper', 'Other Vale' ) ], 'Unknown-zone references are emitted before new zone headings so they cannot inherit a different zone' );
$duplicateZones = $item( "[[Synthetic Vale]]\n* [[Old keeper]]\n[[Synthetic Vale]]\n* [[Other keeper]]" );
$expect( EqlNpcLootWikitext::mergeItemSources( $duplicateZones, [ $row( 'New keeper', 'Synthetic Vale' ) ] ), [ 'text' => $duplicateZones, 'unplaced' => 1 ], 'Ambiguous duplicate authored zone lists are preserved with an explicit unplaced result instead of adding another block' );
$unscoped = $item( "* [[Unscoped keeper]]\n" );
$merge = EqlNpcLootWikitext::mergeItemSources( $unscoped, [ $row( 'New keeper', 'Synthetic Vale' ) ] );
$expect( EqlNpcLootWikitext::itemSources( $merge['text'] ), [ $row( 'Unscoped keeper', '' ), $row( 'New keeper', 'Synthetic Vale' ) ], 'Adding the first zone preserves the existing unscoped NPC list instead of reinterpreting or losing it' );
$explicitUnscoped = $item( "=== Normal Instance ===\n* [[Unscoped keeper]]\n" );
$merge = EqlNpcLootWikitext::mergeItemSources( $explicitUnscoped, [ $row( 'New keeper', 'Synthetic Vale' ) ] );
$expect( [ substr_count( $merge['text'], 'Normal Instance' ), EqlNpcLootWikitext::itemSources( $merge['text'] ) ], [ 1, [ $row( 'Unscoped keeper', '' ), $row( 'New keeper', 'Synthetic Vale' ) ] ], 'An existing explicit unscoped instance boundary is reused without another heading' );

$bareUnscoped = $item( '[[Unscoped keeper]]' );
$expect( EqlNpcLootWikitext::mergeItemSources( $bareUnscoped, [ $row( 'New keeper', 'Synthetic Vale' ) ] ), [ 'text' => $bareUnscoped, 'unplaced' => 1 ], 'A bare unscoped authored link is not silently reinterpreted as a new zone heading' );

echo "NPC loot wikitext: {$checks} source-only checks passed.\n";
