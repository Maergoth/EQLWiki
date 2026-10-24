<?php

use MediaWiki\Title\Title;
use MediaWiki\Parser\ParserOutputLinkTypes;

/** Read-only reverse loot on ordinary NPC article views; never changes page source. */
class EqlNpcLootRenderer {
    public const MARKER = 'eql-npc-source-loot';
    public const GUARD = 'eql-npc-source-loot-scanned-v2';
    public const CACHE_SECONDS = 300;

    /** @var callable|null Source-only fixtures may inject a lookup without writing wiki pages. */
    public static $lookupFactory = null;

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
        if ( $analysis === null ) {
            return;
        }
        // Even an empty result must expire: a newly linked item is not a dependency yet.
        $output->setExtensionData( self::MARKER, true );
        $output->updateCacheExpiry( self::CACHE_SECONDS );
        try {
            $lookup = self::$lookupFactory ? ( self::$lookupFactory )() : new EqlNpcLootLookup();
            $result = $lookup->find( $title, $analysis );
        } catch ( Throwable $error ) {
            if ( function_exists( 'wfDebugLog' ) ) {
                wfDebugLog( 'DynamicZoneList', 'Recorded NPC loot lookup unavailable: ' . get_class( $error ) );
            }
            $result = [ 'items' => [], 'complete' => false, 'warnings' => [] ];
        }
        $text .= self::render( $result );
    }

    /** Normal item transclusion preserves Itembox/AjaxHoverHelper and parser dependencies. */
    public static function render( array $result ): string {
        $rows = [];
        foreach ( $result['items'] ?? [] as $item ) {
            $title = self::mainTitle( $item['title'] ?? '' );
            if ( !$title ) {
                continue;
            }
            $contexts = [];
            foreach ( $item['contexts'] ?? [] as $context ) {
                $zone = self::mainTitle( $context['zone'] ?? '' );
                $instance = ( $context['instance'] ?? 'normal' ) === 'raid' ? 'Raid Zone' : 'Normal Zone';
                $label = self::escape( $instance );
                if ( $zone ) {
                    // Raw HTML anchors are escaped by the wikitext sanitizer.
                    $label .= ' · [[' . $zone->getPrefixedDBkey() . '|' .
                        self::escape( $zone->getText() ) . ']]';
                } else {
                    $label .= ' · zone unspecified';
                }
                $contexts[$label] = $label;
            }
            $rows[] = '<li><span class="eql-recorded-loot-item">{{:' . $title->getPrefixedDBkey() .
                '}}</span> <small class="eql-recorded-loot-context">' .
                implode( '; ', $contexts ) . '</small></li>';
        }
        $incomplete = empty( $result['complete'] );
        if ( !$rows && !$incomplete ) {
            return '';
        }
        // HTML headings have no misleading source-section edit link for this generated content.
        return "\n\n<div class=\"eql-recorded-loot\">\n<h2>Additional recorded drops</h2>\n" .
            '<p><small>From current published item drop sources. Existing loot lists are unchanged; ' .
            'rarity is not inferred.</small></p>' . "\n" .
            ( $rows ? "<ul>\n" . implode( "\n", $rows ) . "\n</ul>\n" : '' ) .
            ( $incomplete ? '<p class="eql-recorded-loot-notice">Some recorded drops could not be checked. ' .
                'Try again shortly.</p>' . "\n" : '' ) . "</div>\n";
    }

    private static function mainTitle( string $text ): ?Title {
        // Reject wikitext delimiters explicitly as well as using MediaWiki's title validation.
        if ( $text === '' || preg_match( '/[{}|<>\[\]#\r\n]/', $text ) ) {
            return null;
        }
        $title = Title::newFromText( $text );
        return $title && $title->getNamespace() === NS_MAIN && !$title->isExternal() ? $title : null;
    }

    private static function escape( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
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
                strcasecmp( str_replace( '_', ' ', $link->getDBkey() ), 'Namedmobpage' ) === 0
            ) {
                return false;
            }
        }
        return null;
    }
}
