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
    $counts = $five();
    $counts->item( 'Shared Relic', $item( "* [[Guardian One]]\n* [[ Ground_Spawn ]]\n* [[GROUND SPAWNS]]" ) );
    $counts->npc( 'Ground Spawn', $npc( 'common_loot' ) );
    $result = $counts->apply( $records, $resolve )[0];
    checkLootCount( $result['lootSourceCount'] === 5 && $result['lootSourceCountComplete'], 'Five real NPCs plus exact normalized Ground Spawn/Spawns pseudomobs remain five complete monsters' );
    $groundAliasResolver = static fn ( string $name ) => $name === 'Synthetic ground alias'
        ? [ 'title' => 'Ground Spawns', 'pageId' => 500, 'public' => true, 'complete' => true ] : $resolve( $name );
    $counts = new EqlItemLootSourceCounts();
    $counts->item( 'Shared Relic', $item( "* [[Synthetic ground alias]]\n* [[Ground Spawn Keeper]]" ) );
    $result = $counts->apply( $records, $groundAliasResolver )[0];
    checkLootCount( $result['lootSourceNpcs'] === [ 'Ground Spawn Keeper' ] && $result['lootSourceCountComplete'], 'Canonical aliases to Ground Spawns are excluded; longer monster names remain valid' );
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

    $duplicateMerchant = '{{Namedmobpage|name=Merchant display|zone=[[Earlier Zone]]|zone=[[Final Zone]]|items_sold=Earlier stock|items_sold=Final stock|known_loot={{:Shared Relic}}}}';
    checkLootCount( EqlNpcLootWikitext::analyseNpcPage( $duplicateMerchant ) === null, 'Default renderer analysis still rejects duplicate non-loot parameters' );
    $parsed = true;
    checkLootCount( EqlNpcLootWikitext::npcLootSources( $duplicateMerchant, $parsed, true ) === [] && !$parsed, 'Colon-permissive recognition alone does not opt into duplicate compatibility' );
    $analysis = EqlNpcLootWikitext::analyseNpcPage( $duplicateMerchant, true );
    checkLootCount( $analysis !== null && $analysis['zones'] === [ 'Final Zone' ], 'Global-only NPC analysis uses the last duplicate non-loot parameter' );
    $counts = $five();
    $counts->npc( 'Guardian Five', str_replace( [ 'Namedmobpage', 'known_loot' ], [ 'MerchantPage', 'knownloot' ], $duplicateMerchant ) );
    $result = $counts->apply( $records, $resolve )[0];
    checkLootCount( $result['lootSourceCount'] === 5 && $result['lootSourceCountComplete'], 'Duplicate merchant items_sold fields cannot make five global sources incomplete' );
    $counts->npc( 'Guardian Six', str_replace( 'Namedmobpage', 'NPCpage', $duplicateMerchant ) );
    $result = $counts->apply( $records, $resolve )[0];
    checkLootCount( $result['lootSourceCount'] === 6 && $result['lootSourceCountComplete'], 'Duplicate non-loot NPC fields retain complete six-source Common proof' );
    foreach ( [ 'known_loot', 'common_loot' ] as $field ) {
        $counts = new EqlItemLootSourceCounts(); $counts->item( 'Shared Relic', $item() );
        $counts->npc( 'Guardian Five', '{{Namedmobpage|' . $field . '={{:Shared Relic}}|' . $field . '=None}}' );
        $result = $counts->apply( $records, $resolve )[0];
        checkLootCount( $result['lootSourceCount'] === 0 && $result['lootSourceCountComplete'], 'Global counts use only the final repeated exact static loot parameter' );
        $counts = new EqlItemLootSourceCounts(); $counts->item( 'Shared Relic', $item() );
        $counts->npc( 'Guardian Five', '{{Namedmobpage|' . $field . '= |' . $field . '={{:Shared Relic}}}}' );
        $result = $counts->apply( $records, $resolve )[0];
        checkLootCount( $result['lootSourceCount'] === 1 && $result['lootSourceCountComplete'], 'Blank shadowed defaults do not conceal the final rendered loot list' );
    }
    $parsed = true;
    EqlNpcLootWikitext::npcLootSources( '{{Namedmobpage|known_loot={{:Shared Relic}}|KNOWN_LOOT=None}}', $parsed, true, true );
    checkLootCount( !$parsed, 'Case-distinct parameter names are not guessed to be repeated MediaWiki keys' );
    $parsed = true;
    EqlNpcLootWikitext::npcLootSources( '{{OtherTemplate|items_sold=First|items_sold=Second}}{{Namedmobpage|known_loot={{:Shared Relic}}}}', $parsed, true, true );
    checkLootCount( !$parsed, 'Global duplicate compatibility applies only to Namedmobpage, not arbitrary templates' );
    $literalClose = "((Synthetic Era}}\n{{Namedmobpage|known_loot={{:Shared Relic}}}}";
    checkLootCount( EqlNpcLootWikitext::analyseNpcPage( $literalClose ) === null, 'Default renderer parsing still rejects stray closing markup' );
    $parsed = true; $rows = EqlNpcLootWikitext::npcLootSources( $literalClose, $parsed, true, true );
    checkLootCount( $parsed && count( $rows ) === 1, 'Global parsing treats closing braces outside every card as literal prose' );
    $parsed = true; EqlNpcLootWikitext::npcLootSources( '{{Unclosed prefix {{Namedmobpage|known_loot={{:Shared Relic}}}}', $parsed, true, true );
    checkLootCount( !$parsed, 'Unclosed markup can conceal a card and always stays incomplete' );
    foreach ( [ 'Chardok Revamp Era Inline', 'Item Lore|Synthetic lore text', 'Unconfirmed Itemlist', 'P1999|Synthetic_page', 'Era Short|Classic', 'Lootcheck' ] as $badge ) {
        $parsed = true; $rows = EqlNpcLootWikitext::npcLootSources( '{{Namedmobpage|known_loot={{' . $badge . '}}{{:Shared Relic}}}}', $parsed, true, true );
        checkLootCount( $parsed && array_column( $rows, 'item' ) === [ 'Shared Relic' ], 'Reviewed literal metadata does not hide or invent a loot item' );
    }
    $parsed = true; EqlNpcLootWikitext::npcLootSources( '{{Namedmobpage|known_loot={{Item Lore|{{UnknownWrapper}}}}{{:Shared Relic}}}}', $parsed, true, true );
    checkLootCount( !$parsed, 'Dynamic arguments of metadata templates remain opaque' );
    $legacy = '{{Namedmobpage|known_loot={{Synthetic Legacy Relic}}{{VeliousGray|{{Template:Other Legacy Relic}}}}{{Unknown With Args|value}}{{P1999}}}}';
    checkLootCount( EqlNpcLootWikitext::globalNpcLootTemplateNames( $legacy ) === [ 'Synthetic Legacy Relic', 'Other Legacy Relic' ], 'Only active unknown zero-argument templates are exposed for public verification, preserving case' );
    $parsed = true; $rows = EqlNpcLootWikitext::npcLootSources( '{{Namedmobpage|known_loot={{Synthetic Legacy Relic}}}}', $parsed, true, true, [ 'Synthetic Legacy Relic' => 'Shared Relic' ] );
    checkLootCount( $parsed && array_column( $rows, 'item' ) === [ 'Shared Relic' ], 'An independently verified legacy item template contributes its canonical main article' );
    $parsed = true; EqlNpcLootWikitext::npcLootSources( '{{Namedmobpage|known_loot={{Synthetic Legacy Relic|override}}}}', $parsed, true, true, [ 'Synthetic Legacy Relic' => 'Shared Relic' ] );
    checkLootCount( !$parsed, 'Verified item templates cannot override their verified zero-argument output' );
    $parsed = true; EqlNpcLootWikitext::npcLootSources( '{{Namedmobpage|known_loot={{Unverified Legacy Relic}}}}', $parsed, true, true );
    checkLootCount( !$parsed, 'Unverified unknown item templates remain incomplete' );
    checkLootCount( EqlNpcLootWikitext::templateInvocationKey( ' Template:synthetic_Legacy Relic ' ) === 'Synthetic Legacy Relic', 'Template invocation keys normalize namespace, first letter and underscores without folding suffix case' );
    checkLootCount( EqlNpcLootWikitext::globalNpcLootTemplateNames( '{{Namedmobpage|known_loot={{Synthetic Legacy Relic}}{{Synthetic legacy Relic}}}}' ) === [ 'Synthetic Legacy Relic', 'Synthetic legacy Relic' ], 'Case-distinct legacy templates both require independent public verification' );
    $parsed = true; $rows = EqlNpcLootWikitext::npcLootSources( '{{Namedmobpage|known_loot={{Synthetic Legacy Relic}}{{Synthetic legacy Relic}}}}', $parsed, true, true, [ 'Synthetic Legacy Relic' => 'Shared Relic' ] );
    checkLootCount( !$parsed && array_column( $rows, 'item' ) === [ 'Shared Relic' ], 'A verified legacy template never verifies a different case-distinct template' );
    $parsed = true; EqlNpcLootWikitext::npcLootSources( '{{Namedmobpage|known_loot={{Item lore}}{{:Shared Relic}}}}', $parsed, true, true );
    checkLootCount( !$parsed, 'A case-distinct template does not inherit reviewed metadata output' );

    $hoverCard = '<div class="hbdiv">[[Shared Relic]]<span class="hb">{{Itembox|itemname=Shared Relic|statsblock=AC: 2}}</span></div>';
    $staticTemplate = '<onlyinclude><includeonly>' . $hoverCard . '</includeonly></onlyinclude>{{Itempage|itemname=Shared Relic}}';
    checkLootCount( EqlNpcLootWikitext::globalItemTemplateTarget( $staticTemplate ) === 'Shared Relic', 'A single static unconditional legacy hover has one matching item target' );
    checkLootCount( EqlNpcLootWikitext::globalItemTemplateTarget( str_replace( [ '<includeonly>', '</includeonly>' ], '', $staticTemplate ) ) === 'Shared Relic', 'The same literal onlyinclude hover does not require redundant includeonly tags' );
    foreach ( [
        str_replace( '<includeonly>', '<includeonly>Prefix', $staticTemplate ),
        str_replace( '</includeonly>', '{{Unknown}}' . '</includeonly>', $staticTemplate ),
        str_replace( '</includeonly>', '[[Other Relic]]' . '</includeonly>', $staticTemplate ),
        str_replace( '</span>', '{{Unknown}}</span>', $staticTemplate ),
        str_replace( '</includeonly>', $hoverCard . '</includeonly>', $staticTemplate ),
        $staticTemplate . '{{Itempage|itemname=Shared Relic}}',
        str_replace( '[[Shared Relic]]', '[[Other Relic]]', $staticTemplate ),
        str_replace( 'Itembox|itemname=Shared Relic', 'Itembox|itemname=Other Relic', $staticTemplate ),
        str_replace( 'AC: 2', 'AC: {{{1|2}}}', $staticTemplate ),
        str_replace( 'AC: 2', '{{#if:1|AC: 2}}', $staticTemplate ),
        str_replace( '[[Shared Relic]]', '[[Shared Relic|{{Unknown}}]]', $staticTemplate ),
        $staticTemplate . '<onlyinclude>Another output</onlyinclude>',
        str_replace( '</includeonly>', '', $staticTemplate ),
    ] as $unsafeTemplate ) {
        checkLootCount( EqlNpcLootWikitext::globalItemTemplateTarget( $unsafeTemplate ) === null, 'Extra, mismatched, conditional or malformed legacy hover output cannot prove one item' );
    }

    $setComplete = true;
    checkLootCount( EqlNpcLootWikitext::globalGearSetItems( '{{Gear Set|Shared Relic|[[Set-only Relic|Sleeve]]|Shared_Relic}}', $setComplete ) === [ 'Shared Relic', 'Set-only Relic' ] && $setComplete, 'Gear Set literal positional members deduplicate without name guesses' );
    checkLootCount( EqlNpcLootWikitext::globalGearSetItems( '{{Example|{{Gear Set|Shared Relic}}}}', $setComplete ) === null && $setComplete, 'Nested examples do not establish a gear set' );
    checkLootCount( EqlNpcLootWikitext::globalGearSetItems( '{{ClassOverview|duplicate=One|duplicate=Two}} {{:Synthetic Armor}}', $setComplete ) === null && $setComplete, 'Indirect set transclusions do not parse unrelated class markup as a set definition' );
    checkLootCount( EqlNpcLootWikitext::globalGearSetItems( '<!-- {{Gear Set|Shared Relic}} --><nowiki>{{Gear Set|Shared Relic}}</nowiki>{{:Synthetic Armor}}', $setComplete ) === null && $setComplete, 'Inactive set examples do not make an indirectly linked page a set' );
    checkLootCount( EqlNpcLootWikitext::globalGearSetItems( '{{Template:gear_Set|Shared Relic}}', $setComplete ) === [ 'Shared Relic' ] && $setComplete, 'Literal canonical Gear Set invocation handles namespace, underscore and first-letter normalization' );
    checkLootCount( EqlNpcLootWikitext::globalGearSetItems( '{{Gear Set|Shared Relic}}{{Gear set|Other Relic}}', $setComplete ) === [ 'Shared Relic' ] && $setComplete, 'A case-distinct template is not another Gear Set definition' );
    checkLootCount( EqlNpcLootWikitext::globalGearSetItems( '{{Gear Set||Shared Relic| }}', $setComplete ) === [ 'Shared Relic' ] && $setComplete, 'Blank positional slots are ignored exactly as the reviewed GearSet module does' );
    foreach ( [ '{{Gear Set|item=Shared Relic}}', '{{Gear Set|{{Unknown}}}}', '{{Gear Set|[[File:Example]]}}', '{{Gear Set|}}', '{{Gear Set|Shared Relic' ] as $source ) {
        EqlNpcLootWikitext::globalGearSetItems( $source, $setComplete );
        checkLootCount( !$setComplete, 'Named, dynamic, invalid or malformed set members do not prove a complete set' );
    }
    $counts = $five();
    $counts->sets( [ 'Synthetic Gear' => [ 'items' => [ 'Relic Alias', 'Shared Relic', 'Set-only Relic' ], 'complete' => true ] ] );
    $counts->npc( 'Guardian Six', '{{Namedmobpage|known_loot={{:Synthetic Gear}}{{:Shared Relic}}}}' );
    $setCatalog = $counts->catalog( $resolve );
    checkLootCount( $setCatalog['Shared Relic']['lootSourceCount'] === 6 && $setCatalog['Shared Relic']['lootSourceCountComplete'], 'A sixth global NPC dropping an explicit set counts for each member once' );
    checkLootCount( $setCatalog['Set-only Relic']['lootSourceCount'] === 1 && !isset( $setCatalog['Synthetic Gear'] ), 'Set source references create member counts, never a fictitious item count for the set title' );
    checkLootCount( $counts->catalog( $resolve ) === $setCatalog, 'Repeated catalog generation does not mutate or multiply set references' );
    $counts = new EqlItemLootSourceCounts();
    $counts->sets( [ 'Synthetic Gear' => [ 'items' => [ 'Shared Relic' ], 'complete' => false ] ] );
    $counts->npc( 'Guardian One', '{{Namedmobpage|known_loot={{:Synthetic Gear}}}}' );
    checkLootCount( !$counts->catalog( $resolve )['Shared Relic']['lootSourceCountComplete'], 'Referenced incomplete sets cannot establish Unique proof' );
    $counts = new EqlItemLootSourceCounts(); $counts->item( 'Shared Relic', $item() );
    $counts->sets( [ 'Synthetic Gear' => [ 'items' => [ 'Synthetic Gear' ], 'complete' => true ] ] );
    $counts->npc( 'Guardian One', '{{Namedmobpage|known_loot={{:Synthetic Gear}}}}' );
    checkLootCount( !$counts->catalog( $resolve )['Shared Relic']['lootSourceCountComplete'], 'A set cycle stays bounded and cannot prove a small total' );
    $counts = new EqlItemLootSourceCounts(); $counts->item( 'Shared Relic', $item() );
    $counts->sets( [
        'Synthetic Gear' => [ 'items' => [ 'Nested Gear One', 'Nested Gear Two' ], 'complete' => true ],
        'Nested Gear One' => [ 'items' => [ 'Nested Gear Shared' ], 'complete' => true ],
        'Nested Gear Two' => [ 'items' => [ 'Nested Gear Shared' ], 'complete' => true ],
        'Nested Gear Shared' => [ 'items' => [ 'Shared Relic' ], 'complete' => true ],
    ] );
    $counts->npc( 'Guardian One', '{{Namedmobpage|known_loot={{:Synthetic Gear}}{{:Nested Gear Shared}}}}' );
    $nested = $counts->catalog( $resolve )['Shared Relic'];
    checkLootCount( $nested['lootSourceCount'] === 1 && $nested['lootSourceCountComplete'], 'Overlapping nested sets and direct member references deduplicate without losing completeness' );
    $counts = new EqlItemLootSourceCounts( 1 ); $counts->item( 'Shared Relic', $item() );
    $counts->sets( [ 'Synthetic Gear' => [ 'items' => [ 'Shared Relic', 'Set-only Relic', 'Third Relic' ], 'complete' => true ] ] );
    $counts->npc( 'Guardian One', '{{Namedmobpage|known_loot={{:Synthetic Gear}}}}' );
    $boundedSet = $counts->catalog( $resolve )['Shared Relic'];
    checkLootCount( $boundedSet['lootSourceCount'] === 1 && !$boundedSet['lootSourceCountComplete'], 'A bounded set expansion retains proved lower bounds and cannot prove a small total' );

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
