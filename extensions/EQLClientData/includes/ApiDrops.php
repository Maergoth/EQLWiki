<?php

declare( strict_types = 1 );

namespace EQLClientData;

use InvalidArgumentException;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use RuntimeException;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/** Public aggregate claims only. No endpoint returns private observation records. */
final class ApiDrops extends ApiBase {
	public function __construct( ApiMain $main, string $action, private readonly DropDatabaseRepository $repository ) {
		parent::__construct( $main, $action );
	}

	public function execute(): void {
		$params = $this->extractRequestParams();
		try {
			if ( isset( $params['detail'] ) ) {
				if ( !isset( $params['snapshot'] ) || array_filter( array_intersect_key( $params, array_flip( [ 'q', 'zone', 'npc', 'item', 'instance' ] ) ), static fn ( $value ): bool => $value !== null ) ) {
					throw new InvalidArgumentException( 'badvalue' );
				}
				$result = [ 'detail' => $this->repository->getDetail( $params['detail'], $params['snapshot'], (int)( $params['offset'] ?? 0 ), (int)( $params['limit'] ?? 25 ) ) ];
			} else {
				if ( isset( $params['snapshot'] ) ) { throw new InvalidArgumentException( 'badvalue' ); }
				$result = $this->repository->getSummary( array_filter( $params, static fn ( $value ): bool => $value !== null ) );
			}
		} catch ( InvalidArgumentException $error ) {
			$this->dieWithError( 'apierror-eqldrops-invalid', 'badvalue' );
		} catch ( RuntimeException $error ) {
			$code = in_array( $error->getMessage(), [ 'eqldrops-detail-stale', 'eqldrops-detail-unavailable' ], true ) ? $error->getMessage() : 'eqldrops-unavailable';
			$this->dieWithError( 'apierror-' . $code, $code );
		}
		$this->getMain()->setCacheMode( 'public' );
		$this->getMain()->setCacheMaxAge( 60 );
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'schemaVersion' => 2,
			'metric' => 'loot-report-share',
			'denominator' => 'All eligible explicit-corpse loot reports in the window for the same zone, NPC and instance; combines every server and tier and includes reports below the display threshold. Capability-message pseudo-zones are excluded.',
			'coverage' => 'Unknown; reports are not distinct kills and reportShare is not a drop chance.',
			'minimumReports' => DropObservationSummary::MIN_REPORTS,
			'cacheSeconds' => DropDatabaseRepository::CACHE_SECONDS
		] + $result );
	}

	public function getAllowedParams(): array {
		$params = [];
		foreach ( [ 'zone', 'npc', 'item', 'q', 'detail', 'snapshot' ] as $field ) {
			$params[$field] = [ ParamValidator::PARAM_TYPE => 'string' ];
		}
		$params['instance'] = [ ParamValidator::PARAM_TYPE => [ 'normal', 'raid', 'unknown' ] ];
		$params['offset'] = [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 0,
			IntegerDef::PARAM_MIN => 0, IntegerDef::PARAM_MAX => 1000000 ];
		$params['limit'] = [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 50,
			IntegerDef::PARAM_MIN => 1, IntegerDef::PARAM_MAX => 500 ];
		return $params;
	}

	public function isWriteMode(): bool { return false; }
	public function mustBePosted(): bool { return false; }
	public function isInternal(): bool { return false; }
	public function getHelpUrls(): array { return [ 'https://eqlwiki.com/API_Documentation' ]; }
}
