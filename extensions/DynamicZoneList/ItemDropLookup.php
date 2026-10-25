<?php
/** Public, read-only item sources recovered from authored NPC loot fields. */
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
require_once __DIR__ . '/PublicLootCollections.php';

class EqlItemDropLookup {
	use EqlPublicLootCollections;

	private const VERSION = 3;
	private const CANDIDATE_TTL = 60;
	private const MAX_ALIASES = 20;
	private const MAX_ZONES = 8;
	private const MAX_CANDIDATES = 160;
	private const MAX_COLLECTIONS = 20;
	private const MAX_SOURCES = 50;
	private const MAX_EXISTING = 100;
	private const MAX_PUBLIC_READS = 320;
	private const MAX_PAGE_BYTES = 524288;
	private const MAX_TOTAL_BYTES = 8388608;
	private MediaWikiServices $services;
	private array $publicMemo = [];
	private array $resolveMemo = [];
	private bool $complete = true;
	private array $warnings = [];
	private int $bytesRead = 0;
	private array $zoneAliases = [];

	public function __construct( ?MediaWikiServices $services = null ) {
		$this->services = $services ?? MediaWikiServices::getInstance();
	}

	/** Shared with targeted NPC-edit invalidation; do not create a synthetic page revision. */
	public static function checkKey( $cache, Title $title ): string {
		return $cache->makeKey( 'eql-item-source-loot-check', EqlNpcLootWikitext::identity( $title->getPrefixedText() ) );
	}

	/**
	 * @param array{names?:array,zones?:array,existingSources?:array} $analysis
	 * @return array{sources:array,complete:bool,warnings:array}
	 */
	public function find( Title $itemTitle, array $analysis ): array {
		$this->publicMemo = $this->resolveMemo = $this->zoneAliases = $this->collectionMemo = [];
		$this->complete = true;
		$this->warnings = [];
		$this->bytesRead = 0;
		try {
			if ( !$this->validTitle( $itemTitle ) || !$this->canRead( $itemTitle ) ) {
				$this->incomplete( 'This page cannot use public recorded-source lookup.' );
				return $this->result();
			}
			$names = array_values( array_unique( array_merge( [ $itemTitle->getPrefixedText() ],
				$this->strings( $analysis['names'] ?? [], self::MAX_ALIASES ) ) ) );
			if ( count( $names ) > self::MAX_ALIASES ) {
				$this->incomplete( 'Recorded-source lookup reached a name limit; additional sources may exist.' );
				$names = array_slice( $names, 0, self::MAX_ALIASES );
			}
			$zones = $this->strings( $analysis['zones'] ?? [], self::MAX_ZONES );
			$existing = $analysis['existingSources'] ?? [];
			if ( !is_array( $existing ) || count( $existing ) > self::MAX_EXISTING ) {
				$this->incomplete( 'Existing sources exceed the exclusion limit; additional sources were not added to avoid duplicates.' );
				return $this->result();
			}
			$cache = $this->services->getMainWANObjectCache();
			$key = $cache->makeKey( 'eql-item-source-loot', self::VERSION, $itemTitle->getDBkey(),
				$itemTitle->getLatestRevID(), hash( 'sha256', json_encode( [ $names, $zones ] ) ) );
			// Cache only discovery, never content or permissions. Every cache hit
			// rechecks anonymous visibility and the current public revision below.
			$discovery = $cache->getWithSetCallback( $key, self::CANDIDATE_TTL,
				function ( $old, &$ttl ) use ( $names, $zones ) {
					$value = $this->discover( $names, $zones );
					if ( !$value['complete'] ) { $ttl = 10; }
					return $value;
				}, [ 'checkKeys' => [ self::checkKey( $cache, $itemTitle ) ] ] );
			if ( !is_array( $discovery ) || !isset( $discovery['titles'], $discovery['aliases'], $discovery['complete'] ) ||
				!is_array( $discovery['titles'] ) || count( $discovery['titles'] ) > self::MAX_CANDIDATES ||
				!is_array( $discovery['aliases'] ) || count( $discovery['aliases'] ) > self::MAX_ALIASES ) {
				throw new RuntimeException( 'Invalid recorded-source discovery cache.' );
			}
			if ( !$discovery['complete'] ) {
				$this->incomplete( 'Recorded-source lookup reached a candidate limit; additional sources may exist.' );
			}
			$allowed = array_fill_keys( array_map( [ EqlNpcLootWikitext::class, 'identity' ], array_merge( $names, $discovery['aliases'] ) ), true );
			$sources = [];
			foreach ( $discovery['titles'] as $name ) {
				$title = is_string( $name ) ? $this->title( $name ) : null;
				$canonical = $title ? $this->canonical( $title ) : null;
				$record = $canonical ? $this->publicText( $canonical ) : null;
				if ( !$record ) { continue; }
				foreach ( EqlNpcLootWikitext::npcLootSources( $record['text'], $sourceComplete, true ) as $source ) {
					$matches = $this->matchesItem( $source['item'], $itemTitle, $allowed );
					if ( !$matches ) {
						$reference = $this->title( $source['item'] );
						$collection = $reference ? $this->collectionMembers( $reference ) : [ 'items' => [], 'complete' => false ];
						$matches = $collection['complete'] && in_array( $itemTitle->getDBkey(), array_map( static fn ( $title ) => $title->getDBkey(), $collection['items'] ), true );
					}
					if ( !$matches ||
						$this->alreadyDeclared( $canonical, $source, $existing ) ) { continue; }
					$source['zone'] = $this->zoneName( $source['zone'] );
					$instance = ( $source['instance'] ?? 'normal' ) === 'raid' ? 'raid' : 'normal';
					$key = $canonical->getDBkey() . '|' . $source['zone'] . '|' . $instance;
					if ( !isset( $sources[$key] ) && count( $sources ) >= self::MAX_SOURCES ) {
						$this->incomplete( 'Recorded-source lookup reached its result limit; additional sources may exist.' );
						break 2;
					}
					$sources[$key] ??= [ 'npc' => $canonical->getPrefixedText(), 'zone' => $source['zone'],
						'instance' => $instance, 'lootKind' => ( $source['lootKind'] ?? '' ) === 'common' ? 'common' : 'known' ];
				}
			}
			// Supply only bounded, current public redirect proofs for layout matching.
			foreach ( $existing as $row ) { if ( is_array( $row ) && is_string( $row['zone'] ?? null ) ) { $this->zoneName( $row['zone'] ); } }
			ksort( $sources, SORT_NATURAL | SORT_FLAG_CASE );
			return $this->result( array_values( $sources ) );
		} catch ( Throwable $error ) {
			if ( function_exists( 'wfDebugLog' ) ) {
				wfDebugLog( 'DynamicZoneList', 'Recorded item-source lookup failed: ' . get_class( $error ) );
			}
			$this->incomplete( 'Recorded-source lookup is temporarily unavailable; additional sources may exist.' );
			return $this->result();
		}
	}

	private function discover( array $names, array $zones ): array {
		$db = $this->services->getConnectionProvider()->getReplicaDatabase();
		$migration = $this->services->getLinksMigration();
		$npcCategory = $this->services->getTitleFactory()->makeTitleSafe( NS_CATEGORY, 'NPCs' );
		$npcConditions = $this->categoryConditions( $migration, $npcCategory, 'npc_category' );
		$targets = [];
		foreach ( $names as $name ) {
			$title = $this->title( $name );
			if ( $title ) { $targets[$title->getDBkey()] = $title; }
		}
		$complete = true;
		$titles = [];
		$pending = array_values( $targets );
		$visited = [];
		while ( $pending ) {
			$target = array_shift( $pending );
			if ( isset( $visited[$target->getDBkey()] ) ) { continue; }
			if ( count( $visited ) >= self::MAX_ALIASES ) { $complete = false; break; }
			$visited[$target->getDBkey()] = true;
			$rows = $db->newSelectQueryBuilder()->select( [ 'page_title' ] )
				->from( 'redirect' )->join( 'page', null, 'page_id = rd_from' )
				->where( [ 'page_namespace' => NS_MAIN, 'rd_namespace' => NS_MAIN,
					'rd_title' => $target->getDBkey(), 'rd_interwiki' => [ '', null ] ] )
				->orderBy( 'page_id', 'DESC' )->limit( self::MAX_ALIASES + 1 )->caller( __METHOD__ )->fetchResultSet();
			foreach ( $rows as $row ) {
				$title = $this->title( $row->page_title );
				if ( !$title || isset( $targets[$title->getDBkey()] ) ) { continue; }
				if ( count( $targets ) >= self::MAX_ALIASES ) { $complete = false; break; }
				$targets[$title->getDBkey()] = $title;
				$pending[] = $title;
			}
		}
		$npcTargets = $targets;
		// A finite collection is another authored route to this item. Candidate
		// discovery stays bounded; current public membership is rechecked in find().
		$collectionCandidates = [];
		$pendingCollections = array_map( static fn ( $title ) => [ $title, 0 ], array_values( $targets ) );
		$checkedCollections = [];
		$acceptedCollections = 0;
		while ( $pendingCollections ) {
			[ $target, $depth ] = array_shift( $pendingCollections );
			if ( $depth >= 8 ) { $complete = false; continue; }
			foreach ( [ 'pagelinks' => 'pl', 'templatelinks' => 'tl' ] as $table => $prefix ) {
				$rows = $db->newSelectQueryBuilder()->select( [ 'page_title' ] )->distinct()
					->from( $table )->join( 'page', null, "page_id = {$table}.{$prefix}_from" )
					->where( array_merge( [ 'page_namespace' => NS_MAIN, 'page_is_redirect' => 0 ], $migration->getLinksConditions( $table, $target ) ) )
					->orderBy( 'page_id', 'DESC' )->limit( self::MAX_CANDIDATES + 1 )->caller( __METHOD__ )->fetchResultSet();
				$this->collect( $rows, $collectionCandidates, $complete );
			}
			foreach ( $collectionCandidates as $key => $name ) {
				if ( isset( $checkedCollections[$key] ) ) { continue; }
				$checkedCollections[$key] = true;
				$title = $this->title( $name );
				$record = $title ? $this->publicText( $title ) : null;
				if ( !$record || EqlNpcLootWikitext::authoredPageKind( $record['text'] ) !== 'other' ) { continue; }
				EqlNpcLootWikitext::finiteCollectionItems( $record['text'], $finite );
				if ( !$finite ) { continue; }
				$members = $this->collectionMembers( $title );
				if ( $members['complete'] && array_intersect( array_keys( $targets ), array_map( static fn ( $member ) => $member->getDBkey(), $members['items'] ) ) ) {
					if ( ++$acceptedCollections > self::MAX_COLLECTIONS ) { $complete = false; continue; }
					$npcTargets[$title->getDBkey()] = $title;
					$pendingCollections[] = [ $title, $depth + 1 ];
					$aliases = $db->newSelectQueryBuilder()->select( [ 'page_title' ] )->from( 'redirect' )->join( 'page', null, 'page_id = rd_from' )
						->where( [ 'page_namespace' => NS_MAIN, 'rd_namespace' => NS_MAIN, 'rd_title' => $title->getDBkey(), 'rd_interwiki' => [ '', null ] ] )
						->orderBy( 'page_id', 'DESC' )->limit( self::MAX_ALIASES + 1 )->caller( __METHOD__ )->fetchResultSet();
					foreach ( $aliases as $alias ) {
						if ( count( $npcTargets ) >= count( $targets ) + self::MAX_COLLECTIONS + self::MAX_ALIASES ) { $complete = false; break; }
						$aliasTitle = $this->title( $alias->page_title );
						if ( $aliasTitle ) { $npcTargets[$aliasTitle->getDBkey()] = $aliasTitle; }
					}
				}
			}
		}

		foreach ( [ 'pagelinks' => 'pl', 'templatelinks' => 'tl' ] as $table => $prefix ) {
			foreach ( $npcTargets as $target ) {
				$rows = $db->newSelectQueryBuilder()->select( [ 'page_title' ] )->distinct()
					->from( $table )->join( 'page', null, "page_id = {$table}.{$prefix}_from" )
					->join( 'categorylinks', 'npc_category', 'npc_category.cl_from = page_id' )
					->where( array_merge( [ 'page_namespace' => NS_MAIN, 'page_is_redirect' => 0 ],
						$migration->getLinksConditions( $table, $target ), $npcConditions ) )
					->orderBy( 'page_id', 'DESC' )->limit( self::MAX_CANDIDATES + 1 )->caller( __METHOD__ )->fetchResultSet();
				$this->collect( $rows, $titles, $complete );
			}
			// Missing case-variant links may not target the canonical linktarget.
			// A new item with no source has no known zone: constrain that fallback
			// by NPC category and literal item names rather than scanning documents.
			$linkInfo = $migration->getQueryInfo( $table );
			[ $namespaceField, $titleField ] = $migration->getTitleFields( $table );
			if ( !preg_match( '/^(?:lt|pl|tl)_namespace$/', $namespaceField ) || !preg_match( '/^(?:lt|pl|tl)_title$/', $titleField ) ) {
				throw new RuntimeException( 'Unsupported recorded-source link query shape.' );
			}
			$namespaceField = ( str_starts_with( $namespaceField, 'lt_' ) ? 'linktarget.' : $table . '.' ) . $namespaceField;
			$titleField = ( str_starts_with( $titleField, 'lt_' ) ? 'linktarget.' : $table . '.' ) . $titleField;
			$variants = [];
			foreach ( $targets as $target ) {
				$name = str_replace( [ '`', '’', '‘' ], "'", $target->getDBkey() );
				foreach ( [ $target->getDBkey(), $name, str_replace( "'", '`', $name ) ] as $variant ) {
					$variants[function_exists( 'mb_strtolower' ) ? mb_strtolower( $variant, 'UTF-8' ) : strtolower( $variant )] = true;
				}
			}
			if ( !$variants ) { continue; }
			// MySQL link titles are binary; convert to Unicode before LOWER.
			$caseCondition = 'LOWER(CAST(' . $titleField . ' AS CHAR CHARACTER SET utf8mb4)) IN (' .
				implode( ',', array_map( [ $db, 'addQuotes' ], array_keys( $variants ) ) ) . ')';
			foreach ( $zones ?: [ '' ] as $zone ) {
				$zoneConditions = [];
				$query = $db->newSelectQueryBuilder()->select( [ 'page_title', 'page_id' ] )->distinct();
				if ( $zone !== '' ) {
					$category = $this->services->getTitleFactory()->makeTitleSafe( NS_CATEGORY, str_replace( ' ', '_', $zone ) );
					if ( !$category ) { continue; }
					$zoneConditions = $this->categoryConditions( $migration, $category, 'categorylinks' );
					$query->from( 'categorylinks' )->join( 'page', null, 'page_id = categorylinks.cl_from' )
						->join( $table, null, "{$table}.{$prefix}_from = page_id" );
				} else {
					$query->from( $table )->join( 'page', null, "page_id = {$table}.{$prefix}_from" );
				}
				$query->join( 'categorylinks', 'npc_category', 'npc_category.cl_from = page_id' );
				foreach ( $linkInfo['joins'] as $join => [ $type, $conditions ] ) {
					if ( $type !== 'JOIN' || $join !== 'linktarget' ) { throw new RuntimeException( 'Unsupported recorded-source link join.' ); }
					$query->join( $join, null, $conditions );
				}
				$rows = $query->where( array_merge( [ 'page_namespace' => NS_MAIN, 'page_is_redirect' => 0,
					$namespaceField => NS_MAIN, $caseCondition ],
					$zoneConditions, $npcConditions ) )
					->orderBy( 'page_id', 'DESC' )->limit( self::MAX_CANDIDATES + 1 )->setMaxExecutionTime( 1000 )
					->caller( __METHOD__ )->fetchResultSet();
				$this->collect( $rows, $titles, $complete );
			}
		}
		return [ 'titles' => array_values( $titles ), 'aliases' => array_map(
			static fn ( $title ) => $title->getPrefixedText(), array_values( $targets ) ), 'complete' => $complete ];
	}

	private function categoryConditions( $migration, Title $category, string $alias ): array {
		$conditions = [];
		foreach ( $migration->getLinksConditions( 'categorylinks', $category ) as $field => $value ) {
			if ( is_int( $field ) ) { $conditions[] = $value; }
			elseif ( preg_match( '/^cl_[a-z_]+$/', $field ) ) { $conditions[$alias . '.' . $field] = $value; }
			else { throw new RuntimeException( 'Unsupported recorded-source category condition.' ); }
		}
		return $conditions;
	}

	private function collect( iterable $rows, array &$titles, bool &$complete ): void {
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ++$count > self::MAX_CANDIDATES ) { $complete = false; break; }
			$title = $this->title( $row->page_title );
			if ( !$title ) { continue; }
			$key = $title->getDBkey();
			if ( !isset( $titles[$key] ) && count( $titles ) >= self::MAX_CANDIDATES ) { $complete = false; continue; }
			$titles[$key] = $title->getPrefixedText();
		}
	}

	private function matchesItem( string $name, Title $itemTitle, array $allowed ): bool {
		if ( !isset( $allowed[EqlNpcLootWikitext::identity( $name )] ) ) { return false; }
		$title = $this->title( $name );
		if ( !$title ) { return false; }
		// Forgiveness is only for missing spellings; two real case-distinct
		// item pages remain distinct, including through redirect chains.
		if ( !$title->exists() ) { return true; }
		if ( !$title->isRedirect() ) { return $title->getDBkey() === $itemTitle->getDBkey(); }
		$canonical = $this->canonical( $title );
		return $canonical && $canonical->getDBkey() === $itemTitle->getDBkey();
	}

	private function alreadyDeclared( Title $npcTitle, array $source, array $existing ): bool {
		foreach ( $existing as $declared ) {
			if ( !is_array( $declared ) || !is_string( $declared['npc'] ?? null ) ||
				!is_string( $declared['zone'] ?? '' ) ||
				( ( $declared['zone'] ?? '' ) !== '' && $source['zone'] !== '' &&
					$this->zoneName( $declared['zone'] ) !== $this->zoneName( $source['zone'] ) ) ||
				( ( $declared['instance'] ?? 'normal' ) === 'raid' ) !== ( ( $source['instance'] ?? 'normal' ) === 'raid' ) ) { continue; }
			$title = $this->title( $declared['npc'] );
			if ( !$title ) { continue; }
			if ( !$title->exists() ) {
				if ( EqlNpcLootWikitext::identity( $declared['npc'] ) === EqlNpcLootWikitext::identity( $npcTitle->getPrefixedText() ) ) { return true; }
				continue;
			}
			$canonical = $this->canonical( $title );
			if ( $canonical && $canonical->getDBkey() === $npcTitle->getDBkey() ) { return true; }
		}
		return false;
	}

	/** Existing public redirect chains prove zone equivalence; missing names stay literal. */
	private function zoneName( string $name ): string {
		if ( $name === '' ) { return ''; }
		if ( isset( $this->zoneAliases[$name] ) ) { return $this->zoneAliases[$name]; }
		$title = $this->title( $name );
		$canonical = $title && $title->exists() ? $this->canonical( $title ) : null;
		$resolved = $canonical ? $canonical->getPrefixedText() : ( $title ? $title->getPrefixedText() : $name );
		if ( count( $this->zoneAliases ) < 200 ) { $this->zoneAliases[$name] = $resolved; }
		return $resolved;
	}

	private function canonical( Title $title ): ?Title {
		$key = $title->getDBkey();
		if ( array_key_exists( $key, $this->resolveMemo ) ) { return $this->resolveMemo[$key]; }
		$seen = [];
		for ( $depth = 0; $depth < 8; $depth++ ) {
			if ( !$this->validTitle( $title ) || isset( $seen[$title->getDBkey()] ) || !$this->publicText( $title ) ) {
				return $this->resolveMemo[$key] = null;
			}
			$seen[$title->getDBkey()] = true;
			$target = $this->services->getRedirectLookup()->getRedirectTarget( $title );
			if ( !$target ) { return $this->resolveMemo[$key] = $title; }
			$title = $this->services->getTitleFactory()->newFromLinkTarget( $target );
		}
		$this->incomplete( 'Some source redirects could not be resolved; additional sources may exist.' );
		return $this->resolveMemo[$key] = null;
	}

	private function publicText( Title $title ): ?array {
		$key = $title->getDBkey();
		if ( array_key_exists( $key, $this->publicMemo ) ) { return $this->publicMemo[$key]; }
		if ( count( $this->publicMemo ) >= self::MAX_PUBLIC_READS ) {
			$this->incomplete( 'Recorded-source lookup reached its public-read limit; additional sources may exist.' );
			return null;
		}
		if ( !$this->validTitle( $title ) || !$title->exists() || !$this->canRead( $title ) ) {
			$this->incomplete( 'Some source pages were not publicly readable; additional sources may exist.' );
			return $this->publicMemo[$key] = null;
		}
		$revision = $this->services->getRevisionLookup()->getRevisionByTitle( $title );
		$content = $revision ? $revision->getContent( 'main', RevisionRecord::FOR_PUBLIC ) : null;
		if ( !$content || $content->getModel() !== 'wikitext' || !method_exists( $content, 'getText' ) ) {
			$this->incomplete( 'Some source content was not publicly readable; additional sources may exist.' );
			return $this->publicMemo[$key] = null;
		}
		$text = $content->getText();
		if ( strlen( $text ) > self::MAX_PAGE_BYTES ) {
			$this->incomplete( 'Some source pages exceeded the lookup limit; additional sources may exist.' );
			return $this->publicMemo[$key] = null;
		}
		$this->bytesRead += strlen( $text );
		if ( $this->bytesRead > self::MAX_TOTAL_BYTES ) {
			$this->incomplete( 'Recorded-source lookup reached its content limit; additional sources may exist.' );
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
		if ( count( $values ) > $limit ) { $this->incomplete( 'Recorded-source lookup reached a page-context limit; additional sources may exist.' ); }
		return array_values( array_filter( array_slice( $values, 0, $limit ),
			static fn ( $value ) => is_string( $value ) && $value !== '' && strlen( $value ) <= 240 ) );
	}

	private function incomplete( string $warning ): void {
		$this->complete = false;
		$this->warnings[$warning] = $warning;
	}

	private function result( array $sources = [] ): array {
		return [ 'sources' => $sources, 'zoneAliases' => $this->zoneAliases, 'complete' => $this->complete, 'warnings' => array_values( $this->warnings ) ];
	}
}
