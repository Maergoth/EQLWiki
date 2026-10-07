<?php
/** Replace only the Plane of Sky section of the database-backed Common.js. */
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Content\ContentHandler;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class EQLSyncSkyRewards extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Update Plane of Sky reward fields with revision conflict protection.' );
        $this->addOption( 'source', 'Reviewed reward section JavaScript', true, true );
        $this->addOption( 'expected-revision', 'Reviewed Common.js revision ID', true, true );
        $this->addOption( 'backup', 'Fresh private recovery file outside the document root', true, true );
    }
    public function execute() {
        $services = $this->getServiceContainer();
        $user = $services->getUserFactory()->newFromName( 'Maergoth' );
        if ( !$user || !$user->getId() ) { $this->fatalError( 'The owner account must exist.' ); }
        $page = $services->getWikiPageFactory()->newFromTitle( Title::newFromText( 'MediaWiki:Common.js' ) );
        $updater = $page->newPageUpdater( $user );
        if ( $updater->hasEditConflict( (int)$this->getOption( 'expected-revision' ) ) ) {
            $this->fatalError( 'Common.js changed since review; refusing to overwrite.' );
        }
        $old = $updater->grabParentRevision()->getContent( SlotRecord::MAIN )->getText();
        $marker = ' * Plane of Sky - quest reward number fields';
        $at = strpos( $old, $marker );
        if ( $at === false || strpos( $old, $marker, $at + 1 ) !== false ) {
            $this->fatalError( 'Expected exactly one Plane of Sky section.' );
        }
        $begin = strrpos( substr( $old, 0, $at ), '/* =====' );
        $end = strpos( $old, '/* =====', $at + strlen( $marker ) );
        $source = file_get_contents( $this->getOption( 'source' ) );
        if ( $begin === false || $end === false || !$source ||
            substr_count( $source, $marker ) !== 1 || !str_contains( $source, 'sky-reward:item:' ) ||
            !str_contains( substr( $old, $begin, $end - $begin ), 'eql-sky-reward-input' ) ) {
            $this->fatalError( 'Reward section boundaries or reviewed source are invalid.' );
        }
        $new = substr( $old, 0, $begin ) . rtrim( $source ) . "\n\n" . substr( $old, $end );
        if ( $new === $old ) {
            $this->output( "Reward section is already current.\n" );
            return;
        }
        $backup = $this->getOption( 'backup' );
        $handle = fopen( $backup, 'x' );
        if ( !$handle || fwrite( $handle, $old ) !== strlen( $old ) ) {
            $this->fatalError( 'Could not create a fresh private recovery copy.' );
        }
        fclose( $handle );
        $updater->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $new, $page->getTitle() ) );
        $revision = $updater->saveRevision( CommentStoreComment::newUnsavedComment(
            'Use stable reward identities and preserve existing saved numbers (GitHub #3).'
        ), EDIT_MINOR );
        if ( !$updater->getStatus()->isOK() ) { $this->fatalError( 'Common.js update failed.' ); }
        $this->output( 'Saved Common.js revision ' . $revision->getId() . "\n" );
    }
}
return EQLSyncSkyRewards::class;
