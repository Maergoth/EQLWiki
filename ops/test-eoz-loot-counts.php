<?php
/** Source-only public-identity and global pack-count checks. No database or writes. */
namespace MediaWiki\Revision { class RevisionRecord { public const FOR_PUBLIC = 1; } }
namespace MediaWiki { class MediaWikiServices { public static $fixture; public static function getInstance() { return self::$fixture; } } }
namespace MediaWiki\Title {
    class Title {
        public function __construct( private string $text, private int $namespace = 0, private string $fragment = '' ) {}
        public static function newFromText( $name, $namespace = 0 ) {
            if ( !is_string( $name ) || $name === '' || preg_match( '/[\[\]{}|\r\n]/', $name ) ) { return null; }
            [ $name, $fragment ] = array_pad( explode( '#', $name, 2 ), 2, '' );
            if ( preg_match( '/^(?:Talk|User|File|Template):/i', $name ) ) { $namespace = 1; $name = substr( $name, strpos( $name, ':' ) + 1 ); }
            return new self( ucfirst( str_replace( '_', ' ', trim( $name ) ) ), $namespace, $fragment );
        }
        public static function makeTitle( $namespace, $key ) { return self::newFromText( $key, $namespace ); }
        public function getText() { return $this->text; }
        public function getDBkey() { return str_replace( ' ', '_', $this->text ); }
        public function getNamespace() { return $this->namespace; }
        public function isExternal() { return false; }
        public function getFragment() { return $this->fragment; }
        public function exists() { return isset( $GLOBALS['lootPages'][$this->text] ); }
        public function getArticleID() { return $GLOBALS['lootPages'][$this->text]['id'] ?? 0; }
    }
}
namespace {
    const NS_MAIN = 0;
    use MediaWiki\Title\Title;
    require __DIR__ . '/../maintenance/ItemLootSourceCounts.php';
    $checks = 0;
    function checkLootCount( bool $ok, string $message ): void { global $checks; $checks++; if ( !$ok ) { throw new \RuntimeException( $message ); } }
    $item = static fn ( string $sources = '' ) => '{{Itempage|itemname=Shared Relic|dropsfrom=' . $sources . '}}';
    $npc = static fn ( string $field, string $target = 'Shared Relic', string $zone = '' ) => '{{Namedmobpage|name=A display name|zone=' . ( $zone ? '[[' . $zone . ']]' : '' ) . '|' . $field . '={{:' . $target . '}}}}';
    $records = [ [ 'name' => 'Display Relic', 'wikiTitle' => 'Shared Relic' ] ];
    $ids = [ 'Shared Relic' => 1, 'Guardian One' => 11, 'Guardian Two' => 12, 'Guardian Three' => 13, 'Guardian Four' => 14, 'Guardian Five' => 15, 'Guardian Six' => 16 ];
    $aliases = [ 'Guardian Alias' => 'Guardian One', 'Relic Alias' => 'Shared Relic' ];
    $resolve = static function ( string $name ) use ( $ids, $aliases ): array {
        if ( $name === 'Read failure' ) { throw new \RuntimeException( 'Synthetic read failure' ); }
        if ( in_array( $name, [ 'Private guardian', 'Suppressed guardian', 'Broken alias' ], true ) ) {
            return [ 'title' => $name, 'pageId' => null, 'complete' => false, 'public' => false ];
        }
        $name = $aliases[$name] ?? $name;
        return [ 'title' => $name, 'pageId' => $ids[$name] ?? null, 'public' => true, 'complete' => true ];
    };
    $five = static function () use ( $item, $npc ): EqlItemLootSourceCounts {
        $counts = new EqlItemLootSourceCounts();
        $counts->item( 'Shared Relic', $item( "[[Zone One]]\n* [[Guardian One]]\n* [[Guardian Alias]]\n== Raid Instance ==\n[[Zone Two]]\n* [[Guardian One]]" ) );
        foreach ( [ 'Guardian Two', 'Guardian Three', 'Guardian Four', 'Guardian Five' ] as $i => $name ) {
            $counts->npc( $name, $npc( $i % 2 ? 'common_loot' : 'known_loot', $i === 0 ? 'Relic Alias' : 'Shared Relic', 'Zone ' . $i ) );
        }
        return $counts;
    };
    $counts = $five();
    $result = $counts->apply( $records, $resolve )[0];
    checkLootCount( $result['lootSourceCount'] === 5 && $result['lootSourceCountComplete'], 'Five distinct global mobs prove the less-than-six boundary' );
    checkLootCount( count( array_filter( $result['lootSourceNpcs'], static fn ( $name ) => $name === 'Guardian One' ) ) === 1, 'Zones, instances and canonical aliases cannot increase the mob count' );
    $counts->npc( 'Guardian Six', $npc( 'common_loot', 'Shared Relic', 'Another Continent' ) );
    $result = $counts->apply( $records, $resolve )[0];
    checkLootCount( $result['lootSourceCount'] === 6 && $result['lootSourceCountComplete'], 'A sixth mob in another zone and authored Common Loot reaches Common' );
    $counts->incomplete();
    $result = $counts->apply( $records, $resolve )[0];
    checkLootCount( $result['lootSourceCount'] === 6 && !$result['lootSourceCountComplete'], 'A complete lower bound of six remains usable when a later scan is incomplete' );

    $counts = new EqlItemLootSourceCounts();
    $counts->item( 'Shared Relic', $item( '* [[Missing guardian]]' ) );
    $counts->npc( 'Unzoned guardian', $npc( 'known_loot' ) );
    $result = $counts->apply( $records, $resolve )[0];
    checkLootCount( $result['lootSourceCount'] === 2 && $result['lootSourceCountComplete'], 'Public redlink NPCs and unzoned authored NPC loot both count globally' );
    checkLootCount( $result['lootSourceNpcs'] === [ 'Missing guardian', 'Unzoned guardian' ], 'Canonical names accompany the count for offline pending-source deduplication' );

    foreach ( [ 'Private guardian', 'Suppressed guardian', 'Broken alias', 'Read failure' ] as $name ) {
        $counts = new EqlItemLootSourceCounts();
        $counts->item( 'Shared Relic', $item( '* [[Guardian One]]' . "\n* [[" . $name . ']]' ) );
        $result = $counts->apply( $records, $resolve )[0];
        checkLootCount( $result['lootSourceNpcs'] === [ 'Guardian One' ] && !$result['lootSourceCountComplete'], 'Unreadable or unresolved source identities stay private and cannot prove Unique' );
    }
    $counts = new EqlItemLootSourceCounts();
    $counts->item( 'Shared Relic', $item( "* [[A loathling lich pet]]\n* [[a carpet]]\n* [[a petal keeper]]" ) );
    $result = $counts->apply( $records, $resolve )[0];
    checkLootCount( $result['lootSourceNpcs'] === [ 'a carpet', 'a petal keeper' ] && $result['lootSourceCountComplete'], 'Only an explicit final pet token is excluded' );
    $counts = new EqlItemLootSourceCounts( 2 );
    $counts->item( 'Shared Relic', $item() );
    foreach ( [ 'Guardian One', 'Guardian Two', 'Guardian Three' ] as $name ) { $counts->npc( $name, $npc( 'known_loot' ) ); }
    $result = $counts->apply( $records, $resolve )[0];
    checkLootCount( $result['lootSourceCount'] === 2 && !$result['lootSourceCountComplete'], 'Relation-budget exhaustion preserves only an incomplete lower bound' );
    $counts = new EqlItemLootSourceCounts( 100, 1 );
    $counts->item( 'Shared Relic', $item( "* [[Guardian One]]\n* [[Guardian Two]]" ) );
    $result = $counts->apply( $records, $resolve )[0];
    checkLootCount( $result['lootSourceCount'] === 1 && !$result['lootSourceCountComplete'], 'Per-item result overflow never proves a small complete count' );
    $counts = $five(); $counts->npc( 'Malformed guardian', '{{Namedmobpage|known_loot=Broken' );
    checkLootCount( !$counts->apply( $records, $resolve )[0]['lootSourceCountComplete'], 'An unparsed NPC page can conceal sources for any item' );
    $counts = new EqlItemLootSourceCounts();
    $counts->item( 'Shared Relic', '{{Itempage|dropsfrom=[[Guardian One]]|drops_from=[[Guardian Two]]}}' );
    checkLootCount( !$counts->apply( $records, $resolve )[0]['lootSourceCountComplete'], 'Conflicting authored source fields do not become a complete zero' );
    $counts = new EqlItemLootSourceCounts();
    $counts->item( 'Crystallized Sulfur', '{{Itempage|itemname=Crystallized Sulfur|dropsfrom=}}' );
    foreach ( [ 'Guardian One', 'Guardian Two', 'Guardian Three', 'Guardian Four', 'Guardian Five', 'Guardian Six' ] as $name ) { $counts->npc( $name, $npc( 'common_loot', 'Crystallized Sulfur' ) ); }
    $catalog = $counts->catalog( $resolve );
    checkLootCount( $catalog['Crystallized Sulfur']['lootSourceCount'] === 6 && $catalog['Crystallized Sulfur']['lootSourceCountComplete'], 'Statless materials retain global metadata without inventing rich item records' );
    $counts->npc( 'Guardian One', $npc( 'known_loot', 'New redlink item' ) );
    $catalog = $counts->catalog( $resolve );
    checkLootCount( $catalog['New redlink item']['lootSourceCount'] === 1 && $catalog['New redlink item']['lootSourceCountComplete'], 'Missing item pages still retain globally authored NPC sources in the count catalog' );
    $counts = new EqlItemLootSourceCounts();
    $counts->item( 'Staff of Elemental Mastery: Water', '{{Itempage|itemname=Staff of Elemental Mastery: Water|dropsfrom=}}' );
    $counts->npc( 'Guardian One', $npc( 'known_loot', 'Staff of Elemental Mastery: Water' ) );
    checkLootCount( $counts->catalog( $resolve )['Staff of Elemental Mastery: Water']['lootSourceCount'] === 1, 'A colon inside a real main-namespace item name is not a namespace exclusion' );
    $counts = new EqlItemLootSourceCounts(); $counts->item( 'Shared Relic', $item() );
    $counts->npc( 'Guardian One', '{{NPCpage|knownloot={{:Shared Relic}}}}' );
    checkLootCount( $counts->apply( $records, $resolve )[0]['lootSourceCount'] === 1, 'Compatible older NPC template and loot-field spellings remain counted' );
    $counts->npc( 'Guardian Two', '{{Namedmobpage|known_loot={{UnknownLootWrapper|{{:Shared Relic}}}}}}' );
    checkLootCount( !$counts->apply( $records, $resolve )[0]['lootSourceCountComplete'], 'Unknown unexpanded loot wrappers cannot prove Unique' );

    // Execute the actual producer's public revision/redirect adapter against
    // service doubles, rather than testing only a permissive resolver callback.
    $source = file_get_contents( __DIR__ . '/../maintenance/BuildEyeOfZommPack.php' );
    $method = static function ( string $name, string $next ) use ( $source ): string {
        preg_match( '/    private function ' . $name . '\b[\s\S]*?(?=\n    private function ' . $next . '\b)/', $source, $match );
        return $match[0];
    };
    $methods = $method( 'publicLootText', 'rememberPublicLootSource' ) . $method( 'rememberPublicLootSource', 'resolveLootTitle' ) .
        $method( 'resolveLootTitle', 'enrichNpcRedirectAliases' ) . $method( 'enrichNpcRedirectAliases', 'parseItemDisplayLines' );
    $methods = str_replace( 'MediaWikiServices::', '\\MediaWiki\\MediaWikiServices::', $methods );
    $methods = preg_replace( '/\bTitle\b/', '\\MediaWiki\\Title\\Title', $methods );
    eval( 'class LootProducerFixture {
        private $lootCounts; private array $lootTitleMemo=[];
        public function __construct() { $this->lootCounts=new EqlItemLootSourceCounts(); }
        private function maybePause(): void {}
        public function resolve($name) { return $this->resolveLootTitle($name, $GLOBALS["lootFactory"]); }
        public function remember($kind, $name) { $title = \MediaWiki\Title\Title::newFromText($name); $this->rememberPublicLootSource($kind, $title, $GLOBALS["lootFactory"]->newFromTitle($title)); }
        public function catalog() { return $this->lootCounts->catalog(fn($name) => $this->resolve($name)); }
        public function aliases($npcs, $db) { return $this->enrichNpcRedirectAliases($npcs, $db, $GLOBALS["lootFactory"]); }
        ' . $methods . ' }' );
    $GLOBALS['lootPages'] = [
        'Public guardian' => [ 'id' => 20, 'text' => '{{Namedmobpage}}' ],
        'Public alias' => [ 'id' => 21, 'text' => '#REDIRECT [[Public guardian]]', 'redirect' => 'Public guardian' ],
        'Private guardian' => [ 'id' => 22, 'text' => 'PRIVATE CONTENT', 'private' => true ],
        'Suppressed guardian' => [ 'id' => 23, 'text' => 'SUPPRESSED CONTENT', 'hidden' => true ],
        'Private alias' => [ 'id' => 24, 'text' => '#REDIRECT [[Public guardian]]', 'redirect' => 'Public guardian', 'private' => true ],
        'Loop alias' => [ 'id' => 25, 'text' => '#REDIRECT [[Loop alias]]', 'redirect' => 'Loop alias' ],
        'Section alias' => [ 'id' => 26, 'text' => '#REDIRECT [[Public guardian#Raid Instance]]', 'redirect' => 'Public guardian#Raid Instance' ],
        'Public material' => [ 'id' => 27, 'text' => "{{Itempage|itemname=Public material|dropsfrom=* [[Public guardian]]\n* [[Public alias]]}}" ],
    ];
    $GLOBALS['lootFactory'] = new class {
        public function newFromTitle( $title ) { return new class( $GLOBALS['lootPages'][$title->getText()] ?? [] ) {
            public function __construct( private array $page ) {}
            public function getRevisionRecord() { return new class( $this->page ) {
                public function __construct( private array $page ) {}
                public function getContent( $slot, $audience ) {
                    checkLootCount( $slot === 'main' && $audience === \MediaWiki\Revision\RevisionRecord::FOR_PUBLIC, 'Read only public current main-slot content' );
                    if ( $this->page['hidden'] ?? false ) { return null; }
                    return new class( $this->page['text'] ?? '' ) { public function __construct( private string $text ) {} public function getModel() { return 'wikitext'; } public function getText() { return $this->text; } };
                }
            }; }
        }; }
    };
    \MediaWiki\MediaWikiServices::$fixture = new class {
        public function getUserFactory() { return new class { public function newAnonymous() { return 'anonymous'; } }; }
        public function getPermissionManager() { return new class { public function userCan( $action, $user, $title ) {
            checkLootCount( $action === 'read' && $user === 'anonymous', 'A logged-in builder cannot widen public visibility' );
            return !( $GLOBALS['lootPages'][$title->getText()]['private'] ?? false );
        } }; }
        public function getRedirectLookup() { return new class { public function getRedirectTarget( $title ) { return isset( $GLOBALS['lootPages'][$title->getText()]['redirect'] ) ? Title::newFromText( $GLOBALS['lootPages'][$title->getText()]['redirect'] ) : null; } }; }
        public function getTitleFactory() { return new class { public function newFromLinkTarget( $target ) { return $target; } }; }
    };
    $candidates = new LootProducerFixture();
    $candidates->remember( 'npc', 'Public alias' );
    checkLootCount( $candidates->resolve( 'Public alias' ) === [ 'title' => 'Public guardian', 'pageId' => 20, 'public' => true, 'complete' => true ], 'A categorized redirect candidate cannot preseed its own page ID as canonical' );
    $candidates->remember( 'npc', 'Public guardian' );
    $candidates->remember( 'item', 'Public material' );
    $candidateCount = $candidates->catalog()['Public material'];
    checkLootCount( $candidateCount === [ 'lootSourceNpcs' => [ 'Public guardian' ], 'lootSourceCount' => 1, 'lootSourceCountComplete' => true ], 'Reading canonical and redirect category candidates still counts one public monster' );
    $hiddenCandidates = new LootProducerFixture();
    $hiddenCandidates->remember( 'npc', 'Private guardian' );
    $hiddenCandidates->remember( 'npc', 'Suppressed guardian' );
    checkLootCount( !$hiddenCandidates->resolve( 'Private guardian' )['public'] && !$hiddenCandidates->resolve( 'Suppressed guardian' )['public'], 'Unreadable categorized candidates cannot preseed public canonical metadata' );
    $adapter = new LootProducerFixture();
    checkLootCount( $adapter->resolve( 'Public alias' ) === [ 'title' => 'Public guardian', 'pageId' => 20, 'public' => true, 'complete' => true ], 'Current public redirects resolve to one canonical page ID' );
    checkLootCount( $adapter->resolve( 'Section alias' ) === $adapter->resolve( 'Public alias' ), 'Instance-section redirects share the same canonical mob identity' );
    checkLootCount( $adapter->resolve( 'Missing guardian' )['pageId'] === null && $adapter->resolve( 'Missing guardian' )['complete'], 'Missing public NPC titles retain explicit redlink identity' );
    foreach ( [ 'Private guardian', 'Suppressed guardian', 'Private alias', 'Loop alias', 'Talk:Not a mob', 'Talk:Public guardian' ] as $name ) {
        $record = $adapter->resolve( $name );
        checkLootCount( !$record['public'] && !$record['complete'] && $record['pageId'] === null, 'Private, suppressed, cyclic and non-main identities expose no ID or contents' );
    }
    $db = new class { public function select( $tables, $fields, $where, $caller, $options, $joins ) {
        checkLootCount( $where['page_namespace'] === 0 && $where['rd_namespace'] === 0 && $options['LIMIT'] === 20001, 'Alias discovery is a bounded main-namespace query' );
        return [ (object)[ 'page_title' => 'Public_alias' ], (object)[ 'page_title' => 'Private_alias' ] ];
    } };
    $aliases = $adapter->aliases( [ [ 'wikiTitle' => 'Public guardian' ] ], $db );
    checkLootCount( $aliases[0]['aliases'] === [ 'Public alias' ], 'Rich NPC aliases include public redirects and never private redirect names' );
    echo "Global loot source counts: $checks synthetic checks passed (five/six boundary, cross-zone Common/Unique, aliases/IDs, redlinks, privacy, pets, limits and incomplete lower bounds).\n";
}
