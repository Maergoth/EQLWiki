<?php

/**
 * Normalize legacy Small/Normal/Large armor names to unsized canonical names.
 *
 * MediaWiki 1.40+:
 *   php maintenance/run.php normalizeSizedArmor --dry-run
 *   php maintenance/run.php normalizeSizedArmor --dry-run --title-contains Studded --show-scanned
 *   php maintenance/run.php normalizeSizedArmor --apply
 *   php maintenance/run.php normalizeSizedArmor --apply --redirect-sized-pages
 *
 * This script intentionally uses MediaWiki page-save APIs rather than direct SQL writes.
 */

require_once __DIR__ . '/Maintenance.php';

use MediaWiki\MediaWikiServices;

class NormalizeSizedArmor extends Maintenance {

	private const EDIT_SUMMARY = 'Normalize legacy Small/Normal/Large armor names to unsized canonical armor names';

	private array $armorRoots = [
		// Include this because your current examples are Studded.
		'Studded',

		// Cloth
		'Cloth',
		'Netted',
		'Damask',
		'Gossamer',
		'Woven',

		// Leather
		'Raw-hide',
		'Raw Hide',
		'Rawhide',
		'Mesh',
		'Leather',
		'Dark Muslin',
		'Withered Leather',

		// Chain
		'Ringmail',
		'Chainmail',
		'Iron',
		'Blackened Iron',
		'Ravenscale',

		// Plate
		'Bronze',
		'Rubicite',
		'Golden Efreeti',
		'Wind Etched',
		'Paineel Steel',
		'Blazing Fennin Ro',
	];

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Normalize legacy Small/Normal/Large armor names to unsized canonical names.' );

		$this->addOption( 'dry-run', 'Show changes without saving.', false, false );
		$this->addOption( 'apply', 'Actually save page changes.', false, false );
		$this->addOption( 'redirect-sized-pages', 'Redirect old sized mainspace pages to existing canonical pages.', false, false );
		$this->addOption( 'limit', 'Limit number of pages scanned.', false, true );
		$this->addOption( 'diff-limit', 'Limit number of diffs printed.', false, true );
		$this->addOption( 'exclude-root', 'Armor root to exclude. Can be repeated.', false, true, false, true );
		$this->addOption( 'title-contains', 'Only scan pages whose title contains this text. Can be repeated.', false, true, false, true );
		$this->addOption( 'show-scanned', 'Print every scanned page title, even if unchanged.', false, false );
	}

	public function execute() {
		$apply = $this->hasOption( 'apply' );
		$dryRun = $this->hasOption( 'dry-run' ) || !$apply;
		$redirectSizedPages = $this->hasOption( 'redirect-sized-pages' );
		$showScanned = $this->hasOption( 'show-scanned' );

		$limit = $this->getOption( 'limit', null );
		$limit = $limit !== null ? (int)$limit : null;

		$diffLimit = (int)$this->getOption( 'diff-limit', 50 );

		$excludeRoots = $this->getOption( 'exclude-root', [] );
		if ( !is_array( $excludeRoots ) ) {
			$excludeRoots = [ $excludeRoots ];
		}

		$titleContains = $this->getOption( 'title-contains', [] );
		if ( !is_array( $titleContains ) ) {
			$titleContains = [ $titleContains ];
		}

		$titleContains = array_values( array_filter(
			array_map( 'trim', $titleContains ),
			static fn ( $value ) => $value !== ''
		) );

		$this->armorRoots = $this->filterArmorRoots( $this->armorRoots, $excludeRoots );
		$this->armorRoots = $this->sortRootsLongestFirst( $this->armorRoots );

		$this->output( "Mode: " . ( $apply ? "APPLY\n" : "DRY RUN\n" ) );
		$this->output( "Scanning namespaces: main/article and Template\n" );

		if ( $titleContains ) {
			$this->output( "Title filter:\n" );
			foreach ( $titleContains as $needle ) {
				$this->output( "  - contains \"{$needle}\"\n" );
			}
		}

		$this->output( "Armor roots:\n" );
		foreach ( $this->armorRoots as $root ) {
			$this->output( "  - {$root}\n" );
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
			'page_namespace' => [ NS_MAIN, NS_TEMPLATE ],
		];

		$options = [
			'ORDER BY' => [ 'page_namespace', 'page_title' ],
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
		$changed = 0;
		$saved = 0;
		$redirected = 0;
		$diffsPrinted = 0;

		foreach ( $res as $row ) {
			$rowsSeen++;

			$title = $this->makeTitleSafeCompat( (int)$row->page_namespace, $row->page_title );
			if ( !$title ) {
				continue;
			}

			if ( $titleContains && !$this->titleMatchesFilter( $title->getPrefixedText(), $titleContains ) ) {
				continue;
			}

			$scanned++;

			if ( $showScanned ) {
				$this->output( "Scanning: " . $title->getPrefixedText() . "\n" );
			}

			$page = $this->makeWikiPageCompat( $title );
			if ( !$page ) {
				continue;
			}

			$content = $page->getContent();

			if ( !$content || !method_exists( $content, 'getText' ) ) {
				continue;
			}

			$oldText = $content->getText();
			$newText = $this->normalizeWikitext( $oldText );

			if ( $newText !== $oldText ) {
				$changed++;
				$this->output( "\n=== CHANGE: " . $title->getPrefixedText() . " ===\n" );

				if ( $diffsPrinted < $diffLimit ) {
					$this->printSimpleDiff( $oldText, $newText );
					$diffsPrinted++;
				} else {
					$this->output( "(diff suppressed by --diff-limit)\n" );
				}

				if ( $apply ) {
					$status = $this->savePageCompat(
						$page,
						$title,
						$newText,
						self::EDIT_SUMMARY,
						$user
					);

					if ( $status !== true ) {
						$this->error( "Failed to save " . $title->getPrefixedText() . ": " . $this->statusToStringCompat( $status ) );
					} else {
						$saved++;
					}
				}
			}

			if ( $redirectSizedPages && $title->getNamespace() === NS_MAIN ) {
				$canonical = $this->canonicalTitleText( $title->getText() );

				if ( $canonical !== $title->getText() ) {
					$canonicalTitle = $this->newTitleFromTextCompat( $canonical, NS_MAIN );

					if ( $canonicalTitle && $canonicalTitle->exists() ) {
						$redirectText = '#REDIRECT [[' . $canonicalTitle->getText() . "]]\n";

						if ( trim( $oldText ) !== trim( $redirectText ) ) {
							$this->output( "\n=== REDIRECT: " . $title->getText() . " -> " . $canonicalTitle->getText() . " ===\n" );

							if ( $apply ) {
								$status = $this->savePageCompat(
									$page,
									$title,
									$redirectText,
									'Redirect legacy sized armor page to [[' . $canonicalTitle->getText() . ']]',
									$user
								);

								if ( $status !== true ) {
									$this->error( "Failed to redirect " . $title->getPrefixedText() . ": " . $this->statusToStringCompat( $status ) );
								} else {
									$redirected++;
								}
							}
						}
					}
				}
			}
		}

		$this->output( "\nDone.\n" );
		$this->output( "Rows seen before title filter: {$rowsSeen}\n" );
		$this->output( "Pages scanned after title filter: {$scanned}\n" );
		$this->output( "Pages with changes: {$changed}\n" );
		$this->output( "Pages saved: {$saved}\n" );
		$this->output( "Sized pages redirected: {$redirected}\n" );

		if ( $dryRun ) {
			$this->output( "\nDry run only. Re-run with --apply to save changes.\n" );
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

	private function titleMatchesFilter( string $titleText, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( stripos( $titleText, $needle ) !== false ) {
				return true;
			}
		}

		return false;
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
			return \MediaWiki\User\User::newSystemUser( 'ArmorNormalizer', [ 'steal' => true ] );
		}

		if ( class_exists( 'User' ) && method_exists( 'User', 'newSystemUser' ) ) {
			return \User::newSystemUser( 'ArmorNormalizer', [ 'steal' => true ] );
		}

		if ( class_exists( 'User' ) && method_exists( 'User', 'newFromName' ) ) {
			return \User::newFromName( 'ArmorNormalizer', false );
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

	private function filterArmorRoots( array $roots, array $excludeRoots ): array {
		if ( !$excludeRoots ) {
			return array_values( array_unique( $roots ) );
		}

		$excludeMap = [];
		foreach ( $excludeRoots as $root ) {
			$excludeMap[ mb_strtolower( trim( $root ) ) ] = true;
		}

		$out = [];
		foreach ( $roots as $root ) {
			if ( !isset( $excludeMap[ mb_strtolower( trim( $root ) ) ] ) ) {
				$out[] = $root;
			}
		}

		return array_values( array_unique( $out ) );
	}

	private function sortRootsLongestFirst( array $roots ): array {
		usort( $roots, static function ( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		} );

		return $roots;
	}

	private function normalizeSpaces( string $value ): string {
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	private function beginsWithArmorRoot( string $name ): bool {
		$name = $this->normalizeSpaces( $name );

		foreach ( $this->armorRoots as $root ) {
			if ( preg_match( '/^' . preg_quote( $root, '/' ) . '(\b|$)/i', $name ) ) {
				return true;
			}
		}

		return false;
	}

	private function stripSizePrefixIfTarget( string $name ): string {
		$original = $name;
		$name = $this->normalizeSpaces( $name );

		if ( !preg_match( '/^(Small|Normal|Large)\s+(.+)$/i', $name, $m ) ) {
			return $original;
		}

		$remainder = $this->normalizeSpaces( $m[2] );

		if ( $this->beginsWithArmorRoot( $remainder ) ) {
			return $remainder;
		}

		return $original;
	}

	private function canonicalTitleText( string $titleText ): string {
		return $this->stripSizePrefixIfTarget( $titleText );
	}

	private function normalizeWikilinkTarget( string $target ): string {
		$originalTarget = $target;
		$targetForCheck = trim( $target );

		// Avoid changing files, categories, special pages, user pages, etc.
		if ( strpos( $targetForCheck, ':' ) !== false ) {
			$prefix = strtolower( trim( explode( ':', $targetForCheck, 2 )[0] ) );
			$blocked = [
				'file',
				'image',
				'category',
				'template',
				'special',
				'media',
				'user',
				'user talk',
				'talk',
				'help',
				'mediawiki',
			];

			if ( in_array( $prefix, $blocked, true ) ) {
				return $originalTarget;
			}
		}

		if ( strpos( $targetForCheck, '#' ) !== false ) {
			$parts = explode( '#', $targetForCheck, 2 );
			$newPage = $this->stripSizePrefixIfTarget( $parts[0] );

			if ( $newPage !== $parts[0] ) {
				return $newPage . '#' . $parts[1];
			}

			return $originalTarget;
		}

		$newTarget = $this->stripSizePrefixIfTarget( $targetForCheck );

		if ( $newTarget !== $targetForCheck ) {
			return $newTarget;
		}

		return $originalTarget;
	}

	private function normalizeWikitext( string $text ): string {
		$changed = $text;

		// 1. Normalize wikilink targets:
		//    [[Small Studded Gloves]] -> [[Studded Gloves]]
		//    [[Small Studded Gloves|foo]] -> [[Studded Gloves|foo]]
		//    Unrelated links are returned exactly as written.
		$changed = preg_replace_callback(
			'/\[\[([^\[\]\n]+?)\]\]/',
			function ( $m ) {
				$inner = $m[1];
				$parts = explode( '|', $inner, 2 );
				$target = $parts[0];
				$newTarget = $this->normalizeWikilinkTarget( $target );

				if ( count( $parts ) === 1 ) {
					if ( $newTarget !== $target ) {
						return '[[' . $newTarget . ']]';
					}

					return $m[0];
				}

				if ( $newTarget !== $target ) {
					return '[[' . $newTarget . '|' . $parts[1] . ']]';
				}

				return $m[0];
			},
			$changed
		);

		// 2. Normalize colon transclusions:
		//    {{:Small Studded Gloves}} -> {{:Studded Gloves}}
		//    Unrelated transclusions are returned exactly as written.
		$changed = preg_replace_callback(
			'/\{\{\s*:\s*([^{}\n|]+?)\s*\}\}/',
			function ( $m ) {
				$originalTitle = $m[1];
				$titleForCheck = trim( $originalTitle );
				$newTitle = $this->stripSizePrefixIfTarget( $titleForCheck );

				if ( $newTitle !== $titleForCheck ) {
					return '{{:' . $newTitle . '}}';
				}

				return $m[0];
			},
			$changed
		);

		// 3. Normalize itemname parameter:
		//    |itemname = Small Studded Gloves -> |itemname = Studded Gloves
		//    Unrelated itemname values are returned exactly as written.
		$changed = preg_replace_callback(
			'/(^\s*\|\s*itemname\s*=\s*)([^\n<>{}|]+?)(\s*)$/mi',
			function ( $m ) {
				$originalValue = $m[2];
				$valueForCheck = trim( $originalValue );
				$newValue = $this->stripSizePrefixIfTarget( $valueForCheck );

				if ( $newValue !== $valueForCheck ) {
					return $m[1] . $newValue . $m[3];
				}

				return $m[0];
			},
			$changed
		);

		// 4. Normalize common recipe Yield lines.
		$changed = preg_replace_callback(
			'/^.*Yield:.*$/mi',
			function ( $m ) {
				return $this->normalizeSizedNamesInsideLine( $m[0] );
			},
			$changed
		);

		return $changed;
	}

	private function normalizeSizedNamesInsideLine( string $line ): string {
		$rootsPattern = implode(
			'|',
			array_map(
				static fn ( $root ) => preg_quote( $root, '/' ),
				$this->armorRoots
			)
		);

		// Conservative: only rewrite names starting with Small/Normal/Large + one configured armor root.
		$pattern = '/\b(?:Small|Normal|Large)\s+(?:' . $rootsPattern . ')\b[^\n\[\]{}<>|\'"]*/i';

		return preg_replace_callback(
			$pattern,
			function ( $m ) {
				return $this->stripSizePrefixIfTarget( $m[0] );
			},
			$line
		);
	}

	private function printSimpleDiff( string $old, string $new ): void {
		$oldLines = explode( "\n", $old );
		$newLines = explode( "\n", $new );

		$max = max( count( $oldLines ), count( $newLines ) );

		for ( $i = 0; $i < $max; $i++ ) {
			$o = $oldLines[$i] ?? null;
			$n = $newLines[$i] ?? null;

			if ( $o !== $n ) {
				if ( $o !== null ) {
					$this->output( "- " . $o . "\n" );
				}
				if ( $n !== null ) {
					$this->output( "+ " . $n . "\n" );
				}
			}
		}
	}
}

$maintClass = NormalizeSizedArmor::class;
require_once RUN_MAINTENANCE_IF_MAIN;