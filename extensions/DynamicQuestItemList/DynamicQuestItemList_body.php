<?php
/*************************************************

EQL Wiki extension: DynamicQuestItemList

Usage on a wiki page:

{{Special:DynamicQuestItemList}}

Optional category override:

{{Special:DynamicQuestItemList/Quest Items}}

v0.9:
- Initial page renders the filter bar and table shell immediately.
- Rows are loaded through the same SpecialPage in chunks of 500.
- Client JS requests the next chunk as soon as the previous chunk has appended.
- Completed chunk HTML is cached client-side. If all chunks are cached, the
  table restores from cache immediately and skips lazy/network loading.
- Obtained column lists the first two sources per source category, then links
  to the item page with an And more link when additional sources exist.

*************************************************/

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

class DynamicQuestItemList extends SpecialPage {
	private const DEFAULT_CATEGORY = 'Quest Items';
	private const DEFAULT_LIMIT = 500;
	private const MAX_LIMIT = 500;

	public function __construct() {
		parent::__construct( 'DynamicQuestItemList' );
	}

	public function isIncludable() {
		return true;
	}

	private function makeTitle( $namespace, $dbKey ) {
		if ( class_exists( 'MediaWiki\\Title\\Title' ) ) {
			return Title::makeTitle( $namespace, $dbKey );
		}

		return \Title::makeTitle( $namespace, $dbKey );
	}

	private function makeTitleFromText( $text ) {
		if ( class_exists( 'MediaWiki\\Title\\Title' ) ) {
			return Title::newFromText( $text );
		}

		return \Title::newFromText( $text );
	}

	private function htmlAttribute( $value ) {
		return htmlspecialchars( (string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private function jsonResponse( $data ) {
		$this->getOutput()->disable();

		if ( !headers_sent() ) {
			header( 'Content-Type: application/json; charset=UTF-8' );
		}

		echo json_encode( $data );
	}

	private function normalizeCategoryName( $categoryName ) {
		$categoryName = trim( (string)$categoryName );

		if ( $categoryName === '' ) {
			$categoryName = self::DEFAULT_CATEGORY;
		}

		$categoryName = str_replace( '_', ' ', $categoryName );

		if ( strlen( $categoryName ) > 80 ) {
			$categoryName = substr( $categoryName, 0, 80 );
		}

		return $categoryName;
	}

	private function cleanParamValue( $value ) {
		$value = preg_replace( '/<!--.*?-->/s', '', (string)$value );
		return trim( $value );
	}

	private function hasUsefulValue( $value ) {
		$value = trim( preg_replace( '/<!--.*?-->/s', '', (string)$value ) );

		if ( $value === '' ) {
			return false;
		}

		if ( $value === '-' || $value === '?' ) {
			return false;
		}

		return true;
	}

	private function extractBalancedTemplate( $text, $templateName ) {
		if (
			!preg_match(
				'/\{\{\s*' . preg_quote( $templateName, '/' ) . '\b/i',
				$text,
				$match,
				PREG_OFFSET_CAPTURE
			)
		) {
			return '';
		}

		$start = $match[0][1];
		$length = strlen( $text );
		$depth = 0;

		for ( $i = $start; $i < $length - 1; $i++ ) {
			$pair = substr( $text, $i, 2 );

			if ( $pair === '{{' ) {
				$depth++;
				$i++;
				continue;
			}

			if ( $pair === '}}' ) {
				$depth--;
				$i++;

				if ( $depth === 0 ) {
					return substr( $text, $start, $i + 1 - $start );
				}

				continue;
			}
		}

		return '';
	}

	private function stripItempageWrapper( $templateText ) {
		$templateText = preg_replace( '/^\{\{\s*Itempage\b/i', '', $templateText );
		$templateText = preg_replace( '/\s*\}\}\s*$/', '', $templateText );

		return $templateText;
	}

	/*
	 * Extract one Itempage parameter by line boundary.
	 *
	 * This wiki's item pages use one template parameter per line:
	 *
	 * |dropsfrom =
	 * |relatedquests =
	 * |playercrafted =
	 *
	 * Values may contain links, lists, nested templates, recipe markup, and
	 * additional pipes. Splitting on pipe characters is therefore unsafe.
	 */
	private function extractItempageParam( $templateText, $paramNames ) {
		foreach ( $paramNames as $paramName ) {
			$paramPattern = preg_quote( $paramName, '/' );

			if (
				preg_match(
					'/(?:^|\R)[ \t]*\|[ \t]*' . $paramPattern . '[ \t]*=[ \t]*(.*?)(?=\R[ \t]*\|[ \t]*[A-Za-z0-9_ ]+[ \t]*=|\z)/is',
					$templateText,
					$match
				)
			) {
				return $this->cleanParamValue( $match[1] );
			}
		}

		return '';
	}

	private function parseItempageParams( $pageText ) {
		$templateText = $this->extractBalancedTemplate( $pageText, 'Itempage' );

		if ( $templateText === '' ) {
			return array();
		}

		$templateText = $this->stripItempageWrapper( $templateText );

		return array(
			'itemname' => $this->extractItempageParam( $templateText, array( 'itemname', 'item_name' ) ),
			'notes' => $this->extractItempageParam( $templateText, array( 'notes' ) ),
			'dropsfrom' => $this->extractItempageParam( $templateText, array( 'dropsfrom', 'drops_from' ) ),
			'soldby' => $this->extractItempageParam( $templateText, array( 'soldby', 'sold_by' ) ),
			'playercrafted' => $this->extractItempageParam( $templateText, array( 'playercrafted', 'player_crafted' ) ),
			'relatedquests' => $this->extractItempageParam( $templateText, array( 'relatedquests', 'related_quests' ) ),
		);
	}

	private function renderItemLoreInline( $lore ) {
		$lore = trim( (string)$lore );

		if ( $lore === '' ) {
			return '';
		}

		/*
		 * Equivalent visual output to Template:Item Lore, but without adding
		 * [[Category:Lore Items]] to the dynamic list/category page.
		 */
		return '<span style="background: #7d684640;">[[File:Spellicon_U.png|18px]] '
			. '<small style="font-size:71%;color: #d8c49c;">'
			. "'''Item Lore''' : ''" . $lore . "&nbsp;&nbsp;''"
			. '</small></span>';
	}

	private function cleanNotesCell( $notes ) {
		$notes = $this->cleanParamValue( $notes );

		if ( $notes === '' ) {
			return '';
		}

		/*
		 * Omit the "Item Lore Missing" maintenance/template banner from this
		 * summary table. Keep any real notes that follow it.
		 */
		$notes = preg_replace(
			'/\{\{\s*Item[ _]+Lore[ _]+Missing\s*(?:\|[^}\r\n]*)?\s*\}\}/i',
			'',
			$notes
		);

		/*
		 * Convert {{Item Lore|...}} to equivalent formatting. Do not transclude
		 * Template:Item Lore directly; that template adds [[Category:Lore Items]]
		 * and would categorize the dynamic list page.
		 *
		 * Supports spacing variants:
		 *   {{Item Lore|Noresa's Purse}}
		 *   {{Item Lore | 'Blazing Wand' }}
		 *
		 * Requires a real closing }} so the lore text does not keep trailing
		 * braces such as "Noresa's Purse}}".
		 */
		$notes = preg_replace_callback(
			'/\{\{\s*Item[ _]+Lore\s*\|\s*([^{}\r\n]*?)\s*\}\}/i',
			function ( $match ) {
				return $this->renderItemLoreInline( $match[1] );
			},
			$notes
		);

		$notes = preg_replace( '/^(?:\s*<br\s*\/?>\s*)+/i', '', $notes );
		$notes = preg_replace( '/(?:\s*<br\s*\/?>\s*)+$/i', '', $notes );

		return trim( $notes );
	}

	private function normalizeSourceText( $text ) {
		$text = trim( (string)$text );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text, " \t\n\r\0\x0B-–—,;:" );

		return $text;
	}

	private function parseWikiLinkLabel( $text ) {
		$text = trim( (string)$text );

		if ( preg_match( '/\[\[\s*([^|\]]+)\s*\|\s*([^\]]+)\s*\]\]/', $text, $match ) ) {
			return '[[' . trim( $match[1] ) . '|' . trim( $match[2] ) . ']]';
		}

		if ( preg_match( '/\[\[\s*([^\]]+)\s*\]\]/', $text, $match ) ) {
			return '[[' . trim( $match[1] ) . ']]';
		}

		return $this->normalizeSourceText( strip_tags( $text ) );
	}

	private function sourceAlreadyExists( $sources, $source ) {
		$sourceKey = strtolower( preg_replace( '/\s+/', ' ', trim( strip_tags( $source ) ) ) );

		foreach ( $sources as $existing ) {
			$existingKey = strtolower( preg_replace( '/\s+/', ' ', trim( strip_tags( $existing ) ) ) );

			if ( $existingKey === $sourceKey ) {
				return true;
			}
		}

		return false;
	}

	private function addSource( &$sources, $source ) {
		$source = $this->normalizeSourceText( $source );

		if ( $source === '' ) {
			return;
		}

		if ( !$this->sourceAlreadyExists( $sources, $source ) ) {
			$sources[] = $source;
		}
	}

	private function extractBulletSources( $value ) {
		$sources = array();
		$lines = preg_split( "/\r\n|\n|\r/", (string)$value );

		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*\*+\s*(.+?)\s*$/', $line, $match ) ) {
				$this->addSource( $sources, $this->parseWikiLinkLabel( $match[1] ) );
			}
		}

		return $sources;
	}

	private function splitTopLevelPipes( $text ) {
		$parts = array();
		$current = '';
		$linkDepth = 0;
		$templateDepth = 0;
		$length = strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			$pair = substr( $text, $i, 2 );

			if ( $pair === '[[' ) {
				$linkDepth++;
				$current .= $pair;
				$i++;
				continue;
			}

			if ( $pair === ']]' ) {
				$linkDepth = max( 0, $linkDepth - 1 );
				$current .= $pair;
				$i++;
				continue;
			}

			if ( $pair === '{{' ) {
				$templateDepth++;
				$current .= $pair;
				$i++;
				continue;
			}

			if ( $pair === '}}' ) {
				$templateDepth = max( 0, $templateDepth - 1 );
				$current .= $pair;
				$i++;
				continue;
			}

			$char = $text[$i];

			if ( $char === '|' && $linkDepth === 0 && $templateDepth === 0 ) {
				$parts[] = trim( $current );
				$current = '';
				continue;
			}

			$current .= $char;
		}

		$parts[] = trim( $current );

		return $parts;
	}

	private function extractItemWhereSources( $value ) {
		$sources = array();
		$lines = preg_split( "/\r\n|\n|\r/", (string)$value );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( $line === '' || stripos( $line, '{{ItemWhereRow' ) === false ) {
				continue;
			}

			/*
			 * Parse one ItemWhereRow line without treating pipes inside wiki
			 * links as field separators.
			 *
			 * Example:
			 * {{ItemWhereRow | [[Cabilis|East Cabilis]] | [[Klok Ixmid]] | | (659, 156) }}
			 *
			 * A plain regex split on "|" breaks that into:
			 *   [[Cabilis
			 *   East Cabilis]]
			 *
			 * so we split only on top-level pipes outside [[...]] and {{...}}.
			 */
			$inner = preg_replace( '/^\{\{\s*ItemWhereRow\w*\s*\|/i', '', $line );
			$inner = preg_replace( '/\s*\}\}\s*$/', '', $inner );

			$parts = $this->splitTopLevelPipes( $inner );

			if ( count( $parts ) < 2 ) {
				continue;
			}

			$zone = $this->parseWikiLinkLabel( $parts[0] );
			$npc = $this->parseWikiLinkLabel( $parts[1] );

			if ( $npc !== '' && $zone !== '' ) {
				$this->addSource( $sources, $npc . ' in ' . $zone );
			} elseif ( $npc !== '' ) {
				$this->addSource( $sources, $npc );
			} elseif ( $zone !== '' ) {
				$this->addSource( $sources, $zone );
			}
		}

		return $sources;
	}

	private function extractCraftedSources( $value ) {
		$sources = array();
		$lines = preg_split( "/\r\n|\n|\r/", (string)$value );

		foreach ( $lines as $line ) {
			/*
			 * Prefer top-level recipe/tradeskill bullets.
			 * Example:
			 * * [[Poison Making]] (Trivial: 20)
			 */
			if ( preg_match( '/^\s*\*\s*(?!\*)\s*(.+?)\s*$/', $line, $match ) ) {
				$this->addSource( $sources, $this->parseWikiLinkLabel( $match[1] ) );
			}
		}

		if ( !$sources ) {
			$sources = $this->extractBulletSources( $value );
		}

		return $sources;
	}

	private function formatSourceCategory( $label, $sources, $itemLink ) {
		$total = count( $sources );

		if ( $total === 0 ) {
			return '';
		}

		$shown = array_slice( $sources, 0, 2 );
		$text = "'''" . $label . ":''' " . implode( ', ', $shown );

		if ( $total > 2 ) {
			$text .= ', [[' . $itemLink . '|And more]]';
		}

		return $text;
	}

	private function buildObtainedCell( $params, $pageText, $title = null ) {
		$blocks = array();
		$itemLink = $title ? $title->getText() : '';

		if ( isset( $params['dropsfrom'] ) && $this->hasUsefulValue( $params['dropsfrom'] ) ) {
			$dropSources = $this->extractBulletSources( $params['dropsfrom'] );
			$block = $this->formatSourceCategory( 'Dropped', $dropSources, $itemLink );

			if ( $block !== '' ) {
				$blocks[] = $block;
			}
		}

		if ( isset( $params['soldby'] ) && $this->hasUsefulValue( $params['soldby'] ) ) {
			$soldSources = $this->extractItemWhereSources( $params['soldby'] );

			if ( !$soldSources ) {
				$soldSources = $this->extractBulletSources( $params['soldby'] );
			}

			$block = $this->formatSourceCategory( 'Sold', $soldSources, $itemLink );

			if ( $block !== '' ) {
				$blocks[] = $block;
			}
		}

		if ( isset( $params['playercrafted'] ) && $this->hasUsefulValue( $params['playercrafted'] ) ) {
			$craftedSources = $this->extractCraftedSources( $params['playercrafted'] );
			$block = $this->formatSourceCategory( 'Crafted', $craftedSources, $itemLink );

			if ( $block !== '' ) {
				$blocks[] = $block;
			}
		} elseif ( preg_match( '/\[\[\s*Category\s*:\s*Player[ _]+Crafted\s*\]\]/i', $pageText ) ) {
			$blocks[] = "'''Crafted'''";
		}

		return implode( '<br>', $blocks );
	}

	private function pageLooksLikeItemPage( $pageText ) {
		return is_string( $pageText ) && preg_match( '/\{\{\s*Itempage\b/i', $pageText );
	}

	private function getCategoryDbKey( $categoryName ) {
		$categoryTitle = $this->makeTitleFromText( 'Category:' . $categoryName );

		if ( !$categoryTitle ) {
			return '';
		}

		return $categoryTitle->getDBKey();
	}

	private function getPagesInCategory( $db, $categoryName, $limit = null, $offset = 0 ) {
		$categoryDbKey = $this->getCategoryDbKey( $categoryName );

		if ( $categoryDbKey === '' ) {
			return array();
		}

		$tables = array(
			'page',
			'cl' => 'categorylinks',
			'lt' => 'linktarget',
		);

		$fields = array(
			'page_id',
			'page_namespace',
			'page_title',
		);

		$where = array(
			'page_namespace' => NS_MAIN,
			'page_is_redirect' => 0,
			'lt.lt_namespace' => NS_CATEGORY,
			'lt.lt_title' => $categoryDbKey,
		);

		$join = array(
			'cl' => array(
				'INNER JOIN',
				'page_id = cl.cl_from',
			),
			'lt' => array(
				'INNER JOIN',
				'cl.cl_target_id = lt.lt_id',
			),
		);

		$options = array(
			'ORDER BY' => 'page_title ASC',
			'GROUP BY' => array(
				'page_id',
				'page_namespace',
				'page_title',
			),
		);

		if ( $limit !== null ) {
			$options['LIMIT'] = (int)$limit;
			$options['OFFSET'] = (int)$offset;
		}

		$res = $db->select(
			$tables,
			$fields,
			$where,
			__METHOD__,
			$options,
			$join
		);

		return iterator_to_array( $res, false );
	}

	private function getCategoryPageCount( $db, $categoryName ) {
		$categoryDbKey = $this->getCategoryDbKey( $categoryName );

		if ( $categoryDbKey === '' ) {
			return 0;
		}

		$tables = array(
			'page',
			'cl' => 'categorylinks',
			'lt' => 'linktarget',
		);

		$where = array(
			'page_namespace' => NS_MAIN,
			'page_is_redirect' => 0,
			'lt.lt_namespace' => NS_CATEGORY,
			'lt.lt_title' => $categoryDbKey,
		);

		$join = array(
			'cl' => array(
				'INNER JOIN',
				'page_id = cl.cl_from',
			),
			'lt' => array(
				'INNER JOIN',
				'cl.cl_target_id = lt.lt_id',
			),
		);

		$count = $db->selectField(
			$tables,
			'COUNT(DISTINCT page_id)',
			$where,
			__METHOD__,
			array(),
			$join
		);

		return (int)$count;
	}

	private function getPageTextFromTitle( $title ) {
		if ( !$title ) {
			return '';
		}

		if ( method_exists( $title, 'canExist' ) && !$title->canExist() ) {
			return '';
		}

		try {
			$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
			$wikiPage = $wikiPageFactory->newFromTitle( $title );
			$contentObj = $wikiPage->getContent();
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( !$contentObj || !method_exists( $contentObj, 'getText' ) ) {
			return '';
		}

		return $contentObj->getText();
	}

	private function buildItemLinkCell( $title, $params ) {
		$titleText = $title->getText();
		$itemName = isset( $params['itemname'] ) && $this->hasUsefulValue( $params['itemname'] )
			? trim( $params['itemname'] )
			: $titleText;

		if ( $itemName === $titleText ) {
			return '[[' . $titleText . ']]';
		}

		return '[[' . $titleText . '|' . $itemName . ']]';
	}

	private function buildFilterBarHtml( $tableId ) {
		$tableIdAttr = $this->htmlAttribute( $tableId );

		$output = '';
		$output .= "<div class='dqil-filterbar' data-dqil-table-id='" . $tableIdAttr . "'>";
		$output .= "<div class='dqil-filter-field dqil-filter-field-wide'>";
		$output .= "<label for='" . $tableIdAttr . "-filter'>Quest or Item Name</label>";
		$output .= "<input id='" . $tableIdAttr . "-filter' class='dqil-filter-input' type='search' data-dqil-column='item-or-quest' placeholder='Type an item or quest name'>";
		$output .= "</div>";
		$output .= "<button type='button' class='dqil-filter-clear'>Clear</button>";
		$output .= "<span class='dqil-filter-count' aria-live='polite'></span>";
		$output .= "</div>\n";

		return $output;
	}

	private function buildTableShellHtml( $tableId, $categoryName, $total, $limit ) {
		$tableIdAttr = $this->htmlAttribute( $tableId );

		$output = '';
		$output .= "<table id='" . $tableIdAttr . "' class='eoTable3 sortable dqil-table' style='width:100%;' ";
		$output .= "data-dqil-category='" . $this->htmlAttribute( $categoryName ) . "' ";
		$output .= "data-dqil-total='" . (int)$total . "' ";
		$output .= "data-dqil-limit='" . (int)$limit . "' ";
		$output .= "data-dqil-next-offset='0'>\n";
		$output .= "<thead><tr>";
		$output .= "<th>Item</th>";
		$output .= "<th>Obtained</th>";
		$output .= "<th>Related Quest</th>";
		$output .= "<th>Notes</th>";
		$output .= "</tr></thead>\n";
		$output .= "<tbody class='dqil-body'></tbody>\n";
		$output .= "</table>\n";
		$output .= "<div class='dqil-load-status' data-dqil-table-id='" . $tableIdAttr . "' aria-live='polite'>Loading quest items…</div>";

		return $output;
	}

	private function buildChunkTableWikitext( $categoryName, $offset, $limit, &$scanned, &$returned ) {
		$services = MediaWikiServices::getInstance();
		$db = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

		$rows = $this->getPagesInCategory( $db, $categoryName, $limit, $offset );
		$scanned = count( $rows );
		$returned = 0;

		$table = "<table class='dqil-chunk-table'>\n<tbody>\n";

		foreach ( $rows as $row ) {
			$title = $this->makeTitle( (int)$row->page_namespace, $row->page_title );

			if ( !$title ) {
				continue;
			}

			$pageText = $this->getPageTextFromTitle( $title );

			if ( $pageText === '' || !$this->pageLooksLikeItemPage( $pageText ) ) {
				continue;
			}

			$params = $this->parseItempageParams( $pageText );

			$relatedQuests = isset( $params['relatedquests'] ) ? $params['relatedquests'] : '';
			$itemCell = $this->buildItemLinkCell( $title, $params );
			$obtainedCell = $this->buildObtainedCell( $params, $pageText, $title );
			$notesCell = isset( $params['notes'] ) ? $this->cleanNotesCell( $params['notes'] ) : '';

			$table .= "<tr class='dqil-row'>";
			$table .= "\n<td class='dqil-item-cell'>\n" . $itemCell . "\n</td>";
			$table .= "\n<td class='dqil-obtained-cell'>\n" . $obtainedCell . "\n</td>";
			$table .= "\n<td class='dqil-quest-cell'>\n" . $relatedQuests . "\n</td>";
			$table .= "\n<td class='dqil-notes-cell'>\n" . $notesCell . "\n</td>";
			$table .= "\n</tr>\n";

			$returned++;
		}

		$table .= "</tbody>\n</table>\n";

		return $table;
	}

	private function parseWikitextToHtml( $wikitext ) {
		global $wgTitle;

		$parser = MediaWikiServices::getInstance()->getParserFactory()->create();
		$pOptions = ParserOptions::newFromAnon();

		$title = $wgTitle;

		if ( !$title ) {
			$title = $this->makeTitle( NS_SPECIAL, 'DynamicQuestItemList' );
		}

		$result = $parser->parse( $wikitext, $title, $pOptions );

		return $result->getContentHolderText();
	}

	private function outputChunk( $categoryName, $offset, $limit ) {
		$services = MediaWikiServices::getInstance();
		$db = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

		$total = $this->getCategoryPageCount( $db, $categoryName );
		$scanned = 0;
		$returned = 0;
		$chunkWikitext = $this->buildChunkTableWikitext( $categoryName, $offset, $limit, $scanned, $returned );
		$html = $this->parseWikitextToHtml( $chunkWikitext );
		$nextOffset = $offset + $scanned;

		$this->jsonResponse( array(
			'ok' => true,
			'category' => $categoryName,
			'offset' => $offset,
			'limit' => $limit,
			'scanned' => $scanned,
			'returned' => $returned,
			'nextOffset' => $nextOffset,
			'total' => $total,
			'done' => $scanned === 0 || $nextOffset >= $total,
			'html' => $html,
		) );
	}

	private function parseAndAddWikitext( $out, $wikitext ) {
		global $wgTitle;

		$parser = MediaWikiServices::getInstance()->getParserFactory()->create();
		$pOptions = ParserOptions::newFromAnon();

		$title = $wgTitle;

		if ( !$title ) {
			$title = $this->makeTitle( NS_SPECIAL, 'DynamicQuestItemList' );
		}

		$result = $parser->parse( $wikitext, $title, $pOptions );
		$out->addHTML( $result->getContentHolderText() );
	}

	public function execute( $par ) {
		global $wgOut;

		$request = $this->getRequest();
		$categoryName = $this->normalizeCategoryName( $par );

		if ( $request->getVal( 'dqil_action' ) === 'chunk' ) {
			$categoryName = $this->normalizeCategoryName( $request->getVal( 'category', $categoryName ) );
			$offset = max( 0, $request->getInt( 'offset', 0 ) );
			$limit = max( 1, min( self::MAX_LIMIT, $request->getInt( 'limit', self::DEFAULT_LIMIT ) ) );

			$this->outputChunk( $categoryName, $offset, $limit );
			return;
		}

		$wgOut->addModules( 'ext.dynamicQuestItemList' );

		$services = MediaWikiServices::getInstance();
		$db = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

		$total = $this->getCategoryPageCount( $db, $categoryName );
		$tableId = 'dqil-quest-items-' . substr( md5( $categoryName ), 0, 10 );
		$limit = self::DEFAULT_LIMIT;

		$header = "'''Quest Items''' - ''Loading " . $total . " quest items from [[:Category:" . $categoryName . "]] in chunks.''\n\n";

		$this->parseAndAddWikitext( $wgOut, $header );
		$wgOut->addHTML( $this->buildFilterBarHtml( $tableId ) );
		$wgOut->addHTML( $this->buildTableShellHtml( $tableId, $categoryName, $total, $limit ) );
	}
}
