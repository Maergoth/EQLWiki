<?php

declare( strict_types = 1 );

namespace EQLClientData;

use MediaWiki\Api\ApiBase;
use MediaWiki\Content\WikitextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IDBAccessObject;

/** Fill effect-only gaps in public snapshots, without rewriting User pages. */
final class ApiCharacterEffects extends ApiBase {

	public function execute(): void {
		$params = $this->extractRequestParams();
		$names = array_values( array_unique( array_filter( array_map( 'trim', explode( '|', $params['titles'] ) ) ) ) );
		if ( count( $names ) > 50 || strlen( $params['titles'] ) > 16000 ) {
			$this->dieWithError( 'apierror-eqlcharactereffects-limit', 'toomanyvalues' );
		}
		$services = MediaWikiServices::getInstance();
		$anonymous = $services->getUserFactory()->newAnonymous();
		$permissions = $services->getPermissionManager();
		$items = [];
		require_once dirname( __DIR__, 2 ) . '/DynamicZoneList/NpcLootWikitext.php';
		foreach ( $names as $name ) {
			$title = $services->getTitleFactory()->newFromText( $name );
			if ( !$title || $title->getNamespace() !== NS_MAIN || $title->isExternal() || $title->getFragment() !== '' ) { continue; }
			if ( !$permissions->userCan( 'read', $anonymous, $title, PermissionManager::RIGOR_SECURE ) ) { continue; }
			$revision = $services->getRevisionLookup()->getRevisionByTitle( $title, 0, IDBAccessObject::READ_LATEST );
			if ( !$revision || !$revision->audienceCan( RevisionRecord::DELETED_TEXT, RevisionRecord::FOR_PUBLIC ) ) { continue; }
			$content = $revision->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC );
			if ( !$content instanceof WikitextContent ) { continue; }
			$items[] = [ 'title' => $title->getText(), 'revisionId' => $revision->getId(),
				'effects' => \EqlNpcLootWikitext::itemEffects( $content->getText() ) ];
		}
		// No shared HTTP cache may retain a subsequently suppressed revision.
		$this->getMain()->setCacheMode( 'private' );
		$this->getResult()->addValue( null, $this->getModuleName(), [ 'items' => $items ] );
	}

	public function getAllowedParams(): array {
		return [ 'titles' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true ] ];
	}
	public function mustBePosted(): bool { return true; }
	public function isInternal(): bool { return false; }
}
