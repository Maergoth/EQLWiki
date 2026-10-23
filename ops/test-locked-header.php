<?php
/**
 * Source-only custom-skin inheritance regression; no settings/database loaded.
 * Run: php ops/test-locked-header.php
 * The stub parent supplies Vector's sticky markup contract, not a running skin.
 */
namespace MediaWiki\Skins\Vector {
    class SkinVector22 {
        public static array $templateData = [];
        public static array $htmlAttributes = [];
        public function getTemplateData(): array {
            return self::$templateData;
        }
        public function getHtmlElementAttributes() {
            return self::$htmlAttributes;
        }
    }
}

namespace {
    if ( PHP_SAPI !== 'cli' ) {
        exit( "Run this fixture from the command line.\n" );
    }
    require_once dirname( __DIR__ ) . '/skins/EQLImmersive/includes/SkinEQLImmersive.php';
    $parent = MediaWiki\Skins\Vector\SkinVector22::class;
    $skin = ( new ReflectionClass( EQLImmersive\SkinEQLImmersive::class ) )->newInstanceWithoutConstructor();
    $parent::$templateData = [
        'data-vector-sticky-header' => [ 'id' => 'vector-sticky-header' ],
        'data-vector-user-links' => [ 'id' => 'vector-user-links-dropdown' ],
        'data-search-box' => [ 'id' => 'p-search' ],
        'html-title' => 'Synthetic page',
    ];
    $expected = $parent::$templateData;
    unset( $expected['data-vector-sticky-header'] );
    if ( $skin->getTemplateData() !== $expected ) {
        throw new RuntimeException( 'The duplicate must be omitted while original header data remains unchanged.' );
    }
    $parent::$templateData = $expected;
    if ( $skin->getTemplateData() !== $expected ) {
        throw new RuntimeException( 'A parent without duplicate markup must remain unchanged.' );
    }
    $parent::$htmlAttributes = [
        'class' => 'client-js vector-sticky-header-enabled vector-feature-limited-width-enabled keep-vector-sticky-header-enabled',
        'lang' => 'en', 'dir' => 'ltr',
    ];
    $attributes = $skin->getHtmlElementAttributes();
    if ( $attributes !== [
        'class' => 'client-js vector-feature-limited-width-enabled keep-vector-sticky-header-enabled',
        'lang' => 'en', 'dir' => 'ltr',
    ] ) {
        throw new RuntimeException( 'Remove only the native sticky-enabled token and retain other HTML attributes.' );
    }
    echo "Passed 3 source-only custom-header inheritance checks.\n";
}
