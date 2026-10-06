<?php

namespace MediaWiki\Extension\EQLIconIndex;

use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use MediaWiki\Extension\EQLIconIndex\Job\RebuildIndexJob;

class Hooks {

	public static function onPageSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $user,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord,
		EditResult $editResult
	): void {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$title = $wikiPage->getTitle();

		if ( $title->getPrefixedText() !== $config->get( 'EQLIconIndexPage' ) ) {
			return;
		}

		self::markStaleAndQueue( [
			'mode' => 'full',
			'reason' => 'icon-list-edit',
			'revision' => $revisionRecord->getId(),
		] );
	}

	public static function onFileUpload( $file, $reupload, $hasDescription ): void {
		if ( !$file || !method_exists( $file, 'getTitle' ) ) {
			return;
		}

		$fileTitle = $file->getTitle()->getPrefixedText();

		try {
			$builder = new IndexBuilder();
			if ( !$builder->isListedFile( $fileTitle ) ) {
				return;
			}
		} catch ( \Throwable $e ) {
			/*
			 * If the server index does not exist yet, the Icon List edit hook
			 * or the manual initial build will establish it. Never interfere
			 * with an upload because this optional cache could not be read.
			 */
			return;
		}

		self::markStaleAndQueue( [
			'mode' => 'file',
			'reason' => $reupload ? 'icon-reupload' : 'icon-upload',
			'fileTitle' => $fileTitle,
		] );
	}

	public static function onFileDeleteComplete(
		$file,
		$oldimage,
		$article,
		$user,
		$reason
	): void {
		/*
		 * Ignore deletion of an old archived revision; only a deletion that
		 * can affect the current file should invalidate the active fingerprint.
		 */
		if ( $oldimage !== null && $oldimage !== '' ) {
			return;
		}

		if ( !$file || !method_exists( $file, 'getTitle' ) ) {
			return;
		}

		$fileTitle = $file->getTitle()->getPrefixedText();

		try {
			$builder = new IndexBuilder();
			if ( !$builder->isListedFile( $fileTitle ) ) {
				return;
			}
		} catch ( \Throwable $e ) {
			return;
		}

		self::markStaleAndQueue( [
			'mode' => 'file',
			'reason' => 'icon-delete',
			'fileTitle' => $fileTitle,
		] );
	}

	public static function onFileUndeleteComplete( $title, $fileVersions, $user, $reason ): void {
		if ( !$title ) {
			return;
		}

		$fileTitle = method_exists( $title, 'getPrefixedText' )
			? $title->getPrefixedText()
			: (string)$title;

		try {
			$builder = new IndexBuilder();
			if ( !$builder->isListedFile( $fileTitle ) ) {
				return;
			}
		} catch ( \Throwable $e ) {
			return;
		}

		self::markStaleAndQueue( [
			'mode' => 'file',
			'reason' => 'icon-undelete',
			'fileTitle' => $fileTitle,
		] );
	}

	private static function markStaleAndQueue( array $params ): void {
		try {
			$builder = new IndexBuilder();
			$builder->markStale( $params['reason'] ?? 'change' );

			$job = new RebuildIndexJob( $params );
			MediaWikiServices::getInstance()
				->getJobQueueGroup()
				->lazyPush( $job );
		} catch ( \Throwable $e ) {
			/*
			 * Never interfere with an edit/upload because the optional Icon
			 * Finder cache had a problem. The browser implementation remains
			 * a complete fallback.
			 */
			wfDebugLog(
				'EQLIconIndex',
				'Could not invalidate/enqueue icon index: ' . $e->getMessage()
			);
		}
	}
}
