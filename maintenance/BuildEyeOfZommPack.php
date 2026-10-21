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
    private array $raidNpcIndex = [];
    private array $npcVariantIndex = [];
    private array $wikiEntityNames = [ 'npcs' => [], 'items' => [], 'titles' => [] ];
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
        $this->loadRaidInstanceIndex( $wikiPageFactory, $this->listPagesInCategory( $dbr, 'Zones', 0 ) );

        $this->output( "EQLWiki - Eye of Zomm static dataset build\n" );
        $this->output( "HTTP/API requests: 0\n" );
        $this->output( "Per-page pause: {$pauseMs} ms\n" );
        $this->output( "Current era: {$currentEra}\n\n" );

        $npcRows = $this->listPagesInCategory( $dbr, 'NPCs', $limit );
        $itemRows = $this->listPagesInCategory( $dbr, 'Items', $limit );
        // Public namespace titles (including redirect aliases) are a fallback
        // for pages without entity templates/categories. No revision, user,
        // account or private namespace data enters this presence-only index.
        $this->indexWikiEntityCandidates( $npcRows, $itemRows,
            $dbr->select( 'page', [ 'page_title' ], [ 'page_namespace' => NS_MAIN ], __METHOD__ ) );

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
        $npcs = $this->applyNpcInstanceVariants( $npcs );

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

        $npcs = $this->sortNpcRecords( $npcs );
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
                'wikiEntitiesComplete' => $limit === 0,
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
            'wikiEntities' => $this->wikiEntityIndex(),
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
        $allRecords = [];
        foreach ( $this->instanceSections( $text, true ) as $section ) {
            $text = $section['text'];

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
                continue;
            }
            $body = $this->stripTemplateWrapper( $template, $templateName );
            $this->rememberWikiEntity( 'npcs', $title->getText(), $this->cleanWikiText( $this->extractParamByLine( $body, [ 'name' ] ) ) );
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
            // The NPC template describes its explicit spawn zones. Categories can
            // be incomplete or stale, so use them only when no field zone resolves.
            $zoneText = $this->extractParamByLine( $body, [ 'zone' ] );
            $zones = $this->detectZonesFromField( $zoneText );
            if ( !$zones ) {
                $zones = $this->detectZonesFromCategories( $categories );
            }
            if ( !$zones ) {
                $zones = $this->detectZonesFromTitle( $title->getText() );
            }
            if ( !$zones ) {
                $zones = [ '' ];
            }
            $era = $this->detectEra( $categories );
            $raidCategory = in_array( 'raid encounters', array_map(
                static fn ( $value ) => strtolower( trim( str_replace( '_', ' ', (string)$value ) ) ), $categories
            ), true );
            $respawn = $this->cleanWikiText( $this->extractParamByLine( $body, [ 'respawn_time' ] ) );
            $raidRespawn = (bool)preg_match( '/(?:only[^.]*raid\s+instances?|once\s+per\s+raid\s+instance)/i', $respawn );
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
                    'raidInstance' => $section['raidInstance'],
                    'instanceType' => $section['raidInstance'] ? 'raid' : 'normal',
                    '_instanceExplicit' => $section['explicit'],
                    '_raidOnly' => $raidCategory || $raidRespawn,
                ];
                if ( $section['explicit'] ) {
                    $record['instanceSources'] = [ [ 'wikiTitle' => $title->getText(),
                        'revisionId' => $revisionId, 'section' => $section['heading'] ] ];
                }
                elseif ( $raidCategory || $raidRespawn ) {
                    $record['instanceSources'] = [ [ 'wikiTitle' => $title->getText(), 'revisionId' => $revisionId,
                        'section' => '', 'reason' => $raidCategory ? 'Raid Encounters category' : 'raid-only respawn field' ] ];
                }
                if ( $loc !== null ) {
                    $record['loc'] = $loc;
                }
                if ( $description !== '' ) {
                    $record['description'] = $description;
                }
                $records[] = $record;
            }
            $allRecords = array_merge( $allRecords, $records );
        }
        return $allRecords;
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
        $itemName = $this->extractParamByLine( $body, [ 'itemname', 'item_name' ] );
        if ( $itemName === '' ) {
            $itemName = $title->getText();
        }
        $this->rememberWikiEntity( 'items', $title->getText(), $this->cleanWikiText( $itemName ) );
        $statsBlock = $this->extractParamByLine( $body, [ 'statsblock', 'stats_block' ] );
        if ( $statsBlock === '' ) {
            return null;
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
        $revision = $page->getRevisionRecord();
        $revisionId = $revision ? (int)$revision->getId() : 0;
        $dropSources = [];
        foreach ( $this->instanceSections( $text, true ) as $section ) {
            $sectionTemplate = $this->extractBalancedTemplate( $section['text'], 'Itempage' );
            if ( $sectionTemplate === '' ) {
                continue;
            }
            $sectionBody = $this->stripTemplateWrapper( $sectionTemplate, 'Itempage' );
            $sectionItemName = $this->cleanWikiText( $this->extractParamByLine( $sectionBody, [ 'itemname', 'item_name' ] ) );
            if ( $sectionItemName !== '' && strcasecmp( $sectionItemName, $this->cleanWikiText( $itemName ) ) !== 0 ) {
                continue;
            }
            $sources = $this->parseDropSources(
                $this->extractParamByLine( $sectionBody, [ 'dropsfrom', 'drops_from' ] ),
                $section['raidInstance'], $section['explicit']
            );
            foreach ( $sources as $source ) {
                if ( $source['_instanceExplicit'] ?? false ) {
                    $source['instanceSources'] = [ [ 'wikiTitle' => $title->getText(),
                        'revisionId' => $revisionId, 'section' => $source['raidInstance'] ? 'Raid Instance' : 'Normal Instance' ] ];
                }
                $dropSources[] = $source;
            }
        }
        $dropSources = $this->expandDropSourceVariants( $dropSources );

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
        if ( preg_match( '/^ALL\s+EXCEPT\b(.*)$/s', $text, $excluded ) ) {
            preg_match_all( '/\b[A-Z]{3}\b/', $excluded[1], $matches );
            $playable = array_values( array_diff( self::CLASS_CODES, [ 'ALL' ] ) );
            $allowed = array_values( array_diff( $playable, $matches[0] ?? [] ) );
            // Empty metadata means unknown to clients; NONE means no class can
            // equip the item and must not silently become unrestricted.
            return $allowed ?: [ 'NONE' ];
        }
        if ( preg_match( '/^NONE\b/', $text ) ) {
            return [ 'NONE' ];
        }
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

    private function parseDropSources( string $dropsFrom, bool $raidInstance = false, bool $explicit = false ): array {
        $sources = [];
        $seen = [];
        $counts = [ 0, 0 ];
        foreach ( $this->instanceSections( $dropsFrom, false, $raidInstance, $explicit ) as $section ) {
            foreach ( $this->parseDropSourceLines( $section['text'], $section['raidInstance'], $section['explicit'] ) as $source ) {
                $key = $this->instanceKey( $source['zone'], $source['name'] ) . '|' . (int)$source['raidInstance'];
                $variant = (int)$source['raidInstance'];
                if ( !isset( $seen[$key] ) && $counts[$variant] < 24 ) {
                    $sources[] = $source;
                    $seen[$key] = true;
                    $counts[$variant]++;
                }
            }
        }
        return $sources;
    }

    private function parseDropSourceLines( string $dropsFrom, bool $raidInstance, bool $explicit ): array {
        if ( trim( $dropsFrom ) === '' ) {
            return [];
        }

        $sources = [];
        $seen = [];
        $currentHeading = '';
        $currentZone = '';
        $headingHadBullets = false;

        $defaultRaid = $raidInstance;
        $defaultExplicit = $explicit;
        $flushStandaloneHeading = function () use ( &$sources, &$seen, &$currentHeading, &$currentZone, &$headingHadBullets, &$raidInstance, &$explicit ): void {
            if ( $currentHeading === '' || $headingHadBullets || $currentZone !== '' ) {
                return;
            }
            $key = '|'.strtolower( $currentHeading ) . '|' . (int)$raidInstance;
            if ( !isset( $seen[$key] ) ) {
                $sources[] = [ 'zone' => '', 'name' => $currentHeading, 'raidInstance' => $raidInstance,
                    'instanceType' => $raidInstance ? 'raid' : 'normal', '_instanceExplicit' => $explicit ];
                $seen[$key] = true;
            }
        };

        foreach ( preg_split( '/\r\n|\n|\r/', $dropsFrom ) as $line ) {
            $line = trim( preg_replace( '/<!--.*?-->/s', '', (string)$line ) );
            if ( $line === '' ) {
                continue;
            }
            if ( preg_match( '/^={1,6}.*?={1,6}$/', $line ) ) {
                continue;
            }

            // A standalone wiki link is normally either a zone heading or a
            // non-NPC source such as Fletching / Quest / Purchased.
            if ( preg_match( '/^\[\[\s*([^|\]]+)(?:\|[^\]]+)?\s*\]\]\s*$/', $line, $m ) ) {
                $flushStandaloneHeading();
                $currentHeading = $this->cleanWikiText( $line );
                $headingHadBullets = false;
                [ $zoneTitle, $anchor ] = array_pad( explode( '#', $m[1], 2 ), 2, '' );
                $raidInstance = $defaultRaid;
                $explicit = $defaultExplicit;
                if ( preg_match( '/^raid[ _]+instance$/i', trim( $anchor ) ) ) {
                    $raidInstance = true;
                    $explicit = true;
                }
                $zoneKey = $this->normalizeZone( $zoneTitle );
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
                $key = strtolower( $currentZone . '|' . $name ) . '|' . (int)$raidInstance;
                if ( isset( $seen[$key] ) ) {
                    continue;
                }
                $sources[] = [ 'zone' => $currentZone, 'name' => $name, 'raidInstance' => $raidInstance,
                    'instanceType' => $raidInstance ? 'raid' : 'normal', '_instanceExplicit' => $explicit ];
                $seen[$key] = true;
                continue;
            }

            // Conservative fallback for a direct plain-text source line.
            $name = $this->cleanWikiText( $line );
            if ( $name !== '' && strlen( $name ) <= 160 ) {
                $key = '|'.strtolower( $name ) . '|' . (int)$raidInstance;
                if ( !isset( $seen[$key] ) ) {
                    $sources[] = [ 'zone' => '', 'name' => $name, 'raidInstance' => $raidInstance,
                        'instanceType' => $raidInstance ? 'raid' : 'normal', '_instanceExplicit' => $explicit ];
                    $seen[$key] = true;
                }
            }
        }

        $flushStandaloneHeading();
        return $sources;
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

        // EQLWiki loc templates preserve EverQuest's displayed Y, X, Z order.
        // The app normalizes this dataset boundary into world X, Y, Z.
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

    private function instanceSections( string $text, bool $respectTemplates = false, bool $defaultRaid = false, bool $defaultExplicit = false ): array {
        $text = preg_replace( '/<!--.*?-->|<(nowiki|pre|source|syntaxhighlight)\b[^>]*>.*?<\/\1>/is', '', $text );
        $sections = [];
        $body = '';
        $raid = $defaultRaid;
        $explicit = $defaultExplicit;
        $heading = $raid ? 'Raid Instance' : 'Normal Instance';
        $scopeLevel = null;
        $templateDepth = 0;
        foreach ( preg_split( '/\r\n|\n|\r/', $text ) as $line ) {
            if ( ( !$respectTemplates || $templateDepth === 0 ) && preg_match( '/^(={1,6})\s*(.*?)\s*\1[ \t]*$/', trim( $line ), $match ) ) {
                $label = strtolower( trim( str_replace( [ '_', "'''", "''" ], [ ' ', '', '' ], $this->cleanWikiText( $match[2] ) ) ) );
                $isRaid = (bool)preg_match( '/^raid\s+instances?$/', $label );
                $isNormal = (bool)preg_match( '/^(?:normal|public)\s+instances?$/', $label );
                if ( $isRaid || $isNormal || ( $scopeLevel !== null && strlen( $match[1] ) <= $scopeLevel ) ) {
                    if ( trim( $body ) !== '' ) {
                        $sections[] = [ 'text' => $body, 'raidInstance' => $raid, 'explicit' => $explicit, 'heading' => $heading ];
                    }
                    $body = '';
                    $raid = $isRaid;
                    $explicit = $isRaid || $isNormal;
                    $heading = $isRaid ? 'Raid Instance' : 'Normal Instance';
                    $scopeLevel = $explicit ? strlen( $match[1] ) : null;
                    continue;
                }
            }
            $body .= $line . "\n";
            $templateDepth = max( 0, $templateDepth + substr_count( $line, '{{' ) - substr_count( $line, '}}' ) );
        }
        if ( trim( $body ) !== '' ) {
            $sections[] = [ 'text' => $body, 'raidInstance' => $raid, 'explicit' => $explicit, 'heading' => $heading ];
        }
        return $sections;
    }

    private function instanceKey( string $zone, string $name ): string {
        return $this->normalizeZone( $zone ) . '|' . strtolower( trim( str_replace( '_', ' ', $name ) ) );
    }

    private function wikiLinkTargets( string $text ): array {
        preg_match_all( '/\[\[\s*([^|\]#]+)(?:#[^|\]]*)?(?:\|[^\]]*)?\]\]/', $text, $matches );
        return array_values( array_unique( array_map( 'trim', $matches[1] ?? [] ) ) );
    }

    private function loadRaidInstanceIndex( $wikiPageFactory, array $zoneRows = [] ): void {
        $this->raidNpcIndex = [];
        $candidates = [];
        foreach ( $this->zoneCatalog as $zone ) {
            $candidates[(string)( $zone['wikiTitle'] ?? $zone['name'] )] = $zone;
        }
        // Discover source pages from the wiki's zone category. The catalog is
        // an alias/map registry, never a hardcoded list of zones with raids.
        foreach ( $zoneRows as $row ) {
            $title = str_replace( '_', ' ', (string)$row->page_title );
            $known = $this->zoneAliasMap[$this->normalizeZone( $title )] ?? null;
            $candidates[$title] = $known ?: [ 'name' => $title, 'wikiTitle' => $title, '_newZone' => true ];
            $candidates[$title]['wikiTitle'] = $title;
        }
        foreach ( $candidates as $zone ) {
            $zoneTitle = (string)( $zone['wikiTitle'] ?? $zone['name'] );
            $title = Title::newFromText( $zoneTitle, NS_MAIN );
            if ( !$title || !$title->exists() ) {
                continue;
            }
            $page = $wikiPageFactory->newFromTitle( $title );
            $content = $page->getContent();
            if ( !$content || !method_exists( $content, 'getText' ) ) {
                continue;
            }
            if ( $zone['_newZone'] ?? false ) {
                $hasRaid = false;
                foreach ( $this->instanceSections( $content->getText(), true ) as $section ) {
                    $hasRaid = $hasRaid || $section['raidInstance'];
                }
                if ( !$hasRaid ) {
                    continue;
                }
                unset( $zone['_newZone'] );
                $zone['viewerName'] = $zone['name'];
                $zone['aliases'] = [];
                $this->zoneCatalog[] = $zone;
                $this->zoneAliasMap[$this->normalizeZone( $zone['name'] )] = $zone;
            }
            $revision = $page->getRevisionRecord();
            $this->collectZoneRaidReferences( $content->getText(), (string)$zone['name'], $zoneTitle,
                $revision ? (int)$revision->getId() : 0, $wikiPageFactory );
        }
    }

    private function collectZoneRaidReferences( string $text, string $zone, string $wikiTitle, int $revisionId, $wikiPageFactory = null ): void {
        $source = [ 'wikiTitle' => $wikiTitle, 'revisionId' => $revisionId, 'section' => 'Raid Instance' ];
        $add = function ( string $name ) use ( $zone, $source, $wikiPageFactory ): void {
            $names = [ $name ];
            if ( $wikiPageFactory ) {
                $targetTitle = Title::newFromText( $name, NS_MAIN );
                $page = $targetTitle && $targetTitle->exists() ? $wikiPageFactory->newFromTitle( $targetTitle ) : null;
                if ( $page && method_exists( $page, 'getRedirectTarget' ) ) {
                    $target = $page->getRedirectTarget();
                    if ( $target ) {
                        $names[] = $target->getText();
                    }
                }
            }
            foreach ( $names as $resolved ) {
                $this->raidNpcIndex[$this->instanceKey( $zone, $resolved )] = [ 'only' => false, 'source' => $source ];
            }
        };
        foreach ( $this->instanceSections( $text, true ) as $section ) {
            if ( !$section['raidInstance'] ) {
                continue;
            }
            $npcField = false;
            $targetTable = false;
            foreach ( preg_split( '/\r\n|\n|\r/', $section['text'] ) as $line ) {
                if ( preg_match( '/^[!|]\s*.*?(?:Types of Monsters|(?:Mini\s+|Raid\s+)?Boss NPCs|Mini Boss NPCs)/i', $line ) ) {
                    $npcField = true;
                } elseif ( preg_match( '/^\|-|^\|\}/', trim( $line ) ) ) {
                    $npcField = false;
                    if ( preg_match( '/^\|\}/', trim( $line ) ) ) {
                        $targetTable = false;
                    }
                }
                if ( preg_match( '/^!.*\bMob Name\b/i', trim( $line ) ) ) {
                    $targetTable = true;
                }
                if ( $npcField || ( $targetTable && preg_match( '/^\|\s*\[\[/', trim( $line ) ) ) ) {
                    $targets = $this->wikiLinkTargets( $targetTable ? explode( '||', $line )[0] : $line );
                    foreach ( $targets as $name ) {
                        $add( $name );
                    }
                }
            }
        }
        // Bind an explicit "Only" annotation to its nearest preceding link,
        // not every NPC mentioned in the same room/map paragraph.
        preg_match_all( '/\[\[\s*([^|\]#]+)(?:\|[^\]]*)?\]\](?:(?!\[\[).){0,200}?\(Raid Instance Only\)/i', $text, $matches );
        foreach ( $matches[1] ?? [] as $name ) {
            $key = $this->instanceKey( $zone, trim( $name ) );
            if ( isset( $this->raidNpcIndex[$key] ) ) {
                $this->raidNpcIndex[$key]['only'] = true;
            }
        }
    }

    private function sortNpcRecords( array $records ): array {
        // Legacy clients collapse zone/name pairs with last-record-wins. Keep
        // public coordinates and stats by emitting the normal variant last.
        usort( $records, static function ( $a, $b ) {
            return [ strtolower( $a['zone'] ), strtolower( $a['name'] ), -(int)$a['raidInstance'] ]
                <=> [ strtolower( $b['zone'] ), strtolower( $b['name'] ), -(int)$b['raidInstance'] ];
        } );
        return $records;
    }

    private function applyNpcInstanceVariants( array $records ): array {
        $out = [];
        $this->npcVariantIndex = [];
        foreach ( $records as $record ) {
            $key = $this->instanceKey( $record['zone'], $record['name'] );
            $membership = $this->raidNpcIndex[$key] ?? null;
            $raidOnly = (bool)( $record['_raidOnly'] ?? false ) || (bool)( $membership['only'] ?? false );
            $explicit = (bool)( $record['_instanceExplicit'] ?? false );
            unset( $record['_instanceExplicit'], $record['_raidOnly'] );
            if ( !$explicit && $raidOnly ) {
                $record['raidInstance'] = true;
                $record['instanceType'] = 'raid';
            }
            $variants = [ $record ];
            if ( $membership && !$record['raidInstance'] && !$explicit ) {
                $raid = $record;
                $raid['raidInstance'] = true;
                $raid['instanceType'] = 'raid';
                // A shared NPC's public spawn point does not establish its
                // placement inside a separate raid instance.
                unset( $raid['loc'] );
                $variants[] = $raid;
            }
            foreach ( $variants as $variant ) {
                if ( $membership && $variant['raidInstance'] ) {
                    $variant['instanceSources'] = array_merge( $variant['instanceSources'] ?? [], [ $membership['source'] ] );
                }
                $variantKey = $key . '|' . (int)$variant['raidInstance'];
                $out[$variantKey] = $variant;
                $this->npcVariantIndex[$key][(int)$variant['raidInstance']] = $variant['instanceSources'] ?? [];
            }
        }
        return array_values( $out );
    }

    private function expandDropSourceVariants( array $sources ): array {
        $out = [];
        foreach ( $sources as $source ) {
            $key = $this->instanceKey( $source['zone'], $source['name'] );
            $variants = $this->npcVariantIndex[$key] ?? null;
            $explicit = (bool)( $source['_instanceExplicit'] ?? false );
            unset( $source['_instanceExplicit'] );
            foreach ( $explicit || !$variants ? [ (int)$source['raidInstance'] => $source['instanceSources'] ?? [] ] : $variants as $raid => $provenance ) {
                $variant = $source;
                $variant['raidInstance'] = (bool)$raid;
                $variant['instanceType'] = $raid ? 'raid' : 'normal';
                if ( $provenance ) {
                    $variant['instanceSources'] = $provenance;
                }
                $out[$key . '|' . $raid] = $variant;
            }
        }
        return array_values( $out );
    }

    private function indexWikiEntityCandidates( array $npcRows, array $itemRows, iterable $titleRows ): void {
        foreach ( [ 'npcs' => $npcRows, 'items' => $itemRows, 'titles' => $titleRows ] as $kind => $rows ) {
            foreach ( $rows as $row ) {
                $this->rememberWikiEntity( $kind, str_replace( '_', ' ', $row->page_title ) );
            }
        }
    }

    private function rememberWikiEntity( string $kind, string ...$names ): void {
        foreach ( $names as $name ) {
            $name = trim( preg_replace( '/\s+/', ' ', str_replace( '_', ' ', $name ) ) );
            if ( $name !== '' ) {
                $this->wikiEntityNames[$kind][strtolower( $name )] = $name;
            }
        }
    }

    private function wikiEntityIndex(): array {
        $index = [];
        foreach ( [ 'npcs', 'items', 'titles' ] as $kind ) {
            $index[$kind] = array_values( $this->wikiEntityNames[$kind] ?? [] );
            usort( $index[$kind], 'strcasecmp' );
        }
        return $index;
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
