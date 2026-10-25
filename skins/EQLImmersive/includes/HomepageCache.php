<?php

namespace EQLImmersive;

use MediaWiki\Cache\Hook\HTMLFileCache__useFileCacheHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\ParserAfterParseHook;
use MediaWiki\Hook\RejectParserCacheValueHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\OutputPageCheckLastModifiedHook;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;

/** Shared freshness for the home page and its transcluded News article. */
final class HomepageCache implements
	ParserAfterParseHook,
	RejectParserCacheValueHook,
	BeforePageDisplayHook,
	OutputPageCheckLastModifiedHook,
	PageSaveCompleteHook,
	HTMLFileCache__useFileCacheHook
{
	public const CACHE_SECONDS = 300;
	public const MARKER = 'eql-homepage-cache-v1';
	private ?MediaWikiServices $services;
	/** @var callable */
	private $clock;

	public function __construct( ?MediaWikiServices $services = null, ?callable $clock = null ) {
		$this->services = $services;
		$this->clock = $clock ?? static fn (): int => time();
	}

	private function isNews( $title ): bool {
		return $title && $title->getNamespace() === NS_MAIN && $title->getDBkey() === 'News';
	}

	private function applies( $title ): bool {
		return $title && ( $title->isMainPage() || $this->isNews( $title ) );
	}

	private function windowStart( int $now ): int {
		return intdiv( $now, self::CACHE_SECONDS ) * self::CACHE_SECONDS;
	}

	public function onParserAfterParse( $parser, &$text, $stripState ): void {
		if ( !$this->applies( $parser->getTitle() ) ) {
			return;
		}
		$now = (int)( $this->clock )();
		$output = $parser->getOutput();
		$output->setExtensionData( self::MARKER, $this->windowStart( $now ) );
		$output->updateCacheExpiry( self::CACHE_SECONDS - $now % self::CACHE_SECONDS );
	}

	/** Also retire pre-deployment day-long entries without a global cache flush. */
	public function onRejectParserCacheValue( $parserOutput, $wikiPage, $parserOptions ): bool {
		return !$this->applies( $wikiPage->getTitle() ) ||
			$parserOutput->getExtensionData( self::MARKER ) === $this->windowStart( (int)( $this->clock )() );
	}

	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $this->applies( $out->getTitle() ) ) {
			$now = (int)( $this->clock )();
			// Only lower an existing limit; private/no-store responses stay private.
			$out->lowerCdnMaxage( self::CACHE_SECONDS - $now % self::CACHE_SECONDS );
		}
	}

	/** File-cached HTML is served before parser freshness hooks can run. */
	public function onHTMLFileCache__useFileCache( $context ): bool {
		return !$this->applies( $context->getTitle() );
	}

	/** A browser's conditional request must cross the same shared freshness window. */
	public function onOutputPageCheckLastModified( &$modifiedTimes, $out ): void {
		if ( $this->applies( $out->getTitle() ) ) {
			$modifiedTimes['eql-homepage-window'] = gmdate( 'YmdHis', $this->windowStart( (int)( $this->clock )() ) );
		}
	}

	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		if ( !$this->isNews( $wikiPage->getTitle() ) ) {
			return;
		}
		try {
			$services = $this->services ?? MediaWikiServices::getInstance();
			$mainPage = $services->getTitleFactory()->newMainPage();
			// This refreshes shared parser/file/CDN caches without creating a revision
			// or waiting for the ordinary transclusion-update job queue.
			$services->getWikiPageFactory()->newFromTitle( $mainPage )->doPurge();
		} catch ( \Throwable $error ) {
			// Cache maintenance must not reject a successful News edit. The shared
			// five-minute window still provides the fallback freshness bound.
			if ( function_exists( 'wfDebugLog' ) ) {
				wfDebugLog( 'EQLImmersive', 'Home page cache purge failed: ' . get_class( $error ) );
			}
		}
	}
}
