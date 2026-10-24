<?php
/** Executes guarded installers against in-memory doubles; no real wiki/DB. */
declare( strict_types=1 );
namespace MediaWiki\Maintenance {
    class Maintenance {
        public static $services;public array $options=[];public array $messages=[];
        public function __construct() {}public function addDescription( ...$args ) {}public function addOption( ...$args ) {}
        public function getOption( $key,$default=null ) { return $this->options[$key]??$default; }public function hasOption( $key ) { return array_key_exists( $key,$this->options ); }
        public function getServiceContainer() { return self::$services; }public function output( $text ) { $this->messages[]=$text; }public function fatalError( $text ) { throw new \RuntimeException( $text ); }
    }
}
namespace MediaWiki\Content { class WikitextContent { public function __construct( private string $text ) {}public function getText() { return $this->text; } } }
namespace MediaWiki\CommentStore { class CommentStoreComment { public static function newUnsavedComment( $text ) { return $text; } } }
namespace MediaWiki\Revision { class SlotRecord { public const MAIN='main'; } }
namespace MediaWiki\Title { class Title { public static function newFromText( $x ) { return new self(); }public function getPrefixedText() { return 'API Documentation'; } } }
namespace Wikimedia\Rdbms { interface IDBAccessObject { public const READ_LATEST=1; } }
namespace {
define( 'EDIT_MINOR',1 );define( 'EDIT_UPDATE',2 );define( 'EDIT_NEW',4 );$IP=dirname( __DIR__ );
require __DIR__ . '/../extensions/EQLClientData/includes/ObservationRepository.php';
require __DIR__ . '/install-eoz-observations.php';require __DIR__ . '/sync-api-documentation.php';
$checks=0;function verify( $value,$message ) { global $checks;if ( !$value ) { throw new RuntimeException( $message ); }$checks++; }
function refuses( $fn,$message ) { try { $fn(); } catch ( RuntimeException $e ) { verify( str_contains( $e->getMessage(),$message ),'Expected refusal: '.$message.'; got '.$e->getMessage() );return; }throw new RuntimeException( 'Expected refusal '.$message ); }
class SchemaFixture {
    public array $tables=[];public int $creations=0;public bool $enabled=false;public bool $state=true;public bool $ddlError=false;
    public function getType() { return 'mysql'; }public function getDBname() { return 'fixture_database'; }public function addQuotes( $x ) { return "'".$x."'"; }
    public function query( $sql,$caller ) { verify( str_starts_with( $sql,'SELECT GET_LOCK(' )||str_starts_with( $sql,'SELECT RELEASE_LOCK(' ),'Only own schema advisory lock queries.' );return new class { public function fetchObject() { return (object)[ 'acquired'=>1 ]; } }; }
    public function tableExists( $name,$caller ) { return in_array( $name,$this->tables,true ); }public function fieldExists( ...$x ) { return true; }public function selectRow( ...$x ) { return $this->state?(object)[ 'eos_id'=>1 ]:false; }
    public function sourceFile( $file ) { verify( realpath( $file )===realpath( __DIR__.'/../extensions/EQLClientData/sql/mysql/observations.sql' ),'Only the fixed reviewed SQL may execute.' );$sql=file_get_contents( $file );verify( substr_count( $sql,'CREATE TABLE' )===3&&!preg_match( '/\b(?:DROP|ALTER|DELETE|TRUNCATE)\b/i',$sql ),'Create only three own tables, never destructive migration.' );$this->creations++;$this->tables=\EQLClientData\ObservationRepository::TABLES;return $this->ddlError?'Query failed':true; }
}
class PageFixture {
    public int $revision=0;public ?string $text=null;public int $writes=0;public bool $race=false;
    public function getRevisionByTitle( ...$x ) { return $this->revision?new class( $this ) { public function __construct( private PageFixture $page ) {}public function getId() { return $this->page->revision; }public function getContent( $slot ) { return new \MediaWiki\Content\WikitextContent( $this->page->text ); } }:null; }
    public function newFromName( $name ) { return new class { public function getId() { return 1; } }; }
    public function newFromTitle( $title ) { return $this; }public function newPageUpdater( $user ) { return new class( $this ) {
        private $source;public function __construct( private PageFixture $page ) {}public function hasEditConflict( $expected ) { return $this->page->race||$expected!==$this->page->revision; }public function setContent( $slot,$content ) { $this->source=$content->getText(); }
        public function saveRevision( $comment,$flags ) { $this->page->writes++;$this->page->revision++;$this->page->text=$this->source;return new class( $this->page->revision ) { public function __construct( private int $id ) {}public function getId() { return $this->id; } }; }
        public function getStatus() { return new class { public function isOK() { return true; } }; }
    }; }
}
$db=new SchemaFixture();$page=new PageFixture();
\MediaWiki\Maintenance\Maintenance::$services=new class( $db,$page ) {
    public function __construct( private SchemaFixture $db,private PageFixture $page ) {}public function getConnectionProvider() { return $this; }public function getPrimaryDatabase() { return $this->db; }
    public function getMainConfig() { return new class( $this->db ) { public function __construct( private SchemaFixture $db ) {}public function get( $key ) { verify( $key==='EQLClientDataEnableObservations','Inspect only own collection flag.' );return $this->db->enabled; } }; }
    public function getRevisionStore() { return $this->page; }public function getUserFactory() { return $this->page; }public function getWikiPageFactory() { return $this->page; }
};
$temporary=sys_get_temp_dir().DIRECTORY_SEPARATOR.'eoz-installers-'.bin2hex(random_bytes(6));mkdir( $temporary,0700 );
$knownFiles=[];$fresh=static function( $name ) use ( $temporary,&$knownFiles ) { $path=$temporary.DIRECTORY_SEPARATOR.$name.'.json';$knownFiles[]=$path;return $path; };
try {
    $options=[ 'expected-db'=>'fixture_database','expected-sha256'=>hash_file('sha256',__DIR__.'/../extensions/EQLClientData/sql/mysql/observations.sql') ];
    $installer=new EQLInstallObservations();$installer->options=$options;$installer->execute();verify( $db->creations===0,'Default schema review never creates a table.' );
    $installer->options['expected-db']='another';refuses( fn()=>$installer->execute(),'exact reviewed' );$installer->options=$options;
    $installer->options['expected-sha256']=str_repeat('0',64);refuses( fn()=>$installer->execute(),'reviewed hash' );$installer->options=$options;
    $db->enabled=true;refuses( fn()=>$installer->execute(),'Disable observation' );$db->enabled=false;
    $db->tables=[ 'eql_eoz_state' ];refuses( fn()=>$installer->execute(),'Partial schema' );$db->tables=[];
    $installer->options=$options+[ 'apply'=>true ];refuses( fn()=>$installer->execute(),'private backup' );
    $backup=$fresh('schema');$installer->options['backup']=$backup;$installer->execute();verify( $db->creations===1&&file_exists($backup),'Reviewed apply creates only own tables after fresh recovery receipt.' );
    $receipt=json_decode(file_get_contents($backup),true);verify( $receipt['tables']===array_fill_keys(\EQLClientData\ObservationRepository::TABLES,false),'Recovery receipt proves pre-install absence.' );
    $installer->execute();verify( $db->creations===1,'Existing complete tables are never rewritten.' );
    $db->state=false;refuses( fn()=>$installer->execute(),'state row missing' );$db->state=true;
    $db->tables=[];$db->ddlError=true;$installer->options['backup']=$fresh('ddl-failure');refuses( fn()=>$installer->execute(),'Schema creation failed' );verify( count($db->tables)===3,'DDL error is not mistaken for success even if tables partially committed.' );
    $publisher=new EQLSyncApiDocumentation();$publisher->options=[ 'expected-page'=>0 ];$publisher->execute();verify( $page->writes===0,'Documentation defaults to read-only.' );
    $publisher->options['apply']=true;refuses( fn()=>$publisher->execute(),'private backup' );
    $publisher->options['backup']=$fresh('page');$publisher->execute();verify( $page->writes===1&&$page->revision===1,'Only fixed API Documentation is saved after backup/CAS.' );
    $publisher->options['expected-page']=0;refuses( fn()=>$publisher->execute(),'changed since review' );
    $publisher->options['expected-page']=1;$page->text=str_replace("\n","\r\n",str_replace(["\r\n","\r"],"\n",$page->text));unset($publisher->options['backup']);$publisher->execute();verify( $page->writes===1,'Terminal newline/CRLF-only normalization does not duplicate publication.' );
    $page->text='Human changed page';$page->race=true;$publisher->options['backup']=$fresh('race');refuses( fn()=>$publisher->execute(),'changed after backup' );verify( $page->writes===1,'CAS race never overwrites a newer page.' );
    echo "Observation schema/public API publisher doubles: $checks checks passed; only temporary private receipts, no real DB/page writes.\n";
} finally {
    verify( dirname($temporary)===rtrim(sys_get_temp_dir(),'\\/')&&str_starts_with(basename($temporary),'eoz-installers-'),'Cleanup stays in the isolated test directory.' );
    foreach(array_unique($knownFiles) as $file)if(is_file($file))unlink($file);rmdir($temporary);
}
}
