<?php

namespace EQLImmersive;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleParser;
use SearchSuggestionSet;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\RawSQLExpression;

/** Keep native full-text search, with case-insensitive title autocomplete. */
class SearchMySQL extends \SearchMySQL {

	protected function completionSearchBackend( $search ) {
		$namespaces = $this->namespaces ?: [ NS_MAIN ];
		if ( in_array( NS_SPECIAL, $namespaces, true ) || trim( $search ) === '' ) {
			return parent::completionSearchBackend( $search );
		}

		$search = preg_replace( TitleParser::getTitleInvalidRegex(), '', trim( $search ) );
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
			// Bound ordinary Latin searches with the existing namespace/title index.
			// The full folded prefix still checks every word and escapes SQL wildcards.
			$first = mb_substr( $prefix, 0, 1, 'UTF-8' );
			if ( preg_match( '/^[a-z]$/D', $first ) ) {
				$condition = $condition->andExpr( $dbr->orExpr( [
					$dbr->expr( 'page_title', IExpression::LIKE, new LikeValue( $first, $dbr->anyString() ) ),
					$dbr->expr( 'page_title', IExpression::LIKE, new LikeValue( strtoupper( $first ), $dbr->anyString() ) ),
				] ) );
			}
			$conditions[] = $condition;
		}
		if ( !$conditions ) {
			return SearchSuggestionSet::emptySuggestionSet();
		}
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_namespace', 'page_title' ] )
			->from( 'page' )
			->where( $dbr->orExpr( $conditions ) )
			->orderBy( [ 'LOWER(CONVERT(page_title USING utf8mb4))', 'page_title', 'page_namespace' ] )
			->limit( $this->limit )
			->offset( $this->offset )
			->caller( __METHOD__ )
			->fetchResultSet();
		$titles = iterator_to_array( MediaWikiServices::getInstance()->getTitleFactory()->newTitleArrayFromResult( $rows ) );
		return SearchSuggestionSet::fromTitles( $titles );
	}
}
