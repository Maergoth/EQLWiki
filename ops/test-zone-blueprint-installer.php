<?php
/** Runs the fixed-page publisher against synthetic services and private temp files. */
namespace MediaWiki\Maintenance {
    class Maintenance {
        public static $services; public array $options = []; public array $messages = [];
        public function __construct() {} public function addDescription( ...$args ) {} public function addOption( ...$args ) {}
        public function getOption( $key, $default = null ) { return $this->options[$key] ?? $default; }
        public function hasOption( $key ) { return array_key_exists( $key, $this->options ); }
        public function getServiceContainer() { return self::$services; }
        public function output( $text ) { $this->messages[] = $text; }
        public function fatalError( $text ) { throw new \RuntimeException( $text ); }
    }
}
namespace MediaWiki\Content {
    class WikitextContent { public function __construct( private string $text ) {} public function getText() { return $this->text; } }
    class ContentHandler { public static function makeContent( $text, $title ) { return new WikitextContent( $text ); } }
}
namespace MediaWiki\CommentStore { class CommentStoreComment { public static function newUnsavedComment( $text ) { return $text; } } }
namespace MediaWiki\Revision { class SlotRecord { public const MAIN = 'main'; } }
namespace MediaWiki\Title { class Title { public static function newFromText( $title ) { if ( $title !== 'Help:Contents' ) { throw new \RuntimeException( 'Wrong target page.' ); } return new self(); } } }
namespace Wikimedia\Rdbms { interface IDBAccessObject { public const READ_LATEST = 1; } }
namespace {
define( 'EDIT_MINOR', 1 ); define( 'EDIT_UPDATE', 2 );
$IP = dirname( __DIR__ );
require __DIR__ . '/sync-zone-page-blueprint.php';
$n = 0;
$check = static function ( $yes, $message ) use ( &$n ) { $n++; if ( !$yes ) { throw new RuntimeException( $message ); } };
$refuses = static function ( $fn, $message ) use ( $check ) { try { $fn(); } catch ( RuntimeException $error ) { $check( str_contains( $error->getMessage(), $message ), $error->getMessage() ); return; } $check( false, 'Expected refusal: ' . $message ); };
class ZoneBlueprintPageFixture {
    public int $revision = 200; public int $writes = 0; public int $reads = 0;
    public bool $beforeRace = false; public bool $afterRace = false; public bool $saveRace = false;
    public string $text = "== Zones ==\nUnchanged prose.\n=== Zone Page Blueprint ===\n<pre>old blueprint</pre>\n\n=== City Page Blueprint ===\n<pre>untouched city</pre>\n";
    public function newFromTitle( $title ) { return $this; }
    public function getRevisionByTitle( $page, $id, $flags ) {
        if ( $flags !== \Wikimedia\Rdbms\IDBAccessObject::READ_LATEST ) { throw new RuntimeException( 'Expected primary current read.' ); }
        $this->reads++; if ( $this->afterRace && $this->reads === 2 ) { $this->revision++; }
        return new class( $this->revision, $this->text ) {
            public function __construct( private int $id, private string $text ) {}
            public function getId() { return $this->id; }
            public function getContent( $slot ) { return new \MediaWiki\Content\WikitextContent( $this->text ); }
        };
    }
    public function newPageUpdater( $user ) { return new class( $this ) {
        private string $new; private int $base;
        public function __construct( private ZoneBlueprintPageFixture $page ) { $this->base = $page->revision; }
        public function hasEditConflict( $expected ) { return $this->page->beforeRace || $expected !== $this->base; }
        public function setContent( $slot, $content ) { $this->new = $content->getText(); }
        public function saveRevision( $comment, $flags ) {
            if ( $this->page->saveRace ) { $this->page->revision++; }
            if ( $this->page->revision !== $this->base ) { return null; }
            $this->page->writes++; $this->page->revision++; $this->page->text = $this->new;
            return new class( $this->page->revision ) { public function __construct( private int $id ) {} public function getId() { return $this->id; } };
        }
        public function getStatus() { return new class { public function isOK() { return true; } }; }
    }; }
}
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eql-zone-blueprint-' . bin2hex( random_bytes( 6 ) );
mkdir( $temporary, 0700 ); $files = [];
$path = static function ( $name ) use ( $temporary, &$files ) { return $files[] = $temporary . DIRECTORY_SEPARATOR . $name; };
$reset = static function () {
    $page = new ZoneBlueprintPageFixture();
    \MediaWiki\Maintenance\Maintenance::$services = new class( $page ) {
        public function __construct( private ZoneBlueprintPageFixture $page ) {}
        public function getWikiPageFactory() { return $this->page; } public function getRevisionStore() { return $this->page; }
        public function getUserFactory() { return new class { public function newFromName( $name ) { return new class { public function getId() { return 1; } }; } }; }
    };
    $runner = new EQLSyncZonePageBlueprint(); $runner->options = [ 'source' => __DIR__ . '/zone-page-blueprint.wiki', 'expected-revision' => 200 ];
    return [ $page, $runner ];
};
try {
    [ $page, $runner ] = $reset(); $runner->execute();
    $check( $page->writes === 0 && count( glob( $temporary . '/*' ) ) === 0, 'Default review performs no page or backup writes' );
    $runner->options['expected-revision'] = 199; $refuses( fn () => $runner->execute(), 'changed' );
    $runner->options['expected-revision'] = 0; $refuses( fn () => $runner->execute(), 'positive' );
    [ $page, $runner ] = $reset(); $runner->options['apply'] = true;
    $refuses( fn () => $runner->execute(), 'private backup' );
    $runner->options['backup'] = __DIR__ . '/must-not-create-public-backup.txt';
    $refuses( fn () => $runner->execute(), 'served root' );
    $backup = $path( 'success.txt' ); $runner->options['backup'] = $backup; $old = $page->text; $runner->execute();
    $check( $page->writes === 1 && file_get_contents( $backup ) === $old, 'Successful guarded save retains an exact full-page private recovery copy' );
    $check( str_ends_with( $page->text, "=== City Page Blueprint ===\n<pre>untouched city</pre>\n" ), 'Publisher changes no other blueprint' );
    $check( str_starts_with( $page->text, "== Zones ==\nUnchanged prose.\n" ), 'Publisher preserves surrounding Help content' );
    $runner->options['expected-revision'] = 201; $runner->execute(); $check( $page->writes === 1, 'Already-current source is a no-op even when apply is present' );
    [ $page, $runner ] = $reset(); $runner->options += [ 'apply' => true, 'backup' => $backup ];
    $refuses( fn () => $runner->execute(), 'already exists' ); $check( $page->writes === 0, 'Existing recovery files are never overwritten' );
    foreach ( [ 'beforeRace', 'afterRace', 'saveRace' ] as $race ) {
        [ $page, $runner ] = $reset(); $page->$race = true; $backup = $path( $race . '.txt' );
        $runner->options += [ 'apply' => true, 'backup' => $backup ];
        $refuses( fn () => $runner->execute(), $race === 'saveRace' ? 'publication failed' : 'changed' );
        $check( $page->writes === 0, 'Revision races cannot overwrite concurrent edits' );
        $check( $race === 'beforeRace' ? !file_exists( $backup ) : file_get_contents( $backup ) === $page->text, 'Recovery copy is retained when the race occurs after backup' );
    }
    [ $page, $runner ] = $reset(); $page->text .= "\n=== Zone Page Blueprint ===\nDuplicate target";
    $refuses( fn () => $runner->execute(), 'exactly one' ); $check( $page->writes === 0, 'Ambiguous sections never write' );
    echo "Guarded zone blueprint publisher: {$n} synthetic checks passed.\n";
} finally { foreach ( $files as $file ) { if ( is_file( $file ) ) { unlink( $file ); } } rmdir( $temporary ); }
}
