<?php
/** Executes actual DynamicZoneList admission/render entry against synthetic services. */
namespace MediaWiki\Title {
    class Title {
        public function __construct( private string $text, private int $ns = 0, private bool $external = false ) {}
        public static function newFromText( $value, $namespace = 0 ) {
            if ( !is_string( $value ) || $value === '' || preg_match( '/[\[\]{}|\r\n]/', $value ) ) { return null; }
            $value = ucfirst( preg_replace( '/\s+/', ' ', str_replace( '_', ' ', trim( $value ) ) ) );
            if ( preg_match( '/^(?:Talk|User|File|Template|Category|Special):/i', $value ) ) { $namespace = 1; }
            return new self( $value, $namespace, str_starts_with( $value, 'External:' ) );
        }
        public function getText() { return $this->text; } public function getDBkey() { return str_replace( ' ', '_', $this->text ); }
        public function getNamespace() { return $this->ns; } public function isExternal() { return $this->external; } public function getFragment() { return ''; }
    }
}
namespace MediaWiki\Revision { class RevisionRecord { public const FOR_PUBLIC = 1; } }
namespace MediaWiki\Parser { class ParserOptions { public static function newFromAnon() { return new self(); } } }
namespace MediaWiki { class MediaWikiServices { public static $fixture; public static function getInstance() { return self::$fixture; } } }
namespace {
class SpecialPage { public function __construct( ...$args ) {} }
define( 'NS_MAIN', 0 ); define( 'NS_CATEGORY', 14 ); define( 'DB_REPLICA', 1 );
require __DIR__ . '/../extensions/DynamicZoneList/DynamicZoneList_body.php';
$n = 0; $check = static function ( $yes, $message ) use ( &$n ) { $n++; if ( !$yes ) { throw new RuntimeException( $message ); } };
$GLOBALS['zones'] = [
    'Synthetic New Lake' => [ 'source' => 'A new zone. [[Category:Zones]]' ],
    'Synthetic Lowercase' => [ 'source' => '[[category:zones|Lowercase]]' ],
    'Synthetic Selfclosing' => [ 'source' => '<nowiki /> [[Category:Zones]]' ],
    'Synthetic Long Newly Authored Zone With A Name Over Forty Characters' => [ 'source' => '[[Category:Zones]]' ],
    'Synthetic Ordinary Page' => [ 'source' => 'An ordinary article. [[Category:Equipment]]', 'categorized' => false ],
    'Synthetic Redirect' => [ 'source' => '#REDIRECT [[Synthetic New Lake]] [[Category:Zones]]', 'redirect' => true ],
    'Synthetic Private' => [ 'source' => '[[Category:Zones]]', 'private' => true ],
    'Synthetic Suppressed' => [ 'source' => '[[Category:Zones]]', 'hidden' => true ],
    'Synthetic Inherited' => [ 'source' => '{{Unknown zone category generator}}' ],
    'Synthetic Comment' => [ 'source' => '<!-- [[Category:Zones]] -->' ],
    'Synthetic Protected' => [ 'source' => '<nowiki>[[Category:Zones]]</nowiki>' ],
    'Synthetic Navigation' => [ 'source' => '[[:Category:Zones]]' ],
    'Synthetic Wrong Category Case' => [ 'source' => '[[Category:ZONES]]' ],
    'Synthetic Nonwikitext' => [ 'source' => '[[Category:Zones]]', 'model' => 'json' ],
    'Synthetic Oversized' => [ 'source' => str_repeat( ' ', 524289 ) . '[[Category:Zones]]' ]
];
$db = new class {
    public int $eligibilityReads = 0; public array $zoneQueries = [];
    public function selectRow( $tables, $fields, $where, $caller, $options, $joins ) {
        $this->eligibilityReads++;
        if ( $where['page_namespace'] !== NS_MAIN || $where['page_is_redirect'] !== 0 || $where['zone_lt.lt_namespace'] !== NS_CATEGORY || $where['zone_lt.lt_title'] !== 'Zones' || $fields !== [ 'page_id' ] || $joins['zone_lt'][1] !== 'zone_cl.cl_target_id = zone_lt.lt_id' ) { throw new RuntimeException( 'Unbounded category admission query.' ); }
        $page = $GLOBALS['zones'][str_replace( '_', ' ', $where['page_title'] )] ?? null;
        return !$page || ( $page['redirect'] ?? false ) || !( $page['categorized'] ?? true ) ? false : (object)[ 'page_id' => 123 ];
    }
    public function addQuotes( $value ) { $this->zoneQueries[] = $value; return "'" . $value . "'"; }
    public function select( ...$args ) { return []; }
};
$parser = new class {
    public function transformMsg( $value, $options ) { return $value; }
    public function parse( $text, $title, $options ) { return new class( $text ) { public function __construct( private string $text ) {} public function getContentHolderText() { return $this->text; } }; }
};
$services = new class( $db, $parser ) {
    public function __construct( private $db, private $parser ) {}
    public function getPermissionManager() { return new class { public function userCan( $action, $user, $title ) {
        if ( $action !== 'read' || $user !== 'anonymous' ) { throw new RuntimeException( 'Expected anonymous permission check.' ); }
        return !( $GLOBALS['zones'][$title->getText()]['private'] ?? false );
    } }; }
    public function getUserFactory() { return new class { public function newAnonymous() { return 'anonymous'; } }; }
    public function getWikiPageFactory() { return new class { public function newFromTitle( $title ) { return new class( $GLOBALS['zones'][$title->getText()] ?? [] ) {
        public function __construct( private array $fixture ) {}
        public function getRevisionRecord() { return new class( $this->fixture ) {
            public function __construct( private array $fixture ) {} public function getId() { return 456; }
            public function getContent( $slot, $audience ) {
                if ( $slot !== 'main' || $audience !== \MediaWiki\Revision\RevisionRecord::FOR_PUBLIC ) { throw new RuntimeException( 'Expected public main-slot revision.' ); }
                if ( $this->fixture['hidden'] ?? false ) { return null; }
                return new class( $this->fixture ) { public function __construct( private array $fixture ) {} public function getModel() { return $this->fixture['model'] ?? 'wikitext'; } public function getText() { return $this->fixture['source'] ?? ''; } };
            }
        }; }
    }; } }; }
    public function getParserFactory() { return $this; } public function create() { return $this->parser; }
    public function getDBLoadBalancer() { return $this; } public function getConnection( $index ) { return $this->db; }
};
\MediaWiki\MediaWikiServices::$fixture = $services;
$resolve = static fn ( $value ) => DynamicZoneList::resolveZoneName( $value, [ 'Rathe Mountains' ], $services, $db );
$check( $resolve( 'Rathe_Mountains' ) === 'Rathe Mountains' && $db->eligibilityReads === 0, 'The full legacy path remains compatible without new category requirements' );
$check( $resolve( 'synthetic_New_Lake' ) === 'Synthetic New Lake', 'A new canonical public zone is admitted without adding a hardcoded name' );
$check( $resolve( 'Synthetic Lowercase' ) === 'Synthetic Lowercase' && $resolve( 'Synthetic Selfclosing' ) === 'Synthetic Selfclosing', 'Actual category namespace/first-letter and protected-tag normalization remains supported' );
$check( $resolve( 'Synthetic Long Newly Authored Zone With A Name Over Forty Characters' ) === 'Synthetic Long Newly Authored Zone With A Name Over Forty Characters', 'Valid new zone titles are never silently truncated to the old forty-byte prefix' );
foreach ( array_keys( $GLOBALS['zones'] ) as $title ) {
    if ( in_array( $title, [ 'Synthetic New Lake', 'Synthetic Lowercase', 'Synthetic Selfclosing', 'Synthetic Long Newly Authored Zone With A Name Over Forty Characters' ], true ) ) { continue; }
    $check( $resolve( $title ) === null, 'No admission from a non-zone, redirect, private/suppressed, inherited or inactive source: ' . $title );
}
foreach ( [ 'Synthetic Missing', 'User:Synthetic New Lake', 'Category:Zones', 'Special:DynamicZoneList', 'External:Synthetic New Lake', 'Synthetic New Lake#Raid_Instance', '{{Synthetic New Lake}}', "Synthetic\nNew Lake", str_repeat( 'A', 256 ), [ 'Synthetic New Lake' ] ] as $request ) {
    $check( $resolve( $request ) === null, 'No arbitrary categories, namespace, fragment, interwiki, malformed or oversized title admission' );
}
$wgTitle = \MediaWiki\Title\Title::newFromText( 'Synthetic New Lake' );
$wgOut = new class { public string $text = ''; public function addHTML( $text ) { $this->text .= $text; } public function addWikiTextAsContent( $text ) { $this->text .= $text; } public function setPageTitle( $title ) {} };
( new DynamicZoneList() )->execute( 'Synthetic_New_Lake' );
$check( !str_contains( $wgOut->text, 'Invalid zone' ), 'Actual SpecialPage execute reaches existing table renderer for a new authored public zone' );
$check( $db->zoneQueries === [ 'Synthetic_New_Lake', 'Quests', 'Synthetic_New_Lake', 'NPCs', 'Synthetic_New_Lake', 'Items' ], 'Existing category intersections use only the canonical zone and fixed entity categories' );
$wgOut->text = ''; $queries = count( $db->zoneQueries ); ( new DynamicZoneList() )->execute( 'Synthetic Ordinary Page' );
$check( str_contains( $wgOut->text, 'Invalid zone' ) && count( $db->zoneQueries ) === $queries, 'Rejected arbitrary pages never fan out to category tables' );
echo "Dynamic zone admission: {$n} source/service checks passed.\n";
}
