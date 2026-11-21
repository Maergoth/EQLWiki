<?php

declare( strict_types = 1 );

namespace EQLClientData;

use InvalidArgumentException;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
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
			$result = $this->repository->getSummary( array_filter( $params, static fn ( $value ): bool => $value !== null ) );
		} catch ( InvalidArgumentException $error ) {
			$this->dieWithError( 'apierror-eqldrops-invalid', 'badvalue' );
		}
		$this->getMain()->setCacheMode( 'public' );
		$this->getMain()->setCacheMaxAge( 60 );
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'schemaVersion' => 1,
			'metric' => 'loot-report-share',
			'denominator' => 'All eligible explicit-corpse loot reports in the window for the same server, zone, NPC and instance; includes every tier and reports below the display threshold.',
			'coverage' => 'Unknown; reports are not distinct kills and reportShare is not a drop chance.',
			'minimumReports' => DropObservationSummary::MIN_REPORTS,
			'cacheSeconds' => DropDatabaseRepository::CACHE_SECONDS
		] + $result );
	}

	public function getAllowedParams(): array {
		$params = [];
		foreach ( [ 'zone', 'npc', 'item', 'server', 'q' ] as $field ) {
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
