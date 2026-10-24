<?php
declare( strict_types = 1 );
namespace EQLClientData;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Config\Config;
use Wikimedia\ParamValidator\ParamValidator;

/** Authenticated data-only writes; does not create/edit wiki articles. */
final class ApiObservations extends ApiBase {
    public function __construct( ApiMain $main, string $action,
        private readonly ObservationRepository $repository, private readonly Config $config ) {
        parent::__construct( $main, $action );
    }
    public function execute(): void {
        $params = $this->extractRequestParams(); $user = $this->getUser();
        if ( !$user->isNamed() ) { $this->dieWithError( 'apierror-eoz-login', 'eoz-login' ); }
        $this->checkUserRightsAny( 'eoz-submit-observations' );
        if ( $user->getBlock() ) { $this->dieWithError( 'apierror-eoz-blocked', 'eoz-blocked' ); }
        if ( $this->config->get( 'EQLClientDataObservationRequireConfirmedEmail' ) && !$user->isEmailConfirmed() ) {
            $this->dieWithError( 'apierror-eoz-email', 'eoz-email' );
        }
        $this->getMain()->setCacheMode( 'private' );
        if ( $params['operation'] === 'status' ) {
            $this->getResult()->addValue( null, $this->getModuleName(), [ 'schemaVersion' => 1,
                'enabled' => $this->repository->enabled(), 'available' => $this->repository->available(),
                'account' => [ 'id' => $user->getId(), 'name' => $user->getName() ], 'limits' => $this->repository->limits() ] );return;
        }
        if ( !$this->repository->enabled() ) { $this->dieWithError( 'apierror-eoz-disabled', 'eoz-disabled' ); }
        if ( !$this->repository->available() ) { $this->dieWithError( 'apierror-eoz-schema', 'eoz-schema-unavailable' ); }
        try { $batch = ObservationPayload::batch( $params['batch'] ); }
        catch ( \Throwable $error ) { $this->dieWithError( 'apierror-eoz-invalid', 'eoz-invalid-batch' ); }
        try { $receipt = $this->repository->store( $user, $batch ); }
        catch ( \RuntimeException $error ) {
            $code = $error->getMessage();
            if ( in_array( $code, [ 'eoz-disabled', 'eoz-schema-unavailable', 'eoz-rate-limit', 'eoz-capacity', 'eoz-batch-conflict' ], true ) ) {
                $this->dieWithError( 'apierror-' . $code, $code );
            }
            throw $error;
        }
        $this->getResult()->addValue( null, $this->getModuleName(), $receipt );
    }
    public function getAllowedParams(): array {
        return [ 'operation' => [ ParamValidator::PARAM_TYPE => [ 'submit', 'status' ], ParamValidator::PARAM_DEFAULT => 'submit' ],
            'batch' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ] ];
    }
    public function mustBePosted(): bool { return true; }
    public function isWriteMode(): bool { return true; }
    public function needsToken(): string { return 'csrf'; }
    public function getHelpUrls(): array { return [ 'https://eqlwiki.com/API_Documentation' ]; }
}
