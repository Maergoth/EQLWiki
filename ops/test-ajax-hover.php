<?php
/**
 * Execute the legacy hover special page with MW 1.45's namespaced Title
 * contract. Synthetic content/parser stubs; no settings, database or HTTP calls.
 */
namespace MediaWiki\Title {
	class Title {
		public static int $calls = 0;
		public function __construct( public string $text ) {}
		public static function newFromText( string $text ): ?self {
			self::$calls++;
			return str_contains( $text, '<' ) ? null : new self( $text );
		}
	}
}

namespace MediaWiki {
	class MediaWikiServices {
		public static array $pages = [];
		public static ?\MediaWiki\Title\Title $lastTitle = null;
		public static function getInstance(): self { return new self(); }
		public function getWikiPageFactory(): self { return $this; }
		public function newFromTitle( \MediaWiki\Title\Title $title ): object {
			self::$lastTitle = $title;
			return new class( self::$pages[$title->text] ?? null ) {
				public function __construct( private ?string $text ) {}
				public function getContent(): ?object {
					return $this->text === null ? null : new class( $this->text ) {
						public function __construct( private string $text ) {}
						public function getText(): string { return $this->text; }
					};
				}
			};
		}
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' ) {
		exit( "Run this fixture from the command line.\n" );
	}

	class SpecialPage {
		public static string $type = 'item';
		public function __construct( string $name ) {}
		public function getRequest(): object {
			return new class {
				public function getText( string $name, string $default ): string {
					return $name === 'type' ? SpecialPage::$type : $default;
				}
			};
		}
	}
	$wgOut = new class {
		public int $disabled = 0;
		public function disable(): void { $this->disabled++; }
		public function parseAsContent( string $text ): string {
			return '<div class="fixture-hover">' . htmlspecialchars( $text, ENT_QUOTES ) . '</div>';
		}
	};

	require __DIR__ . '/../extensions/AjaxHoverHelper/AjaxHoverHelper_body.php';
	$handler = new AjaxHoverHelper();
	$checks = 0;
	function checkHover( bool $condition, string $message ): void {
		global $checks;
		$checks++;
		if ( !$condition ) {
			throw new RuntimeException( $message );
		}
	}
	function hoverResponse( string $page, string $type = 'item' ): string {
		global $handler;
		SpecialPage::$type = $type;
		ob_start();
		try {
			$handler->execute( $page );
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	checkHover( !class_exists( 'Title', false ), 'The fixture must not supply the removed global Title alias.' );
	checkHover( hoverResponse( '' ) === '<p>AjaxHoverHelper Error 1.</p>', 'Keep the empty-title response.' );
	checkHover( \MediaWiki\Title\Title::$calls === 0, 'An empty request must stop before title resolution.' );
	checkHover( hoverResponse( '<invalid>' ) === '<p>AjaxHoverHelper Error 1.</p>', 'Invalid titles must return existing error HTML, without a fatal exception.' );
	checkHover( hoverResponse( 'Missing_Synthetic_Page' ) === '<p>AjaxHoverHelper Error 2.</p>', 'Missing content must resolve the namespaced title and return existing error HTML.' );
	checkHover( \MediaWiki\MediaWikiServices::$lastTitle?->text === 'Missing Synthetic Page', 'Keep underscore normalization when passing Title to the page factory.' );

	\MediaWiki\MediaWikiServices::$pages['Synthetic Claymore'] = '{{Itempage|itemname=Synthetic Claymore|lucy_img_ID=603|statsblock=Slot: PRIMARY<br>DMG: 17|focus_effect=Synthetic Focus}}';
	$item = html_entity_decode( hoverResponse( 'Synthetic_Claymore' ), ENT_QUOTES );
	checkHover( str_contains( $item, '{{Itembox' ) && str_contains( $item, '|itemname    = Synthetic Claymore' ) && str_contains( $item, 'DMG: 17' ) && str_contains( $item, '|focus_effect = Synthetic Focus' ), 'Execute a real item-hover request through title/content lookup and Itembox parsing.' );
	\MediaWiki\MediaWikiServices::$pages['Synthetic Spell'] = '{{Spellpage|spellname=Synthetic Spell|mana=10|description=Synthetic effect}}';
	$spell = html_entity_decode( hoverResponse( 'Synthetic_Spell', 'spell' ), ENT_QUOTES );
	checkHover( str_contains( $spell, '{{Spellbox' ) && str_contains( $spell, '| spellname = Synthetic Spell' ) && str_contains( $spell, '| mana = 10' ), 'The same namespaced title lookup must work for spell-hover execution.' );
	checkHover( $wgOut->disabled === 5, 'Keep bare tooltip responses with normal page output disabled.' );

	echo "Passed $checks synthetic hover-execution checks; no live parsing or site writes.\n";
}
