<?php
/** Synthetic core-service doubles: no wiki bootstrap, accounts or writes. */
namespace MediaWiki\Revision { class RevisionRecord { public const FOR_PUBLIC = 1; } }
namespace MediaWiki\Title {
	class Title {
		public function __construct( public \NpcLookupFixture $fixture, private string $key, private int $namespace = 0 ) {}
		public function getDBkey() { return $this->key; }
		public function getPrefixedText() { return ( $this->namespace === 14 ? 'Category:' : '' ) . str_replace( '_', ' ', $this->key ); }
		public function getNamespace() { return $this->namespace; }
		public function isExternal() { return false; }
		public function getFragment() { return ''; }
		public function exists() { return isset( $this->fixture->pages[$this->key] ); }
		public function getArticleID() { return $this->fixture->pages[$this->key]['id'] ?? 0; }
		public function getLatestRevID() { return $this->fixture->pages[$this->key]['revision'] ?? 0; }
		public function isRedirect() { return !empty( $this->fixture->pages[$this->key]['redirect'] ); }
	}
}
namespace MediaWiki {
	class MediaWikiServices {
		public function __construct( public \NpcLookupFixture $fixture ) {}
		public function getMainWANObjectCache() { return $this->fixture->cache; }
		public function getTitleFactory() { return new \NpcLookupTitleFactory( $this->fixture ); }
		public function getConnectionProvider() { return new class( $this->fixture ) { public function __construct( private $f ) {} public function getReplicaDatabase() { return new \NpcLookupDatabase( $this->f ); } }; }
		public function getLinksMigration() { return new class { public function getLinksConditions( $table, $title ) { return [ ( $table === 'pagelinks' ? 'pl' : 'cl' ) . '_target_id' => $title->getNamespace() . ':' . $title->getDBkey() ]; } public function getQueryInfo( $table ) { return [ 'joins' => [ 'linktarget' => [ 'JOIN', [ 'pl_target_id = lt_id' ] ] ] ]; } public function getTitleFields( $table ) { return [ 'lt_namespace', 'lt_title' ]; } }; }
		public function getUserFactory() { return new class { public function newAnonymous() { return 'synthetic-anonymous'; } }; }
		public function getPermissionManager() { return new class( $this->fixture ) { public function __construct( private $f ) {} public function userCan( $action, $user, $title ) { \checkNpcLookup( $action === 'read' && $user === 'synthetic-anonymous', 'Every access check is anonymous read' ); return !( $this->f->pages[$title->getDBkey()]['private'] ?? false ); } }; }
		public function getRedirectLookup() { return new class( $this->fixture ) { public function __construct( private $f ) {} public function getRedirectTarget( $title ) { $target = $this->f->pages[$title->getDBkey()]['redirect'] ?? null; return $target ? ( new \NpcLookupTitleFactory( $this->f ) )->newFromText( $target ) : null; } }; }
		public function getRevisionLookup() { return new class( $this->fixture ) { public function __construct( private $f ) {} public function getRevisionByTitle( $title ) { $this->f->revisionReads++; $page = $this->f->pages[$title->getDBkey()] ?? null; return $page ? new \NpcLookupRevision( $page ) : null; } }; }
	}
}
namespace {
	const NS_MAIN = 0;
	const NS_CATEGORY = 14;
	$checks = 0;
	function checkNpcLookup( bool $ok, string $message ): void { global $checks; $checks++; if ( !$ok ) { throw new \RuntimeException( $message ); } }
	class NpcLookupTitleFactory {
		public function __construct( private NpcLookupFixture $fixture ) {}
		public function newFromText( $name, $namespace = 0 ) {
			if ( !is_string( $name ) || $name === '' || preg_match( '/[\[\]{}|#\r\n]/', $name ) ) { return null; }
			if ( str_starts_with( $name, 'Category:' ) ) { $namespace = 14; $name = substr( $name, 9 ); }
			elseif ( str_contains( $name, ':' ) ) { return null; }
			return new \MediaWiki\Title\Title( $this->fixture, ucfirst( str_replace( ' ', '_', trim( $name ) ) ), $namespace );
		}
		public function makeTitleSafe( $namespace, $name ) { return $this->newFromText( $name, $namespace ); }
		public function newFromLinkTarget( $title ) { return $title; }
	}
	class NpcLookupRevision {
		public function __construct( private array $page ) {}
		public function getId() { return $this->page['revision']; }
		public function getContent( $slot, $audience ) {
			checkNpcLookup( $slot === 'main' && $audience === \MediaWiki\Revision\RevisionRecord::FOR_PUBLIC, 'Only public main-slot content is requested' );
			return !empty( $this->page['hidden'] ) ? null : new class( $this->page['text'] ) { public function __construct( private string $text ) {} public function getModel() { return 'wikitext'; } public function getText() { return $this->text; } };
		}
	}
	class NpcLookupCache {
		public array $entries = [];
		public int $now = 0;
		public int $hits = 0;
		public array $checkKeys = [];
		public function makeKey( ...$parts ) { return implode( ':', $parts ); }
		public function touchCheckKey( $key ) { $this->checkKeys[$key] = ( $this->checkKeys[$key] ?? 0 ) + 1; }
		public function getWithSetCallback( $key, $ttl, $callback, $options = [] ) {
			checkNpcLookup( count( $options['checkKeys'] ?? [] ) === 1, 'Discovery cache registers the affected NPC check key' );
			$epoch = array_map( fn ( $key ) => $this->checkKeys[$key] ?? 0, $options['checkKeys'] );
			if ( isset( $this->entries[$key] ) && $this->entries[$key]['until'] > $this->now && $this->entries[$key]['epoch'] === $epoch ) { $this->hits++; return $this->entries[$key]['value']; }
			$value = $callback( false, $ttl ); $this->entries[$key] = [ 'until' => $this->now + $ttl, 'value' => $value, 'epoch' => $epoch ]; return $value;
		}
	}
	class NpcLookupFixture {
		public array $pages = [];
		public array $queries = [];
		public bool $fail = false;
		public int $revisionReads = 0;
		public NpcLookupCache $cache;
		public function __construct() { $this->cache = new NpcLookupCache(); }
		public function page( string $name, string $text, array $extra = [] ): void {
			$key = ucfirst( str_replace( ' ', '_', $name ) ); $this->pages[$key] = $extra + [ 'id' => count( $this->pages ) + 1, 'revision' => 1, 'text' => $text, 'categories' => [], 'links' => [] ];
		}
		public function item( string $name, string $npc, string $zone = 'Synthetic Vale', array $extra = [] ): void {
			$this->page( $name, '{{Itempage|itemname=' . $name . "\n|dropsfrom=\n[[" . $zone . "]]\n* [[" . $npc . "]]\n}}", $extra + [ 'categories' => [ 'Items', $zone ], 'links' => [ $npc ] ] );
		}
		public function title( string $name ) { return ( new NpcLookupTitleFactory( $this ) )->newFromText( $name ); }
		public function lookup() { return new \EqlNpcLootLookup( new \MediaWiki\MediaWikiServices( $this ) ); }
	}
	class NpcLookupDatabase {
		public function __construct( private NpcLookupFixture $fixture ) {}
		public function newSelectQueryBuilder() { return new NpcLookupQuery( $this->fixture ); }
		public function buildStringCast( $field ) { return 'CAST(' . $field . ' AS BINARY)'; }
		public function addQuotes( $value ) { return "'" . str_replace( "'", "''", $value ) . "'"; }
	}
	class NpcLookupQuery {
		private string $table;
		private array $conditions = [];
		private array $joins = [];
		private int $maximum = 0;
		public function __construct( private NpcLookupFixture $fixture ) {}
		public function select( $fields ) { return $this; }
		public function distinct() { return $this; }
		public function from( $table ) { $this->table = $table; return $this; }
		public function join( $table, $alias, $conditions ) { $this->joins[$alias ?? $table] = [ $table, $conditions ]; return $this; }
		public function where( $conditions ) { $this->conditions = $conditions; return $this; }
		public function orderBy( $field, $direction ) { checkNpcLookup( $field === 'page_id' && $direction === 'DESC', 'Newer source pages have fallback priority' ); return $this; }
		public function limit( $limit ) { $this->maximum = $limit; return $this; }
		public function caller( $name ) { return $this; }
		public function fetchResultSet() {
			if ( $this->fixture->fail ) { throw new \RuntimeException( 'Synthetic replica failure' ); }
			$this->fixture->queries[] = [ $this->table, $this->conditions, $this->maximum ];
			checkNpcLookup( $this->maximum > 0 && $this->maximum <= 161 && $this->conditions['page_namespace'] === 0, 'All discovery queries have a namespace and row bound' );
			if ( $this->table !== 'redirect' ) { checkNpcLookup( isset( $this->joins['item_category'] ) && ( $this->conditions['item_category.cl_target_id'] ?? null ) === '14:Items', 'Only the Items category enters backlink and zone candidate limits' ); }
			if ( $this->table === 'categorylinks' ) { checkNpcLookup( isset( $this->conditions['categorylinks.cl_target_id'] ) && !isset( $this->conditions['cl_target_id'] ) && str_contains( $this->joins['page'][1], 'categorylinks.cl_from' ), 'Multiple category joins qualify both their conditions and source-column join' ); }
			$caseNames = null; if ( $this->table === 'categorylinks' ) { checkNpcLookup( isset( $this->joins['pagelinks'], $this->joins['linktarget'] ) && isset( $this->conditions['linktarget.lt_namespace'] ), 'Zone fallback only scans item links to bounded NPC names' ); foreach ( $this->conditions as $condition ) { if ( is_string( $condition ) && str_starts_with( $condition, 'LOWER(' ) ) { preg_match_all( "/'((?:[^']|'')*)'/", $condition, $values ); $caseNames = array_map( static fn ( $value ) => str_replace( "''", "'", $value ), $values[1] ); } } checkNpcLookup( is_array( $caseNames ) && count( $caseNames ) > 0 && count( $caseNames ) <= 60, 'Case fallback has a bounded quoted name set' ); }
			if ( $this->table === 'categorylinks' ) {
				$folding = array_values( array_filter( $this->conditions, static fn ( $value ) => is_string( $value ) && str_starts_with( $value, 'LOWER(' ) ) );
				checkNpcLookup( str_contains( $folding[0], 'AS CHAR CHARACTER SET utf8mb4' ), 'Binary link-title storage must be converted to Unicode text before folding case' );
			}
			$rows = [];
			foreach ( $this->fixture->pages as $name => $page ) {
				if ( $this->table === 'redirect' ) { $accept = isset( $page['redirect'] ) && str_replace( ' ', '_', $page['redirect'] ) === $this->conditions['rd_title']; }
				else {
					$accept = !isset( $page['redirect'] ) && in_array( 'Items', $page['categories'], true );
					if ( $this->table === 'pagelinks' ) { $accept = $accept && in_array( substr( $this->conditions['pl_target_id'], 2 ), array_map( static fn ( $value ) => str_replace( ' ', '_', $value ), $page['links'] ), true ); }
					else { $accept = $accept && in_array( str_replace( '_', ' ', substr( $this->conditions['categorylinks.cl_target_id'], 3 ) ), $page['categories'], true ) && count( array_intersect( $caseNames, array_map( static fn ( $value ) => strtolower( str_replace( ' ', '_', $value ) ), $page['links'] ) ) ) > 0; }
				}
				if ( $accept ) { $rows[] = (object)[ 'page_title' => $name, 'id' => $page['id'] ]; }
			}
			usort( $rows, static fn ( $a, $b ) => $b->id <=> $a->id ); return array_slice( $rows, 0, $this->maximum );
		}
	}
	require __DIR__ . '/../extensions/DynamicZoneList/NpcLootWikitext.php';
	require __DIR__ . '/../extensions/DynamicZoneList/NpcLootLookup.php';
	require __DIR__ . '/../extensions/DynamicZoneList/NpcLootInvalidation.php';
	$analysis = [ 'names' => [ 'Later Guardian' ], 'zones' => [ 'Synthetic Vale' ], 'authoredItems' => [] ];
	$f = new NpcLookupFixture(); $f->item( 'Early Relic', 'Later Guardian' ); $f->page( 'Later Guardian', '{{Namedmobpage|name=Later Guardian}}' );
	$result = $f->lookup()->find( $f->title( 'Later Guardian' ), $analysis ); checkNpcLookup( array_column( $result['items'], 'title' ) === [ 'Early Relic' ] && $result['complete'], 'A manually created later NPC finds the already-published item' );
	$f->page( 'Guardian Alias', '#REDIRECT [[Later Guardian]]', [ 'redirect' => 'Later Guardian' ] ); $f->item( 'Alias Relic', 'Guardian Alias' ); $f->cache->now += 61;
	$result = $f->lookup()->find( $f->title( 'Later Guardian' ), $analysis ); checkNpcLookup( count( $result['items'] ) === 2, 'Public redirect aliases resolve to the canonical NPC' );
	$f->page( 'Item Alias', '#REDIRECT [[Alias Relic]]', [ 'redirect' => 'Alias Relic' ] );
	$result = $f->lookup()->find( $f->title( 'Later Guardian' ), $analysis + [] );
	$authored = $analysis; $authored['authoredItems'] = [ 'Item Alias', 'Early Relic' ]; $result = $f->lookup()->find( $f->title( 'Later Guardian' ), $authored ); checkNpcLookup( $result['items'] === [], 'Authored Common/Unique entries and canonical item aliases are globally excluded' );
	$f->pages['Early_Relic']['hidden'] = true; $f->pages['Alias_Relic']['private'] = true;
	$result = $f->lookup()->find( $f->title( 'Later Guardian' ), $analysis ); checkNpcLookup( $result['items'] === [] && !$result['complete'] && $f->cache->hits > 0, 'Cached discovery never leaks suppressed or anonymous-unreadable current content' ); checkNpcLookup( !str_contains( json_encode( $result ), 'Early Relic' ) && !str_contains( json_encode( $result ), 'Alias Relic' ), 'Visibility warnings disclose no suppressed source title' );
	$g = new NpcLookupFixture(); $g->page( 'Named Guardian', '{{Namedmobpage|name=Named Guardian}}' ); $g->page( 'Named guardian', '{{Namedmobpage|name=Named guardian}}' ); $g->item( 'Other Real Mob Relic', 'Named guardian' ); $g->item( 'Uncreated Case Relic', 'NAMED GUARDIAN' );
	$result = $g->lookup()->find( $g->title( 'Named Guardian' ), [ 'names' => [ 'Named Guardian' ], 'zones' => [ 'Synthetic Vale' ], 'authoredItems' => [] ] ); checkNpcLookup( array_column( $result['items'], 'title' ) === [ 'Uncreated Case Relic' ], 'Zone item fallback forgives an uncreated case variant but never merges two real case-distinct mobs' );
	$g->item( 'Context Relic', 'Named Guardian' ); $g->pages['Context_Relic']['text'] .= ''; $g->pages['Context_Relic']['text'] = '{{Itempage|itemname=Context Relic' . "\n|dropsfrom=\n[[Synthetic Vale]]\n* [[Named Guardian]]\n=== Raid Instance ===\n[[Other Vale]]\n* [[Named Guardian]]\n}}"; $g->cache->now += 61;
	$result = $g->lookup()->find( $g->title( 'Named Guardian' ), [ 'names' => [], 'zones' => [], 'authoredItems' => [] ] ); $context = array_values( array_filter( $result['items'], static fn ( $item ) => $item['title'] === 'Context Relic' ) )[0]; checkNpcLookup( count( $context['contexts'] ) === 2 && $context['contexts'][1] === [ 'zone' => 'Other Vale', 'instance' => 'raid' ], 'A whole NPC page retains distinct normal/raid and zone contexts' );
	$h = new NpcLookupFixture(); $h->page( 'Cache Guardian', '{{Namedmobpage|name=Cache Guardian}}' ); $emptyAnalysis = [ 'names' => [], 'zones' => [ 'Synthetic Vale' ], 'authoredItems' => [] ]; $lookup = $h->lookup(); $before = $lookup->find( $h->title( 'Cache Guardian' ), $emptyAnalysis ); $queries = count( $h->queries ); $h->item( 'Newly Published Relic', 'Cache Guardian' ); checkNpcLookup( $lookup->find( $h->title( 'Cache Guardian' ), $emptyAnalysis )['items'] === [] && count( $h->queries ) === $queries, 'Discovery cache has a bounded short stale interval' ); $h->cache->now += 61; checkNpcLookup( count( $lookup->find( $h->title( 'Cache Guardian' ), $emptyAnalysis )['items'] ) === 1, 'Late item creation is discovered after the 60-second discovery TTL' );
	$i = new NpcLookupFixture(); $i->page( 'Fallback Guardian', '{{Namedmobpage|name=Fallback Guardian}}' ); $i->item( 'Old Item Relic', 'FALLBACK GUARDIAN' ); for ( $n = 0; $n < 200; $n++ ) { $i->page( 'Recent Nonitem ' . $n, 'Prose', [ 'categories' => [ 'Synthetic Vale' ] ] ); $i->item( 'Unrelated Recent Relic ' . $n, 'Some Other Mob' ); } $result = $i->lookup()->find( $i->title( 'Fallback Guardian' ), $emptyAnalysis ); checkNpcLookup( array_column( $result['items'], 'title' ) === [ 'Old Item Relic' ] && $result['complete'], 'Neither non-item pages nor unrelated items can crowd an older matching item out of the fallback limit' );
	$j = new NpcLookupFixture(); $j->page( 'Many Guardian', '{{Namedmobpage|name=Many Guardian}}' ); for ( $n = 0; $n < 170; $n++ ) { $j->item( 'Many Relic ' . $n, 'Many Guardian' ); } $result = $j->lookup()->find( $j->title( 'Many Guardian' ), $emptyAnalysis ); checkNpcLookup( count( $result['items'] ) === 50 && !$result['complete'] && $result['warnings'], 'Candidate/output caps explicitly report incomplete results' ); checkNpcLookup( $j->revisionReads <= 320, 'Public revision reads remain bounded' );
	$k = new NpcLookupFixture(); $k->page( 'Failure Guardian', '{{Namedmobpage|name=Failure Guardian}}' ); $k->fail = true; $result = $k->lookup()->find( $k->title( 'Failure Guardian' ), $emptyAnalysis ); checkNpcLookup( $result['items'] === [] && !$result['complete'] && $result['warnings'], 'Replica failures never silently assert that no drops exist' );
	$l = new NpcLookupFixture(); $l->page( 'Purge Guardian', '{{Namedmobpage|name=Purge Guardian}}' ); $l->lookup()->find( $l->title( 'Purge Guardian' ), $emptyAnalysis ); $l->item( 'New Cross Link', 'Purge Guardian' ); $l->cache->touchCheckKey( EqlNpcLootInvalidation::checkKey( $l->cache, $l->title( 'Purge Guardian' ) ) ); $result = $l->lookup()->find( $l->title( 'Purge Guardian' ), $emptyAnalysis ); checkNpcLookup( array_column( $result['items'], 'title' ) === [ 'New Cross Link' ], 'Touching the NPC check key discovers a new cross-link without waiting for TTL' );
	$tooMany = $emptyAnalysis; $tooMany['authoredItems'] = array_merge( array_map( static fn ( $n ) => 'Authored Relic ' . $n, range( 1, 100 ) ), [ 'New Cross Link' ] ); $result = $l->lookup()->find( $l->title( 'Purge Guardian' ), $tooMany ); checkNpcLookup( $result['items'] === [] && !$result['complete'], 'An incomplete authored exclusion set never adds potentially duplicated items' );
	$policy = static function ( array $names, array $redirects = [], array $extraSources = [] ): array {
		$fixture = new NpcLookupFixture();
		foreach ( $names as $name ) { $fixture->page( $name, '{{Namedmobpage|name=' . $name . '}}' ); }
		foreach ( $redirects as $alias => $target ) { $fixture->page( $alias, '#REDIRECT [[' . $target . ']]', [ 'redirect' => $target ] ); }
		$fixture->item( 'Policy Relic', $names[0] );
		$dropNames = array_merge( $names, array_keys( $redirects ), $extraSources );
		$fixture->pages['Policy_Relic']['text'] = '{{Itempage|itemname=Policy Relic' . "\n|dropsfrom=\n[[Synthetic Vale]]\n" . implode( "\n", array_map( static fn ( $name ) => '* [[' . $name . ']]', $dropNames ) ) . "\n}}";
		return [ $fixture, static fn ( array $authored = [] ) => $fixture->lookup()->find( $fixture->title( $names[0] ), [ 'names' => [], 'zones' => [], 'authoredItems' => $authored ] ) ];
	};
	$five = [ 'Policy Guardian', 'Keeper One', 'Keeper Two', 'Keeper Three', 'Keeper Four' ];
	[ $policyFive, $findFive ] = $policy( $five );
	$policyResult = $findFive();
	checkNpcLookup( $policyResult['items'][0]['lootKind'] === 'known', 'Exactly five distinct monsters retain the existing Known/Unique default' );
	[ $policySix, $findSix ] = $policy( array_merge( $five, [ 'Keeper Five' ] ) );
	$policyResult = $findSix();
	checkNpcLookup( $policyResult['items'][0]['lootKind'] === 'common', 'More than five distinct canonical item-source monsters default to Common Loot' );
	$policyFive->pages['Policy_Relic']['text'] = substr( $policyFive->pages['Policy_Relic']['text'], 0, -2 ) . "\n=== Raid Instance ===\n[[Other Vale]]\n" . implode( "\n", array_map( static fn ( $name ) => '* [[' . $name . ']]', $five ) ) . "\n}}";
	$policyResult = $findFive();
	checkNpcLookup( $policyResult['items'][0]['lootKind'] === 'known' && count( $policyResult['items'][0]['contexts'] ) === 2, 'Repeated zone and instance rows do not inflate distinct monster counts' );
	[ $policyAlias, $findAlias ] = $policy( $five, [ 'Keeper Alias' => 'Keeper One' ] );
	checkNpcLookup( $findAlias()['items'][0]['lootKind'] === 'known', 'Public source redirects count as the same canonical monster rather than a sixth source' );
	$apostropheNames = array_merge( array_slice( $five, 0, 4 ), [ "Keeper O'Neal" ] );
	[ $policySpelling, $findSpelling ] = $policy( $apostropheNames, [], [ 'policy guardian', 'KEEPER O`NEAL' ] );
	checkNpcLookup( $findSpelling()['items'][0]['lootKind'] === 'known', 'First-letter normalization and uncreated backtick/case spellings do not manufacture extra monsters' );
	[ $policyCase, $findCase ] = $policy( array_merge( array_slice( $five, 0, 4 ), [ 'Case Keeper', 'Case keeper' ] ) );
	checkNpcLookup( $findCase()['items'][0]['lootKind'] === 'common', 'Two real case-distinct NPC pages remain two canonical monsters for the threshold' );
	[ $policyMissing, $findMissing ] = $policy( [ 'Policy Guardian' ], [], [ 'Missing One', 'Missing Two', 'Missing Three', 'Missing Four', 'Missing Five' ] );
	checkNpcLookup( $findMissing()['items'][0]['lootKind'] === 'common', 'Distinct declared redlink monsters count before their NPC pages are created' );
	$policyFive->pages['Policy_Relic']['text'] = str_replace( "\n}}", "\n|notes=See [[Prose One]], [[Prose Two]] and {{Loc|Synthetic Vale|1,2,3|Prose location}}\n}}", $policyFive->pages['Policy_Relic']['text'] );
	checkNpcLookup( $findFive()['items'][0]['lootKind'] === 'known', 'Arbitrary item prose and location references cannot turn five actual source monsters into a common drop' );
	foreach ( [ 'known_loot', 'common_loot' ] as $field ) {
		$explicit = EqlNpcLootWikitext::analyseNpcPage( '{{Namedmobpage|name=Policy Guardian|' . $field . '={{:Policy Relic}}}}' );
		checkNpcLookup( $policySix->lookup()->find( $policySix->title( 'Policy Guardian' ), $explicit )['items'] === [], 'Explicit authored ' . $field . ' remains the per-NPC override instead of being moved by the default policy' );
	}
	echo "NPC reverse loot lookup: $checks synthetic checks passed (public visibility, item/category intersections, late creation, aliases/case collisions, authored loot, common-drop policy, contexts, bounded cache/reads and failures).\n";
}
