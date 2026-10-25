<?php
/** Pure global authored-loot aggregation for the static client pack. No I/O. */
require_once __DIR__ . '/../extensions/DynamicZoneList/NpcLootWikitext.php';

final class EqlItemLootSourceCounts {
    private array $direct = [];
    private array $references = [];
    private array $missingItems = [];
    private array $sets = [];
    private bool $complete = true;
    private array $incompleteReasons = [];
    private array $uncertainReferences = [];
    private array $uncertainItems = [];

    public function __construct( private int $maxReferences = 200000, private int $maxNpcs = 1000 ) {}

    public function incomplete( string $reason = 'scan-incomplete' ): void {
        $this->complete = false;
        $this->incompleteReasons[$reason] = ( $this->incompleteReasons[$reason] ?? 0 ) + 1;
    }

    /** Aggregate diagnostics only; no authored source or private page identity. */
    public function diagnostics(): array {
        return [ 'complete' => $this->complete, 'incompleteReasons' => $this->incompleteReasons,
            'items' => count( $this->direct ), 'references' => count( $this->references ), 'collections' => count( $this->sets ),
            'uncertainItems' => count( $this->uncertainItems ) ];
    }

    /** Public, canonical set metadata supplied by the bounded producer adapter. */
    public function sets( array $sets ): void {
        if ( count( $sets ) > 10000 ) { $this->incomplete(); }
        $this->sets = array_slice( $sets, 0, 10000, true );
    }

    public function item( string $title, string $text ): void {
        $parsed = true;
        $analysis = EqlNpcLootWikitext::analyseItemPage( $text, $parsed, true );
        $this->direct[$title] = [ 'complete' => $parsed && $analysis !== null,
            'names' => array_column( $analysis['existingSources'] ?? [], 'npc' ) ];
    }

    public function npc( string $title, string $text, array $verifiedItemTemplates = [] ): void {
        $parsed = true;
        $text = preg_replace( '/\{\{\s*(?:NPCpage|MerchantPage)\b/i', '{{Namedmobpage', $text );
        $text = preg_replace( '/\|\s*(knownloot|commonloot)\s*=/i', '|$1=', $text );
        $text = preg_replace( '/\|knownloot=/i', '|known_loot=', $text );
        $text = preg_replace( '/\|commonloot=/i', '|common_loot=', $text );
        $rows = EqlNpcLootWikitext::npcLootSources( $text, $parsed, true, true, $verifiedItemTemplates );
        $bounded = false;
        $possible = method_exists( EqlNpcLootWikitext::class, 'possibleNpcLootItems' )
            ? EqlNpcLootWikitext::possibleNpcLootItems( $text, $bounded ) : [];
        if ( !$parsed && !$bounded ) { $this->incomplete( 'unsupported-npc-loot' ); }
        if ( $bounded ) {
            $exact = array_fill_keys( array_column( $rows, 'item' ), true );
            foreach ( $possible as $item ) { if ( !$parsed || !isset( $exact[$item] ) ) { $this->uncertainReferences[$item] = true; } }
        }
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
        $references = $this->expandedReferences( $resolve );
        $this->expandUncertainty( $resolve );
        $titles = array_diff_key( array_fill_keys( array_keys( $this->direct ), true ), $this->sets );
        $folded = [];
        foreach ( $this->direct as $title => $_ ) { $folded[EqlNpcLootWikitext::identity( $title )][] = $title; }
        foreach ( $references as $reference ) {
            $target = $reference['target'];
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
        foreach ( $this->aggregate( $items, $resolve, $references ) as $item ) { $title = $item['wikiTitle']; unset( $item['wikiTitle'] ); $catalog[$title] = $item; }
        ksort( $catalog, SORT_STRING );
        return $catalog;
    }

    private function aggregate( array $items, callable $resolve, array $references ): array {
        $index = [];
        $rows = [];
        $complete = [];
        $folded = [];
        foreach ( $items as $i => $item ) {
            $title = $item['wikiTitle'];
            $index[$title] = $i;
            $folded[EqlNpcLootWikitext::identity( $title )][] = $i;
            $rows[$i] = [];
            $complete[$i] = ( $this->direct[$title]['complete'] ?? isset( $this->missingItems[$title] ) ) && !isset( $this->uncertainItems[$title] );
        }
        $add = function ( int $i, string $name ) use ( &$rows, &$complete, $resolve ): void {
            if ( self::nonMonsterSource( $name ) ) { return; }
            try { $npc = $resolve( $name ); } catch ( Throwable $error ) { $complete[$i] = false; return; }
            if ( !( $npc['complete'] ?? false ) || !( $npc['public'] ?? false ) ) { $complete[$i] = false; return; }
            // Version 2 producer adapters prove an actual public NPC card.
            // Redlinks and arbitrary existing pages remain relationship
            // evidence, but never inflate the verified monster lower bound.
            if ( isset( $npc['kind'] ) && $npc['kind'] !== 'npc' ) { $complete[$i] = false; return; }
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
        foreach ( $references as $reference ) {
            $target = $reference['target'];
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

    private function expandedReferences( callable $resolve ): array {
        $references = [];
        $memo = [];
        $expandedSets = [];
        $expansions = 0;
        $expand = function ( string $item, string $npc, array $visited = [] ) use ( &$expand, &$references, &$memo, &$expandedSets, &$expansions, $resolve ): void {
            if ( ++$expansions > $this->maxReferences * 2 ) { $this->incomplete(); return; }
            try { $target = $memo[$item] ??= $resolve( $item ); }
            catch ( Throwable $error ) { $this->incomplete(); return; }
            if ( !( $target['complete'] ?? false ) || !( $target['public'] ?? false ) ) { $this->incomplete(); return; }
            $title = $target['title'];
            if ( array_key_exists( $title, $this->sets ) ) {
                $set = $this->sets[$title];
                if ( isset( $visited[$title] ) || count( $visited ) >= 8 || !is_array( $set ) || !is_array( $set['items'] ?? null ) ) { $this->incomplete(); return; }
                // Different paths can reach the same nested set for one mob.
                // Expand it once; a cyclic path is still rejected above.
                $setKey = $title . "\0" . $npc;
                if ( isset( $expandedSets[$setKey] ) ) { return; }
                $expandedSets[$setKey] = true;
                if ( ( $set['complete'] ?? false ) !== true ) {
                    if ( ( $set['possibleItemsComplete'] ?? false ) === true && count( $set['possibleItems'] ?? [] ) <= 1000 ) {
                        foreach ( $set['possibleItems'] as $possible ) { $this->uncertainReferences[$possible] = true; }
                    } else { $this->incomplete( 'unbounded-collection-membership' ); }
                }
                if ( count( $set['items'] ) > 1000 ) { $this->incomplete( 'collection-member-limit' ); }
                $visited[$title] = true;
                foreach ( array_slice( $set['items'], 0, 1000 ) as $member ) {
                    if ( !is_string( $member ) || $member === '' ) { $this->incomplete(); continue; }
                    $expand( $member, $npc, $visited );
                }
                return;
            }
            if ( isset( $target['kind'] ) && in_array( $target['kind'], [ 'npc', 'spell', 'merchant', 'zone', 'quest', 'skill' ], true ) ) { return; }
            if ( isset( $target['kind'] ) && !in_array( $target['kind'], [ 'item', 'missing' ], true ) ) {
                $this->incomplete( 'unresolved-collection-membership' );
                return;
            }
            $key = $title . "\0" . $npc;
            if ( !isset( $references[$key] ) && count( $references ) >= $this->maxReferences ) { $this->incomplete(); return; }
            $references[$key] = [ 'item' => $title, 'npc' => $npc, 'target' => $target ];
        };
        foreach ( $this->references as $reference ) {
            $expand( $reference['item'], $reference['npc'] );
            if ( $expansions > $this->maxReferences * 2 ) { break; }
        }
        return $references;
    }

    /** Possible membership is uncertainty only, never an observed drop cross-product. */
    private function expandUncertainty( callable $resolve ): void {
        $this->uncertainItems = [];
        $seen = [];
        $visit = function ( string $name, int $depth = 0 ) use ( &$visit, &$seen, $resolve ): void {
            if ( isset( $seen[$name] ) ) { return; }
            $seen[$name] = true;
            if ( $depth >= 8 || count( $seen ) > $this->maxReferences ) { $this->incomplete( 'uncertainty-limit' ); return; }
            try { $target = $resolve( $name ); } catch ( Throwable $error ) { $this->incomplete( 'unresolved-uncertainty' ); return; }
            if ( !( $target['public'] ?? false ) || !( $target['complete'] ?? false ) ) { $this->incomplete( 'unresolved-uncertainty' ); return; }
            $title = $target['title'];
            if ( isset( $this->sets[$title] ) ) {
                $set = $this->sets[$title];
                // A reviewed public self-labelled leaf has no member graph.
                // Its own possible identity stays uncertain without poisoning
                // unrelated items or turning the stub into a positive item.
                if ( ( $set['possibleRule'] ?? '' ) === 'literal-self-title' &&
                    ( $set['possibleItemsComplete'] ?? false ) === true &&
                    ( $set['items'] ?? [] ) === [] && ( $set['possibleItems'] ?? [] ) === [ $title ] &&
                    ( $set['source']['wikiTitle'] ?? '' ) === $title &&
                    is_int( $set['source']['revisionId'] ?? null ) && $set['source']['revisionId'] > 0 ) {
                    $this->uncertainItems[$title] = true;
                    return;
                }
                if ( !( $set['complete'] ?? false ) && !( $set['possibleItemsComplete'] ?? false ) ) { $this->incomplete( 'unbounded-collection-membership' ); }
                foreach ( array_unique( array_merge( $set['items'] ?? [], $set['possibleItems'] ?? [] ) ) as $member ) { $visit( $member, $depth + 1 ); }
            } elseif ( !isset( $target['kind'] ) || in_array( $target['kind'], [ 'item', 'missing' ], true ) ) {
                $this->uncertainItems[$title] = true;
            } elseif ( !in_array( $target['kind'], [ 'npc', 'spell', 'merchant', 'zone', 'quest', 'skill' ], true ) ) { $this->incomplete( 'unresolved-uncertainty' ); }
        };
        foreach ( array_keys( $this->uncertainReferences ) as $name ) { $visit( $name ); }
    }

    private static function nonMonsterSource( string $name ): bool {
        if ( in_array( EqlNpcLootWikitext::identity( $name ), [ 'ground spawn', 'ground spawns' ], true ) ) { return true; }
        $label = preg_replace( '/[.!?,;:]+$/', '', EqlNpcLootWikitext::identity( $name ) );
        $label = preg_replace( '/^(?:the )?corpse of /', '', $label );
        $label = preg_replace( "/(?:'s corpse| \\(corpse\\))$/", '', $label );
        return preg_match( "/^[\\p{L}\\p{N}][\\p{L}\\p{N}' .-]{0,183} pet$/u", trim( $label ) ) === 1;
    }
}
