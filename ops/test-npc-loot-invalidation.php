<?php
/** Synthetic save/link/delete/move hooks; no wiki bootstrap or real page writes. */
namespace Wikimedia\Rdbms { interface IDBAccessObject { public const READ_LATEST = 1; } }
namespace MediaWiki\Revision {
	class RevisionRecord {
		public const FOR_PUBLIC = 1;
		public function __construct( public ?string $text, public int $parent = 0, public string $model = 'wikitext' ) {}
		public function getParentId() { return $this->parent; }
		public function getContent( $slot, $audience ) {
			\invalidationCheck( $slot === 'main' && $audience === self::FOR_PUBLIC, 'Content reads are public main-slot only' );
			return $this->text === null ? null : new class( $this->text, $this->model ) {
				public function __construct( private $text, private $model ) {}
				public function getText() { return $this->text; }
				public function getModel() { return $this->model; }
			};
		}
	}
}
namespace MediaWiki\Title {
	class Title {
		public function __construct( public \InvalidationFixture $f, public string $key, public int $ns = 0 ) {}
		public function getNamespace() { return $this->ns; }
		public function getDBkey() { return $this->key; }
		public function getPrefixedText() { return str_replace( '_', ' ', $this->key ); }
		public function getFragment() { return str_contains( $this->key, '#' ) ? 'fragment' : ''; }
		public function isExternal() { return str_contains( $this->key, '://' ); }
		public function exists() { return isset( $this->f->pages[$this->key] ); }
	}
}
namespace MediaWiki {
	class MediaWikiServices {
		public static $instance;
		public function __construct( public \InvalidationFixture $f ) { self::$instance = $this; }
		public static function getInstance() { return self::$instance; }
		public function getTitleFactory() { return new \InvalidationTitles( $this->f ); }
		public function getMainWANObjectCache() { return $this->f->cache; }
		public function getUserFactory() { return new class { public function newAnonymous() { return 'anonymous'; } }; }
		public function getPermissionManager() { return new class( $this->f ) { public function __construct( private $f ) {} public function userCan( $action, $user, $title ) { \invalidationCheck( $action === 'read' && $user === 'anonymous', 'Permissions are checked as anonymous' ); return !in_array( $title->key, $this->f->private, true ); } }; }
		public function getRevisionLookup() { return new class( $this->f ) {
			public function __construct( private $f ) {}
			public function getRevisionByTitle( $title, $id = 0, $flags = 0 ) { \invalidationCheck( $flags === 1, 'Invalidation resolves current public revision on primary' ); return $this->f->pages[$title->key]['revision'] ?? null; }
			public function getRevisionById( $id, $flags ) { \invalidationCheck( $flags === 1, 'Old source revisions are read on primary' ); return $this->f->revisions[$id] ?? null; }
		}; }
		public function getRedirectLookup() { return new class( $this->f ) { public function __construct( private $f ) {} public function getRedirectTarget( $title ) { $name = $this->f->pages[$title->key]['redirect'] ?? null; return $name ? $this->f->title( $name ) : null; } }; }
		public function getWikiPageFactory() { return new class( $this->f ) { public function __construct( private $f ) {} public function newFromTitle( $title ) { return new class( $this->f, $title ) { public function __construct( private $f, private $title ) {} public function doPurge() { $this->f->purges[] = $this->title->key; } }; } }; }
		public function getConnectionProvider() { return new class( $this->f ) { public function __construct( private $f ) {} public function getPrimaryDatabase() { return new \InvalidationDatabase( $this->f ); } }; }
		public function getLinksMigration() { return new class { public function getLinksConditions( $table, $title ) { \invalidationCheck( $table === 'categorylinks', 'Fallback uses core category migration' ); return [ 'cl_target_id' => $title->key ]; } }; }
	}
}
namespace {
	const NS_MAIN = 0;
	const NS_CATEGORY = 14;
	$checks = 0;
	function invalidationCheck( bool $ok, string $message ): void { global $checks; $checks++; if ( !$ok ) { throw new \RuntimeException( $message ); } }
	class InvalidationTitles {
		public function __construct( private InvalidationFixture $f ) {}
		public function newFromText( $name, $ns = 0 ) {
			if ( $name === '' || preg_match( '/[\[\]{}|\r\n]/', $name ) ) { return null; }
			if ( str_starts_with( $name, 'Talk:' ) ) { $ns = 1; $name = substr( $name, 5 ); }
			return new \MediaWiki\Title\Title( $this->f, ucfirst( str_replace( ' ', '_', $name ) ), $ns );
		}
		public function makeTitleSafe( $ns, $name ) { return $this->newFromText( $name, $ns ); }
		public function newFromLinkTarget( $title ) { return $title; }
		public function newFromPageIdentity( $title ) { return $title; }
	}
	class InvalidationDatabase {
		public function __construct( private InvalidationFixture $f ) {}
		public function buildStringCast( $name ) { return "CAST($name AS CHAR)"; }
		public function addQuotes( $value ) { return "'" . str_replace( "'", "''", $value ) . "'"; }
		public function newSelectQueryBuilder() { return new class( $this->f ) {
			private $where;
			public function __construct( private $f ) {}
			public function select( $fields ) { return $this; }
			public function from( $table ) { invalidationCheck( $table === 'categorylinks', 'No full NPC/content table scan' ); return $this; }
			public function join( $table, $alias, $conditions ) { return $this; }
			public function where( $conditions ) { $this->where = $conditions; return $this; }
			public function limit( $limit ) { invalidationCheck( $limit === 101, 'Case fallback rows are bounded' ); return $this; }
			public function caller( $name ) { return $this; }
			public function fetchResultSet() {
				invalidationCheck( $this->where['page_namespace'] === 0, 'Case fallback is main namespace only' );
				$zone = $this->where['cl_target_id'] ?? $this->where['categorylinks.cl_target_id']; $case = $this->where[0];
				if ( isset( $this->where['item_category.cl_target_id'] ) ) { invalidationCheck( $this->where['item_category.cl_target_id'] === 'Items', 'Reverse item-case discovery also requires Items membership' ); }
				invalidationCheck( str_starts_with( $case, 'LOWER(CAST(page_title AS CHAR CHARACTER SET utf8mb4))' ), 'Case comparison converts binary wiki titles to explicit UTF-8 text' );
				preg_match_all( "/'((?:[^']|'')*)'/", $case, $quoted ); $names = array_map( static fn ( $n ) => str_replace( "''", "'", $n ), $quoted[1] );
				$rows = []; foreach ( $this->f->pages as $name => $page ) { if ( in_array( $zone, $page['zones'], true ) && in_array( strtolower( $name ), $names, true ) && ( !isset( $this->where['item_category.cl_target_id'] ) || in_array( 'Items', $page['zones'], true ) ) ) { $rows[] = (object)[ 'page_title' => $name ]; } } return $rows;
			}
		}; }
	}
	class InvalidationFixture {
		public array $pages = [], $revisions = [], $purges = [], $private = [];
		public $cache;
		public \MediaWiki\MediaWikiServices $services;
		public function __construct() {
			$this->cache = new class { public array $touches = []; public bool $fail = false; public function makeKey( ...$parts ) { return implode( ':', $parts ); } public function touchCheckKey( $key ) { if ( $this->fail ) { throw new \RuntimeException( 'synthetic failure' ); } $this->touches[] = $key; } };
			$this->services = new \MediaWiki\MediaWikiServices( $this );
		}
		public function title( $name ) { return ( new InvalidationTitles( $this ) )->newFromText( $name ); }
		public function npc( $name, $text = null, array $extra = [] ) { $this->pages[$this->title( $name )->key] = $extra + [ 'revision' => new \MediaWiki\Revision\RevisionRecord( $text ?? '{{Namedmobpage|name=' . $name . '}}' ), 'zones' => [ 'Synthetic_Vale' ] ]; }
		public function item( $name, $text = null, array $extra = [] ) { $this->pages[$this->title( $name )->key] = $extra + [ 'revision' => new \MediaWiki\Revision\RevisionRecord( $text ?? '{{Itempage|itemname=' . $name . '}}' ), 'zones' => [ 'Items', 'Synthetic_Vale' ] ]; }
		public function page() { return new class( $this->title( 'Synthetic Item' ) ) { public function __construct( private $title ) {} public function getTitle() { return $this->title; } }; }
		public function run( $new, $old = null ) { $this->revisions[9] = $old; $new->parent = $old ? 9 : 0; \EqlNpcLootInvalidation::onPageSaveComplete( $this->page(), null, '', 0, $new, null ); }
	}
	function itemRevision( $npc, $parent = 0 ) { return new \MediaWiki\Revision\RevisionRecord( '{{Itempage|itemname=Synthetic Item|dropsfrom=' . "\n[[Synthetic Vale]]\n* [[" . $npc . "]]\n}}", $parent ); }
	function npcRevision( $item, $kind = 'known_loot' ) { return new \MediaWiki\Revision\RevisionRecord( '{{Namedmobpage|name=Synthetic Guardian|zone=[[Synthetic Vale]]|' . $kind . "=\n* {{:" . $item . "}}\n}}" ); }
	class EqlItemDropLookup { public static function checkKey( $cache, \MediaWiki\Title\Title $title ): string { return $cache->makeKey( 'eql-item-source-loot-check', EqlNpcLootWikitext::identity( $title->getPrefixedText() ) ); } }
	require __DIR__ . '/../extensions/DynamicZoneList/NpcLootWikitext.php';
	require __DIR__ . '/../extensions/DynamicZoneList/NpcLootInvalidation.php';

	$f = new InvalidationFixture(); $f->npc( 'Emperor Crush' ); $f->run( itemRevision( 'Emperor Crush' ) );
	invalidationCheck( $f->purges === [ 'Emperor_Crush' ] && $f->cache->touches === [ 'eql-npc-source-loot-changed:Emperor_Crush' ], 'New item purges NPC HTML and every discovery-cache variant' );
	$f = new InvalidationFixture(); $f->npc( 'First Guardian' ); $f->npc( 'Second Guardian' ); $f->run( itemRevision( 'Second Guardian' ), itemRevision( 'First Guardian' ) );
	sort( $f->purges ); invalidationCheck( $f->purges === [ 'First_Guardian', 'Second_Guardian' ], 'An edit invalidates both removed and added source NPCs' );
	$f = new InvalidationFixture(); $f->npc( 'First Guardian' ); $f->run( new \MediaWiki\Revision\RevisionRecord( '{{Itempage|itemname=Synthetic Item}}' ), itemRevision( 'First Guardian' ) );
	invalidationCheck( $f->purges === [ 'First_Guardian' ], 'Removing all dropsfrom references still purges the old NPC' );
	$f = new InvalidationFixture(); $f->npc( 'Real Guardian' ); $f->npc( 'Alias Guardian', '#REDIRECT [[Real Guardian]]', [ 'redirect' => 'Real Guardian' ] ); $f->run( itemRevision( 'Alias Guardian' ) );
	invalidationCheck( $f->purges === [ 'Real_Guardian' ], 'Redirect source invalidates the canonical NPC' );
	$f = new InvalidationFixture(); $f->npc( 'A Loathing Lich' ); $f->run( itemRevision( 'A loathing lich' ) );
	invalidationCheck( $f->purges === [ 'A_Loathing_Lich' ], 'Case-variant redlink finds the existing public same-zone NPC' );
	$f = new InvalidationFixture(); $f->npc( 'A Loathing Lich' ); $f->npc( 'A loathing lich' ); $f->run( itemRevision( 'A loathing lich' ) );
	invalidationCheck( $f->purges === [ 'A_loathing_lich' ], 'Two real case-distinct NPC pages remain separate' );
	$f = new InvalidationFixture(); $f->npc( 'First Guardian' ); $r = itemRevision( 'First Guardian' );
	\EqlNpcLootInvalidation::onPageDeleteComplete( $f->title( 'Synthetic Item' ), null, '', 1, $r, null, 1 );
	invalidationCheck( $f->purges === [ 'First_Guardian' ], 'Deleting an item invalidates its previous NPC source' );
	$f->purges = []; \EqlNpcLootInvalidation::onPageMoveComplete( $f->title( 'Old Item' ), $f->title( 'New Item' ), null, 1, 2, '', $r );
	invalidationCheck( $f->purges === [ 'First_Guardian' ], 'Moving an item refreshes the canonical item title in loot' );
	$f->purges = []; $links = new class( $f, $r ) { public function __construct( private $f, private $r ) {} public function getTitle() { return $this->f->title( 'Synthetic Item' ); } public function getRevisionRecord() { return $this->r; } };
	\EqlNpcLootInvalidation::onLinksUpdateComplete( $links, null ); invalidationCheck( $f->purges === [ 'First_Guardian' ], 'Link-table completion repeats the purge after source indexes commit' );
	$f = new InvalidationFixture(); $f->npc( 'First Guardian' ); $f->run( new \MediaWiki\Revision\RevisionRecord( '<nowiki>{{Itempage|dropsfrom=[[First Guardian]]}}</nowiki>' ) );
	invalidationCheck( $f->purges === [], 'Protected examples and ordinary prose do not establish cross-links' );
	$f->run( itemRevision( 'Uncreated Guardian' ) ); invalidationCheck( $f->purges === [], 'A missing NPC does not create a page or pretend to edit it' );
	$f->private = [ 'First_Guardian' ]; $f->run( itemRevision( 'First Guardian' ) ); invalidationCheck( $f->purges === [], 'Unreadable NPC content is not inspected or purged' );
	$f->private = []; $f->pages['First_Guardian']['revision'] = new \MediaWiki\Revision\RevisionRecord( null ); $f->run( itemRevision( 'First Guardian' ) ); invalidationCheck( $f->purges === [], 'Suppressed NPC text cannot enter invalidation discovery' );
	$f->pages['First_Guardian']['revision'] = new \MediaWiki\Revision\RevisionRecord( 'An ordinary page' ); $f->run( itemRevision( 'First Guardian' ) ); invalidationCheck( $f->purges === [], 'Non-NPC source titles are not purged' );
	$f = new InvalidationFixture(); $f->npc( 'First Guardian' ); $f->cache->fail = true; $f->run( itemRevision( 'First Guardian' ) ); invalidationCheck( $f->purges === [], 'A failed cache operation cannot abort the completed edit hook' );
	$f = new InvalidationFixture(); $f->npc( 'First Guardian' );
	\EqlNpcLootInvalidation::onPageDeleteComplete( $f->title( 'Synthetic Item' ), null, '', 1, null, null, 1 );
	invalidationCheck( $f->purges === [], 'Missing deletion content is skipped without an edit failure' );
	$f->run( new \MediaWiki\Revision\RevisionRecord( null ), itemRevision( 'First Guardian' ) );
	invalidationCheck( $f->purges === [ 'First_Guardian' ], 'A newly suppressed item still invalidates the formerly public source' );
	$f = new InvalidationFixture(); $f->item( 'Early Relic' ); $f->run( npcRevision( 'Early Relic' ) );
	invalidationCheck( $f->purges === [ 'Early_Relic' ] && $f->cache->touches === [ 'eql-item-source-loot-check:early relic' ], 'A new NPC authored loot row purges the existing item and its reverse-discovery cache' );
	$f = new InvalidationFixture(); $f->item( 'First Relic' ); $f->item( 'Second Relic' ); $f->run( npcRevision( 'Second Relic', 'common_loot' ), npcRevision( 'First Relic' ) ); sort( $f->purges );
	invalidationCheck( $f->purges === [ 'First_Relic', 'Second_Relic' ], 'NPC edit invalidates both removed unique loot and added common loot items' );
	$f = new InvalidationFixture(); $f->item( 'First Relic' ); $f->run( new \MediaWiki\Revision\RevisionRecord( '{{Namedmobpage|name=Synthetic Guardian}}' ), npcRevision( 'First Relic' ) );
	invalidationCheck( $f->purges === [ 'First_Relic' ], 'Removing all NPC loot references still purges old item sources' );
	$f = new InvalidationFixture(); $f->item( 'Real Relic' ); $f->item( 'Alias Relic', '#REDIRECT [[Real Relic]]', [ 'redirect' => 'Real Relic' ] ); $f->run( npcRevision( 'Alias Relic' ) );
	invalidationCheck( $f->purges === [ 'Real_Relic' ], 'NPC loot redirect refreshes the canonical item' );
	$f = new InvalidationFixture(); $f->item( 'Early Relic', null, [ 'zones' => [ 'Items' ] ] ); $f->run( npcRevision( 'Early relic' ) );
	invalidationCheck( $f->purges === [ 'Early_Relic' ], 'NPC case-variant item redlink resolves an Items member even without a zone category' );
	$f = new InvalidationFixture(); $f->item( 'Early Relic', null, [ 'zones' => [ 'Items' ] ] ); $f->run( new \MediaWiki\Revision\RevisionRecord( "{{Namedmobpage|name=Synthetic Guardian|known_loot=* {{:Early relic}}}}" ) );
	invalidationCheck( $f->purges === [ 'Early_Relic' ], 'An NPC with unknown zone can still invalidate its exact-name folded item target' );
	$f = new InvalidationFixture(); $f->item( 'Early Relic' ); $f->item( 'Early relic' ); $f->run( npcRevision( 'Early relic' ) );
	invalidationCheck( $f->purges === [ 'Early_relic' ], 'Real case-distinct item titles remain separate when invalidating' );
	$f = new InvalidationFixture(); $f->item( 'Early Relic' ); $r = npcRevision( 'Early Relic' );
	\EqlNpcLootInvalidation::onPageDeleteComplete( $f->title( 'Synthetic Guardian' ), null, '', 1, $r, null, 1 ); invalidationCheck( $f->purges === [ 'Early_Relic' ], 'Deleting an NPC purges its formerly linked item sources' );
	$f->purges = []; \EqlNpcLootInvalidation::onPageMoveComplete( $f->title( 'Old Guardian' ), $f->title( 'New Guardian' ), null, 1, 2, '', $r ); invalidationCheck( $f->purges === [ 'Early_Relic' ], 'Moving an NPC refreshes source names on item pages' );
	$f->purges = []; $links = new class( $f, $r ) { public function __construct( private $f, private $r ) {} public function getTitle() { return $this->f->title( 'Synthetic Guardian' ); } public function getRevisionRecord() { return $this->r; } }; \EqlNpcLootInvalidation::onLinksUpdateComplete( $links, null ); invalidationCheck( $f->purges === [ 'Early_Relic' ], 'NPC link-table completion repeats item purge after links commit' );
	$f = new InvalidationFixture(); $f->item( 'Early Relic' ); $f->private = [ 'Early_Relic' ]; $f->run( npcRevision( 'Early Relic' ) ); invalidationCheck( $f->purges === [], 'Private item contents are not inspected or purged' );
	$f->private = []; $f->pages['Early_Relic']['revision'] = new \MediaWiki\Revision\RevisionRecord( null ); $f->run( npcRevision( 'Early Relic' ) ); invalidationCheck( $f->purges === [], 'Suppressed item content cannot establish an invalidation target' );
	$f->pages['Early_Relic']['revision'] = new \MediaWiki\Revision\RevisionRecord( 'An ordinary article' ); $f->run( npcRevision( 'Early Relic' ) ); invalidationCheck( $f->purges === [], 'A loot link to a non-item article is not treated as an item' );
	$f = new InvalidationFixture(); $f->item( 'Early Relic' ); $f->run( new \MediaWiki\Revision\RevisionRecord( '<nowiki>{{Namedmobpage|known_loot={{:Early Relic}}}}</nowiki>' ) ); invalidationCheck( $f->purges === [], 'Protected NPC examples cannot invalidate item relationships' );
	echo "NPC loot invalidation: $checks checks passed.\n";
}
