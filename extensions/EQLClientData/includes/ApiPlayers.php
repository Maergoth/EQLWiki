<?php

declare( strict_types = 1 );

namespace EQLClientData;

use InvalidArgumentException;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use RuntimeException;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/** Public read-only snapshots; always use anonymous visibility, including for administrators. */
final class ApiPlayers extends ApiBase {
	public function __construct( ApiMain $main, string $action, private readonly PlayerListRepository $repository ) {
		parent::__construct( $main, $action );
	}

	public function execute(): void {
		$params = $this->extractRequestParams();
		$this->getMain()->setCacheMode( 'private' );
		$this->getMain()->setCacheMaxAge( 0 );
		try {
			if ( isset( $params['detail'] ) ) {
				if ( !isset( $params['snapshot'] ) || isset( $params['continue'] ) ) { throw new InvalidArgumentException( 'badvalue' ); }
				$result = [ 'detail' => $this->repository->getDetail( $params['detail'], $params['snapshot'] ) ];
			} else {
				if ( isset( $params['snapshot'] ) ) { throw new InvalidArgumentException( 'badvalue' ); }
				$result = $this->repository->getBatch( $params['continue'] ?? null, (int)$params['limit'] );
			}
		} catch ( InvalidArgumentException $error ) {
			$this->dieWithError( isset( $params['detail'] ) || isset( $params['snapshot'] ) ? 'apierror-eqlplayers-detail-invalid' : 'apierror-badcontinue', isset( $params['detail'] ) || isset( $params['snapshot'] ) ? 'badvalue' : 'badcontinue' );
		} catch ( RuntimeException $error ) {
			if ( isset( $params['detail'] ) ) {
				$code = in_array( $error->getMessage(), [ 'eqlplayers-detail-stale', 'eqlplayers-detail-conflict' ], true ) ? $error->getMessage() : 'eqlplayers-detail-unavailable';
				$this->dieWithError( 'apierror-' . $code, $code );
			}
			$this->dieWithError( 'apierror-eqlplayers-unavailable', 'eqlplayers-unavailable' );
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	public function getAllowedParams(): array {
		return [
			'detail' => [ ParamValidator::PARAM_TYPE => 'string' ],
			'snapshot' => [ ParamValidator::PARAM_TYPE => 'string' ],
			'continue' => [ ParamValidator::PARAM_TYPE => 'string' ],
			'limit' => [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 10,
				IntegerDef::PARAM_MIN => 1, IntegerDef::PARAM_MAX => 25 ]
		];
	}

	public function isWriteMode(): bool { return false; }
	public function mustBePosted(): bool { return false; }
	public function isInternal(): bool { return false; }
	public function getHelpUrls(): array { return [ 'https://eqlwiki.com/API_Documentation' ]; }
}
