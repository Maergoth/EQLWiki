<?php

use MediaWiki\Api\ApiBase;

/** Public, read-only item formula configuration for offline-capable clients. */
final class ApiEQLItemScaling extends ApiBase {
	public function execute(): void {
		$rules = ItemLevelScalingRules::read();
		// Do not let a CDN retain a pre-deployment formula document.
		$this->getMain()->setCacheMode( 'private' );
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'schemaVersion' => 1,
			'engineVersion' => 1,
			'revision' => $rules['revision'],
			'rules' => $rules
		] );
	}

	public function isWriteMode(): bool {
		return false;
	}

	public function getExamplesMessages(): array {
		return [ 'action=eqlitemscaling&format=json&formatversion=2' => 'apihelp-eqlitemscaling-example-rules' ];
	}

	public function getHelpUrls(): array {
		return [ 'https://eqlwiki.com/API_Documentation' ];
	}
}
