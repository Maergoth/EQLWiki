<?php
/** Pure, bounded replacement of the one authored Help:Contents zone blueprint. */
final class EQLZonePageBlueprint {
    public const MARKER = '<!-- EQL-ZONE-BLUEPRINT:1 -->';
    public const RAID_BEGIN = '<!-- EQL-ZONE-RAID:BEGIN -->';
    public const RAID_END = '<!-- EQL-ZONE-RAID:END -->';

    public static function replaceSection( string $old, string $reviewed ): string {
        self::validateSource( $reviewed );
        $headings = self::headings( $old );
        $targets = array_keys( array_filter( $headings, static fn ( $h ) => $h['level'] === 3 && $h['name'] === 'Zone Page Blueprint' ) );
        if ( count( $targets ) !== 1 ) { throw new RuntimeException( 'Expected exactly one active Zone Page Blueprint heading.' ); }
        $index = $targets[0];
        $end = null;
        for ( $i = $index + 1; $i < count( $headings ); $i++ ) {
            if ( $headings[$i]['level'] <= 3 ) {
                if ( $headings[$i]['level'] !== 3 || $headings[$i]['name'] !== 'City Page Blueprint' ) {
                    throw new RuntimeException( 'Expected the reviewed City Page Blueprint boundary after the zone blueprint.' );
                }
                $end = $headings[$i]['start']; break;
            }
        }
        if ( $end === null ) { throw new RuntimeException( 'Missing reviewed City Page Blueprint boundary; refusing an unbounded replacement.' ); }
        return substr( $old, 0, $headings[$index]['start'] ) . rtrim( $reviewed ) . "\n\n" . substr( $old, $end );
    }

    public static function validateSource( string $source ): void {
        $headings = self::headings( $source );
        if ( count( $headings ) !== 1 || $headings[0]['level'] !== 3 || $headings[0]['name'] !== 'Zone Page Blueprint' || $headings[0]['start'] !== 0 ) {
            throw new RuntimeException( 'Reviewed source must contain only the Zone Page Blueprint section.' );
        }
        if ( substr_count( $source, '<pre>' ) !== 1 || substr_count( $source, '</pre>' ) !== 1 ||
            !preg_match( '~<pre>([\s\S]*?)</pre>\s*$~', $source, $match ) ) {
            throw new RuntimeException( 'Reviewed section must end with exactly one literal blueprint pre block.' );
        }
        $blueprint = $match[1];
        foreach ( [ self::MARKER, self::RAID_BEGIN, self::RAID_END, '== Map ==', '== Raid Map ==', '= Raid Instance =', '@@DYNAMIC_ZONE_LIST@@' ] as $marker ) {
            if ( substr_count( $blueprint, $marker ) !== 1 ) { throw new RuntimeException( 'Missing or ambiguous blueprint contract: ' . $marker ); }
        }
        if ( !str_starts_with( $blueprint, self::MARKER . "\n" ) ||
            !preg_match( '/^<!-- EQL-ZONE-RAID:BEGIN -->\n= Raid Instance =[\s\S]+^<!-- EQL-ZONE-RAID:END -->$/m', $blueprint ) ||
            substr_count( $blueprint, 'class="zoneTopTable"' ) !== 2 ) {
            throw new RuntimeException( 'Normal/raid overview or optional-block boundaries are invalid.' );
        }
        $fields = [ 'OVERVIEW', 'LEVEL_RANGE', 'MIN_PLAYER_LEVEL', 'MONSTER_TYPES', 'NOTABLE_NPCS', 'COMMON_LOOT',
            'UNIQUE_ITEMS', 'RELATED_QUESTS', 'ADJACENT_ZONES', 'WHO_NAME', 'RESPAWN_TIME', 'SUCCOR', 'ZEM', 'MAP_IMAGE', 'MAP_NOTES' ];
        $expected = [ 'ERA', 'ZONE_NAME', 'RAID_AVAILABILITY', 'DYNAMIC_ZONE_LIST' ];
        foreach ( $fields as $field ) { $expected[] = $field; $expected[] = 'RAID_' . $field; }
        preg_match_all( '/@@([A-Z_]+)@@/', $blueprint, $tokens );
        $actual = array_values( array_unique( $tokens[1] ) ); sort( $actual ); sort( $expected );
        if ( $actual !== $expected ) { throw new RuntimeException( 'Unsupported or missing version-one blueprint token.' ); }
    }

    private static function headings( string $source ): array {
        if ( strlen( $source ) > 2 * 1024 * 1024 || str_contains( $source, "\0" ) || preg_match( '//u', $source ) !== 1 ) {
            throw new RuntimeException( 'Invalid or oversized blueprint wikitext.' );
        }
        $mask = static fn ( $m ) => preg_replace( '/[^\r\n]/', ' ', $m[0] );
        $active = preg_replace_callback( '/<!--[\s\S]*?(?:-->|$)/', $mask, $source );
        $active = preg_replace_callback( '~<(pre|nowiki|source|syntaxhighlight)\b[^>]*>[\s\S]*?(?:</\1\s*>|$)~i', $mask, $active );
        preg_match_all( '/^(={1,6})[ \t]*(.*?)[ \t]*\1[ \t]*\r?$/m', $active, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
        return array_map( static fn ( $m ) => [ 'level' => strlen( $m[1][0] ), 'name' => trim( $m[2][0] ), 'start' => $m[0][1] ], $matches );
    }
}
