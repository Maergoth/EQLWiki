<?php
/**
 * Source guards: php ops/test-item-reader-copy.php
 * Real unsaved parser fixtures: php maintenance/run.php ./ops/test-item-reader-copy.php
 * Optional parser overlays: --itempage-source, --lore-source, --equipment-source, --zone-patch-source.
 * No wiki pages are saved, purged or imported by either mode.
 */
namespace MediaWiki\Maintenance {
    if ( !class_exists( Maintenance::class ) ) {
        define( 'EQL_READER_COPY_SOURCE_ONLY', true );
        abstract class Maintenance {}
    }
}

namespace {
    use MediaWiki\Content\WikitextContent;
    use MediaWiki\Linker\LinkTarget;
    use MediaWiki\Maintenance\Maintenance;
    use MediaWiki\Parser\ParserOptions;
    use MediaWiki\Revision\MutableRevisionRecord;
    use MediaWiki\Revision\RevisionRecord;
    use MediaWiki\Revision\SlotRecord;
    use MediaWiki\Title\Title;

    if ( PHP_SAPI !== 'cli' ) {
        exit( "Run this fixture from the command line.\n" );
    }
    require_once __DIR__ . '/sync-item-reader-copy.php';

    class EQLReaderCopyPublisherProbe extends EQLSyncItemReaderCopy {
        protected function fatalError( $message, $exitCode = 1 ): never {
            throw new RuntimeException( $message );
        }
    }

    function readerCopyCheck( bool $condition, string $message ): void {
        if ( !$condition ) {
            throw new RuntimeException( $message );
        }
    }

    /** Actual publisher validators with synthetic invalid inputs; no settings/database required. */
    function runReaderCopySourceChecks( array $paths ): int {
        $publisher = ( new ReflectionClass( EQLReaderCopyPublisherProbe::class ) )->newInstanceWithoutConstructor();
        $validate = new ReflectionMethod( EQLSyncItemReaderCopy::class, 'validateSource' );
        $delta = new ReflectionMethod( EQLSyncItemReaderCopy::class, 'validateDelta' );
        $zonePatch = new ReflectionMethod( EQLSyncItemReaderCopy::class, 'applyZoneLabels' );
        $sources = [];
        $checks = 0;
        foreach ( $paths as $key => $path ) {
            $sources[$key] = file_get_contents( $path );
            $validate->invoke( $publisher, $key, $sources[$key] );
            $checks++;
        }
        $reject = static function ( callable $operation, string $message ) use ( &$checks ): void {
            try {
                $operation();
            } catch ( RuntimeException $e ) {
                $checks++;
                return;
            }
            throw new RuntimeException( $message );
        };
        foreach ( [ 'Template:Unrelated', '', '../itempage' ] as $badKey ) {
            $reject( static fn () => $validate->invoke( $publisher, $badKey, $sources['itempage'] ), 'Reject arbitrary publication targets.' );
        }
        foreach ( [ '', false, "bad\0source", "\xFF" ] as $badSource ) {
            $reject( static fn () => $validate->invoke( $publisher, 'itempage', $badSource ), 'Reject invalid source bytes.' );
        }
        foreach ( [ '{{{statsblock}}}', '[[Category:Items]]', 'class="hbdiv"' ] as $required ) {
            $reject( static fn () => $validate->invoke( $publisher, 'itempage', str_replace( $required, '', $sources['itempage'] ) ), 'Retain item stat, category and hover contracts.' );
        }
        foreach ( [ '[[Category:Missing Lore]]', '[[Category:Lore Items]]', '[[File:Spellicon_U.png|18px]]' ] as $required ) {
            $reject( static fn () => $validate->invoke( $publisher, 'item-lore-missing', str_replace( $required, '', $sources['item-lore-missing'] ) ), 'Retain lore categories and icon.' );
        }

        // Reviewed public baselines: Itempage 176024 and Item Lore Missing 132306.
        // Reversing only the bounded copy changes must recover the exact reviewed bytes.
        $fields = [
            'dropsfrom' => [ 'This item is not dropped by mobs.', 'No mob drop source has been documented.' ],
            'soldby' => [ 'This item cannot be purchased from merchants.', 'No merchant source has been documented.' ],
            'relatedquests' => [ 'This item has no related quests.', 'No related quest has been documented.' ],
            'playercrafted' => [ 'This item is not crafted by players.', 'No player-crafted recipe has been documented.' ],
            'recipes' => [ 'This item is not used in player tradeskills.', 'No tradeskill use has been documented.' ],
        ];
        $oldItem = rtrim( $sources['itempage'], "\r\n" );
        foreach ( $fields as $field => $messages ) {
            $before = '{{{' . $field . "|<ul class='esec'><li> <span class='esec'>" . $messages[0] . '</span> </li></ul>}}}';
            $after = '{{#if: {{{' . $field . '|}}} | {{{' . $field . "}}} | <ul class='esec'><li> <span class='esec'>" . $messages[1] . '</span> </li></ul>}}';
            readerCopyCheck( substr_count( $oldItem, $after ) === 1, 'Each empty-field branch must have exactly one reviewed replacement.' );
            $checks++;
            $oldItem = str_replace( $after, $before, $oldItem );
        }
        readerCopyCheck( hash( 'sha256', $oldItem ) === 'ca3cf7bc2433920cb1ab381d9911db42bc2515e7c44e2d9288679e11130cd1ef', 'Preserve all Itempage source outside the five reviewed replacements.' );
        $checks++;
        $delta->invoke( $publisher, 'itempage', $oldItem, $sources['itempage'] );
        $checks++;
        $oldLore = str_replace(
            [ '<span class="eql-item-lore-missing" style=', 'Item lore has not been documented. [{{fullurl:{{FULLPAGENAME}}|action=edit}} Help add lore]' ],
            [ '<span style=', "'''Item Lore missing.''' Replace this with <nowiki>{{Item Lore | 'Lore Here' }}</nowiki>" ],
            rtrim( $sources['item-lore-missing'], "\r\n" )
        );
        readerCopyCheck( hash( 'sha256', $oldLore ) === 'c123710d16f4d2f9783e8c33d6ec48cb262282577d7e6856493206ac0826a39f', 'Preserve lore source, icon and documentation outside the reviewed notice.' );
        $checks++;
        $delta->invoke( $publisher, 'item-lore-missing', $oldLore, $sources['item-lore-missing'] );
        $checks++;
        foreach ( [ 'itempage' => $oldItem, 'item-lore-missing' => $oldLore ] as $key => $old ) {
            $reject( static fn () => $delta->invoke( $publisher, $key, $old, $sources[$key] . '\nUnrelated change' ), 'Reject unrelated template changes even if required markers remain.' );
        }

        // Equipment By Class baseline 162217; only a fixed appended block is accepted.
        $oldEquipment = strstr( $sources['equipment-by-class'], '<!-- EQL_EQUIPMENT_CLASS_LINKS_START -->', true );
        readerCopyCheck( is_string( $oldEquipment ), 'Equipment source must contain its reviewed appended block.' );
        $checks++;
        $delta->invoke( $publisher, 'equipment-by-class', $oldEquipment, $sources['equipment-by-class'] );
        $checks++;
        $reject( static fn () => $delta->invoke( $publisher, 'equipment-by-class', $oldEquipment, str_replace( 'Here you will find', 'Unrelated introductory replacement', $sources['equipment-by-class'] ) ), 'Do not rewrite equipment content above the appended block.' );
        $reject( static fn () => $validate->invoke( $publisher, 'equipment-by-class', str_replace( 'Category:Warrior Equipment', 'Category:Unrelated', $sources['equipment-by-class'] ) ), 'Keep every reviewed class category.' );

        // Zones baseline 199986; use a literal patch without copying its large map source.
        $pairs = json_decode( $sources['zones-map-labels'], true, 512, JSON_THROW_ON_ERROR );
        $oldMap = '<div class="synthetic-map" data-coordinates="12,34,56,78">' . implode( '|', array_keys( $pairs ) ) . '</div>';
        $newMap = $zonePatch->invoke( $publisher, $oldMap );
        readerCopyCheck( $newMap === str_replace( array_keys( $pairs ), array_values( $pairs ), $oldMap ), 'Only reviewed anchor labels change; map attributes remain byte-for-byte.' );
        $checks++;
        readerCopyCheck( $zonePatch->invoke( $publisher, $newMap ) === $newMap, 'An already normalized map remains unchanged.' );
        $checks++;
        $delta->invoke( $publisher, 'zones-map-labels', $oldMap, $newMap );
        $checks++;
        $reject( static fn () => $zonePatch->invoke( $publisher, $oldMap . array_key_first( $pairs ) ), 'Refuse duplicate ambiguous old labels.' );
        $reject( static fn () => $validate->invoke( $publisher, 'zones-map-labels', json_encode( [ 'Other label' => 'Replacement' ] ) ), 'Reject arbitrary map patch pairs.' );
        $reject( static fn () => $delta->invoke( $publisher, 'zones-map-labels', $oldMap, str_replace( '12,34,56,78', '0,0,0,0', $newMap ) ), 'Reject unrelated map geometry changes.' );

        global $IP;
        $savedIP = $IP ?? null;
        $IP = dirname( __DIR__ );
        $backupPath = new ReflectionMethod( EQLSyncItemReaderCopy::class, 'resolveBackupPath' );
        $writeBackup = new ReflectionMethod( EQLSyncItemReaderCopy::class, 'writeBackup' );
        $temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eql-reader-copy-' . bin2hex( random_bytes( 8 ) ) . '.wiki';
        try {
            $resolved = $backupPath->invoke( $publisher, $temporary );
            $checks++;
            $writeBackup->invoke( $publisher, $resolved, "Synthetic recovery bytes.\n" );
            readerCopyCheck( file_get_contents( $resolved ) === "Synthetic recovery bytes.\n", 'Recovery copies preserve exact source bytes.' );
            $checks++;
            $reject( static fn () => $backupPath->invoke( $publisher, $resolved ), 'Refuse an existing backup path.' );
            $reject( static fn () => $writeBackup->invoke( $publisher, $resolved, 'Overwrite' ), 'Never overwrite a recovery file.' );
            $reject( static fn () => $backupPath->invoke( $publisher, __DIR__ . '/synthetic-public-backup.wiki' ), 'Refuse backups under the served application root.' );
            // A dot-directory name does not make a recovery copy private on a host.
            $fakeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eql-reader-public-' . bin2hex( random_bytes( 8 ) );
            readerCopyCheck( mkdir( $fakeRoot, 0700 ) && mkdir( $fakeRoot . '/.local', 0700 ), 'Create synthetic served-root fixture.' );
            $beforeFakeIP = $IP;
            try {
                $IP = $fakeRoot;
                $reject( static fn () => $backupPath->invoke( $publisher, $fakeRoot . '/.local/recovery.wiki' ), 'Refuse .local recovery copies inside a served root.' );
            } finally {
                $IP = $beforeFakeIP;
                rmdir( $fakeRoot . '/.local' );
                rmdir( $fakeRoot );
            }
        } finally {
            if ( is_file( $temporary ) ) {
                unlink( $temporary );
            }
            $IP = $savedIP;
        }
        return $checks;
    }

    function readerCopyDefaultPaths(): array {
        return [
            'itempage' => __DIR__ . '/itempage.wiki',
            'item-lore-missing' => __DIR__ . '/item-lore-missing.wiki',
            'equipment-by-class' => __DIR__ . '/equipment-by-class.wiki',
            'zones-map-labels' => __DIR__ . '/zone-map-labels.json',
        ];
    }

    if ( defined( 'EQL_READER_COPY_SOURCE_ONLY' ) ) {
        $count = runReaderCopySourceChecks( readerCopyDefaultPaths() );
        echo "Passed $count reader-copy source, scope and recovery guards; no wiki or parser integration.\n";
        return;
    }

    class EQLTestItemReaderCopy extends Maintenance {
        private int $checks = 0;

        public function __construct() {
            parent::__construct();
            $this->addDescription( 'Parse synthetic item reader-copy fixtures using unsaved template overlays; no page writes.' );
            foreach ( [ 'itempage-source', 'lore-source', 'equipment-source', 'zone-patch-source' ] as $option ) {
                $this->addOption( $option, 'Reviewed source override for the read-only fixture', false, true );
            }
        }

        private function check( bool $condition, string $message ): void {
            readerCopyCheck( $condition, $message );
            $this->checks++;
        }

        private function parse( string $text, string $title = 'EQL synthetic item reader copy' ): array {
            $parser = $this->getServiceContainer()->getParserFactory()->create();
            $output = $parser->parse( $text, Title::newFromText( $title ), ParserOptions::newFromAnon() );
            $html = $output->getRawText();
            $this->check( !preg_match( '/class=["\'][^"\']*\b(?:error|scribunto-error)\b/i', $html ), 'Synthetic input must not produce parser errors: ' . strip_tags( $html ) );
            return [ $html, $output ];
        }

        private function item( array $fields = [] ): string {
            $text = "{{Itempage\n|itemname=EQL synthetic item reader copy\n|lucy_img_ID=0\n|statsblock=Slot: PRIMARY<br>Skill: 2H Slashing Atk Delay: 45<br>DMG: 17<br>Class: ALL<br>Race: ALL<br>\n";
            foreach ( $fields as $name => $value ) {
                $text .= '|' . $name . '=' . $value . "\n";
            }
            return $text . '}}';
        }

        public function execute() {
            $paths = readerCopyDefaultPaths();
            foreach ( [ 'itempage' => 'itempage-source', 'item-lore-missing' => 'lore-source', 'equipment-by-class' => 'equipment-source', 'zones-map-labels' => 'zone-patch-source' ] as $key => $option ) {
                $paths[$key] = $this->getOption( $option, $paths[$key] );
            }
            $sourceChecks = runReaderCopySourceChecks( $paths );
            $overlays = [
                'Template:Itempage' => file_get_contents( $paths['itempage'] ),
                'Template:Item Lore Missing' => file_get_contents( $paths['item-lore-missing'] ),
            ];
            $this->getServiceContainer()->getHookContainer()->register( 'BeforeParserFetchTemplateRevisionRecord',
                static function ( ?LinkTarget $contextTitle, LinkTarget $title, bool &$skip, ?RevisionRecord &$revision ) use ( $overlays ) {
                    $source = $overlays[$title->getPrefixedText()] ?? null;
                    if ( $source !== null ) {
                        $revision = new MutableRevisionRecord( Title::castFromLinkTarget( $title ) );
                        $revision->setContent( SlotRecord::MAIN, new WikitextContent( $source ) );
                    }
                }
            );
            $fallbacks = [ 'No mob drop source has been documented.', 'No merchant source has been documented.',
                'No related quest has been documented.', 'No player-crafted recipe has been documented.', 'No tradeskill use has been documented.' ];
            $fields = [ 'dropsfrom', 'soldby', 'relatedquests', 'playercrafted', 'recipes' ];
            foreach ( [ [], array_fill_keys( $fields, '' ), array_fill_keys( $fields, '   ' ) ] as $parameters ) {
                [ $html ] = $this->parse( $this->item( $parameters ) );
                foreach ( $fallbacks as $fallback ) {
                    $this->check( substr_count( $html, $fallback ) === 1, 'Omitted, empty and whitespace fields must each show one neutral default.' );
                }
                foreach ( [ 'cannot be purchased', 'has no related quests', 'is not crafted', 'is not used in player tradeskills', 'is not dropped' ] as $falseAbsence ) {
                    $this->check( !str_contains( $html, $falseAbsence ), 'An undocumented field must not assert factual absence.' );
                }
                $this->check( str_contains( $html, 'itemdata' ) && str_contains( $html, 'DMG: 17' ) && str_contains( $html, '45' ), 'The actual Itembox must retain synthetic stats.' );
            }
            [ $html ] = $this->parse( $this->item( array_fill_keys( $fields, '0' ) ) );
            foreach ( $fallbacks as $fallback ) {
                $this->check( !str_contains( $html, $fallback ), 'A scalar zero is supplied content, not an empty field.' );
            }
            $this->check( preg_match_all( '/>\s*0\s*</', $html ) >= 5, 'All five explicit zero values must remain visible.' );
            $authored = [];
            foreach ( $fields as $index => $field ) {
                $authored[$field] = '[[EQL synthetic target ' . $index . '|Authored link ' . $index . ']]';
            }
            $authored['notes'] = 'Authored note remains. {{Item Lore Missing}}';
            $authored['bookcontents'] = 'Authored book text.';
            $authored['foraged'] = 'Authored forage source.';
            [ $html, $output ] = $this->parse( $this->item( $authored ) );
            foreach ( range( 0, 4 ) as $index ) {
                $this->check( str_contains( $html, 'Authored link ' . $index ), 'Preserve supplied relationship wikitext and links.' );
            }
            foreach ( [ 'Authored note remains.', 'Authored book text.', 'Authored forage source.', 'Item lore has not been documented.', 'Help add lore' ] as $authoredText ) {
                $this->check( str_contains( $html, $authoredText ), 'Retain supplied prose and concise lore help.' );
            }
            $this->check( !str_contains( $html, 'Replace this with' ) && !str_contains( $html, "Lore Here" ), 'Reader output must not expose editor template syntax.' );
            $document = new DOMDocument();
            $errors = libxml_use_internal_errors( true );
            $document->loadHTML( '<?xml encoding="UTF-8">' . $html );
            libxml_clear_errors();
            libxml_use_internal_errors( $errors );
            $xpath = new DOMXPath( $document );
            $help = $xpath->query( '//a[normalize-space(.)="Help add lore"]' );
            $this->check( $help->length === 1 && str_contains( $help->item( 0 )->getAttribute( 'href' ), 'action=edit' ) &&
                str_contains( $help->item( 0 )->getAttribute( 'rel' ), 'nofollow' ), 'Lore help must use native editing with a nofollow link.' );
            $categories = $output->getCategoryNames();
            foreach ( [ 'Items', 'Missing Lore', 'Lore Items' ] as $category ) {
                $this->check( in_array( str_replace( ' ', '_', $category ), $categories, true ), 'Preserve item and maintenance categories: ' . $category );
            }
            [ $tooltip ] = $this->parse( $this->item( [ 'mode' => 'tooltip', 'notes' => '{{Item Lore Missing}}' ] ) );
            $this->check( str_contains( $tooltip, 'hbdiv' ) && str_contains( $tooltip, 'class="hb"' ), 'Preserve hover mode markup.' );
            $this->check( !str_contains( $tooltip, 'documented' ) && !str_contains( $tooltip, 'Drops From' ), 'Tooltip mode must not acquire article reader copy.' );
            [ $equipment ] = $this->parse( file_get_contents( $paths['equipment-by-class'] ), 'EQL synthetic equipment index' );
            $this->check( substr_count( $equipment, 'id="eql-equipment-trio-builder"' ) === 1, 'Retain the one equipment builder placeholder.' );
            $this->check( preg_match_all( '/href="[^"\n]*Category:[^"\n]*_Equipment"/', $equipment ) === 16, 'Render sixteen native class category links without JavaScript.' );
            $this->output( "Passed $sourceChecks source/recovery guards and {$this->checks} real-parser checks using unsaved template overlays. No wiki pages saved.\n" );
        }
    }
    return EQLTestItemReaderCopy::class;
}
