<?php

namespace EQLImmersive;

use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\SkinBuildSidebarHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use SkinTemplate;

class Hooks implements
	BeforePageDisplayHook,
	SkinTemplateNavigation__UniversalHook,
	SkinBuildSidebarHook
{

	private function isEQLImmersiveSkinName( string $skinName ): bool {
		return strtolower( $skinName ) === 'eqlimmersive';
	}

	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$this->isEQLImmersiveSkinName( $skin->getSkinName() ) ) {
			return;
		}

		$viewport = 'width=device-width, initial-scale=1, viewport-fit=cover';

		if ( method_exists( $out, 'addHeadItem' ) ) {
			$out->addHeadItem(
				'viewport',
				'<meta name="viewport" content="' . htmlspecialchars( $viewport, ENT_QUOTES ) . '">'
			);
		} else {
			$out->addMeta( 'viewport', $viewport );
		}

		$this->addConditionalResourceModules( $out, $skin );
	}

	/**
	 * Keep the stable shell global while loading clearly page-specific bundles
	 * only when their markup or special page is present.
	 */
	private function addConditionalResourceModules( $out, $skin ): void {
		$title = $skin->getTitle();

		if ( !$title ) {
			return;
		}

		$html = $out->getHTML();

		if ( $title->isMainPage() ) {
			$out->addModuleStyles( 'skins.EQLImmersive.homepage.styles' );
		}

		if ( $title->isSpecialPage() ) {
			$out->addModuleStyles( 'skins.EQLImmersive.special.styles' );

			if ( $title->isSpecial( 'Search' ) ) {
				$out->addModuleStyles( 'skins.EQLImmersive.search.styles' );
			}

			if (
				$title->isSpecial( 'RecentChanges' ) ||
				$title->isSpecial( 'Watchlist' )
			) {
				$out->addModuleStyles( 'skins.EQLImmersive.recentChanges.styles' );
			}
		}

		if (
			$title->getNamespace() === NS_MAIN &&
			$title->getDBkey() === 'Class_Guides'
		) {
			$out->addModules( 'skins.EQLImmersive.classGuide' );
		}

		if ( str_contains( $html, 'eql-mobpage' ) ) {
			$out->addModuleStyles( 'skins.EQLImmersive.mob.styles' );
		}

		if ( str_contains( $html, 'eql-factionpage' ) ) {
			$out->addModuleStyles( 'skins.EQLImmersive.faction.styles' );
		}

		$containsSpellUi =
			str_contains( $html, 'eql-spellpage' ) ||
			str_contains( $html, 'eql-spell-lazy' ) ||
			str_contains( $html, 'eql-spellpage-items' );

		/*
		 * Some spell-related tools intentionally operate outside a full
		 * Spellpage: item-effect links, merchant spell lists, and ordinary
		 * spell tables. Keep those features intact while avoiding a global
		 * load on pages with no relevant markup.
		 */
		$containsSpellRelatedUi =
			$containsSpellUi ||
			str_contains( $html, 'itemeff' ) ||
			str_contains( $html, 'merchant-page-items-sold' ) ||
			str_contains( $html, 'spell-hbdiv' );

		if ( $containsSpellRelatedUi ) {
			$out->addModuleStyles( 'skins.EQLImmersive.spell.styles' );
			$out->addModules( 'skins.EQLImmersive.spellTools' );
		}
	}

	public function onSkinTemplateNavigation__Universal(
		$sktemplate,
		&$links
	): void {
		if ( !$this->isEQLImmersiveSkinName( $sktemplate->getSkinName() ) ) {
			return;
		}

		$this->addEmailVerificationLink( $sktemplate, $links );
		$this->addHeaderWatchLink( $sktemplate, $links );
		$this->createViewsOverflow( $links );
	}

	private function addEmailVerificationLink(
		SkinTemplate $sktemplate,
		array &$links
	): void {
		$title = $sktemplate->getTitle();

		if ( !$title || $title->isSpecialPage() ) {
			return;
		}

		$user = $sktemplate->getUser();

		if (
			!$user ||
			!$user->isNamed() ||
			$user->isEmailConfirmed()
		) {
			return;
		}

		if ( !isset( $links['views'] ) || !is_array( $links['views'] ) ) {
			$links['views'] = [];
		}

		$verificationLink = [
			'id' => 'ca-eql-verify-email',
			'text' => 'Verify2Edit',
			'href' => SpecialPage::getTitleFor( 'Confirmemail' )->getLocalURL(),
			'class' => 'vector-tab-noicon eql-verify-email-action'
		];

		$editKeys = [
			'edit' => true,
			've-edit' => true,
			'viewsource' => true
		];
		$editIds = [
			'ca-edit' => true,
			'ca-ve-edit' => true,
			'ca-viewsource' => true
		];
		$updatedViews = [];
		$verificationLinkInserted = false;

		foreach ( $links['views'] as $key => $item ) {
			$itemId = is_array( $item )
				? (string)( $item['id'] ?? '' )
				: '';
			$isEditAction = isset( $editKeys[$key] ) || isset( $editIds[$itemId] );

			if ( $isEditAction ) {
				if ( !$verificationLinkInserted ) {
					$updatedViews['eql-verify-email'] = $verificationLink;
					$verificationLinkInserted = true;
				}

				continue;
			}

			$updatedViews[$key] = $item;

			/*
			 * MediaWiki may omit every edit action when email confirmation
			 * is required. In that case, put this directly after Read,
			 * where the normal edit actions would have appeared.
			 */
			if ( !$verificationLinkInserted && $key === 'view' ) {
				$updatedViews['eql-verify-email'] = $verificationLink;
				$verificationLinkInserted = true;
			}
		}

		if ( !$verificationLinkInserted ) {
			$updatedViews = [
				'eql-verify-email' => $verificationLink
			] + $updatedViews;
		}

		$links['views'] = $updatedViews;
	}

	private function addHeaderWatchLink(
		SkinTemplate $sktemplate,
		array &$links
	): void {
		$title = $sktemplate->getTitle();

		if ( !$title || $title->isSpecialPage() ) {
			return;
		}

		$user = $sktemplate->getUser();

		if ( !$user || !$user->isRegistered() ) {
			return;
		}

		$services = MediaWikiServices::getInstance();
		$isWatched = false;

		try {
			$isWatched = $services
				->getWatchedItemStore()
				->isWatched( $user, $title );
		} catch ( \Throwable $e ) {
			$isWatched = false;
		}

		if ( !isset( $links['views'] ) || !is_array( $links['views'] ) ) {
			$links['views'] = [];
		}

		$links['views']['eql-watch'] = [
			'id' => 'ca-eql-watch',
			'text' => $isWatched ? 'Unwatch' : 'Watch',
			'href' => $title->getLocalURL( $isWatched ? 'action=unwatch' : 'action=watch' ),
			'class' => trim(
				'mw-watchlink vector-tab-noicon eql-header-watch-action ' .
				( $isWatched ? 'eql-header-watch-action-watched' : 'eql-header-watch-action-unwatched' )
			)
		];
	}

	private function createViewsOverflow(
		array &$links
	): void {
		$clonedViews = [];

		foreach ( $links['views'] ?? [] as $key => $item ) {
			$newItem = $item;
			$newItem['class'] = trim( ( $newItem['class'] ?? '' ) . ' vector-tab-noicon' );
			$newItem['is-collapsible'] = true;
			$clonedViews['more-' . $key] = $newItem;
		}

		$links['views-overflow'] = $clonedViews;
	}

	public function onSkinBuildSidebar( $skin, &$bar ): void {
		$isEQLImmersive = $this->isEQLImmersiveSkinName( $skin->getSkinName() );

		$pageTools = $isEQLImmersive ? $this->buildPageTools( $skin ) : [];
		$adminTools = $isEQLImmersive ? $this->buildAdminTools( $skin ) : [];

		$this->replaceEqlSidebarPlaceholders( $bar, $pageTools, $adminTools );

		if ( $isEQLImmersive ) {
			$this->ensureMainMenuAdminTools( $bar, $adminTools );
		}

		$this->removeEmptySidebarSections( $bar );
	}

	private function ensureMainMenuAdminTools(
		array &$bar,
		array $adminTools
	): void {
		if ( count( $adminTools ) === 0 ) {
			return;
		}

		/*
		 * If admin tools were already inserted through an
		 * __EQL_ADMIN_TOOLS__ sidebar placeholder, do not duplicate them.
		 */
		foreach ( $bar as $section ) {
			if ( !is_array( $section ) ) {
				continue;
			}

			foreach ( $section as $item ) {
				if ( !is_array( $item ) ) {
					continue;
				}

				$id = (string)( $item['id'] ?? '' );

				if (
					$id === 'n-eql-move' ||
					$id === 'n-eql-protect' ||
					$id === 'n-eql-delete'
				) {
					return;
				}
			}
		}

		/*
		 * Vector's pinnable main menu is sidebar-driven.
		 * This creates a normal main-menu section inside:
		 * #vector-main-menu.vector-pinnable-element
		 */
		$bar['EQL Admin tools'] = $adminTools;
	}

	private function buildPageTools( $skin ): array {
		$title = $skin->getTitle();

		if ( !$title || $title->isSpecialPage() ) {
			return [];
		}

		$user = $skin->getUser();
		$services = MediaWikiServices::getInstance();
		$permissionManager = $services->getPermissionManager();

		$items = [];

		$this->addSidebarItem(
			$items,
			'n-eql-read',
			'Read',
			$title->getLocalURL()
		);

		if ( $permissionManager->quickUserCan( 'edit', $user, $title ) ) {
			$this->addSidebarItem(
				$items,
				'n-eql-edit',
				'Edit',
				$title->getLocalURL( 'veaction=edit' )
			);

			$this->addSidebarItem(
				$items,
				'n-eql-edit-source',
				'Edit source',
				$title->getLocalURL( 'action=edit' )
			);
		}

		$this->addSidebarItem(
			$items,
			'n-eql-history',
			'View history',
			$title->getLocalURL( 'action=history' )
		);

		$talkTitle = null;

		if ( method_exists( $title, 'getTalkPageIfDefined' ) ) {
			$talkTitle = $title->getTalkPageIfDefined();
		} elseif ( method_exists( $title, 'getTalkPage' ) ) {
			$talkTitle = $title->getTalkPage();
		}

		if ( $talkTitle ) {
			$this->addSidebarItem(
				$items,
				'n-eql-discussion',
				'Discussion',
				$talkTitle->getLocalURL()
			);
		}

		if ( $user && $user->isRegistered() ) {
			$isWatched = false;

			try {
				$isWatched = $services
					->getWatchedItemStore()
					->isWatched( $user, $title );
			} catch ( \Throwable $e ) {
				$isWatched = false;
			}

			$this->addSidebarItem(
				$items,
				$isWatched ? 'n-eql-unwatch' : 'n-eql-watch',
				$isWatched ? 'Unwatch' : 'Watch',
				$title->getLocalURL( $isWatched ? 'action=unwatch' : 'action=watch' )
			);
		}

		$this->addSidebarItem(
			$items,
			'n-eql-whatlinkshere',
			'What links here',
			SpecialPage::getTitleFor(
				'Whatlinkshere',
				$title->getPrefixedText()
			)->getLocalURL()
		);

		$this->addSidebarItem(
			$items,
			'n-eql-relatedchanges',
			'Related changes',
			SpecialPage::getTitleFor(
				'Recentchangeslinked',
				$title->getPrefixedText()
			)->getLocalURL()
		);

		$this->addSidebarItem(
			$items,
			'n-eql-upload',
			'Upload file',
			SpecialPage::getTitleFor( 'Upload' )->getLocalURL()
		);

		return $items;
	}

	private function buildAdminTools( $skin ): array {
		$title = $skin->getTitle();

		if ( !$title || $title->isSpecialPage() ) {
			return [];
		}

		$user = $skin->getUser();
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		$items = [];

		if (
			$this->isAdminUser( $user ) &&
			$permissionManager->quickUserCan( 'move', $user, $title )
		) {
			$this->addSidebarItem(
				$items,
				'n-eql-move',
				'Move',
				SpecialPage::getTitleFor(
					'Movepage',
					$title->getPrefixedText()
				)->getLocalURL()
			);
		}

		if (
			$this->isAdminUser( $user ) &&
			$permissionManager->quickUserCan( 'protect', $user, $title )
		) {
			$this->addSidebarItem(
				$items,
				'n-eql-protect',
				'Protect / change protection',
				$title->getLocalURL( 'action=protect' )
			);
		}

		if (
			$this->isAdminUser( $user ) &&
			$permissionManager->quickUserCan( 'delete', $user, $title )
		) {
			$this->addSidebarItem(
				$items,
				'n-eql-delete',
				'Delete',
				$title->getLocalURL( 'action=delete' )
			);
		}

		return $items;
	}

	private function isAdminUser( $user ): bool {
		if ( !$user || !$user->isRegistered() ) {
			return false;
		}

		try {
			$groups = MediaWikiServices::getInstance()
				->getUserGroupManager()
				->getUserEffectiveGroups( $user );
		} catch ( \Throwable $e ) {
			return false;
		}

		return in_array( 'sysop', $groups, true ) ||
			in_array( 'bureaucrat', $groups, true ) ||
			in_array( 'interface-admin', $groups, true );
	}

	private function replaceEqlSidebarPlaceholders(
		array &$bar,
		array $pageTools,
		array $adminTools
	): void {
		foreach ( $bar as &$section ) {
			if ( !is_array( $section ) ) {
				continue;
			}

			$newSection = [];

			foreach ( $section as $item ) {
				if ( $this->isSidebarMarkerItem( $item, 'page' ) ) {
					foreach ( $pageTools as $replacementItem ) {
						$newSection[] = $replacementItem;
					}

					continue;
				}

				if ( $this->isSidebarMarkerItem( $item, 'admin' ) ) {
					foreach ( $adminTools as $replacementItem ) {
						$newSection[] = $replacementItem;
					}

					continue;
				}

				$newSection[] = $item;
			}

			$section = $newSection;
		}

		unset( $section );
	}

	private function removeEmptySidebarSections( array &$bar ): void {
		foreach ( $bar as $sectionName => $section ) {
			if ( is_array( $section ) && count( $section ) === 0 ) {
				unset( $bar[$sectionName] );
			}
		}
	}

	private function isSidebarMarkerItem(
		$item,
		string $type
	): bool {
		if ( !is_array( $item ) ) {
			return false;
		}

		$text = (string)( $item['text'] ?? '' );
		$id = (string)( $item['id'] ?? '' );
		$href = (string)( $item['href'] ?? '' );

		$haystack = $text . ' ' . $id . ' ' . $href;

		if ( $type === 'page' ) {
			return str_contains( $haystack, '__EQL_PAGE_TOOLS__' ) ||
				str_contains( $haystack, 'EQL_PAGE_TOOLS' );
		}

		if ( $type === 'admin' ) {
			return str_contains( $haystack, '__EQL_ADMIN_TOOLS__' ) ||
				str_contains( $haystack, 'EQL_ADMIN_TOOLS' ) ||
				str_contains( $haystack, 'ADMIN#PLACEHOLDER' );
		}

		return false;
	}

	private function addSidebarItem(
		array &$section,
		string $id,
		string $text,
		string $href
	): void {
		$section[] = [
			'id' => $id,
			'text' => $text,
			'href' => $href,
			'active' => false
		];
	}
}