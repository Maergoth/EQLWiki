<?php

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

require_once __DIR__ . '/maintenance/Maintenance.php';

class FixItemTransclusionTags extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Repairs malformed <onlyinclude>/<includeonly> wrappers around item hover tooltip hbdiv blocks.'
		);

		$this->addOption(
			'apply',
			'Actually save changes. Without this, the script only reports what it would change.',
			false,
			false
		);

		$this->addOption(
			'title',
			'Repair one exact page title only.',
			false,
			true
		);

		$this->addOption(
			'contains',
			'Only scan page titles containing this text, case-insensitive.',
			false,
			true
		);

		$this->addOption(
			'limit',
			'Maximum number of candidate pages to scan.',
			false,
			true
		);
	}

	private function getPageText( $page ) {
		$content = $page->getContent();

		if ( !$content ) {
			return '';
		}

		if ( method_exists( 'ContentHandler', 'getContentText' ) ) {
			return ContentHandler::getContentText( $content );
		}

		if ( method_exists( $content, 'getText' ) ) {
			return $content->getText();
		}

		return '';
	}

	private function repairText( $text, &$reason ) {
		$reason = '';

		if ( stripos( $text, 'hbdiv' ) === false ) {
			return $text;
		}

		if (
			stripos( $text, '{{Itembox' ) === false &&
			stripos( $text, '{{Itempage' ) === false
		) {
			$reason = 'contains hbdiv but no Itembox/Itempage; skipped';
			return $text;
		}

		/*
		 * Bulk edits can leave parser-control tags in the wrong place.
		 * Remove only includeonly/onlyinclude controls, then wrap the first
		 * hbdiv tooltip block cleanly.
		 *
		 * We intentionally do NOT remove <noinclude> sections.
		 */
		$clean = preg_replace(
			'/<\/?\s*(?:onlyinclude|includeonly)\s*>/i',
			'',
			$text
		);

		$matched = preg_match(
			'/<div\s+class\s*=\s*["\']hbdiv["\'][^>]*>.*?<\/div>/is',
			$clean,
			$match,
			PREG_OFFSET_CAPTURE
		);

		if ( !$matched ) {
			$reason = 'could not find hbdiv block after cleanup; skipped';
			return $text;
		}

		$hbdiv = $match[0][0];
		$pos = $match[0][1];

		if (
			stripos( $hbdiv, '{{Itembox' ) === false &&
			stripos( $hbdiv, '{{Itempage' ) === false
		) {
			$reason = 'hbdiv does not contain Itembox/Itempage; skipped';
			return $text;
		}

		$wrapped =
			'<onlyinclude><includeonly>' .
			$hbdiv .
			'</includeonly></onlyinclude>';

		$repaired =
			substr( $clean, 0, $pos ) .
			$wrapped .
			substr( $clean, $pos + strlen( $hbdiv ) );

		if ( $repaired === $text ) {
			$reason = 'already normalized';
			return $text;
		}

		$reason = 'normalized hbdiv onlyinclude/includeonly wrapper';
		return $repaired;
	}

	private function savePageText( Title $title, $page, $newText ) {
		$user = User::newSystemUser(
			'ItemTransclusionFixer',
			[ 'steal' => true ]
		);

		$content = ContentHandler::makeContent( $newText, $title );

		$updater = $page->newPageUpdater( $user );
		$updater->setContent( SlotRecord::MAIN, $content );

		$summary = CommentStoreComment::newUnsavedComment(
			'Repair malformed item transclusion include tags'
		);

		return $updater->saveRevision(
			$summary,
			EDIT_UPDATE | EDIT_MINOR
		);
	}

	public function execute() {
		$apply = $this->hasOption( 'apply' );
		$titleOption = $this->getOption( 'title', null );
		$contains = $this->getOption( 'contains', null );
		$limit = (int)$this->getOption( 'limit', 0 );

		$services = MediaWikiServices::getInstance();
		$pageFactory = $services->getWikiPageFactory();

		$seen = 0;
		$changed = 0;
		$saved = 0;
		$skipped = 0;

		$titles = [];

		if ( $titleOption ) {
			$title = Title::newFromText( $titleOption );

			if ( !$title ) {
				$this->fatalError( 'Invalid title: ' . $titleOption );
			}

			$titles[] = $title;
		} else {
			$dbr = $services
				->getDBLoadBalancer()
				->getConnection( DB_REPLICA );

			$conditions = [
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => 0
			];

			if ( $contains !== null && $contains !== '' ) {
				$conditions[] = 'LOWER(page_title) LIKE ' .
					$dbr->addQuotes( '%' . strtolower( str_replace( ' ', '_', $contains ) ) . '%' );
			}

			$options = [
				'ORDER BY' => 'page_id ASC'
			];

			if ( $limit > 0 ) {
				$options['LIMIT'] = $limit;
			}

			$res = $dbr->select(
				'page',
				[ 'page_namespace', 'page_title' ],
				$conditions,
				__METHOD__,
				$options
			);

			foreach ( $res as $row ) {
				$titles[] = Title::makeTitle( $row->page_namespace, $row->page_title );
			}
		}

		$this->output( $apply ? "Mode: APPLY\n" : "Mode: DRY RUN\n" );
		$this->output( "Candidates: " . count( $titles ) . "\n\n" );

		foreach ( $titles as $title ) {
			$seen++;

			$page = $pageFactory->newFromTitle( $title );
			$text = $this->getPageText( $page );

			if ( $text === '' ) {
				$skipped++;
				continue;
			}

			$reason = '';
			$newText = $this->repairText( $text, $reason );

			if ( $newText === $text ) {
				if ( $reason && $reason !== 'already normalized' ) {
					$this->output( "SKIP: " . $title->getPrefixedText() . " — " . $reason . "\n" );
				}

				$skipped++;
				continue;
			}

			$changed++;
			$this->output( "CHANGE: " . $title->getPrefixedText() . " — " . $reason . "\n" );

			if ( $apply ) {
				$status = $this->savePageText( $title, $page, $newText );

				if ( $status && method_exists( $status, 'isOK' ) && !$status->isOK() ) {
					$this->output( "  ERROR saving: " . $title->getPrefixedText() . "\n" );
					continue;
				}

				$saved++;
			}
		}

		$this->output( "\nDone.\n" );
		$this->output( "Pages scanned: {$seen}\n" );
		$this->output( "Pages changed: {$changed}\n" );
		$this->output( "Pages saved:   {$saved}\n" );
		$this->output( "Pages skipped: {$skipped}\n" );

		if ( !$apply ) {
			$this->output( "\nDry run only. Re-run with --apply to save changes.\n" );
		}
	}
}

$maintClass = FixItemTransclusionTags::class;
require_once RUN_MAINTENANCE_IF_MAIN;