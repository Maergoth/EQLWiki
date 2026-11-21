<?php
/** Publish reviewed reader-copy changes to fixed targets with private recovery copies. */
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDBAccessObject;

class EQLSyncItemReaderCopy extends Maintenance {
    private const TARGETS = [
        'itempage' => 'Template:Itempage',
        'item-lore-missing' => 'Template:Item Lore Missing',
        'equipment-by-class' => 'Equipment By Class',
        'zones-map-labels' => 'Zones',
    ];

    private const UNKNOWN_FIELDS = [
        'dropsfrom' => [ 'This item is not dropped by mobs.', 'No mob drop source has been documented.' ],
        'soldby' => [ 'This item cannot be purchased from merchants.', 'No merchant source has been documented.' ],
        'relatedquests' => [ 'This item has no related quests.', 'No related quest has been documented.' ],
        'playercrafted' => [ 'This item is not crafted by players.', 'No player-crafted recipe has been documented.' ],
        'recipes' => [ 'This item is not used in player tradeskills.', 'No tradeskill use has been documented.' ],
    ];

    private const CLASSES = [
        'Bard', 'Beastlord', 'Berserker', 'Cleric', 'Druid', 'Enchanter', 'Magician', 'Monk',
        'Necromancer', 'Paladin', 'Ranger', 'Rogue', 'Shadow Knight', 'Shaman', 'Warrior', 'Wizard',
    ];

    private const ZONE_LABELS = [
        '<span style="opacity: 0;">NEWWWW SEBILISSSS EXPEDITION</span>' => '<span style="opacity: 0;">New Sebilis Expedition</span>',
        '<span style="opacity: 0;">Lakeeeee Rathetear</span>' => '<span style="opacity: 0;">Lake Rathetear</span>',
        '<span style="opacity: 0;">Southhh Rooo</span>' => '<span style="opacity: 0;">South Ro</span>',
    ];

    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Publish reviewed item reader copy or static equipment links to a fixed target.' );
        $this->addOption( 'target', 'One fixed target: itempage, item-lore-missing, equipment-by-class, zones-map-labels', true, true );
        $this->addOption( 'source', 'Reviewed source file for the selected target', true, true );
        $this->addOption( 'expected-revision', 'Positive current revision ID reviewed before publication', true, true );
        $this->addOption( 'backup', 'Fresh private recovery file outside the web root', true, true );
    }

    public function execute() {
        $key = (string)$this->getOption( 'target' );
        if ( !isset( self::TARGETS[$key] ) ) {
            $this->fatalError( 'Select one of the four fixed reader-copy targets.' );
        }
        $expected = filter_var( $this->getOption( 'expected-revision' ), FILTER_VALIDATE_INT,
            [ 'options' => [ 'min_range' => 1 ] ] );
        if ( $expected === false ) {
            $this->fatalError( 'Expected revision must be a positive integer.' );
        }
        $sourcePath = $this->getOption( 'source' );
        if ( !is_file( $sourcePath ) || !is_readable( $sourcePath ) ) {
            $this->fatalError( 'Reviewed source must be a readable file.' );
        }
        $source = file_get_contents( $sourcePath );
        $this->validateSource( $key, $source );
        $backup = $this->resolveBackupPath( (string)$this->getOption( 'backup' ) );

        $title = Title::newFromText( self::TARGETS[$key] );
        $services = $this->getServiceContainer();
        $user = $services->getUserFactory()->newFromName( 'Maergoth' );
        if ( !$user || !$user->getId() ) {
            $this->fatalError( 'The owner account must already exist.' );
        }
        $page = $services->getWikiPageFactory()->newFromTitle( $title );
        $updater = $page->newPageUpdater( $user );
        if ( $updater->hasEditConflict( $expected ) ) {
            $this->fatalError( 'The target changed since review; refusing to overwrite.' );
        }
        $parent = $updater->grabParentRevision();
        $oldContent = $parent ? $parent->getContent( SlotRecord::MAIN ) : null;
        if ( !$parent || $parent->getId() !== $expected || !$oldContent instanceof WikitextContent ) {
            $this->fatalError( 'The exact reviewed target revision must contain readable wikitext.' );
        }
        $oldText = $oldContent->getText();
        if ( $key === 'zones-map-labels' ) {
            $source = $this->applyZoneLabels( $oldText );
        }
        if ( $this->normalizedSource( $source ) === $this->normalizedSource( $oldText ) ) {
            $this->output( self::TARGETS[$key] . " is already current.\n" );
            return;
        }
        $this->validateDelta( $key, $oldText, $source );
        $this->writeBackup( $backup, $oldText );

        // Check the primary after the private backup. PageUpdater retains its original
        // compare-and-swap token, so a later concurrent edit is also refused at save.
        $current = $services->getRevisionStore()->getRevisionByTitle( $page, 0, IDBAccessObject::READ_LATEST );
        if ( !$current || $current->getId() !== $expected ) {
            $this->fatalError( 'The target changed while preparing publication; recovery copy retained.' );
        }
        $updater->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $source, $title ) );
        $revision = $updater->saveRevision( CommentStoreComment::newUnsavedComment(
            match ( $key ) {
                'equipment-by-class' => 'Add direct class equipment links while retaining the equipment builder.',
                'zones-map-labels' => 'Normalize three zone-map anchor labels while preserving map geometry and targets.',
                default => 'Distinguish undocumented item relationships from confirmed absence and keep lore help concise.',
            }
        ), EDIT_MINOR | EDIT_UPDATE );
        if ( !$updater->getStatus()->isOK() || !$revision ) {
            $this->fatalError( 'Reader-copy publication failed; recovery copy retained.' );
        }
        $this->output( 'Saved ' . self::TARGETS[$key] . ' revision ' . $revision->getId() . "\n" );
    }

    private function normalizedSource( string $source ): string {
        return rtrim( str_replace( "\r\n", "\n", $source ), "\r\n" );
    }

    private function validateSource( string $key, $source ): void {
        if ( !isset( self::TARGETS[$key] ) || !is_string( $source ) || $source === '' ||
            strlen( $source ) > 64 * 1024 || str_contains( $source, "\0" ) || !preg_match( '//u', $source ) ) {
            $this->fatalError( 'Invalid fixed-target reader-copy source.' );
        }
        if ( $key === 'equipment-by-class' ) {
            $this->validateEquipmentSource( $source );
            return;
        }
        if ( $key === 'zones-map-labels' ) {
            if ( json_decode( $source, true ) !== self::ZONE_LABELS ) {
                $this->fatalError( 'Zone-label source must contain only the three reviewed literal replacements.' );
            }
            return;
        }
        foreach ( [ '<includeonly>', '</includeonly>', '<noinclude>' ] as $tag ) {
            if ( substr_count( $source, $tag ) !== 1 ) {
                $this->fatalError( 'Template source must retain its existing inclusion boundaries.' );
            }
        }
        if ( $key === 'item-lore-missing' ) {
            foreach ( [ 'class="eql-item-lore-missing"', '[[File:Spellicon_U.png|18px]]',
                'Item lore has not been documented.', '[{{fullurl:{{FULLPAGENAME}}|action=edit}} Help add lore]',
                '[[Category:Missing Lore]]', '[[Category:Lore Items]]' ] as $marker ) {
                if ( !str_contains( $source, $marker ) ) {
                    $this->fatalError( 'Lore source must retain its icon, maintenance categories and native edit help.' );
                }
            }
            return;
        }
        foreach ( [ 'class="hbdiv"', 'class="hb"', '{{{itemname}}}', '{{{lucy_img_ID}}}',
            '{{{statsblock}}}', '{{{focus_effect|}}}', '{{FULLPAGENAME}}', '[[Category:Items]]',
            "<span id='itemfashion'></span>", '</noinclude>' ] as $marker ) {
            if ( !str_contains( $source, $marker ) ) {
                $this->fatalError( 'Itempage source must retain its article, hover, stat and category contracts.' );
            }
        }
        foreach ( self::UNKNOWN_FIELDS as $messages ) {
            if ( !str_contains( $source, $messages[1] ) || str_contains( $source, $messages[0] ) ) {
                $this->fatalError( 'Itempage defaults must describe undocumented information without asserting absence.' );
            }
        }
    }

    private function validateDelta( string $key, string $old, string $source ): void {
        if ( $key === 'equipment-by-class' ) {
            $links = array_map( static fn ( $class ) => '[[:Category:' . $class . ' Equipment|' . $class . ']]', self::CLASSES );
            $paragraphs = array_map( static fn ( $chunk ) => implode( ' · ', $chunk ), array_chunk( $links, 4 ) );
            $append = "\n\n<!-- EQL_EQUIPMENT_CLASS_LINKS_START -->\n=== Browse equipment by class ===\n" .
                "Browse a class category for individual items, or use the equipment search above to combine classes and filter results.\n\n" .
                implode( "\n\n", $paragraphs ) . "\n<!-- EQL_EQUIPMENT_CLASS_LINKS_END -->";
            if ( $this->normalizedSource( $source ) !== $this->normalizedSource( $old ) . $append ) {
                $this->fatalError( 'Equipment publication may only append the reviewed class-link block.' );
            }
            return;
        }
        if ( $key === 'zones-map-labels' ) {
            if ( $source !== $this->applyZoneLabels( $old ) ) {
                $this->fatalError( 'Zone publication may only replace the three reviewed anchor labels.' );
            }
            return;
        }
        $expected = $old;
        if ( $key === 'itempage' ) {
            foreach ( self::UNKNOWN_FIELDS as $field => $messages ) {
                $before = '{{{' . $field . "|<ul class='esec'><li> <span class='esec'>" . $messages[0] . '</span> </li></ul>}}}';
                $after = '{{#if: {{{' . $field . '|}}} | {{{' . $field . "}}} | <ul class='esec'><li> <span class='esec'>" . $messages[1] . '</span> </li></ul>}}';
                if ( substr_count( $expected, $before ) !== 1 ) {
                    $this->fatalError( 'Itempage differs from the reviewed fallback structure; review a new source.' );
                }
                $expected = str_replace( $before, $after, $expected );
            }
        } elseif ( $key === 'item-lore-missing' ) {
            $replacements = [
                '<includeonly><span style="background: #7d684640;">' => '<includeonly><span class="eql-item-lore-missing" style="background: #7d684640;">',
                "'''Item Lore missing.''' Replace this with <nowiki>{{Item Lore | 'Lore Here' }}</nowiki>" =>
                    'Item lore has not been documented. [{{fullurl:{{FULLPAGENAME}}|action=edit}} Help add lore]',
            ];
            foreach ( $replacements as $before => $after ) {
                if ( substr_count( $expected, $before ) !== 1 ) {
                    $this->fatalError( 'Lore template differs from the reviewed notice; review a new source.' );
                }
                $expected = str_replace( $before, $after, $expected );
            }
        } else {
            $this->fatalError( 'Unknown reader-copy target.' );
        }
        if ( $this->normalizedSource( $source ) !== $this->normalizedSource( $expected ) ) {
            $this->fatalError( 'Source changes more than the reviewed reader-copy replacement; publication refused.' );
        }
    }

    private function applyZoneLabels( string $old ): string {
        foreach ( self::ZONE_LABELS as $before => $after ) {
            $count = substr_count( $old, $before );
            if ( $count === 0 && substr_count( $old, $after ) === 1 ) {
                continue;
            }
            if ( $count !== 1 ) {
                $this->fatalError( 'Zone map labels differ from the reviewed literal structure; publication refused.' );
            }
            $old = str_replace( $before, $after, $old );
        }
        return $old;
    }

    private function validateEquipmentSource( string $source ): void {
        foreach ( [ 'EQL_EQUIPMENT_CLASS_LINKS_START', 'EQL_EQUIPMENT_CLASS_LINKS_END', 'id="eql-equipment-trio-builder"' ] as $marker ) {
            if ( substr_count( $source, $marker ) !== 1 ) {
                $this->fatalError( 'Equipment source must retain one builder and one appended link block.' );
            }
        }
        foreach ( self::CLASSES as $class ) {
            if ( preg_match_all( '/\[\[:Category:' . preg_quote( $class, '/' ) . ' Equipment\|[^\]\r\n]+\]\]/', $source ) !== 1 ) {
                $this->fatalError( 'Equipment source must retain each reviewed class category link exactly once.' );
            }
        }
        if ( !str_contains( $source, '[[:Category:Items' ) ) {
            $this->fatalError( 'Equipment source must retain its existing Items category link.' );
        }
    }

    private function resolveBackupPath( string $requested ): string {
        global $IP;
        $parent = realpath( dirname( $requested ) );
        $name = basename( $requested );
        if ( $requested === '' || !$parent || !is_dir( $parent ) || !is_writable( $parent ) ||
            $name === '' || $name === '.' || $name === '..' ) {
            $this->fatalError( 'Backup must name a fresh file in an existing private directory.' );
        }
        $backup = $parent . DIRECTORY_SEPARATOR . $name;
        $root = realpath( $IP );
        if ( !$root ) {
            $this->fatalError( 'Could not resolve the wiki installation root.' );
        }
        if ( $this->isWithin( $backup, $root ) ) {
            $this->fatalError( 'Recovery copy must be outside the served wiki root.' );
        }
        if ( file_exists( $backup ) || is_link( $backup ) ) {
            $this->fatalError( 'Backup already exists; choose a fresh private backup path.' );
        }
        return $backup;
    }

    private function isWithin( string $path, string $directory ): bool {
        $path = str_replace( '\\', '/', $path );
        $directory = rtrim( str_replace( '\\', '/', $directory ), '/' );
        if ( DIRECTORY_SEPARATOR === '\\' ) {
            $path = strtolower( $path );
            $directory = strtolower( $directory );
        }
        return $path === $directory || str_starts_with( $path, $directory . '/' );
    }

    private function writeBackup( string $path, string $text ): void {
        $previousMask = umask( 0077 );
        try {
            $handle = @fopen( $path, 'xb' );
        } finally {
            umask( $previousMask );
        }
        if ( !$handle ) {
            $this->fatalError( 'Could not create a fresh private recovery copy.' );
        }
        if ( DIRECTORY_SEPARATOR !== '\\' && !chmod( $path, 0600 ) ) {
            fclose( $handle );
            $this->fatalError( 'Could not restrict recovery-copy permissions; publication refused.' );
        }
        $offset = 0;
        while ( $offset < strlen( $text ) ) {
            $written = fwrite( $handle, substr( $text, $offset ) );
            if ( $written === false || $written === 0 ) {
                fclose( $handle );
                $this->fatalError( 'Could not finish the recovery copy; publication refused.' );
            }
            $offset += $written;
        }
        $flushed = fflush( $handle );
        $closed = fclose( $handle );
        if ( !$flushed || !$closed || file_get_contents( $path ) !== $text ) {
            $this->fatalError( 'Could not verify the recovery copy; publication refused.' );
        }
    }
}
return EQLSyncItemReaderCopy::class;
