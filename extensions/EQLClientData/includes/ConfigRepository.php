<?php

declare( strict_types = 1 );

namespace EQLClientData;

use MediaWiki\Config\Config;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageReference;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Throwable;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Revision-aware configuration and page-era metadata repository.
 *
 * Configuration pages are cached in WANObjectCache and invalidated immediately
 * through check keys when those pages are edited, moved, or deleted.
 *
 * Linked-page era statuses are cached per page_touched value and PageEra
 * revision. A page edit or link-table refresh naturally produces a new cache
 * key without requiring a global purge.
 */
final class ConfigRepository {
	private const CONFIG_VERIFIED = 'verified-pages';
	private const CONFIG_PAGE_ERA = 'page-era';
	private const CONFIG_SPELL_OVERRIDES = 'spell-overrides';

	private const FALLBACK_OUT_ERA_KEYS = [
		'kunark',
		'velious',
		'luclin',
		'chardok',
		'chardokrevamp',
		'holevp',
		'temple',
		'warrens',
		'warrensfearhatererevamp',
		'epics',
		'epicquests',
		'unknown'
	];

	private const OUT_ERA_CATEGORY_ALIASES = [
		'kunark' => [ 'kunark era', 'kunark' ],
		'velious' => [ 'velious era', 'velious' ],
		'luclin' => [ 'luclin era', 'luclin' ],
		'chardok' => [ 'chardok era', 'chardok' ],
		'chardokrevamp' => [
			'chardok revamp era',
			'chardok revamp',
			'chardokrevamp'
		],
		'holevp' => [
			'hole vp era',
			'hole/vp era',
			'holevp'
		],
		'temple' => [ 'temple era', 'temple' ],
		'warrens' => [ 'warrens era', 'warrens' ],
		'warrensfearhatererevamp' => [
			'warrens fear hate revamp era',
			'warrens/fear/hate revamp era',
			'warrensfearhatererevamp'
		],
		'epics' => [ 'epics era', 'epics', 'epic era' ],
		'epicquests' => [
			'epic quests era',
			'epic quests',
			'epicquests'
		],
		'unknown' => [ 'unknown era', 'unknown' ]
	];

	public function __construct(
		private readonly RevisionLookup $revisionLookup,
		private readonly TitleFactory $titleFactory,
		private readonly WANObjectCache $wanCache,
		private readonly IConnectionProvider $dbProvider,
		private readonly Config $config
	) {
	}

	/**
	 * @return array{revision:int,pages:array<string,bool>,status:string}
	 */
	public function getVerifiedPages(): array {
		/** @var array{revision:int,pages:array<string,bool>,status:string} */
		return $this->getCachedConfig(
			self::CONFIG_VERIFIED,
			(string)$this->config->get( 'EQLClientDataVerifiedPagesTitle' ),
			function ( string $content ): array {
				$pages = [];

				foreach ( preg_split( '/\R/u', $content ) ?: [] as $line ) {
					$title = $this->normalizePageName( $line );

					if ( $title === '' || str_starts_with( $title, '#' ) ) {
						continue;
					}

					$pages[$title] = true;
				}

				return [
					'pages' => $pages,
					'status' => 'ok'
				];
			},
			[
				'pages' => [],
				'status' => 'missing'
			]
		);
	}

	/**
	 * @return array{
	 *   revision:int,
	 *   outKeys:string[],
	 *   categoryDbKeys:string[],
	 *   status:string
	 * }
	 */
	public function getEraConfig(): array {
		/** @var array{
		 *   revision:int,
		 *   outKeys:string[],
		 *   categoryDbKeys:string[],
		 *   status:string
		 * }
		 */
		return $this->getCachedConfig(
			self::CONFIG_PAGE_ERA,
			(string)$this->config->get( 'EQLClientDataPageEraTitle' ),
			function ( string $content ): array {
				$outKeys = [];
				$pattern = '/\|\s*([a-zA-Z0-9_ -]+)\s*=\s*out\b/i';

				if ( preg_match_all( $pattern, $content, $matches ) ) {
					foreach ( $matches[1] as $match ) {
						$key = strtolower(
							preg_replace(
								'/[\s_-]+/',
								'',
								trim( (string)$match )
							) ?? ''
						);

						if ( $key !== '' ) {
							$outKeys[$key] = true;
						}
					}
				}

				if ( !$outKeys ) {
					$outKeys = array_fill_keys(
						self::FALLBACK_OUT_ERA_KEYS,
						true
					);
				}

				$keys = array_keys( $outKeys );

				return [
					'outKeys' => $keys,
					'categoryDbKeys' => $this->buildEraCategoryDbKeys( $keys ),
					'status' => 'ok'
				];
			},
			[
				'outKeys' => self::FALLBACK_OUT_ERA_KEYS,
				'categoryDbKeys' => $this->buildEraCategoryDbKeys(
					self::FALLBACK_OUT_ERA_KEYS
				),
				'status' => 'missing'
			]
		);
	}

	/**
	 * @return array{revision:int,overrides:array<string,string>,status:string}
	 */
	public function getSpellOverrides(): array {
		/** @var array{revision:int,overrides:array<string,string>,status:string} */
		return $this->getCachedConfig(
			self::CONFIG_SPELL_OVERRIDES,
			(string)$this->config->get( 'EQLClientDataSpellOverridesTitle' ),
			function ( string $content ): array {
				$overrides = [];
				$pattern = '/<pre\b[^>]*\bid\s*=\s*["\']?spell-level-slider-overrides["\']?[^>]*>([\s\S]*?)<\/pre\s*>/i';

				if ( !preg_match( $pattern, $content, $match ) ) {
					return [
						'overrides' => [],
						'status' => trim( $content ) !== '' ? 'invalid' : 'missing'
					];
				}

				$block = preg_replace(
					'/<!--[\s\S]*?-->/',
					'',
					(string)$match[1]
				) ?? '';

				foreach ( preg_split( '/\R/u', $block ) ?: [] as $rawLine ) {
					$line = trim( (string)$rawLine );

					if ( $line === '' || str_starts_with( $line, '#' ) ) {
						continue;
					}

					$separator = strpos( $line, '=' );

					if ( $separator === false || $separator < 1 ) {
						continue;
					}

					$page = $this->normalizeSpellPageTitle(
						substr( $line, 0, $separator )
					);
					$category = $this->normalizeSpellCategory(
						substr( $line, $separator + 1 )
					);

					if ( $page !== '' && $category !== '' ) {
						$overrides[$page] = $category;
					}
				}

				return [
					'overrides' => $overrides,
					'status' => 'ok'
				];
			},
			[
				'overrides' => [],
				'status' => 'missing'
			]
		);
	}

	/**
	 * @param array{revision:int,pages:array<string,bool>,status:string}|null $verified
	 */
	public function isPageVerified( Title $title, ?array $verified = null ): bool {
		$verified ??= $this->getVerifiedPages();
		$key = $this->normalizePageName( $title->getPrefixedDBkey() );

		return isset( $verified['pages'][$key] );
	}

	/**
	 * Return era status for all supplied titles using one page-table query,
	 * one categorylinks query for cache misses, and WANObjectCache per page.
	 *
	 * @param Title[] $titles
	 * @return array<string,array{
	 *   title:string,
	 *   outOfEra:bool,
	 *   missing:bool,
	 *   touched:?string
	 * }>
	 */
	public function getEraStatuses( array $titles ): array {
		$unique = [];

		foreach ( $titles as $title ) {
			if ( !$title instanceof Title || $title->isExternal() ) {
				continue;
			}

			$unique[$title->getPrefixedDBkey()] = $title;
		}

		$result = [];

		foreach ( $unique as $key => $title ) {
			$result[$key] = [
				'title' => $key,
				'outOfEra' => false,
				'missing' => true,
				'touched' => null
			];
		}

		if ( !$unique ) {
			return $result;
		}

		$dbr = $this->dbProvider->getReplicaDatabase();
		$conditions = [];
		$titlesByNamespace = [];

		foreach ( $unique as $title ) {
			$titlesByNamespace[$title->getNamespace()][] = $title->getDBkey();
		}

		foreach ( $titlesByNamespace as $namespace => $dbKeys ) {
			$conditions[] = $dbr->andExpr( [
				'page_namespace' => (int)$namespace,
				'page_title' => array_values( array_unique( $dbKeys ) )
			] );
		}

		$rows = $dbr->newSelectQueryBuilder()
			->select( [
				'page_id',
				'page_namespace',
				'page_title',
				'page_touched'
			] )
			->from( 'page' )
			->where( $dbr->orExpr( $conditions ) )
			->caller( __METHOD__ )
			->fetchResultSet();

		$eraConfig = $this->getEraConfig();
		$eraRevision = (int)$eraConfig['revision'];
		$outCategoryNames = array_fill_keys(
			$this->buildNormalizedEraCategoryNames( $eraConfig['outKeys'] ),
			true
		);
		$rowsByCacheKey = [];
		$cacheKeys = [];

		foreach ( $rows as $row ) {
			$title = $this->titleFactory->newFromRow( $row );
			$titleKey = $title->getPrefixedDBkey();
			$cacheKey = $this->wanCache->makeKey(
				'eql-client-data',
				'era-status-v2',
				(string)$row->page_id,
				(string)$row->page_touched,
				(string)$eraRevision
			);

			$result[$titleKey] = [
				'title' => $titleKey,
				'outOfEra' => false,
				'missing' => false,
				'touched' => (string)$row->page_touched
			];

			$cacheKeys[$titleKey] = $cacheKey;
			$rowsByCacheKey[$cacheKey] = [
				'pageId' => (int)$row->page_id,
				'titleKey' => $titleKey
			];
		}

		$cached = $cacheKeys
			? $this->wanCache->getMulti( array_values( $cacheKeys ) )
			: [];
		$missingPageIds = [];
		$missingCacheKeys = [];

		foreach ( $rowsByCacheKey as $cacheKey => $rowInfo ) {
			if ( array_key_exists( $cacheKey, $cached ) ) {
				$result[$rowInfo['titleKey']]['outOfEra'] = (bool)$cached[$cacheKey];
				continue;
			}

			$missingPageIds[] = $rowInfo['pageId'];
			$missingCacheKeys[$rowInfo['pageId']] = $cacheKey;
		}

		$outOfEraByPageId = [];

		if ( $missingPageIds && $outCategoryNames ) {
			/*
			 * Preserve the exact semantics of the original browser implementation:
			 * fetch the categories for all requested pages in one query, normalize
			 * each category name in PHP, and compare against the same alias set the
			 * old JavaScript used.
			 *
			 * Do not filter lt_title in SQL. MediaWiki stores link targets as binary
			 * DB keys; matching after normalization avoids subtle differences in
			 * spaces/underscores and historical category aliases while retaining one
			 * batched categorylinks query.
			 */
			$categoryRows = $dbr->newSelectQueryBuilder()
				->select( [ 'cl_from', 'lt_title' ] )
				->from( 'categorylinks' )
				->join( 'linktarget', null, 'cl_target_id = lt_id' )
				->where( [
					'cl_from' => $missingPageIds,
					'lt_namespace' => NS_CATEGORY
				] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $categoryRows as $categoryRow ) {
				$normalized = $this->normalizeEraCategoryName(
					(string)$categoryRow->lt_title
				);

				if ( isset( $outCategoryNames[$normalized] ) ) {
					$outOfEraByPageId[(int)$categoryRow->cl_from] = true;
				}
			}
		}

		$setOptions = Database::getCacheSetOptions( $dbr );

		foreach ( $missingPageIds as $pageId ) {
			$isOutOfEra = isset( $outOfEraByPageId[$pageId] );
			$cacheKey = $missingCacheKeys[$pageId];
			$titleKey = $rowsByCacheKey[$cacheKey]['titleKey'];

			$result[$titleKey]['outOfEra'] = $isOutOfEra;

			$this->wanCache->set(
				$cacheKey,
				$isOutOfEra,
				WANObjectCache::TTL_DAY,
				$setOptions
			);
		}

		return $result;
	}

	/**
	 * Immediately invalidate any cached configuration sourced from this page.
	 */
	public function invalidateIfConfigPage( LinkTarget|PageReference $page ): void {
		$pageNamespace = $page->getNamespace();
		$pageDbKey = $page->getDBkey();

		foreach ( $this->getConfigTitleMap() as $configName => $title ) {
			if (
				$title->getNamespace() === $pageNamespace &&
				$title->getDBkey() === $pageDbKey
			) {
				$this->wanCache->touchCheckKey(
					$this->getConfigCheckKey( $configName )
				);
			}
		}
	}

	/**
	 * @param callable(string):array $parser
	 * @param array<string,mixed> $fallback
	 * @return array<string,mixed>
	 */
	private function getCachedConfig(
		string $configName,
		string $titleText,
		callable $parser,
		array $fallback
	): array {
		$cacheKey = $this->wanCache->makeKey(
			'eql-client-data',
			'config',
			$configName
		);
		$checkKey = $this->getConfigCheckKey( $configName );

		/** @var array<string,mixed> */
		return $this->wanCache->getWithSetCallback(
			$cacheKey,
			WANObjectCache::TTL_DAY,
			function ( $oldValue, &$ttl, &$setOptions ) use (
				$titleText,
				$parser,
				$fallback
			): array {
				$title = $this->titleFactory->newFromText( $titleText );

				if ( !$title ) {
					return [ 'revision' => 0 ] + $fallback;
				}

				$revision = $this->revisionLookup->getRevisionByTitle(
					$title,
					0,
					IDBAccessObject::READ_LATEST
				);

				if ( !$revision ) {
					return [ 'revision' => 0 ] + $fallback;
				}

				$content = $revision->getContent( SlotRecord::MAIN );
				$text = $content ? $content->serialize() : '';

				try {
					$parsed = $parser( $text );
				} catch ( Throwable $error ) {
					$parsed = $fallback;
					$parsed['status'] = 'error';
				}

				return [
					'revision' => (int)$revision->getId()
				] + $parsed;
			},
			[
				'checkKeys' => [ $checkKey ],
				'lockTSE' => 10,
				'pcTTL' => 10
			]
		);
	}

	/** @return array<string,Title> */
	private function getConfigTitleMap(): array {
		$map = [];
		$configTitles = [
			self::CONFIG_VERIFIED => (string)$this->config->get(
				'EQLClientDataVerifiedPagesTitle'
			),
			self::CONFIG_PAGE_ERA => (string)$this->config->get(
				'EQLClientDataPageEraTitle'
			),
			self::CONFIG_SPELL_OVERRIDES => (string)$this->config->get(
				'EQLClientDataSpellOverridesTitle'
			)
		];

		foreach ( $configTitles as $name => $titleText ) {
			$title = $this->titleFactory->newFromText( $titleText );

			if ( $title ) {
				$map[$name] = $title;
			}
		}

		return $map;
	}

	private function getConfigCheckKey( string $configName ): string {
		return $this->wanCache->makeKey(
			'eql-client-data',
			'config-check',
			$configName
		);
	}

	/** @param string[] $outKeys @return string[] */
	private function buildEraCategoryDbKeys( array $outKeys ): array {
		$categoryKeys = [];

		foreach ( $outKeys as $outKey ) {
			$aliases = self::OUT_ERA_CATEGORY_ALIASES[$outKey] ?? [];
			$aliases[] = $outKey . ' era';
			$aliases[] = $outKey;

			foreach ( $aliases as $alias ) {
				$title = $this->titleFactory->newFromText(
					'Category:' . $alias
				);

				if ( $title ) {
					$categoryKeys[$title->getDBkey()] = true;
				}
			}
		}

		return array_keys( $categoryKeys );
	}

	/** @param string[] $outKeys @return string[] */
	private function buildNormalizedEraCategoryNames( array $outKeys ): array {
		$names = [];

		foreach ( $outKeys as $outKey ) {
			$aliases = self::OUT_ERA_CATEGORY_ALIASES[$outKey] ?? [];
			$aliases[] = $outKey . ' era';
			$aliases[] = $outKey;

			foreach ( $aliases as $alias ) {
				$normalized = $this->normalizeEraCategoryName( (string)$alias );

				if ( $normalized !== '' ) {
					$names[$normalized] = true;
				}
			}
		}

		return array_keys( $names );
	}

	/**
	 * Match the normalization used by the original era-filter.js:
	 * underscores become spaces, whitespace collapses, and comparison is
	 * case-insensitive. A Category: prefix is accepted for diagnostics even
	 * though linktarget.lt_title normally omits it.
	 */
	private function normalizeEraCategoryName( string $value ): string {
		$value = preg_replace( '/^Category:/i', '', trim( $value ) ) ?? '';
		$value = str_replace( '_', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value ) ?? '';

		return strtolower( trim( $value ) );
	}

	private function normalizePageName( string $value ): string {
		$value = trim( $value );

		if ( preg_match(
			'/^\[\[\s*([^|\]#]+)(?:#[^|\]]*)?(?:\|[^\]]*)?\s*\]\]$/',
			$value,
			$match
		) ) {
			$value = (string)$match[1];
		}

		return str_replace( ' ', '_', ltrim( trim( $value ), ':' ) );
	}


	private function normalizeSpellPageTitle( string $value ): string {
		$value = trim( $value );

		if ( preg_match(
			'/^\[\[\s*([^|\]#]+)(?:#[^|\]]*)?(?:\|[^\]]*)?\s*\]\]$/',
			$value,
			$match
		) ) {
			$value = (string)$match[1];
		}

		$value = str_replace( '_', ' ', ltrim( trim( $value ), ':' ) );
		$value = preg_replace( '/\s+/', ' ', $value ) ?? '';

		return strtolower( trim( $value ) );
	}

	private function normalizeSpellCategory( string $value ): string {
		$key = strtolower( trim( $value ) );
		$key = preg_replace( '/[\/-]+/', '_', $key ) ?? '';
		$key = preg_replace( '/\s+/', '_', $key ) ?? '';
		$key = preg_replace( '/_+/', '_', $key ) ?? '';
		$key = trim( $key, '_' );

		$aliases = [
			'nuke' => 'nuke_lifetap',
			'lifetap' => 'nuke_lifetap',
			'nuke_lifetap' => 'nuke_lifetap',
			'dot' => 'dot',
			'damage_over_time' => 'dot',
			'heal' => 'heal',
			'hot' => 'hot',
			'heal_over_time' => 'hot',
			'debuff' => 'debuff',
			'charm' => 'charm_mez',
			'mez' => 'charm_mez',
			'mesmerize' => 'charm_mez',
			'charm_mez' => 'charm_mez',
			'buff' => 'buff'
		];

		return $aliases[$key] ?? '';
	}
}
