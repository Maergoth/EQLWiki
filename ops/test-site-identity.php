<?php
/** Synthetic homepage branding/environment checks; no database or network calls. */
if ( PHP_SAPI !== 'cli' ) {
	exit( "Run this fixture from the command line.\n" );
}
define( 'NS_MAIN', 0 );
require __DIR__ . '/../skins/EQLImmersive/includes/SiteIdentity.php';

use EQLImmersive\SiteIdentity;

class IdentityTitle {
	public int $namespace = 0;
	public bool $exists = true;
	public bool $redirect = false;
	public bool $main = true;
	public function getNamespace() { return $this->namespace; }
	public function exists() { return $this->exists; }
	public function isRedirect() { return $this->redirect; }
	public function isMainPage() { return $this->main; }
}
class IdentityOutput {
	public ?IdentityTitle $title;
	public array $config = [ 'Server' => 'https://eqlwiki.com', 'CanonicalServer' => 'https://eqlwiki.com' ];
	public bool $article = true;
	public bool $current = true;
	public string $action = 'view';
	public array $parameters = [];
	public array $meta = [];
	public array $heads = [];
	public string $htmlTitle = 'Original title';
	public string $pageTitle = 'Original visible heading';
	public string $robots = 'index,follow';
	public function __construct() { $this->title = new IdentityTitle(); }
	public function getTitle() { return $this->title; }
	public function getConfig() { return $this; }
	public function get( $key ) { return $this->config[$key] ?? null; }
	public function isArticle() { return $this->article; }
	public function isRevisionCurrent() { return $this->current; }
	public function getContext() { return $this; }
	public function getActionName() { return $this->action; }
	public function getRequest() { return $this; }
	public function getVal( $key ) { return $this->parameters[$key] ?? null; }
	public function getMetaTags() { return $this->meta; }
	public function addMeta( $name, $value ) { $this->meta[] = [ $name, $value ]; }
	public function getHeadItemsArray() { return $this->heads; }
	public function addHeadItem( $key, $html ) { $this->heads[$key] = $html; }
	public function setHTMLTitle( $title ) { $this->htmlTitle = $title; }
}
$checks = 0;
function checkIdentity( bool $condition, string $message ): void {
	global $checks;
	$checks++;
	if ( !$condition ) { throw new RuntimeException( $message ); }
}
function websiteData( IdentityOutput $out ): array {
	$script = $out->heads['eql-site-identity'] ?? '';
	checkIdentity( preg_match( '~^<script type="application/ld\+json">(.+)</script>$~s', $script, $match ) === 1,
		'One valid non-executable JSON-LD script is present.' );
	return json_decode( $match[1], true, 32, JSON_THROW_ON_ERROR );
}
foreach ( [ 'https://eqlwiki.com', 'https://test.eqlwiki.com' ] as $origin ) {
	$out = new IdentityOutput();
	$out->config = [ 'Server' => $origin, 'CanonicalServer' => $origin ];
	$out->robots = $origin === 'https://test.eqlwiki.com' ? 'noindex,nofollow' : 'index,follow';
	$robots = $out->robots;
	SiteIdentity::addToOutput( $out, true );
	$data = websiteData( $out );
	checkIdentity( $data['@type'] === 'WebSite' && $data['name'] === 'EQ Legends Wiki', 'Preferred site name is concise.' );
	checkIdentity( $data['url'] === $origin . '/' && $data['@id'] === $origin . '/#website', 'Use only the configured environment origin.' );
	foreach ( [ 'EQLegends Wiki', 'EverQuest Legends Wiki', 'EQ Legends', 'EQLegends' ] as $alias ) {
		checkIdentity( in_array( $alias, $data['alternateName'], true ), 'Publish an established alternate name: ' . $alias );
	}
	checkIdentity( $out->htmlTitle === 'EQ Legends Wiki - Items, NPCs and Zones', 'Homepage title explains its lookup purpose.' );
	checkIdentity( $out->pageTitle === 'Original visible heading' && $out->robots === $robots, 'Do not alter visible headings or robots policy.' );
	$once = [ $out->heads, $out->meta ];
	SiteIdentity::addToOutput( $out, true );
	checkIdentity( [ $out->heads, $out->meta ] === $once, 'Repeat hooks do not duplicate schema or descriptions.' );
}
$out = new IdentityOutput();
$out->meta = [ [ 'description', 'Authored homepage description.' ] ];
SiteIdentity::addToOutput( $out, true );
checkIdentity( $out->meta === [ [ 'description', 'Authored homepage description.' ] ], 'Preserve an explicit homepage description.' );
$out = new IdentityOutput();
$out->heads['existing-schema'] = '<script type="application/ld+json">{"@graph":[{"@type":["Thing","WebSite"],"name":"Existing"}]}</script>';
$before = $out->heads;
SiteIdentity::addToOutput( $out, true );
checkIdentity( $out->heads === $before, 'Preserve an existing WebSite provider instead of adding a second node.' );
$out = new IdentityOutput();
$out->heads['existing-schema'] = '<script type=application/ld+json>{"@type":"WebSite","name":"Existing"}</script>';
$before = $out->heads;
SiteIdentity::addToOutput( $out, true );
checkIdentity( $out->heads === $before, 'Recognize valid unquoted JSON-LD type attributes as an existing provider.' );
foreach ( [
	static function ( $o ) { $o->title = null; },
	static function ( $o ) { $o->title->namespace = 2; },
	static function ( $o ) { $o->title->exists = false; },
	static function ( $o ) { $o->title->redirect = true; },
	static function ( $o ) { $o->title->main = false; },
	static function ( $o ) { $o->article = false; },
	static function ( $o ) { $o->current = false; },
	static function ( $o ) { $o->action = 'edit'; },
	static function ( $o ) { $o->parameters['oldid'] = '0'; },
	static function ( $o ) { $o->parameters['diff'] = 'prev'; },
	static function ( $o ) { $o->parameters['veaction'] = 'edit'; },
	static function ( $o ) { $o->config['CanonicalServer'] = 'https://other.example'; },
	static function ( $o ) { $o->config = [ 'Server' => 'http://127.0.0.1:8080', 'CanonicalServer' => 'http://127.0.0.1:8080' ]; },
	static function ( $o ) { $o->config = [ 'Server' => 'https://test.eqlwiki.com', 'CanonicalServer' => 'https://eqlwiki.com' ]; },
] as $alter ) {
	$out = new IdentityOutput();
	$alter( $out );
	SiteIdentity::addToOutput( $out, true );
	checkIdentity( $out->heads === [] && $out->meta === [] && $out->htmlTitle === 'Original title', 'Excluded views/environments retain their existing output.' );
}
$out = new IdentityOutput();
SiteIdentity::addToOutput( $out, false );
checkIdentity( $out->heads === [] && $out->meta === [], 'Private content gets no public search identity.' );
echo "Passed $checks synthetic homepage site-identity checks; no live-site indexing claim.\n";
