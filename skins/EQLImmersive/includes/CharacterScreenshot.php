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
		$owner = $parser->getTitle()->getText();
		$candidate = $override !== '' ? $override :
			'Character ' . $owner . ' - ' . ( $name !== '' ? $name : 'Unnamed character' ) .
			' (' . ( $server !== '' ? $server : 'Unknown server' ) . ').png';
		$title = trim( (string)$input ) === '' && !array_diff( array_keys( $args ), [ 'name', 'server', 'screenshot' ] ) ?
			self::fileTitle( $candidate ) : null;
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
			$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
			if ( $file ) {
				return Html::rawElement( 'div', $attributes,
					Html::rawElement( 'div', [ 'class' => 'eql-character-screenshot-image' ], $image )
				);
			}
		}
		$attributes['class'] .= ' is-missing';
		$hint = !$title ? 'Upload an image, then set screenshot to its local filename when editing this sheet.' :
			( $override === '' ? 'Upload a PNG screenshot to this filename. It will appear here automatically.' :
				'Upload a screenshot with this filename. It will appear here automatically.' );
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
