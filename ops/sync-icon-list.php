<?php
/** Publish the reviewed display page, with a private backup and edit-conflict guard. */
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Content\ContentHandler;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class EQLSyncIconList extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Update Icon List from reviewed wikitext.' );
        foreach ( [ 'source', 'expected-revision', 'backup' ] as $option ) {
            $this->addOption( $option, $option, true, true );
        }
    }
    public function execute() {
        $title = Title::newFromText( 'Icon List' );
        $services = $this->getServiceContainer();
        $user = $services->getUserFactory()->newFromName( 'Maergoth' );
        if ( !$user || !$user->getId() ) { $this->fatalError( 'Owner account must exist.' ); }
        $updater = $services->getWikiPageFactory()->newFromTitle( $title )->newPageUpdater( $user );
        if ( $updater->hasEditConflict( (int)$this->getOption( 'expected-revision' ) ) ) {
            $this->fatalError( 'Icon List changed since review; refusing to overwrite.' );
        }
        $oldText = $updater->grabParentRevision()->getContent( SlotRecord::MAIN )->getText();
        $source = file_get_contents( $this->getOption( 'source' ) );
        if ( !$source || !str_contains( $source, 'id="eql-icon-catalog"' ) ) { $this->fatalError( 'Invalid list source.' ); }
        $backup = $this->getOption( 'backup' );
        if ( file_exists( $backup ) || file_put_contents( $backup, $oldText ) === false ) {
            $this->fatalError( 'Could not create a fresh backup.' );
        }
        $updater->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $source, $title ) );
        $revision = $updater->saveRevision( CommentStoreComment::newUnsavedComment(
            'Browse the complete uploaded icon library in small pages with exact ID lookup (GitHub #4).'
        ), EDIT_MINOR );
        if ( !$updater->getStatus()->isOK() ) { $this->fatalError( 'Page update failed: ' . $updater->getStatus() ); }
        $this->output( 'Saved Icon List revision ' . $revision->getId() . "\n" );
    }
}
return EQLSyncIconList::class;
