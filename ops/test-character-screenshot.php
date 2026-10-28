<?php
/** Read-only native parser/Title integration. No page, upload, purge or account writes. */
use EQLImmersive\CharacterScreenshot;
use EQLClientData\CharacterSheetSnapshot;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class EQLTestCharacterScreenshot extends Maintenance {
	private int $checks = 0;
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Parse reviewed Character screenshots in memory; never save pages or uploads.' );
		$this->addOption( 'output', 'Optional private synthetic HTML for visual review.', false, true );
		$this->addOption( 'existing-file', 'Optional existing local public image filename for a native present-file check.', false, true );
		$this->addOption( 'existing-page', 'Exact User page for an optional own-prefix existing-file check; no account or page is created.', false, true );
	}
	private function check( bool $ok, string $message ): void {
		if ( !$ok ) { throw new RuntimeException( $message ); }
		$this->checks++;
	}
	private function parse( string $source, string $page = 'User:Synthetic owner/Subpage' ): array {
		$output = $this->getServiceContainer()->getParserFactory()->create()->parse(
			$source, Title::newFromText( $page ), ParserOptions::newFromAnon()
		);
		$html = $output->getRawText();$doc = new DOMDocument();
		$old = libxml_use_internal_errors( true );$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );libxml_clear_errors();libxml_use_internal_errors( $old );
		return [ $output, $html, new DOMXPath( $doc ) ];
	}
	private function images( $output ): array {
		return array_map( static fn ( array $row ): string => $row['link']->getDBkey(),
			$output->getLinkList( ParserOutputLinkTypes::MEDIA ) );
	}
	public function execute() {
		$services = $this->getServiceContainer();
		$sources = [ 'Template:Character sheet' => file_get_contents( __DIR__ . '/character-sheet.wiki' ),
			'Template:Character sheet item' => file_get_contents( __DIR__ . '/character-sheet-item.wiki' ) ];
		$services->getHookContainer()->register( 'BeforeParserFetchTemplateRevisionRecord',
			static function ( ?LinkTarget $context, LinkTarget $title, bool &$skip, ?RevisionRecord &$revision ) use ( $sources ) {
				$key = str_replace( '_', ' ', $title->getPrefixedText() );
				if ( isset( $sources[$key] ) ) { $revision = new MutableRevisionRecord( Title::castFromLinkTarget( $title ) );$revision->setContent( SlotRecord::MAIN, new WikitextContent( $sources[$key] ) ); }
			}
		);
		foreach ( [
			'Character_Owner/Subpage_-_A_&_B_(Test_Realm).png' => 'Character Owner/Subpage - A & B (Test Realm).png',
			"Portrait Éowyn's & companion.JPG" => "Portrait Éowyn's & companion.JPG",
			'Character Owner - A (Server:One).webp' => 'Character Owner - A (Server:One).webp'
		] as $input => $expected ) {
			$title = CharacterScreenshot::fileTitle( $input );
			$this->check( $title && $title->getNamespace() === NS_FILE && $title->getText() === $expected, 'Actual MediaWiki File title must normalize the accepted filename.' );
		}
		$this->check( CharacterScreenshot::fileTitle( str_repeat( 'a', 236 ) . '.png' ) !== null, 'An exactly240-byte valid filename remains accepted.' );
		foreach ( [ 'https://outside.invalid/photo.png', '//outside.invalid/photo.png', 'data:image/png;base64,a.png',
			'File:Photo.png', 'Media:Photo.png', '/tmp/photo.png', 'C:\\photo.png', '../photo.png',
			'Photo.png#fragment', 'Photo[1].png', 'Photo{1}.png', 'Photo|thumb.png', '<img>.png', "Photo\0.png", "Photo\n.png",
			'Photo.svg', 'Photo.html', str_repeat( 'a', 237 ) . '.png', str_repeat( 'é', 120 ) . '.png'
		] as $invalid ) {
			$this->check( CharacterScreenshot::fileTitle( $invalid ) === null, 'Unsafe, unsupported or overlong filename must not become an image target.' );
		}
		$sheet = '{{Character_sheet|name=A &amp; B|server=Test_Realm|level=50|classes=WAR / CLR|total_hp=1234|total_mp=?|total_end=0|head_item=Synthetic cap|head_tier=0|head_stats=HP: 0}}';
		[ $output, $html, $xpath ] = $this->parse( $sheet );
		$photo = $xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," eql-character-screenshot-v1 ")]' );
		$this->check( $photo->length === 1, 'Old payload without screenshot must gain exactly one native screenshot component.' );
		$expected = 'Character Synthetic owner/Subpage - A & B (Test Realm).png';
		$this->check( $photo[0]->getAttribute( 'data-file-title' ) === 'File:' . $expected, 'Default must include the full authoritative PAGENAME and both identity fields.' );
		$this->check( str_contains( $photo[0]->getAttribute( 'class' ), 'is-missing' ), 'Synthetic absent default must show its upload placeholder.' );
		$link = $xpath->query( './/a', $photo[0] )[0];$query = [];parse_str( parse_url( $link->getAttribute( 'href' ), PHP_URL_QUERY ) ?? '', $query );
		$this->check( ( $query['wpDestFile'] ?? null ) === $expected && ( $query['eqlReturnTo'] ?? null ) === 'User:Synthetic owner/Subpage', 'Native upload URL must encode exact filename and current return page.' );
		$this->check( $link->textContent === '+Add an image' && str_contains( $photo[0]->textContent, 'PNG' ), 'Missing default explains the PNG action with ordinary accessible anchor text.' );
		$this->check( in_array( str_replace( ' ', '_', $expected ), $this->images( $output ), true ), 'Missing default must be a native image dependency for late upload invalidation.' );
		$this->check( $xpath->query( '//dt[text()="HP"]/following-sibling::dd[text()="1234"]' )->length === 1 &&
			$xpath->query( '//dt[text()="Mana"]/following-sibling::dd[text()="?"]' )->length === 1 &&
			$xpath->query( '//dt[text()="Endurance"]/following-sibling::dd[text()="0"]' )->length === 1, 'Photo must not alter observed numeric, explicit zero or unknown totals.' );
		$this->check( $xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," eql-character-slot ")]' )->length === 24, 'All original equipment slots remain.' );
		$rows = CharacterSheetSnapshot::extract( $html );
		$this->check( count( $rows ) === 1 && $rows[0]['name'] === 'A & B' && $rows[0]['stats']['hp'] === 1234 && $rows[0]['stats']['mp'] === null && $rows[0]['stats']['end'] === 0 && $rows[0]['totalTier'] === 0 && $rows[0]['equippedSlots'] === 1, 'Actual Player List extraction keeps original identity, numeric values, unknowns, tiers and slot count.' );
		$this->check( !array_key_exists( 'screenshot', $rows[0] ) && !array_key_exists( 'screenshotFile', $rows[0] ), 'Screenshot is not added to public ranking data or identity.' );
		$this->check( in_array( 'skins.EQLImmersive.characterSheet', $output->getModules(), true ), 'Unsaved full sheet retains its actual conditional module.' );
		[ $other ] = $this->parse( $sheet, 'User:Other owner/Subpage' );
		$this->check( in_array( 'Character_Other_owner/Subpage_-_A_&_B_(Test_Realm).png', $this->images( $other ), true ), 'Same character on a different owner page has a different default dependency.' );
		[ , , $unicodeXpath ] = $this->parse( "{{Character_sheet|name=O&#39;Brien &amp; Éowyn|server=Realm:One|total_hp=0}}", "User:Éowyn's owner/Sub_page" );
		$unicode = $unicodeXpath->query( '//*[@data-file-title]' )[0];
		$this->check( $unicode && $unicode->getAttribute( 'data-file-title' ) === "File:Character Éowyn's owner/Sub page - O'Brien & Éowyn (Realm:One).png", 'Real parser arguments, entities, apostrophes, Unicode, subpages and interior colon use the same native File normalization.' );
		$customName = 'Character Synthetic owner/Subpage - Explicit photo.jpg';
		[ $custom, , $customXpath ] = $this->parse( str_replace( '}}', '|screenshot=' . wfEscapeWikiText( $customName ) . '}}', $sheet ) );
		$this->check( $customXpath->query( '//*[@data-file-title]' )[0]->getAttribute( 'data-file-title' ) === 'File:' . $customName && in_array( str_replace( ' ', '_', $customName ), $this->images( $custom ), true ), 'Explicit own-page filename overrides default and remains a normal image dependency.' );
		foreach ( [ 'https://outside.invalid/photo.png', '&lt;img src=x onerror=alert(1)&gt;.png', str_repeat( 'a', 237 ) . '.png' ] as $invalid ) {
			[ $bad, $badHtml, $badXpath ] = $this->parse( str_replace( '}}', '|screenshot=' . $invalid . '}}', $sheet ) );
			$box = $badXpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," eql-character-screenshot-v1 ")]' )[0];
			$this->check( $box->getAttribute( 'data-file-title' ) === 'File:' . $expected && $badXpath->query( './/img', $box )->length === 0 && str_contains( $box->textContent, 'Clear or change' ), 'Invalid explicit filename becomes an honest own-default placeholder requiring override repair.' );
			$this->check( !str_contains( $badHtml, 'outside.invalid' ) && $badXpath->query( '//dt[text()="HP"]/following-sibling::dd[text()="1234"]' )->length === 1, 'Invalid image must neither leak an external target nor block the ordinary sheet.' );
		}
		foreach ( [ 'Item_500.png', 'Character Other owner - Portrait.png', 'Character Synthetic owner/Other - Portrait.png' ] as $borrowed ) {
			[ , , $blockedXpath ] = $this->parse( str_replace( '}}', '|screenshot=' . wfEscapeWikiText( $borrowed ) . '}}', $sheet ) );
			$box = $blockedXpath->query( '//*[contains(@class,"eql-character-screenshot-v1")]' )[0];
			$this->check( $box->getAttribute( 'data-file-title' ) === 'File:' . $expected && $blockedXpath->query( './/img', $box )->length === 0 && str_contains( $box->textContent, 'Clear or change' ), 'Borrowed, foreign and cross-subpage overrides cannot claim ordinary artwork.' );
		}
		[ , , $unsupported ] = $this->parse( $sheet, 'Synthetic proof outside User namespace' );
		$this->check( $unsupported->query( '//*[@data-file-title]' )->length === 0, 'An unsupported namespace cannot synthesize an account-owned target.' );
		[ , , $longXpath ] = $this->parse( str_replace( 'name=A &amp; B', 'name=' . str_repeat( 'a', 240 ), $sheet ) );
		$this->check( $longXpath->query( '//*[@data-file-title]' )->length === 0 && $longXpath->query( '//dt[text()="HP"]/following-sibling::dd' )->length === 1, 'Overlong default uses safe upload guidance while keeping the sheet.' );
		if ( $this->hasOption( 'existing-file' ) ) {
			$title = CharacterScreenshot::fileTitle( $this->getOption( 'existing-file' ) );
			$page = Title::newFromText( $this->getOption( 'existing-page', 'User:Synthetic owner/Subpage' ) );
			$policy = new \EQLImmersive\CharacterScreenshotOwnership();
			$this->check( $title && $page && $policy->belongsToPage( $title, $page ) && $services->getRepoGroup()->findFile( $title ), 'Explicit native present-file check requires its exact own-prefix User page and known local file; no upload occurs.' );
			[ $present, $presentHtml, $presentXpath ] = $this->parse( str_replace( '}}', '|screenshot=' . wfEscapeWikiText( $title->getText() ) . '}}', $sheet ), $page->getPrefixedText() );
			$this->check( $presentXpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," eql-character-screenshot-image ")]//img' )->length === 1, 'Existing local file must render with native image markup.' );
			$this->check( in_array( $title->getDBkey(), $this->images( $present ), true ), 'Existing file stays a native image dependency.' );
			$this->check( CharacterSheetSnapshot::extract( $presentHtml ) === $rows, 'Present image and missing placeholder produce exactly the same Player List snapshot.' );
			$html .= "\n" . $presentHtml;
		}
		if ( $this->hasOption( 'output' ) ) {
			$this->check( file_put_contents( $this->getOption( 'output' ), $html ) === strlen( $html ), 'Private synthetic render could not be written.' );
		}
		$this->output( 'Character screenshot native Title/parser: ' . $this->checks . " checks passed; no pages or uploads saved.\n" );
	}
}
return EQLTestCharacterScreenshot::class;
