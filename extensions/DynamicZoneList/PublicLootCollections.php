<?php
use MediaWiki\Title\Title;

/** Shared bounded public-revision closure; consumers supply their existing read budgets. */
trait EqlPublicLootCollections {
	private array $collectionMemo = [];

	/** @return array{items:array,complete:bool} */
	private function collectionMembers( Title $title, array $seen = [] ): array {
		$key = $title->getDBkey();
		if ( isset( $seen[$key] ) || count( $seen ) >= 8 ) { return [ 'items' => [], 'complete' => false ]; }
		if ( isset( $this->collectionMemo[$key] ) ) { return $this->collectionMemo[$key]; }
		$seen[$key] = true;
		$canonical = $this->canonical( $title );
		$record = $canonical ? $this->publicText( $canonical ) : null;
		if ( !$record ) { return [ 'items' => [], 'complete' => false ]; }
		if ( EqlNpcLootWikitext::authoredPageKind( $record['text'] ) === 'item' ) {
			return [ 'items' => [ $canonical ], 'complete' => true ];
		}
		$names = EqlNpcLootWikitext::finiteCollectionItems( $record['text'], $complete );
		$items = [];
		foreach ( $names as $name ) {
			$member = $this->title( $name );
			$nested = $member ? $this->collectionMembers( $member, $seen ) : [ 'items' => [], 'complete' => false ];
			$complete = $complete && $nested['complete'];
			foreach ( $nested['items'] as $item ) {
				if ( count( $items ) >= 1000 ) { $complete = false; break 2; }
				$items[$item->getDBkey()] = $item;
			}
		}
		return $this->collectionMemo[$key] = [ 'items' => array_values( $items ), 'complete' => $complete && count( $items ) > 0 ];
	}
}
