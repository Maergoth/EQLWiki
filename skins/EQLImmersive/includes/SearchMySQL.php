<?php

namespace EQLImmersive;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleParser;
use SearchSuggestionSet;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\RawSQLExpression;
use Wikimedia\Rdbms\SelectQueryBuilder;

/** Native full-text search with indexed, case-insensitive title prefixes. */
class SearchMySQL extends \SearchMySQL {

	protected function completionSearchBackend( $search ) {
		$namespaces = $this->namespaces ?: [ NS_MAIN ];
		if ( in_array( NS_SPECIAL, $namespaces, true ) || trim( $search ) === '' ) {
			return parent::completionSearchBackend( $search );
		}

		$search = preg_replace( TitleParser::getTitleInvalidRegex(), '', trim( $search ) );
		$query = $this->titlePrefixQuery( $search, $namespaces );
		if ( !$query ) {
			return SearchSuggestionSet::emptySuggestionSet();
		}
		$rows = $this->selectPrefixPage( $query )->fetchResultSet();
		$titles = iterator_to_array( MediaWikiServices::getInstance()->getTitleFactory()->newTitleArrayFromResult( $rows ) );
		return SearchSuggestionSet::fromTitles( $titles );
	}

	/**
	 * A submitted partial name must expose the same title links as autocomplete.
	 * Choose one result family from its total, so paging cannot switch between
	 * prefix and native token results. Body/full-text searches remain upstream.
	 */
	protected function doSearchTitleInDB( $term ) {
		if ( !$this->isLiteralPrefixQuery( $term ) || $this->namespaces === null ||
			in_array( NS_SPECIAL, $this->namespaces, true )
		) {
			return parent::doSearchTitleInDB( $term );
		}
		$query = $this->titlePrefixQuery( trim( $term ), $this->namespaces ?: [ NS_MAIN ], true );
		if ( !$query ) {
			return parent::doSearchTitleInDB( $term );
		}
		$this->queryFeatures( $query );
		$countQuery = clone $query;
		$total = (int)$countQuery->select( 'COUNT(*)' )->caller( __METHOD__ )->fetchField();
		if ( !$total ) {
			return parent::doSearchTitleInDB( $term );
		}
		$rows = $this->selectPrefixPage( $query )->fetchResultSet();
		return new \SqlSearchResultSet( $rows,
			[ preg_quote( str_replace( '_', ' ', trim( $term ) ), '/' ) ], $total );
	}

	/** Keep quoted phrases, boolean operators, wildcards and short queries native. */
	private function isLiteralPrefixQuery( string $term ): bool {
		$term = trim( $term );
		return mb_strlen( $term, 'UTF-8' ) >= 3 && mb_strlen( $term, 'UTF-8' ) <= 255 &&
			preg_match( '/^[\p{L}\p{N}][\p{L}\p{N} _]*$/uD', $term ) === 1 &&
			!preg_match( '/(?:^|\s)(?:AND|OR|NOT)(?:\s|$)/i', $term );
	}

	/** Up to sixteen index ranges cover the first four ASCII characters. */
	private function prefixIndexRanges( string $prefix ): array {
		if ( !preg_match( '/^[a-z0-9_]{1,4}/D', $prefix, $match ) ) {
			return [];
		}
		$ranges = [ '' ];
		foreach ( str_split( $match[0] ) as $character ) {
			$next = [];
			foreach ( $ranges as $range ) {
				$next[] = $range . $character;
				if ( $character >= 'a' && $character <= 'z' ) {
					$next[] = $range . strtoupper( $character );
				}
			}
			$ranges = $next;
		}
		return $ranges;
	}

	private function titlePrefixQuery( string $search, array $namespaces, bool $requireIndex = false ): ?SelectQueryBuilder {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$conditions = [];
		foreach ( $namespaces as $namespace ) {
			$title = Title::makeTitleSafe( $namespace, $search );
			if ( !$title ) {
				continue;
			}
			$prefix = mb_strtolower( $title->getDBkey(), 'UTF-8' );
			$condition = $dbr->andExpr( [
				$dbr->expr( 'page_namespace', '=', $namespace ),
				// The expression is constant SQL; buildLike quotes and escapes all input.
				new RawSQLExpression( 'LOWER(CONVERT(page_title USING utf8mb4)) COLLATE utf8mb4_bin' .
					$dbr->buildLike( $prefix, $dbr->anyString() ) ),
			] );
			// Each range can use page_name_title(page_namespace,page_title).
			// The full folded predicate still validates later words and characters.
			$ranges = $this->prefixIndexRanges( $prefix );
			if ( $ranges ) {
				$condition = $condition->andExpr( $dbr->orExpr( array_map(
					static fn ( $range ) => $dbr->expr( 'page_title', IExpression::LIKE,
						new LikeValue( $range, $dbr->anyString() ) ), $ranges
				) ) );
			} elseif ( $requireIndex ) {
				// Do not introduce an unindexed page scan for submitted searches.
				return null;
			}
			$conditions[] = $condition;
		}
		if ( !$conditions ) {
			return null;
		}
		return $dbr->newSelectQueryBuilder()->from( 'page' )
			->where( $dbr->orExpr( $conditions ) )->caller( __METHOD__ );
	}

	private function selectPrefixPage( SelectQueryBuilder $query ): SelectQueryBuilder {
		return $query
			->select( [ 'page_id', 'page_namespace', 'page_title' ] )
			->orderBy( [ 'LOWER(CONVERT(page_title USING utf8mb4))', 'page_title', 'page_namespace' ] )
			->limit( $this->limit )
			->offset( $this->offset )
			->caller( __METHOD__ );
	}
}
