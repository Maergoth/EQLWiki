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
	require __DIR__ . '/../skins/EQLImmersive/includes/SiteIdentity.php';
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
	$expected = 'Synthetic Claymore is a two-handed slashing weapon in EQ Legends Wiki. ' .
		'Base DMG: 17; delay: 45. Class: Warrior, Paladin, Ranger, Shadow Knight. Slot: PRIMARY. Listed drop location: Synthetic Keep.';
	checkSeo( $summary['text'] === $expected && $summary['addLead'], 'Summarize only documented own-card fields and the linked drop zone.' );
	checkSeo( !str_contains( $summary['text'], 'missing' ) && !str_contains( $summary['text'], 'Guard' ) && !str_contains( $summary['text'], 'Shop' ), 'Exclude placeholders, NPC names and unrelated sections.' );

	$out = new SeoOutput( $html );
	ItemSeo::addToOutput( $out, true );
	checkSeo( count( $out->meta ) === 1 && str_starts_with( $expected, rtrim( $out->meta[0][1], '…' ) ), 'Generate one bounded escaped-by-core meta description from the actual article.' );
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

	$stats = 'Slot: <span>PRIMARY</span><br>Skill: <span>2H Slashing</span> Atk Delay: <b>45</b><br>DMG: <b>17</b><br>Class: WAR PAL RNG SHD<br>Effect: <a href="/Proc">DMG: 999</a>';
	checkSeo( ItemSeo::summarize( itemHtml( stats: $stats ), 'Synthetic Claymore' )['text'] === $expected, 'Inline stat markup must retain real base values and exclude effect text.' );
	$noStats = ItemSeo::summarize( itemHtml( stats: 'Class: ALL<br>Race: ALL' ), 'Synthetic Claymore' );
	checkSeo( str_contains( $noStats['text'], 'is an item in EQ Legends Wiki.' ) && !str_contains( $noStats['text'], 'DMG' ), 'Nonweapon items must not acquire inferred weapon stats.' );
	$effectStats = 'Slot: PRIMARY<br>Skill: 1H Slashing Atk Delay: 34<br>DMG: 15<br>' .
		'Effect: <a href="/Synthetic_Light"><span class="itemeff">Synthetic Light</span></a> (Combat, Casting Time: Instant) at Level 20<br>Class: PAL';
	$effect = new SeoOutput( itemHtml( stats: $effectStats ) );
	ItemSeo::addToOutput( $effect, true );
	checkSeo( str_contains( $effect->html, 'Effect: Synthetic Light (Combat, level 20).' ) && str_contains( $effect->html, 'Class: Paladin.' ), 'Keep the documented effect, trigger, level and class in the own-item summary.' );
	checkSeo( str_contains( $effect->meta[0][1], 'Synthetic Light (Combat, level 20)' ) && str_contains( $effect->meta[0][1], 'Base DMG: 15; delay: 34.' ), 'Prioritize distinctive effect facts while retaining weapon damage and delay in the bounded description.' );
	$beltStats = 'MAGIC ITEM<br>Slot: WAIST<br>Haste: <b>+21%</b><br>Class: ALL';
	$belt = new SeoOutput( itemHtml( stats: $beltStats ) );
	ItemSeo::addToOutput( $belt, true );
	checkSeo( str_contains( $belt->meta[0][1], 'Haste: +21%.' ) && str_contains( $belt->meta[0][1], 'Slot: WAIST.' ) && !str_contains( $belt->meta[0][1], 'DMG' ), 'Summarize an explicit worn haste value without inferring weapon statistics.' );
	$armor = ItemSeo::summarize( itemHtml( stats: 'Slot: CHEST<br>AC: 23<br>Class: CLR' ), 'Synthetic Claymore' )['text'];
	checkSeo( str_contains( $armor, 'AC: 23.' ) && str_contains( $armor, 'Class: Cleric.' ), 'Describe explicit armor and a recognized single class.' );
	checkSeo( str_contains( ItemSeo::summarize( itemHtml( stats: 'AC: 0' ), 'Synthetic Claymore' )['text'], 'AC: 0.' ), 'An explicitly documented zero armor value remains a fact.' );
	foreach ( [
		'Haste: +21%<br>Haste: unknown',
		'AC: 23<br>AC: 99',
		'Class: PAL<br>Class: ???',
		'Effect: Synthetic Light<br>Effect: Another Light',
		'Haste: unknown<br>AC: ?<br>Class: ALL except CLR<br>Effect: Need info',
		'<div class="itemicon">AC: 999<br>Haste: +999%<br>Class: PAL<br>Effect: Foreign spell</div>',
		'Focus Effect: <a href="/Foreign_Spell">Foreign spell</a><br><span class="hb"><br>AC: 999<br>Haste: +999%</span>',
	] as $untrusted ) {
		$summary = ItemSeo::summarize( itemHtml( stats: $untrusted ), 'Synthetic Claymore' )['text'];
		checkSeo( !preg_match( '/(?:Effect|Haste|AC|Class):/', $summary ), 'Unknown, conflicting, unsupported and tooltip/icon values cannot become distinguishing item facts.' );
	}
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
	checkSeo( str_contains( $out->html, 'Épée &amp; &quot;Lumière&quot; is a two-handed' ), 'Escape display text without losing Unicode.' );
	$out = new SeoOutput( itemHtml( str_repeat( '長', 210 ) ) );
	$out->title->name = str_repeat( '長', 210 );
	ItemSeo::addToOutput( $out, true );
	checkSeo( mb_strlen( $out->meta[0][1], 'UTF-8' ) <= 200 && mb_check_encoding( $out->meta[0][1], 'UTF-8' ), 'Bound descriptions by Unicode characters without cutting a codepoint.' );

	$skin = ( new ReflectionClass( EQLImmersive\SkinEQLImmersive::class ) )->newInstanceWithoutConstructor();
	checkSeo( $skin->isResponsive(), 'EQL must request the native responsive viewport even when Vector defaults to desktop.' );
	$hooks = file_get_contents( __DIR__ . '/../skins/EQLImmersive/includes/Hooks.php' );
	checkSeo( !preg_match( '/[\'"]viewport[\'"]/', $hooks ), 'The skin hook must not add a second viewport.' );

	echo "Passed $checks synthetic item SEO/viewport checks; no live-site or browser verification.\n";

	// Optional captured anonymous HTML supplements fixtures without adding public
	// article snapshots or network dependencies to CI. This does not test Google
	// inclusion, JavaScript or a live installation of the candidate code.
	if ( isset( $argv[1] ) ) {
		if ( $argc !== 3 || $argv[1] !== '--public-html-dir' || !is_dir( $argv[2] ) ) {
			throw new RuntimeException( 'Usage: php ops/test-item-seo.php [--public-html-dir DIRECTORY]' );
		}
		$publicChecks = $checks;
		foreach ( [
			'Ghoulbane' => [ 'Effect: Dismiss Undead (Combat, level 20).', 'Class: Paladin.', 'Base DMG: 15; delay: 34.' ],
			'Flowing Black Silk Sash' => [ 'Haste: +21%.', 'Slot: WAIST.' ],
			'Bone Bladed Claymore' => [ 'Base DMG: 17; delay: 45.', 'Class: Warrior, Paladin, Ranger, Shadow Knight.' ]
		] as $title => $facts ) {
			$source = file_get_contents( $argv[2] . '/' . str_replace( ' ', '_', $title ) . '.html' );
			// Only remove our old generated paragraph, leaving authored source and
			// all original cards/links intact as an unsaved hook-input fixture.
			$source = preg_replace( '~<p class="eql-item-summary">[^<]*</p>~u', '', $source );
			$out = new SeoOutput( $source );
			$out->title->name = $title;
			ItemSeo::addToOutput( $out, true );
			foreach ( $facts as $fact ) {
				checkSeo( str_contains( $out->html, $fact ), 'Captured own-card HTML must retain this documented fact in its generated lead: ' . $title . ' / ' . $fact );
			}
			checkSeo( str_contains( $out->meta[0][1], $facts[0] ), 'The defining real item fact must survive meta description length limits: ' . $title );
			checkSeo( preg_replace( '~<p class="eql-item-summary">[^<]*</p>~u', '', $out->html ) === $source, 'Captured original article markup remains byte-identical after the new lead is removed: ' . $title );
		}
		$foreign = file_get_contents( $argv[2] . '/Befallen.html' );
		checkSeo( ItemSeo::summarize( $foreign, 'Befallen' ) === null, 'Actual multi-item zone HTML cannot acquire one of its embedded item summaries.' );
		echo 'Passed ' . ( $checks - $publicChecks ) . " captured public-HTML checks; offline candidate transformation only.\n";
	}
}
