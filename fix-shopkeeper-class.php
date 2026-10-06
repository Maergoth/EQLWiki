<?php

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

require_once __DIR__ . '/maintenance/Maintenance.php';

class FixShopkeeperClass extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Changes NPC template class values from Shopkeeper/Shopkeep to Merchant.'
		);

		$this->addOption(
			'apply',
			'Actually save changes. Without this, the script only reports what it would change.',
			false,
			false
		);

		$this->addOption(
			'title',
			'Fix one exact page title only.',
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

	private function getPageText( $page ): string {
		$content = $page->getContent();

		if ( !$content ) {
			return '';
		}

		if ( class_exists( 'ContentHandler' ) && method_exists( 'ContentHandler', 'getContentText' ) ) {
			return ContentHandler::getContentText( $content );
		}

		if ( method_exists( $content, 'getText' ) ) {
			return $content->getText();
		}

		return '';
	}

	private function pageLooksLikeNpcPage( string $text ): bool {
		return preg_match(
			'/\{\{\s*(Namedmobpage|MerchantPage|Mobpage|NPCPage|Npcpage)\b/i',
			$text
		) === 1;
	}

	private function repairText( string $text, array &$changes ): string {
		$changes = [];

		if ( !$this->pageLooksLikeNpcPage( $text ) ) {
			return $text;
		}

		$pattern = '/^(\s*\|\s*class\s*=\s*)' .
			'(' .
				'\[\[\s*(?:Shopkeeper|Shopkeep)\s*(?:\|[^\]]+)?\s*\]\]' .
				'|' .
				'(?:Shopkeeper|Shopkeep)' .
			')' .
			'(\s*(?:<!--.*?-->)?)$/mi';

		$newText = preg_replace_callback(
			$pattern,
			function ( $match ) use ( &$changes ) {
				$oldLine = $match[0];
				$newLine = $match[1] . '[[Merchant]]' . $match[3];

				$changes[] = [
					'old' => $oldLine,
					'new' => $newLine
				];

				return $newLine;
			},
			$text
		);

		return $newText;
	}

	private function savePageText( Title $title, $page, string $newText ) {
		$user = User::newSystemUser(
			'ShopkeeperClassFixer',
			[ 'steal' => true ]
		);

		$content = ContentHandler::makeContent( $newText, $title );

		$updater = $page->newPageUpdater( $user );
		$updater->setContent( SlotRecord::MAIN, $content );

		$summary = CommentStoreComment::newUnsavedComment(
			'Change NPC class from Shopkeeper to Merchant'
		);

		return $updater->saveRevision(
			$summary,
			EDIT_UPDATE | EDIT_MINOR
		);
	}

	private function getCandidateTitles(): array {
		$titleOption = $this->getOption( 'title', null );
		$contains = $this->getOption( 'contains', null );
		$limit = (int)$this->getOption( 'limit', 0 );

		$titles = [];
		$services = MediaWikiServices::getInstance();

		if ( $titleOption ) {
			$title = Title::newFromText( $titleOption );

			if ( !$title ) {
				$this->fatalError( 'Invalid title: ' . $titleOption );
			}

			return [ $title ];
		}

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

		return $titles;
	}

	public function execute() {
		$apply = $this->hasOption( 'apply' );
		$services = MediaWikiServices::getInstance();
		$pageFactory = $services->getWikiPageFactory();

		$titles = $this->getCandidateTitles();

		$seen = 0;
		$npcPages = 0;
		$changed = 0;
		$saved = 0;
		$skipped = 0;

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

			if ( !$this->pageLooksLikeNpcPage( $text ) ) {
				$skipped++;
				continue;
			}

			$npcPages++;

			$changes = [];
			$newText = $this->repairText( $text, $changes );

			if ( $newText === $text || count( $changes ) === 0 ) {
				$skipped++;
				continue;
			}

			$changed++;

			$this->output( "CHANGE: " . $title->getPrefixedText() . "\n" );

			foreach ( $changes as $change ) {
				$this->output( "- " . $change['old'] . "\n" );
				$this->output( "+ " . $change['new'] . "\n" );
			}

			$this->output( "\n" );

			if ( $apply ) {
				$status = $this->savePageText( $title, $page, $newText );

				if ( $status && method_exists( $status, 'isOK' ) && !$status->isOK() ) {
					$this->output( "  ERROR saving: " . $title->getPrefixedText() . "\n" );
					continue;
				}

				$saved++;
			}
		}

		$this->output( "Done.\n" );
		$this->output( "Pages scanned: {$seen}\n" );
		$this->output( "NPC-style pages scanned: {$npcPages}\n" );
		$this->output( "Pages changed: {$changed}\n" );
		$this->output( "Pages saved:   {$saved}\n" );
		$this->output( "Pages skipped: {$skipped}\n" );

		if ( !$apply ) {
			$this->output( "\nDry run only. Re-run with --apply to save changes.\n" );
		}
	}
}

$maintClass = FixShopkeeperClass::class;
require_once RUN_MAINTENANCE_IF_MAIN;