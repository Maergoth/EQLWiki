<?php

namespace EQLImmersive;

use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\SkinBuildSidebarHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
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

		// EQL critical first-paint background.
		// Prevent the browser's default light canvas from flashing before
		// the external EQLImmersive stylesheet has been applied.
		$out->addHeadItem(
			'eql-color-scheme',
			'<meta name="color-scheme" content="dark">'
		);

		$out->addInlineStyle(
			'html, body { background-color: #0c1425 !important; color-scheme: dark; }'
		);
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
		$this->addTalkActivityModule( $out, $skin );
	}

	/** Provide authoritative Talk pairing and the timestamp of rendered content. */
	private function addTalkActivityModule( $out, $skin ): void {
		$title = $skin->getTitle();
		$talkTitle = $title ? $title->getTalkPageIfDefined() : null;
		if ( !$talkTitle ) {
			return;
		}
		$timestamp = $out->getMetadata()->getRevisionTimestamp();
		$out->addJsConfigVars( [
			'wgEQLTalkPageName' => $talkTitle->getPrefixedDBkey(),
			'wgEQLTalkIsTalkPage' => $title->isTalkPage(),
			'wgEQLTalkRevisionTimestamp' => $timestamp ? wfTimestamp( TS_ISO_8601, $timestamp ) : null
		] );
		$out->addModules( 'skins.EQLImmersive.talkUnread' );
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

		if ( str_contains( $html, 'id="eql-icon-catalog"' ) ) {
			$out->addModuleStyles( 'skins.EQLImmersive.iconList' );
			$out->addModules( 'skins.EQLImmersive.iconList' );
		}

		/* EQL_PERF_WIDGET_CONDITIONAL_V1
		 * Diagnostics are useful to editors/admins, but should not be
		 * part of every anonymous visitor's global ResourceLoader bundle.
		 */
		$user = $skin->getUser();

		if ( $user && $user->isRegistered() ) {
			$out->addModuleStyles( 'skins.EQLImmersive.diagnostics' );
			$out->addModules( 'skins.EQLImmersive.diagnostics' );
		}

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

		/*
		 * EQL class-guide dropdown markup detection.
		 *
		 * ClassGuidesDropdown is used on more than just Class_Guides.
		 * Load its tiny helper whenever the rendered page actually
		 * contains the dropdown placeholder.
		 */
		if ( str_contains( $html, 'class-guides-dropdown' ) ) {
			$out->addModules( 'skins.EQLImmersive.classGuide' );
		}

		if ( str_contains( $html, 'eql-mobpage' ) ) {
			$out->addModuleStyles( 'skins.EQLImmersive.mob.styles' );
		}

		if ( str_contains( $html, 'eql-factionpage' ) ) {
			$out->addModuleStyles( 'skins.EQLImmersive.faction.styles' );
		}

		/*
		 * EQL Lucy icon reference grid styles.
		 *
		 * spellblade-lucy.css also contains the Lucy_Images grid rules,
		 * including the CSS that hides missing/unpopulated icon cells.
		 * Stage 04 made the spell bundle conditional, so explicitly load
		 * its styles when the standalone icon-reference grid is present.
		 */
		if ( str_contains( $html, 'eql-lucy-images-grid' ) ) {
			$out->addModuleStyles( 'skins.EQLImmersive.spell.styles' );
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
		// Use concise labels throughout the wiki while preserving link contracts.
		foreach ( [ 'namespaces', 'associated-pages' ] as $group ) {
			foreach ( $links[$group] ?? [] as $key => $item ) {
				if ( $key === 'talk' || ( $item['context'] ?? '' ) === 'talk' ) {
					$links[$group][$key]['text'] = 'Talk';
				}
			}
		}
		foreach ( [ 'views', 'views-overflow', 'actions' ] as $group ) {
			foreach ( $links[$group] ?? [] as $key => $item ) {
				if ( $key === 'history' || $key === 'more-history' ) {
					$links[$group][$key]['text'] = 'History';
				}
			}
		}

		if ( !$this->isEQLImmersiveSkinName( $sktemplate->getSkinName() ) ) {
			return;
		}

		$this->addEmailVerificationLink( $sktemplate, $links );
		$this->addHeaderWatchLink( $sktemplate, $links );
		$this->moveTalkNextToRead( $links );
		$this->createViewsOverflow( $links );
	}

	/** Keep the subject tab first, with Talk immediately after the Read action. */
	private function moveTalkNextToRead( array &$links ): void {
		$talk = null;
		foreach ( [ 'associated-pages', 'namespaces', 'views' ] as $group ) {
			foreach ( $links[$group] ?? [] as $key => $item ) {
				if ( !is_array( $item ) ||
					( $key !== 'talk' && ( $item['context'] ?? '' ) !== 'talk' )
				) {
					continue;
				}
				$talk ??= $item;
				unset( $links[$group][$key] );
			}
		}
		if ( $talk === null ) {
			return;
		}

		// Core uses this context after the hook to retain ca-talk and rel=discussion.
		$talk['context'] = 'talk';
		$talkClass = $talk['class'] ?? '';
		$talkClass = is_array( $talkClass ) ? implode( ' ', $talkClass ) : (string)$talkClass;
		if ( !preg_match( '/(?:^|\s)vector-tab-noicon(?:\s|$)/', $talkClass ) ) {
			$talkClass = trim( $talkClass . ' vector-tab-noicon' );
		}
		$talk['class'] = $talkClass;
		$views = [];
		foreach ( $links['views'] ?? [] as $key => $item ) {
			$views[$key] = $item;
			if ( $key === 'view' ) {
				$views['talk'] = $talk;
			}
		}
		if ( !isset( $views['talk'] ) ) {
			// New pages can have a creation action without a Read action.
			$views = [ 'talk' => $talk ] + $views;
		}
		$links['views'] = $views;
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

		// Talk contributions may be allowed before email confirmation. Only show
		// Verify2Edit when the server actually reports that requirement.
		$status = MediaWikiServices::getInstance()->getPermissionManager()->getPermissionStatus(
			'edit', $user, $title, PermissionManager::RIGOR_QUICK
		);
		if ( !$status->hasMessage( 'confirmedittext' ) ) {
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
			if ( $key === 'talk' ) {
				// The primary Talk context would otherwise give both links ca-talk.
				$newItem['id'] = 'ca-more-talk';
				$newItem['rel'] = $newItem['rel'] ?? 'discussion';
			} else {
				$newItem['class'] = trim( ( $newItem['class'] ?? '' ) . ' vector-tab-noicon' );
			}
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
			'History',
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
				'Talk',
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
