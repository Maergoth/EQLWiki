<?php
/** Public source-only typed field capability checks. No MediaWiki, HTTP, DB or writes. */
require __DIR__ . '/../extensions/DynamicZoneList/NpcLootWikitext.php';
$checks = 0;
$check = static function ( bool $yes, string $message ) use ( &$checks ): void {
    $checks++;
    if ( !$yes ) { throw new RuntimeException( $message ); }
};
$table = static fn ( string $rows ) => "{| class=\"zoneTopTable\"\n" . $rows . "\n|}";
$scope = static fn ( array $coverage, string $field, string $instance = 'normal' ) => array_values( array_filter( $coverage['scopes'], static fn ( $scope ) => $scope['field'] === $field && $scope['instance'] === $instance ) );
$zone = "Various merchants sell useful things.\n{| class=\"zoneOuter\"\n|\n" . $table( "! '''Unique Items:'''\n| {{:Synthetic Ring}}\n|-\n! Notable NPCs\n| [[Synthetic Keeper]]" ) . "\n|}\n{{Special:DynamicZoneList/Synthetic Vale}}";
$coverage = EqlNpcLootWikitext::contributionCoverage( $zone, 'zone' );
$check( $scope( $coverage, 'unique_items' )[0]['state'] === 'exact', 'Incidental Various narrative does not block a pre-existing nested formatted Unique Items cell' );
$check( $scope( $coverage, 'notable_npcs' )[0]['state'] === 'exact', 'Notable population is a separate pre-existing summary capability' );
$check( $coverage['dynamicLists'] === [ [ 'zone' => 'Synthetic Vale', 'instance' => 'normal', 'field' => 'dynamic_population' ] ], 'Dynamic population records the exact source category and instance' );
$check( count( $coverage['edges'] ) === 2, 'Only manual summary cells supply their own positive entries' );
$coverage = EqlNpcLootWikitext::contributionCoverage( "Sky quest progression mentions various rewards.\n{{Special:DynamicZoneList/Synthetic Sky}}", 'zone' );
$check( $scope( $coverage, 'unique_items' )[0]['reason'] === 'missing-section' && count( $coverage['dynamicLists'] ) === 1, 'An absent Unique Items cell skips only that optional summary while preserving ordinary dynamic population' );
$coverage = EqlNpcLootWikitext::contributionCoverage( $table( "! Unique Items\n| n/a" ) . "\n== Raid Instance ==\n" . $table( "! Unique Items\n| {{:Synthetic Raid Ring}}" ) . "\n{{Special:DynamicZoneList/Synthetic Raid Vale}}", 'zone' );
$check( $scope( $coverage, 'unique_items' )[0]['state'] === 'exact' && $scope( $coverage, 'unique_items', 'raid' )[0]['state'] === 'exact', 'Existing placeholder and raid summaries retain independent exact capabilities' );
$check( $coverage['dynamicLists'][0]['instance'] === 'raid' && $coverage['edges'][0]['instance'] === 'raid', 'Raid manual and dynamic references never leak into normal scope' );
$coverage = EqlNpcLootWikitext::contributionCoverage( $table( "! Unique Items\n| Various class armor" ), 'zone' );
$check( $scope( $coverage, 'unique_items' )[0]['state'] === 'broad', 'A broad affected summary cell is preserved without extending group membership' );
$coverage = EqlNpcLootWikitext::contributionCoverage( $table( "! Unique Items\n| {{:Synthetic Ring}}\n|-\n! Unique Items\n| {{:Other Ring}}" ), 'zone' );
$check( $scope( $coverage, 'unique_items' )[0]['state'] === 'variant', 'Duplicate affected summary cells cannot authorize an automatic edit' );
$coverage = EqlNpcLootWikitext::contributionCoverage( "{| class=\"outer\"\n" . $table( "! Unique Items\n| {{:Synthetic Ring}}" ), 'zone' );
$check( $scope( $coverage, 'unique_items' )[0]['state'] === 'unsupported', 'Unbalanced enclosing zone markup fails closed' );
$coverage = EqlNpcLootWikitext::contributionCoverage( '<nowiki>' . $zone . '</nowiki>', 'zone' );
$check( !$coverage['dynamicLists'] && $scope( $coverage, 'unique_items' )[0]['reason'] === 'missing-section', 'Protected examples never establish actual zone capabilities' );
echo "Typed zone relationship coverage: {$checks} source checks passed.\n";
