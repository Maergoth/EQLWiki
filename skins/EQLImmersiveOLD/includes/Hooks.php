<?php

namespace EQLImmersive;

use MediaWiki\Hook\SkinBuildSidebarHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use SkinTemplate;

class Hooks implements SkinTemplateNavigation__UniversalHook, SkinBuildSidebarHook {

	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $skin->getSkinName() !== 'eqlimmersive' ) {
			return;
		}

		$viewport = 'width=device-width, initial-scale=1, viewport-fit=cover';

		if ( method_exists( $out, 'addHeadItem' ) ) {
			$out->addHeadItem(
				'viewport',
				'<meta name="viewport" content="' . htmlspecialchars( $viewport, ENT_QUOTES ) . '">'
			);

			return;
		}

		$out->addMeta( 'viewport', $viewport );
	}

	public function onSkinTemplateNavigation__Universal(
		$sktemplate,
		&$links
	): void {
		if ( $sktemplate->getSkinName() !== 'eqlimmersive' ) {
			return;
		}

		$this->addHeaderWatchLink( $sktemplate, $links );
		$this->createViewsOverflow( $links );
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
		$isEQLImmersive = $skin->getSkinName() === 'eqlimmersive';

		$pageTools = $isEQLImmersive ? $this->buildPageTools( $skin ) : [];
		$adminTools = $isEQLImmersive ? $this->buildAdminTools( $skin ) : [];

		$this->replaceEqlSidebarPlaceholders( $bar, $pageTools, $adminTools );
		$this->removeEmptySidebarSections( $bar );
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
