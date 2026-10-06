<?php

namespace MediaWiki\Extension\EQLIconIndex\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\EQLIconIndex\IndexBuilder;
use Wikimedia\ParamValidator\ParamValidator;

class ApiIconIndex extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$builder = new IndexBuilder();

		$state = $builder->getPublicState( !$params['meta'] );

		/*
		 * The tiny metadata request is intentionally not publicly cached:
		 * it is the invalidation check that tells the browser which immutable
		 * generation it should use.
		 */
		if ( $params['meta'] ) {
			$this->getMain()->setCacheMode( 'private' );
			$this->getMain()->setCacheMaxAge( 0 );

			unset( $state['records'] );
			$this->getResult()->addValue( null, 'eqliconindex', $state );
			return;
		}

		if (
			!$state['ready'] ||
			!isset( $state['generation'] ) ||
			$params['generation'] === '' ||
			$params['generation'] !== $state['generation']
		) {
			unset( $state['records'] );
			$this->getResult()->addValue( null, 'eqliconindex', $state );
			return;
		}

		/*
		 * Full payloads are immutable by generation. Clients include the
		 * generation in the request URL, so it is safe to allow HTTP caching.
		 */
		$this->getMain()->setCacheMode( 'public' );
		$this->getMain()->setCacheMaxAge( 86400 );

		$this->getResult()->addValue(
			null,
			'eqliconindex',
			$state,
			ApiResult::NO_SIZE_CHECK
		);
	}

	public function getAllowedParams() {
		return [
			'meta' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
			'generation' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
		];
	}

	public function isWriteMode() {
		return false;
	}

	protected function getExamplesMessages() {
		return [
			'action=eqliconindex&meta=1' => 'apihelp-eqliconindex-example-meta',
		];
	}
}
