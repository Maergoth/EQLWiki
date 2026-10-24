<?php
/** Fixed public API_Documentation publisher; dry-run by default. */
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDBAccessObject;
class EQLSyncApiDocumentation extends Maintenance {
    public function __construct() {
        parent::__construct();$this->addDescription( 'Review/publish only API_Documentation with expected revision, fresh private backup and compare-and-swap.' );
        $this->addOption( 'expected-page', 'Reviewed revision; 0 requires absent page.', true, true );
        $this->addOption( 'apply', 'Explicitly publish this reviewed public documentation.', false, false );
        $this->addOption( 'backup', 'Fresh private JSON recovery file, required for apply.', false, true );
    }
    public function execute() {
        global $IP;$expected=filter_var( $this->getOption( 'expected-page' ), FILTER_VALIDATE_INT, [ 'options'=>[ 'min_range'=>0 ] ] );
        if ( $expected === false ) { $this->fatalError( 'Expected revision must be a nonnegative integer.' ); }
        $source=file_get_contents( __DIR__ . '/api-documentation.wiki' );
        if ( !$source || strlen( $source ) > 100000 || !preg_match( '//u', $source ) || str_contains( $source, "\0" ) || !str_starts_with( $source, '<!-- EQL_API_DOCUMENTATION_V1 -->' ) ) { $this->fatalError( 'Fixed reviewed API documentation source is invalid.' ); }
        $services=$this->getServiceContainer();$title=Title::newFromText( 'API Documentation' );
        $revision=$services->getRevisionStore()->getRevisionByTitle( $title, 0, IDBAccessObject::READ_LATEST );
        if ( ( $revision ? $revision->getId() : 0 ) !== $expected ) { $this->fatalError( 'API_Documentation changed since review; refusing publication.' ); }
        $content=$revision ? $revision->getContent( SlotRecord::MAIN ) : null;
        if ( $revision && !$content instanceof WikitextContent ) { $this->fatalError( 'Existing page is not readable wikitext.' ); }
        $old=$content ? $content->getText() : null;$canonical=static fn( $x ) => rtrim( str_replace( [ "\r\n", "\r" ], "\n", $x ), "\n" ) . "\n";
        if ( $old !== null && $canonical( $old ) === $canonical( $source ) ) { $this->output( "API_Documentation already current. No write.\n" );return; }
        $this->output( 'API_Documentation reviewed revision ' . $expected . ', source SHA256 ' . hash( 'sha256', $source ) . ".\n" );
        if ( !$this->hasOption( 'apply' ) ) { $this->output( "Dry run only. No page saved.\n" );return; }
        $requested=$this->getOption( 'backup', '' );$dir=realpath( dirname( $requested ) );
        if ( !$dir || !is_writable( $dir ) || in_array( basename( $requested ), [ '', '.', '..' ], true ) ) { $this->fatalError( 'Use a fresh file in a private backup directory.' ); }
        $file=$dir . DIRECTORY_SEPARATOR . basename( $requested );$normal=static fn( $x ) => str_replace( '\\', '/', DIRECTORY_SEPARATOR === '\\' ? strtolower( $x ) : $x );
        if ( str_starts_with( $normal( $file ), $normal( realpath( $IP ) ) . '/' ) && !str_starts_with( $normal( $file ), $normal( realpath( $IP ) ) . '/.local/' ) ) { $this->fatalError( 'Backup must be outside served wiki root or in blocked .local.' ); }
        $json=json_encode( [ 'savedAt'=>gmdate( DATE_ATOM ),'title'=>$title->getPrefixedText(),'revision'=>$expected,'text'=>$old,'reviewedSourceSha256'=>hash( 'sha256', $source ) ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
        $mask=umask( 0077 );try { $handle=@fopen( $file, 'xb' ); } finally { umask( $mask ); }
        if ( !$handle ) { $this->fatalError( 'Backup already exists or cannot be created.' ); }
        if ( DIRECTORY_SEPARATOR !== '\\' && !chmod( $file, 0600 ) ) { fclose( $handle );$this->fatalError( 'Could not secure backup.' ); }
        $written=fwrite( $handle, $json );$flushed=fflush( $handle );fclose( $handle );
        if ( $written !== strlen( $json ) || !$flushed || file_get_contents( $file ) !== $json ) { $this->fatalError( 'Backup verification failed; nothing saved.' ); }
        $user=$services->getUserFactory()->newFromName( 'Maergoth' );if ( !$user || !$user->getId() ) { $this->fatalError( 'Owner account must exist.' ); }
        $updater=$services->getWikiPageFactory()->newFromTitle( $title )->newPageUpdater( $user );
        if ( $updater->hasEditConflict( $expected ) ) { $this->fatalError( 'Page changed after backup. Review again.' ); }
        $updater->setContent( SlotRecord::MAIN, new WikitextContent( $source ) );
        $saved=$updater->saveRevision( CommentStoreComment::newUnsavedComment( 'Publish reviewed reusable EQLWiki API documentation.' ), EDIT_MINOR | ( $expected ? EDIT_UPDATE : EDIT_NEW ) );
        if ( !$saved || !$updater->getStatus()->isOK() ) { $this->fatalError( 'Documentation save failed; retain backup and inspect current revision.' ); }
        $this->output( 'API_Documentation saved revision ' . $saved->getId() . ".\n" );
    }
}
return EQLSyncApiDocumentation::class;
