<?php
/** Targeted cache maintenance for reciprocal public item/NPC relationships. */
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDBAccessObject;

final class EqlNpcLootInvalidation {
	private const MAX_BYTES = 524288;
	private const MAX_SOURCES_PER_REVISION = 50;
	private const MAX_ZONES = 8;
	private const MAX_TARGETS = 100;
	private const MAX_PUBLIC_READS = 128;
	private const MAX_TOTAL_BYTES = 8388608;
	private MediaWikiServices $services;
	private array $canonicalMemo = [];
	private int $publicReads = 0;
	private int $bytesRead = 0;

	public function __construct( ?MediaWikiServices $services = null ) {
		$this->services = $services ?? MediaWikiServices::getInstance();
	}

	/** One check key invalidates every discovery-cache variant of this NPC. */
	public static function checkKey( $cache, Title $npcTitle ): string {
		return $cache->makeKey( 'eql-npc-source-loot-changed', $npcTitle->getDBkey() );
	}

	public static function onPageSaveComplete( $page, $user, $summary, $flags, $revision, $editResult ): void {
		self::safely( static function ( self $handler ) use ( $page, $revision ) {
			$handler->fromPublishedRevision( $page->getTitle(), $revision );
		} );
	}

	/** Repeat after link/category commits, so new sources are discoverable. */
	public static function onLinksUpdateComplete( $linksUpdate, $ticket ): void {
		self::safely( static function ( self $handler ) use ( $linksUpdate ) {
			$title = $linksUpdate->getTitle();
			$revision = $linksUpdate->getRevisionRecord();
			if ( !$revision ) {
				$revision = $handler->services->getRevisionLookup()->getRevisionByTitle(
					$title, 0, IDBAccessObject::READ_LATEST
				);
			}
			if ( $revision ) {
				$handler->fromPublishedRevision( $title, $revision );
			}
		} );
	}

	public static function onPageDeleteComplete( $page, $deleter, $reason, $pageID, $revision, $logEntry, $count ): void {
		self::safely( static function ( self $handler ) use ( $page, $revision ) {
			$title = $handler->services->getTitleFactory()->newFromPageIdentity( $page );
			if ( $handler->validTitle( $title ) && $handler->canRead( $title ) ) {
				$handler->invalidateRevisions( [ $revision ] );
			}
		} );
	}

	public static function onPageMoveComplete( $old, $new, $user, $pageID, $redirectID, $reason, $revision ): void {
		self::safely( static function ( self $handler ) use ( $old, $new, $revision ) {
			$factory = $handler->services->getTitleFactory();
			$oldTitle = $factory->newFromLinkTarget( $old );
			$newTitle = $factory->newFromLinkTarget( $new );
			if ( ( $handler->validTitle( $oldTitle ) || $handler->validTitle( $newTitle ) ) &&
				$newTitle && $handler->canRead( $newTitle ) ) {
				// The move revision carries the moved content even when the old
				// title is now a redirect, or a page moves out of main namespace.
				$handler->invalidateRevisions( [ $revision ] );
			}
		} );
	}

	private static function safely( callable $callback ): void {
		try {
			$callback( new self() );
		} catch ( Throwable $error ) {
			// A cache failure must never reject an otherwise successful edit.
			// Do not include source text, titles or private exception messages.
			if ( function_exists( 'wfDebugLog' ) ) {
				wfDebugLog( 'DynamicZoneList', 'Recorded-drop cache invalidation failed: ' . get_class( $error ) );
			}
		}
	}

	private function fromPublishedRevision( Title $title, RevisionRecord $revision ): void {
		if ( !$this->validTitle( $title ) || !$this->canRead( $title ) ) {
			return;
		}
		$revisions = [ $revision ];
		if ( $revision->getParentId() ) {
			$parent = $this->services->getRevisionLookup()->getRevisionById(
				$revision->getParentId(), IDBAccessObject::READ_LATEST
			);
			if ( $parent ) {
				$revisions[] = $parent;
			}
		}
		$this->invalidateRevisions( $revisions );
	}

	/** Public entry for focused service tests; no page revisions are written. */
	public function invalidateRevisions( array $revisions ): void {
		$this->canonicalMemo = [];
		$this->publicReads = $this->bytesRead = 0;
		$sources = [];
		$itemSources = [];
		foreach ( array_slice( $revisions, 0, 2 ) as $revision ) {
			if ( !$revision instanceof RevisionRecord ) {
				continue;
			}
			$text = $this->publicText( $revision );
			if ( $text === null ) {
				continue;
			}
			foreach ( array_slice( EqlNpcLootWikitext::itemSources( $text ), 0, self::MAX_SOURCES_PER_REVISION ) as $source ) {
				$key = $source['npc'] . '\n' . $source['zone'];
				$sources[$key] = $source;
			}
			foreach ( array_slice( EqlNpcLootWikitext::npcLootSources( $text ), 0, self::MAX_SOURCES_PER_REVISION ) as $source ) {
				$itemSources[$source['item'] . '\n' . $source['zone']] = $source;
			}
		}
		$targets = [];
		$zones = [];
		foreach ( $sources as $source ) {
			$title = $this->services->getTitleFactory()->newFromText( $source['npc'], NS_MAIN );
			if ( !$this->validTitle( $title ) ) {
				continue;
			}
			// Respect real case-distinct pages. A case fallback applies only
			// when the actual source link is a redlink, just like the lookup.
			if ( $title->exists() ) {
				$canonical = $this->canonicalNpc( $title );
				if ( $canonical ) {
					$targets[$canonical->getDBkey()] = $canonical;
				}
			} elseif ( $source['zone'] !== '' &&
				( isset( $zones[$source['zone']] ) || count( $zones ) < self::MAX_ZONES ) ) {
				$zones[$source['zone']][$title->getDBkey()] = $source['npc'];
			}
		}
		foreach ( $zones as $zone => $names ) {
			foreach ( $this->caseVariantsInZone( $zone, $names ) as $title ) {
				$canonical = $this->canonicalNpc( $title );
				if ( $canonical ) {
					$targets[$canonical->getDBkey()] = $canonical;
				}
			}
		}
		$cache = $this->services->getMainWANObjectCache();
		foreach ( array_slice( $targets, 0, self::MAX_TARGETS ) as $title ) {
			$cache->touchCheckKey( self::checkKey( $cache, $title ) );
			// Core purge updates page_touched, parser/file caches and CDN URLs.
			// It does not create a revision or a misleading RecentChanges entry.
			$this->services->getWikiPageFactory()->newFromTitle( $title )->doPurge();
		}
		$this->invalidateItemSources( $itemSources );
	}

	private function invalidateItemSources( array $sources ): void {
		$targets = $missing = [];
		foreach ( $sources as $source ) {
			$title = $this->services->getTitleFactory()->newFromText( $source['item'], NS_MAIN );
			if ( !$this->validTitle( $title ) ) { continue; }
			if ( $title->exists() ) {
				$canonical = $this->canonicalItem( $title );
				if ( $canonical ) { $targets[$canonical->getDBkey()] = $canonical; }
			} else {
				$missing[$title->getDBkey()] = $source['item'];
			}
		}
		if ( $missing ) {
			// An item's missing source is the gap being filled, so it cannot
			// already be required to belong to that source's zone category.
			foreach ( $this->caseVariantsInZone( '', $missing, true ) as $title ) {
				$canonical = $this->canonicalItem( $title );
				if ( $canonical ) { $targets[$canonical->getDBkey()] = $canonical; }
			}
		}
		$cache = $this->services->getMainWANObjectCache();
		foreach ( array_slice( $targets, 0, self::MAX_TARGETS ) as $title ) {
			$cache->touchCheckKey( EqlItemDropLookup::checkKey( $cache, $title ) );
			$this->services->getWikiPageFactory()->newFromTitle( $title )->doPurge();
		}
	}

	private function caseVariantsInZone( string $zone, array $names, bool $itemsOnly = false ): array {
		$category = $this->services->getTitleFactory()->makeTitleSafe( NS_CATEGORY, $itemsOnly ? 'Items' : $zone );
		if ( !$category || $category->isExternal() || $category->getFragment() ) {
			return [];
		}
		$db = $this->services->getConnectionProvider()->getPrimaryDatabase();
		$conditions = $this->services->getLinksMigration()->getLinksConditions( 'categorylinks', $category );
		$folded = array_map( static fn ( $name ) => mb_strtolower( $name, 'UTF-8' ), array_keys( $names ) );
		$conditions += [ 'page_namespace' => NS_MAIN ];
		// MediaWiki's MySQL buildStringCast() deliberately returns BINARY;
		// LOWER(BINARY) does not fold case. Explicit UTF-8 text is required.
		$conditions[] = 'LOWER(CAST(page_title AS CHAR CHARACTER SET utf8mb4)) IN (' .
			implode( ',', array_map( [ $db, 'addQuotes' ], $folded ) ) . ')';
		$rows = $db->newSelectQueryBuilder()->select( [ 'page_title' ] )->from( 'categorylinks' )
			->join( 'page', null, 'categorylinks.cl_from = page_id' );
		$rows = $rows->where( $conditions )
			->limit( self::MAX_TARGETS + 1 )->caller( __METHOD__ )->fetchResultSet();
		$identities = array_fill_keys( array_map( [ EqlNpcLootWikitext::class, 'identity' ], $names ), true );
		$result = [];
		foreach ( $rows as $row ) {
			if ( count( $result ) >= self::MAX_TARGETS ) {
				break;
			}
			if ( isset( $identities[EqlNpcLootWikitext::identity( $row->page_title )] ) ) {
				$title = $this->services->getTitleFactory()->makeTitleSafe( NS_MAIN, $row->page_title );
				if ( $this->validTitle( $title ) ) {
					$result[] = $title;
				}
			}
		}
		return $result;
	}

	private function canonicalNpc( Title $title ): ?Title {
		$key = $title->getDBkey();
		if ( !array_key_exists( $key, $this->canonicalMemo ) ) {
			$this->canonicalMemo[$key] = $this->resolveNpc( $title );
		}
		return $this->canonicalMemo[$key];
	}

	private function resolveNpc( Title $title ): ?Title {
		return $this->resolvePage( $title, false );
	}

	private function canonicalItem( Title $title ): ?Title {
		$key = 'item:' . $title->getDBkey();
		if ( !array_key_exists( $key, $this->canonicalMemo ) ) {
			$this->canonicalMemo[$key] = $this->resolvePage( $title, true );
		}
		return $this->canonicalMemo[$key];
	}

	private function resolvePage( Title $title, bool $item ): ?Title {
		$seen = [];
		for ( $depth = 0; $depth < 8; $depth++ ) {
			if ( !$this->validTitle( $title ) || !$title->exists() || !$this->canRead( $title ) ||
				isset( $seen[$title->getDBkey()] ) ) {
				return null;
			}
			$seen[$title->getDBkey()] = true;
			$revision = $this->services->getRevisionLookup()->getRevisionByTitle(
				$title, 0, IDBAccessObject::READ_LATEST
			);
			$text = $revision ? $this->publicText( $revision ) : null;
			if ( $text === null ) {
				return null;
			}
			$target = $this->services->getRedirectLookup()->getRedirectTarget( $title );
			if ( !$target ) {
				$analysis = $item ? EqlNpcLootWikitext::analyseItemPage( $text ) : EqlNpcLootWikitext::analyseNpcPage( $text );
				return $analysis !== null ? $title : null;
			}
			$title = $this->services->getTitleFactory()->newFromLinkTarget( $target );
		}
		return null;
	}

	private function publicText( RevisionRecord $revision ): ?string {
		if ( ++$this->publicReads > self::MAX_PUBLIC_READS ) {
			return null;
		}
		$content = $revision->getContent( 'main', RevisionRecord::FOR_PUBLIC );
		if ( !$content || $content->getModel() !== 'wikitext' || !method_exists( $content, 'getText' ) ) {
			return null;
		}
		$text = $content->getText();
		$this->bytesRead += strlen( $text );
		return strlen( $text ) <= self::MAX_BYTES && $this->bytesRead <= self::MAX_TOTAL_BYTES ? $text : null;
	}

	private function validTitle( ?Title $title ): bool {
		return $title && $title->getNamespace() === NS_MAIN && !$title->isExternal() && !$title->getFragment();
	}

	private function canRead( Title $title ): bool {
		return $this->services->getPermissionManager()->userCan(
			'read', $this->services->getUserFactory()->newAnonymous(), $title
		);
	}
}
