<?php

declare( strict_types = 1 );

namespace EQLImmersive;

use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\Hook\UploadVerifyUploadHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Title\Title;
use MediaWiki\User\UserRigorOptions;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

/** Reserved portrait names, independently of page/file existence or request parameters. */
final class CharacterScreenshotOwnership implements GetUserPermissionsErrorsHook, UploadVerifyUploadHook, UploadCompleteHook {

	public const PREFIX = 'Character ';
	public const PURGE_LIMIT = 20;
	private MediaWikiServices $services;
	private \WeakMap $verifiedUploads;

	public function __construct( ?MediaWikiServices $services = null ) {
		$this->services = $services ?? MediaWikiServices::getInstance();
		$this->verifiedUploads = new \WeakMap();
	}

	/** null = ordinary file. A reserved but unresolved/ambiguous name has ownerId=null. */
	public function ownerOf( Title $title ): ?array {
		$name = $title->getText();
		if ( $title->getNamespace() !== NS_FILE || !str_starts_with( $name, self::PREFIX ) ||
			!str_contains( substr( $name, strlen( self::PREFIX ) ), ' - ' ) ) { return null; }
		$unknown = [ 'ownerId' => null, 'ownerName' => null, 'pages' => [] ];
		// UploadBase itself bounds bare names to240 bytes. Longer description-only
		// reserved titles remain protected, without an unbounded account query.
		if ( strlen( $name ) > 240 ) { return $unknown; }
		$tail = substr( $name, strlen( self::PREFIX ) ); $names = []; $pages = [];
		try {
			for ( $offset = 0; ( $at = strpos( $tail, ' - ', $offset ) ) !== false; $offset = $at + 3 ) {
				$pageText = substr( $tail, 0, $at );
				$page = Title::makeTitleSafe( NS_USER, $pageText );
				if ( !$page || $page->getText() !== $pageText ) { continue; }
				$root = $page->getRootText();
				$canonical = $this->services->getUserNameUtils()->getCanonical( $root, UserRigorOptions::RIGOR_USABLE );
				if ( $canonical === false || $canonical !== $root ) { continue; }
				$names[$canonical] = $canonical; $pages[$canonical][] = $page->getText();
			}
			if ( !$names ) { return $unknown; }
			// One bounded exact-name primary query, including accounts with no User
			// page or actor/edit history. Never pick the first/longest delimiter.
			$db = $this->services->getConnectionProvider()->getPrimaryDatabase();
			$rows = $db->newSelectQueryBuilder()->select( [ 'user_id', 'user_name' ] )->from( 'user' )
				->where( [ 'user_name' => array_values( $names ) ] )->limit( count( $names ) + 1 )->caller( __METHOD__ )->fetchResultSet();
			$owners = [];
			foreach ( $rows as $row ) {
				if ( (int)$row->user_id > 0 && isset( $names[$row->user_name] ) ) { $owners[(int)$row->user_id] = (string)$row->user_name; }
			}
			if ( count( $owners ) !== 1 ) { return $unknown; }
			$id = array_key_first( $owners ); $owner = $owners[$id];
			return [ 'ownerId' => $id, 'ownerName' => $owner, 'pages' => array_values( array_unique( $pages[$owner] ) ) ];
		} catch ( \Throwable $error ) {
			// Lookup failure is not permission, nor permission to invent a portrait.
			return $unknown;
		}
	}

	public function belongsToPage( Title $file, Title $page ): bool {
		if ( $page->getNamespace() !== NS_USER || $file->getNamespace() !== NS_FILE ||
			!str_starts_with( $file->getText(), self::PREFIX . $page->getText() . ' - ' ) ) { return false; }
		// Rendering an unsaved User-page preview grants no write permission and
		// must not depend on account/database availability. Mutation checks above
		// separately require an unambiguous existing stable account ID.
		$root = $page->getRootText();
		return $this->services->getUserNameUtils()->getCanonical( $root, UserRigorOptions::RIGOR_USABLE ) === $root;
	}

	private function permitted( Title $title, $user ): bool {
		$owner = $this->ownerOf( $title );
		return $owner === null || $user->isAllowed( 'editalluserpages' ) ||
			( $user->isNamed() && $owner['ownerId'] !== null && (int)$user->getId() === $owner['ownerId'] );
	}

	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ): bool {
		if ( !in_array( $action, [ 'edit', 'create', 'upload', 'move', 'move-target' ], true ) || $this->permitted( $title, $user ) ) { return true; }
		$result = [ 'eql-character-screenshot-owner-required' ];
		return false; // Stop handlers which would otherwise clear another denial.
	}

	public function onUploadVerifyUpload( $upload, $user, ?array $props, $comment, $pageText, &$error ): bool {
		$title = $upload->getTitle();
		if ( $title && !$this->permitted( $title, $user ) ) {
			$error = [ 'eql-character-screenshot-owner-required' ]; return false;
		}
		if ( $title && !$error && ( $owner = $this->ownerOf( $title ) ) && $owner['ownerId'] !== null ) {
			$this->verifiedUploads[$upload] = [ 'file' => $title->getDBkey(), 'owner' => $owner ];
		}
		return true; // Core rights, blocks/protection and prior hook errors remain authoritative.
	}

	public function onUploadComplete( $upload ): void {
		$verified = $this->verifiedUploads[$upload] ?? null; unset( $this->verifiedUploads[$upload] );
		$title = $upload->getTitle();
		if ( !$verified || !$title || $title->getDBkey() !== $verified['file'] ) { return; }
		$owner = $this->ownerOf( $title );
		if ( !$owner || $owner['ownerId'] !== $verified['owner']['ownerId'] ) { return; }
		try {
			$root = Title::makeTitleSafe( NS_USER, $owner['ownerName'] );
			if ( !$root ) { return; }
			$targets = [ $root->getDBkey() => $root ];
			foreach ( $owner['pages'] as $text ) {
				$page = Title::makeTitleSafe( NS_USER, $text );
				if ( $page && count( $targets ) < self::PURGE_LIMIT ) { $targets[$page->getDBkey()] = $page; }
			}
			$db = $this->services->getConnectionProvider()->getPrimaryDatabase();
			$rows = $db->newSelectQueryBuilder()->select( [ 'page_title' ] )->from( 'imagelinks' )
				->join( 'page', null, 'page_id=il_from' )->where( [ 'il_to' => $title->getDBkey(), 'page_namespace' => NS_USER ] )
				->andWhere( $db->expr( 'page_title', '=', $root->getDBkey() )->or( 'page_title', IExpression::LIKE, new LikeValue( $root->getDBkey() . '/', $db->anyString() ) ) )
				->orderBy( 'page_id' )->limit( self::PURGE_LIMIT )->caller( __METHOD__ )->fetchResultSet();
			foreach ( $rows as $row ) {
				$page = Title::makeTitleSafe( NS_USER, (string)$row->page_title );
				if ( $page && $page->getRootText() === $owner['ownerName'] && count( $targets ) < self::PURGE_LIMIT ) { $targets[$page->getDBkey()] = $page; }
			}
			foreach ( $targets as $target ) {
				$page = $this->services->getWikiPageFactory()->newFromTitle( $target );
				if ( $page->exists() ) { $page->doPurge(); }
			}
		} catch ( \Throwable $error ) {
			// The file is already committed. Core's normal image dependency jobs
			// still run; never misreport a successful upload as an unknown write.
			wfDebugLog( 'eql-character-screenshot', 'Owned portrait uploaded; immediate bounded page invalidation failed.' );
		}
	}
}
