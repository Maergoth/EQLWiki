<?php
/** Synthetic public entity search metadata checks; no settings, database or network calls. */
if ( PHP_SAPI !== 'cli' ) {
	exit( "Run this fixture from the command line.\n" );
}
define( 'NS_MAIN', 0 );
require __DIR__ . '/../skins/EQLImmersive/includes/SiteIdentity.php';
require __DIR__ . '/../skins/EQLImmersive/includes/ItemSeo.php';
require __DIR__ . '/../skins/EQLImmersive/includes/EntitySeo.php';

use EQLImmersive\EntitySeo;
use EQLImmersive\ItemSeo;

class EntityTitle {
	public int $namespace = 0;
	public bool $exists = true;
	public bool $redirect = false;
	public string $name = 'Synthetic Guardian';
	public function getNamespace() { return $this->namespace; }
	public function exists() { return $this->exists; }
	public function isRedirect() { return $this->redirect; }
	public function getText() { return $this->name; }
}
class EntityOutput {
	public ?EntityTitle $title;
	public array $config = [ 'Server' => 'https://eqlwiki.com', 'CanonicalServer' => 'https://eqlwiki.com' ];
	public array $categories = [ 'NPCs', 'Named Mobs', 'Kunark Era' ];
	public array $parameters = [];
	public array $meta = [];
	public bool $article = true;
	public bool $current = true;
	public string $action = 'view';
	public string $htmlTitle = 'Original title';
	public string $pageTitle = '<em>Original visible heading</em>';
	public string $robots = 'index,follow';
	public string $html;
	public function __construct( string $html ) { $this->title = new EntityTitle(); $this->html = $html; }
	public function getTitle() { return $this->title; }
	public function getConfig() { return $this; }
	public function get( $key ) { return $this->config[$key] ?? null; }
	public function isArticle() { return $this->article; }
	public function isRevisionCurrent() { return $this->current; }
	public function getContext() { return $this; }
	public function getActionName() { return $this->action; }
	public function getRequest() { return $this; }
	public function getVal( $key ) { return $this->parameters[$key] ?? null; }
	public function getHeader( $key ) { return 'attacker.invalid'; }
	public function getCategories() { return $this->categories; }
	public function getHTML() { return $this->html; }
	public function getMetaTags() { return $this->meta; }
	public function addMeta( $name, $value ) { $this->meta[] = [ $name, $value ]; }
	public function setHTMLTitle( $title ) { $this->htmlTitle = $title; }
	public function clearHTML() { $this->html = ''; }
	public function addHTML( $html ) { $this->html .= $html; }
}
$checks = 0;
function checkEntity( bool $condition, string $message ): void {
	global $checks;
	$checks++;
	if ( !$condition ) { throw new RuntimeException( $message ); }
}
function npcEntityHtml( string $before = '', string $after = '' ): string {
	return '<div class="mw-parser-output">' . $before .
		'<div class="eql-mobpage"><div class="eql-mobpage-hero"><div class="eql-mobpage-stats">' .
		'<table class="mobStatsBox"><tr><th>Race</th><td>???</td></tr>' .
		'<tr><th>Level</th><td>Unknown</td></tr><tr><th>Zone</th><td>Foreign Zone</td></tr></table></div>' .
		'<div class="eql-mobpage-loot"><span class="itemtitle">Unrelated loot item</span></div></div>' .
		'<div class="eql-mobpage-description"><p>Description needed.</p></div></div>' . $after . '</div>';
}
function zoneEntityHtml( string $before = '', string $after = '', bool $nested = true ): string {
	$card = '<table class="zoneTopTable"><tr><td>Zone stat summary</td></tr></table>';
	if ( $nested ) {
		$card = '<table><tbody><tr><td>' . $card . '</td></tr></tbody></table>';
	}
	return '<div class="mw-parser-output">' . $before .
		$card .
		'<div class="itemtitle">Unrelated zone loot</div>' . $after . '</div>';
}
function itemEntityHtml( string $name = 'Synthetic Claymore' ): string {
	return '<div class="mw-parser-output"><div class="itemtopbg"><div class="itemtitle">' .
		htmlspecialchars( $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</div></div>' .
		'<div class="itembg"><div class="itemdata">Slot: PRIMARY<br>Skill: 2H Slashing Atk Delay: 45<br>DMG: 17</div></div>' .
		'<div class="mw-heading"><h2 id="Drops_From">Drops From</h2></div><p><a href="/Synthetic_Keep">Synthetic Keep</a></p></div>';
}

foreach ( [ 'https://eqlwiki.com', 'https://test.eqlwiki.com' ] as $origin ) {
	$html = npcEntityHtml( '<div class="right-float-era">Kunark Era: Out of Era</div>' );
	$out = new EntityOutput( $html );
	$out->config = [ 'Server' => $origin, 'CanonicalServer' => $origin ];
	$out->robots = $origin === 'https://test.eqlwiki.com' ? 'noindex,nofollow' : 'index,follow';
	$robots = $out->robots;
	EntitySeo::addToOutput( $out, true );
	checkEntity( $out->htmlTitle === 'Synthetic Guardian - EQ Legends Wiki', 'Use one concise brand in an eligible entity browser title.' );
	checkEntity( $out->meta === [ [ 'description', 'Synthetic Guardian NPC reference in EQ Legends Wiki (EQLegends).' ] ], 'NPC metadata states a reference without borrowing unowned stats or promising availability.' );
	checkEntity( $out->html === $html && $out->pageTitle === '<em>Original visible heading</em>' && $out->robots === $robots, 'Preserve article markup, H1 and environment indexing policy.' );
	checkEntity( !str_contains( $out->meta[0][1], 'Foreign Zone' ) && !str_contains( $out->meta[0][1], '???' ), 'Unknown and foreign card values must never become metadata facts.' );
	$once = [ $out->htmlTitle, $out->meta ];
	EntitySeo::addToOutput( $out, true );
	checkEntity( [ $out->htmlTitle, $out->meta ] === $once, 'Repeat hooks retain one title and one description.' );
}
$out = new EntityOutput( npcEntityHtml() );
$out->meta = [ [ 'Description', 'Authored entity description.' ] ];
EntitySeo::addToOutput( $out, true );
checkEntity( $out->meta === [ [ 'Description', 'Authored entity description.' ] ] && $out->htmlTitle === 'Synthetic Guardian - EQ Legends Wiki', 'Preserve explicit descriptions while branding an eligible title.' );

$zoneIntro = '<figure><img src="/map.png"><figcaption>A misleading image caption.</figcaption></figure>' .
	'<p><img src="/view.png">A second unrelated image caption.</p>' .
	'<p>Synthetic Forest is a dark forest north of a documented crossing.</p>';
$out = new EntityOutput( zoneEntityHtml( $zoneIntro ) );
$out->title->name = 'Synthetic Forest';
$out->categories = [ 'Zones', 'Classic Era' ];
EntitySeo::addToOutput( $out, true );
checkEntity( $out->htmlTitle === 'Synthetic Forest - EQ Legends Wiki', 'Brand zone browser titles independently of embedded item names.' );
checkEntity( str_contains( $out->meta[0][1], 'Synthetic Forest is a dark forest' ) && !str_contains( $out->meta[0][1], 'caption' ), 'Only a direct paragraph beginning with the zone title supplies descriptive prose.' );
$out = new EntityOutput( zoneEntityHtml() );
$out->title->name = 'Synthetic Forest';
$out->categories = [ 'Zones' ];
EntitySeo::addToOutput( $out, true );
checkEntity( $out->meta === [ [ 'description', 'Synthetic Forest zone reference in EQ Legends Wiki (EQLegends).' ] ], 'A zone without unambiguous own prose gets a truthful reference description.' );
$out = new EntityOutput( zoneEntityHtml( '', '', false ) );
$out->title->name = 'Synthetic Forest';
$out->categories = [ 'Zones' ];
EntitySeo::addToOutput( $out, true );
checkEntity( $out->htmlTitle === 'Synthetic Forest - EQ Legends Wiki', 'Accept a legacy direct zone card as well as its known classless table layout.' );
$out = new EntityOutput( itemEntityHtml() );
$out->title->name = 'Synthetic Claymore';
$out->categories = [ 'Items' ];
ItemSeo::addToOutput( $out, true );
$html = $out->html;
$meta = $out->meta;
EntitySeo::addToOutput( $out, true );
checkEntity( $out->htmlTitle === 'Synthetic Claymore - EQ Legends Wiki' && $out->meta === $meta && $out->html === $html, 'The entity hook preserves the existing factual item summary and markup.' );
checkEntity( str_contains( $meta[0][1], 'EQ Legends Wiki' ) && str_contains( $meta[0][1], 'Base DMG: 17; delay: 45.' ), 'Item summaries use the preferred brand while retaining real own-card facts.' );

foreach ( [
	[ npcEntityHtml(), [ 'NPCs', 'Items' ] ],
	[ zoneEntityHtml(), [ 'Zones', 'NPCs' ] ],
	[ zoneEntityHtml(), [] ],
	[ npcEntityHtml( '<p>A guide to an unrelated monster.</p>' ), [ 'NPCs' ] ],
	[ zoneEntityHtml( '<p>Another Forest is a different location.</p>' ), [ 'Zones' ] ],
	[ npcEntityHtml( '', '<div class="eql-mobpage"><table class="mobStatsBox"></table></div>' ), [ 'NPCs' ] ],
	[ zoneEntityHtml( '', '<table class="zoneTopTable"></table>' ), [ 'Zones' ] ],
	[ '<div class="mw-parser-output"><div class="guide-layout"><table class="zoneTopTable"></table></div></div>', [ 'Zones' ] ],
	[ '<div class="mw-parser-output"><div><div class="eql-mobpage"><table class="mobStatsBox"></table></div></div></div>', [ 'NPCs' ] ],
	[ npcEntityHtml() . npcEntityHtml(), [ 'NPCs' ] ],
	[ '<div class="mw-parser-output">No entity card.</div>', [ 'NPCs' ] ],
	[ itemEntityHtml( 'Another item' ), [ 'Items' ] ],
] as [ $html, $categories ] ) {
	$out = new EntityOutput( $html );
	$out->categories = $categories;
	EntitySeo::addToOutput( $out, true );
	checkEntity( $out->meta === [] && $out->htmlTitle === 'Original title' && $out->html === $html, 'Reject mixed kinds, foreign leads, wrong card identity and ambiguous containers.' );
}

foreach ( [
	static function ( $o ) { $o->title = null; },
	static function ( $o ) { $o->title->namespace = 10; },
	static function ( $o ) { $o->title->exists = false; },
	static function ( $o ) { $o->title->redirect = true; },
	static function ( $o ) { $o->article = false; },
	static function ( $o ) { $o->current = false; },
	static function ( $o ) { $o->action = 'edit'; },
	static function ( $o ) { $o->parameters['oldid'] = '0'; },
	static function ( $o ) { $o->parameters['diff'] = 'prev'; },
	static function ( $o ) { $o->parameters['veaction'] = 'edit'; },
	static function ( $o ) { $o->config['CanonicalServer'] = 'https://unrelated.example'; },
	static function ( $o ) { $o->config = [ 'Server' => 'https://test.eqlwiki.com', 'CanonicalServer' => 'https://eqlwiki.com' ]; },
	static function ( $o ) { $o->config = [ 'Server' => 'http://127.0.0.1:8080', 'CanonicalServer' => 'http://127.0.0.1:8080' ]; },
] as $alter ) {
	$out = new EntityOutput( npcEntityHtml() );
	$alter( $out );
	EntitySeo::addToOutput( $out, true );
	checkEntity( $out->meta === [] && $out->htmlTitle === 'Original title', 'Exclude private, noncurrent, editing, special and mismatched environments.' );
}
$out = new EntityOutput( npcEntityHtml() );
EntitySeo::addToOutput( $out, false );
checkEntity( $out->meta === [] && $out->htmlTitle === 'Original title', 'Public metadata requires independent anonymous read permission.' );
$rawTitle = 'Épée & "Lumière"';
$out = new EntityOutput( npcEntityHtml() );
$out->title->name = $rawTitle;
EntitySeo::addToOutput( $out, true );
checkEntity( $out->htmlTitle === $rawTitle . ' - EQ Legends Wiki', 'Store unescaped Unicode, ampersands and quotes in OutputPage.' );
$escaped = htmlspecialchars( $out->htmlTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
checkEntity( str_contains( $escaped, '&amp;' ) && !str_contains( $escaped, '&amp;amp;' ), 'Native title escaping must occur exactly once.' );
$out = new EntityOutput( npcEntityHtml() );
$out->title->name = str_repeat( '長', 210 );
EntitySeo::addToOutput( $out, true );
checkEntity( mb_strlen( $out->meta[0][1], 'UTF-8' ) <= 200 && mb_check_encoding( $out->meta[0][1], 'UTF-8' ), 'Bound descriptions without splitting Unicode codepoints.' );

echo "Passed $checks synthetic entity search metadata checks; no live indexing claim.\n";
