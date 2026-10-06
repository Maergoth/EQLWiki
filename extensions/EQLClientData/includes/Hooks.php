<?php

declare( strict_types = 1 );

namespace EQLClientData;

use MediaWiki\Config\Config;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Skin\Skin;

/**
 * Feature-gated hooks so the optimization can be deployed one feature at a
 * time without changing unrelated wiki behavior.
 */
final class Hooks implements
	BeforePageDisplayHook,
	PageSaveCompleteHook,
	PageDeleteCompleteHook,
	PageMoveCompleteHook
{
	public function __construct(
		private readonly ConfigRepository $repository,
		private readonly Config $config
	) {
	}

	public function onBeforePageDisplay( $out, $skin ): void {
		if (
			!$out instanceof OutputPage ||
			!$skin instanceof Skin ||
			strtolower( $skin->getSkinName() ) !== 'eqlimmersive'
		) {
			return;
		}

		$vars = [
			'wgEQLClientDataEnabled' => true
		];

		if ( (bool)$this->config->get( 'EQLClientDataEnableEra' ) ) {
			$eraConfig = $this->repository->getEraConfig();

			$vars += [
				'wgEQLMetadataApiAction' => 'eqlmetadata',
				'wgEQLEraConfigRevision' => (int)$eraConfig['revision'],
				'wgEQLEraOutKeys' => $eraConfig['outKeys'],
				'wgEQLEraStatusClientTtlSeconds' => max(
					5,
					(int)$this->config->get(
						'EQLClientDataEraStatusClientTtlSeconds'
					)
				)
			];
		}

		if ( (bool)$this->config->get( 'EQLClientDataEnableVerification' ) ) {
			$title = $out->getTitle();
			$verifiedConfig = $this->repository->getVerifiedPages();
			$isArticlePage = $title && !$title->isSpecialPage();
			$isVerified = $isArticlePage
				? $this->repository->isPageVerified( $title, $verifiedConfig )
				: true;

			$vars += [
				'wgEQLVerifiedPagesTitle' => (string)$this->config->get(
					'EQLClientDataVerifiedPagesTitle'
				),
				'wgEQLVerifiedPagesRevision' => (int)$verifiedConfig['revision'],
				'wgEQLPageVerified' => $isVerified
			];

			$verificationModule = trim( (string)$this->config->get(
				'EQLClientDataVerificationModuleName'
			) );

			if ( $isArticlePage && !$isVerified && $verificationModule !== '' ) {
				$out->addModules( $verificationModule );
			}
		}

		if ( (bool)$this->config->get( 'EQLClientDataEnableSpellOverrides' ) ) {
			$html = $out->getHTML();
			$containsSpellUi =
				str_contains( $html, 'eql-spellpage' ) ||
				str_contains( $html, 'eql-spell-lazy' ) ||
				str_contains( $html, 'eql-spellpage-items' );

			if ( $containsSpellUi ) {
				$spellOverrides = $this->repository->getSpellOverrides();

				$vars += [
					'wgEQLSpellLevelOverrides' => $spellOverrides['overrides'],
					'wgEQLSpellLevelOverridesRevision' => (int)$spellOverrides['revision'],
					'wgEQLSpellLevelOverridesStatus' => $spellOverrides['status']
				];
			}
		}

		$out->addJsConfigVars( $vars );
	}

	public function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	): void {
		if ( $wikiPage instanceof WikiPage ) {
			$this->repository->invalidateIfConfigPage( $wikiPage->getTitle() );
		}
	}

	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	): void {
		$this->repository->invalidateIfConfigPage( $page );
	}

	public function onPageMoveComplete(
		$old,
		$new,
		$user,
		$pageid,
		$redirid,
		$reason,
		$revision
	): void {
		if ( $old instanceof LinkTarget ) {
			$this->repository->invalidateIfConfigPage( $old );
		}

		if ( $new instanceof LinkTarget ) {
			$this->repository->invalidateIfConfigPage( $new );
		}
	}
}
