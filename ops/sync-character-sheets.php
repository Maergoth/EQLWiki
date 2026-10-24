<?php
/** Fixed two-template publisher. Dry-run by default; never edits user talk pages. */
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDBAccessObject;

class EQLSyncCharacterSheets extends Maintenance {
    /** Match only MediaWiki's CRLF and single final-LF normalization. */
    private static function comparableSource( string $source ): string {
        $source = str_replace( "\r\n", "\n", $source );
        return str_ends_with( $source, "\n" ) ? substr( $source, 0, -1 ) : $source;
    }
    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Review/publish the two fixed Character sheet templates with revision/create guards.' );
        $this->addOption( 'expected-sheet', 'Reviewed main template revision; 0 requires the page to be absent.', true, true );
        $this->addOption( 'expected-item', 'Reviewed item helper revision; 0 requires the page to be absent.', true, true );
        $this->addOption( 'apply', 'Explicitly publish after read-only review and backup.', false, false );
        $this->addOption( 'backup', 'Fresh private JSON recovery file; required with --apply.', false, true );
    }
    private function expected( string $name ): int {
        $value = filter_var( $this->getOption( $name ), FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] );
        if ( $value === false ) {
            $this->fatalError( 'Reviewed revisions must be nonnegative integers; 0 means create only.' );
        }
        return $value;
    }
    private function backup( string $requested, array $pages ): void {
        global $IP;
        $directory = realpath( dirname( $requested ) );
        $name = basename( $requested );
        if ( !$directory || !is_writable( $directory ) || in_array( $name, [ '', '.', '..' ], true ) ) {
            $this->fatalError( 'Backup requires a fresh file in an existing private directory.' );
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        $normal = static function ( string $value ): string {
            $value = str_replace( '\\', '/', $value );
            return DIRECTORY_SEPARATOR === '\\' ? strtolower( $value ) : $value;
        };
        $root = $normal( realpath( $IP ) );
        $target = $normal( $path );
        if ( str_starts_with( $target, $root . '/' ) && !str_starts_with( $target, $root . '/.local/' ) ) {
            $this->fatalError( 'Backup must be outside the served wiki root or in blocked local .local/.' );
        }
        if ( file_exists( $path ) || is_link( $path ) ) {
            $this->fatalError( 'Backup already exists; choose a fresh private file.' );
        }
        $contents = json_encode( [ 'savedAt' => gmdate( DATE_ATOM ), 'pages' => $pages ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
        $mask = umask( 0077 );
        try { $handle = @fopen( $path, 'xb' ); } finally { umask( $mask ); }
        if ( !$handle ) {
            $this->fatalError( 'Could not create a fresh private recovery file.' );
        }
        if ( DIRECTORY_SEPARATOR !== '\\' && !chmod( $path, 0600 ) ) {
            fclose( $handle );$this->fatalError( 'Could not restrict recovery file permissions.' );
        }
        $offset = 0;
        while ( $offset < strlen( $contents ) ) {
            $written = fwrite( $handle, substr( $contents, $offset ) );
            if ( !$written ) { fclose( $handle );$this->fatalError( 'Could not finish recovery file.' ); }
            $offset += $written;
        }
        $flushed = fflush( $handle );$closed = fclose( $handle );
        if ( !$flushed || !$closed || file_get_contents( $path ) !== $contents ) {
            $this->fatalError( 'Recovery verification failed; nothing was published.' );
        }
    }
    public function execute() {
        $services = $this->getServiceContainer();
        $targets = [
            [ 'Template:Character sheet item', 'character-sheet-item.wiki', 'EQL_CHARACTER_SHEET_ITEM_V1', $this->expected( 'expected-item' ) ],
            [ 'Template:Character sheet', 'character-sheet.wiki', 'EQL_CHARACTER_SHEET_V1', $this->expected( 'expected-sheet' ) ]
        ];
        $plans = [];$recovery = [];
        foreach ( $targets as [ $text, $file, $marker, $expected ] ) {
            $source = file_get_contents( __DIR__ . '/' . $file );
            if ( !$source || strlen( $source ) > 200000 || !preg_match( '//u', $source ) || str_contains( $source, "\0" ) || !str_contains( $source, '<includeonly><!-- ' . $marker . ' -->' ) ) {
                $this->fatalError( 'The fixed reviewed template source is invalid: ' . $file );
            }
            $title = Title::newFromText( $text );
            $revision = $services->getRevisionStore()->getRevisionByTitle( $title, 0, IDBAccessObject::READ_LATEST );
            if ( ( $revision ? $revision->getId() : 0 ) !== $expected ) {
                $this->fatalError( $text . ' changed since review; refusing publication.' );
            }
            $content = $revision ? $revision->getContent( SlotRecord::MAIN ) : null;
            if ( $revision && !$content instanceof WikitextContent ) {
                $this->fatalError( 'Existing target must have readable wikitext: ' . $text );
            }
            $old = $content ? $content->getText() : null;
            $changed = $old === null || self::comparableSource( $source ) !== self::comparableSource( $old );
            $recovery[] = [ 'title' => $text, 'revision' => $expected, 'text' => $old ];
            $plans[] = [ 'title' => $title, 'source' => $source, 'expected' => $expected, 'changed' => $changed ];
            $this->output( $text . ': reviewed revision ' . $expected . ( $changed ? ', update required.' : ', already current.' ) . "\n" );
        }
        if ( !$this->hasOption( 'apply' ) ) {
            $this->output( "Read-only review complete. No pages saved. --apply requires a fresh private --backup.\n" );return;
        }
        if ( !array_filter( $plans, static fn ( $plan ) => $plan['changed'] ) ) { return; }
        $backup = $this->getOption( 'backup', '' );
        if ( $backup === '' ) { $this->fatalError( '--apply requires a fresh private --backup.' ); }
        $user = $services->getUserFactory()->newFromName( 'Maergoth' );
        if ( !$user || !$user->getId() ) { $this->fatalError( 'The owner account must already exist.' ); }
        $this->backup( $backup, $recovery );
        // Per-page compare-and-swap, helper first. A later failure retains the
        // recovery file and printed successful revisions; never roll back over
        // another editor. Review fresh IDs before an explicit partial retry.
        foreach ( $plans as $plan ) {
            if ( !$plan['changed'] ) { continue; }
            $page = $services->getWikiPageFactory()->newFromTitle( $plan['title'] );
            $updater = $page->newPageUpdater( $user );
            if ( $updater->hasEditConflict( $plan['expected'] ) ) {
                $this->fatalError( 'Target changed after backup; earlier successful pages remain installed. Review revisions before retry.' );
            }
            $updater->setContent( SlotRecord::MAIN, new WikitextContent( $plan['source'] ) );
            $revision = $updater->saveRevision( CommentStoreComment::newUnsavedComment( 'Install reviewed interactive Character sheet schema 1.' ), EDIT_MINOR | ( $plan['expected'] ? EDIT_UPDATE : EDIT_NEW ) );
            if ( !$revision || !$updater->getStatus()->isOK() ) {
                $this->fatalError( 'Template save failed; retain recovery file and review any earlier successful revisions before retry.' );
            }
            $this->output( 'Saved ' . $plan['title']->getPrefixedText() . ' revision ' . $revision->getId() . ".\n" );
        }
    }
}
return EQLSyncCharacterSheets::class;
