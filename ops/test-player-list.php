<?php
/** Synthetic parser/API/index regression. No wiki pages, accounts or real database writes. */
declare( strict_types = 1 );

namespace Wikimedia\Rdbms {
	interface IConnectionProvider {}
	interface IDBAccessObject { public const READ_LATEST = 1; }
}
namespace MediaWiki\Config { abstract class Config { abstract public function get( $key ); } }
namespace MediaWiki\Page {
	interface PageRecord {}
	class PageStore {
		public array $pages = [];
		public $onRead = null;
		public function getPageById( $id, $flags = 0 ) {
			\check( $flags === 1, 'Current pages use primary/latest reads.' );
			if ( $this->onRead ) { ( $this->onRead )( $id ); }
			return $this->pages[$id] ?? null;
		}
		public function getPageByName( $namespace, $title, $flags = 0 ) {
			\check( $flags === 1, 'Dependencies use primary/latest reads.' );
			foreach ( $this->pages as $page ) {
				if ( $page->namespace === $namespace && $page->title === $title ) { return $page; }
			}
			return null;
		}
	}
	class ParserOutputAccess {
		public const OPT_NO_CACHE = 3;
		public const OPT_NO_CHECK_CACHE = 1;
		public const OPT_NO_UPDATE_CACHE = 2;
		public int $calls = 0;
		public array $options = [];
		public array $outputs = [];
		public bool $fail = false;
		public $onParse = null;
		public function getParserOutput( $page, $options, $revision, $flags = [] ) {
			\check( $options->anonymous, 'Parser must not inherit administrator/requester privileges.' );
			\check( $revision->id === $page->latest, 'Parser receives only the current verified revision.' );
			$this->calls++; $this->options[] = $flags;
			if ( $this->onParse ) { ( $this->onParse )( $page, $flags ); }
			return new class( $this->outputs[$page->id] ?? null, $this->fail ) {
				public function __construct( private $value, private bool $fail ) {}
				public function isGood() { return !$this->fail; }
				public function getValue() { return $this->value; }
			};
		}
	}
}
namespace MediaWiki\Parser {
	class ParserOptions {
		public bool $anonymous = true;
		public static function newFromAnon() { return new self(); }
	}
	enum ParserOutputLinkTypes { case TEMPLATE; }
}
namespace MediaWiki\Revision {
	interface RevisionLookup {}
	class RevisionRecord {
		public const DELETED_TEXT = 1;
		public const FOR_PUBLIC = 1;
		public function __construct( public int $id, public int $pageId, public bool $public = true ) {}
		public function getId() { return $this->id; }
		public function getPageId() { return $this->pageId; }
		public function audienceCan( $field, $audience ) {
			\check( $field === self::DELETED_TEXT && $audience === self::FOR_PUBLIC, 'Visibility always uses the public audience.' );
			return $this->public;
		}
	}
}
namespace MediaWiki\Title {
	class TitleFactory {
		public function newFromPageIdentity( $page ) { return new \FixtureTitle( $page->namespace, $page->title ); }
	}
}
namespace MediaWiki\Permissions {
	class PermissionManager {
		public const RIGOR_SECURE = 'secure';
		public array $denied = [];
		public function userCan( $right, $user, $title, $rigor ) {
			\check( $right === 'read' && $rigor === 'secure' && $user->anonymous, 'Do not reuse elevated requester rights.' );
			return !in_array( $title->getPrefixedText(), $this->denied, true );
		}
	}
}
namespace MediaWiki\User {
	class UserFactory { public function newAnonymous() { return (object)[ 'anonymous' => true ]; } }
}
namespace Wikimedia\ParamValidator {
	class ParamValidator { public const PARAM_TYPE = 'type'; public const PARAM_DEFAULT = 'default'; }
}
namespace Wikimedia\ParamValidator\TypeDef {
	class IntegerDef { public const PARAM_MIN = 'min'; public const PARAM_MAX = 'max'; }
}
namespace MediaWiki\Api {
	class ApiMain {
		public array $data = [];
		public function setCacheMode( $mode ) { \check( $mode === 'private', 'HTTP response cannot be shared across future visibility changes.' ); }
		public function setCacheMaxAge( $seconds ) { \check( $seconds === 0, 'Do not retain browser snapshots after rights change.' ); }
	}
	class ApiBase {
		public array $params = [];
		public function __construct( private ApiMain $main, private string $action ) {}
		public function extractRequestParams() { return $this->params; }
		public function getMain() { return $this->main; }
		public function getModuleName() { return $this->action; }
		public function dieWithError( $message, $code = '' ) { throw new \RuntimeException( $code ); }
		public function getResult() {
			return new class( $this->main ) {
				public function __construct( private ApiMain $main ) {}
				public function addValue( $path, $name, $value ) { $this->main->data[$name] = $value; }
			};
		}
	}
}
namespace {
	use EQLClientData\CharacterSheetSnapshot as Snapshot;
	use EQLClientData\PlayerListRepository as Repository;
	use MediaWiki\Revision\RevisionRecord;
	define( 'NS_USER', 2 ); define( 'NS_TEMPLATE', 10 );
	require __DIR__ . '/../extensions/EQLClientData/includes/CharacterSheetSnapshot.php';
	require __DIR__ . '/../extensions/EQLClientData/includes/CharacterSheetDetail.php';
	require __DIR__ . '/../extensions/EQLClientData/includes/PlayerListRepository.php';
	require __DIR__ . '/../extensions/EQLClientData/includes/ApiPlayers.php';
	$checks = 0;
	function check( bool $condition, string $message ): void {
		global $checks;
		if ( !$condition ) { throw new \RuntimeException( $message ); }
		$checks++;
	}
	function rejects( callable $fn, string $message ): void {
		try { $fn(); } catch ( \Throwable $error ) {
			check( $error->getMessage() === $message, 'Expected ' . $message . ', got ' . $error->getMessage() );
			return;
		}
		throw new \RuntimeException( 'Expected refusal: ' . $message );
	}
	function sheet( array $options = [] ): string {
		$name = $options['name'] ?? 'Fixture Hero'; $server = $options['server'] ?? 'Test Realm';
		$totals = $options['totals'] ?? [ 'HP' => '1234', 'Mana' => '0', 'STR' => '101', 'Accuracy' => '400', 'Accuracy bonus' => '3' ];
		$pairs = '';
		foreach ( $totals as $label => $value ) { $pairs .= '<div><dt>' . $label . '</dt><dd>' . $value . '</dd></div>'; }
		$equipment = '';
		foreach ( Snapshot::SLOT_LABELS as $index => $label ) {
			if ( ( $options['omitSlot'] ?? -1 ) === $index ) { continue; }
			$card = '<span class="eql-character-empty">Empty</span>';
			if ( $index < ( $options['equipped'] ?? 2 ) ) {
				$tier = $options['tiers'][$index] ?? (string)$index;
				$tierHtml = $tier === 'missing' ? '' : '<span class="eql-character-tier">+' . $tier . '</span>';
				$card = '<div class="eql-character-item">' . $tierHtml . '<span class="eql-character-item-name"><a>Fixture Relic</a></span>' .
					'<div class="eql-character-inspect-v1"><span class="eql-character-tier">+10</span><div class="eql-character-augments"><span class="eql-character-tier">+9</span></div></div></div>';
			}
			$equipment .= '<div class="eql-character-equipment-row"><div class="eql-character-slot"><span class="eql-character-slot-label">' . $label . '</span>' . $card . '</div></div>';
		}
		$achievements = $options['achievementsHtml'] ?? ( array_key_exists( 'achievementSummary', $options ) ? achievementSummary( $options['achievementSummary'] ) : '' );
		return '<div class="eql-character-sheet eql-character-sheet-v1"><div class="eql-character-heading"><strong>' . $name . ' (' . $server . ')</strong>' .
			'<span class="eql-character-metadata">Level ' . ( $options['level'] ?? '50' ) . ' · ' . ( $options['classes'] ?? 'WAR / CLR' ) . ' · ' . ( $options['aa'] ?? '12' ) . ' AAs · Human</span></div>' .
			'<div class="eql-character-body"><div class="eql-character-layout"><div class="eql-character-totals"><dl>' . $pairs . ( $options['extraPairs'] ?? '' ) . '</dl></div>' .
			'<div class="eql-character-equipment">' . $equipment . '</div></div>' . $achievements . '<p class="eql-character-note">? Unknown · * Estimate. Updated: 2026-10-26T12:00:00Z</p>' .
			'<div class="eql-character-caption"><dl><div><dt>HP</dt><dd>999999</dd></div></dl><span class="eql-character-tier">+10</span><p class="eql-character-achievements-summary">999 / 999 achievements complete</p></div></div></div>';
	}
	function achievementSummary( string $summary ): string {
		return '<div class="eql-character-achievements-v1"><div class="eql-character-achievements-heading">Achievements</div>' .
			'<div class="eql-character-achievements-body"><p class="eql-character-achievements-summary">' . $summary . '</p></div></div>';
	}
	class WANObjectCache {
		public array $data = [];
		public function makeKey( ...$parts ) { return implode( ':', $parts ); }
		public function get( $key ) { return $this->data[$key] ?? false; }
		public function set( $key, $value, $ttl ) { check( $ttl === 30, 'Extraction cache has a short bounded TTL.' ); $this->data[$key] = $value; }
	}
	class FixtureTitle {
		public function __construct( public int $namespace, public string $title ) {}
		public function getNamespace() { return $this->namespace; }
		public function getDBkey() { return $this->title; }
		public function getPrefixedText() { return ( $this->namespace === 2 ? 'User:' : 'Template:' ) . str_replace( '_', ' ', $this->title ); }
		public function getLocalURL() { return '/wiki/' . rawurlencode( str_replace( ' ', '_', $this->getPrefixedText() ) ); }
	}
	class FixturePage implements \MediaWiki\Page\PageRecord {
		public function __construct( public int $id, public string $title, public int $namespace = 2, public int $latest = 1, public string $touched = '20261026120000', public bool $redirect = false ) {}
		public function getId() { return $this->id; } public function getDBkey() { return $this->title; }
		public function getNamespace() { return $this->namespace; } public function getLatest() { return $this->latest; }
		public function getTouched() { return $this->touched; } public function isRedirect() { return $this->redirect; }
	}
	class FixtureLookup implements \MediaWiki\Revision\RevisionLookup {
		public array $revisions = [];
		public function getRevisionById( $id, $flags, $page ) { check( $flags === 1, 'Current revision visibility is not a replica hint.' ); return $this->revisions[$id] ?? null; }
	}
	class FixtureConfig extends \MediaWiki\Config\Config {
		public int $maxLevel = 50;
		public function get( $key ) { check( in_array( $key, [ 'EQLClientDataPlayerMaxLevel', 'Server' ], true ), 'Use only public player/site configuration.' ); return $key === 'Server' ? 'https://eqlwiki.test' : $this->maxLevel; }
	}
	class FixtureDB {
		public array $ids = [ 10, 20, 30, 40, 50, 60 ];
		public int $max = 60;
		public function selectField( $table, $field, $conditions, $caller ) {
			check( $table === 'page' && $field === 'MAX(page_id)' && $conditions === [ 'page_namespace' => 2 ], 'Watermark is a public page-ID boundary, never an account-table scan.' ); return $this->max;
		}
		public function select( $tables, $fields, $where, $caller, $options, $joins ) {
			check( $tables === [ 'page', 'tl' => 'templatelinks', 'lt' => 'linktarget' ] && $where['page_namespace'] === 2 && $where['page_is_redirect'] === 0 && $where['lt.lt_namespace'] === 10 && $where['lt.lt_title'] === 'Character_sheet', 'Candidates come only from native canonical template transclusions on User pages.' );
			check( $options[0] === 'DISTINCT' && $options['ORDER BY'] === 'page_id ASC' && $options['LIMIT'] <= 26 && count( $joins ) === 2, 'Batches have stable keyset order and bounded native joins.' );
			$after = (int)substr( $where[0], strlen( 'page_id > ' ) ); $until = (int)substr( $where[1], strlen( 'page_id <= ' ) );
			return array_map( static fn ( $id ) => (object)[ 'page_id' => $id ], array_slice( array_values( array_filter( $this->ids, static fn ( $id ) => $id > $after && $id <= $until ) ), 0, $options['LIMIT'] ) );
		}
	}
	class FixtureProvider implements \Wikimedia\Rdbms\IConnectionProvider {
		public function __construct( public FixtureDB $db ) {}
		public function getPrimaryDatabase() { return $this->db; }
	}
	class FixtureOutput {
		public function __construct( public string $html, public array $dependencies ) {}
		public function getContentHolderText() { return $this->html; }
		public function getLinkList( $kind ) { check( $kind === \MediaWiki\Parser\ParserOutputLinkTypes::TEMPLATE, 'Use modern parser dependency metadata, not regex source.' ); return $this->dependencies; }
	}
	function fixtures(): array {
		$db = new FixtureDB(); $pages = new \MediaWiki\Page\PageStore(); $lookup = new FixtureLookup();
		$permissions = new \MediaWiki\Permissions\PermissionManager(); $parser = new \MediaWiki\Page\ParserOutputAccess(); $cache = new WANObjectCache(); $config = new FixtureConfig();
		$pages->pages[100] = new FixturePage( 100, 'Character_sheet', 10, 1000 ); $lookup->revisions[1000] = new RevisionRecord( 1000, 100 );
		foreach ( $db->ids as $id ) {
			$pages->pages[$id] = new FixturePage( $id, 'Fixture_' . $id, 2, $id + 100 ); $lookup->revisions[$id + 100] = new RevisionRecord( $id + 100, $id );
			$parser->outputs[$id] = new FixtureOutput( sheet(), [ [ 'link' => new FixtureTitle( 10, 'Character_sheet' ), 'pageid' => 100, 'revid' => 1000 ] ] );
		}
		$repository = new Repository( new FixtureProvider( $db ), $pages, $lookup, new \MediaWiki\Title\TitleFactory(), $permissions, new \MediaWiki\User\UserFactory(), $parser, $cache, $config );
		return [ $repository, $db, $pages, $lookup, $permissions, $parser, $cache, $config ];
	}

	$row = Snapshot::extract( sheet() )[0];
	check( $row['name'] === 'Fixture Hero' && $row['server'] === 'Test Realm' && $row['level'] === 50 && $row['classes'] === [ 'WAR', 'CLR' ] && $row['aa'] === 12, 'Canonical heading and metadata are independently parsed.' );
	$trimmedMetadata = Snapshot::extract( str_replace( '12 AAs · Human', '12 AAs· Human', sheet() ) )[0];
	check( $trimmedMetadata['aa'] === 12 && $trimmedMetadata['classes'] === [ 'WAR', 'CLR' ], 'Actual deployed parser-function whitespace around the optional race separator does not hide a canonical snapshot.' );
	check( $row['stats']['hp'] === 1234 && $row['stats']['mp'] === 0 && $row['stats']['atk_accuracy'] === 400 && $row['stats']['accuracy'] === 3, 'Zero and the two accuracy concepts remain distinct; caption decoys ignored.' );
	check( $row['totalTier'] === 1 && $row['equippedSlots'] === 2 && $row['tieredSlots'] === 2, 'Sum only direct equipped tiers, including explicit zero; exclude inspect duplicates and Exaltations.' );
	check( count( $row['stats'] ) === count( Snapshot::STAT_LABELS ) && $row['stats']['sv_fire'] === null && $row['updated'] === '2026-10-26T12:00:00Z', 'Absent values remain null and reported update text is retained.' );
	check( $row['achievementCount'] === null, 'Legacy sheets without the fixed summary have unknown achievements; caption lookalikes do not count.' );
	foreach ( [ '15 / 80 achievements complete' => 15, '0 / 3 achievements complete' => 0,
		'0 / 0 achievements complete' => 0, '1000000000 / 1000000000 achievements complete' => 1000000000 ] as $summary => $expected ) {
		check( Snapshot::extract( sheet( [ 'achievementSummary' => $summary ] ) )[0]['achievementCount'] === $expected, 'Only the bounded completed achievement count is public: ' . $summary );
	}
	foreach ( [ '? / 2 achievements complete', '-1 / 2 achievements complete', '1.5 / 2 achievements complete',
		'3 / 2 achievements complete', '1 / -2 achievements complete', '1 / 1.5 achievements complete',
		'1e3 / 2000 achievements complete', '1,000 / 2000 achievements complete', '01 / 2 achievements complete',
		'1 / 02 achievements complete', '1000000001 / 1000000001 achievements complete',
		'1 / 1000000001 achievements complete', '999999999999999999 / 999999999999999999 achievements complete',
		'1 / 2 achievements complete *', '1 / 2 objectives complete' ] as $summary ) {
		check( Snapshot::extract( sheet( [ 'achievementSummary' => $summary ] ) )[0]['achievementCount'] === null, 'Malformed, estimated, excessive or contradictory achievement summary stays unranked: ' . $summary );
	}
	$fixedAchievements = achievementSummary( '7 / 20 achievements complete' );
	$duplicateSummary = str_replace( '</p>', '</p><p class="eql-character-achievements-summary">8 / 20 achievements complete</p>', $fixedAchievements );
	check( Snapshot::extract( sheet( [ 'achievementsHtml' => $duplicateSummary ] ) )[0]['achievementCount'] === null, 'Duplicate fixed summaries cannot choose an arbitrary completion count.' );
	check( Snapshot::extract( sheet( [ 'achievementsHtml' => $fixedAchievements . $fixedAchievements ] ) )[0]['achievementCount'] === null, 'Multiple achievement sections remain unranked rather than selecting the first.' );
	$nestedSummary = str_replace( '<p class="eql-character-achievements-summary">', '<div><p class="eql-character-achievements-summary">', $fixedAchievements );
	$nestedSummary = str_replace( '</p>', '</p></div>', $nestedSummary );
	check( Snapshot::extract( sheet( [ 'achievementsHtml' => $nestedSummary ] ) )[0]['achievementCount'] === null, 'Nested objective or category summary lookalikes do not report the fixed sheet total.' );
	$independentAchievements = Snapshot::extract( sheet( [ 'achievementSummary' => '4 / 10 achievements complete' ] ) .
		sheet( [ 'server' => 'Other Realm', 'achievementSummary' => '0 / 12 achievements complete' ] ) );
	check( array_column( $independentAchievements, 'achievementCount' ) === [ 4, 0 ], 'Each character sheet supplies its own count without leaking a sibling summary.' );
	$achievementConflict = Snapshot::extract( sheet( [ 'achievementSummary' => '4 / 10 achievements complete' ] ) .
		sheet( [ 'achievementSummary' => '5 / 10 achievements complete' ] ) );
	check( count( $achievementConflict ) === 1 && $achievementConflict[0]['snapshotConflict'] && $achievementConflict[0]['achievementCount'] === null,
		'Conflicting repeated snapshots cannot manufacture an achievement rank.' );
	foreach ( [ '?', '12 *', 'NaN', 'Infinity', '1e4', '1,000', '4 apples', '999999999999999' ] as $invalid ) {
		check( Snapshot::extract( sheet( [ 'totals' => [ 'HP' => $invalid ] ] ) )[0]['stats']['hp'] === null, 'Unsupported/estimated total cannot rank: ' . $invalid );
	}
	check( Snapshot::extract( sheet( [ 'totals' => [ 'HP' => '-3', 'Primary DPS' => '12.25' ] ] ) )[0]['stats']['primary_dps'] === 12.25, 'Finite decimal observed values remain numeric.' );
	check( Snapshot::extract( sheet( [ 'extraPairs' => '<div><dt>HP</dt><dd>9999</dd></div>' ] ) )[0]['stats']['hp'] === null, 'Conflicting duplicate labels never choose an arbitrary winner.' );
	foreach ( [ 'missing', '11', '-1', '3 *', '?' ] as $tier ) {
		$unknown = Snapshot::extract( sheet( [ 'tiers' => [ 0 => $tier ] ] ) )[0];
		check( $unknown['totalTier'] === null && $unknown['equippedSlots'] === 2 && $unknown['tieredSlots'] === 1, 'Unknown/invalid equipped tier never silently becomes zero.' );
	}
	check( Snapshot::extract( sheet( [ 'equipped' => 0 ] ) )[0]['totalTier'] === 0, 'An explicitly empty complete equipment layout can report zero.' );
	check( Snapshot::extract( sheet( [ 'omitSlot' => 5 ] ) )[0]['totalTier'] === null, 'An incomplete equipment layout cannot report a complete tier sum.' );
	check( Snapshot::extract( sheet( [ 'level' => '51', 'aa' => '12 *', 'classes' => 'WAR / INVALID' ] ) )[0]['level'] === null, 'Unknown metadata cannot rank.' );
	check( Snapshot::extract( sheet( [ 'level' => '51' ] ), 60 )[0]['level'] === 51, 'Configured future level caps are supported without changing parser constants.' );
	check( Snapshot::extract( sheet( [ 'server' => '?' ] ) ) === [] && Snapshot::extract( sheet( [ 'name' => 'Unnamed character' ] ) ) === [], 'Missing public identity is not a player.' );
	check( count( Snapshot::extract( sheet() . sheet() ) ) === 1, 'Identical repeated snapshot is one player row.' );
	$conflict = Snapshot::extract( sheet() . sheet( [ 'totals' => [ 'HP' => '4444' ] ] ) );
	check( count( $conflict ) === 1 && $conflict[0]['snapshotConflict'] && $conflict[0]['totalTier'] === null && $conflict[0]['level'] === null && !array_filter( $conflict[0]['stats'], static fn ( $value ) => $value !== null ), 'Conflicting same-identity snapshots are linked but unranked, never guessed newer.' );
	check( count( Snapshot::extract( sheet() . sheet( [ 'server' => 'Other Realm' ] ) ) ) === 2, 'Distinct public server snapshots stay distinct.' );
	check( Snapshot::extract( str_replace( 'eql-character-sheet-v1', 'eql-character-sheet-v2', sheet() ) ) === [], 'Unknown future schema is not misread as schema 1.' );
	rejects( fn () => Snapshot::extract( str_repeat( 'x', Snapshot::MAX_HTML_BYTES + 1 ) ), 'player-list-output-limit' );
	rejects( fn () => Snapshot::extract( str_repeat( sheet(), Snapshot::MAX_SHEETS + 1 ) ), 'player-list-sheet-limit' );

	[ $repo, $db, $pages, $lookup, $permissions, $parser, $cache, $config ] = fixtures();
	$parser->outputs[10]->html = sheet( [ 'achievementSummary' => '6 / 10 achievements complete' ] );
	$legacyRow = Snapshot::extract( sheet() )[0];
	unset( $legacyRow['achievementCount'] );
	$legacyKey = $cache->makeKey( 'eqlplayers', 'schema1-v1', 10, 110, $pages->pages[10]->touched, 50 );
	$cache->data[$legacyKey] = [ 'rows' => [ $legacyRow ], 'dependencies' => [ [ 'id' => 100, 'revision' => 1000, 'namespace' => 10, 'title' => 'Character_sheet' ] ] ];
	$first = $repo->getBatch( null, 2 ); $calls = $parser->calls;
	check( $first['players'][0]['achievementCount'] === 6 && $first['players'][1]['achievementCount'] === null && $parser->calls === 2,
		'Cache schema change bypasses pre-feature rows and returns numeric/null achievement fields through unchanged keyset batches.' );
	check( count( $first['players'] ) === 2 && isset( $first['continue'] ) && $first['maxLevel'] === 50, 'First keyset batch has exactly its configured page budget.' );
	check( str_starts_with( $first['players'][0]['id'], '10:' ) && $first['players'][0]['pageTitle'] === 'User:Fixture 10' && str_starts_with( $first['players'][0]['pageUrl'], '/wiki/' ), 'Public rows link to the page and expose no account/private identifiers.' );
	$repo->getBatch( null, 2 ); check( $parser->calls === $calls, 'Verified current extracted rows reuse short-lived server cache.' );
	$second = $repo->getBatch( $first['continue'], 2 ); $third = $repo->getBatch( $second['continue'], 2 );
	check( count( $second['players'] ) === 2 && count( $third['players'] ) === 2 && !isset( $third['continue'] ), 'Continuation is exhaustive without repeated or skipped boundary pages.' );
	$db->max = 70; $db->ids[] = 70; check( !isset( $repo->getBatch( $second['continue'], 25 )['continue'] ), 'Opaque continuation fixes an upper page watermark; newly appended pages do not extend the traversal forever.' );
	foreach ( [ 'bad', '1|20|60', str_repeat( 'A', 101 ), rtrim( base64_encode( '1|60|20' ), '=' ), rtrim( base64_encode( '1|00|60' ), '=' ) ] as $token ) {
		rejects( fn () => $repo->getBatch( $token ), 'badcontinue' );
	}
	$lookup->revisions[110]->public = false;
	check( count( $repo->getBatch( null, 2 )['players'] ) === 1, 'Suppression after cache fill immediately removes the row, even for elevated request contexts.' );
	$lookup->revisions[110]->public = true; $permissions->denied[] = 'User:Fixture 10';
	check( count( $repo->getBatch( null, 2 )['players'] ) === 1, 'Latest anonymous read restrictions are rechecked before cached rows return.' );
	$permissions->denied = []; $pages->pages[10]->title = 'Fixture_10/Private_subpage';
	check( count( $repo->getBatch( null, 2 )['players'] ) === 1, 'User subpages are excluded even if transclusion index is stale.' );
	$pages->pages[10]->title = 'Fixture_10'; $pages->pages[10]->namespace = 3;
	check( count( $repo->getBatch( null, 2 )['players'] ) === 1, 'Moved User talk pages cannot leak through stale index candidates.' );
	$pages->pages[10]->namespace = 2; $pages->pages[10]->redirect = true;
	check( count( $repo->getBatch( null, 2 )['players'] ) === 1, 'Current redirects are excluded.' );
	$pages->pages[10]->redirect = false;
	$pages->pages[100]->latest = 1001; $lookup->revisions[1001] = new RevisionRecord( 1001, 100 );
	$parser->onParse = static function ( $page, $flags ) use ( $parser ) {
		if ( ( $flags[\MediaWiki\Page\ParserOutputAccess::OPT_NO_CHECK_CACHE] ?? false ) && ( $flags[\MediaWiki\Page\ParserOutputAccess::OPT_NO_UPDATE_CACHE] ?? false ) ) {
			$parser->outputs[$page->id]->dependencies[0]['revid'] = 1001;
			$parser->outputs[$page->id]->html = sheet( [ 'totals' => [ 'HP' => '4321' ] ] );
		}
	};
	check( $repo->getBatch( null, 1 )['players'][0]['stats']['hp'] === 4321 && in_array( [ 1 => true, 2 => true ], $parser->options, true ), 'Changed template dependencies invalidate extraction and force one fresh parse before ranking.' );
	$permissions->denied[] = 'Template:Character sheet';
	rejects( fn () => $repo->getBatch( null, 1 ), 'player-list-dependencies-unavailable' );
	$permissions->denied = []; $parser->onParse = null;
	[ $repo, $db, $pages, $lookup, $permissions, $parser ] = fixtures();
	$pages->pages[10]->title = 'Fixture_10/Subpage'; $permissions->denied[] = 'User:Fixture 20';
	$empty = $repo->getBatch( null, 2 );
	check( $empty['players'] === [] && isset( $empty['continue'] ) && count( $repo->getBatch( $empty['continue'], 2 )['players'] ) === 2, 'Filtered empty batches still advance continuation and cannot silently end the list.' );
	$main = new \MediaWiki\Api\ApiMain(); $api = new \EQLClientData\ApiPlayers( $main, 'eqlplayers', $repo );
	$api->params = [ 'limit' => 2 ]; $api->execute();
	check( !$api->mustBePosted() && !$api->isWriteMode() && !$api->isInternal() && isset( $main->data['eqlplayers']['continue'] ), 'Actual API class is read-only public GET and preserves continuation payload.' );
	$api->params = [ 'limit' => 2, 'continue' => $empty['continue'] ]; $api->execute();
	check( array_key_exists( 'achievementCount', $main->data['eqlplayers']['players'][0] ), 'The existing read-only API exposes the optional count without adding per-batch sorting or altering continuation.' );
	$api->params['continue'] = 'malformed'; rejects( fn () => $api->execute(), 'badcontinue' );
	$api->params = [ 'limit' => 25 ]; $parser->fail = true;
	$before = $main->data;
	rejects( fn () => $api->execute(), 'eqlplayers-unavailable' );
	check( $main->data === $before, 'A failed batch never returns an apparently successful partial leaderboard.' );
	[ $repo, $db, $pages, $lookup, $permissions, $parser ] = fixtures();
	$parser->onParse = static function ( $page ) use ( $pages, $lookup ) {
		$pages->pages[$page->id] = new FixturePage( $page->id, $page->title, 2, $page->latest + 1000 );
		$lookup->revisions[$page->latest + 1000] = new RevisionRecord( $page->latest + 1000, $page->id );
	};
	check( $repo->getBatch( null, 1 )['players'] === [], 'An edit during parsing cannot return an old snapshot as the current public revision.' );
	[ $repo, $db, $pages, $lookup, $permissions, $parser ] = fixtures();
	$repo->getBatch( null, 1 );
	$lookup->revisions[1000]->public = false;
	rejects( fn () => $repo->getBatch( null, 1 ), 'player-list-dependencies-unavailable' );
	[ $repo, $db, $pages, $lookup, $permissions, $parser ] = fixtures();
	$parser->outputs[10]->dependencies = [];
	check( $repo->getBatch( null, 1 )['players'] === [], 'A stale link-index candidate without current canonical template provenance cannot return handmade DOM lookalikes.' );

	// Lazy details use the same public boundary and exact identity, without a
	// directory traversal, account lookup, arbitrary source or whole User HTML.
	[ $repo, $db, $pages, $lookup, $permissions, $parser, $cache ] = fixtures();
	$publicSheet = sheet( [ 'totals' => [ 'HP' => '1234', 'Mana' => '0', 'STR' => '?', 'Fire resist' => '12 *' ], 'achievementSummary' => '4 / 20 achievements complete' ] );
	$publicSheet = str_replace( '<a>Fixture Relic</a>', '<a href="/Fixture_Relic" title="Fixture Relic">Fixture Relic</a>', $publicSheet );
	$publicSheet = str_replace( '<div class="eql-character-item">', '<div class="eql-character-item"><span class="eql-character-item-icon"><a href="/Fixture_Relic"><img src="/images/item_500.png" width="48" height="48" alt="Fixture Relic"></a></span>', $publicSheet );
	$publicSheet = str_replace( '<div class="eql-character-augments"><span class="eql-character-tier">+9</span></div>',
		'<div class="eql-character-item-stats">HP: 42<br>STR: ?</div><div class="eql-character-item-details">Focus Effect: <a href="/Canonical_Effect" title="Canonical Effect">Visible Effect</a> (Worn)</div>' .
		'<div class="eql-character-augments"><strong>Exaltations</strong><ul><li><strong><a href="/Fixture_Rune">Fixture Rune</a></strong><span class="eql-character-item-details">HP: 999<br>Focus Effect: <a href="/Canonical_Rune">Visible Rune</a> (Worn)</span><span class="eql-character-tier">+9</span></li></ul></div>', $publicSheet );
	$publicSheet = str_replace( '<div class="eql-character-layout">', '<div class="eql-character-layout"><div class="eql-character-screenshot eql-character-screenshot-v1" data-file-title="File:Fixture.png"><a href="/File:Fixture.png"><img src="/images/Fixture.png" width="200" height="300" alt="Fixture"></a></div>', $publicSheet );
	$parser->outputs[10]->html = '<p>UNRELATED_USER_BIOGRAPHY</p>' . $publicSheet . '<p>UNRELATED_PRIVATE_INVENTORY</p>';
	$summary = $repo->getBatch( null, 1 )['players'][0];
	check( preg_match( '/^[a-f0-9]{64}$/D', $summary['snapshotToken'] ) === 1 && $summary['pageRevision'] === 110 && !isset( $summary['equipment'] ), 'Summaries carry a bound token/revision without eagerly loading typed details.' );
	$db->ids = []; // Detail must not depend on the directory SQL query.
	$detail = $repo->getDetail( $summary['id'], $summary['snapshotToken'] );
	check( $detail['id'] === $summary['id'] && $detail['revision'] === 110 && $detail['snapshotToken'] === $summary['snapshotToken'] && count( $detail['equipment'] ) === 24, 'Direct detail binds one exact page+character and returns the 24 published slots.' );
	check( $detail['stats']['mp'] === 0 && $detail['stats']['str'] === null && $detail['stats']['sv_fire'] === null && array_column( $detail['totals'], 'text' ) === [ '1234', '0', '?', '12 *' ], 'Public zero, unknown and estimate text survives without deriving game values.' );
	$item = $detail['equipment'][0]['item'];
	check( $item['tier'] === 0 && $item['icon']['url'] === '/images/item_500.png' && $item['statLines'] === [ 'HP: 42', 'STR: ?' ] && $detail['equipment'][2]['item'] === null, 'Item-specific direct tier, icon and published lines stay distinct from nested tooltip/socket tiers.' );
	check( $item['details'][0][1]['href'] === '/Canonical_Effect' && $item['details'][0][1]['text'] === 'Visible Effect' && $item['augments'][0]['effects'][0][1]['href'] === '/Canonical_Rune', 'Typed rich text preserves visible aliases, canonical local effect links and Worn suffixes.' );
	check( $detail['screenshot']['fileTitle'] === 'File:Fixture.png' && $detail['screenshot']['url'] === '/images/Fixture.png' && $detail['achievementCount'] === 4, 'Only optional public photo info and the existing achievement count are included.' );
	$detailJson = json_encode( $detail );
	check( !str_contains( $detailJson, 'UNRELATED_' ) && !str_contains( $detailJson, '999' ) && !array_intersect( [ 'html', 'modules', 'config', 'achievements' ], array_keys( $detail ) ), 'No whole-page prose, socket numbers, HTML, executable modules/config or achievement objectives enter details.' );
	$calls = $parser->calls; $repo->getDetail( $summary['id'], $summary['snapshotToken'] );
	check( $parser->calls === $calls, 'Repeated detail reuses the short cache after current visibility/dependency validation.' );
	$permissions->denied[] = 'User:Fixture 10'; rejects( fn () => $repo->getDetail( $summary['id'], $summary['snapshotToken'] ), 'eqlplayers-detail-unavailable' ); $permissions->denied = [];
	$lookup->revisions[110]->public = false; rejects( fn () => $repo->getDetail( $summary['id'], $summary['snapshotToken'] ), 'eqlplayers-detail-unavailable' ); $lookup->revisions[110]->public = true;
	foreach ( [ [ 'namespace', 3 ], [ 'title', 'Fixture_10/Subpage' ], [ 'redirect', true ] ] as [ $field, $value ] ) {
		$old = $pages->pages[10]->$field; $pages->pages[10]->$field = $value; rejects( fn () => $repo->getDetail( $summary['id'], $summary['snapshotToken'] ), 'eqlplayers-detail-unavailable' ); $pages->pages[10]->$field = $old;
	}
	rejects( fn () => $repo->getDetail( '999:' . substr( $summary['id'], 3 ), $summary['snapshotToken'] ), 'eqlplayers-detail-unavailable' );
	rejects( fn () => $repo->getDetail( '10:' . str_repeat( 'a', 16 ), $summary['snapshotToken'] ), 'eqlplayers-detail-unavailable' );
	foreach ( [ [ 'User:Fixture 10', $summary['snapshotToken'] ], [ $summary['id'], 'bad' ], [ '0:' . str_repeat( 'a', 16 ), str_repeat( 'b', 64 ) ] ] as [ $id, $token ] ) { rejects( fn () => $repo->getDetail( $id, $token ), 'badvalue' ); }
	$pages->pages[10] = new FixturePage( 10, 'Fixture_10', 2, 111 ); $lookup->revisions[111] = new RevisionRecord( 111, 10 );
	rejects( fn () => $repo->getDetail( $summary['id'], $summary['snapshotToken'] ), 'eqlplayers-detail-stale' );

	[ $repo, $db, $pages, $lookup, $permissions, $parser ] = fixtures();
	$summary = $repo->getBatch( null, 1 )['players'][0];
	$pages->pages[100]->latest = 1001; $lookup->revisions[1001] = new RevisionRecord( 1001, 100 );
	$parser->outputs[10]->dependencies[0]['revid'] = 1001;
	rejects( fn () => $repo->getDetail( $summary['id'], $summary['snapshotToken'] ), 'eqlplayers-detail-stale' );
	$changed = $repo->getBatch( null, 1 )['players'][0];
	check( $changed['snapshotToken'] !== $summary['snapshotToken'] && $changed['stats'] === $summary['stats'], 'A template-only change invalidates the token even when the summary readings are unchanged.' );
	$permissions->denied[] = 'Template:Character sheet'; rejects( fn () => $repo->getDetail( $changed['id'], $changed['snapshotToken'] ), 'player-list-dependencies-unavailable' );

	foreach ( [ false, true ] as $identical ) {
		[ $repo, $db, $pages, $lookup, $permissions, $parser ] = fixtures();
		$second = $identical ? $publicSheet : str_replace( 'Fixture Relic', 'Other Relic', $publicSheet );
		$parser->outputs[10]->html = $publicSheet . $second;
		$summary = $repo->getBatch( null, 1 )['players'][0];
		check( !isset( $summary['snapshotConflict'] ), 'Equal scalar summaries can still have different equipped item names.' );
		if ( $identical ) { check( $repo->getDetail( $summary['id'], $summary['snapshotToken'] )['name'] === 'Fixture Hero', 'Identical repeated sheets collapse without choosing an arbitrary gear variant.' ); }
		else { rejects( fn () => $repo->getDetail( $summary['id'], $summary['snapshotToken'] ), 'eqlplayers-detail-conflict' ); }
	}
	[ $repo, $db, $pages, $lookup, $permissions, $parser ] = fixtures();
	$summary = $repo->getBatch( null, 1 )['players'][0]; $parser->fail = true;
	rejects( fn () => $repo->getDetail( $summary['id'], $summary['snapshotToken'] ), 'player-list-parser-unavailable' );
	$parser->fail = false; $parser->onParse = static function ( $page ) use ( $lookup ) { $lookup->revisions[$page->latest]->public = false; };
	rejects( fn () => $repo->getDetail( $summary['id'], $summary['snapshotToken'] ), 'eqlplayers-detail-unavailable' );

	$identity = substr( hash( 'sha256', "Fixture Hero\0Test Realm" ), 0, 16 );
	$external = str_replace( [ '/Canonical_Effect', '/Canonical_Rune', '/images/Fixture.png', '/images/item_500.png' ], [ 'javascript:alert(1)', '//evil.example/rune', 'https://evil.example/image.png', 'data:image/png;base64,evil' ], $publicSheet );
	$safe = \EQLClientData\CharacterSheetDetail::extract( $external, $identity, 50, 'https://eqlwiki.test' );
	check( $safe['screenshot'] === null && $safe['equipment'][0]['item']['icon'] === null && !str_contains( json_encode( $safe ), 'javascript:' ) && !str_contains( json_encode( $safe ), 'evil.example' ), 'Unsafe/external link and image targets are omitted without accepting arbitrary protocols or third-party content.' );
	rejects( fn () => \EQLClientData\CharacterSheetDetail::extract( str_replace( 'HP: 42', '<script>bad</script>', $publicSheet ), $identity, 50, 'https://eqlwiki.test' ), 'eqlplayers-detail-unavailable' );
	rejects( fn () => \EQLClientData\CharacterSheetDetail::extract( str_replace( 'HP: 42', str_repeat( 'x', 8193 ), $publicSheet ), $identity, 50, 'https://eqlwiki.test' ), 'eqlplayers-detail-unavailable' );

	[ $repo, $db, $pages, $lookup, $permissions, $parser ] = fixtures(); $summary = $repo->getBatch( null, 1 )['players'][0];
	$main = new \MediaWiki\Api\ApiMain(); $api = new \EQLClientData\ApiPlayers( $main, 'eqlplayers', $repo );
	$api->params = [ 'detail' => $summary['id'], 'snapshot' => $summary['snapshotToken'], 'limit' => 10 ]; $api->execute();
	check( isset( $main->data['eqlplayers']['detail']['equipment'] ) && !isset( $main->data['eqlplayers']['players'] ), 'Existing API dispatch returns one typed detail only, not a second directory load.' );
	$api->params['snapshot'] = str_repeat( 'f', 64 ); rejects( fn () => $api->execute(), 'eqlplayers-detail-stale' );
	foreach ( [ [ 'detail' => $summary['id'] ], [ 'snapshot' => $summary['snapshotToken'] ], [ 'detail' => $summary['id'], 'snapshot' => $summary['snapshotToken'], 'continue' => 'anything' ] ] as $params ) { $api->params = $params; rejects( fn () => $api->execute(), 'badvalue' ); }
	if ( ( $argv[1] ?? '' ) === '--rendered' && isset( $argv[2] ) ) {
		$actual = Snapshot::extract( file_get_contents( $argv[2] ) );
		check( count( $actual ) === 2 && $actual[0]['server'] !== $actual[1]['server'], 'Actual unsaved MediaWiki template output yields both independent synthetic sheets.' );
	}
	echo "Player List backend: $checks synthetic checks passed; no real database/page/account writes.\n";
}
