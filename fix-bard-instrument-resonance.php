<?php

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

require_once __DIR__ . '/maintenance/Maintenance.php';

class FixBardInstrumentResonance extends Maintenance {

	private array $instrumentMap = [
		/* Percussion */
		'Drums of the Beast' => [
			'Percussion' => 26
		],
		'Selo`s Drums of the March' => [
			'Percussion' => 24
		],
		'Walrus Skin Drum' => [
			'Percussion' => 23
		],
		'Sharkskin Drum' => [
			'Percussion' => 22
		],
		'Nostrolo Tambourine' => [
			'Percussion' => 22
		],
		'Mistmoore Battle Drums' => [
			'Percussion' => 21
		],
		"Mahlin's Mystical Bongos" => [
			'Percussion' => 21
		],
		'Hand Drum' => [
			'Percussion' => 18
		],

		/* String */
		"Lyran's Mystical Lute" => [
			'String' => 25
		],
		'Kelin`s Seven Stringed Lute' => [
			'String' => 24
		],
		"Lyendlln's Lute" => [
			'String' => 24
		],
		'Lute of the Howler' => [
			'String' => 22
		],
		'Lute of the Gypsy Princess' => [
			'String' => 21
		],
		'Gypsy Lute' => [
			'String' => 21
		],
		'Mystical Lute' => [
			'String' => 21
		],
		'Mandolin' => [
			'String' => 20
		],
		'Lute' => [
			'String' => 20
		],

		/* Brass */
		'Immaculate Shell Horn' => [
			'Brass' => 24
		],
		"Denon's Horn of Disaster" => [
			'Brass' => 24
		],
		'McVaxius` Horn of War' => [
			'Brass' => 23
		],
		"Verlekarnorm's Horn of Disaster" => [
			'Brass' => 22
		],
		'Efreeti War Horn' => [
			'Brass' => 22
		],
		'Conch Shell Horn' => [
			'Brass' => 21
		],
		'Horn' => [
			'Brass' => 20
		],
		'Alluring Horn' => [
			'Brass' => 18
		],

		/* Wind */
		'Flute of the Sacred Glade' => [
			'Wind' => 25
		],
		'Lyssa`s Darkwood Piccolo' => [
			'Wind' => 24
		],
		"Ervaj's Flute of Flight" => [
			'Wind' => 22
		],
		"Agilmente's Flute of Flight" => [
			'Wind' => 22
		],
		'Unicorn Horn' => [
			'Wind' => 22
		],
		'Faun Flute' => [
			'Wind' => 21
		],
		'Brahhms Horn' => [
			'Wind' => 21
		],
		'Scorpion Pincer' => [
			'Wind' => 20
		],
		'Minotaur Horn' => [
			'Wind' => 20
		],
		'Wooden Flute' => [
			'Wind' => 18
		],
		"Broken Minotaur Lord's Horn" => [
			'Wind' => 10
		],
		'Crude Wooden Flute' => [
			'Wind' => 10
		],
		"Lianna's Flute" => [
			'Wind' => 10
		],

		/* Multi-instrument */
		'Singing Short Sword' => [
			'Percussion' => 18,
			'String' => 18,
			'Brass' => 18,
			'Wind' => 18
		]
	];

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Updates bard instrument stat labels from Instrument/Instruments to Resonance and subtracts 10 from the listed modifier.'
		);

		$this->addOption(
			'apply',
			'Actually save changes. Without this, the script only reports what it would change.',
			false,
			false
		);

		$this->addOption(
			'title',
			'Update one exact page title only.',
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

	private function normalizeKindFromLabel( string $label ): string {
		$label = strtolower( trim( $label ) );

		if ( $label === 'percussion' ) {
			return 'Percussion';
		}

		if ( $label === 'string' || $label === 'stringed' ) {
			return 'String';
		}

		if ( $label === 'brass' ) {
			return 'Brass';
		}

		if ( $label === 'wind' || $label === 'woodwind' ) {
			return 'Wind';
		}

		return '';
	}

	private function resonanceLabel( string $kind ): string {
		if ( $kind === 'String' ) {
			return 'String Resonance';
		}

		if ( $kind === 'Wind' ) {
			return 'Wind Resonance';
		}

		return $kind . ' Resonance';
	}

	private function repairTextForTitle( string $titleText, string $text, array &$changes ): string {
		$changes = [];

		if ( !isset( $this->instrumentMap[$titleText] ) ) {
			return $text;
		}

		$kindMap = $this->instrumentMap[$titleText];

		/*
		 * Match examples:
		 *   Percussion Instrument: 26<br>
		 *   Percussion Instruments: 26<br>
		 *   Stringed Instruments: 21
		 *   Brass Instrument: 18
		 *   Wind Instrument
		 *   Wind Instruments: 22
		 */
		$pattern = '/(^|\n)([ \t]*)(Percussion|Stringed|String|Brass|Wind|Woodwind)\s+Instrument(?:s)?\s*(?::\s*(\d+))?([^\n]*)/i';

		$newText = preg_replace_callback(
			$pattern,
			function ( $match ) use ( $titleText, $kindMap, &$changes ) {
				$linePrefix = $match[1];
				$indent = $match[2];
				$oldLabel = $match[3];
				$oldNumber = $match[4] ?? '';
				$tail = $match[5] ?? '';

				$kind = $this->normalizeKindFromLabel( $oldLabel );

				if ( $kind === '' || !isset( $kindMap[$kind] ) ) {
					return $match[0];
				}

				$oldMod = (int)$kindMap[$kind];
				$newMod = max( 0, $oldMod - 10 );
				$newLabel = $this->resonanceLabel( $kind );
				$newLine = $indent . $newLabel . ': ' . $newMod . $tail;

				$changes[] = [
					'kind' => $kind,
					'old' => trim( substr( $match[0], strlen( $linePrefix ) ) ),
					'new' => trim( $newLine )
				];

				return $linePrefix . $newLine;
			},
			$text
		);

		return $newText;
	}

	private function savePageText( Title $title, $page, string $newText ) {
		$user = User::newSystemUser(
			'BardInstrumentResonanceFixer',
			[ 'steal' => true ]
		);

		$content = ContentHandler::makeContent( $newText, $title );

		$updater = $page->newPageUpdater( $user );
		$updater->setContent( SlotRecord::MAIN, $content );

		$summary = CommentStoreComment::newUnsavedComment(
			'Update bard instrument modifiers to Resonance terminology'
		);

		return $updater->saveRevision(
			$summary,
			EDIT_UPDATE | EDIT_MINOR
		);
	}

	private function getTitlesToProcess(): array {
		$titleOption = $this->getOption( 'title', null );

		if ( $titleOption ) {
			$title = Title::newFromText( $titleOption );

			if ( !$title ) {
				$this->fatalError( 'Invalid title: ' . $titleOption );
			}

			return [ $title ];
		}

		$titles = [];

		foreach ( array_keys( $this->instrumentMap ) as $pageName ) {
			$title = Title::newFromText( $pageName );

			if ( $title ) {
				$titles[] = $title;
			}
		}

		return $titles;
	}

	public function execute() {
		$apply = $this->hasOption( 'apply' );

		$services = MediaWikiServices::getInstance();
		$pageFactory = $services->getWikiPageFactory();

		$titles = $this->getTitlesToProcess();

		$seen = 0;
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
				$this->output( "SKIP: " . $title->getPrefixedText() . " — empty or unreadable page text\n" );
				$skipped++;
				continue;
			}

			$changes = [];
			$newText = $this->repairTextForTitle( $title->getText(), $text, $changes );

			if ( $newText === $text || count( $changes ) === 0 ) {
				$this->output( "SKIP: " . $title->getPrefixedText() . " — no matching instrument stat line found\n" );
				$skipped++;
				continue;
			}

			$changed++;

			$this->output( "CHANGE: " . $title->getPrefixedText() . "\n" );

			foreach ( $changes as $change ) {
				$this->output( "  [" . $change['kind'] . "]\n" );
				$this->output( "  - " . $change['old'] . "\n" );
				$this->output( "  + " . $change['new'] . "\n" );
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
		$this->output( "Pages changed: {$changed}\n" );
		$this->output( "Pages saved:   {$saved}\n" );
		$this->output( "Pages skipped: {$skipped}\n" );

		if ( !$apply ) {
			$this->output( "\nDry run only. Re-run with --apply to save changes.\n" );
		}
	}
}

$maintClass = FixBardInstrumentResonance::class;
require_once RUN_MAINTENANCE_IF_MAIN;