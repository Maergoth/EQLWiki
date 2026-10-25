<?php
/** Unsaved parser integration. No page, link-table, purge or upload writes. */
declare( strict_types = 1 );

use MediaWiki\Content\WikitextContent;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class EQLTestNpcLootMediaWiki extends Maintenance {
	private int $checks = 0;
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Parse synthetic reverse NPC loot in memory; never save wiki pages.' );
		$this->addOption( 'output', 'Optional private synthetic parsed HTML file for inspection.', false, true );
	}
	private function check( bool $condition, string $message ): void {
		if ( !$condition ) { throw new RuntimeException( $message ); }
		$this->checks++;
	}
	public function execute() {
		// An absolute feature-file include allows the installed primary wiki runtime
		// to test this worktree without changing its LocalSettings or source files.
		require_once __DIR__ . '/../extensions/DynamicZoneList/NpcLootWikitext.php';
		require_once __DIR__ . '/../extensions/DynamicZoneList/NpcLootRenderer.php';
		$hooks = $this->getServiceContainer()->getHookContainer();
		$hooks->register( 'ParserBeforeInternalParse', [ EqlNpcLootRenderer::class, 'onParserBeforeInternalParse' ] );
		$sources = [
			'Template:Namedmobpage' => '<div class="synthetic-npc">{{{name|}}}</div>' .
				"\n<div class=\"eql-mobpage-loot\"><h2>{{#if:{{{common_loot|}}}|Unique Loot|Known Loot}}</h2>\n{{{known_loot|<ul><li>None</li></ul>}}}\n</div>\n" .
				"{{#if:{{{common_loot|}}}|<div class=\"eql-mobpage-common-loot\"><h2>Common Loot</h2>\n{{{common_loot}}}\n</div>}}",
			'Template:Itempage' => '{{#ifeq:{{FULLPAGENAME}}|{{{itemname}}}|<div class="synthetic-item-page"><h2>Drops From</h2>{{{dropsfrom|}}}</div>|<span class="ih">[[{{{itemname}}}]]</span>}}',
			'EQL synthetic reverse relic' => '<onlyinclude>{{Itempage|itemname=EQL synthetic reverse relic}}</onlyinclude>',
			'EQL synthetic authored relic' => '<onlyinclude>{{Itempage|itemname=EQL synthetic authored relic}}</onlyinclude>',
			'EQL synthetic common relic' => '<onlyinclude>{{Itempage|itemname=EQL synthetic common relic}}</onlyinclude>',
			'EQL synthetic shared relic' => '<onlyinclude>{{Itempage|itemname=EQL synthetic shared relic}}</onlyinclude>',
			'EQL synthetic normal relic' => '<onlyinclude>{{Itempage|itemname=EQL synthetic normal relic}}</onlyinclude>',
			'EQL synthetic raid relic' => '<onlyinclude>{{Itempage|itemname=EQL synthetic raid relic}}</onlyinclude>'
		];
		$npcSource = "Lead text\n{{Namedmobpage|name=EQL synthetic Drolvarg|zone=[[EQL synthetic cavern]]|known_loot=<ul><li>{{:EQL synthetic authored relic}}</li></ul>|common_loot=<ul><li>{{:EQL synthetic common relic}}</li></ul>}}\n== Notes ==\nAuthored notes remain unchanged.";
		$sources['EQL synthetic Drolvarg'] = $npcSource;
		$hooks->register( 'BeforeParserFetchTemplateRevisionRecord', static function (
			?LinkTarget $context, LinkTarget $title, bool &$skip, ?RevisionRecord &$revision
		) use ( $sources ) {
			$key = str_replace( '_', ' ', $title->getPrefixedText() );
			if ( isset( $sources[$key] ) ) {
				$revision = new MutableRevisionRecord( Title::castFromLinkTarget( $title ) );
				$revision->setContent( SlotRecord::MAIN, new WikitextContent( $sources[$key] ) );
			}
		} );
		$lookup = new class {
			public array $calls = [];
			public bool $empty = false;
			public ?array $items = null;
			public function find( Title $title, array $analysis ): array {
				$this->calls[] = [ $title->getPrefixedText(), $analysis ];
				return [ 'items' => $this->empty ? [] : ( $this->items ?? [ [
					'title' => 'EQL synthetic reverse relic', 'contexts' => [
						[ 'zone' => 'EQL synthetic cavern', 'instance' => 'normal' ],
						[ 'zone' => 'EQL synthetic cavern', 'instance' => 'raid' ]
					]
				] ] ), 'complete' => true, 'warnings' => [] ];
			}
		};
		EqlNpcLootRenderer::$lookupFactory = static fn () => $lookup;
		$factory = $this->getServiceContainer()->getParserFactory();
		$output = $factory->create()->parse( $npcSource, Title::newFromText( 'EQL synthetic Drolvarg' ), ParserOptions::newFromAnon() );
		$html = $output->getRawText();
		$this->check( count( $lookup->calls ) === 1, 'Normal top-level parsing must perform one reverse-source lookup.' );
		$this->check( in_array( 'EQL synthetic authored relic', $lookup->calls[0][1]['authoredItems'], true ), 'Authored template transclusions must enter the deduplication analysis.' );
		$this->check( $output->getCacheExpiry() <= 300, 'Actual ParserOutput cache expiry must be bounded.' );
		$this->check( $output->getExtensionData( EqlNpcLootRenderer::MARKER ) === true, 'Actual parser output must carry the downstream CDN marker.' );
		$this->check( !str_contains( $html, '{{:' ) && !str_contains( $html, '{{{' ), 'Generated item transclusions and template parameters must fully expand.' );
		$this->check( !preg_match( '/class="[^"]*(?:error|scribunto-error)/i', $html ), 'Synthetic parser output must not contain expansion errors.' );
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $document );
		$class = static fn ( string $name ): string => 'contains(concat(" ",normalize-space(@class)," ")," ' . $name . ' ")';
		$panels = $xpath->query( '//*[' . $class( 'eql-mobpage-loot' ) . ']' );
		$this->check( $panels->length === 1, 'Generated loot must use the single native NPC loot card.' );
		$panel = $panels[0];
		$this->check( $xpath->query( './/li[' . $class( 'eql-recorded-loot-item' ) . ']', $panel )->length === 1, 'The generated row marker must live inside the existing native card, including when its authored outer list is reused.' );
		$this->check( $xpath->query( './/span[' . $class( 'ih' ) . ']/a[normalize-space(text())="EQL synthetic reverse relic"]', $panel )->length === 1, 'Generated item must be a descendant of the native loot card and retain the span.ih hover link.' );
		$this->check( $xpath->query( './/span[' . $class( 'ih' ) . ']/a[normalize-space(text())="EQL synthetic authored relic"]', $panel )->length === 1, 'Native loot must retain its original item once.' );
		$this->check( $xpath->query( './/h2[normalize-space(text())="Unique Loot"]', $panel )->length === 1, 'An existing common_loot field must retain the template\'s Unique Loot heading.' );
		$common = $xpath->query( '//*[' . $class( 'eql-mobpage-common-loot' ) . ']' );
		$this->check( $common->length === 1 && str_contains( $common[0]->textContent, 'Common Loot' ) && str_contains( $common[0]->textContent, 'EQL synthetic common relic' ), 'Authored Common Loot must remain in its native separate card.' );
		$this->check( !str_contains( $html, 'Additional recorded drops' ), 'Do not add a separate recorded-loot heading.' );
		$this->check( $xpath->query( './/*[' . $class( 'mw-editsection' ) . ']', $panel )->length === 0, 'Generated content must not advertise nonexistent source-section edits.' );
		$generatedRow = $xpath->query( './/li[' . $class( 'eql-recorded-loot-item' ) . ']', $panel )[0];
		$this->check( trim( $generatedRow->textContent ) === 'EQL synthetic reverse relic' && $xpath->query( './/a', $generatedRow )->length === 1, 'Generated native loot rows must display only the item name with its normal wiki hover link.' );
		$this->check( $xpath->query( './/small', $generatedRow )->length === 0 && !str_contains( $generatedRow->textContent, 'Normal Zone' ) && !str_contains( $generatedRow->textContent, 'Raid Zone' ) && !str_contains( $generatedRow->textContent, 'EQL synthetic cavern' ), 'Zone and instance context must not add visible clutter to the generated item row.' );
		$this->check( substr_count( $panel->textContent, 'EQL synthetic authored relic' ) === 1, 'The native card must not repeat its authored loot.' );
		$dependencies = array_map( static fn ( array $link ) => str_replace( '_', ' ', Title::newFromLinkTarget( $link['link'] )->getPrefixedText() ), $output->getLinkList( ParserOutputLinkTypes::TEMPLATE ) );
		$this->check( in_array( 'EQL synthetic reverse relic', $dependencies, true ), 'Generated normal item transclusions must register item dependencies with core.' );
		$this->check( in_array( 'Template:Itempage', $dependencies, true ), 'Generated item transclusions must retain their template dependencies.' );
		$beforePst = count( $lookup->calls );
		$anonymous = $this->getServiceContainer()->getUserFactory()->newAnonymous();
		$pst = $factory->create()->preSaveTransform( $npcSource, Title::newFromText( 'EQL synthetic Drolvarg' ), $anonymous, ParserOptions::newFromAnon() );
		$this->check( $pst === $npcSource && count( $lookup->calls ) === $beforePst, 'Saving an NPC must preserve its original stored wikicode and never persist generated loot.' );
		$ordinary = $factory->create()->parse( 'Synthetic zone article: {{:EQL synthetic Drolvarg}}', Title::newFromText( 'EQL synthetic ordinary zone' ), ParserOptions::newFromAnon() );
		$this->check( !str_contains( $ordinary->getRawText(), 'eql-recorded-loot' ) && count( $lookup->calls ) === 1, 'Transcluding an NPC on an ordinary root article must not append a reverse-loot panel.' );
		$pageFactory = $this->getServiceContainer()->getWikiPageFactory();
		$npcPage = $pageFactory->newFromTitle( Title::newFromText( 'EQL synthetic Drolvarg' ) );
		$cacheOptions = ParserOptions::newFromAnon();
		$oldNpcOutput = clone $output;
		$oldNpcOutput->setExtensionData( EqlNpcLootRenderer::GUARD, null );
		$oldNpcOutput->setExtensionData( EqlNpcLootRenderer::MARKER, null );
		$this->check( EqlNpcLootRenderer::onRejectParserCacheValue( $oldNpcOutput, $npcPage, $cacheOptions ) === false, 'A pre-feature cached NPC dependency must force one refresh after deployment.' );
		$this->check( EqlNpcLootRenderer::onRejectParserCacheValue( $output, $npcPage, $cacheOptions ) !== false, 'Actual newly scanned NPC ParserOutput must be accepted for future cache hits.' );
		$zonePage = $pageFactory->newFromTitle( Title::newFromText( 'EQL synthetic ordinary zone' ) );
		$oldZoneOutput = clone $ordinary;
		$oldZoneOutput->setExtensionData( EqlNpcLootRenderer::GUARD, null );
		$this->check( EqlNpcLootRenderer::onRejectParserCacheValue( $oldZoneOutput, $zonePage, $cacheOptions ) === false, 'Old pages transcluding an NPC may refresh once through their real template dependency.' );
		$this->check( EqlNpcLootRenderer::onRejectParserCacheValue( $ordinary, $zonePage, $cacheOptions ) !== false, 'A scanned ordinary root article must not be repeatedly invalidated by nested NPC dependencies.' );
		$noneSource = '{{Namedmobpage|name=EQL synthetic empty Drolvarg|zone=[[EQL synthetic cavern]]|known_loot=<ul><li>None</li></ul>}}';
		$none = $factory->create()->parse( $noneSource, Title::newFromText( 'EQL synthetic empty Drolvarg' ), ParserOptions::newFromAnon() );
		$this->check( str_contains( $none->getRawText(), 'Known Loot' ) && str_contains( $none->getRawText(), 'EQL synthetic reverse relic' ) && !preg_match( '/>\s*None\s*</', $none->getRawText() ), 'A native Known Loot card must replace an exact None placeholder when adding its first drop.' );
		$lookup->items = [ [ 'title' => 'EQL synthetic shared relic', 'lootKind' => 'common',
			'contexts' => [ [ 'zone' => 'EQL synthetic cavern', 'instance' => 'normal' ] ] ] ];
		$sharedOutput = $factory->create()->parse( $npcSource, Title::newFromText( 'EQL synthetic common Drolvarg' ), ParserOptions::newFromAnon() );
		$sharedDocument = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$sharedDocument->loadHTML( '<?xml encoding="UTF-8">' . $sharedOutput->getRawText() );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$sharedXpath = new DOMXPath( $sharedDocument );
		$sharedKnown = $sharedXpath->query( '//*[' . $class( 'eql-mobpage-loot' ) . ']' )[0];
		$sharedCommon = $sharedXpath->query( '//*[' . $class( 'eql-mobpage-common-loot' ) . ']' )[0];
		$this->check( !str_contains( $sharedKnown->textContent, 'EQL synthetic shared relic' ) && str_contains( $sharedCommon->textContent, 'EQL synthetic shared relic' ) && str_contains( $sharedCommon->textContent, 'Common Loot' ), 'A generated common item must appear inside the native Common Loot card rather than Unique Loot.' );
		$sharedRow = $sharedXpath->query( './/li[' . $class( 'eql-recorded-loot-item' ) . ']', $sharedCommon )[0];
		$this->check( trim( $sharedRow->textContent ) === 'EQL synthetic shared relic' && $sharedXpath->query( './/span[' . $class( 'ih' ) . ']/a', $sharedRow )->length === 1 && $sharedXpath->query( './/small', $sharedRow )->length === 0, 'Generated Common Loot must keep its normal wiki hover and item-name-only display.' );
		$this->check( str_contains( $sharedKnown->textContent, 'EQL synthetic authored relic' ) && str_contains( $sharedKnown->textContent, 'Unique Loot' ) && str_contains( $sharedCommon->textContent, 'EQL synthetic common relic' ) && str_contains( $sharedOutput->getRawText(), 'Authored notes remain unchanged.' ), 'Classifying generated common loot must preserve authored unique/common rows, headings and notes.' );
		$lookup->items = [
			[ 'title' => 'EQL synthetic normal relic', 'contexts' => [ [ 'zone' => 'EQL synthetic cavern', 'instance' => 'normal' ] ] ],
			[ 'title' => 'EQL synthetic raid relic', 'contexts' => [ [ 'zone' => 'EQL synthetic cavern', 'instance' => 'raid' ] ] ]
		];
		$splitSource = '{{Namedmobpage|name=EQL synthetic Drolvarg|zone=[[EQL synthetic cavern]]|known_loot=<ul><li>None</li></ul>}}' .
			"\n== Raid Instance ==\n" . '{{Namedmobpage|name=EQL synthetic Drolvarg|zone=[[EQL synthetic cavern]]|known_loot=<ul><li>None</li></ul>}}';
		$split = $factory->create()->parse( $splitSource, Title::newFromText( 'EQL synthetic split Drolvarg' ), ParserOptions::newFromAnon() );
		$splitDocument = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$splitDocument->loadHTML( '<?xml encoding="UTF-8">' . $split->getRawText() );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$splitXpath = new DOMXPath( $splitDocument );
		$splitCards = $splitXpath->query( '//*[' . $class( 'eql-mobpage-loot' ) . ']' );
		$this->check( $splitCards->length === 2 && str_contains( $splitCards[0]->textContent, 'EQL synthetic normal relic' ) && !str_contains( $splitCards[0]->textContent, 'EQL synthetic raid relic' ) && str_contains( $splitCards[1]->textContent, 'EQL synthetic raid relic' ) && !str_contains( $splitCards[1]->textContent, 'EQL synthetic normal relic' ), 'Normal and raid generated drops must stay inside their respective native loot cards.' );
		$lookup->empty = true;
		$empty = $factory->create()->parse( $npcSource, Title::newFromText( 'EQL synthetic empty Drolvarg' ), ParserOptions::newFromAnon() );
		$this->check( !str_contains( $empty->getRawText(), 'eql-recorded-loot' ) && $empty->getCacheExpiry() <= 300, 'An empty reverse result must hide the panel while retaining a short cache lifetime for newly linked items.' );
		$itemLookup = new class {
			public int $calls = 0;
			public function find( Title $title, array $analysis ): array {
				$this->calls++;
				return [ 'sources' => [
					[ 'npc' => 'EQL synthetic normal guardian', 'zone' => 'EQL synthetic cavern', 'instance' => 'normal', 'lootKind' => 'known' ],
					[ 'npc' => 'EQL synthetic raid guardian', 'zone' => 'EQL synthetic cavern', 'instance' => 'raid', 'lootKind' => 'common' ]
				], 'complete' => true, 'warnings' => [] ];
			}
		};
		EqlNpcLootRenderer::$itemLookupFactory = static fn () => $itemLookup;
		$itemTitle = Title::newFromText( 'EQL synthetic later relic' );
		$itemSource = '<onlyinclude>{{Itempage|itemname=EQL synthetic later relic|statsblock=HP: 1|dropsfrom=}}</onlyinclude>';
		$itemOutput = $factory->create()->parse( $itemSource, $itemTitle, ParserOptions::newFromAnon() );
		$itemHtml = $itemOutput->getRawText();
		$this->check( $itemLookup->calls === 1 && str_contains( $itemHtml, 'Drops From' ) && str_contains( $itemHtml, 'EQL synthetic normal guardian' ) && str_contains( $itemHtml, 'EQL synthetic raid guardian' ), 'A later-created item must display existing NPC loot relationships inside native Drops From.' );
		$this->check( str_contains( $itemHtml, 'Raid Instance' ) && $itemOutput->getCacheExpiry() <= 300, 'Reciprocal item sources must retain normal/raid scope and a bounded fallback cache.' );
		$itemPst = $factory->create()->preSaveTransform( $itemSource, $itemTitle, $anonymous, ParserOptions::newFromAnon() );
		$this->check( $itemPst === $itemSource && $itemLookup->calls === 1, 'Reciprocal item sources must never be persisted by the pre-save transform.' );
		$oldItemOutput = clone $itemOutput;
		$oldItemOutput->setExtensionData( EqlNpcLootRenderer::GUARD, null );
		$this->check( EqlNpcLootRenderer::onRejectParserCacheValue( $oldItemOutput, $pageFactory->newFromTitle( $itemTitle ), $cacheOptions ) === false, 'Old item-containing parser cache entries must refresh once for reciprocal sources.' );
		$this->check( EqlNpcLootRenderer::onRejectParserCacheValue( $itemOutput, $pageFactory->newFromTitle( $itemTitle ), $cacheOptions ) !== false, 'Fresh reciprocal item output must remain cacheable.' );
		$groupedSource = '<onlyinclude>{{Itempage|itemname=EQL synthetic later relic|statsblock=HP: 1|dropsfrom=' .
			"\n[[EQL synthetic cavern]]\n* [[EQL synthetic authored guardian]]\n=== Raid Instance ===\n[[EQL synthetic cavern]]\n* [[EQL synthetic authored raid guardian]]\n}}</onlyinclude>";
		$groupedOutput = $factory->create()->parse( $groupedSource, $itemTitle, ParserOptions::newFromAnon() );
		$groupedHtml = $groupedOutput->getRawText();
		$this->check( substr_count( $groupedHtml, '>EQL synthetic cavern</a>' ) === 2 && substr_count( $groupedHtml, 'Raid Instance' ) >= 1 && !str_contains( $groupedHtml, 'Normal Instance' ), 'Existing item zone lists and raid boundary must be reused without duplicate instance/zone blocks.' );
		$groupedDocument = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$groupedDocument->loadHTML( '<?xml encoding="UTF-8">' . $groupedHtml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$groupedXpath = new DOMXPath( $groupedDocument );
		$this->check( $groupedXpath->query( '//li[not(normalize-space())]' )->length === 0, 'Reciprocal source insertion must not create empty list bullets in an actual parser result.' );
		$beforeVarious = $itemLookup->calls;
		$variousSource = str_replace( [ 'dropsfrom=', 'HP: 1' ], [ 'dropsfrom=Various zones ', 'AC: 1<br>DMG: 10' ], $groupedSource );
		$variousOutput = $factory->create()->parse( $variousSource, $itemTitle, ParserOptions::newFromAnon() );
		$this->check( $itemLookup->calls === $beforeVarious + 1 && str_contains( $variousOutput->getRawText(), 'Various zones' ) && !str_contains( $variousOutput->getRawText(), 'EQL synthetic normal guardian' ), 'Authored Various pages must render their own lists without generated entries or warning clutter.' );

		$bonusOutput = $factory->create()->parse( str_replace( 'dropsfrom=Various zones ', 'notes=Various unrelated notes|dropsfrom=', $variousSource ), $itemTitle, ParserOptions::newFromAnon() );
		$this->check( $itemLookup->calls === $beforeVarious + 2 && str_contains( $bonusOutput->getRawText(), 'EQL synthetic normal guardian' ), 'Various outside the affected drop-source field does not block exact reciprocal sources.' );

		EqlNpcLootRenderer::$itemLookupFactory = null;
		if ( $this->hasOption( 'output' ) ) {
			$this->check( file_put_contents( $this->getOption( 'output' ), $html ) === strlen( $html ), 'Could not write the optional private synthetic HTML.' );
		}
		EqlNpcLootRenderer::$lookupFactory = null;
		$this->output( 'NPC reverse loot unsaved MediaWiki parse: ' . $this->checks . " checks passed; no pages saved.\n" );
	}
}
return EQLTestNpcLootMediaWiki::class;
