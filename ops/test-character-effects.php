<?php
/** Literal effect extraction and current anonymous API guards; synthetic data only. */
declare( strict_types = 1 );

namespace MediaWiki\Api {
	class ApiBase {
		public array $result = [];
		public string $cacheMode = '';
		public function __construct( private array $params ) {}
		protected function extractRequestParams(): array { return $this->params; }
		protected function dieWithError( $message, $code ): never { throw new \RuntimeException( $code ); }
		public function getMain(): self { return $this; }
		public function getResult(): self { return $this; }
		public function getModuleName(): string { return 'eqlcharactereffects'; }
		public function setCacheMode( string $mode ): void { $this->cacheMode = $mode; }
		public function addValue( $path, $name, $value ): void { $this->result[$name] = $value; }
	}
}
namespace MediaWiki\Content { class WikitextContent { public function __construct( private string $text ) {} public function getText(): string { return $this->text; } } }
namespace MediaWiki\Permissions { class PermissionManager { public const RIGOR_SECURE = 'secure'; } }
namespace MediaWiki\Revision { class RevisionRecord { public const DELETED_TEXT = 1, FOR_PUBLIC = 0; } class SlotRecord { public const MAIN = 'main'; } }
namespace Wikimedia\Rdbms { interface IDBAccessObject { public const READ_LATEST = 1; } }
namespace Wikimedia\ParamValidator { class ParamValidator { public const PARAM_TYPE = 'type', PARAM_REQUIRED = 'required'; } }
namespace MediaWiki {
	class MediaWikiServices {
		public static array $pages = [], $reads = [];
		public static function getInstance(): self { return new self; }
		public function getUserFactory(): object { return new class { public function newAnonymous(): object { return (object)[ 'anonymous' => true ]; } }; }
		public function getTitleFactory(): object { return new class {
			public function newFromText( string $name ): ?object {
				if ( $name === '[' ) { return null; }
				return new class( $name ) {
					public function __construct( public string $name ) {}
					public function getText(): string { return str_replace( '_', ' ', $this->name ); }
					public function getNamespace(): int { return str_contains( $this->name, ':' ) ? 2 : 0; }
					public function isExternal(): bool { return false; }
					public function getFragment(): string { return str_contains( $this->name, '#' ) ? 'fragment' : ''; }
				};
			}
		}; }
		public function getPermissionManager(): object { return new class {
			public function userCan( $right, $user, $title, $rigor ): bool {
				if ( !$user->anonymous || $rigor !== 'secure' ) { throw new \RuntimeException( 'Not a secure anonymous read.' ); }
				return $title->name !== 'Unreadable';
			}
		}; }
		public function getRevisionLookup(): object { return new class {
			public function getRevisionByTitle( $title, $id, $flags ): ?object {
				if ( $flags !== 1 || $id !== 0 ) { throw new \RuntimeException( 'Not a current revision read.' ); }
				MediaWikiServices::$reads[] = $title->name;
				if ( !isset( MediaWikiServices::$pages[$title->name] ) ) { return null; }
				return new class( $title->name, MediaWikiServices::$pages[$title->name] ) {
					public function __construct( private string $name, private string $source ) {}
					public function audienceCan( $flag, $audience ): bool { return $flag === 1 && $audience === 0 && $this->name !== 'Suppressed'; }
					public function getContent( $slot, $audience ): object {
						if ( $this->name === 'Suppressed' || $audience !== 0 ) { throw new \RuntimeException( 'Hidden content accessed.' ); }
						return $this->name === 'Nonwikitext' ? new \stdClass : new \MediaWiki\Content\WikitextContent( $this->source );
					}
					public function getId(): int { return 123; }
				};
			}
		}; }
	}
}
namespace {
	define( 'NS_MAIN', 0 );
	require_once __DIR__ . '/../extensions/DynamicZoneList/NpcLootWikitext.php';
	require_once __DIR__ . '/../extensions/EQLClientData/includes/ApiCharacterEffects.php';
	$checks = 0;
	$check = static function ( bool $value, string $message ) use ( &$checks ): void {
		if ( !$value ) { throw new \RuntimeException( $message ); } $checks++;
	};
	$source = "{{Itempage\n|itemname=Synthetic Earring\n|statsblock=AC: 2<br>HP: 12<br>Effect: [[See Invisible]] (Worn)<br>\n|focus_effect=[[Affliction Efficiency I]]\n|notes=Unrelated\n}}";
	$effects = EqlNpcLootWikitext::itemEffects( $source );
	$check( $effects === [ [ 'label' => 'Effect', 'value' => 'See Invisible (Worn)', 'linkTitle' => 'See Invisible' ],
		[ 'label' => 'Focus effect', 'value' => 'Affliction Efficiency I', 'linkTitle' => 'Affliction Efficiency I' ] ], 'Both authored effect locations and worn suffix survive.' );
	$check( !preg_match( '/AC:|HP:|Unrelated/', json_encode( $effects ) ), 'Effect-only API never returns numeric stats or prose.' );
	$check( EqlNpcLootWikitext::itemEffects( '<nowiki>' . $source . '</nowiki>' ) === [], 'Quoted examples do not establish effects.' );
	$check( EqlNpcLootWikitext::itemEffects( $source . $source ) === [], 'Variant/multiple item cards do not mix effects.' );
	$check( EqlNpcLootWikitext::itemEffects( str_replace( '|focus_effect=', '|focus_effect=Other\n|focus_effect=', $source ) ) === [], 'Duplicate fields fail closed.' );
	foreach ( [ '{{Unsafe}}', '<script>unsafe</script>', '[https://other.example/unsafe link]', '?', 'None', '[[Template:Private]]' ] as $invalid ) {
		$rows = EqlNpcLootWikitext::itemEffects( "{{Itempage\n|statsblock=AC: 2\n|focus_effect=$invalid\n}}" );
		$check( !$rows || !isset( $rows[0]['linkTitle'] ) && !preg_match( '/[{}<>]/', $rows[0]['value'] ), 'Dynamic/untrusted effect markup cannot become a raw or privileged link.' );
	}
	$aliased = EqlNpcLootWikitext::itemEffects( "{{Itempage|statsblock=HP: 1|focus_effect=[[Canonical Focus|Visible focus]]}}" );
	$check( $aliased[0]['value'] === 'Visible focus' && $aliased[0]['linkTitle'] === 'Canonical Focus', 'Link alias retains its canonical target.' );
	\MediaWiki\MediaWikiServices::$pages = array_fill_keys( [ 'Synthetic Earring', 'Unreadable', 'Suppressed', 'Nonwikitext' ], $source );
	$api = new \EQLClientData\ApiCharacterEffects( [ 'titles' => 'Synthetic Earring|Synthetic Earring|Unreadable|Suppressed|Nonwikitext|Missing|User:Private|Synthetic#part|[' ] );
	$api->execute();
	$check( count( $api->result['eqlcharactereffects']['items'] ) === 1, 'Only current public readable main-namespace wikitext is returned.' );
	$check( $api->result['eqlcharactereffects']['items'][0]['effects'] === $effects, 'API uses the same typed effect extraction as the producer.' );
	$check( $api->cacheMode === 'private' && !in_array( 'Unreadable', \MediaWiki\MediaWikiServices::$reads, true ), 'Private HTTP caching and anonymous permission checks precede any revision read.' );
	$check( $api->mustBePosted() && $api->getAllowedParams()['titles']['required'], 'Browser enrichment is one bounded explicit batch.' );
	try { ( new \EQLClientData\ApiCharacterEffects( [ 'titles' => implode( '|', range( 1, 51 ) ) ] ) )->execute(); throw new \LogicException( 'Unbounded request accepted.' ); }
	catch ( \RuntimeException $error ) { $check( $error->getMessage() === 'toomanyvalues', 'Oversized requests fail before any reads.' ); }
	echo "Character effect extraction/API: $checks checks passed; no database or page writes.\n";
}
