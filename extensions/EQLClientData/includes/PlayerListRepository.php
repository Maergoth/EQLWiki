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
			$rows = $this->snapshots( $page, $maxLevel );
			// Do not let a concurrent move, deletion, edit or suppression publish stale cached rows.
			$current = $this->pageStore->getPageById( $id, IDBAccessObject::READ_LATEST );
			if ( !$this->isRootUserPage( $current ) || !$this->samePageVersion( $page, $current ) || !$this->publicRevision( $current ) ) {
				continue;
			}
			$title = $this->titleFactory->newFromPageIdentity( $current );
			foreach ( $rows as $row ) {
				$players[] = [ 'id' => $id . ':' . substr( hash( 'sha256', $row['name'] . "\0" . $row['server'] ), 0, 16 ) ] + $row + [
					'pageTitle' => $title->getPrefixedText(), 'pageUrl' => $title->getLocalURL()
				];
			}
		}
		$result = [ 'players' => $players, 'maxLevel' => $maxLevel ];
		if ( $hasMore ) {
			$result['continue'] = self::encodeContinue( $after, $until );
		}
		return $result;
	}

	private function snapshots( PageRecord $page, int $maxLevel ): array {
		$revision = $this->publicRevision( $page );
		if ( !$revision ) {
			return [];
		}
		$key = $this->cache->makeKey( 'eqlplayers', 'schema1-v2', $page->getId(), $page->getLatest(), $page->getTouched(), $maxLevel );
		$cached = $this->cache->get( $key );
		if ( is_array( $cached ) && isset( $cached['rows'], $cached['dependencies'] ) &&
			$this->dependenciesCurrent( $cached['dependencies'] ) ) {
			return $cached['rows'];
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
			$rows = $hasSheetTemplate ? CharacterSheetSnapshot::extract( $output->getContentHolderText(), $maxLevel ) : [];
			$this->cache->set( $key, [ 'rows' => $rows, 'dependencies' => $dependencies ], 30 );
			return $rows;
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
