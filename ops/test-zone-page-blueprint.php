<?php
/** Source-only blueprint/section contracts; no wiki, HTTP, database or writes. */
require __DIR__ . '/ZonePageBlueprint.php';
$n = 0;
$check = static function ( $yes, $message ) use ( &$n ) { $n++; if ( !$yes ) { throw new RuntimeException( $message ); } };
$refuses = static function ( $fn ) use ( $check ) { try { $fn(); } catch ( RuntimeException $e ) { $check( true, 'Expected refusal' ); return; } $check( false, 'Expected refusal' ); };
$source = file_get_contents( __DIR__ . '/zone-page-blueprint.wiki' );
EQLZonePageBlueprint::validateSource( $source );
$prefix = "= Help =\nIntro unchanged.\n=== NPC Page Blueprint ===\n<pre>\n=== Zone Page Blueprint ===\nFake protected example.\n</pre>\n== Zones ==\n";
$suffix = "=== City Page Blueprint ===\n<pre>City untouched.</pre>\n=== Loc Mapping ===\nCoordinates untouched.\n== Class Pages ==\n<pre>Class untouched.</pre>\n";
$old = $prefix . "=== Zone Page Blueprint ===\nOld zone introduction.\n<pre>\n= Example =\nOld zone markup.\n</pre>\n\n" . $suffix;
$updated = EQLZonePageBlueprint::replaceSection( $old, $source );
$check( str_starts_with( $updated, $prefix ), 'All earlier help and protected example bytes remain unchanged' );
$check( str_ends_with( $updated, $suffix ), 'Every later city, mapping and class blueprint byte remains unchanged' );
$check( EQLZonePageBlueprint::replaceSection( $updated, $source ) === $updated, 'Replacing an already-current section is idempotent' );
$refuses( fn () => EQLZonePageBlueprint::replaceSection( $old . "\n=== Zone Page Blueprint ===\nDuplicate", $source ) );
$refuses( fn () => EQLZonePageBlueprint::replaceSection( 'No active heading.', $source ) );
$refuses( fn () => EQLZonePageBlueprint::replaceSection( str_replace( '=== City Page Blueprint ===', '== Unexpected section ==', $old ), $source ) );
$refuses( fn () => EQLZonePageBlueprint::replaceSection( str_replace( "</pre>\n\n=== City", "\n\n=== City", $old ), $source ) );
foreach ( [
 str_replace( EQLZonePageBlueprint::MARKER, '', $source ),
 str_replace( '== Map ==', '', $source ),
 str_replace( EQLZonePageBlueprint::RAID_END, '', $source ),
 str_replace( '@@WHO_NAME@@', '@@UNKNOWN_TOKEN@@', $source ),
 $source . "\n=== City Page Blueprint ===\nShould not replace cities."
] as $broken ) { $refuses( fn () => EQLZonePageBlueprint::validateSource( $broken ) ); }
preg_match( '~<pre>([\s\S]*?)</pre>~', $source, $match );
$blueprint = $match[1];
$raidPattern = '~<!-- EQL-ZONE-RAID:BEGIN -->[\s\S]*?<!-- EQL-ZONE-RAID:END -->~';
$normal = preg_replace( $raidPattern, '', $blueprint );
$check( !str_contains( $normal, '= Raid Instance =' ) && substr_count( $normal, 'class="zoneTopTable"' ) === 1, 'No/unknown raid availability removes only the optional raid scaffold' );
$check( str_contains( $normal, '== Map ==' ) && str_contains( $normal, 'Notable NPCs:' ) && str_contains( $normal, 'Unique Items:' ), 'Normal overview and map remain even without known details' );
$raid = str_replace( [ EQLZonePageBlueprint::RAID_BEGIN, EQLZonePageBlueprint::RAID_END ], '', $blueprint );
$at = strpos( $raid, '= Raid Instance =' );
$check( substr_count( $raid, '= Raid Instance =' ) === 1 && str_contains( substr( $raid, $at ), '== Raid Map ==' ), 'Confirmed raid has exactly one final toggle-compatible heading and its own map' );
$check( str_contains( substr( $raid, 0, $at ), '@@DYNAMIC_ZONE_LIST@@' ) && !str_contains( substr( $raid, $at ), '@@DYNAMIC_ZONE_LIST@@' ), 'A general category list is never presented as a filtered raid list' );
$check( str_contains( $blueprint, '@@COMMON_LOOT@@' ) && str_contains( $blueprint, '@@UNIQUE_ITEMS@@' ), 'Common and Unique summaries have separate reviewed inputs' );
echo "Zone blueprint section and instance contracts: {$n} checks passed.\n";
