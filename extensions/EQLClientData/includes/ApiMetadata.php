<?php

declare( strict_types = 1 );

namespace EQLClientData;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Config\Config;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Batched, read-only era metadata endpoint.
 *
 * Replaces many prop=categories calls with one inexpensive POST request.
 */
final class ApiMetadata extends ApiBase {
	public function __construct(
		ApiMain $main,
		string $action,
		private readonly ConfigRepository $repository,
		private readonly Config $config,
		private readonly TitleFactory $titleFactory
	) {
		parent::__construct( $main, $action );
	}

	public function execute(): void {
		$params = $this->extractRequestParams();
		$rawTitles = preg_split( '/\|/', (string)$params['titles'] ) ?: [];
		$maxTitles = max(
			1,
			(int)$this->config->get( 'EQLClientDataApiMaxTitles' )
		);
		$titles = [];
		$requestedByCanonical = [];

		foreach ( $rawTitles as $rawTitle ) {
			$rawTitle = trim( (string)$rawTitle );

			if ( $rawTitle === '' ) {
				continue;
			}

			$title = $this->titleFactory->newFromText( $rawTitle );

			if ( !$title || $title->isExternal() ) {
				continue;
			}

			$canonical = $title->getPrefixedDBkey();
			$requested = str_replace( ' ', '_', ltrim( $rawTitle, ':' ) );
			$titles[$canonical] = $title;
			$requestedByCanonical[$canonical][$requested] = true;

			if ( count( $titles ) >= $maxTitles ) {
				break;
			}
		}

		$eraConfig = $this->repository->getEraConfig();
		$statuses = $this->repository->getEraStatuses( array_values( $titles ) );

		foreach ( $statuses as $canonical => &$status ) {
			$status['requested'] = array_keys(
				$requestedByCanonical[$canonical] ?? [ $canonical => true ]
			);
		}
		unset( $status );

		$this->getMain()->setCacheMode( 'private' );
		$this->getResult()->addValue(
			null,
			$this->getModuleName(),
			[
				'eraRevision' => (int)$eraConfig['revision'],
				'pages' => array_values( $statuses )
			]
		);
	}

	public function getAllowedParams(): array {
		return [
			'titles' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true
			]
		];
	}

	public function mustBePosted(): bool {
		return true;
	}

	public function isInternal(): bool {
		return true;
	}
}
