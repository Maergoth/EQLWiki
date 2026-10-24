<?php
/** Real MediaWiki registration/dispatch checks, no authenticated writes. */
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiMessage;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Request\FauxRequest;
class EQLTestClientApiBootstrap extends Maintenance {
    public function __construct() { parent::__construct();$this->addDescription( 'Read-only client API registration and rejected unauthenticated dispatch checks. No schema/account/page writes.' ); }
    public function execute() {
        $checks=0;$check=static function ( $value, $message ) use ( &$checks ) { if ( !$value ) { throw new RuntimeException( $message ); }$checks++; };
        $services=$this->getServiceContainer();$repository=$services->getService( 'EQLClientData.ObservationRepository' );
        $check( $repository instanceof \EQLClientData\ObservationRepository, 'Actual service wiring must resolve the observation repository.' );
        $check( !$repository->enabled() && !$repository->available(), 'This fixture requires disabled collection and must not touch its schema.' );
        $request=new FauxRequest( [ 'action'=>'paraminfo','modules'=>'eozobservations|eqlmetadata','format'=>'json' ] );
        $api=new ApiMain( $request );$api->execute();$data=$api->getResult()->getResultData();
        $modules=$data['paraminfo']['modules'] ?? [];$names=array_column( $modules, 'name' );
        $check( in_array( 'eozobservations', $names, true ) && in_array( 'eqlmetadata', $names, true ), 'Both actual registered API modules must be discoverable.' );
        $module=$modules[array_search( 'eozobservations', $names, true )];
        $tokenParams=array_values( array_filter( $module['parameters'] ?? [], static fn( $parameter ) => ( $parameter['name'] ?? null )==='token' ) );
        $check( ( $module['mustbeposted'] ?? false ) && ( $tokenParams[0]['tokentype'] ?? null )==='csrf', 'Actual API parameter discovery must declare POST/CSRF.' );
        foreach ( [ [ false,[] ], [ true,[ 'token'=>'invalid-synthetic-token' ] ] ] as [ $post,$extra ] ) {
            $api=new ApiMain( new FauxRequest( [ 'action'=>'eozobservations','operation'=>'status','format'=>'json' ] + $extra, $post ) );
            $rejected=false;try { $api->execute(); } catch ( ApiUsageException $error ) {
                $rejected=true;
                $messages=$error->getStatusValue()->getMessages( 'error' );$code=ApiMessage::create( $messages[0] )->getApiCode();
                $check( in_array( $code, [ 'missingparam','badtoken','mustbeposted','writeapidenied','eoz-login' ], true ), 'Only the expected core authorization/POST/CSRF rejection is accepted.' );
            }
            $data=$api->getResult()->getResultData();
            $errors=$data['errors'] ?? ( isset( $data['error'] ) ? [ $data['error'] ] : [] );
            $check( ( $rejected || count( $errors )>0 ) && !isset( $data['eozobservations'] ), 'Core dispatch must reject unauthenticated/invalid-token observation requests.' );
        }
        $this->output( "Client API real MediaWiki bootstrap: $checks checks passed; no schema, observation, account or page writes.\n" );
    }
}
return EQLTestClientApiBootstrap::class;
