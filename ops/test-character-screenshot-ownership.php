<?php
/** Synthetic server-hook lifecycle. No accounts, files, wiki pages or database writes. */
declare( strict_types = 1 );

namespace MediaWiki\Permissions\Hook { interface GetUserPermissionsErrorsHook {} }
namespace MediaWiki\Hook { interface UploadVerifyUploadHook {} interface UploadCompleteHook {} interface ParserFirstCallInitHook {} }
namespace MediaWiki\User { class UserRigorOptions { public const RIGOR_USABLE = 'usable'; } }
namespace Wikimedia\Rdbms {
	interface IExpression { public const LIKE = 'LIKE'; }
	class LikeValue { public function __construct( public string $prefix, $wildcard ) {} }
}
namespace MediaWiki\Title {
	class Title {
		private function __construct( private int $ns, private string $text ) {}
		public static function makeTitleSafe( $ns, $text ) {
			if ( !preg_match( '//u', $text ) || preg_match( '/[\x00-\x1f\x7f<>\[\]{}|#]/', $text ) || str_starts_with( $text, '../' ) ) { return null; }
			$text = trim( str_replace( '_', ' ', $text ) ); return $text === '' ? null : new self( $ns, ucfirst( $text ) );
		}
		public static function newFromText( $text ) { return str_starts_with( $text, 'File:' ) ? self::makeTitleSafe( 6, substr( $text, 5 ) ) : null; }
		public function getNamespace() { return $this->ns; }
		public function getText() { return $this->text; }
		public function getDBkey() { return str_replace( ' ', '_', $this->text ); }
		public function getRootText() { return explode( '/', $this->text, 2 )[0]; }
		public function getPrefixedText() { return ( $this->ns === 6 ? 'File:' : ( $this->ns === 2 ? 'User:' : '' ) ) . $this->text; }
		public function isExternal() { return false; }
		public function getFragment() { return ''; }
		public function getLocalURL( $query = [] ) { return '/wiki/' . rawurlencode( $this->getPrefixedText() ) . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' ); }
	}
}
namespace MediaWiki {
	class MediaWikiServices {
		public static $instance;
		public function __construct( public $db, public $repo, public $pages ) {}
		public static function getInstance() { return self::$instance; }
		public function getConnectionProvider() { return new class( $this->db ) { public function __construct( private $db ) {} public function getPrimaryDatabase() { return $this->db; } }; }
		public function getUserNameUtils() { return new class { public function getCanonical( $name, $rigor ) { \check( $rigor === 'usable', 'Use native login-usable account normalization.' ); return preg_match( '~[/@:<>#]~', $name ) ? false : ucfirst( trim( str_replace( '_', ' ', $name ) ) ); } }; }
		public function getRepoGroup() { return $this->repo; }
		public function getWikiPageFactory() { return $this->pages; }
	}
}
namespace MediaWiki\Html {
	class Html {
		public static function rawElement( $tag, $attrs, $html ) { $a = ''; foreach ( $attrs as $key => $value ) { $a .= ' ' . $key . '="' . htmlspecialchars( (string)$value, ENT_QUOTES ) . '"'; } return '<' . $tag . $a . '>' . $html . '</' . $tag . '>'; }
		public static function element( $tag, $attrs, $text ) { return self::rawElement( $tag, $attrs, htmlspecialchars( $text ) ); }
	}
}
namespace MediaWiki\SpecialPage { class SpecialPage { public static function getTitleFor( $name ) { \check( $name === 'Upload', 'Use core Upload destination.' ); return \MediaWiki\Title\Title::makeTitleSafe( -1, 'Special:Upload' ); } } }
namespace {
	use EQLImmersive\CharacterScreenshotOwnership as Policy;
	use EQLImmersive\CharacterScreenshot as Screenshot;
	use MediaWiki\Title\Title;
	define( 'NS_USER', 2 ); define( 'NS_FILE', 6 );
	$checks = 0; $logs = [];
	function check( bool $ok, string $message ): void { global $checks; if ( !$ok ) { throw new \RuntimeException( $message ); } $checks++; }
	function wfMessage( $key ) { return new class( $key ) { public function __construct( private string $key ) {} public function text() { $m = json_decode( file_get_contents( __DIR__ . '/../skins/EQLImmersive/i18n/en.json' ), true ); return $m[$this->key]; } }; }
	function wfEscapeWikiText( $text ) { return htmlspecialchars( $text ); }
	function wfDebugLog( $channel, $message ) { global $logs; $logs[] = [ $channel, $message ]; }
	require __DIR__ . '/../skins/EQLImmersive/includes/CharacterScreenshotOwnership.php';
	require __DIR__ . '/../skins/EQLImmersive/includes/CharacterScreenshot.php';
	class Expr { public function __construct( public string $field, public $value ) {} public function or( $field, $op, $value ) { return $this; } }
	class DB {
		public array $users = [ 'Alice' => 1, 'Bob' => 2, 'Root Name' => 3, 'Éowyn' => 4 ]; public array $queries = []; public array $usage = [];
		public bool $fail = false;
		public function newSelectQueryBuilder() { return new Builder( $this ); }
		public function expr( $field, $op, $value ) { return new Expr( $field, $value ); }
		public function anyString() { return '%'; }
	}
	class Builder {
		private string $table = ''; private array $where = []; private int $limit = 0;
		public function __construct( private DB $db ) {}
		public function select( $fields ) { return $this; } public function from( $table ) { $this->table = $table; return $this; }
		public function join( ...$args ) { return $this; } public function andWhere( $where ) { return $this; }
		public function where( $where ) { $this->where = $where; return $this; } public function limit( $n ) { $this->limit = $n; return $this; }
		public function orderBy( ...$args ) { return $this; } public function caller( $caller ) { return $this; }
		public function fetchResultSet() {
			$this->db->queries[] = [ $this->table, $this->where, $this->limit ];
			if ( $this->db->fail ) { throw new \RuntimeException( 'Synthetic database unavailable' ); }
			if ( $this->table === 'user' ) { check( $this->limit <= 81, 'Filename length gives a finite exact-account query.' ); $out = []; foreach ( $this->where['user_name'] as $name ) { if ( isset( $this->db->users[$name] ) ) { $out[] = (object)[ 'user_name' => $name, 'user_id' => $this->db->users[$name] ]; } } return $out; }
			check( $this->table === 'imagelinks' && $this->where['page_namespace'] === NS_USER && $this->limit === Policy::PURGE_LIMIT, 'Invalidation uses bounded native User image usage.' );
			return array_map( static fn ( $name ) => (object)[ 'page_title' => $name ], array_slice( $this->db->usage, 0, $this->limit ) );
		}
	}
	class Repo {
		public array $files = []; public array $reads = [];
		public function findFile( $title, $options ) { check( $options === [ 'latest' => true ], 'Resolved image ownership uses latest native repository lookup.' ); $this->reads[] = $title->getText(); return $this->files[$title->getText()] ?? false; }
	}
	class File {
		public function __construct( private string $name, private bool $local = true ) {}
		public function getTitle() { return Title::makeTitleSafe( NS_FILE, $this->name ); }
		public function getRepo() { return new class( $this->local ) { public function __construct( private bool $local ) {} public function isLocal() { return $this->local; } }; }
	}
	class Pages {
		public array $purged = []; public array $existing = [ 'User:Alice', 'User:Alice/Sub', 'User:Alice/Other', 'User:Bob', 'User:Alice other' ];
		public function newFromTitle( $title ) { return new class( $this, $title ) { public function __construct( private $pages, private $title ) {} public function exists() { return in_array( $this->title->getPrefixedText(), $this->pages->existing, true ); } public function doPurge() { $this->pages->purged[] = $this->title->getPrefixedText(); } }; }
	}
	class User {
		public function __construct( public int $id, private bool $moderator = false, private bool $named = true ) {}
		public function getId() { return $this->id; } public function isNamed() { return $this->named; }
		public function isAllowed( $right ) { check( $right === 'editalluserpages', 'Only the established explicit maintenance right can bypass portrait owner policy.' ); return $this->moderator; }
	}
	class Upload { public function __construct( public $title ) {} public function getTitle() { return $this->title; } }
	class Parser {
		public array $parsed = [];
		public function __construct( private $title ) {} public function getTitle() { return $this->title; }
		public function recursiveTagParse( $text, $frame ) { $this->parsed[] = $text; return '<a href="/wiki/File:Owned.png"><img src="/images/owned.png" alt="Character screenshot"></a>'; }
	}
	function fresh(): array { $db = new DB(); $repo = new Repo(); $pages = new Pages(); $services = new \MediaWiki\MediaWikiServices( $db, $repo, $pages ); \MediaWiki\MediaWikiServices::$instance = $services; return [ new Policy( $services ), $db, $repo, $pages ]; }
	function fileTitle( string $text ): Title { return Title::makeTitleSafe( NS_FILE, $text ); }
	function render( string $page = 'Alice', array $args = [], int $ns = NS_USER ): array { $p = new Parser( Title::makeTitleSafe( $ns, $page ) ); $html = Screenshot::render( '', $args + [ 'name' => 'Hero', 'server' => 'Realm' ], $p, null ); $d = new \DOMDocument(); $d->loadHTML( $html ); return [ $html, new \DOMXPath( $d ), $p ]; }
	[ $policy, $db, $repo, $pages ] = fresh();
	$alice = new User( 1 ); $bob = new User( 2 ); $anonymous = new User( 0, false, false ); $moderator = new User( 99, true );
	$portrait = fileTitle( 'Character Alice - Hero (Realm).png' );
	foreach ( [ 'edit', 'create', 'upload', 'move', 'move-target' ] as $action ) {
		foreach ( [ [ $alice, true ], [ $bob, false ], [ $anonymous, false ], [ $moderator, true ] ] as [ $user, $allowed ] ) {
			$error = [ 'earlier-core-error' ]; $result = $policy->onGetUserPermissionsErrors( $portrait, $user, $action, $error );
			check( $result === $allowed, 'Owner/moderator vs foreign/anonymous protection applies before the first file or User page exists.' );
			check( $allowed ? $error === [ 'earlier-core-error' ] : $error === [ 'eql-character-screenshot-owner-required' ], 'Allowing owner/moderator never clears protections or blocks; denial stops clearing hooks.' );
		}
	}
	$count = count( $db->queries ); $error = [ 'prior' ];
	check( $policy->onGetUserPermissionsErrors( $portrait, $bob, 'read', $error ) && count( $db->queries ) === $count && $error === [ 'prior' ], 'Public reading is unchanged and needs no ownership query.' );
	foreach ( [ 'Item_500.png', 'Ordinary screenshot.png', 'Character selection.png' ] as $ordinary ) { check( $policy->onGetUserPermissionsErrors( fileTitle( $ordinary ), $bob, 'upload', $error ), 'Ordinary icons and files retain core permissions.' ); }
	check( $policy->ownerOf( fileTitle( 'Character Future owner - Hero.png' ) )['ownerId'] === null, 'A nonexistent account cannot have its reserved filename preclaimed.' );
	$db->users['A'] = 10; $db->users['A - B'] = 11;
	$ambiguous = fileTitle( 'Character A - B - C (S).png' );
	check( $policy->ownerOf( $ambiguous )['ownerId'] === null, 'All delimiters are considered; both valid accounts create ambiguity.' );
	foreach ( [ new User( 10 ), new User( 11 ) ] as $user ) { check( !$policy->onGetUserPermissionsErrors( $ambiguous, $user, 'upload', $error ), 'Neither first nor longest account wins an ambiguous reserved name.' ); }
	check( $policy->onGetUserPermissionsErrors( $ambiguous, $moderator, 'upload', $error ), 'Explicit maintenance can repair ambiguous names with core rights still required.' );
	foreach ( [ [ 'Character Root_Name/Sub_page - Hero (S).png', 3 ], [ 'Character Éowyn/Sub - Héro (Réalme).png', 4 ], [ 'Character Alice - Hero (S) - 0123456789abcdef.png', 1 ] ] as [ $name, $id ] ) { check( $policy->ownerOf( fileTitle( $name ) )['ownerId'] === $id, 'Native normalized spaces, Unicode, subpages and app collision suffix retain stable root account identity.' ); }
	check( !$policy->belongsToPage( fileTitle( 'Character Alice/Sub - Hero.png' ), Title::makeTitleSafe( NS_USER, 'Alice' ) ), 'An override must match the exact full User PAGENAME, not another owned subpage.' );
	check( !$policy->belongsToPage( $portrait, Title::makeTitleSafe( 0, 'Alice' ) ), 'Other namespaces cannot manufacture portrait ownership.' );
	$db->fail = true; check( !$policy->onGetUserPermissionsErrors( $portrait, $alice, 'upload', $error ), 'Lookup failure fails closed.' ); $db->fail = false;
	$oldCount = count( $db->queries ); check( $policy->ownerOf( fileTitle( 'Character Alice - ' . str_repeat( 'x', 241 ) ) )['ownerId'] === null && count( $db->queries ) === $oldCount, 'Oversized reserved descriptions cannot cause an unbounded lookup.' );
	// Final publication is shared by Special:Upload/API/filekey/async publication.
	foreach ( [ 'direct', 'filekey-after-renaming-stash', 'chunk-final', 'async-final', 'forged-return-context' ] as $route ) {
		$upload = new Upload( $portrait ); $error = null;
		check( !$policy->onUploadVerifyUpload( $upload, $bob, null, 'Owner claim in comment', 'eqlReturnTo=User:Alice', $error ) && $error !== null, 'Final ' . $route . ' cannot bypass ownership via stash creator or request metadata.' );
	}
	$upload = new Upload( $portrait ); $error = [ 'core-blocked' ]; check( $policy->onUploadVerifyUpload( $upload, $moderator, null, '', false, $error ) && $error === [ 'core-blocked' ], 'Moderator does not override final existing verification errors.' );
	// Both web/API revert authorize File upload; moves authorize source AND target.
	check( !$policy->onGetUserPermissionsErrors( $portrait, $bob, 'upload', $error ), 'Binary revert is blocked even though it bypasses UploadBase final verification.' );
	foreach ( [ [ $portrait, fileTitle( 'Ordinary.png' ), $bob ], [ fileTitle( 'Ordinary.png' ), $portrait, $bob ], [ $portrait, fileTitle( 'Character Bob - H.png' ), $alice ] ] as [ $old, $new, $user ] ) {
		$allowed = $policy->onGetUserPermissionsErrors( $old, $user, 'move', $error ) && $policy->onGetUserPermissionsErrors( $new, $user, 'move-target', $error ); check( !$allowed, 'Rename cannot move into/out of another account reserved namespace.' );
	}
	// Only a final permitted upload to a verified owner triggers bounded cache invalidation.
	[ $policy, $db, $repo, $pages ] = fresh(); $db->usage = [ 'Alice/Sub', 'Bob', 'Alice/Other', 'Alice_other' ];
	$upload = new Upload( fileTitle( 'Character Alice/Sub - Hero.png' ) ); $error = null;
	check( $policy->onUploadVerifyUpload( $upload, $alice, null, '', false, $error ), 'Exact owner passes final upload.' );
	$policy->onUploadComplete( $upload ); sort( $pages->purged );
	check( $pages->purged === [ 'User:Alice', 'User:Alice/Other', 'User:Alice/Sub' ], 'Successful upload invalidates only existing root/exact/same-owner native image usage; never a foreign or prefix-lookalike User page.' );
	$count = count( $pages->purged ); $policy->onUploadComplete( $upload ); check( count( $pages->purged ) === $count, 'Completion tracking is single-use and cannot replay invalidation.' );
	$policy->onUploadComplete( new Upload( $portrait ) ); check( count( $pages->purged ) === $count, 'No final verified owner means no arbitrary completion purge.' );
	$pages->purged = []; $error = null; $repair = new Upload( $portrait );
	$policy->onUploadVerifyUpload( $repair, $moderator, null, '', false, $error ); $policy->onUploadComplete( $repair ); sort( $pages->purged );
	check( $pages->purged === [ 'User:Alice', 'User:Alice/Other', 'User:Alice/Sub' ], 'Permitted moderator repair invalidates the verified owner and same-owner references, never a supplied return target.' );
	$count = count( $pages->purged ); $error = null; $unresolved = new Upload( fileTitle( 'Character Unknown owner - H.png' ) );
	$policy->onUploadVerifyUpload( $unresolved, $moderator, null, '', false, $error ); $policy->onUploadComplete( $unresolved );
	check( count( $pages->purged ) === $count, 'Moderator repair of an unresolved target does not invent a purge owner.' );
	// Actual helper source: all image/override paths use the identical policy.
	[ $policy, $db, $repo, $pages ] = fresh();
	[ $html, $xpath, $parser ] = render();
	check( $xpath->query( '//*[@data-file-title="File:Character Alice - Hero (Realm).png"]' )->length === 1 && str_contains( $html, 'eqlReturnTo=User%3AAlice' ), 'Default missing image has a native own-page filename/return even before any page edit.' );
	check( count( $parser->parsed ) === 1, 'Missing owned file still registers its native image dependency.' );
	$repo->files['Character Alice - Hero (Realm).png'] = new File( 'Character Alice - Hero (Realm).png' );
	[ $html, $xpath ] = render(); check( $xpath->query( '//img' )->length === 1 && $xpath->query( '//*[contains(@class,"is-missing")]' )->length === 0, 'Existing own portrait remains rendered without modification.' );
	foreach ( [ 'Item_500.png', 'Character Bob - Hero.png', 'Character Alice/Sub - Hero.png', 'https://outside.invalid/a.png' ] as $override ) {
		[ $html, $xpath ] = render( 'Alice', [ 'screenshot' => $override ] );
		check( $xpath->query( '//img' )->length === 0 && $xpath->query( '//*[@data-file-title="File:Character Alice - Hero (Realm).png"]' )->length === 1 && str_contains( $html, 'Clear or change' ) && !str_contains( $html, 'automatically' ), 'Borrowed/foreign/cross-subpage/unsafe override keeps an honest default placeholder; never claims shared artwork.' );
	}
	$custom = 'Character Alice - Custom portrait.jpg'; $repo->files[$custom] = new File( $custom );
	[ $html, $xpath ] = render( 'Alice', [ 'screenshot' => $custom ] ); check( $xpath->query( '//img' )->length === 1, 'An own full-page-prefixed custom portrait is supported.' );
	foreach ( [ new File( 'Item_500.png' ), new File( 'Character Bob - Shared.png' ), new File( 'Character Alice/Sub - Different.png' ), new File( 'Character Alice - Document.pdf' ), new File( 'Character Alice - Remote.png', false ) ] as $file ) {
		$repo->files['Character Alice - Hero (Realm).png'] = $file; [ $html, $xpath ] = render();
		check( $xpath->query( '//img' )->length === 0 && str_contains( $html, 'shared or foreign' ), 'Resolved foreign/shared/cross-page binary cannot bypass literal portrait ownership through a redirect/repository.' );
	}
	$count = count( $db->queries ); $db->fail = true;
	[ $html, $xpath ] = render( 'Unknown synthetic account/Subpage' );
	check( $xpath->query( '//*[@data-file-title="File:Character Unknown synthetic account/Subpage - Hero (Realm).png"]' )->length === 1 && count( $db->queries ) === $count, 'Unsaved synthetic User previews retain their exact default filename without any account query, including while SQL is unavailable.' );
	$db->fail = false;
	[ $html, $xpath ] = render( 'Alice', [], 0 ); check( $xpath->query( '//*[@data-file-title]' )->length === 0 && $xpath->query( '//img' )->length === 0, 'Unsupported namespace does not manufacture a usable owned target.' );
	$error = null; check( !$policy->onGetUserPermissionsErrors( fileTitle( 'Character Unknown synthetic account/Subpage - Hero (Realm).png' ), $alice, 'upload', $error ), 'Rendering a synthetic default never grants its mutation rights to another account.' );
	$manifest = json_decode( file_get_contents( __DIR__ . '/../skins/EQLImmersive/skin.json' ), true, 512, JSON_THROW_ON_ERROR );
	foreach ( [ 'getUserPermissionsErrors', 'UploadVerifyUpload', 'UploadComplete' ] as $hook ) { check( $manifest['Hooks'][$hook] === 'EQLCharacterScreenshotOwnership', 'Skin manifest registers the server policy globally, not only on the selected skin.' ); }
	echo "Character screenshot ownership: $checks synthetic checks passed; no accounts/files/pages/database writes.\n";
}
