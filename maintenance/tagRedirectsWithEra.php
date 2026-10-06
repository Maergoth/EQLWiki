<?php

/**
 * Add era tags to redirect pages based on the redirect target's era categories.
 *
 * This script is designed for the EQL era system where public era templates
 * ultimately place pages into categories such as:
 *
 *   Category:Classic Era
 *   Category:Kunark Era
 *   Category:Velious Era
 *   Category:Chardok Revamp Era
 *
 * It does NOT require changing Template:PageEra.
 *
 * MediaWiki 1.40+:
 *
 * Dry run:
 *   php maintenance/run.php tagRedirectsWithEra --dry-run
 *
 * Focused dry run:
 *   php maintenance/run.php tagRedirectsWithEra --dry-run --title-contains "Vyemm" --show-scanned
 *
 * Apply:
 *   php maintenance/run.php tagRedirectsWithEra --apply
 *
 * Optional:
 *   --out-only
 *     Only inherit eras currently marked "out" in Template:PageEra.
 *     This mirrors the out-of-era filter more narrowly.
 *
 * Important:
 *   Era tags are inserted AFTER the #REDIRECT line.
 *   Do not put templates before #REDIRECT or the page may stop behaving as a redirect.
 */

require_once __DIR__ . '/Maintenance.php';

use MediaWiki\MediaWikiServices;

class TagRedirectsWithEra extends Maintenance {

	private const EDIT_SUMMARY = 'Add era tag to redirect based on redirect target';

	private const PAGE_ERA_TEMPLATE_TITLE = 'Template:PageEra';

	/**
	 * Map internal PageEra keys / category aliases to the public template
	 * that should be added to the redirect.
	 *
	 * The aliases are intentionally similar to your JS plugin's
	 * OUT_ERA_CATEGORY_ALIASES, but expanded to include in-era categories too.
	 */
	private array $eraCategoryMap = [
		'classic' => [
			'template' => 'Classic Era',
			'aliases' => [
				'classic era',
				'classic',
			],
		],
		'kunark' => [
			'template' => 'Kunark Era',
			'aliases' => [
				'kunark era',
				'kunark',
			],
		],
		'velious' => [
			'template' => 'Velious Era',
			'aliases' => [
				'velious era',
				'velious',
			],
		],
		'luclin' => [
			'template' => 'Luclin Era',
			'aliases' => [
				'luclin era',
				'luclin',
			],
		],
		'chardok' => [
			'template' => 'Chardok Era',
			'aliases' => [
				'chardok era',
				'chardok',
			],
		],
		'chardokrevamp' => [
			'template' => 'Chardok Revamp Era',
			'aliases' => [
				'chardok revamp era',
				'chardok revamp',
				'chardokrevamp',
			],
		],
		'fear' => [
			'template' => 'Fear Era',
			'aliases' => [
				'fear era',
				'fear',
			],
		],
		'hate' => [
			'template' => 'Hate Era',
			'aliases' => [
				'hate era',
				'hate',
			],
		],
		'hole' => [
			'template' => 'Hole Era',
			'aliases' => [
				'hole era',
				'hole',
			],
		],
		'holevp' => [
			'template' => 'Hole VP Era',
			'aliases' => [
				'hole vp era',
				'hole/vp era',
				'holevp',
			],
		],
		'sky' => [
			'template' => 'Sky Era',
			'aliases' => [
				'sky era',
				'sky',
			],
		],
		'stonebrunt' => [
			'template' => 'Stonebrunt Era',
			'aliases' => [
				'stonebrunt era',
				'stonebrunt',
			],
		],
		'temple' => [
			'template' => 'Temple Era',
			'aliases' => [
				'temple era',
				'temple',
			],
		],
		'warrens' => [
			'template' => 'Warrens Era',
			'aliases' => [
				'warrens era',
				'warrens',
			],
		],
		'warrensfearhatererevamp' => [
			'template' => 'Warrens Fear Hate Revamp Era',
			'aliases' => [
				'warrens fear hate revamp era',
				'warrens/fear/hate revamp era',
				'warrensfearhatererevamp',
			],
		],
		'paineel' => [
			'template' => 'Paineel Era',
			'aliases' => [
				'paineel era',
				'paineel',
			],
		],
		'epics' => [
			'template' => 'Epics Era',
			'aliases' => [
				'epics era',
				'epics',
				'epic era',
			],
		],
		'epicquests' => [
			'template' => 'Epic Quests Era',
			'aliases' => [
				'epic quests era',
				'epic quests',
				'epicquests',
			],
		],
		'unknown' => [
			'template' => 'Unknown Era',
			'aliases' => [
				'unknown era',
				'unknown',
			],
		],
	];

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Add era tags to redirects based on their final target page era categories.' );

		$this->addOption( 'dry-run', 'Show changes without saving.', false, false );
		$this->addOption( 'apply', 'Actually save page changes.', false, false );
		$this->addOption( 'limit', 'Limit number of redirect pages scanned.', false, true );
		$this->addOption( 'diff-limit', 'Limit number of diffs printed.', false, true );
		$this->addOption( 'title-contains', 'Only scan redirect pages whose title contains this text. Can be repeated.', false, true, false, true );
		$this->addOption( 'target-contains', 'Only process redirects whose final target title contains this text. Can be repeated.', false, true, false, true );
		$this->addOption( 'show-scanned', 'Print every scanned redirect title.', false, false );
		$this->addOption( 'out-only', 'Only inherit eras currently marked out in Template:PageEra.', false, false );
		$this->addOption( 'era-template', 'Additional era template/category name to inherit. Can be repeated.', false, true, false, true );
	}

	public function execute() {
		$apply = $this->hasOption( 'apply' );
		$dryRun = $this->hasOption( 'dry-run' ) || !$apply;
		$showScanned = $this->hasOption( 'show-scanned' );
		$outOnly = $this->hasOption( 'out-only' );

		$limit = $this->getOption( 'limit', null );
		$limit = $limit !== null ? (int)$limit : null;

		$diffLimit = (int)$this->getOption( 'diff-limit', 200 );

		$titleContains = $this->getStringListOption( 'title-contains' );
		$targetContains = $this->getStringListOption( 'target-contains' );

		$extraEraTemplates = $this->getStringListOption( 'era-template' );
		foreach ( $extraEraTemplates as $template ) {
			$this->addExtraEraTemplate( $template );
		}

		$outEraKeys = $this->getOutEraKeysFromPageEraTemplate();

		$this->output( "Mode: " . ( $apply ? "APPLY\n" : "DRY RUN\n" ) );
		$this->output( "Scanning redirect pages in main/article namespace.\n" );
		$this->output( "Era detection mode: target page categories\n" );

		if ( $outOnly ) {
			$this->output( "Era inheritance: only eras currently marked out in Template:PageEra\n" );
		} else {
			$this->output( "Era inheritance: all recognized era categories\n" );
		}

		if ( $titleContains ) {
			$this->output( "Redirect title filter:\n" );
			foreach ( $titleContains as $needle ) {
				$this->output( "  - contains \"{$needle}\"\n" );
			}
		}

		if ( $targetContains ) {
			$this->output( "Final target title filter:\n" );
			foreach ( $targetContains as $needle ) {
				$this->output( "  - contains \"{$needle}\"\n" );
			}
		}

		$this->output( "Out-era keys from Template:PageEra:\n" );
		foreach ( $outEraKeys as $key ) {
			$this->output( "  - {$key}\n" );
		}

		$this->output( "\n" );

		$services = MediaWikiServices::getInstance();

		if ( method_exists( $services, 'getConnectionProvider' ) ) {
			$db = $services->getConnectionProvider()->getReplicaDatabase();
		} else {
			$db = wfGetDB( DB_REPLICA );
		}

		$user = $this->getMaintenanceUserCompat();

		$conditions = [
			'page_namespace' => NS_MAIN,
			'page_is_redirect' => 1,
		];

		$options = [
			'ORDER BY' => 'page_title',
		];

		if ( $limit !== null && $limit > 0 ) {
			$options['LIMIT'] = $limit;
		}

		$res = $db->select(
			'page',
			[ 'page_id', 'page_namespace', 'page_title' ],
			$conditions,
			__METHOD__,
			$options
		);

		$rowsSeen = 0;
		$scanned = 0;
		$eligibleTargets = 0;
		$changed = 0;
		$saved = 0;
		$skippedNoTarget = 0;
		$skippedNoEraCategory = 0;
		$skippedAlreadyTagged = 0;
		$diffsPrinted = 0;

		foreach ( $res as $row ) {
			$rowsSeen++;

			$redirectTitle = $this->makeTitleSafeCompat( (int)$row->page_namespace, $row->page_title );
			if ( !$redirectTitle ) {
				continue;
			}

			if ( $titleContains && !$this->titleMatchesFilter( $redirectTitle->getPrefixedText(), $titleContains ) ) {
				continue;
			}

			$scanned++;

			if ( $showScanned ) {
				$this->output( "Scanning: " . $redirectTitle->getPrefixedText() . "\n" );
			}

			$redirectPage = $this->makeWikiPageCompat( $redirectTitle );
			if ( !$redirectPage ) {
				continue;
			}

			$redirectText = $this->getPageText( $redirectPage );
			if ( $redirectText === null ) {
				continue;
			}

			$immediateTargetTitle = $this->extractRedirectTargetTitle( $redirectText, $redirectTitle );
			if ( !$immediateTargetTitle ) {
				$skippedNoTarget++;
				continue;
			}

			$targetInfo = $this->resolveFinalTarget( $immediateTargetTitle, 0, [] );
			if ( !$targetInfo || !$targetInfo['title'] ) {
				$skippedNoTarget++;
				continue;
			}

			$finalTargetTitle = $targetInfo['title'];

			if ( $targetContains && !$this->titleMatchesFilter( $finalTargetTitle->getPrefixedText(), $targetContains ) ) {
				continue;
			}

			$eligibleTargets++;

			$targetCategories = $this->getPageCategoryNames( $finalTargetTitle );
			$targetEraTemplates = $this->eraTemplatesFromCategories( $targetCategories, $outOnly, $outEraKeys );

			if ( !$targetEraTemplates ) {
				$skippedNoEraCategory++;
				continue;
			}

			$redirectCategories = $this->getPageCategoryNames( $redirectTitle );
			$redirectExistingEraTemplates = $this->eraTemplatesFromCategories( $redirectCategories, false, $outEraKeys );
			$redirectExistingEraTemplates = array_merge(
				$redirectExistingEraTemplates,
				$this->eraTemplatesFromWikitext( $redirectText )
			);

			$missingEraTemplates = $this->missingTemplates( $targetEraTemplates, $redirectExistingEraTemplates );

			if ( !$missingEraTemplates ) {
				$skippedAlreadyTagged++;
				continue;
			}

			$newRedirectText = $this->insertEraTagsAfterRedirectLine( $redirectText, $missingEraTemplates );
			if ( $newRedirectText === $redirectText ) {
				$skippedAlreadyTagged++;
				continue;
			}

			$changed++;

			$this->output( "\n=== CHANGE: " . $redirectTitle->getPrefixedText() . " ===\n" );
			$this->output( "Target: " . $finalTargetTitle->getPrefixedText() . "\n" );
			$this->output( "Target era categories detected:\n" );
			foreach ( $targetEraTemplates as $template ) {
				$this->output( "  - " . $template . "\n" );
			}
			$this->output( "Adding to redirect:\n" );
			foreach ( $missingEraTemplates as $template ) {
				$this->output( "+ {{" . $template . "}}\n" );
			}

			if ( $diffsPrinted < $diffLimit ) {
				$this->printRedirectDiff( $redirectText, $newRedirectText );
				$diffsPrinted++;
			} else {
				$this->output( "(diff suppressed by --diff-limit)\n" );
			}

			if ( $apply ) {
				$status = $this->savePageCompat(
					$redirectPage,
					$redirectTitle,
					$newRedirectText,
					self::EDIT_SUMMARY . ' [[' . $finalTargetTitle->getPrefixedText() . ']]',
					$user
				);

				if ( $status !== true ) {
					$this->error( "Failed to save " . $redirectTitle->getPrefixedText() . ": " . $this->statusToStringCompat( $status ) );
				} else {
					$saved++;
				}
			}
		}

		$this->output( "\nDone.\n" );
		$this->output( "Redirect rows seen: {$rowsSeen}\n" );
		$this->output( "Redirect pages scanned after filters: {$scanned}\n" );
		$this->output( "Redirects with eligible targets after filters: {$eligibleTargets}\n" );
		$this->output( "Skipped, no readable redirect target: {$skippedNoTarget}\n" );
		$this->output( "Skipped, target has no recognized era category: {$skippedNoEraCategory}\n" );
		$this->output( "Skipped, redirect already had target era tags: {$skippedAlreadyTagged}\n" );
		$this->output( "Redirect pages with changes: {$changed}\n" );
		$this->output( "Redirect pages saved: {$saved}\n" );

		if ( $dryRun ) {
			$this->output( "\nDry run only. Re-run with --apply to save changes.\n" );
		}
	}

	private function addExtraEraTemplate( string $template ): void {
		$template = trim( preg_replace( '/\s+/', ' ', str_replace( '_', ' ', $template ) ) );

		if ( $template === '' ) {
			return;
		}

		$key = $this->normalizeEraKey( $template );

		$this->eraCategoryMap[$key] = [
			'template' => $template,
			'aliases' => [
				$template,
				preg_replace( '/\s+Era$/i', '', $template ),
			],
		];
	}

	private function getStringListOption( string $name ): array {
		$value = $this->getOption( $name, [] );

		if ( !is_array( $value ) ) {
			$value = [ $value ];
		}

		return array_values( array_filter(
			array_map( 'trim', $value ),
			static fn ( $v ) => $v !== ''
		) );
	}

	private function titleMatchesFilter( string $titleText, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( stripos( $titleText, $needle ) !== false ) {
				return true;
			}
		}

		return false;
	}

	private function getOutEraKeysFromPageEraTemplate(): array {
		$fallback = [
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
			'unknown',
		];

		$title = $this->newTitleFromTextCompat( self::PAGE_ERA_TEMPLATE_TITLE, NS_TEMPLATE );
		if ( !$title || !$title->exists() ) {
			return $fallback;
		}

		$page = $this->makeWikiPageCompat( $title );
		if ( !$page ) {
			return $fallback;
		}

		$text = $this->getPageText( $page );
		if ( $text === null ) {
			return $fallback;
		}

		$keys = [];
		if ( preg_match_all( '/\|\s*([a-zA-Z0-9_ -]+)\s*=\s*out\b/', $text, $matches ) ) {
			foreach ( $matches[1] as $rawKey ) {
				$keys[] = $this->normalizeEraKey( $rawKey );
			}
		}

		$keys = array_values( array_unique( array_filter( $keys ) ) );

		return $keys ?: $fallback;
	}

	private function getPageText( $page ): ?string {
		$content = $page->getContent();

		if ( !$content || !method_exists( $content, 'getText' ) ) {
			return null;
		}

		return $content->getText();
	}

	private function extractRedirectTargetTitle( string $text, $sourceTitle ) {
		if ( !preg_match( '/^\s*#\s*REDIRECT\s*:?\s*\[\[([^\]\|#]+)(?:#[^\]\|]*)?(?:\|[^\]]*)?\]\]/im', $text, $m ) ) {
			return null;
		}

		$targetText = trim( $m[1] );
		if ( $targetText === '' ) {
			return null;
		}

		$namespace = method_exists( $sourceTitle, 'getNamespace' ) ? $sourceTitle->getNamespace() : NS_MAIN;

		return $this->newTitleFromTextCompat( $targetText, $namespace );
	}

	private function resolveFinalTarget( $title, int $depth, array $seen ): ?array {
		if ( !$title ) {
			return null;
		}

		if ( $depth > 10 ) {
			return null;
		}

		$key = method_exists( $title, 'getPrefixedDBkey' ) ? $title->getPrefixedDBkey() : $title->getPrefixedText();
		if ( isset( $seen[$key] ) ) {
			return null;
		}
		$seen[$key] = true;

		if ( !$title->exists() ) {
			return null;
		}

		$page = $this->makeWikiPageCompat( $title );
		if ( !$page ) {
			return null;
		}

		$text = $this->getPageText( $page );
		if ( $text === null ) {
			return null;
		}

		$nextTarget = $this->extractRedirectTargetTitle( $text, $title );
		if ( $nextTarget ) {
			return $this->resolveFinalTarget( $nextTarget, $depth + 1, $seen );
		}

		return [
			'title' => $title,
			'text' => $text,
		];
	}

	private function getPageCategoryNames( $title ): array {
	$pageId = $this->getTitlePageIdCompat( $title );

	if ( !$pageId ) {
		return [];
	}

	$services = MediaWikiServices::getInstance();

	if ( method_exists( $services, 'getConnectionProvider' ) ) {
		$db = $services->getConnectionProvider()->getReplicaDatabase();
	} else {
		$db = wfGetDB( DB_REPLICA );
	}

	$categories = [];

	// Older MediaWiki schema: categorylinks.cl_to contains the category DB key.
	if ( $db->fieldExists( 'categorylinks', 'cl_to', __METHOD__ ) ) {
		$res = $db->select(
			'categorylinks',
			[ 'cl_to' ],
			[ 'cl_from' => $pageId ],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$categories[] = str_replace( '_', ' ', $row->cl_to );
		}

		return $categories;
	}

	// Newer MediaWiki schema: categorylinks.cl_target_id points to linktarget.lt_id.
	if (
		$db->fieldExists( 'categorylinks', 'cl_target_id', __METHOD__ ) &&
		$db->tableExists( 'linktarget', __METHOD__ )
	) {
		$res = $db->select(
			[ 'cl' => 'categorylinks', 'lt' => 'linktarget' ],
			[ 'lt_title' ],
			[
				'cl_from' => $pageId,
				'lt_namespace' => NS_CATEGORY,
			],
			__METHOD__,
			[],
			[
				'lt' => [ 'JOIN', 'lt_id = cl_target_id' ],
			]
		);

		foreach ( $res as $row ) {
			$categories[] = str_replace( '_', ' ', $row->lt_title );
		}

		return $categories;
	}

	throw new RuntimeException( 'No compatible categorylinks schema found. Expected cl_to or cl_target_id/linktarget.' );
}

	private function getTitlePageIdCompat( $title ): int {
		if ( method_exists( $title, 'getArticleID' ) ) {
			return (int)$title->getArticleID();
		}

		if ( method_exists( $title, 'getId' ) ) {
			return (int)$title->getId();
		}

		$services = MediaWikiServices::getInstance();

		if ( method_exists( $services, 'getConnectionProvider' ) ) {
			$db = $services->getConnectionProvider()->getReplicaDatabase();
		} else {
			$db = wfGetDB( DB_REPLICA );
		}

		$row = $db->selectRow(
			'page',
			[ 'page_id' ],
			[
				'page_namespace' => $title->getNamespace(),
				'page_title' => $title->getDBkey(),
			],
			__METHOD__
		);

		return $row ? (int)$row->page_id : 0;
	}

	private function eraTemplatesFromCategories( array $categories, bool $outOnly, array $outEraKeys ): array {
		$templates = [];

		$outMap = [];
		foreach ( $outEraKeys as $key ) {
			$outMap[$this->normalizeEraKey( $key )] = true;
		}

		$categorySet = [];
		foreach ( $categories as $category ) {
			$categorySet[$this->normalizeCategoryText( $category )] = true;
		}

		foreach ( $this->eraCategoryMap as $key => $info ) {
			$normalizedKey = $this->normalizeEraKey( $key );

			if ( $outOnly && !isset( $outMap[$normalizedKey] ) ) {
				continue;
			}

			$aliases = $info['aliases'] ?? [];
			$aliases[] = $info['template'] ?? '';

			foreach ( $aliases as $alias ) {
				if ( $alias === '' ) {
					continue;
				}

				if ( isset( $categorySet[$this->normalizeCategoryText( $alias )] ) ) {
					$templates[] = $info['template'];
					break;
				}
			}
		}

		return $this->dedupeTemplates( $templates );
	}

	private function eraTemplatesFromWikitext( string $text ): array {
		$templates = [];

		foreach ( $this->eraCategoryMap as $info ) {
			$template = $info['template'];

			$templatePattern = preg_quote( $template, '/' );
			$templatePattern = str_replace( '\ ', '[ _]+', $templatePattern );

			if ( preg_match( '/\{\{\s*' . $templatePattern . '\s*(?:\|[^{}]*)?\}\}/i', $text ) ) {
				$templates[] = $template;
				continue;
			}

			$categoryPattern = preg_quote( $template, '/' );
			$categoryPattern = str_replace( '\ ', '[ _]+', $categoryPattern );

			if ( preg_match( '/\[\[\s*Category\s*:\s*' . $categoryPattern . '\s*(?:\|[^\]]*)?\]\]/i', $text ) ) {
				$templates[] = $template;
			}
		}

		return $this->dedupeTemplates( $templates );
	}

	private function missingTemplates( array $targetTemplates, array $existingTemplates ): array {
		$existingMap = [];
		foreach ( $existingTemplates as $template ) {
			$existingMap[$this->normalizeCategoryText( $template )] = true;
		}

		$missing = [];
		foreach ( $targetTemplates as $template ) {
			$key = $this->normalizeCategoryText( $template );

			if ( !isset( $existingMap[$key] ) ) {
				$missing[] = $template;
			}
		}

		return $this->dedupeTemplates( $missing );
	}

	private function dedupeTemplates( array $templates ): array {
		$out = [];
		$seen = [];

		foreach ( $templates as $template ) {
			$template = trim( preg_replace( '/\s+/', ' ', str_replace( '_', ' ', $template ) ) );

			if ( $template === '' ) {
				continue;
			}

			$key = $this->normalizeCategoryText( $template );

			if ( isset( $seen[$key] ) ) {
				continue;
			}

			$seen[$key] = true;
			$out[] = $template;
		}

		return $out;
	}

	private function normalizeCategoryText( string $value ): string {
		return mb_strtolower(
			trim(
				preg_replace(
					'/\s+/',
					' ',
					str_replace( '_', ' ', preg_replace( '/^Category:/i', '', $value ) )
				)
			)
		);
	}

	private function normalizeEraKey( string $value ): string {
		return mb_strtolower(
			preg_replace(
				'/[\s_-]+/',
				'',
				trim( $value )
			)
		);
	}

	private function insertEraTagsAfterRedirectLine( string $text, array $eraTemplates ): string {
		$lines = preg_split( "/(\r\n|\n|\r)/", $text );

		if ( $lines === false || !$lines ) {
			return $text;
		}

		$redirectLineIndex = null;

		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/^\s*#\s*REDIRECT\s*:?\s*\[\[[^\]]+\]\]/i', $line ) ) {
				$redirectLineIndex = $i;
				break;
			}
		}

		if ( $redirectLineIndex === null ) {
			return $text;
		}

		$insertLines = [];
		foreach ( $eraTemplates as $template ) {
			$insertLines[] = '{{' . $template . '}}';
		}

		array_splice( $lines, $redirectLineIndex + 1, 0, $insertLines );

		$newText = implode( "\n", $lines );

		if ( preg_match( "/(\r\n|\n|\r)$/", $text ) && !preg_match( "/\n$/", $newText ) ) {
			$newText .= "\n";
		}

		return $newText;
	}

	private function printRedirectDiff( string $old, string $new ): void {
		$oldLines = explode( "\n", $old );
		$newLines = explode( "\n", $new );

		$this->output( "Before:\n" );
		for ( $i = 0; $i < min( count( $oldLines ), 8 ); $i++ ) {
			$this->output( "- " . $oldLines[$i] . "\n" );
		}

		$this->output( "After:\n" );
		for ( $i = 0; $i < min( count( $newLines ), 12 ); $i++ ) {
			$this->output( "+ " . $newLines[$i] . "\n" );
		}
	}

	private function savePageCompat( $page, $title, string $text, string $summary, $user ) {
		$content = $this->makeContentCompat( $text, $title );

		if ( method_exists( $page, 'doEditContent' ) ) {
			$flags = 0;
			if ( defined( 'EDIT_UPDATE' ) ) {
				$flags |= EDIT_UPDATE;
			}
			if ( defined( 'EDIT_MINOR' ) ) {
				$flags |= EDIT_MINOR;
			}

			$status = $page->doEditContent(
				$content,
				$summary,
				$flags,
				false,
				$user
			);

			if ( $status && method_exists( $status, 'isOK' ) && !$status->isOK() ) {
				return $status;
			}

			return true;
		}

		if ( method_exists( $page, 'newPageUpdater' ) ) {
			$updater = $page->newPageUpdater( $user );

			$slot = 'main';
			if ( class_exists( '\MediaWiki\Revision\SlotRecord' ) ) {
				$slot = \MediaWiki\Revision\SlotRecord::MAIN;
			} elseif ( class_exists( 'SlotRecord' ) ) {
				$slot = \SlotRecord::MAIN;
			}

			$updater->setContent( $slot, $content );

			$comment = $this->makeCommentCompat( $summary );

			$flags = 0;
			if ( defined( 'EDIT_UPDATE' ) ) {
				$flags |= EDIT_UPDATE;
			}
			if ( defined( 'EDIT_MINOR' ) ) {
				$flags |= EDIT_MINOR;
			}

			$status = $updater->saveRevision( $comment, $flags );

			if ( $status && method_exists( $status, 'isOK' ) && !$status->isOK() ) {
				return $status;
			}

			if ( method_exists( $updater, 'getStatus' ) ) {
				$updaterStatus = $updater->getStatus();
				if ( $updaterStatus && method_exists( $updaterStatus, 'isOK' ) && !$updaterStatus->isOK() ) {
					return $updaterStatus;
				}
			}

			if ( method_exists( $updater, 'wasSuccessful' ) && !$updater->wasSuccessful() ) {
				if ( method_exists( $updater, 'getStatus' ) ) {
					return $updater->getStatus();
				}

				return 'PageUpdater save was not successful.';
			}

			return true;
		}

		return 'No compatible page-save method found.';
	}

	private function makeCommentCompat( string $summary ) {
		if ( class_exists( '\MediaWiki\CommentStore\CommentStoreComment' ) ) {
			return \MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment( $summary );
		}

		if ( class_exists( 'CommentStoreComment' ) ) {
			return \CommentStoreComment::newUnsavedComment( $summary );
		}

		return $summary;
	}

	private function makeTitleSafeCompat( int $namespace, string $dbKey ) {
		if ( class_exists( '\MediaWiki\Title\Title' ) ) {
			return \MediaWiki\Title\Title::makeTitleSafe( $namespace, $dbKey );
		}

		if ( class_exists( 'Title' ) ) {
			return \Title::makeTitleSafe( $namespace, $dbKey );
		}

		throw new RuntimeException( 'No compatible Title class found.' );
	}

	private function newTitleFromTextCompat( string $text, int $namespace ) {
		if ( class_exists( '\MediaWiki\Title\Title' ) ) {
			return \MediaWiki\Title\Title::newFromText( $text, $namespace );
		}

		if ( class_exists( 'Title' ) ) {
			return \Title::newFromText( $text, $namespace );
		}

		throw new RuntimeException( 'No compatible Title class found.' );
	}

	private function makeWikiPageCompat( $title ) {
		$services = MediaWikiServices::getInstance();

		if ( method_exists( $services, 'getWikiPageFactory' ) ) {
			return $services->getWikiPageFactory()->newFromTitle( $title );
		}

		if ( class_exists( '\MediaWiki\Page\WikiPage' ) ) {
			return \MediaWiki\Page\WikiPage::factory( $title );
		}

		if ( class_exists( 'WikiPage' ) ) {
			return \WikiPage::factory( $title );
		}

		throw new RuntimeException( 'No compatible WikiPage factory found.' );
	}

	private function makeContentCompat( string $text, $title ) {
		if ( class_exists( '\MediaWiki\Content\ContentHandler' ) ) {
			return \MediaWiki\Content\ContentHandler::makeContent( $text, $title );
		}

		if ( class_exists( 'ContentHandler' ) ) {
			return \ContentHandler::makeContent( $text, $title );
		}

		throw new RuntimeException( 'No compatible ContentHandler class found.' );
	}

	private function getMaintenanceUserCompat() {
		if ( method_exists( $this, 'getUser' ) ) {
			return $this->getUser();
		}

		if ( class_exists( '\MediaWiki\User\User' ) && method_exists( '\MediaWiki\User\User', 'newSystemUser' ) ) {
			return \MediaWiki\User\User::newSystemUser( 'RedirectEraTagger', [ 'steal' => true ] );
		}

		if ( class_exists( 'User' ) && method_exists( 'User', 'newSystemUser' ) ) {
			return \User::newSystemUser( 'RedirectEraTagger', [ 'steal' => true ] );
		}

		if ( class_exists( 'User' ) && method_exists( 'User', 'newFromName' ) ) {
			return \User::newFromName( 'RedirectEraTagger', false );
		}

		throw new RuntimeException( 'No compatible maintenance user method found.' );
	}

	private function statusToStringCompat( $status ): string {
		if ( is_string( $status ) ) {
			return $status;
		}

		if ( is_object( $status ) && method_exists( $status, 'getWikiText' ) ) {
			return $status->getWikiText( false, false, 'en' );
		}

		if ( is_object( $status ) && method_exists( $status, 'getMessage' ) ) {
			return $status->getMessage();
		}

		if ( is_object( $status ) && method_exists( $status, '__toString' ) ) {
			return (string)$status;
		}

		return 'unknown error';
	}
}

$maintClass = TagRedirectsWithEra::class;
require_once RUN_MAINTENANCE_IF_MAIN;