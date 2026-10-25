<?php
/** Publish only the reviewed zone blueprint subsection of Help:Contents. */
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDBAccessObject;
require_once __DIR__ . '/ZonePageBlueprint.php';

class EQLSyncZonePageBlueprint extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Review or publish only Help:Contents Zone Page Blueprint, preserving every other blueprint.' );
        $this->addOption( 'source', 'Reviewed ops/zone-page-blueprint.wiki subsection', true, true );
        $this->addOption( 'expected-revision', 'Positive reviewed Help:Contents revision ID', true, true );
        $this->addOption( 'backup', 'Fresh private full-page backup; required with --apply', false, true );
        $this->addOption( 'apply', 'Publish after revision and backup guards; default is read-only review' );
    }

    public function execute() {
        $expected = filter_var( $this->getOption( 'expected-revision' ), FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
        if ( $expected === false ) { $this->fatalError( 'Expected revision must be a positive integer.' ); }
        $sourcePath = $this->getOption( 'source' );
        if ( !is_file( $sourcePath ) || !is_readable( $sourcePath ) ) { $this->fatalError( 'Reviewed source must be readable.' ); }
        $source = file_get_contents( $sourcePath );
        if ( $source === false ) { $this->fatalError( 'Could not read reviewed source.' ); }
        try { EQLZonePageBlueprint::validateSource( $source ); } catch ( RuntimeException $error ) { $this->fatalError( $error->getMessage() ); }
        $services = $this->getServiceContainer();
        $title = Title::newFromText( 'Help:Contents' );
        $page = $services->getWikiPageFactory()->newFromTitle( $title );
        $current = $services->getRevisionStore()->getRevisionByTitle( $page, 0, IDBAccessObject::READ_LATEST );
        $content = $current ? $current->getContent( SlotRecord::MAIN ) : null;
        if ( !$current || $current->getId() !== $expected || !$content instanceof WikitextContent ) {
            $this->fatalError( 'Help:Contents changed or its current wikitext is unavailable.' );
        }
        $old = $content->getText();
        try { $new = EQLZonePageBlueprint::replaceSection( $old, $source ); } catch ( RuntimeException $error ) { $this->fatalError( $error->getMessage() ); }
        if ( $new === $old ) { $this->output( "Zone Page Blueprint is already current.\n" ); return; }
        $this->output( 'Reviewed Help:Contents revision ' . $expected . '; proposed page SHA256 ' . hash( 'sha256', $new ) . ".\n" );
        if ( !$this->hasOption( 'apply' ) ) { $this->output( "Read-only review; no backup or page write performed.\n" ); return; }
        $backup = $this->backupPath( (string)$this->getOption( 'backup', '' ) );
        $user = $services->getUserFactory()->newFromName( 'Maergoth' );
        if ( !$user || !$user->getId() ) { $this->fatalError( 'The owner account must already exist.' ); }
        $updater = $page->newPageUpdater( $user );
        if ( $updater->hasEditConflict( $expected ) ) { $this->fatalError( 'Help:Contents changed before backup; publication refused.' ); }
        $this->writeBackup( $backup, $old );
        $fresh = $services->getRevisionStore()->getRevisionByTitle( $page, 0, IDBAccessObject::READ_LATEST );
        if ( !$fresh || $fresh->getId() !== $expected ) { $this->fatalError( 'Help:Contents changed after backup; recovery copy retained.' ); }
        $updater->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $new, $title ) );
        $revision = $updater->saveRevision( CommentStoreComment::newUnsavedComment(
            'Use a shared zone overview/map blueprint with explicit optional raid-instance structure.'
        ), EDIT_MINOR | EDIT_UPDATE );
        if ( !$revision || !$updater->getStatus()->isOK() ) { $this->fatalError( 'Blueprint publication failed; recovery copy retained.' ); }
        $this->output( 'Saved Help:Contents revision ' . $revision->getId() . ".\n" );
    }

    private function backupPath( string $requested ): string {
        global $IP;
        $directory = realpath( dirname( $requested ) );
        $name = basename( $requested );
        if ( $requested === '' || !$directory || !is_writable( $directory ) || in_array( $name, [ '', '.', '..' ], true ) ) {
            $this->fatalError( 'A fresh private backup path is required with --apply.' );
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        $root = realpath( $IP );
        if ( !$root ) { $this->fatalError( 'Could not resolve the wiki installation root.' ); }
        $local = realpath( $root . DIRECTORY_SEPARATOR . '.local' );
        $within = static function ( $file, $parent ): bool {
            $file = str_replace( '\\', '/', $file ); $parent = rtrim( str_replace( '\\', '/', $parent ), '/' );
            if ( DIRECTORY_SEPARATOR === '\\' ) { $file = strtolower( $file ); $parent = strtolower( $parent ); }
            return $file === $parent || str_starts_with( $file, $parent . '/' );
        };
        if ( $within( $path, $root ) && ( !$local || !$within( $path, $local ) ) ) { $this->fatalError( 'Backup must be outside the served root or inside blocked local .local/.' ); }
        if ( file_exists( $path ) || is_link( $path ) ) { $this->fatalError( 'Backup already exists; choose a fresh private path.' ); }
        return $path;
    }

    private function writeBackup( string $path, string $old ): void {
        $mask = umask( 0077 );
        try { $handle = @fopen( $path, 'xb' ); } finally { umask( $mask ); }
        if ( !$handle ) { $this->fatalError( 'Could not create a fresh private backup.' ); }
        if ( DIRECTORY_SEPARATOR !== '\\' && !chmod( $path, 0600 ) ) { fclose( $handle ); $this->fatalError( 'Could not restrict backup permissions.' ); }
        $offset = 0;
        while ( $offset < strlen( $old ) ) {
            $written = fwrite( $handle, substr( $old, $offset ) );
            if ( $written === false || $written === 0 ) { fclose( $handle ); $this->fatalError( 'Could not finish backup.' ); }
            $offset += $written;
        }
        $flushed = fflush( $handle ); $closed = fclose( $handle );
        if ( !$flushed || !$closed || file_get_contents( $path ) !== $old ) { $this->fatalError( 'Could not verify full-page backup.' ); }
    }
}
return EQLSyncZonePageBlueprint::class;
