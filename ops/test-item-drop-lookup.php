<?php
/** Synthetic core-service doubles: no wiki bootstrap, accounts or writes. */
namespace MediaWiki\Revision { class RevisionRecord { public const FOR_PUBLIC = 1; } }
namespace MediaWiki\Title {
	class Title {
		public function __construct( public \ItemLookupFixture $fixture, private string $key, private int $namespace = 0 ) {}
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
		public function __construct( public \ItemLookupFixture $fixture ) {}
		public function getMainWANObjectCache() { return $this->fixture->cache; }
		public function getTitleFactory() { return new \ItemLookupTitleFactory( $this->fixture ); }
		public function getConnectionProvider() { return new class( $this->fixture ) { public function __construct( private $f ) {} public function getReplicaDatabase() { return new \ItemLookupDatabase( $this->f ); } }; }
		public function getLinksMigration() { return new class { public function getLinksConditions( $table, $title ) { return [ ( [ 'pagelinks' => 'pl', 'templatelinks' => 'tl', 'categorylinks' => 'cl' ][$table] ) . '_target_id' => $title->getNamespace() . ':' . $title->getDBkey() ]; } public function getQueryInfo( $table ) { return [ 'joins' => [ 'linktarget' => [ 'JOIN', [ ( $table === 'templatelinks' ? 'tl' : 'pl' ) . '_target_id = lt_id' ] ] ] ]; } public function getTitleFields( $table ) { return [ 'lt_namespace', 'lt_title' ]; } }; }
		public function getUserFactory() { return new class { public function newAnonymous() { return 'synthetic-anonymous'; } }; }
		public function getPermissionManager() { return new class( $this->fixture ) { public function __construct( private $f ) {} public function userCan( $action, $user, $title ) { \checkItemLookup( $action === 'read' && $user === 'synthetic-anonymous', 'Every access check is anonymous read' ); return !( $this->f->pages[$title->getDBkey()]['private'] ?? false ); } }; }
		public function getRedirectLookup() { return new class( $this->fixture ) { public function __construct( private $f ) {} public function getRedirectTarget( $title ) { $target = $this->f->pages[$title->getDBkey()]['redirect'] ?? null; return $target ? ( new \ItemLookupTitleFactory( $this->f ) )->newFromText( $target ) : null; } }; }
		public function getRevisionLookup() { return new class( $this->fixture ) { public function __construct( private $f ) {} public function getRevisionByTitle( $title ) { $this->f->revisionReads++; $page = $this->f->pages[$title->getDBkey()] ?? null; return $page ? new \ItemLookupRevision( $page ) : null; } }; }
	}
}
namespace {
	const NS_MAIN = 0;
	const NS_CATEGORY = 14;
	$checks = 0;
	function checkItemLookup( bool $ok, string $message ): void { global $checks; $checks++; if ( !$ok ) { throw new \RuntimeException( $message ); } }
	class ItemLookupTitleFactory {
		public function __construct( private ItemLookupFixture $fixture ) {}
		public function newFromText( $name, $namespace = 0 ) {
			if ( !is_string( $name ) || $name === '' || preg_match( '/[\[\]{}|#\r\n]/', $name ) ) { return null; }
			if ( str_starts_with( $name, 'Category:' ) ) { $namespace = 14; $name = substr( $name, 9 ); }
			elseif ( preg_match( '/^(?:File|Image|Template|User|Special|MediaWiki|Help|Talk|Category)\s*:/i', $name ) ) { return null; }
			return new \MediaWiki\Title\Title( $this->fixture, ucfirst( str_replace( ' ', '_', trim( $name ) ) ), $namespace );
		}
		public function makeTitleSafe( $namespace, $name ) { return $this->newFromText( $name, $namespace ); }
		public function newFromLinkTarget( $title ) { return $title; }
	}
	class ItemLookupRevision {
		public function __construct( private array $page ) {}
		public function getId() { return $this->page['revision']; }
		public function getContent( $slot, $audience ) {
			checkItemLookup( $slot === 'main' && $audience === \MediaWiki\Revision\RevisionRecord::FOR_PUBLIC, 'Only public main-slot content is requested' );
			return !empty( $this->page['hidden'] ) ? null : new class( $this->page['text'] ) { public function __construct( private string $text ) {} public function getModel() { return 'wikitext'; } public function getText() { return $this->text; } };
		}
	}
	class ItemLookupCache {
		public array $entries = [];
		public int $now = 0;
		public int $hits = 0;
		public array $checkKeys = [];
		public function makeKey( ...$parts ) { return implode( ':', $parts ); }
		public function touchCheckKey( $key ) { $this->checkKeys[$key] = ( $this->checkKeys[$key] ?? 0 ) + 1; }
		public function getWithSetCallback( $key, $ttl, $callback, $options = [] ) {
			checkItemLookup( count( $options['checkKeys'] ?? [] ) === 1, 'Discovery cache registers the affected item check key' );
			$epoch = array_map( fn ( $key ) => $this->checkKeys[$key] ?? 0, $options['checkKeys'] );
			if ( isset( $this->entries[$key] ) && $this->entries[$key]['until'] > $this->now && $this->entries[$key]['epoch'] === $epoch ) { $this->hits++; return $this->entries[$key]['value']; }
			$value = $callback( false, $ttl ); $this->entries[$key] = [ 'until' => $this->now + $ttl, 'value' => $value, 'epoch' => $epoch ]; return $value;
		}
	}
	class ItemLookupFixture {
		public array $pages = [];
		public array $queries = [];
		public bool $fail = false;
		public int $revisionReads = 0;
		public ItemLookupCache $cache;
		public function __construct() { $this->cache = new ItemLookupCache(); }
		public function page( string $name, string $text, array $extra = [] ): void {
			$key = ucfirst( str_replace( ' ', '_', $name ) ); $this->pages[$key] = $extra + [ 'id' => count( $this->pages ) + 1, 'revision' => 1, 'text' => $text, 'categories' => [], 'links' => [], 'transclusions' => [] ];
		}
		public function npc( string $name, string $item, string $zone = 'Synthetic Vale', array $extra = [] ): void {
			$this->page( $name, '{{Namedmobpage|name=' . $name . '|zone=[[' . $zone . ']]|known_loot=<ul><li>{{:' . $item . '}}</li></ul>}}', $extra + [ 'categories' => [ 'NPCs', $zone ], 'transclusions' => [ $item ] ] );
		}
		public function title( string $name ) { return ( new ItemLookupTitleFactory( $this ) )->newFromText( $name ); }
		public function lookup() { return new \EqlItemDropLookup( new \MediaWiki\MediaWikiServices( $this ) ); }
	}
	class ItemLookupDatabase {
		public function __construct( private ItemLookupFixture $fixture ) {}
		public function newSelectQueryBuilder() { return new ItemLookupQuery( $this->fixture ); }
		public function buildStringCast( $field ) { return 'CAST(' . $field . ' AS BINARY)'; }
		public function addQuotes( $value ) { return "'" . str_replace( "'", "''", $value ) . "'"; }
	}
	class ItemLookupQuery {
		private string $table;
		private array $conditions = [];
		private array $joins = [];
		private int $maximum = 0;
		public function __construct( private ItemLookupFixture $fixture ) {}
		public function select( $fields ) { return $this; }
		public function distinct() { return $this; }
		public function from( $table ) { $this->table = $table; return $this; }
		public function join( $table, $alias, $conditions ) { $this->joins[$alias ?? $table] = [ $table, $conditions ]; return $this; }
		public function where( $conditions ) { $this->conditions = $conditions; return $this; }
		public function orderBy( $field, $direction ) { checkItemLookup( $field === 'page_id' && $direction === 'DESC', 'Newer source pages have fallback priority' ); return $this; }
		public function limit( $limit ) { $this->maximum = $limit; return $this; }
		public function setMaxExecutionTime( $time ) { checkItemLookup( $time > 0 && $time <= 1000, 'Case fallback SQL has a one-second execution bound' ); return $this; }
		public function caller( $name ) { return $this; }
		public function fetchResultSet() {
			if ( $this->fixture->fail ) { throw new \RuntimeException( 'Synthetic replica failure' ); }
			$this->fixture->queries[] = [ $this->table, $this->conditions, $this->maximum ];
			checkItemLookup( $this->maximum > 0 && $this->maximum <= 161 && $this->conditions['page_namespace'] === 0, 'All discovery queries have a namespace and row bound' );
			if ( $this->table !== 'redirect' && isset( $this->joins['npc_category'] ) ) { checkItemLookup( ( $this->conditions['npc_category.cl_target_id'] ?? null ) === '14:NPCs', 'NPC source discovery remains category constrained' ); } elseif ( $this->table !== 'redirect' ) { checkItemLookup( in_array( $this->table, [ 'pagelinks', 'templatelinks' ], true ) && isset( $this->joins['page'] ), 'Finite collection candidates are bounded direct item backlinks, not a content scan' ); }
			if ( $this->table === 'categorylinks' ) { checkItemLookup( isset( $this->conditions['categorylinks.cl_target_id'] ) && !isset( $this->conditions['cl_target_id'] ) && str_contains( $this->joins['page'][1], 'categorylinks.cl_from' ), 'Multiple category joins qualify both their conditions and source-column join' ); }
			$linkTable = $this->table === 'categorylinks' ? ( isset( $this->joins['templatelinks'] ) ? 'templatelinks' : 'pagelinks' ) : $this->table;
			$linkField = $linkTable === 'templatelinks' ? 'transclusions' : 'links';
			$folding = array_values( array_filter( $this->conditions, static fn ( $value ) => is_string( $value ) && str_starts_with( $value, 'LOWER(' ) ) );
			$isCase = (bool)$folding;
			$caseNames = null; if ( $isCase ) { checkItemLookup( isset( $this->joins['linktarget'] ) && isset( $this->conditions['linktarget.lt_namespace'] ), 'Case fallback only scans authored NPC links to bounded item names' ); foreach ( $folding as $condition ) { preg_match_all( "/'((?:[^']|'')*)'/", $condition, $values ); $caseNames = array_map( static fn ( $value ) => str_replace( "''", "'", $value ), $values[1] ); } checkItemLookup( is_array( $caseNames ) && count( $caseNames ) > 0 && count( $caseNames ) <= 60, 'Case fallback has a bounded quoted name set' ); }
			if ( $isCase ) {
				checkItemLookup( str_contains( $folding[0], 'AS CHAR CHARACTER SET utf8mb4' ), 'Binary link-title storage must be converted to Unicode text before folding case' );
			}
			$rows = [];
			foreach ( $this->fixture->pages as $name => $page ) {
				if ( $this->table === 'redirect' ) { $accept = isset( $page['redirect'] ) && str_replace( ' ', '_', $page['redirect'] ) === $this->conditions['rd_title']; }
				else {
					$accept = !isset( $page['redirect'] ) && ( !isset( $this->joins['npc_category'] ) || in_array( 'NPCs', $page['categories'], true ) );
					if ( !$isCase ) { $condition = $this->conditions[$linkTable === 'templatelinks' ? 'tl_target_id' : 'pl_target_id']; $accept = $accept && in_array( substr( $condition, 2 ), array_map( static fn ( $value ) => str_replace( ' ', '_', $value ), $page[$linkField] ), true ); }
					else { $accept = $accept && ( $this->table !== 'categorylinks' || in_array( str_replace( '_', ' ', substr( $this->conditions['categorylinks.cl_target_id'], 3 ) ), $page['categories'], true ) ) && count( array_intersect( $caseNames, array_map( static fn ( $value ) => strtolower( str_replace( ' ', '_', $value ) ), $page[$linkField] ) ) ) > 0; }
				}
				if ( $accept ) { $rows[] = (object)[ 'page_title' => $name, 'id' => $page['id'] ]; }
			}
			usort( $rows, static fn ( $a, $b ) => $b->id <=> $a->id ); return array_slice( $rows, 0, $this->maximum );
		}
	}
	require __DIR__ . '/../extensions/DynamicZoneList/NpcLootWikitext.php';
	require __DIR__ . '/../extensions/DynamicZoneList/ItemDropLookup.php';
	$analysis = [ 'names' => [], 'zones' => [ 'Synthetic Vale' ], 'existingSources' => [] ];
	$f = new ItemLookupFixture();
	$f->npc( 'Earlier Guardian', 'Later Relic' );
	$f->page( 'Later Relic', '{{Itempage|itemname=Later Relic|statsblock=HP: 1|dropsfrom=}}' );
	$result = $f->lookup()->find( $f->title( 'Later Relic' ), $analysis );
	checkItemLookup( $result['complete'] && $result['sources'] === [ [ 'npc' => 'Earlier Guardian', 'zone' => 'Synthetic Vale', 'instance' => 'normal', 'lootKind' => 'known' ] ], 'An item created after a missing-item transclusion finds its earlier NPC source' );
	checkItemLookup( in_array( 'templatelinks', array_column( $f->queries, 0 ), true ) && in_array( 'pagelinks', array_column( $f->queries, 0 ), true ), 'Discover both linked and transcluded loot, including missing item transclusions' );
	$f->page( 'Plain Link Guardian', '{{Namedmobpage|name=Plain Link Guardian|zone=[[Synthetic Vale]]|common_loot=* [[Later Relic]]}}', [ 'categories' => [ 'NPCs', 'Synthetic Vale' ], 'links' => [ 'Later Relic' ] ] );
	$f->cache->touchCheckKey( EqlItemDropLookup::checkKey( $f->cache, $f->title( 'Later Relic' ) ) );
	$result = $f->lookup()->find( $f->title( 'Later Relic' ), $analysis );
	checkItemLookup( count( $result['sources'] ) === 2 && $result['sources'][1]['lootKind'] === 'common', 'A later authored common-loot link is discovered immediately after its item check key is touched' );
	$f->page( 'Relic Alias', '#REDIRECT [[Later Relic]]', [ 'redirect' => 'Later Relic' ] );
	$f->npc( 'Alias Guardian', 'Relic Alias' );
	$f->cache->now += 61;
	$result = $f->lookup()->find( $f->title( 'Later Relic' ), $analysis );
	checkItemLookup( in_array( 'Alias Guardian', array_column( $result['sources'], 'npc' ), true ), 'Public item redirect aliases retain their authored NPC source' );
	$f->page( 'Guardian Alias', '#REDIRECT [[Earlier Guardian]]', [ 'redirect' => 'Earlier Guardian' ] );
	$declared = $analysis;
	$declared['existingSources'] = [ [ 'npc' => 'Guardian Alias', 'zone' => 'Synthetic Vale', 'instance' => 'normal' ] ];
	$result = $f->lookup()->find( $f->title( 'Later Relic' ), $declared );
	checkItemLookup( !in_array( 'Earlier Guardian', array_column( $result['sources'], 'npc' ), true ) && count( $result['sources'] ) === 2, 'An existing item Drops From row excludes the same canonical NPC, zone and instance through a redirect alias' );
	$f->page( 'Synthetic Vale', 'Public zone.' );
	$f->page( 'Vale Alias', '#REDIRECT [[Synthetic Vale]]', [ 'redirect' => 'Synthetic Vale' ] );
	$declared['existingSources'][0]['zone'] = 'Vale Alias';
	$result = $f->lookup()->find( $f->title( 'Later Relic' ), $declared );
	checkItemLookup( !in_array( 'Earlier Guardian', array_column( $result['sources'], 'npc' ), true ) && $result['zoneAliases']['Vale Alias'] === 'Synthetic Vale', 'Public canonical zone aliases exclude the same authored NPC and provide bounded layout proof' );
	$declared['existingSources'][0]['zone'] = '';
	$result = $f->lookup()->find( $f->title( 'Later Relic' ), $declared );
	checkItemLookup( !in_array( 'Earlier Guardian', array_column( $result['sources'], 'npc' ), true ), 'An unscoped authored NPC relationship already covers its recovered zone within the same instance' );
	$declared['existingSources'][0]['instance'] = 'raid';
	$result = $f->lookup()->find( $f->title( 'Later Relic' ), $declared );
	checkItemLookup( in_array( 'Earlier Guardian', array_column( $result['sources'], 'npc' ), true ), 'Unscoped authored raid sources do not exclude normal NPC relationships' );
	$f->page( 'Synthetic vale', 'A real case-distinct public zone.' );
	$declared['existingSources'][0]['zone'] = 'Synthetic vale';
	$declared['existingSources'][0]['instance'] = 'normal';
	$result = $f->lookup()->find( $f->title( 'Later Relic' ), $declared );
	checkItemLookup( in_array( 'Earlier Guardian', array_column( $result['sources'], 'npc' ), true ), 'Two real case-distinct zone pages are not collapsed by forgiving title normalization' );
	$f->pages['Vale_Alias']['private'] = true;
	$declared['existingSources'][0]['zone'] = 'Vale Alias';
	$result = $f->lookup()->find( $f->title( 'Later Relic' ), $declared );
	checkItemLookup( in_array( 'Earlier Guardian', array_column( $result['sources'], 'npc' ), true ) && $result['zoneAliases']['Vale Alias'] !== 'Synthetic Vale', 'Unreadable zone redirects cannot provide canonical alias proofs or silently exclude visible sources' );

	$f->pages['Alias_Guardian']['hidden'] = true;
	$f->pages['Plain_Link_Guardian']['private'] = true;
	$result = $f->lookup()->find( $f->title( 'Later Relic' ), $analysis );
	checkItemLookup( array_column( $result['sources'], 'npc' ) === [ 'Earlier Guardian' ] && !$result['complete'] && $f->cache->hits > 0, 'Cached candidate discovery rechecks public content and anonymous read access' );
	checkItemLookup( !str_contains( json_encode( $result ), 'Alias Guardian' ) && !str_contains( json_encode( $result ), 'Plain Link Guardian' ), 'Visibility warnings never disclose unreadable source titles' );

	$g = new ItemLookupFixture();
	$g->page( 'Named Relic', '{{Itempage|itemname=Named Relic}}' );
	$g->page( 'Named relic', '{{Itempage|itemname=Named relic}}' );
	$g->npc( 'Different Real Item Guardian', 'Named relic' );
	$g->npc( 'Missing Case Guardian', 'NAMED RELIC' );
	$g->npc( 'Real Item Guardian', 'Named Relic' );
	$g->page( 'Prose Guardian', '{{Namedmobpage|name=Prose Guardian|zone=[[Synthetic Vale]]}} Prose mentions [[Named Relic]].', [ 'categories' => [ 'NPCs', 'Synthetic Vale' ], 'links' => [ 'Named Relic' ] ] );
	$g->page( 'Protected Guardian', '{{Namedmobpage|name=Protected Guardian|zone=[[Synthetic Vale]]|known_loot=<nowiki>[[Named Relic]]</nowiki>}}', [ 'categories' => [ 'NPCs', 'Synthetic Vale' ], 'links' => [ 'Named Relic' ] ] );
	$g->page( 'Uncategorized Pretend Guardian', '{{Namedmobpage|name=Uncategorized Pretend Guardian|zone=[[Synthetic Vale]]|known_loot=[[Named Relic]]}}', [ 'links' => [ 'Named Relic' ] ] );
	$result = $g->lookup()->find( $g->title( 'Named Relic' ), $analysis );
	checkItemLookup( array_column( $result['sources'], 'npc' ) === [ 'Missing Case Guardian', 'Real Item Guardian' ] && $result['complete'], 'Case forgiveness applies to missing item spellings only; prose, protected examples, non-NPC candidates and real case-distinct items are excluded' );
	$noZones = $analysis; $noZones['zones'] = [];
	$result = $g->lookup()->find( $g->title( 'Named Relic' ), $noZones );
	checkItemLookup( array_column( $result['sources'], 'npc' ) === [ 'Missing Case Guardian', 'Real Item Guardian' ] && $result['complete'], 'An item with no zone or source metadata still finds case-variant NPC links without merging real case-distinct items' );
	$g->page( 'Scoped Guardian', '{{Namedmobpage|name=Scoped Guardian|zone=[[Synthetic Vale]]|known_loot={{:Named Relic}}}}' . "\n== Raid Instance ==\n" . '{{Namedmobpage|name=Scoped Guardian|zone=[[Other Vale]]|common_loot=[[Named Relic]]}}', [ 'categories' => [ 'NPCs', 'Synthetic Vale', 'Other Vale' ], 'links' => [ 'Named Relic' ], 'transclusions' => [ 'Named Relic' ] ] );
	$g->cache->now += 61;
	$result = $g->lookup()->find( $g->title( 'Named Relic' ), $analysis );
	$scoped = array_values( array_filter( $result['sources'], static fn ( $source ) => $source['npc'] === 'Scoped Guardian' ) );
	checkItemLookup( count( $scoped ) === 2 && $scoped[0]['instance'] === 'raid' && $scoped[0]['zone'] === 'Other Vale' && $scoped[0]['lootKind'] === 'common' && $scoped[1]['instance'] === 'normal', 'Each authored NPC card retains its actual zone, normal/raid scope and common/known provenance' );

	$h = new ItemLookupFixture();
	$h->page( 'Cache Relic', '{{Itempage|itemname=Cache Relic}}' );
	$lookup = $h->lookup();
	checkItemLookup( $lookup->find( $h->title( 'Cache Relic' ), $analysis )['sources'] === [], 'An initially empty item lookup is cacheable' );
	$queries = count( $h->queries );
	$h->npc( 'Late Guardian', 'Cache Relic' );
	checkItemLookup( $lookup->find( $h->title( 'Cache Relic' ), $analysis )['sources'] === [] && count( $h->queries ) === $queries, 'Discovery alone uses a bounded short cache' );
	$h->cache->now += 61;
	checkItemLookup( array_column( $lookup->find( $h->title( 'Cache Relic' ), $analysis )['sources'], 'npc' ) === [ 'Late Guardian' ], 'NPC creation after the item is discovered after the 60-second candidate TTL' );
	$h->npc( 'New Cross Link Guardian', 'Cache Relic' );
	$h->cache->touchCheckKey( EqlItemDropLookup::checkKey( $h->cache, $h->title( 'Cache Relic' ) ) );
	checkItemLookup( count( $lookup->find( $h->title( 'Cache Relic' ), $analysis )['sources'] ) === 2, 'Targeted NPC cross-link invalidation refreshes candidates before the TTL' );

	$i = new ItemLookupFixture();
	$i->page( 'Fallback Relic', '{{Itempage|itemname=Fallback Relic}}' );
	$i->npc( 'Old Case Guardian', 'FALLBACK RELIC' );
	for ( $n = 0; $n < 180; $n++ ) { $i->npc( 'Unrelated Recent Guardian ' . $n, 'Other Relic' ); $i->page( 'Unrelated Recent Page ' . $n, 'Prose', [ 'categories' => [ 'Synthetic Vale' ] ] ); }
	$result = $i->lookup()->find( $i->title( 'Fallback Relic' ), $analysis );
	checkItemLookup( array_column( $result['sources'], 'npc' ) === [ 'Old Case Guardian' ] && $result['complete'], 'Unrelated NPCs and ordinary zone pages do not crowd out an older matching case-variant source' );
	$j = new ItemLookupFixture();
	$j->page( 'Many Relic', '{{Itempage|itemname=Many Relic}}' );
	for ( $n = 0; $n < 170; $n++ ) { $j->npc( 'Many Guardian ' . $n, 'Many Relic' ); }
	$result = $j->lookup()->find( $j->title( 'Many Relic' ), $analysis );
	checkItemLookup( count( $result['sources'] ) === 50 && !$result['complete'] && $result['warnings'], 'Candidate and result caps explicitly report incomplete item sources' );
	checkItemLookup( $j->revisionReads <= 320, 'Public revision reads remain bounded' );
	$k = new ItemLookupFixture();
	$k->page( 'Failure Relic', '{{Itempage|itemname=Failure Relic}}' );
	$k->fail = true;
	$result = $k->lookup()->find( $k->title( 'Failure Relic' ), $analysis );
	checkItemLookup( $result['sources'] === [] && !$result['complete'] && $result['warnings'], 'Replica failures preserve an explicit incomplete status rather than claiming no sources exist' );
	$tooMany = $analysis; $tooMany['existingSources'] = array_fill( 0, 101, [ 'npc' => 'Guardian', 'zone' => '', 'instance' => 'normal' ] );
	$result = $h->lookup()->find( $h->title( 'Cache Relic' ), $tooMany );
	checkItemLookup( !$result['complete'] && $result['sources'] === [], 'An incomplete authored exclusion set fails closed to avoid duplicate sources' );
	$sets = new ItemLookupFixture();
	$sets->page( 'Set Relic', '{{Itempage|itemname=Set Relic|statsblock=HP: 1|dropsfrom=}}' );
	$sets->page( 'Other Set Relic', '{{Itempage|itemname=Other Set Relic|statsblock=AC: 1|dropsfrom=}}' );
	$sets->page( 'Synthetic Armor', '{{Gear Set|Set Relic|Other Set Relic}}', [ 'links' => [ 'Set Relic', 'Other Set Relic' ] ] );
	$sets->page( 'Armor Alias', '#REDIRECT [[Synthetic Armor]]', [ 'redirect' => 'Synthetic Armor' ] );
	$sets->page( 'Set Guardian', '{{Namedmobpage|name=Set Guardian|zone=[[Synthetic Vale]]|known_loot=<ul><li>Always drops two pieces of [[Armor Alias]]</li></ul>}}', [ 'categories' => [ 'NPCs' ], 'links' => [ 'Armor Alias' ] ] );
	$sets->page( 'Synthetic Pool', '<ul><li>{{:Set Relic}}</li><li>[[Other Set Relic]]</li></ul>', [ 'transclusions' => [ 'Set Relic' ], 'links' => [ 'Other Set Relic' ] ] );
	$sets->npc( 'Pool Guardian', 'Synthetic Pool' );
	$sets->page( 'Nested Armor', '{{Gear Set|Synthetic Armor}}', [ 'links' => [ 'Synthetic Armor' ] ] );
	$sets->npc( 'Nested Guardian', 'Nested Armor' );
	$result = $sets->lookup()->find( $sets->title( 'Set Relic' ), $analysis );
	checkItemLookup( array_column( $result['sources'], 'npc' ) === [ 'Nested Guardian', 'Pool Guardian', 'Set Guardian' ], 'Current public finite sets, root-only pools, nested membership and set aliases produce reciprocal item views without saved member mirrors' );
	$sets->pages['Other_Set_Relic']['private'] = true;
	$result = $sets->lookup()->find( $sets->title( 'Set Relic' ), $analysis );
	checkItemLookup( $result['sources'] === [] && !$result['complete'] && !str_contains( json_encode( $result['warnings'] ), 'Other Set Relic' ), 'Cached finite collections recheck every current public member and never disclose inaccessible member names' );
	$colon = new ItemLookupFixture();
	$colon->page( 'Staff of Mastery: Water', '{{Itempage|itemname=Staff of Mastery: Water|dropsfrom=}}' );
	$colon->npc( 'Colon Guardian', 'Staff of Mastery: Water' );
	$result = $colon->lookup()->find( $colon->title( 'Staff of Mastery: Water' ), $analysis );
	checkItemLookup( array_column( $result['sources'], 'npc' ) === [ 'Colon Guardian' ], 'Namespace-validated main-namespace colon titles are valid reciprocal loot items' );

	echo "Item reverse drop-source lookup: $checks synthetic checks passed (links/transclusions, both creation orders, public visibility, canonical aliases, case collisions, card provenance, existing rows, bounded cache and failures).\n";
}
