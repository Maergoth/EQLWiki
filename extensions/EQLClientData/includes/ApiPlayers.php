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
			$result = $this->repository->getBatch( $params['continue'] ?? null, (int)$params['limit'] );
		} catch ( InvalidArgumentException $error ) {
			$this->dieWithError( 'apierror-badcontinue', 'badcontinue' );
		} catch ( RuntimeException $error ) {
			$this->dieWithError( 'apierror-eqlplayers-unavailable', 'eqlplayers-unavailable' );
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	public function getAllowedParams(): array {
		return [
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
