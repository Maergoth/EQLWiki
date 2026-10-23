<?php
/**
 * Source-only navigation regression; no settings, database, or account writes.
 * Run: php ops/test-header-editing.php
 * These synthetic links do not establish live permissions or editor delivery.
 */
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\Title;

if ( PHP_SAPI !== 'cli' ) {
    exit( "Run this fixture from the command line.\n" );
}
$root = dirname( __DIR__ );
require_once $root . '/includes/AutoLoader.php';
require_once $root . '/includes/Defines.php';
require_once $root . '/skins/EQLImmersive/includes/Hooks.php';

class EqlHeaderFixtureTitle extends Title {
    public function __construct( private bool $special = false ) {
    }
    public function isSpecialPage(): bool {
        return $this->special;
    }
    public function getLocalURL( $query = '' ): string {
        return '/wiki/Synthetic' . ( $query ? '?' . http_build_query( $query ) : '' );
    }
}
class EqlHeaderFixtureSkin extends SkinTemplate {
    public function __construct( private ?Title $fixtureTitle, private array $fixtureOptions = [] ) {
    }
    public function getRelevantTitle(): ?Title {
        return $this->fixtureTitle;
    }
    public function editUrlOptions(): array {
        return $this->fixtureOptions + [ 'action' => 'edit' ];
    }
}
$checks = 0;
function eqlHeaderCheck( bool $condition, string $message ): void {
    global $checks;
    $checks++;
    if ( !$condition ) {
        throw new RuntimeException( $message );
    }
}

$hooks = new EQLImmersive\Hooks();
$skin = new EqlHeaderFixtureSkin( new EqlHeaderFixtureTitle(), [ 'oldid' => 42 ] );
$source = [ 'id' => 'ca-edit', 'text' => 'Edit source', 'href' => '/wiki/Synthetic?action=edit',
    'class' => 'selected visualeditor-showtabdialog', 'single-id' => 'ca-editsource', 'accesskey' => 'e' ];
$visual = [ 'text' => 'Edit', 'href' => '/wiki/Synthetic?veaction=edit' ];
$links = [ 'views' => [ 'view' => [ 'text' => 'Read' ], 've-edit' => $visual, 'edit' => $source ],
    'actions' => [ 'protect' => [ 'text' => 'Protect' ] ] ];
$hooks->finalizeNavigation( $skin, $links );
eqlHeaderCheck( array_keys( $links['views'] ) === [ 'view', 'edit' ], 'Only one edit tab remains' );
$edit = $links['views']['edit'];
parse_str( parse_url( $edit['href'], PHP_URL_QUERY ), $query );
eqlHeaderCheck( $edit['text'] === 'Edit', 'The single button is labelled Edit' );
eqlHeaderCheck( $query === [ 'oldid' => '42', 'action' => 'edit', 'veswitched' => '1' ],
    'Editing explicitly chooses source and retains the rendered revision' );
eqlHeaderCheck( $edit['id'] === 'ca-edit' && $edit['single-id'] === 'ca-editsource' && $edit['accesskey'] === 'e',
    'Native editor ID, tooltip, and access key survive' );
eqlHeaderCheck( str_contains( $edit['class'], 'selected' ) &&
    str_contains( $edit['class'], 'eql-source-edit-action' ) &&
    !str_contains( $edit['class'], 'visualeditor-showtabdialog' ), 'Source tab classes preserve selection' );
eqlHeaderCheck( $links['views-overflow']['more-edit']['id'] === 'ca-more-edit' &&
    $links['views-overflow']['more-edit']['href'] === $edit['href'], 'Overflow retains source with a distinct ID' );
eqlHeaderCheck( $links['actions'] === [ 'protect' => [ 'text' => 'Protect' ] ], 'Other actions are untouched' );
$once = $links;
$hooks->finalizeNavigation( $skin, $links );
eqlHeaderCheck( $links === $once, 'Final normalization is idempotent' );

foreach ( [ 'viewsource', 'eql-verify-email' ] as $key ) {
    $action = [ 'id' => 'ca-' . $key, 'text' => 'Synthetic permission action', 'href' => '/wiki/Synthetic' ];
    $links = [ 'views' => [ $key => $action ] ];
    $hooks->finalizeNavigation( $skin, $links );
    eqlHeaderCheck( $links['views'] === [ $key => $action ], "$key permission action remains unchanged" );
}
foreach ( [ null, new EqlHeaderFixtureTitle( true ) ] as $title ) {
    $links = [ 'views' => [ 'edit' => $source, 've-edit' => $visual ] ];
    $before = $links;
    $hooks->finalizeNavigation( new EqlHeaderFixtureSkin( $title ), $links );
    eqlHeaderCheck( $before === $links, 'Missing/special titles retain extension-defined navigation' );
}
echo "Passed $checks source-only header editing checks.\n";
