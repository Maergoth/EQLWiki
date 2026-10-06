<?php
namespace EQLWiki\ZoneViewer;

use Parser;

class Hooks {
    public static function onParserFirstCallInit( Parser $parser ): void {
        $parser->setHook( 'zoneviewer', [ self::class, 'renderTag' ] );
    }

    public static function renderTag( ?string $input, array $args, Parser $parser ): string {
        $parser->getOutput()->addModules( 'ext.eqlZoneViewer' );
        return ViewerMarkup::render( $args );
    }
}
