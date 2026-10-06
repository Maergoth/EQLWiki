<?php
namespace EQLWiki\ZoneViewer;

use MediaWiki\MediaWikiServices;

class ViewerMarkup {
    public static function render( array $args = [] ): string {
        global $wgExtensionAssetsPath;

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $defaultHeight = (int)$config->get( 'EQLZoneViewerDefaultHeight' );
        $height = self::sanitizeHeight( $args['height'] ?? (string)$defaultHeight . 'px' );
        $base = rtrim( $wgExtensionAssetsPath, '/' ) . '/EQLZoneViewer/resources/dist';
        $payload = [
            'moduleUrl' => $base . '/ZoneViewerApp.js?v=1.15.0',
            'workerUrl' => $base . '/zone-parser.worker.js?v=1.15.0',
            'navigationWorkerUrl' => $base . '/navigation.worker.js?v=1.15.0',
            'height' => $height,
            'version' => '1.15.0'
        ];
        $json = htmlspecialchars(
            json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        return '<div class="eqlzv-root" data-eqlzv-config="' . $json . '">' .
            '<noscript>This zone viewer requires JavaScript.</noscript></div>';
    }

    private static function sanitizeHeight( string $height ): string {
        $height = trim( $height );
        if ( preg_match( '/^\d{3,4}px$/', $height ) ) {
            return $height;
        }
        if ( preg_match( '/^calc\([0-9a-zA-Z%+\-*\s.]+\)$/', $height ) ) {
            return $height;
        }
        return '780px';
    }
}
