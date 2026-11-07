<?php
/** Synthetic item/article-output regression; no settings, database or HTTP calls. */
namespace MediaWiki\Skins\Vector {
	class SkinVector22 {
		public function isResponsive() { return false; }
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' ) {
		exit( "Run this fixture from the command line.\n" );
	}
	define( 'NS_MAIN', 0 );
	require __DIR__ . '/../skins/EQLImmersive/includes/ItemSeo.php';
	require __DIR__ . '/../skins/EQLImmersive/includes/SkinEQLImmersive.php';

	use EQLImmersive\ItemSeo;

	$checks = 0;
	function checkSeo( bool $condition, string $message ): void {
		global $checks;
		$checks++;
		if ( !$condition ) {
			throw new RuntimeException( $message );
		}
	}

	function itemHtml( string $title = 'Synthetic Claymore', string $stats = '', string $before = '', string $drops = '' ): string {
		if ( $stats === '' ) {
			$stats = 'MAGIC ITEM LORE ITEM<br><p>Slot: PRIMARY<br>' .
				'Skill: 2H Slashing Atk Delay: 45<br>DMG: 17<br>AGI: -8<br>Class: WAR PAL RNG SHD</p>';
		}
		return '<div class="mw-content-ltr mw-parser-output" lang="en">' . $before .
			'<div class="itemtopbg"><div class="itemtitle">' . $title . '</div></div>' .
			'<div class="itembg"><div class="itemdata"><div class="itemicon">icon</div>' .
			$stats . '</div></div><div class="itembotbg"></div>' .
			'<p>Item Lore missing. Replace this with {{Item Lore}}</p>' .
			'<div class="mw-heading mw-heading2"><h2 id="Drops_From">Drops From</h2></div>' .
			( $drops ?: '<p><a href="/Synthetic_Keep">Synthetic Keep</a></p><ul><li><a href="/Synthetic_Guard">Synthetic Guard</a></li></ul>' ) .
			'<div class="mw-heading mw-heading2"><h2 id="Sold_by">Sold by</h2></div>' .
			'<p><a href="/Unrelated_Shop">Unrelated Shop</a></p></div>';
	}

	class SeoTitle {
		public int $namespace = 0;
		public bool $exists = true;
		public bool $redirect = false;
		public string $name = 'Synthetic Claymore';
		public function getNamespace() { return $this->namespace; }
		public function exists() { return $this->exists; }
		public function isRedirect() { return $this->redirect; }
		public function getText() { return $this->name; }
	}
	class SeoOutput {
		public SeoTitle $title;
		public array $params = [];
		public array $categories = [ 'Items', 'Warrior Equipment' ];
		public array $meta = [];
		public string $html;
		public bool $article = true;
		public bool $current = true;
		public string $action = 'view';
		public string $robotPolicy = 'noindex,nofollow';
		public function __construct( string $html ) { $this->html = $html; $this->title = new SeoTitle(); }
		public function getTitle() { return $this->title; }
		public function getRequest() { return $this; }
		public function getContext() { return $this; }
		public function getVal( $key ) { return $this->params[$key] ?? null; }
		public function getActionName() { return $this->action; }
		public function isArticle() { return $this->article; }
		public function isRevisionCurrent() { return $this->current; }
		public function getCategories() { return $this->categories; }
		public function getHTML() { return $this->html; }
		public function clearHTML() { $this->html = ''; }
		public function addHTML( $html ) { $this->html .= $html; }
		public function getMetaTags() { return $this->meta; }
		public function addMeta( $name, $value ) { $this->meta[] = [ $name, $value ]; }
	}

	$html = itemHtml();
	$summary = ItemSeo::summarize( $html, 'Synthetic Claymore' );
	$expected = 'Synthetic Claymore is listed in the EverQuest Legends Wiki as a two-handed slashing weapon. ' .
		'Slot: PRIMARY. Base DMG: 17; delay: 45. Listed drop location: Synthetic Keep.';
	checkSeo( $summary['text'] === $expected && $summary['addLead'], 'Summarize only documented own-card fields and the linked drop zone.' );
	checkSeo( !str_contains( $summary['text'], 'missing' ) && !str_contains( $summary['text'], 'Guard' ) && !str_contains( $summary['text'], 'Shop' ), 'Exclude placeholders, NPC names and unrelated sections.' );

	$out = new SeoOutput( $html );
	ItemSeo::addToOutput( $out, true );
	checkSeo( $out->meta === [ [ 'description', $expected ] ], 'Generate one escaped-by-core meta description from the actual article.' );
	checkSeo( preg_replace( '~<p class="eql-item-summary">.*?</p>~s', '', $out->html ) === $html, 'Adding a lead must preserve all original rendered markup byte for byte.' );
	checkSeo( str_contains( $out->html, 'lang="en"><p class="eql-item-summary">' ), 'Place lead inside the original parser output.' );
	checkSeo( $out->robotPolicy === 'noindex,nofollow', 'Item metadata must never relax staging or article robots policy.' );
	$once = $out->html;
	ItemSeo::addToOutput( $out, true );
	checkSeo( $out->html === $once && count( $out->meta ) === 1, 'Repeated hooks must not duplicate lead or metadata.' );
	$out = new SeoOutput( '<div class="mw-parser-output-shadow">Outside</div>' . $html );
	ItemSeo::addToOutput( $out, true );
	checkSeo( str_starts_with( $out->html, '<div class="mw-parser-output-shadow">Outside</div><div class="mw-content-ltr mw-parser-output" lang="en"><p class="eql-item-summary">' ), 'Match the exact parser-output class token when inserting the lead.' );

	$out = new SeoOutput( itemHtml( before: '<p>Authored introduction &amp; context.</p>' ) );
	$out->meta = [ [ 'Description', 'Reviewed description' ] ];
	$before = $out->html;
	ItemSeo::addToOutput( $out, true );
	checkSeo( $out->html === $before && $out->meta === [ [ 'Description', 'Reviewed description' ] ], 'Preserve an authored intro and an explicit description.' );
	$out = new SeoOutput( itemHtml( before: '<div class="eql-era">Future era: currently unavailable.</div>' ) );
	$before = $out->html;
	ItemSeo::addToOutput( $out, true );
	checkSeo( $out->html === $before && count( $out->meta ) === 1, 'Do not move or precede existing era/verification notices.' );

	$stats = 'Slot: <span>PRIMARY</span><br>Skill: <span>2H Slashing</span> Atk Delay: <b>45</b><br>DMG: <b>17</b><br>Effect: <a href="/Proc">DMG: 999</a>';
	checkSeo( ItemSeo::summarize( itemHtml( stats: $stats ), 'Synthetic Claymore' )['text'] === $expected, 'Inline stat markup must retain real base values and exclude effect text.' );
	$noStats = ItemSeo::summarize( itemHtml( stats: 'Class: ALL<br>Race: ALL' ), 'Synthetic Claymore' );
	checkSeo( str_contains( $noStats['text'], 'as an item.' ) && !str_contains( $noStats['text'], 'DMG' ), 'Nonweapon items must not acquire inferred weapon stats.' );
	foreach ( [
		'<ul><li>This item is not dropped by mobs.</li></ul>',
		'<p>Various Zones</p><ul><li>Newbie mobs</li></ul>',
		'<p>See <a href="/Synthetic_Keep">Synthetic Keep</a> for more information.</p>',
		'<p><a class="new" href="/Synthetic_Keep">Synthetic Keep</a></p>',
		'<p><a href="https://example.test/">Other website</a></p>'
	] as $drops ) {
		checkSeo( !str_contains( ItemSeo::summarize( itemHtml( drops: $drops ), 'Synthetic Claymore' )['text'], 'drop location' ), 'Unknown, placeholder and non-zone source markup must not become a factual location.' );
	}
	checkSeo( ItemSeo::summarize( $html, 'Different article' ) === null, 'Do not summarize another item transcluded into a page.' );
	checkSeo( ItemSeo::summarize( $html . $html, 'Synthetic Claymore' ) === null, 'Do not choose arbitrarily among multiple item cards.' );

	foreach ( [ 'private', 'namespace', 'missing', 'redirect', 'notArticle', 'history', 'oldRevision', 'oldid', 'diff', 'veaction', 'category' ] as $case ) {
		$out = new SeoOutput( $html );
		$publicRead = true;
		switch ( $case ) {
			case 'private': $publicRead = false; break;
			case 'namespace': $out->title->namespace = 10; break;
			case 'missing': $out->title->exists = false; break;
			case 'redirect': $out->title->redirect = true; break;
			case 'notArticle': $out->article = false; break;
			case 'history': $out->action = 'history'; break;
			case 'oldRevision': $out->current = false; break;
			case 'oldid': case 'diff': case 'veaction': $out->params[$case] = '1'; break;
			case 'category': $out->categories = [ 'Quests' ]; break;
		}
		ItemSeo::addToOutput( $out, $publicRead );
		checkSeo( $out->html === $html && $out->meta === [], 'Exclude ineligible article output: ' . $case );
	}

	$out = new SeoOutput( itemHtml( 'Épée &amp; &quot;Lumière&quot;' ) );
	$out->title->name = 'Épée & "Lumière"';
	ItemSeo::addToOutput( $out, true );
	checkSeo( str_contains( $out->html, 'Épée &amp; &quot;Lumière&quot; is listed' ), 'Escape display text without losing Unicode.' );
	$out = new SeoOutput( itemHtml( str_repeat( '長', 210 ) ) );
	$out->title->name = str_repeat( '長', 210 );
	ItemSeo::addToOutput( $out, true );
	checkSeo( mb_strlen( $out->meta[0][1], 'UTF-8' ) <= 200 && mb_check_encoding( $out->meta[0][1], 'UTF-8' ), 'Bound descriptions by Unicode characters without cutting a codepoint.' );

	$skin = ( new ReflectionClass( EQLImmersive\SkinEQLImmersive::class ) )->newInstanceWithoutConstructor();
	checkSeo( $skin->isResponsive(), 'EQL must request the native responsive viewport even when Vector defaults to desktop.' );
	$hooks = file_get_contents( __DIR__ . '/../skins/EQLImmersive/includes/Hooks.php' );
	checkSeo( !preg_match( '/[\'"]viewport[\'"]/', $hooks ), 'The skin hook must not add a second viewport.' );

	echo "Passed $checks synthetic item SEO/viewport checks; no live-site or browser verification.\n";
}
