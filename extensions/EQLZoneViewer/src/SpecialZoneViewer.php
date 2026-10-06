<?php
namespace EQLWiki\ZoneViewer;

use SpecialPage;

class SpecialZoneViewer extends SpecialPage {
    public function __construct() {
        parent::__construct( 'ZoneViewer' );
    }

    public function execute( $subPage ): void {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitleMsg( $this->msg( 'zoneviewer' ) );
        $out->addModules( 'ext.eqlZoneViewer' );
        $out->addWikiMsg( 'zoneviewer-summary' );
        $out->addHTML( ViewerMarkup::render( [ 'height' => 'calc(100vh - 210px)' ] ) );
    }
}
