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
			'Template:Namedmobpage' => '<div class="synthetic-npc">{{{name|}}}</div>' . "\n== Unique Loot ==\n{{{known_loot|}}}\n== Common Loot ==\n{{{common_loot|}}}",
			'Template:Itempage' => '<span class="ih">[[{{{itemname}}}]]</span>',
			'EQL synthetic reverse relic' => '<onlyinclude>{{Itempage|itemname=EQL synthetic reverse relic}}</onlyinclude>',
			'EQL synthetic authored relic' => '<onlyinclude>{{Itempage|itemname=EQL synthetic authored relic}}</onlyinclude>'
		];
		$npcSource = "Lead text\n{{Namedmobpage|name=EQL synthetic Drolvarg|zone=[[EQL synthetic cavern]]|known_loot={{:EQL synthetic authored relic}}}}\n== Notes ==\nAuthored notes remain unchanged.";
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
			public function find( Title $title, array $analysis ): array {
				$this->calls[] = [ $title->getPrefixedText(), $analysis ];
				return [ 'items' => $this->empty ? [] : [ [
					'title' => 'EQL synthetic reverse relic', 'contexts' => [
						[ 'zone' => 'EQL synthetic cavern', 'instance' => 'normal' ],
						[ 'zone' => 'EQL synthetic cavern', 'instance' => 'raid' ]
					]
				] ], 'complete' => true, 'warnings' => [] ];
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
		$panels = $xpath->query( '//*[' . $class( 'eql-recorded-loot' ) . ']' );
		$this->check( $panels->length === 1, 'Exactly one generated loot panel must be present.' );
		$panel = $panels[0];
		$this->check( $xpath->query( './/span[' . $class( 'ih' ) . ']/a', $panel )->length === 1, 'Normal item expansion must retain the span.ih link consumed by the existing hover component.' );
		$this->check( $xpath->query( './/*[' . $class( 'mw-editsection' ) . ']', $panel )->length === 0, 'Generated content must not advertise nonexistent source-section edits.' );
		$this->check( str_contains( $panel->textContent, 'Normal Zone' ) && str_contains( $panel->textContent, 'Raid Zone' ), 'Actual parsing must preserve normal and raid source context.' );
		$this->check( $xpath->query( './/small//a[normalize-space(text())="EQL synthetic cavern"]', $panel )->length === 2 && !str_contains( $panel->textContent, '<a href=' ), 'Source zones must be working wiki links, not escaped HTML anchors.' );
		$this->check( !str_contains( $panel->textContent, 'EQL synthetic authored relic' ), 'The additional panel must not repeat already authored loot.' );
		$dependencies = array_map( static fn ( array $link ) => str_replace( '_', ' ', Title::newFromLinkTarget( $link['link'] )->getPrefixedText() ), $output->getLinkList( ParserOutputLinkTypes::TEMPLATE ) );
		$this->check( in_array( 'EQL synthetic reverse relic', $dependencies, true ), 'Generated normal item transclusions must register item dependencies with core.' );
		$this->check( in_array( 'Template:Itempage', $dependencies, true ), 'Generated item transclusions must retain their template dependencies.' );
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
		$lookup->empty = true;
		$empty = $factory->create()->parse( $npcSource, Title::newFromText( 'EQL synthetic empty Drolvarg' ), ParserOptions::newFromAnon() );
		$this->check( !str_contains( $empty->getRawText(), 'eql-recorded-loot' ) && $empty->getCacheExpiry() <= 300, 'An empty reverse result must hide the panel while retaining a short cache lifetime for newly linked items.' );
		if ( $this->hasOption( 'output' ) ) {
			$this->check( file_put_contents( $this->getOption( 'output' ), $html ) === strlen( $html ), 'Could not write the optional private synthetic HTML.' );
		}
		EqlNpcLootRenderer::$lookupFactory = null;
		$this->output( 'NPC reverse loot unsaved MediaWiki parse: ' . $this->checks . " checks passed; no pages saved.\n" );
	}
}
return EQLTestNpcLootMediaWiki::class;
