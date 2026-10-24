<?php
declare( strict_types = 1 );

namespace EQLClientData;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Pure, versioned data-only admission. Does not establish wiki or drop facts. */
final class ObservationPayload {
    public const MAX_BYTES = 262144;
    public const MAX_EVENTS = 100;
    public const MAX_EVENT_BYTES = 4096;
    public const EVENT_FIELDS = [ 'id', 'kind', 'observedAt', 'server', 'zone', 'zoneTitle',
        'npc', 'npcTitle', 'item', 'itemTitle', 'quantity', 'tier', 'instance', 'sourceKind',
        'sourceToken', 'sessionToken', 'sequence', 'catalogVersion' ];

    private static function keys( array $value, array $allowed ): void {
        if ( array_diff( array_keys( $value ), $allowed ) ) {
            throw new InvalidArgumentException( 'unsupported-field' );
        }
    }
    private static function text( mixed $value, int $max = 230 ): string {
        if ( !is_string( $value ) || !preg_match( '//u', $value ) ||
            preg_match( '/[\x00-\x1f\x7f]/u', $value ) || mb_strlen( $value, 'UTF-8' ) > $max ) {
            throw new InvalidArgumentException( 'invalid-text' );
        }
        return trim( preg_replace( '/ +/u', ' ', $value ) );
    }
    public static function identifier( mixed $value ): string {
        if ( !is_string( $value ) || !preg_match( '/^[A-Za-z0-9_-]{8,120}$/D', $value ) ) {
            throw new InvalidArgumentException( 'invalid-id' );
        }
        return $value;
    }
    private static function optionalToken( mixed $value ): ?string {
        if ( $value === null || $value === '' ) { return null; }
        if ( !is_string( $value ) || !preg_match( '/^[A-Za-z0-9_-]{1,120}$/D', $value ) ) {
            throw new InvalidArgumentException( 'invalid-token' );
        }
        return $value;
    }
    private static function optionalTitle( mixed $value ): ?string {
        if ( $value === null || $value === '' ) { return null; }
        $title = str_replace( '_', ' ', self::text( $value ) );
        if ( $title === '' || preg_match( '/[\[\]{}<>|#~:]/u', $title ) || str_starts_with( $title, '.' ) ) {
            throw new InvalidArgumentException( 'invalid-title' );
        }
        return $title;
    }
    private static function canonical( mixed $value ): mixed {
        if ( !is_array( $value ) ) { return $value; }
        if ( !array_is_list( $value ) ) { ksort( $value, SORT_STRING ); }
        foreach ( $value as &$entry ) { $entry = self::canonical( $entry ); }
        return $value;
    }
    public static function hash( array $value ): string {
        return hash( 'sha256', json_encode( self::canonical( $value ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
    }
    public static function event( array $value, ?int $now = null ): array {
        self::keys( $value, self::EVENT_FIELDS );
        $id = self::identifier( $value['id'] ?? null );
        $kind = $value['kind'] ?? null;
        if ( !in_array( $kind, [ 'kill', 'loot' ], true ) ) { throw new InvalidArgumentException( 'invalid-kind' ); }
        $at = $value['observedAt'] ?? null;
        if ( !is_string( $at ) || !preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/D', $at ) ) {
            throw new InvalidArgumentException( 'invalid-time' );
        }
        try { $time = new DateTimeImmutable( $at, new DateTimeZone( 'UTC' ) ); }
        catch ( \Exception $error ) { throw new InvalidArgumentException( 'invalid-time', 0, $error ); }
        if ( $time->format( 'Y-m-d\TH:i:s' ) !== substr( $at, 0, 19 ) ||
            $time->getTimestamp() < 0 || $time->getTimestamp() > ( $now ?? time() ) + 300 ) {
            throw new InvalidArgumentException( 'invalid-time' );
        }
        $quantity = $value['quantity'] ?? 1;
        if ( !is_int( $quantity ) || $quantity < 1 || $quantity > 1000 || $kind === 'kill' && $quantity !== 1 ) {
            throw new InvalidArgumentException( 'invalid-quantity' );
        }
        $tier = $value['tier'] ?? null;
        if ( $tier !== null && ( !is_int( $tier ) || $tier < 0 || $tier > 10 ) ) {
            throw new InvalidArgumentException( 'invalid-tier' );
        }
        $instance = $value['instance'] ?? 'unknown';
        $source = $value['sourceKind'] ?? ( $kind === 'kill' ? 'kill-line' : 'unknown' );
        if ( !in_array( $instance, [ 'normal', 'raid', 'unknown' ], true ) ||
            !in_array( $source, [ 'kill-line', 'explicit-corpse', 'explicit-source', 'recent-kill', 'encounter-target', 'unknown' ], true ) ||
            $kind === 'kill' && $source !== 'kill-line' || $kind === 'loot' && $source === 'kill-line' ) {
            throw new InvalidArgumentException( 'invalid-provenance' );
        }
        $sequence = $value['sequence'] ?? null;
        if ( $sequence !== null && ( !is_int( $sequence ) || $sequence < 0 || $sequence > 9007199254740991 ) ) {
            throw new InvalidArgumentException( 'invalid-sequence' );
        }
        $npc = self::text( $value['npc'] ?? '' ); $item = self::text( $value['item'] ?? '' );
        if ( $kind === 'kill' && ( $npc === '' || $item !== '' ) || $kind === 'loot' && $item === '' ||
            in_array( $source, [ 'explicit-corpse', 'explicit-source', 'recent-kill', 'encounter-target' ], true ) && $npc === '' ) {
            throw new InvalidArgumentException( 'invalid-subject' );
        }
        $event = [ 'id' => $id, 'kind' => $kind, 'observedAt' => $time->format( 'Y-m-d\TH:i:s.v\Z' ),
            'server' => self::text( $value['server'] ?? '', 80 ),
            'zone' => self::text( $value['zone'] ?? '' ), 'zoneTitle' => self::optionalTitle( $value['zoneTitle'] ?? null ),
            'npc' => $npc, 'npcTitle' => self::optionalTitle( $value['npcTitle'] ?? null ),
            'item' => $item, 'itemTitle' => self::optionalTitle( $value['itemTitle'] ?? null ),
            'quantity' => $quantity, 'tier' => $tier, 'instance' => $instance, 'sourceKind' => $source,
            'sourceToken' => self::optionalToken( $value['sourceToken'] ?? null ),
            'sessionToken' => self::optionalToken( $value['sessionToken'] ?? null ),
            'sequence' => $sequence, 'catalogVersion' => self::text( $value['catalogVersion'] ?? '', 120 ) ];
        if ( strlen( json_encode( $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ) > self::MAX_EVENT_BYTES ) {
            throw new InvalidArgumentException( 'event-too-large' );
        }
        return $event;
    }
    public static function batch( string $json, ?int $now = null ): array {
        if ( strlen( $json ) > self::MAX_BYTES ) { throw new InvalidArgumentException( 'batch-too-large' ); }
        $input = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
        if ( !is_array( $input ) || array_is_list( $input ) ) { throw new InvalidArgumentException( 'invalid-batch' ); }
        self::keys( $input, [ 'schemaVersion', 'batchId', 'clientVersion', 'events' ] );
        if ( ( $input['schemaVersion'] ?? null ) !== 1 || !is_array( $input['events'] ?? null ) ||
            !array_is_list( $input['events'] ) || count( $input['events'] ) < 1 || count( $input['events'] ) > self::MAX_EVENTS ) {
            throw new InvalidArgumentException( 'invalid-batch' );
        }
        $id = self::identifier( $input['batchId'] ?? null );
        $version = self::text( $input['clientVersion'] ?? '', 40 );
        $events = []; $rejected = []; $seen = [];
        foreach ( $input['events'] as $raw ) {
            $eventId = null;
            try {
                if ( !is_array( $raw ) || array_is_list( $raw ) ) { throw new InvalidArgumentException( 'invalid-event' ); }
                $eventId = self::identifier( $raw['id'] ?? null );
                if ( isset( $seen[$eventId] ) ) { throw new InvalidArgumentException( 'duplicate-batch-id' ); }
                $seen[$eventId] = true;
                $events[] = self::event( $raw, $now );
            } catch ( InvalidArgumentException $error ) {
                if ( $error->getMessage() === 'duplicate-batch-id' ) { throw $error; }
                $rejected[] = [ 'id' => $eventId, 'code' => $error->getMessage() ];
            }
        }
        return [ 'schemaVersion' => 1, 'batchId' => $id, 'clientVersion' => $version,
            'events' => $events, 'rejected' => $rejected, 'payloadHash' => self::hash( $input ) ];
    }
}
