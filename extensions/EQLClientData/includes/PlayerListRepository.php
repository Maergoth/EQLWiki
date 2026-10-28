<?php

declare( strict_types = 1 );

namespace EQLClientData;

use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\Page\PageRecord;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use RuntimeException;
use WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDBAccessObject;

/** Read-only, keyset-paginated public User-page snapshots. No account-table access. */
final class PlayerListRepository {
	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly PageStore $pageStore,
		private readonly RevisionLookup $revisionLookup,
		private readonly TitleFactory $titleFactory,
		private readonly PermissionManager $permissions,
		private readonly UserFactory $userFactory,
		private readonly ParserOutputAccess $parserOutputAccess,
		private readonly WANObjectCache $cache,
		private readonly Config $config
	) {
	}

	public function getBatch( ?string $continue = null, int $limit = 10 ): array {
		$limit = max( 1, min( 25, $limit ) );
		$db = $this->dbProvider->getPrimaryDatabase();
		[ $after, $until ] = $continue === null || $continue === '' ? [
			0, (int)$db->selectField( 'page', 'MAX(page_id)', [ 'page_namespace' => NS_USER ], __METHOD__ )
		] : self::decodeContinue( $continue );
		// The native transclusion index includes nested transclusions and normal title normalization.
		$candidates = $db->select(
			[ 'page', 'tl' => 'templatelinks', 'lt' => 'linktarget' ],
			[ 'page_id' ],
			[
				'page_namespace' => NS_USER, 'page_is_redirect' => 0,
				'lt.lt_namespace' => NS_TEMPLATE, 'lt.lt_title' => 'Character_sheet',
				'page_id > ' . $after, 'page_id <= ' . $until
			],
			__METHOD__,
			[ 'DISTINCT', 'ORDER BY' => 'page_id ASC', 'LIMIT' => $limit + 1 ],
			[
				'tl' => [ 'INNER JOIN', 'tl.tl_from = page_id' ],
				'lt' => [ 'INNER JOIN', 'tl.tl_target_id = lt.lt_id' ]
			]
		);
		$ids = [];
		foreach ( $candidates as $candidate ) {
			$ids[] = (int)$candidate->page_id;
		}
		$hasMore = count( $ids ) > $limit;
		$ids = array_slice( $ids, 0, $limit );
		$players = [];
		$maxLevel = max( 1, min( 1000, (int)$this->config->get( 'EQLClientDataPlayerMaxLevel' ) ) );
		foreach ( $ids as $id ) {
			$after = $id;
			$page = $this->pageStore->getPageById( $id, IDBAccessObject::READ_LATEST );
			if ( !$this->isRootUserPage( $page ) ) {
				continue;
			}
			$snapshot = $this->snapshots( $page, $maxLevel );
			// Do not let a concurrent move, deletion, edit or suppression publish stale cached rows.
			$current = $this->pageStore->getPageById( $id, IDBAccessObject::READ_LATEST );
			if ( !$this->isRootUserPage( $current ) || !$this->samePageVersion( $page, $current ) || !$this->publicRevision( $current ) ) {
				continue;
			}
			$title = $this->titleFactory->newFromPageIdentity( $current );
			foreach ( $snapshot['rows'] as $row ) {
				$players[] = [ 'id' => $id . ':' . substr( hash( 'sha256', $row['name'] . "\0" . $row['server'] ), 0, 16 ) ] + $row + [
					'pageTitle' => $title->getPrefixedText(), 'pageUrl' => $title->getLocalURL(),
					'pageRevision' => $page->getLatest(), 'snapshotToken' => $snapshot['snapshotToken']
				];
			}
		}
		$result = [ 'players' => $players, 'maxLevel' => $maxLevel ];
		if ( $hasMore ) {
			$result['continue'] = self::encodeContinue( $after, $until );
		}
		return $result;
	}

	/** Direct lookup only: never traverse the directory or parse caller-supplied source. */
	public function getDetail( string $id, string $token ): array {
		if ( !preg_match( '/^([1-9][0-9]{0,9}):([a-f0-9]{16})$/D', $id, $match ) ||
			!preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			throw new InvalidArgumentException( 'badvalue' );
		}
		$page = $this->pageStore->getPageById( (int)$match[1], IDBAccessObject::READ_LATEST );
		if ( !$this->isRootUserPage( $page ) || !$this->publicRevision( $page ) ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		$maxLevel = max( 1, min( 1000, (int)$this->config->get( 'EQLClientDataPlayerMaxLevel' ) ) );
		$snapshot = $this->snapshots( $page, $maxLevel );
		if ( !hash_equals( $snapshot['snapshotToken'], $token ) ) { throw new RuntimeException( 'eqlplayers-detail-stale' ); }
		$rows = array_values( array_filter( $snapshot['rows'], static fn ( $row ) => substr( hash( 'sha256', $row['name'] . "\0" . $row['server'] ), 0, 16 ) === $match[2] ) );
		if ( count( $rows ) !== 1 ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		if ( !empty( $rows[0]['snapshotConflict'] ) ) { throw new RuntimeException( 'eqlplayers-detail-conflict' ); }
		$key = $this->cache->makeKey( 'eqlplayerdetail', 'schema1-v2', $id, $token );
		$detail = $this->cache->get( $key );
		if ( !is_array( $detail ) ) {
			$rendered = $this->snapshots( $page, $maxLevel, true );
			if ( !hash_equals( $rendered['snapshotToken'], $token ) ) { throw new RuntimeException( 'eqlplayers-detail-stale' ); }
			$title = $this->titleFactory->newFromPageIdentity( $page );
			$fields = CharacterSheetDetail::extract( $rendered['html'], $match[2], $maxLevel, (string)$this->config->get( 'Server' ), $title->getPrefixedText() );
			if ( !$fields ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
			$detail = [ 'schemaVersion' => 1, 'id' => $id, 'pageTitle' => $title->getPrefixedText(),
				'pageUrl' => $title->getLocalURL(), 'revision' => $page->getLatest(), 'snapshotToken' => $token ] + $fields;
			if ( strlen( json_encode( $detail, JSON_THROW_ON_ERROR ) ) > CharacterSheetDetail::MAX_BYTES ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		}
		// Visibility is never cached. Catch suppression, moves, deletion and
		// dependency edits which happen while reading either cache or parser.
		$current = $this->pageStore->getPageById( $page->getId(), IDBAccessObject::READ_LATEST );
		if ( !$this->isRootUserPage( $current ) || !$this->publicRevision( $current ) ) { throw new RuntimeException( 'eqlplayers-detail-unavailable' ); }
		if ( !$this->samePageVersion( $page, $current ) || !$this->dependenciesCurrent( $snapshot['dependencies'] ) ) { throw new RuntimeException( 'eqlplayers-detail-stale' ); }
		$this->cache->set( $key, $detail, 30 );
		return $detail;
	}

	private function snapshots( PageRecord $page, int $maxLevel, bool $withHtml = false ): array {
		$revision = $this->publicRevision( $page );
		if ( !$revision ) {
			return [ 'rows' => [], 'dependencies' => [], 'snapshotToken' => '' ];
		}
		$key = $this->cache->makeKey( 'eqlplayers', 'schema1-v3', $page->getId(), $page->getLatest(), $page->getTouched(), $maxLevel );
		$cached = $this->cache->get( $key );
		if ( !$withHtml && is_array( $cached ) && isset( $cached['rows'], $cached['dependencies'], $cached['snapshotToken'] ) &&
			$this->dependenciesCurrent( $cached['dependencies'] ) ) {
			return $cached;
		}
		$options = ParserOptions::newFromAnon();
		// A dependency may have changed before an asynchronous parser-cache purge completes.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$status = $this->parserOutputAccess->getParserOutput( $page, $options, $revision,
				$attempt === 0 ? [] : [ ParserOutputAccess::OPT_NO_CHECK_CACHE => true,
					ParserOutputAccess::OPT_NO_UPDATE_CACHE => true ] );
			if ( !$status->isGood() || !$status->getValue() ) {
				throw new RuntimeException( 'player-list-parser-unavailable' );
			}
			$output = $status->getValue();
			$dependencies = [];
			$hasSheetTemplate = false;
			foreach ( $output->getLinkList( ParserOutputLinkTypes::TEMPLATE ) as $dependency ) {
				$link = $dependency['link'];
				if ( $link->getNamespace() === NS_TEMPLATE && $link->getDBkey() === 'Character_sheet' ) {
					$hasSheetTemplate = true;
				}
				// Missing transclusions cannot contribute private content or a sheet, but a newly
				// created page must invalidate this cached output too (record its literal target).
				$dependencies[] = [ 'id' => (int)( $dependency['pageid'] ?? 0 ),
					'revision' => (int)( $dependency['revid'] ?? 0 ),
					'namespace' => $link->getNamespace(), 'title' => $link->getDBkey() ];
				if ( count( $dependencies ) > 128 ) {
					throw new RuntimeException( 'player-list-dependency-limit' );
				}
			}
			if ( !$this->dependenciesCurrent( $dependencies ) ) {
				continue;
			}
			$html = $output->getContentHolderText();
			$rows = $hasSheetTemplate ? CharacterSheetSnapshot::extract( $html, $maxLevel ) : [];
			usort( $dependencies, static fn ( $first, $second ) => [ $first['namespace'], $first['title'], $first['id'], $first['revision'] ] <=> [ $second['namespace'], $second['title'], $second['id'], $second['revision'] ] );
			$token = hash( 'sha256', json_encode( [ $page->getId(), $page->getDBkey(), $page->getLatest(), $page->getTouched(), $maxLevel, $dependencies, hash( 'sha256', $html ) ], JSON_THROW_ON_ERROR ) );
			$value = [ 'rows' => $rows, 'dependencies' => $dependencies, 'snapshotToken' => $token ];
			$this->cache->set( $key, $value, 30 );
			return $withHtml ? $value + [ 'html' => $html ] : $value;
		}
		// Fail the batch visibly rather than make an apparently complete partial leaderboard.
		throw new RuntimeException( 'player-list-dependencies-unavailable' );
	}

	private function dependenciesCurrent( array $dependencies ): bool {
		foreach ( $dependencies as $dependency ) {
			$current = $this->pageStore->getPageByName( $dependency['namespace'], $dependency['title'], IDBAccessObject::READ_LATEST );
			if ( $dependency['id'] === 0 && !$current ) {
				continue;
			}
			if ( !$current || $current->getId() !== $dependency['id'] || $current->getLatest() !== $dependency['revision'] || !$this->publicRevision( $current ) ) {
				return false;
			}
		}
		return true;
	}

	private function publicRevision( PageRecord $page ): ?RevisionRecord {
		$title = $this->titleFactory->newFromPageIdentity( $page );
		if ( !$this->permissions->userCan( 'read', $this->userFactory->newAnonymous(), $title, PermissionManager::RIGOR_SECURE ) ) {
			return null;
		}
		$revision = $this->revisionLookup->getRevisionById( $page->getLatest(), IDBAccessObject::READ_LATEST, $page );
		return $revision && $revision->getPageId() === $page->getId() &&
			$revision->audienceCan( RevisionRecord::DELETED_TEXT, RevisionRecord::FOR_PUBLIC ) ? $revision : null;
	}

	private function isRootUserPage( ?PageRecord $page ): bool {
		return $page && $page->getNamespace() === NS_USER && !$page->isRedirect() && !str_contains( $page->getDBkey(), '/' );
	}

	private function samePageVersion( PageRecord $first, PageRecord $second ): bool {
		return $first->getLatest() === $second->getLatest() && $first->getDBkey() === $second->getDBkey() && $first->getTouched() === $second->getTouched();
	}

	private static function encodeContinue( int $after, int $until ): string {
		return rtrim( strtr( base64_encode( '1|' . $after . '|' . $until ), '+/', '-_' ), '=' );
	}

	private static function decodeContinue( string $token ): array {
		if ( strlen( $token ) > 100 || !preg_match( '/^[A-Za-z0-9_-]+$/D', $token ) ) {
			throw new InvalidArgumentException( 'badcontinue' );
		}
		$decoded = base64_decode( strtr( $token, '-_', '+/' ), true );
		if ( !$decoded || !preg_match( '/^1\|([0-9]{1,10})\|([0-9]{1,10})$/D', $decoded, $parts ) ) {
			throw new InvalidArgumentException( 'badcontinue' );
		}
		$after = (int)$parts[1];
		$until = (int)$parts[2];
		if ( $after < 1 || $after > $until || self::encodeContinue( $after, $until ) !== $token ) {
			throw new InvalidArgumentException( 'badcontinue' );
		}
		return [ $after, $until ];
	}
}
