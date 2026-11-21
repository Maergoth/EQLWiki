<?php
/**
 * Source-only navigation checks against the real native toolbox builder.
 * No settings, database, account, page or network writes.
 * Run: php ops/test-navigation-crawl.php
 */
namespace {
	if ( PHP_SAPI !== 'cli' ) {
		exit( "Run this fixture from the command line.\n" );
	}
	$root = dirname( __DIR__ );
	require_once $root . '/includes/AutoLoader.php';
	require_once $root . '/includes/Defines.php';
}

namespace MediaWiki\Skins\Vector {
	// Reuse the actual core SkinTemplate/toolbox. Vector is not bootstrapped.
	class SkinVector22 extends \MediaWiki\Skin\SkinTemplate {
	}
}

namespace {
	use EQLImmersive\NavigationCrawl;
	use MediaWiki\Skin\SkinTemplate;
	use MediaWiki\Title\Title;

	require_once $root . '/skins/EQLImmersive/includes/NavigationCrawl.php';
	require_once $root . '/skins/EQLImmersive/includes/Hooks.php';
	require_once $root . '/skins/EQLImmersive/includes/SkinEQLImmersive.php';

	$checks = 0;
	function eqlCrawlCheck( bool $condition, string $message ): void {
		global $checks;
		$checks++;
		if ( !$condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$utilityUrls = [
		'/index.php?title=Synthetic&action=edit',
		'/Synthetic?action=history',
		'/Synthetic?action=INFO',
		'/Synthetic?oldid=42',
		'/Synthetic?diff=prev&oldid=42',
		'/Synthetic?printable=yes',
		'/Synthetic?veaction=edit',
		'/Synthetic?veaction=editsource',
		'/Synthetic?action=watch',
		'/Synthetic?action=unwatch',
		'/Synthetic?action=protect',
		'/Synthetic?action=delete',
		'/Special:CreateAccount?returnto=Synthetic&returntoquery=oldid%3D42',
		'/Special:UserLogin',
		'/Special:UserLogout',
		'/Special:WhatLinksHere/Synthetic?limit=500',
		'/Special:RecentChangesLinked/Synthetic',
		'/Special:Upload',
		'/Special:MovePage/Synthetic',
		'/Special:ConfirmEmail',
		'/Special:Log?page=Synthetic',
		'/index.php?title=Special%3ACreateAccount&returnto=Synthetic',
		'/index.php?title=Special%3AWhatLinksHere%2FSynthetic',
		'/Special%3AWhatLinksHere/Synthetic',
		'/wiki/Special:WhatLinksHere/Synthetic',
		'?title=Synthetic&action=history',
	];
	foreach ( $utilityUrls as $url ) {
		$item = [ 'href' => $url, 'text' => 'Utility', 'id' => 'synthetic', 'accesskey' => 'h' ];
		$actual = NavigationCrawl::decorate( $item );
		eqlCrawlCheck( $actual === $item + [ 'rel' => 'nofollow' ], 'Qualify local utility: ' . $url );
		eqlCrawlCheck( NavigationCrawl::decorate( $actual ) === $actual, 'Decoration is idempotent: ' . $url );
	}
	$contentUrls = [
		'/Bone_Bladed_Claymore', '/Befallen#Items', '/Talk:Bone_Bladed_Claymore',
		'/Category:Items?pagefrom=Bone+Bladed+Claymore', '/Category:Zones',
		'/Special:AllPages', '/Special:ClassSlotEquip/Warrior/Two-Handed',
		'/index.php?title=Bone_Bladed_Claymore', '/Synthetic?action=view',
		'/Synthetic?returntoquery=action%3Dedit%26oldid%3D42',
		'/Guide/Special:UserLogin', '/index.php?title=Guide%2FSpecial%3ALog',
		'https://eqlwiki.com/Synthetic?action=edit',
		'https://test.eqlwiki.com/Synthetic?action=edit',
		'https://external.invalid/Synthetic?action=edit',
		'//external.invalid/Special:UserLogin', '#History',
		'/Synthetic?action%5B%5D=edit', '/index.php?title%5B%5D=Special:UserLogin',
	];
	foreach ( $contentUrls as $url ) {
		$item = [ 'href' => $url, 'text' => 'Read', 'rel' => 'discussion' ];
		eqlCrawlCheck( NavigationCrawl::decorate( $item ) === $item, 'Keep content/unclassified destination unchanged: ' . $url );
	}
	$existing = [ 'href' => '/Synthetic?action=edit', 'rel' => 'noopener noreferrer' ];
	eqlCrawlCheck( NavigationCrawl::decorate( $existing )['rel'] === 'noopener noreferrer nofollow',
		'Existing relation tokens survive' );
	$existing['rel'] = 'alternate NOFOLLOW';
	eqlCrawlCheck( NavigationCrawl::decorate( $existing ) === $existing, 'Existing nofollow is not duplicated' );
	eqlCrawlCheck( NavigationCrawl::decorate( [ 'html' => '<span>Keep</span>' ] ) === [ 'html' => '<span>Keep</span>' ],
		'Nonlink menu metadata remains unchanged' );

	class EqlCrawlFixtureTitle extends Title {
		public function __construct( private bool $special = false ) {
		}
		public function isSpecialPage(): bool {
			return $this->special;
		}
		public function getLocalURL( $query = '' ): string {
			return '/Synthetic' . ( $query ? '?' . http_build_query( $query ) : '' );
		}
	}
	class EqlCrawlFixtureSkin extends SkinTemplate {
		public function __construct( private bool $special = false, private bool $missing = false ) {
		}
		public function getRelevantTitle(): ?Title {
			return $this->missing ? null : new EqlCrawlFixtureTitle( $this->special );
		}
		public function editUrlOptions(): array {
			return [ 'action' => 'edit' ];
		}
	}
	$hooks = new EQLImmersive\Hooks();
	$links = [
		'views' => [
			'view' => [ 'href' => '/Synthetic', 'id' => 'ca-view' ],
			'talk' => [ 'href' => '/Talk:Synthetic', 'rel' => 'discussion' ],
			'edit' => [ 'href' => '/Synthetic?veaction=edit', 'class' => 'selected', 'accesskey' => 'e' ],
			'history' => [ 'href' => '/Synthetic?action=history' ],
		],
		'user-menu' => [ 'createaccount' => [ 'href' => '/Special:CreateAccount?returnto=Synthetic' ] ],
		'category-normal' => [ 'items' => [ 'href' => '/Category:Items' ] ],
	];
	$hooks->finalizeNavigation( new EqlCrawlFixtureSkin(), $links );
	eqlCrawlCheck( !isset( $links['views']['view']['rel'] ) && $links['views']['talk']['rel'] === 'discussion',
		'Read and Talk remain followable through final navigation processing' );
	eqlCrawlCheck( $links['views']['edit']['rel'] === 'nofollow' && $links['views']['history']['rel'] === 'nofollow',
		'Actual final navigation qualifies source editing and history' );
	eqlCrawlCheck( $links['views-overflow']['more-edit']['rel'] === 'nofollow' &&
		$links['views-overflow']['more-history']['rel'] === 'nofollow', 'Overflow copies retain utility relations' );
	eqlCrawlCheck( $links['user-menu']['createaccount'] === [ 'href' => '/Special:CreateAccount?returnto=Synthetic' ] &&
		!isset( $links['category-normal']['items']['rel'] ), 'Personal account components and category links remain untouched' );
	eqlCrawlCheck( $links['views']['edit']['accesskey'] === 'e' &&
		str_contains( $links['views']['edit']['class'], 'selected' ), 'Existing action metadata survives' );
	$once = $links;
	$hooks->finalizeNavigation( new EqlCrawlFixtureSkin(), $links );
	eqlCrawlCheck( $links === $once, 'Final navigation is idempotent' );
	foreach ( [ new EqlCrawlFixtureSkin( true ), new EqlCrawlFixtureSkin( false, true ) ] as $specialSkin ) {
		$specialLinks = [
			'views' => [ 'edit' => [ 'href' => '/Synthetic?veaction=edit', 'text' => 'Extension editor' ] ],
			'actions' => [ 'info' => [ 'href' => '/Synthetic?action=info' ] ],
			'user-menu' => [ 'login' => [ 'href' => '/Special:UserLogin', 'active' => true ] ],
		];
		$hooks->finalizeNavigation( $specialSkin, $specialLinks );
		eqlCrawlCheck( $specialLinks['views']['edit']['rel'] === 'nofollow' &&
			$specialLinks['actions']['info']['rel'] === 'nofollow', 'Special/missing titles still qualify utility actions' );
		eqlCrawlCheck( $specialLinks['views']['edit']['href'] === '/Synthetic?veaction=edit' &&
			$specialLinks['views']['edit']['text'] === 'Extension editor' && !isset( $specialLinks['views-overflow'] ),
			'Special/missing titles retain extension-defined editor behavior and group structure' );
		eqlCrawlCheck( $specialLinks['user-menu'] === [ 'login' => [ 'href' => '/Special:UserLogin', 'active' => true ] ],
			'Account component and auth-state metadata remain unchanged on utility pages' );
	}
	$personalInput = [ 'createaccount' => [ 'href' => '/Special:CreateAccount?returnto=Synthetic',
		'text' => 'Create account', 'class' => 'account-class', 'active' => true, 'data' => [ 'synthetic' => 'kept' ] ] ];
	$personalGroups = [ 'user-menu' => $personalInput, 'user-menu-logout' => $personalInput,
		'notifications' => $personalInput, 'user-page' => $personalInput ];
	$personalBefore = $personalGroups;
	NavigationCrawl::applyToGroups( $personalGroups );
	eqlCrawlCheck( $personalGroups === $personalBefore, 'Account, logout, notification and user-page groups stay native' );
	$converterSkin = new EqlCrawlFixtureSkin();
	eqlCrawlCheck( $converterSkin->getPersonalToolsForMakeListItem( $personalGroups['user-menu'], true ) ===
		$converterSkin->getPersonalToolsForMakeListItem( $personalInput, true ),
		'The actual final personal-tools converter receives unchanged account metadata' );
	// Qualify page-action data, then run the real native link component. This
	// catches relation loss between the hook array and rendered anchor attributes.
	$localizer = new class implements \MessageLocalizer {
		public function msg( $key, ...$params ): \MediaWiki\Message\Message {
			throw new \RuntimeException( 'Explicit fixture labels must not need localization.' );
		}
	};
	$renderedAction = $links['views']['history'] + [ 'text' => 'History' ];
	$component = new \MediaWiki\Skin\SkinComponentLink( 'history', $renderedAction, $localizer );
	$rendered = $component->getTemplateData();
	eqlCrawlCheck( str_contains( $rendered['html'], 'rel="nofollow"' ) &&
		str_contains( $rendered['html'], 'href="/Synthetic?action=history"' ),
		'Actual native anchor HTML retains the action relation and destination' );
	eqlCrawlCheck( in_array( [ 'key' => 'rel', 'value' => 'nofollow' ], $rendered['array-attributes'], true ),
		'Mustache link attributes also retain the action relation' );

	$sidebar = [];
	$addSidebarItem = new \ReflectionMethod( $hooks, 'addSidebarItem' );
	$addSidebarItem->invokeArgs( $hooks, [ &$sidebar, 'n-related', 'Related changes', '/Special:RecentChangesLinked/Synthetic' ] );
	$addSidebarItem->invokeArgs( $hooks, [ &$sidebar, 'n-read', 'Read', '/Synthetic' ] );
	eqlCrawlCheck( $sidebar[0]['rel'] === 'nofollow' && !isset( $sidebar[1]['rel'] ),
		'Actual custom sidebar restores native Related changes policy and retains Read' );

	$toolSkin = ( new \ReflectionClass( EQLImmersive\SkinEQLImmersive::class ) )->newInstanceWithoutConstructor();
	$nav = [
		'whatlinkshere' => [ 'href' => '/Special:WhatLinksHere/Synthetic', 'text' => 'What links here' ],
		'recentchangeslinked' => [ 'href' => '/Special:RecentChangesLinked/Synthetic', 'text' => 'Related changes' ],
		'permalink' => [ 'href' => '/Synthetic?oldid=42', 'text' => 'Permanent link' ],
		'info' => [ 'href' => '/Synthetic?action=info', 'text' => 'Page information', 'rel' => 'noopener' ],
		'print' => [ 'href' => '/Synthetic?printable=yes' ],
	];
	$nativeSkin = ( new \ReflectionClass( EqlCrawlFixtureSkin::class ) )->newInstanceWithoutConstructor();
	$native = $nativeSkin->makeToolbox( $nav, [] );
	$toolbox = $toolSkin->makeToolbox( $nav, [] );
	eqlCrawlCheck( $native['recentchangeslinked']['rel'] === 'nofollow', 'Native core Related changes already declares nofollow' );
	foreach ( [ 'whatlinkshere', 'recentchangeslinked', 'permalink', 'info', 'print' ] as $key ) {
		eqlCrawlCheck( str_contains( $toolbox[$key]['rel'], 'nofollow' ), 'Custom skin qualifies native toolbox: ' . $key );
		$before = $native[$key];
		$after = $toolbox[$key];
		unset( $before['rel'], $after['rel'] );
		eqlCrawlCheck( $before === $after, 'Toolbox metadata and URL are retained: ' . $key );
	}
	eqlCrawlCheck( $toolbox['info']['rel'] === 'noopener nofollow' &&
		$toolbox['print']['rel'] === 'alternate nofollow', 'Native toolbox relation tokens are merged' );
	echo "Passed $checks source-only navigation crawl checks.\n";
}
