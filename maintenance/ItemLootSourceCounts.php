<?php
/** Pure global authored-loot aggregation for the static client pack. No I/O. */
require_once __DIR__ . '/../extensions/DynamicZoneList/NpcLootWikitext.php';

final class EqlItemLootSourceCounts {
    private array $direct = [];
    private array $references = [];
    private array $missingItems = [];
    private bool $complete = true;

    public function __construct( private int $maxReferences = 200000, private int $maxNpcs = 1000 ) {}

    public function incomplete(): void { $this->complete = false; }

    public function item( string $title, string $text ): void {
        $parsed = true;
        $analysis = EqlNpcLootWikitext::analyseItemPage( $text, $parsed, true );
        $this->direct[$title] = [ 'complete' => $parsed && $analysis !== null,
            'names' => array_column( $analysis['existingSources'] ?? [], 'npc' ) ];
    }

    public function npc( string $title, string $text ): void {
        $parsed = true;
        $text = preg_replace( '/\{\{\s*(?:NPCpage|MerchantPage)\b/i', '{{Namedmobpage', $text );
        $text = preg_replace( '/\|\s*(knownloot|commonloot)\s*=/i', '|$1=', $text );
        $text = preg_replace( '/\|knownloot=/i', '|known_loot=', $text );
        $text = preg_replace( '/\|commonloot=/i', '|common_loot=', $text );
        $rows = EqlNpcLootWikitext::npcLootSources( $text, $parsed, true );
        if ( !$parsed ) { $this->incomplete(); }
        foreach ( $rows as $row ) {
            $key = $row['item'] . "\0" . $title;
            if ( !isset( $this->references[$key] ) && count( $this->references ) >= $this->maxReferences ) {
                $this->incomplete(); break;
            }
            // The canonical article, not a card display name, identifies a mob.
            $this->references[$key] = [ 'item' => $row['item'], 'npc' => $title ];
        }
    }

    /**
     * $resolve is a public-only canonical resolver returning title, pageId,
     * public and complete. A public redlink has pageId=null and complete=true.
     */
    public function apply( array $items, callable $resolve ): array {
        $catalog = $this->catalog( $resolve );
        foreach ( $items as &$item ) { $item = array_replace( $item, $catalog[$item['wikiTitle']] ?? [ 'lootSourceCount' => 0, 'lootSourceCountComplete' => false, 'lootSourceNpcs' => [] ] ); }
        unset( $item );
        return $items;
    }

    /** Includes statless categorized items and public NPC references to missing items. */
    public function catalog( callable $resolve ): array {
        $this->missingItems = [];
        $titles = array_fill_keys( array_keys( $this->direct ), true );
        $folded = [];
        foreach ( $this->direct as $title => $_ ) { $folded[EqlNpcLootWikitext::identity( $title )][] = $title; }
        foreach ( $this->references as $reference ) {
            try { $target = $resolve( $reference['item'] ); }
            catch ( Throwable $error ) { $this->incomplete(); continue; }
            if ( !( $target['complete'] ?? false ) || !( $target['public'] ?? false ) ) { $this->incomplete(); continue; }
            $title = $target['title'];
            if ( !( $target['pageId'] ?? null ) ) {
                $matches = $folded[EqlNpcLootWikitext::identity( $title )] ?? [];
                if ( count( $matches ) === 1 ) { $title = $matches[0]; }
                elseif ( count( $matches ) > 1 ) { $this->incomplete(); continue; }
                $this->missingItems[$title] = true;
            }
            $titles[$title] = true;
        }
        $items = array_map( static fn ( $title ) => [ 'wikiTitle' => $title ], array_keys( $titles ) );
        $catalog = [];
        foreach ( $this->aggregate( $items, $resolve ) as $item ) { $title = $item['wikiTitle']; unset( $item['wikiTitle'] ); $catalog[$title] = $item; }
        ksort( $catalog, SORT_STRING );
        return $catalog;
    }

    private function aggregate( array $items, callable $resolve ): array {
        $index = [];
        $rows = [];
        $complete = [];
        $folded = [];
        foreach ( $items as $i => $item ) {
            $title = $item['wikiTitle'];
            $index[$title] = $i;
            $folded[EqlNpcLootWikitext::identity( $title )][] = $i;
            $rows[$i] = [];
            $complete[$i] = $this->direct[$title]['complete'] ?? isset( $this->missingItems[$title] );
        }
        $add = function ( int $i, string $name ) use ( &$rows, &$complete, $resolve ): void {
            if ( self::nonMonsterSource( $name ) ) { return; }
            try { $npc = $resolve( $name ); } catch ( Throwable $error ) { $complete[$i] = false; return; }
            if ( !( $npc['complete'] ?? false ) || !( $npc['public'] ?? false ) ) { $complete[$i] = false; return; }
            if ( self::nonMonsterSource( $npc['title'] ) ) { return; }
            $id = $npc['pageId'] ?? null;
            $key = $id ? 'id:' . $id : 'missing:' . EqlNpcLootWikitext::identity( $npc['title'] );
            if ( !isset( $rows[$i][$key] ) && count( $rows[$i] ) >= $this->maxNpcs ) { $complete[$i] = false; return; }
            $rows[$i][$key] = [ 'title' => $npc['title'], 'pageId' => $id,
                'spellings' => array_values( array_unique( array_merge( $rows[$i][$key]['spellings'] ?? [],
                    [ EqlNpcLootWikitext::identity( $name ), EqlNpcLootWikitext::identity( $npc['title'] ) ] ) ) ) ];
        };
        foreach ( $items as $i => $item ) {
            foreach ( $this->direct[$item['wikiTitle']]['names'] ?? [] as $name ) { $add( $i, $name ); }
        }
        foreach ( $this->references as $reference ) {
            try { $target = $resolve( $reference['item'] ); }
            catch ( Throwable $error ) { $this->incomplete(); continue; }
            if ( !( $target['complete'] ?? false ) || !( $target['public'] ?? false ) ) { $this->incomplete(); continue; }
            $i = $index[$target['title']] ?? null;
            // Missing case variants can refer to one known title; two real
            // case-distinct pages are never merged by their display spelling.
            if ( $i === null && !( $target['pageId'] ?? null ) ) {
                $matches = $folded[EqlNpcLootWikitext::identity( $target['title'] )] ?? [];
                if ( count( $matches ) === 1 ) { $i = $matches[0]; }
                elseif ( count( $matches ) > 1 ) { $this->incomplete(); }
            }
            if ( $i !== null ) { $add( $i, $reference['npc'] ); }
        }
        foreach ( $items as $i => &$item ) {
            $knownSpellings = [];
            foreach ( $rows[$i] as $row ) {
                if ( $row['pageId'] ) { foreach ( $row['spellings'] as $spelling ) { $knownSpellings[$spelling] = true; } }
            }
            $names = [];
            foreach ( $rows[$i] as $row ) {
                if ( !$row['pageId'] && isset( $knownSpellings[EqlNpcLootWikitext::identity( $row['title'] )] ) ) { continue; }
                $names[] = $row['title'];
            }
            usort( $names, 'strcmp' );
            $item['lootSourceNpcs'] = $names;
            $item['lootSourceCount'] = count( $names );
            $item['lootSourceCountComplete'] = $this->complete && $complete[$i];
        }
        unset( $item );
        return $items;
    }

    private static function nonMonsterSource( string $name ): bool {
        if ( in_array( EqlNpcLootWikitext::identity( $name ), [ 'ground spawn', 'ground spawns' ], true ) ) { return true; }
        $label = preg_replace( '/[.!?,;:]+$/', '', EqlNpcLootWikitext::identity( $name ) );
        $label = preg_replace( '/^(?:the )?corpse of /', '', $label );
        $label = preg_replace( "/(?:'s corpse| \\(corpse\\))$/", '', $label );
        return preg_match( "/^[\\p{L}\\p{N}][\\p{L}\\p{N}' .-]{0,183} pet$/u", trim( $label ) ) === 1;
    }
}
