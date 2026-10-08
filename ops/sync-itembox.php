<?php
/** Publish only the reviewed Itembox template, preserving a private recovery copy. */
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDBAccessObject;

class EQLSyncItembox extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Publish reviewed Template:Itembox with revision conflict protection.' );
        $this->addOption( 'source', 'Reviewed ops/itembox.wiki source file', true, true );
        $this->addOption( 'expected-revision', 'Positive Itembox revision ID reviewed before publication', true, true );
        $this->addOption( 'backup', 'Fresh private recovery file outside the web root (or blocked local .local/)', true, true );
    }

    public function execute() {
        $expected = filter_var( $this->getOption( 'expected-revision' ), FILTER_VALIDATE_INT,
            [ 'options' => [ 'min_range' => 1 ] ] );
        if ( $expected === false ) {
            $this->fatalError( 'Expected revision must be a positive integer.' );
        }

        $sourcePath = $this->getOption( 'source' );
        if ( !is_file( $sourcePath ) || !is_readable( $sourcePath ) ) {
            $this->fatalError( 'Reviewed Itembox source must be a readable file.' );
        }
        $source = file_get_contents( $sourcePath );
        if ( $source === false || $source === '' || strlen( $source ) > 2 * 1024 * 1024 ||
            str_contains( $source, "\0" ) || !preg_match( '//u', $source ) ) {
            $this->fatalError( 'Invalid Itembox source.' );
        }
        $this->validateSource( $source );
        $backup = $this->resolveBackupPath( $this->getOption( 'backup' ) );

        // The target is fixed: this helper never edits item articles or interface scripts.
        $title = Title::newFromText( 'Template:Itembox' );
        $services = $this->getServiceContainer();
        $user = $services->getUserFactory()->newFromName( 'Maergoth' );
        if ( !$user || !$user->getId() ) {
            $this->fatalError( 'The owner account must already exist.' );
        }
        $page = $services->getWikiPageFactory()->newFromTitle( $title );
        $updater = $page->newPageUpdater( $user );
        if ( $updater->hasEditConflict( $expected ) ) {
            $this->fatalError( 'Itembox changed since review; refusing to overwrite.' );
        }
        $parent = $updater->grabParentRevision();
        $oldContent = $parent ? $parent->getContent( SlotRecord::MAIN ) : null;
        if ( !$oldContent instanceof WikitextContent ) {
            $this->fatalError( 'The existing Itembox template must contain readable wikitext.' );
        }
        $oldText = $oldContent->getText();
        if ( $source === $oldText ) {
            $this->output( "Itembox is already current.\n" );
            return;
        }

        $this->writeBackup( $backup, $oldText );

        // Read the primary again after creating the backup. PageUpdater also retains its
        // original compare-and-swap token, so a later concurrent edit fails at saveRevision.
        $current = $services->getRevisionStore()->getRevisionByTitle(
            $page, 0, IDBAccessObject::READ_LATEST
        );
        if ( !$current || $current->getId() !== $expected ) {
            $this->fatalError( 'Itembox changed while preparing publication; recovery copy retained.' );
        }
        $updater->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $source, $title ) );
        $revision = $updater->saveRevision( CommentStoreComment::newUnsavedComment(
            'Generate level-50 weapon damage bonus when absent, preserving explicit item values and Backstab defaults.'
        ), EDIT_MINOR | EDIT_UPDATE );
        if ( !$updater->getStatus()->isOK() || !$revision ) {
            $this->fatalError( 'Itembox publication failed; recovery copy retained.' );
        }
        $this->output( 'Saved Template:Itembox revision ' . $revision->getId() . "\n" );
    }

    private function validateSource( string $source ): void {
        // These are contracts shared by articles, hovers, and ItemLevelSlider. They are
        // structural checks for a reviewed file, not a substitute for parsing its fixtures.
        foreach ( [ '<includeonly>', '</includeonly>', '<noinclude>', '</noinclude>' ] as $tag ) {
            if ( substr_count( $source, $tag ) !== 1 ) {
                $this->fatalError( 'Itembox source must retain its inclusion boundaries.' );
            }
        }
        foreach ( [
            'class="itemtopbg"', 'class="itemtitle"', 'class="itembg"',
            'class="itemdata"', 'class="itemicon"', 'class="itembotbg"',
            '{{{itemname}}}', '{{{lucy_img_ID}}}', '{{{statsblock|}}}',
            'itembox_stats', 'itembox_classline', 'itembox_rogusable',
            'Skill: Piercing', 'BACKSTAB:', 'focus_effect', 'itemeff',
            'itembox_db_', 'DMG Bonus:'
        ] as $marker ) {
            if ( !str_contains( $source, $marker ) ) {
                $this->fatalError( 'Itembox source is missing a required DOM, Backstab, or damage-bonus contract.' );
            }
        }
        if ( !preg_match( '/\{\{#vardefine:itembox_db_[a-z_]+\|/', $source ) ) {
            $this->fatalError( 'Itembox source is missing damage-bonus calculation variables.' );
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
        // .local is a local-only allowance: ops/local-router.php blocks it. Host recovery
        // copies belong outside the document root, as documented in PROJECT.md.
        $local = realpath( $root . DIRECTORY_SEPARATOR . '.local' );
        if ( $this->isWithin( $backup, $root ) &&
            ( !$local || !$this->isWithin( $backup, $local ) ) ) {
            $this->fatalError( 'Recovery copy must be outside the served wiki root or in blocked local .local/.' );
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
        // Windows inherits directory ACLs; POSIX files are readable only by their owner.
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
return EQLSyncItembox::class;
