<?php

use MediaWiki\Title\Title;
use MediaWiki\Parser\ParserOutputLinkTypes;

/** Read-only reciprocal loot on NPC/item article views; never changes page source. */
class EqlNpcLootRenderer {
    public const MARKER = 'eql-npc-source-loot';
    public const GUARD = 'eql-npc-source-loot-scanned-v5';
    public const CACHE_SECONDS = 300;

    /** @var callable|null Source-only fixtures may inject a lookup without writing wiki pages. */
    public static $lookupFactory = null;
    public static $itemLookupFactory = null;

    public static function onParserBeforeInternalParse( $parser, &$text, $stripState ): void {
        $output = $parser->getOutput();
        if ( $output->getExtensionData( self::GUARD ) ) {
            return;
        }
        // This hook also runs inside recursiveTagParse. Only examine the first, unexpanded input.
        $output->setExtensionData( self::GUARD, true );
        $title = $parser->getTitle();
        $options = $parser->getOptions();
        if ( !$title || $title->getNamespace() !== NS_MAIN ||
            $options->getUseParsoid() || $options->getIsSectionPreview()
        ) {
            return;
        }
        $analysis = EqlNpcLootWikitext::analyseNpcPage( $text );
        $isNpc = $analysis !== null;
        $analysis ??= EqlNpcLootWikitext::analyseItemPage( $text );
        if ( $analysis === null ) {
            return;
        }
        // Even an empty result must expire: a newly linked item is not a dependency yet.
        $output->setExtensionData( self::MARKER, true );
        $output->updateCacheExpiry( self::CACHE_SECONDS );
        try {
            $lookup = $isNpc ?
                ( self::$lookupFactory ? ( self::$lookupFactory )() : new EqlNpcLootLookup() ) :
                ( self::$itemLookupFactory ? ( self::$itemLookupFactory )() : new EqlItemDropLookup() );
            $result = $lookup->find( $title, $analysis );
        } catch ( Throwable $error ) {
            if ( function_exists( 'wfDebugLog' ) ) {
                wfDebugLog( 'DynamicZoneList', 'Recorded NPC loot lookup unavailable: ' . get_class( $error ) );
            }
            $result = [ 'items' => [], 'complete' => false, 'warnings' => [] ];
        }
        $merged = $isNpc ? EqlNpcLootWikitext::mergeNpcLoot( $text, self::entries( $result ) ) :
            EqlNpcLootWikitext::mergeItemSources( $text, $result['sources'] ?? [] );
        $text = $merged['text'];
        if ( empty( $result['complete'] ) || $merged['unplaced'] ) {
            $text .= "\n<p class=\"eql-recorded-loot-notice\">Some recorded drops could not be placed or checked. Try again shortly.</p>\n";
        }
    }

    /** Normal item transclusion preserves Itembox/AjaxHoverHelper and parser dependencies. */
    public static function render( array $result ): string {
        $rows = array_column( self::entries( $result ), 'markup' );
        return $rows ? '<ul class="eql-recorded-loot" title="From current published item drop sources">' .
            "\n" . implode( "\n", $rows ) . "\n</ul>" : '';
    }

    /** Validated entries are merged into native loot fields only while parsing. */
    public static function entries( array $result ): array {
        $entries = [];
        foreach ( $result['items'] ?? [] as $item ) {
            $title = self::mainTitle( $item['title'] ?? '' );
            if ( !$title ) {
                continue;
            }
            $entries[] = [
                'markup' => '<li class="eql-recorded-loot-item" title="From current published item drop sources">{{:' . $title->getPrefixedDBkey() .
                    '}}</li>',
                'contexts' => $item['contexts'] ?? [],
                'field' => ( $item['lootKind'] ?? '' ) === 'common' ? 'common_loot' : 'known_loot'
            ];
        }
        return $entries;
    }

    private static function mainTitle( string $text ): ?Title {
        // Reject wikitext delimiters explicitly as well as using MediaWiki's title validation.
        if ( $text === '' || preg_match( '/[{}|<>\[\]#\r\n]/', $text ) ) {
            return null;
        }
        $title = Title::newFromText( $text );
        return $title && $title->getNamespace() === NS_MAIN && !$title->isExternal() ? $title : null;
    }

    public static function onOutputPageParserOutput( $out, $parserOutput ): void {
        if ( $parserOutput->getExtensionData( self::MARKER ) ) {
            $out->setProperty( self::MARKER, true );
        }
    }

    public static function onBeforePageDisplay( $out, $skin ): void {
        if ( $out->getProperty( self::MARKER ) ) {
            $out->lowerCdnMaxage( self::CACHE_SECONDS );
        }
    }

    public static function onRejectParserCacheValue( $output, $wikiPage, $options ): ?bool {
        if ( $output->getExtensionData( self::GUARD ) || $wikiPage->getTitle()->getNamespace() !== NS_MAIN ||
            $options->getUseParsoid() || $options->getIsSectionPreview()
        ) {
            return null;
        }
        // One-time migration of old NPC-containing output, without flushing unrelated pages.
        // Newly parsed ordinary/transcluding articles also carry GUARD and remain reusable.
        foreach ( $output->getLinkList( ParserOutputLinkTypes::TEMPLATE ) as $dependency ) {
            $link = $dependency['link'];
            if ( $link->getNamespace() === NS_TEMPLATE &&
                in_array( strtolower( str_replace( '_', ' ', $link->getDBkey() ) ), [ 'namedmobpage', 'itempage' ], true )
            ) {
                return false;
            }
        }
        return null;
    }
}
