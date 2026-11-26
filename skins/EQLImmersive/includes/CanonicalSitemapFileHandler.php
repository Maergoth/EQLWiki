<?php

namespace EQLImmersive;

use MediaWiki\Cache\GenderCache;
use MediaWiki\Config\Config;
use MediaWiki\Language\Language;
use MediaWiki\Languages\LanguageConverterFactory;
use MediaWiki\Page\SitemapGenerator;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\Handler\SitemapHandlerBase;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/** Native article sitemap generation with redirect aliases excluded. */
class CanonicalSitemapFileHandler extends SitemapHandlerBase {

	private ?array $data = null;

	public function __construct(
		private Config $config,
		LanguageConverterFactory $languageConverterFactory,
		Language $contLang,
		private PermissionManager $permissionManager,
		private IConnectionProvider $connectionProvider,
		private GenderCache $genderCache,
		private WANObjectCache $wanCache,
	) {
		parent::__construct( $config, $languageConverterFactory, $contLang, $permissionManager );
	}

	public function getParamSettings() {
		return [
			'indexId' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				IntegerDef::PARAM_MIN => 0,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'fileId' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				IntegerDef::PARAM_MIN => 0,
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	protected function getLastModified() {
		// Preconditions can run before execute(): do not query a disabled/private wiki.
		if ( !$this->enabled || !$this->permissionManager->isEveryoneAllowed( 'read' ) ) {
			return null;
		}
		return $this->getData()['timestamp'];
	}

	protected function getXml(): string {
		return $this->getData()['xml'];
	}

	/** @return array{xml:string,timestamp:int} */
	private function getData(): array {
		if ( $this->data === null ) {
			$params = $this->getValidatedParams();
			$startId = $this->getOffset( $params['indexId'], $params['fileId'] );
			$endId = $startId + $this->sitemapSize;
			// Never reuse the native cache: it may contain the old redirect aliases.
			$scope = sha1( json_encode( [
				$this->config->get( 'SitemapNamespaces' ),
				$this->config->get( 'DefaultRobotPolicy' ),
				$this->config->get( 'NamespaceRobotPolicies' ),
				$this->config->get( 'CanonicalServer' ),
				$this->config->get( 'ArticlePath' ),
			], JSON_THROW_ON_ERROR ) );
			$this->data = $this->wanCache->getWithSetCallback(
				$this->wanCache->makeKey( 'eql-canonical-sitemap-v1', $startId, $endId, $scope ),
				$this->expiry,
				function () use ( $startId, $endId ): array {
					$generator = new SitemapGenerator(
						$this->contLang, $this->languageConverterFactory, $this->genderCache
					);
					return [
						'xml' => $generator
							->namespacesFromConfig( $this->config )
							->idRange( $startId, $endId )
							->skipRedirects()
							->getXml( $this->connectionProvider->getReplicaDatabase() ),
						'timestamp' => ConvertibleTimestamp::time(),
					];
				},
				[ 'segmentable' => true, 'version' => 1 ]
			);
		}
		return $this->data;
	}
}
