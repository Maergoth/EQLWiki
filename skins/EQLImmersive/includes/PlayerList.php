<?php

declare( strict_types = 1 );

namespace EQLImmersive;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;

/** Render a directory mount, never a stored copy of other users' profiles. */
final class PlayerList implements ParserFirstCallInitHook {

	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( 'eqlplayers', [ self::class, 'render' ] );
	}

	public static function render( $input, array $args, $parser, $frame ): string {
		$parser->getOutput()->addModules( [ 'skins.EQLImmersive.playerList' ] );
		$parser->getOutput()->addModuleStyles( [ 'skins.EQLImmersive.playerList' ] );
		return Html::rawElement( 'div', [ 'class' => 'eql-player-list' ],
			Html::element( 'p', [], 'Loading public character snapshots…' ) .
			Html::rawElement( 'noscript', [], Html::element( 'p', [],
				'Enable JavaScript to browse, filter and sort published characters.' ) )
		);
	}
}
