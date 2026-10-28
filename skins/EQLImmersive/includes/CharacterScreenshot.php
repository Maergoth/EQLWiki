<?php

declare( strict_types = 1 );

namespace EQLImmersive;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/** A public File reference, not an upload service or a source of Character data. */
final class CharacterScreenshot implements ParserFirstCallInitHook {

	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( 'eqlcharacterscreenshot', [ self::class, 'render' ] );
	}

	/** Match the app's conservative bare-image-name contract using native title normalization. */
	public static function fileTitle( string $candidate ): ?Title {
		if ( strlen( $candidate ) > 4096 || !preg_match( '//u', $candidate ) ||
			preg_match( '/[\x00-\x1f\x7f]/u', $candidate )
		) {
			return null;
		}
		$title = Title::newFromText( 'File:' . trim( $candidate ) );
		if ( !$title || $title->getNamespace() !== NS_FILE || $title->isExternal() || $title->getFragment() !== '' ) {
			return null;
		}
		$name = $title->getText();
		if ( strlen( $name ) > 240 || preg_match( '~[\x00-\x1f\x7f|{}\[\]<>#\\\\]~u', $name ) ||
			preg_match( '~^(?:[a-z][a-z0-9+.-]*:|/)~i', $name ) ||
			!preg_match( '/\.(?:png|jpe?g|gif|webp)$/iD', $name )
		) {
			return null;
		}
		return $title;
	}

	public static function render( $input, array $args, $parser, $frame ): string {
		$override = trim( (string)( $args['screenshot'] ?? '' ) );
		$name = trim( (string)( $args['name'] ?? '' ) );
		$server = trim( (string)( $args['server'] ?? '' ) );
		// Parser title is authoritative: this is PAGENAME, including any subpage.
		$page = $parser->getTitle(); $owner = $page->getText();
		$policy = new CharacterScreenshotOwnership();
		$default = 'Character ' . $owner . ' - ' . ( $name !== '' ? $name : 'Unnamed character' ) .
			' (' . ( $server !== '' ? $server : 'Unknown server' ) . ').png';
		$defaultTitle = self::fileTitle( $default );
		if ( $defaultTitle && !$policy->belongsToPage( $defaultTitle, $page ) ) { $defaultTitle = null; }
		$title = $override !== '' ? self::fileTitle( $override ) : $defaultTitle;
		$refusedOverride = $override !== '' && ( !$title || !$policy->belongsToPage( $title, $page ) );
		if ( $refusedOverride ) { $title = $defaultTitle; }
		if ( trim( (string)$input ) !== '' || array_diff( array_keys( $args ), [ 'name', 'server', 'screenshot' ] ) ) { $title = null; }
		$refusedTarget = false; $file = null;
		if ( $title ) {
			$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title, [ 'latest' => true ] );
			if ( $file && ( !$file->getRepo()->isLocal() || !self::fileTitle( $file->getTitle()->getText() ) ||
				!$policy->belongsToPage( $file->getTitle(), $page ) ) ) {
				// A local owned alias must not lend protection to a shared/foreign
				// binary. Core still records native image dependencies below.
				$refusedTarget = true; $title = $defaultTitle; $file = null;
			}
		}
		$uploadQuery = [ 'eqlReturnTo' => $parser->getTitle()->getPrefixedText() ];
		$attributes = [ 'class' => 'eql-character-screenshot eql-character-screenshot-v1', 'aria-label' => 'Character screenshot' ];
		if ( $title ) {
			$attributes['data-file-title'] = $title->getPrefixedText();
			$uploadQuery['wpDestFile'] = $title->getText();
			// Parse both present AND missing files through core. makeImage registers
			// the image (and redirected target) in ParserOutput even when absent,
			// so a later upload invalidates the cached transcluding User page.
			$image = $parser->recursiveTagParse(
				'[[File:' . wfEscapeWikiText( $title->getText() ) . '|frameless|260x520px|alt=Character screenshot]]', $frame
			);
			if ( $file && !$refusedOverride && !$refusedTarget ) {
				return Html::rawElement( 'div', $attributes,
					Html::rawElement( 'div', [ 'class' => 'eql-character-screenshot-image' ], $image )
				);
			}
		}
		$attributes['class'] .= ' is-missing';
		$hint = $refusedOverride ? wfMessage( 'eql-character-screenshot-change-override' )->text() :
			( $refusedTarget ? wfMessage( 'eql-character-screenshot-unowned-target' )->text() :
				( !$title ? wfMessage( 'eql-character-screenshot-unavailable-target' )->text() :
					( $override === '' ? 'Upload a PNG screenshot to this filename. It will appear here automatically.' :
						'Upload a screenshot with this filename. It will appear here automatically.' ) ) );
		return Html::rawElement( 'div', $attributes,
			Html::rawElement( 'a', [
				'class' => 'eql-character-screenshot-add',
				'href' => SpecialPage::getTitleFor( 'Upload' )->getLocalURL( $uploadQuery )
			], Html::element( 'span', [ 'aria-hidden' => 'true', 'class' => 'eql-character-screenshot-plus' ], '+' ) .
				Html::element( 'strong', [], 'Add an image' ) ) .
			Html::element( 'p', [ 'class' => 'eql-character-screenshot-hint' ], $hint )
		);
	}
}
