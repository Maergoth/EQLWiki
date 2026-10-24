<?php
/** Public, read-only reverse loot lookup. No pages or indexes are written. */
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;

class EqlNpcLootLookup {

	private const VERSION = 2;
	private const CANDIDATE_TTL = 60;
	private const MAX_ALIASES = 20;
	private const MAX_ZONES = 8;
	private const MAX_CANDIDATES = 160;
	private const MAX_ITEMS = 50;
	private const MAX_AUTHORED = 100;
	private const MAX_BYTES = 524288;
	private const MAX_PUBLIC_READS = 320;
	private const MAX_TOTAL_BYTES = 8388608;
	private MediaWikiServices $services;
	private array $publicMemo = [];
	private array $resolveMemo = [];
	private bool $complete = true;
	private array $warnings = [];
	private int $bytesRead = 0;

	public function __construct( ?MediaWikiServices $services = null ) {
		$this->services = $services ?? MediaWikiServices::getInstance();
	}

	/** @return array{items:array,complete:bool,warnings:array} */
	public function find( Title $npcTitle, array $analysis ): array {
		$this->publicMemo = $this->resolveMemo = [];
		$this->complete = true;
		$this->warnings = [];
		$this->bytesRead = 0;
		try {
			if ( !$this->validTitle( $npcTitle ) || !$this->canRead( $npcTitle ) ) {
				$this->incomplete( 'This page cannot use public recorded-drop lookup.' );
				return $this->result();
			}
			$names = array_values( array_unique( array_merge(
				[ $npcTitle->getPrefixedText() ], $this->strings( $analysis['names'] ?? [], self::MAX_ALIASES )
			) ) );
			if ( count( $names ) > self::MAX_ALIASES ) {
				$this->incomplete( 'Recorded-drop lookup reached a name limit; additional drops may exist.' );
				$names = array_slice( $names, 0, self::MAX_ALIASES );
			}
			$zones = $this->strings( $analysis['zones'] ?? [], self::MAX_ZONES );
			$authored = $this->strings( $analysis['authoredItems'] ?? [], self::MAX_AUTHORED );
			$cache = $this->services->getMainWANObjectCache();
			$key = $cache->makeKey( 'eql-npc-source-loot', self::VERSION,
				$npcTitle->getDBkey(), $npcTitle->getLatestRevID(), hash( 'sha256', json_encode( [ $names, $zones ] ) ) );
			// Cache discovery only. Visibility and public current content are read
			// again below, even on a cache hit, so suppression cannot expose a
			// previously cached relationship through this service.
			$discovery = $cache->getWithSetCallback( $key, self::CANDIDATE_TTL,
				function ( $old, &$ttl ) use ( $npcTitle, $names, $zones ) {
					$value = $this->discover( $npcTitle, $names, $zones );
					if ( !$value['complete'] ) {
						$ttl = 10;
					}
					return $value;
				} );
			if ( !is_array( $discovery ) || !isset( $discovery['titles'], $discovery['complete'], $discovery['aliases'] ) ||
				!is_array( $discovery['titles'] ) || count( $discovery['titles'] ) > self::MAX_CANDIDATES ||
				!is_array( $discovery['aliases'] ) || count( $discovery['aliases'] ) > self::MAX_ALIASES ) {
				throw new RuntimeException( 'Invalid discovery cache.' );
			}
			if ( !$discovery['complete'] ) {
				$this->incomplete( 'Recorded-drop lookup reached a source limit; additional drops may exist.' );
			}
			$authoredIds = $authoredMissing = [];
			foreach ( $authored as $name ) {
				$title = $this->title( $name );
				if ( !$title ) {
					continue;
				}
				if ( !$title->exists() ) {
					$authoredMissing[EqlNpcLootWikitext::identity( $name )] = true;
					continue;
				}
				$canonical = $this->canonical( $title );
				if ( $canonical ) {
					$authoredIds[$canonical->getDBkey()] = true;
				}
			}
			$allowed = array_fill_keys( array_map( [ EqlNpcLootWikitext::class, 'identity' ], array_merge( $names, $discovery['aliases'] ) ), true );
			$items = [];
			foreach ( $discovery['titles'] as $name ) {
				$title = is_string( $name ) ? $this->title( $name ) : null;
				$canonical = $title ? $this->canonical( $title ) : null;
				if ( !$canonical || isset( $authoredIds[$canonical->getDBkey()] ) ||
					isset( $authoredMissing[EqlNpcLootWikitext::identity( $canonical->getPrefixedText() )] ) ) {
					continue;
				}
				$record = $this->publicText( $canonical );
				if ( !$record ) {
					continue;
				}
				foreach ( EqlNpcLootWikitext::itemSources( $record['text'] ) as $source ) {
					if ( !$this->matchesNpc( $source['npc'], $npcTitle, $allowed ) ) {
						continue;
					}
					$key = $canonical->getDBkey();
					if ( !isset( $items[$key] ) && count( $items ) >= self::MAX_ITEMS ) {
						$this->incomplete( 'Recorded-drop lookup reached its item limit; additional drops may exist.' );
						break 2;
					}
					$items[$key] ??= [ 'title' => $canonical->getPrefixedText(), 'contexts' => [] ];
					$context = [ 'zone' => $source['zone'], 'instance' => $source['instance'] ];
					if ( !in_array( $context, $items[$key]['contexts'], true ) ) {
						$items[$key]['contexts'][] = $context;
					}
				}
			}
			ksort( $items, SORT_NATURAL | SORT_FLAG_CASE );
			return $this->result( array_values( $items ) );
		} catch ( Throwable $error ) {
			if ( function_exists( 'wfDebugLog' ) ) {
				wfDebugLog( 'DynamicZoneList', 'Recorded-drop lookup failed: ' . get_class( $error ) );
			}
			$this->incomplete( 'Recorded-drop lookup is temporarily unavailable; additional drops may exist.' );
			return $this->result();
		}
	}

	private function discover( Title $npcTitle, array $names, array $zones ): array {
		$db = $this->services->getConnectionProvider()->getReplicaDatabase();
		$migration = $this->services->getLinksMigration();
		$itemsCategory = $this->services->getTitleFactory()->makeTitleSafe( NS_CATEGORY, 'Items' );
		$itemConditions = [];
		foreach ( $migration->getLinksConditions( 'categorylinks', $itemsCategory ) as $field => $value ) {
			if ( is_int( $field ) ) {
				$itemConditions[] = $value;
			} elseif ( preg_match( '/^cl_[a-z_]+$/', $field ) ) {
				$itemConditions['item_category.' . $field] = $value;
			} else {
				throw new RuntimeException( 'Unsupported category-link query shape.' );
			}
		}
		$targets = [];
		foreach ( $names as $name ) {
			$title = $this->title( $name );
			if ( $title ) {
				$targets[$title->getDBkey()] = $title;
			}
		}
		$complete = true;
		$titles = [];
		// Breadth-first redirect discovery also reaches short alias chains.
		$pending = array_values( $targets );
		$visited = [];
		while ( $pending ) {
			$target = array_shift( $pending );
			if ( isset( $visited[$target->getDBkey()] ) ) {
				continue;
			}
			if ( count( $visited ) >= self::MAX_ALIASES ) {
				$complete = false;
				break;
			}
			$visited[$target->getDBkey()] = true;
			$rows = $db->newSelectQueryBuilder()->select( [ 'page_title' ] )
				->from( 'redirect' )->join( 'page', null, 'page_id = rd_from' )
				->where( [ 'page_namespace' => NS_MAIN, 'rd_namespace' => NS_MAIN, 'rd_title' => $target->getDBkey() ] )
				->orderBy( 'page_id', 'DESC' )->limit( self::MAX_ALIASES + 1 )->caller( __METHOD__ )->fetchResultSet();
			foreach ( $rows as $row ) {
				$title = $this->title( $row->page_title );
				if ( !$title || isset( $targets[$title->getDBkey()] ) ) {
					continue;
				}
				if ( count( $targets ) >= self::MAX_ALIASES ) {
					$complete = false;
					break;
				}
				$targets[$title->getDBkey()] = $title;
				$pending[] = $title;
			}
		}
		foreach ( $targets as $target ) {
			$rows = $db->newSelectQueryBuilder()->select( [ 'page_title' ] )
				->from( 'pagelinks' )->join( 'page', null, 'page_id = pagelinks.pl_from' )
				->join( 'categorylinks', 'item_category', 'item_category.cl_from = page_id' )
				->where( array_merge( [ 'page_namespace' => NS_MAIN, 'page_is_redirect' => 0 ], $migration->getLinksConditions( 'pagelinks', $target ), $itemConditions ) )
				->orderBy( 'page_id', 'DESC' )->limit( self::MAX_CANDIDATES + 1 )->caller( __METHOD__ )->fetchResultSet();
			$this->collect( $rows, $titles, $complete );
		}
		// Case-only redlinks can be absent from the canonical backlink set.
		// Constrain the zone fallback to Items which actually link to one of
		// these names, rather than scanning every item in a busy zone.
		$linkInfo = $migration->getQueryInfo( 'pagelinks' );
		[ $namespaceField, $titleField ] = $migration->getTitleFields( 'pagelinks' );
		if ( !preg_match( '/^(?:lt|pl)_namespace$/', $namespaceField ) || !preg_match( '/^(?:lt|pl)_title$/', $titleField ) ) {
			throw new RuntimeException( 'Unsupported page-link query shape.' );
		}
		$namespaceField = ( str_starts_with( $namespaceField, 'lt_' ) ? 'linktarget.' : 'pagelinks.' ) . $namespaceField;
		$titleField = ( str_starts_with( $titleField, 'lt_' ) ? 'linktarget.' : 'pagelinks.' ) . $titleField;
		$variants = [];
		foreach ( $targets as $target ) {
			$name = str_replace( [ '`', '’', '‘' ], "'", $target->getDBkey() );
			foreach ( [ $target->getDBkey(), $name, str_replace( "'", '`', $name ) ] as $variant ) {
				$variants[function_exists( 'mb_strtolower' ) ? mb_strtolower( $variant, 'UTF-8' ) : strtolower( $variant )] = true;
			}
		}
		$caseCondition = 'LOWER(' . $db->buildStringCast( $titleField ) . ') IN (' .
			implode( ',', array_map( [ $db, 'addQuotes' ], array_keys( $variants ) ) ) . ')';
		foreach ( $zones as $zone ) {
			$category = $this->services->getTitleFactory()->makeTitleSafe( NS_CATEGORY, str_replace( ' ', '_', $zone ) );
			if ( !$category ) {
				continue;
			}
			$zoneConditions = [];
			foreach ( $migration->getLinksConditions( 'categorylinks', $category ) as $field => $value ) {
				if ( is_int( $field ) ) {
					$zoneConditions[] = $value;
				} elseif ( preg_match( '/^cl_[a-z_]+$/', $field ) ) {
					$zoneConditions['categorylinks.' . $field] = $value;
				} else {
					throw new RuntimeException( 'Unsupported category-link query shape.' );
				}
			}
			$query = $db->newSelectQueryBuilder()->select( [ 'page_title', 'page_id' ] )->distinct()
				->from( 'categorylinks' )->join( 'page', null, 'page_id = categorylinks.cl_from' )
				->join( 'categorylinks', 'item_category', 'item_category.cl_from = page_id' )
				->join( 'pagelinks', null, 'pagelinks.pl_from = page_id' );
			foreach ( $linkInfo['joins'] as $table => [ $joinType, $conditions ] ) {
				if ( $joinType !== 'JOIN' || $table !== 'linktarget' ) {
					throw new RuntimeException( 'Unsupported page-link join shape.' );
				}
				$query->join( $table, null, $conditions );
			}
			$rows = $query->where( array_merge( [ 'page_namespace' => NS_MAIN, 'page_is_redirect' => 0, $namespaceField => NS_MAIN, $caseCondition ], $zoneConditions, $itemConditions ) )
				->orderBy( 'page_id', 'DESC' )->limit( self::MAX_CANDIDATES + 1 )->caller( __METHOD__ )->fetchResultSet();
			$this->collect( $rows, $titles, $complete );
		}
		return [ 'titles' => array_values( $titles ), 'aliases' => array_map( static fn ( $title ) => $title->getPrefixedText(), array_values( $targets ) ), 'complete' => $complete ];
	}

	private function collect( iterable $rows, array &$titles, bool &$complete ): void {
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ++$count > self::MAX_CANDIDATES ) {
				$complete = false;
				break;
			}
			$title = $this->title( $row->page_title );
			if ( !$title ) {
				continue;
			}
			$key = $title->getDBkey();
			if ( !isset( $titles[$key] ) && count( $titles ) >= self::MAX_CANDIDATES ) {
				$complete = false;
				continue;
			}
			$titles[$key] = $title->getPrefixedText();
		}
	}

	private function matchesNpc( string $name, Title $npcTitle, array $allowed ): bool {
		if ( !isset( $allowed[EqlNpcLootWikitext::identity( $name )] ) ) {
			return false;
		}
		$title = $this->title( $name );
		if ( !$title ) {
			return false;
		}
		if ( !$title->exists() ) {
			// Forgive an uncreated spelling only. A real case-distinct article
			// below resolves to its own ID and must never be merged into this one.
			return isset( $allowed[EqlNpcLootWikitext::identity( $name )] );
		}
		if ( !$title->isRedirect() ) {
			return $title->getDBkey() === $npcTitle->getDBkey();
		}
		$canonical = $this->canonical( $title );
		return $canonical && $canonical->getDBkey() === $npcTitle->getDBkey();
	}

	private function canonical( Title $title ): ?Title {
		$key = $title->getDBkey();
		if ( array_key_exists( $key, $this->resolveMemo ) ) {
			return $this->resolveMemo[$key];
		}
		$seen = [];
		for ( $depth = 0; $depth < 8; $depth++ ) {
			if ( !$this->validTitle( $title ) || isset( $seen[$title->getDBkey()] ) || !$this->publicText( $title ) ) {
				return $this->resolveMemo[$key] = null;
			}
			$seen[$title->getDBkey()] = true;
			$target = $this->services->getRedirectLookup()->getRedirectTarget( $title );
			if ( !$target ) {
				return $this->resolveMemo[$key] = $title;
			}
			$title = $this->services->getTitleFactory()->newFromLinkTarget( $target );
		}
		$this->incomplete( 'Some source redirects could not be resolved; additional drops may exist.' );
		return $this->resolveMemo[$key] = null;
	}

	private function publicText( Title $title ): ?array {
		$key = $title->getDBkey();
		if ( array_key_exists( $key, $this->publicMemo ) ) {
			return $this->publicMemo[$key];
		}
		if ( count( $this->publicMemo ) >= self::MAX_PUBLIC_READS ) {
			$this->incomplete( 'Recorded-drop lookup reached its public-read limit; additional drops may exist.' );
			return null;
		}
		if ( !$this->validTitle( $title ) || !$title->exists() || !$this->canRead( $title ) ) {
			$this->incomplete( 'Some source pages were not publicly readable; additional drops may exist.' );
			return $this->publicMemo[$key] = null;
		}
		$revision = $this->services->getRevisionLookup()->getRevisionByTitle( $title );
		$content = $revision ? $revision->getContent( 'main', RevisionRecord::FOR_PUBLIC ) : null;
		if ( !$content || $content->getModel() !== 'wikitext' || !method_exists( $content, 'getText' ) ) {
			$this->incomplete( 'Some source content was not publicly readable; additional drops may exist.' );
			return $this->publicMemo[$key] = null;
		}
		$text = $content->getText();
		if ( strlen( $text ) > self::MAX_BYTES ) {
			$this->incomplete( 'Some source pages exceeded the lookup limit; additional drops may exist.' );
			return $this->publicMemo[$key] = null;
		}
		$this->bytesRead += strlen( $text );
		if ( $this->bytesRead > self::MAX_TOTAL_BYTES ) {
			$this->incomplete( 'Recorded-drop lookup reached its content limit; additional drops may exist.' );
			return $this->publicMemo[$key] = null;
		}
		return $this->publicMemo[$key] = [ 'text' => $text, 'revision' => $revision->getId() ];
	}

	private function canRead( Title $title ): bool {
		return $this->services->getPermissionManager()->userCan( 'read', $this->services->getUserFactory()->newAnonymous(), $title );
	}

	private function validTitle( Title $title ): bool {
		return $title->getNamespace() === NS_MAIN && !$title->isExternal() && !$title->getFragment();
	}

	private function title( string $name ): ?Title {
		$title = $this->services->getTitleFactory()->newFromText( $name, NS_MAIN );
		return $title && $this->validTitle( $title ) ? $title : null;
	}

	private function strings( array $values, int $limit ): array {
		if ( count( $values ) > $limit ) {
			$this->incomplete( 'Recorded-drop lookup reached a page-context limit; additional drops may exist.' );
		}
		return array_values( array_filter( array_slice( $values, 0, $limit ), static fn ( $value ) => is_string( $value ) && $value !== '' && strlen( $value ) <= 240 ) );
	}

	private function incomplete( string $warning ): void {
		$this->complete = false;
		$this->warnings[$warning] = $warning;
	}

	private function result( array $items = [] ): array {
		return [ 'items' => $items, 'complete' => $this->complete, 'warnings' => array_values( $this->warnings ) ];
	}
}
