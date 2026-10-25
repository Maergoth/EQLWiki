<?php
/** Synthetic hook/renderer contracts; no MediaWiki bootstrap, database or edits. */
declare( strict_types = 1 );

const NS_MAIN = 0;
const NS_TEMPLATE = 10;

enum SyntheticNpcLootLinkTypes: string { case TEMPLATE = 'template'; }
class_alias( SyntheticNpcLootLinkTypes::class, 'MediaWiki\\Parser\\ParserOutputLinkTypes' );

final class SyntheticNpcLootTitle {
	public function __construct( private string $text, private int $namespace = NS_MAIN ) {}
	public function getNamespace(): int { return $this->namespace; }
	public function inNamespace( int $namespace ): bool { return $this->namespace === $namespace; }
	public function getText(): string { return $this->text; }
	public function getPrefixedText(): string { return $this->text; }
	public function getDBkey(): string { return str_replace( ' ', '_', $this->text ); }
	public function getPrefixedDBkey(): string { return $this->getDBkey(); }
	public function getLocalURL(): string { return '/index.php?title=' . rawurlencode( $this->getDBkey() ); }
	public function isExternal(): bool { return false; }
	public function getLatestRevID(): int { return 123; }
	public static function newFromText( string $text ): ?self {
		return $text === '' || preg_match( '/[\[\]{}|<>\r\n]/', $text ) ? null : new self( $text );
	}
}
class_alias( SyntheticNpcLootTitle::class, 'MediaWiki\\Title\\Title' );
class_alias( SyntheticNpcLootTitle::class, 'Title' );

final class SyntheticNpcLootOutput {
	public array $data = [];
	public array $links = [];
	public int $ttl = 3600;
	public function getExtensionData( string $key ): mixed { return $this->data[$key] ?? null; }
	public function setExtensionData( string $key, mixed $value ): void { $this->data[$key] = $value; }
	public function updateCacheExpiry( int $ttl ): void { $this->ttl = min( $ttl, $this->ttl ); }
	public function addModules( array|string $modules ): void {}
	public function addModuleStyles( array|string $modules ): void {}
	public function getLinkList( mixed $type, ?int $namespace = null ): array {
		return array_values( array_filter( $this->links, static fn ( array $entry ) => $namespace === null || $entry['link']->getNamespace() === $namespace ) );
	}
}
final class SyntheticNpcLootWikiPage {
	public function __construct( private SyntheticNpcLootTitle $title ) {}
	public function getTitle(): SyntheticNpcLootTitle { return $this->title; }
}
final class SyntheticNpcLootOptions {
	public function __construct( private bool $parsoid = false, private bool $sectionPreview = false ) {}
	public function getUseParsoid(): bool { return $this->parsoid; }
	public function getIsSectionPreview(): bool { return $this->sectionPreview; }
	public function getIsPreview(): bool { return false; }
}
final class SyntheticNpcLootParser {
	public function __construct(
		private SyntheticNpcLootTitle $title,
		private SyntheticNpcLootOptions $options = new SyntheticNpcLootOptions(),
		public SyntheticNpcLootOutput $output = new SyntheticNpcLootOutput()
	) {}
	public function getTitle(): SyntheticNpcLootTitle { return $this->title; }
	public function getPage(): SyntheticNpcLootTitle { return $this->title; }
	public function getOutput(): SyntheticNpcLootOutput { return $this->output; }
	public function getOptions(): SyntheticNpcLootOptions { return $this->options; }
	public function getRevisionId(): int { return 123; }
}
final class SyntheticNpcLootOutputPage {
	public array $properties = [];
	public int $cdnTtl = 86400;
	public function getProperty( string $key ): mixed { return $this->properties[$key] ?? null; }
	public function setProperty( string $key, mixed $value ): void { $this->properties[$key] = $value; }
	public function lowerCdnMaxage( int $ttl ): void { $this->cdnTtl = min( $ttl, $this->cdnTtl ); }
}
final class SyntheticNpcLootLookup {
	public array $calls = [];
	public array $result = [ 'items' => [], 'complete' => true, 'warnings' => [] ];
	public function find( SyntheticNpcLootTitle $title, array $authored ): array {
		$this->calls[] = [ $title->getText(), $authored ];
		return $this->result;
	}
}

require_once __DIR__ . '/../extensions/DynamicZoneList/NpcLootWikitext.php';
require_once __DIR__ . '/../extensions/DynamicZoneList/NpcLootRenderer.php';

function npcLootExpect( bool $condition, string $message ): void {
	if ( !$condition ) { throw new RuntimeException( $message ); }
}

$lookup = new SyntheticNpcLootLookup();
EqlNpcLootRenderer::$lookupFactory = static fn () => $lookup;
$npcSource = "Lead text\n{{Namedmobpage|name=A Synthetic Drolvarg|known_loot=* [[Synthetic Authored Relic]]}}\n== Notes ==\nKeep this source intact.";
$lookup->result = [ 'items' => [ [ 'title' => 'Synthetic Reverse Relic', 'contexts' => [
	[ 'zone' => 'Synthetic Cavern', 'instance' => 'normal' ],
	[ 'zone' => 'Synthetic Cavern', 'instance' => 'raid' ]
] ] ], 'complete' => true, 'warnings' => [] ];
$parser = new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'A Synthetic Drolvarg' ) );
$text = $npcSource;
EqlNpcLootRenderer::onParserBeforeInternalParse( $parser, $text, null );
npcLootExpect( str_starts_with( $text, "Lead text\n{{Namedmobpage|name=A Synthetic Drolvarg|known_loot=* [[Synthetic Authored Relic]]" ) && str_ends_with( $text, "\n== Notes ==\nKeep this source intact." ), 'Only the native loot field may change in parse input; surrounding source must remain intact.' );
npcLootExpect( in_array( 'Synthetic Reverse Relic', EqlNpcLootWikitext::analyseNpcPage( $text )['authoredItems'], true ), 'Generated item transclusions must be inside the native loot field.' );
npcLootExpect( !str_contains( strtolower( $text ), 'additional recorded drops' ), 'Do not create a separate loot section outside the native card.' );
npcLootExpect( substr_count( $text, '{{:Synthetic_Reverse_Relic}}' ) === 1, 'Use one normal item transclusion, retaining template dependencies and hovers.' );
$entries = EqlNpcLootRenderer::entries( $lookup->result );
npcLootExpect( $entries[0]['contexts'] === $lookup->result['items'][0]['contexts'], 'Keep normal/raid and zone context internally for native card placement.' );
npcLootExpect( !str_contains( $text, 'Normal Zone' ) && !str_contains( $text, 'Raid Zone' ) && !str_contains( $text, 'Synthetic Cavern' ) && !str_contains( $entries[0]['markup'], '<small' ), 'Generated loot rows must display only the item, without visible zone or instance labels.' );
npcLootExpect( str_contains( $text, 'title="From current published item drop sources"' ), 'Label generated rows as current published item data, including when viewing older NPC revisions.' );
npcLootExpect( count( $lookup->calls ) === 1, 'Exactly one lookup should run per root parse.' );
npcLootExpect( in_array( 'Synthetic Authored Relic', $lookup->calls[0][1]['authoredItems'], true ), 'Pass authored loot to the lookup for deduplication.' );
npcLootExpect( $parser->output->ttl <= 300, 'NPC output must have bounded parser cache expiry.' );
npcLootExpect( $parser->output->getExtensionData( EqlNpcLootRenderer::MARKER ) === true, 'Mark eligible NPC output for downstream cache handling.' );
$once = $text;
EqlNpcLootRenderer::onParserBeforeInternalParse( $parser, $text, null );
npcLootExpect( $text === $once && count( $lookup->calls ) === 1, 'Repeated or recursive hooks must not add duplicate loot rows or queries.' );

$projection = EqlNpcLootRenderer::entries( [ 'items' => [ [ 'title' => 'Synthetic Unknown Relic', 'lootKind' => null, 'contexts' => [] ] ] ] );
npcLootExpect( $projection === [], 'Unknown whole-wiki classification cannot render a generated Known/Unique entry.' );
$affectedLookup = new SyntheticNpcLootLookup();
$affectedLookup->result = [ 'sources' => [ [ 'npc' => 'New Guardian', 'zone' => 'Synthetic Vale', 'instance' => 'normal' ] ], 'complete' => true ];
EqlNpcLootRenderer::$itemLookupFactory = static fn () => $affectedLookup;
$broadSource = '{{Itempage|itemname=Synthetic Material|statsblock=HP: 5|dropsfrom=Various zones}}';
EqlNpcLootRenderer::onParserBeforeInternalParse( new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'Synthetic Material' ) ), $broadSource, null );
npcLootExpect( !str_contains( $broadSource, 'New Guardian' ), 'A broad affected source group is not expanded merely because gear has bonus stats.' );
$exactSource = '{{Itempage|itemname=Synthetic Relic|notes=Various unrelated notes|dropsfrom=}}';
EqlNpcLootRenderer::onParserBeforeInternalParse( new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'Synthetic Relic' ) ), $exactSource, null );
npcLootExpect( str_contains( $exactSource, 'New Guardian' ), 'Unrelated page prose does not block an exact affected field.' );
$affectedLookup->result['zoneAliases'] = [ 'Vale Alias' => 'Synthetic Vale', 'Synthetic Vale' => 'Synthetic Vale' ];
$aliasBroad = "{{Itempage|itemname=Synthetic Material|dropsfrom=[[Vale Alias]]\n* Many keepers}}";
EqlNpcLootRenderer::onParserBeforeInternalParse( new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'Synthetic Material' ) ), $aliasBroad, null );
npcLootExpect( !str_contains( $aliasBroad, 'New Guardian' ), 'Canonical zone aliases cannot bypass a broad affected source context.' );
$affectedLookup->result['zoneAliases'] = [ 'Other Vale' => 'Other Vale', 'Synthetic Vale' => 'Synthetic Vale' ];
$otherBroad = "{{Itempage|itemname=Synthetic Material|dropsfrom=[[Other Vale]]\n* Many keepers}}";
EqlNpcLootRenderer::onParserBeforeInternalParse( new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'Synthetic Material' ) ), $otherBroad, null );
npcLootExpect( str_contains( $otherBroad, 'New Guardian' ), 'Current public canonical proofs keep broad policy scoped to its actual zone.' );
EqlNpcLootRenderer::$itemLookupFactory = null;

// The guard must be set before an early return on an ordinary page. Otherwise a
// nested tag parse containing a mob template can pollute unrelated root output.
$plainParser = new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'Synthetic Guide' ) );
$plain = 'An ordinary guide without a mob template.';
EqlNpcLootRenderer::onParserBeforeInternalParse( $plainParser, $plain, null );
$nested = $npcSource;
EqlNpcLootRenderer::onParserBeforeInternalParse( $plainParser, $nested, null );
npcLootExpect( $nested === $npcSource && count( $lookup->calls ) === 1, 'A nested mob template must not activate reverse loot on an ordinary root page.' );
npcLootExpect( $plainParser->output->ttl === 3600, 'Non-mob pages retain their cache policy.' );

foreach ( [
	new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'Synthetic User page', 2 ) ),
	new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'Synthetic Parsoid page' ), new SyntheticNpcLootOptions( true ) ),
	new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'Synthetic Section preview' ), new SyntheticNpcLootOptions( false, true ) )
] as $ineligible ) {
	$text = $npcSource;
	EqlNpcLootRenderer::onParserBeforeInternalParse( $ineligible, $text, null );
	npcLootExpect( $text === $npcSource, 'Non-main pages, Parsoid fragments and section previews must stay unchanged.' );
}
npcLootExpect( count( $lookup->calls ) === 1, 'Ineligible output must not query the reverse lookup.' );

// A zero-result lookup needs exactly the same TTL/marker as a nonempty panel:
// new item links have no existing dependency capable of purging this mob.
$lookup->result = [ 'items' => [], 'complete' => true, 'warnings' => [] ];
$emptyParser = new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'Synthetic Empty Drolvarg' ) );
$text = $npcSource;
EqlNpcLootRenderer::onParserBeforeInternalParse( $emptyParser, $text, null );
npcLootExpect( $text === $npcSource, 'Do not append an empty reverse loot panel.' );
npcLootExpect( $emptyParser->output->ttl <= 300 && $emptyParser->output->getExtensionData( EqlNpcLootRenderer::MARKER ), 'Zero-result mob pages must still refresh for new source items.' );
npcLootExpect( count( $lookup->calls ) === 2, 'The guard must be scoped to each ParserOutput rather than global parser state.' );

$lookup->result = [ 'items' => [ [ 'title' => 'Synthetic Shared Relic', 'lootKind' => 'common',
	'contexts' => [ [ 'zone' => 'Synthetic Cavern', 'instance' => 'normal' ] ]
] ], 'complete' => true, 'warnings' => [] ];
$commonSource = '{{Namedmobpage|name=A Synthetic Drolvarg|known_loot=* [[Synthetic Authored Relic]]|common_loot=<ul><li>[[Synthetic Authored Common Relic]]</li></ul>}}';
$commonText = $commonSource;
EqlNpcLootRenderer::onParserBeforeInternalParse( new SyntheticNpcLootParser( new SyntheticNpcLootTitle( 'Synthetic Common Drolvarg' ) ), $commonText, null );
$commonParts = explode( '|common_loot=', $commonText, 2 );
npcLootExpect( EqlNpcLootRenderer::entries( $lookup->result )[0]['field'] === 'common_loot' && !str_contains( $commonParts[0], '{{:Synthetic_Shared_Relic}}' ) && str_contains( $commonParts[1], '{{:Synthetic_Shared_Relic}}' ), 'A lookup-classified common item must enter common_loot rather than the native known/unique field.' );
npcLootExpect( str_contains( $commonParts[0], '* [[Synthetic Authored Relic]]' ) && str_contains( $commonParts[1], '[[Synthetic Authored Common Relic]]' ) && !str_contains( $commonText, '<small' ) && !str_contains( $commonText, 'Synthetic Cavern' ), 'Generated common loot keeps authored rows intact and displays only the normal item transclusion.' );

$rendered = EqlNpcLootRenderer::render( [ 'items' => [ [ 'title' => 'Synthetic & Relic', 'contexts' => [
	[ 'zone' => 'Synthetic & Cavern', 'instance' => 'raid' ]
] ] ], 'complete' => true, 'warnings' => [] ] );
npcLootExpect( str_contains( $rendered, '{{:Synthetic_&_Relic}}' ), 'Keep item identity in a normal transclusion.' );
npcLootExpect( !str_contains( $rendered, 'Cavern' ) && !str_contains( $rendered, '<small' ) && substr_count( $rendered, '{{:' ) === 1, 'Diagnostic rendering must retain one normal item transclusion without visible source metadata.' );
npcLootExpect( !preg_match( '/^\s*==/m', $rendered ), 'Generated panel headings must not create nonexistent editable source sections.' );
$rejected = EqlNpcLootRenderer::render( [ 'items' => [ [ 'title' => 'Synthetic {{Unsafe}} Relic', 'contexts' => [] ] ], 'complete' => true ] );
npcLootExpect( $rejected === '', 'Unsafe item delimiters must not produce executable transclusion markup.' );

$outputPage = new SyntheticNpcLootOutputPage();
EqlNpcLootRenderer::onOutputPageParserOutput( $outputPage, $parser->output );
EqlNpcLootRenderer::onBeforePageDisplay( $outputPage, null );
npcLootExpect( $outputPage->cdnTtl <= 300, 'Marked mob pages must also cap CDN expiry independently of parser cache.' );
$emptyOutputPage = new SyntheticNpcLootOutputPage();
EqlNpcLootRenderer::onOutputPageParserOutput( $emptyOutputPage, $emptyParser->output );
EqlNpcLootRenderer::onBeforePageDisplay( $emptyOutputPage, null );
npcLootExpect( $emptyOutputPage->cdnTtl <= 300, 'Zero-result mob pages must also cap CDN expiry.' );
$ordinaryOutputPage = new SyntheticNpcLootOutputPage();
EqlNpcLootRenderer::onOutputPageParserOutput( $ordinaryOutputPage, $plainParser->output );
EqlNpcLootRenderer::onBeforePageDisplay( $ordinaryOutputPage, null );
npcLootExpect( $ordinaryOutputPage->cdnTtl === 86400, 'Leave ordinary page CDN behavior unchanged.' );

// Migrate pre-feature parser cache entries lazily, only when their dependencies
// show a mob template. A fresh parse adds GUARD even to a non-mob root article.
$oldNpcOutput = new SyntheticNpcLootOutput();
$oldNpcOutput->links = [ [ 'link' => new SyntheticNpcLootTitle( 'Namedmobpage', NS_TEMPLATE ) ] ];
$npcPage = new SyntheticNpcLootWikiPage( new SyntheticNpcLootTitle( 'Synthetic cached Drolvarg' ) );
$options = new SyntheticNpcLootOptions();
npcLootExpect( EqlNpcLootRenderer::onRejectParserCacheValue( $oldNpcOutput, $npcPage, $options ) === false, 'An old NPC cache entry must be rejected once so the feature appears after deployment.' );
$oldNpcOutput->setExtensionData( EqlNpcLootRenderer::GUARD, true );
npcLootExpect( EqlNpcLootRenderer::onRejectParserCacheValue( $oldNpcOutput, $npcPage, $options ) !== false, 'A newly scanned cache entry must remain reusable.' );
$oldNpcOutput->setExtensionData( EqlNpcLootRenderer::GUARD, null );
foreach ( [ new SyntheticNpcLootOptions( true ), new SyntheticNpcLootOptions( false, true ) ] as $skippedOptions ) {
	npcLootExpect( EqlNpcLootRenderer::onRejectParserCacheValue( $oldNpcOutput, $npcPage, $skippedOptions ) !== false, 'Cache migration must leave Parsoid and section preview entries alone.' );
}
$userPage = new SyntheticNpcLootWikiPage( new SyntheticNpcLootTitle( 'Synthetic user page', 2 ) );
npcLootExpect( EqlNpcLootRenderer::onRejectParserCacheValue( $oldNpcOutput, $userPage, $options ) !== false, 'Non-main cache entries must not be invalidated by the feature rollout.' );
$oldOrdinaryOutput = new SyntheticNpcLootOutput();
$oldOrdinaryOutput->links = [ [ 'link' => new SyntheticNpcLootTitle( 'Spellpage', NS_TEMPLATE ) ] ];
npcLootExpect( EqlNpcLootRenderer::onRejectParserCacheValue( $oldOrdinaryOutput, $npcPage, $options ) !== false, 'An old unrelated page cache remains valid.' );
$oldOrdinaryOutput->links = [ [ 'link' => new SyntheticNpcLootTitle( 'Namedmobpage', NS_MAIN ) ] ];
npcLootExpect( EqlNpcLootRenderer::onRejectParserCacheValue( $oldOrdinaryOutput, $npcPage, $options ) !== false, 'A same-named article dependency is not the NPC template.' );

EqlNpcLootRenderer::$lookupFactory = null;
echo "Synthetic NPC reverse-loot rendering, root guards, transclusions and cache bounds passed.\n";
