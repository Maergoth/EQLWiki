<?php
/**
 * BuildEyeOfZommPack.php
 *
 * Copy this file and zone-catalog.json into MediaWiki's maintenance/ directory.
 * Run through maintenance/run.php. This reads the wiki locally; it does not
 * make HTTP/API requests back to eqlwiki.com.
 */

require_once __DIR__ . '/Maintenance.php';

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class BuildEyeOfZommPack extends Maintenance {
    private const CLASS_CODES = [
        'WAR','CLR','PAL','RNG','SHD','DRU','MNK','BRD',
        'ROG','SHM','NEC','WIZ','MAG','ENC','BST','BER','ALL'
    ];

    private array $zoneCatalog = [];
    private array $zoneAliasMap = [];
    private int $pauseUs = 20000;

    public function __construct() {
        parent::__construct();
        $this->addDescription(
            'Builds the static EQLWiki - Eye of Zomm NPC/item data pack from local MediaWiki data.'
        );
        $this->addOption(
            'output',
            'Output JSON path.',
            true,
            true
        );
        $this->addOption(
            'current-era',
            'Current player-facing era (default: Classic).',
            false,
            true
        );
        $this->addOption(
            'pause-ms',
            'Cooperative pause after each content page (default: 20ms).',
            false,
            true
        );
        $this->addOption(
            'limit',
            'Testing only: limit NPCs and items separately to this many pages.',
            false,
            true
        );
        $this->setBatchSize( 500 );
    }

    public function execute() {
        $output = (string)$this->getOption( 'output' );
        $currentEra = trim( (string)$this->getOption( 'current-era', 'Classic' ) );
        $pauseMs = max( 0, min( 1000, (int)$this->getOption( 'pause-ms', 20 ) ) );
        $this->pauseUs = $pauseMs * 1000;
        $limit = max( 0, (int)$this->getOption( 'limit', 0 ) );

        $this->loadZoneCatalog();

        $services = MediaWikiServices::getInstance();
        $dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $wikiPageFactory = $services->getWikiPageFactory();

        $this->output( "EQLWiki - Eye of Zomm static dataset build\n" );
        $this->output( "HTTP/API requests: 0\n" );
        $this->output( "Per-page pause: {$pauseMs} ms\n" );
        $this->output( "Current era: {$currentEra}\n\n" );

        $npcRows = $this->listPagesInCategory( $dbr, 'NPCs', $limit );
        $itemRows = $this->listPagesInCategory( $dbr, 'Items', $limit );

        $allIds = [];
        foreach ( [ $npcRows, $itemRows ] as $rows ) {
            foreach ( $rows as $row ) {
                $allIds[] = (int)$row->page_id;
            }
        }
        $categoriesByPage = $this->fetchCategoriesForPages( $dbr, $allIds );

        $this->output( sprintf(
            "Candidates: %d NPC pages, %d item pages\n",
            count( $npcRows ),
            count( $itemRows )
        ) );

        $npcs = [];
        $npcSkipped = 0;
        foreach ( $npcRows as $index => $row ) {
            $recordSet = $this->buildNpcRecords(
                $wikiPageFactory,
                $row,
                $categoriesByPage[(int)$row->page_id] ?? []
            );
            if ( !$recordSet ) {
                $npcSkipped++;
            } else {
                foreach ( $recordSet as $record ) {
                    $npcs[] = $record;
                }
            }
            $this->maybePause();
            if ( ( $index + 1 ) % 250 === 0 ) {
                $this->output( 'NPC pages processed: ' . ( $index + 1 ) . "\n" );
            }
        }

        $items = [];
        $itemSkipped = 0;
        foreach ( $itemRows as $index => $row ) {
            $record = $this->buildItemRecord(
                $wikiPageFactory,
                $row,
                $categoriesByPage[(int)$row->page_id] ?? []
            );
            if ( $record ) {
                $items[] = $record;
            } else {
                $itemSkipped++;
            }
            $this->maybePause();
            if ( ( $index + 1 ) % 250 === 0 ) {
                $this->output( 'Item pages processed: ' . ( $index + 1 ) . "\n" );
            }
        }

        usort( $npcs, static function ( $a, $b ) {
            return [ strtolower( $a['zone'] ), strtolower( $a['name'] ) ]
                <=> [ strtolower( $b['zone'] ), strtolower( $b['name'] ) ];
        } );
        usort( $items, static fn ( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

        $icons = $this->buildItemIconMap( $services->getRepoGroup(), $items );

        $generated = gmdate( 'c' );
        $pack = [
            'meta' => [
                'schemaVersion' => 3,
                'version' => 'wiki-' . gmdate( 'Ymd\THis\Z' ),
                'generatedAt' => $generated,
                'currentEra' => $currentEra,
                'sample' => false,
                'source' => 'EQLWiki local MediaWiki maintenance build',
                'distribution' => 'GitHub dataset branch',
                'counts' => [
                    'zones' => count( $this->zoneCatalog ),
                    'npcs' => count( $npcs ),
                    'items' => count( $items ),
                    'icons' => count( $icons ),
                ],
            ],
            'zones' => $this->zoneCatalog,
            'npcs' => $npcs,
            'items' => $items,
            'icons' => (object)$icons,
        ];

        $this->writeAtomicJson( $output, $pack );

        $this->output( "\nComplete.\n" );
        $this->output( 'Zones: ' . count( $this->zoneCatalog ) . "\n" );
        $this->output( 'NPC records: ' . count( $npcs ) . " (skipped pages: {$npcSkipped})\n" );
        $this->output( 'Items: ' . count( $items ) . " (skipped pages: {$itemSkipped})\n" );
        $this->output( 'Embedded item icons: ' . count( $icons ) . "\n" );
        $this->output( "Output: {$output}\n" );
    }

    private function loadZoneCatalog(): void {
        $path = __DIR__ . '/zone-catalog.json';
        if ( !is_file( $path ) ) {
            $this->fatalError( "Missing {$path}" );
        }
        $decoded = json_decode( file_get_contents( $path ), true );
        if ( !is_array( $decoded ) ) {
            $this->fatalError( 'zone-catalog.json is not valid JSON.' );
        }
        $this->zoneCatalog = array_values( $decoded );

        foreach ( $this->zoneCatalog as $zone ) {
            $values = array_merge(
                [ $zone['name'] ?? '', $zone['viewerName'] ?? '', $zone['wikiTitle'] ?? '' ],
                $zone['aliases'] ?? []
            );
            foreach ( $values as $value ) {
                $key = $this->normalizeZone( $value );
                if ( $key !== '' ) {
                    $this->zoneAliasMap[$key] = $zone;
                }
            }
        }
    }

    private function listPagesInCategory( $dbr, string $category, int $limit ): array {
        $tables = [
            'page',
            'cl' => 'categorylinks',
            'lt' => 'linktarget',
        ];
        $joins = [
            'cl' => [ 'INNER JOIN', 'page_id = cl.cl_from' ],
            'lt' => [ 'INNER JOIN', 'cl.cl_target_id = lt.lt_id' ],
        ];
        $options = [ 'ORDER BY' => 'page_id ASC' ];
        if ( $limit > 0 ) {
            $options['LIMIT'] = $limit;
        }
        $res = $dbr->select(
            $tables,
            [ 'page_id', 'page_namespace', 'page_title' ],
            [
                'page_namespace' => NS_MAIN,
                'page_is_redirect' => 0,
                'lt.lt_namespace' => NS_CATEGORY,
                'lt.lt_title' => str_replace( ' ', '_', $category ),
            ],
            __METHOD__,
            $options,
            $joins
        );
        return iterator_to_array( $res, false );
    }

    private function fetchCategoriesForPages( $dbr, array $pageIds ): array {
        $out = [];
        $pageIds = array_values( array_unique( array_map( 'intval', $pageIds ) ) );
        foreach ( array_chunk( $pageIds, $this->getBatchSize() ) as $chunk ) {
            if ( !$chunk ) {
                continue;
            }
            $res = $dbr->select(
                [ 'cl' => 'categorylinks', 'lt' => 'linktarget' ],
                [ 'cl.cl_from', 'lt.lt_title' ],
                [
                    'cl.cl_from' => $chunk,
                    'lt.lt_namespace' => NS_CATEGORY,
                ],
                __METHOD__,
                [],
                [ 'lt' => [ 'INNER JOIN', 'cl.cl_target_id = lt.lt_id' ] ]
            );
            foreach ( $res as $row ) {
                $id = (int)$row->cl_from;
                $out[$id][] = str_replace( '_', ' ', (string)$row->lt_title );
            }
        }
        return $out;
    }

    private function buildNpcRecords( $wikiPageFactory, $row, array $categories ): array {
        $title = Title::makeTitle( (int)$row->page_namespace, $row->page_title );
        $page = $wikiPageFactory->newFromTitle( $title );
        $content = $page->getContent();
        if ( !$content || !method_exists( $content, 'getText' ) ) {
            return [];
        }
        $text = $content->getText();

        // EQLWiki NPC pages primarily use {{Namedmobpage}} and {{MerchantPage}}.
        // Keep {{NPCpage}} as a compatibility fallback for older pages.
        $template = '';
        $templateName = '';
        foreach ( [ 'Namedmobpage', 'MerchantPage', 'NPCpage' ] as $candidate ) {
            $candidateTemplate = $this->extractBalancedTemplate( $text, $candidate );
            if ( $candidateTemplate !== '' ) {
                $template = $candidateTemplate;
                $templateName = $candidate;
                break;
            }
        }
        if ( $template === '' ) {
            return [];
        }
        $body = $this->stripTemplateWrapper( $template, $templateName );
        $levelText = $this->extractParamByLine( $body, [ 'level' ] );
        [ $levelMin, $levelMax, $avgLevel ] = $this->parseLevelRange( $levelText );
        $race = $this->cleanWikiText( $this->extractParamByLine( $body, [ 'race' ] ) );
        $npcClass = $this->cleanWikiText( $this->extractParamByLine( $body, [ 'class' ] ) );
        $description = $this->cleanWikiText( $this->extractParamByLine( $body, [ 'description' ] ) );
        if ( strlen( $description ) > 500 ) {
            $description = substr( $description, 0, 497 ) . '...';
        }
        $locationText = $this->extractParamByLine( $body, [ 'location', 'loc' ] );
        $loc = $this->parseLocation( $locationText );
        $zones = $this->detectZonesFromCategories( $categories );
        if ( !$zones ) {
            $zoneText = $this->extractParamByLine( $body, [ 'zone' ] );
            $zones = $this->detectZonesFromField( $zoneText );
        }
        if ( !$zones ) {
            $zones = $this->detectZonesFromTitle( $title->getText() );
        }
        if ( !$zones ) {
            $zones = [ '' ];
        }
        $era = $this->detectEra( $categories );
        $revision = $page->getRevisionRecord();
        $revisionId = $revision ? (int)$revision->getId() : 0;
        $records = [];
        foreach ( $zones as $zone ) {
            $record = [
                'name' => $title->getText(),
                'zone' => $zone,
                'levelMin' => $levelMin,
                'levelMax' => $levelMax,
                'avgLevel' => $avgLevel,
                'race' => $race,
                'class' => $npcClass,
                'era' => $era,
                'wikiTitle' => $title->getText(),
                'revisionId' => $revisionId,
            ];
            if ( $loc !== null ) {
                $record['loc'] = $loc;
            }
            if ( $description !== '' ) {
                $record['description'] = $description;
            }
            $records[] = $record;
        }
        return $records;
    }

    private function buildItemRecord( $wikiPageFactory, $row, array $categories ): ?array {
        $title = Title::makeTitle( (int)$row->page_namespace, $row->page_title );
        $page = $wikiPageFactory->newFromTitle( $title );
        $content = $page->getContent();
        if ( !$content || !method_exists( $content, 'getText' ) ) {
            return null;
        }
        $text = $content->getText();
        $template = $this->extractBalancedTemplate( $text, 'Itempage' );
        if ( $template === '' ) {
            return null;
        }
        $body = $this->stripTemplateWrapper( $template, 'Itempage' );
        $statsBlock = $this->extractParamByLine( $body, [ 'statsblock', 'stats_block' ] );
        if ( $statsBlock === '' ) {
            return null;
        }

        $itemName = $this->extractParamByLine( $body, [ 'itemname', 'item_name' ] );
        if ( $itemName === '' ) {
            $itemName = $title->getText();
        }
        $notes = $this->cleanWikiText(
            $this->extractParamByLine( $body, [ 'notes' ] )
        );
        $classes = $this->parseClasses( $statsBlock );
        $slots = $this->parseSlots( $statsBlock );
        $stats = $this->parseItemStats( $statsBlock );
        $displayLines = $this->parseItemDisplayLines( $statsBlock );
        $iconText = $this->extractParamByLine( $body, [ 'lucy_img_ID', 'lucy_img_id' ] );
        $iconId = preg_match( '/\d+/', $iconText, $iconMatch ) ? (int)$iconMatch[0] : 0;
        $dropsFrom = $this->extractParamByLine( $body, [ 'dropsfrom', 'drops_from' ] );
        $dropSources = $this->parseDropSources( $dropsFrom );
        $revision = $page->getRevisionRecord();
        $revisionId = $revision ? (int)$revision->getId() : 0;

        $record = [
            'name' => $this->cleanWikiText( $itemName ),
            'wikiTitle' => $title->getText(),
            'era' => $this->detectEra( $categories ),
            'classes' => $classes,
            'slots' => $slots,
            'stats' => (object)$stats,
            'displayLines' => $displayLines,
            'dropSources' => $dropSources,
            'revisionId' => $revisionId,
        ];
        if ( $notes !== '' ) {
            $record['notes'] = $notes;
        }
        if ( $iconId > 0 ) {
            $record['iconId'] = $iconId;
        }
        return $record;
    }

    private function parseItemDisplayLines( string $statsBlock ): array {
        $text = preg_replace( '/<br\s*\/?\s*>/i', "\n", $statsBlock );
        $lines = [];
        foreach ( preg_split( '/\r\n|\n|\r/', (string)$text ) as $line ) {
            $clean = $this->cleanWikiText( $line );
            if ( $clean !== '' ) {
                $lines[] = $clean;
            }
            if ( count( $lines ) >= 16 ) {
                break;
            }
        }
        return $lines;
    }

    private function buildItemIconMap( $repoGroup, array $items ): array {
        $ids = [];
        foreach ( $items as $item ) {
            $id = (int)( $item['iconId'] ?? 0 );
            if ( $id > 0 ) {
                $ids[$id] = true;
            }
        }

        $icons = [];
        $total = count( $ids );
        $index = 0;
        foreach ( array_keys( $ids ) as $id ) {
            $index++;
            try {
                $title = Title::makeTitle( NS_FILE, 'Item_' . $id . '.png' );
                $file = $repoGroup->findFile( $title );
                if ( !$file || !method_exists( $file, 'getLocalRefPath' ) ) {
                    continue;
                }
                $path = $file->getLocalRefPath();
                if ( !$path || !is_file( $path ) ) {
                    continue;
                }
                $size = filesize( $path );
                if ( $size === false || $size <= 0 || $size > 131072 ) {
                    continue;
                }
                $bytes = file_get_contents( $path );
                if ( $bytes === false ) {
                    continue;
                }
                $mime = method_exists( $file, 'getMimeType' )
                    ? (string)$file->getMimeType()
                    : 'image/png';
                if ( strpos( $mime, 'image/' ) !== 0 ) {
                    $mime = 'image/png';
                }
                $icons[(string)$id] = 'data:' . $mime . ';base64,' . base64_encode( $bytes );
            } catch ( Throwable $error ) {
                $this->output( "Icon {$id} skipped: {$error->getMessage()}\n" );
            }
            if ( $index % 250 === 0 ) {
                $this->output( "Item icons processed: {$index}/{$total}\n" );
            }
        }
        return $icons;
    }

    private function detectZonesFromCategories( array $categories ): array {
        $zones = [];
        foreach ( $categories as $category ) {
            $key = $this->normalizeZone( $category );
            if ( isset( $this->zoneAliasMap[$key] ) ) {
                $name = (string)( $this->zoneAliasMap[$key]['name'] ?? $category );
                $zones[$name] = true;
            }
        }
        return array_keys( $zones );
    }

    private function detectZonesFromField( string $zoneText ): array {
        if ( trim( $zoneText ) === '' ) {
            return [];
        }

        $candidates = [];
        if ( preg_match_all(
            '/\[\[\s*([^|\]#]+)(?:#[^|\]]*)?(?:\|[^\]]+)?\s*\]\]/',
            $zoneText,
            $m
        ) ) {
            foreach ( $m[1] as $target ) {
                $candidates[] = trim( (string)$target );
            }
        }

        $plain = $this->cleanWikiText( $zoneText );
        foreach ( preg_split( '/\s*(?:,|;|\/|\bor\b)\s*/i', $plain ) as $part ) {
            if ( trim( $part ) !== '' ) {
                $candidates[] = trim( $part );
            }
        }

        $zones = [];
        foreach ( $candidates as $candidate ) {
            $key = $this->normalizeZone( $candidate );
            if ( isset( $this->zoneAliasMap[$key] ) ) {
                $name = (string)( $this->zoneAliasMap[$key]['name'] ?? $candidate );
                $zones[$name] = true;
            }
        }
        return array_keys( $zones );
    }

    private function detectZonesFromTitle( string $titleText ): array {
        if ( !preg_match( '/\(([^()]+)\)\s*$/', $titleText, $m ) ) {
            return [];
        }
        $key = $this->normalizeZone( $m[1] );
        if ( !isset( $this->zoneAliasMap[$key] ) ) {
            return [];
        }
        return [ (string)( $this->zoneAliasMap[$key]['name'] ?? $m[1] ) ];
    }

    private function detectEra( array $categories ): string {
        $normalized = array_map(
            static fn ( $c ) => strtolower( trim( str_replace( '_', ' ', (string)$c ) ) ),
            $categories
        );
        $has = static function ( array $needles ) use ( $normalized ): bool {
            foreach ( $normalized as $cat ) {
                foreach ( $needles as $needle ) {
                    if ( $cat === $needle ) {
                        return true;
                    }
                }
            }
            return false;
        };
        if ( $has( [ 'luclin', 'luclin era' ] ) ) {
            return 'Luclin';
        }
        if ( $has( [
            'velious', 'velious era', 'warrens', 'warrens era',
            'warrens fear hate revamp era'
        ] ) ) {
            return 'Velious';
        }
        if ( $has( [
            'kunark', 'kunark era', 'chardok', 'chardok era',
            'chardok revamp', 'chardok revamp era', 'hole vp era',
            'hole/vp era', 'epics', 'epics era', 'epic quests',
            'epic quests era'
        ] ) ) {
            return 'Kunark';
        }
        if ( $has( [ 'unknown', 'unknown era' ] ) ) {
            return 'Unknown';
        }
        return 'Classic';
    }

    private function parseClasses( string $statsBlock ): array {
        if ( !preg_match( '/\bClass:\s*(.*?)(?:<br\s*\/?>|\R|$)/i', $statsBlock, $m ) ) {
            return [];
        }
        $text = strtoupper( $this->cleanWikiText( $m[1] ) );
        preg_match_all( '/\b[A-Z]{3}\b|\bALL\b/', $text, $matches );
        $out = [];
        foreach ( $matches[0] ?? [] as $code ) {
            if ( in_array( $code, self::CLASS_CODES, true ) ) {
                $out[$code] = true;
            }
        }
        return array_keys( $out );
    }

    private function parseSlots( string $statsBlock ): array {
        if ( !preg_match(
            '/\bSlot:\s*(.*?)(?=<br\s*\/?>|\R|\b(?:WT|WEIGHT|SIZE|AC|DMG|DAMAGE|DELAY|RANGE|Class|Race):|$)/is',
            $statsBlock,
            $m
        ) ) {
            return [];
        }

        $text = strtoupper( $this->cleanWikiText( $m[1] ) );
        $text = preg_replace( '/[^A-Z0-9 ]+/', ' ', $text );
        $tokens = preg_split( '/\s+/', trim( $text ) );
        $aliases = [
            'FINGERS' => 'FINGER',
            'SECONDAY' => 'SECONDARY',
            'SHOULDER' => 'SHOULDERS',
        ];
        $allowed = [
            'EAR','HEAD','FACE','NECK','SHOULDERS','ARMS','BACK','WRIST','RANGE',
            'HANDS','PRIMARY','SECONDARY','FINGER','CHEST','LEGS','WAIST','FEET',
            'AMMO','CHARM','POWER','SOURCE'
        ];
        $out = [];
        foreach ( $tokens as $token ) {
            if ( $token === '' ) {
                continue;
            }
            $token = $aliases[$token] ?? $token;
            if ( in_array( $token, $allowed, true ) ) {
                $out[$token] = true;
            }
        }
        if ( isset( $out['POWER'], $out['SOURCE'] ) ) {
            unset( $out['POWER'], $out['SOURCE'] );
            $out['POWER SOURCE'] = true;
        }
        return array_keys( $out );
    }

    private function parseItemStats( string $statsBlock ): array {
        $stats = [];
        $map = [
            'AC' => [ 'AC' ],
            'HP' => [ 'HP' ],
            'MP' => [ 'MANA', 'MP' ],
            'END' => [ 'ENDUR', 'END' ],
            'STR' => [ 'STR' ], 'STA' => [ 'STA' ], 'AGI' => [ 'AGI' ],
            'DEX' => [ 'DEX' ], 'WIS' => [ 'WIS' ], 'INT' => [ 'INT' ], 'CHA' => [ 'CHA' ],
            'SV_MAGIC' => [ 'SV MAGIC' ], 'SV_FIRE' => [ 'SV FIRE' ],
            'SV_COLD' => [ 'SV COLD' ], 'SV_POISON' => [ 'SV POISON' ],
            'SV_DISEASE' => [ 'SV DISEASE' ],
            'HP_REGEN' => [ 'HP REGENERATION', 'HP REGEN' ],
            'MANA_REGEN' => [ 'MANA REGENERATION', 'MANA REGEN', 'MP REGEN' ],
            'END_REGEN' => [ 'ENDURANCE REGENERATION', 'ENDURANCE REGEN', 'ENDUR REGEN', 'END REGEN' ],
            'HASTE' => [ 'HASTE' ],
            'RANGE' => [ 'RANGE' ],
            'WT' => [ 'WT', 'WEIGHT' ],
            'COLD_DMG' => [ 'COLD DMG', 'COLD DAMAGE' ],
            'FIRE_DMG' => [ 'FIRE DMG', 'FIRE DAMAGE' ],
            'MAGIC_DMG' => [ 'MAGIC DMG', 'MAGIC DAMAGE' ],
            'POISON_DMG' => [ 'POISON DMG', 'POISON DAMAGE' ],
            'DISEASE_DMG' => [ 'DISEASE DMG', 'DISEASE DAMAGE' ],
            'BACKSTAB_DMG' => [ 'BACKSTAB DMG', 'BACKSTAB DAMAGE' ],
            'BANE_DMG' => [ 'BANE DMG', 'BANE DAMAGE' ],
        ];
        foreach ( $map as $key => $labels ) {
            foreach ( $labels as $label ) {
                $pattern = '/\b' . preg_quote( $label, '/' ) . ':\s*([+\-]?\d+(?:\.\d+)?)/i';
                if ( preg_match( $pattern, $statsBlock, $m ) ) {
                    $stats[$key] = strpos( $m[1], '.' ) !== false ? (float)$m[1] : (int)$m[1];
                    break;
                }
            }
        }
        // Generic matching above can see the DMG token inside "Cold DMG".
        // Standalone DMG is therefore captured separately from a field boundary.
        if ( preg_match( '/(?:^|<br\s*\/?>|\R)\s*(?:DMG|DAMAGE):\s*([+\-]?\d+(?:\.\d+)?)/i', $statsBlock, $m ) ) {
            $stats['DMG'] = strpos( $m[1], '.' ) !== false ? (float)$m[1] : (int)$m[1];
        }
        return $stats;
    }

    private function parseDropSources( string $dropsFrom ): array {
        if ( trim( $dropsFrom ) === '' ) {
            return [];
        }

        $sources = [];
        $seen = [];
        $currentHeading = '';
        $currentZone = '';
        $headingHadBullets = false;

        $flushStandaloneHeading = function () use ( &$sources, &$seen, &$currentHeading, &$currentZone, &$headingHadBullets ): void {
            if ( $currentHeading === '' || $headingHadBullets || $currentZone !== '' ) {
                return;
            }
            $key = '|'.strtolower( $currentHeading );
            if ( !isset( $seen[$key] ) ) {
                $sources[] = [ 'zone' => '', 'name' => $currentHeading ];
                $seen[$key] = true;
            }
        };

        foreach ( preg_split( '/\r\n|\n|\r/', $dropsFrom ) as $line ) {
            $line = trim( preg_replace( '/<!--.*?-->/s', '', (string)$line ) );
            if ( $line === '' ) {
                continue;
            }

            // A standalone wiki link is normally either a zone heading or a
            // non-NPC source such as Fletching / Quest / Purchased.
            if ( preg_match( '/^\[\[\s*([^|\]]+)(?:\|[^\]]+)?\s*\]\]\s*$/', $line, $m ) ) {
                $flushStandaloneHeading();
                $currentHeading = $this->cleanWikiText( $line );
                $headingHadBullets = false;
                $zoneKey = $this->normalizeZone( $m[1] );
                $currentZone = isset( $this->zoneAliasMap[$zoneKey] )
                    ? (string)( $this->zoneAliasMap[$zoneKey]['name'] ?? $currentHeading )
                    : '';
                continue;
            }

            if ( preg_match( '/^\*+\s*(.+?)\s*$/', $line, $m ) ) {
                $headingHadBullets = true;
                $name = $this->cleanWikiText( $m[1] );
                if ( $name === '' ) {
                    continue;
                }
                $key = strtolower( $currentZone . '|' . $name );
                if ( isset( $seen[$key] ) ) {
                    continue;
                }
                $sources[] = [ 'zone' => $currentZone, 'name' => $name ];
                $seen[$key] = true;
                if ( count( $sources ) >= 24 ) {
                    break;
                }
                continue;
            }

            // Conservative fallback for a direct plain-text source line.
            $name = $this->cleanWikiText( $line );
            if ( $name !== '' && strlen( $name ) <= 160 ) {
                $key = '|'.strtolower( $name );
                if ( !isset( $seen[$key] ) ) {
                    $sources[] = [ 'zone' => '', 'name' => $name ];
                    $seen[$key] = true;
                }
            }
        }

        $flushStandaloneHeading();
        return array_slice( $sources, 0, 24 );
    }

    private function parseLevelRange( string $text ): array {
        preg_match_all( '/(?<!\d)(?:100|[1-9]?[0-9])(?!\d)/', $text, $m );
        $values = array_values( array_filter(
            array_map( 'intval', $m[0] ?? [] ),
            static fn ( $value ) => $value >= 1 && $value <= 100
        ) );
        if ( !$values ) {
            return [ 0, 0, 0 ];
        }
        $min = min( $values );
        $max = max( $values );
        $avg = ( $min + $max ) / 2;
        return [ $this->intIfWhole( $min ), $this->intIfWhole( $max ), $this->intIfWhole( $avg ) ];
    }

    private function parseLocation( string $text ): ?array {
        if ( $text === '' ) {
            return null;
        }

        $number = '[+\-]?\d+(?:\.\d+)?';
        $normalize = function ( array $matches ): ?array {
            $values = [];
            foreach ( array_slice( $matches, 1, 3 ) as $value ) {
                if ( $value !== '' && $value !== null ) {
                    $values[] = $this->intIfWhole( (float)$value );
                }
            }
            return count( $values ) >= 2 ? $values : null;
        };

        // EQLWiki commonly stores coordinates in {{loc|x|y|z}}.
        if ( preg_match(
            '/\{\{\s*(?:loc|location)\s*\|\s*(' . $number . ')\s*\|\s*(' . $number . ')(?:\s*\|\s*(' . $number . '))?/i',
            $text,
            $match
        ) ) {
            return $normalize( $match );
        }

        // When several spawn points are listed, take the first complete tuple
        // instead of accidentally treating the next tuple's X as elevation.
        if ( preg_match(
            '/[\(\[]\s*(' . $number . ')\s*[,|]\s*(' . $number . ')(?:\s*[,|]\s*(' . $number . '))?\s*[\)\]]/',
            $text,
            $match
        ) ) {
            return $normalize( $match );
        }

        preg_match_all( '/'. $number .'/', $text, $match );
        $values = array_slice( array_map( 'floatval', $match[0] ?? [] ), 0, 3 );
        if ( count( $values ) < 2 ) {
            return null;
        }
        return array_map( fn ( $value ) => $this->intIfWhole( $value ), $values );
    }

    private function extractBalancedTemplate( string $text, string $templateName ): string {
        if ( !preg_match( '/\{\{\s*' . preg_quote( $templateName, '/' ) . '\b/i', $text, $match, PREG_OFFSET_CAPTURE ) ) {
            return '';
        }
        $start = $match[0][1];
        $length = strlen( $text );
        $depth = 0;
        for ( $i = $start; $i < $length - 1; $i++ ) {
            $pair = substr( $text, $i, 2 );
            if ( $pair === '{{' ) {
                $depth++;
                $i++;
                continue;
            }
            if ( $pair === '}}' ) {
                $depth--;
                $i++;
                if ( $depth === 0 ) {
                    return substr( $text, $start, $i + 1 - $start );
                }
            }
        }
        return '';
    }

    private function stripTemplateWrapper( string $template, string $templateName ): string {
        $template = preg_replace(
            '/^\{\{\s*' . preg_quote( $templateName, '/' ) . '\b/i',
            '',
            $template
        );
        return preg_replace( '/\s*\}\}\s*$/', '', (string)$template );
    }

    private function extractParamByLine( string $body, array $names ): string {
        foreach ( $names as $name ) {
            $pattern = preg_quote( $name, '/' );
            if ( preg_match(
                '/(?:^|\R)[ \t]*\|[ \t]*' . $pattern . '[ \t]*=[ \t]*(.*?)(?=\R[ \t]*\|[ \t]*[A-Za-z0-9_ ]+[ \t]*=|\z)/is',
                $body,
                $m
            ) ) {
                return trim( preg_replace( '/<!--.*?-->/s', '', $m[1] ) );
            }
        }
        return '';
    }

    private function cleanWikiText( string $text ): string {
        $text = preg_replace( '/<!--.*?-->/s', '', $text );
        $text = preg_replace( '/\[\[([^\]|]+)\|([^\]]+)\]\]/', '$2', $text );
        $text = preg_replace( '/\[\[([^\]]+)\]\]/', '$1', $text );
        $text = strip_tags( str_ireplace( [ '<br>', '<br/>', '<br />' ], ' ', $text ) );
        return trim( preg_replace( '/\s+/', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5 ) ) );
    }

    private function normalizeZone( $value ): string {
        $text = strtolower( trim( str_replace( '_', ' ', (string)$value ) ) );
        $text = str_replace( [ '’', '`' ], "'", $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        $text = preg_replace( '/^the\s+/', '', $text );
        $aliases = [
            'northern desert of ro' => 'north ro',
            'southern desert of ro' => 'south ro',
            'eastern karana' => 'east karana',
            'eastern plains of karana' => 'east karana',
            'western karana' => 'west karana',
            'western plains of karana' => 'west karana',
            'howling stones' => 'howling stones (charasis)',
            'charasis' => 'howling stones (charasis)',
            'estate of unrest' => 'estate of unrest',
            'nagafen\'s lair' => 'nagafen\'s lair',
        ];
        return $aliases[$text] ?? $text;
    }

    private function intIfWhole( float $value ) {
        return abs( $value - round( $value ) ) < 0.000001 ? (int)round( $value ) : round( $value, 2 );
    }

    private function maybePause(): void {
        if ( $this->pauseUs > 0 ) {
            usleep( $this->pauseUs );
        }
    }

    private function writeAtomicJson( string $path, array $data ): void {
        $dir = dirname( $path );
        if ( !is_dir( $dir ) && !mkdir( $dir, 0755, true ) && !is_dir( $dir ) ) {
            $this->fatalError( "Unable to create output directory: {$dir}" );
        }
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
        $tmp = $path . '.tmp.' . getmypid();
        if ( file_put_contents( $tmp, $json, LOCK_EX ) === false ) {
            $this->fatalError( "Unable to write temporary output: {$tmp}" );
        }
        if ( !rename( $tmp, $path ) ) {
            @unlink( $tmp );
            $this->fatalError( "Unable to publish output: {$path}" );
        }
    }
}

$maintClass = BuildEyeOfZommPack::class;
require_once RUN_MAINTENANCE_IF_MAIN;
