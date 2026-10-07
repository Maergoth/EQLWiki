<?php
/** Deploy the versioned finder to its database-backed MediaWiki script page. */
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Content\ContentHandler;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class EQLSyncIconFinder extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Update MediaWiki:IconFinder.js with revision conflict protection.' );
        $this->addOption( 'source', 'Reviewed JavaScript file', true, true );
        $this->addOption( 'expected-revision', 'Revision ID reviewed before deployment', true, true );
        $this->addOption( 'backup', 'Private backup file outside the document root', true, true );
    }
    public function execute() {
        $title = Title::newFromText( 'MediaWiki:IconFinder.js' );
        $services = $this->getServiceContainer();
        $user = $services->getUserFactory()->newFromName( 'Maergoth' );
        if ( !$user || !$user->getId() ) {
            $this->fatalError( 'The owner account must already exist.' );
        }
        $page = $services->getWikiPageFactory()->newFromTitle( $title );
        $updater = $page->newPageUpdater( $user );
        if ( $updater->hasEditConflict( (int)$this->getOption( 'expected-revision' ) ) ) {
            $this->fatalError( 'Finder page changed since review; refusing to overwrite.' );
        }
        $parent = $updater->grabParentRevision();
        $oldText = $parent->getContent( SlotRecord::MAIN )->getText();
        $source = file_get_contents( $this->getOption( 'source' ) );
        if ( !$source || !str_contains( $source, 'window.EQLIconFinder' ) ) {
            $this->fatalError( 'Invalid finder source.' );
        }
        if ( file_exists( $this->getOption( 'backup' ) ) ) {
            $this->fatalError( 'Backup already exists; choose a fresh backup path.' );
        }
        if ( file_put_contents( $this->getOption( 'backup' ), $oldText ) === false ) {
            $this->fatalError( 'Could not save recovery copy.' );
        }
        $updater->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $source, $title ) );
        $revision = $updater->saveRevision( CommentStoreComment::newUnsavedComment(
            'Index the complete uploaded icon library and preserve duplicate IDs as selectable aliases (GitHub #4).'
        ), EDIT_MINOR );
        if ( !$updater->getStatus()->isOK() ) {
            $this->fatalError( 'Finder update failed: ' . $updater->getStatus() );
        }
        $this->output( 'Saved finder revision ' . $revision->getId() . "\n" );
    }
}
return EQLSyncIconFinder::class;
