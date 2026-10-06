<?php

declare( strict_types = 1 );

use EQLClientData\ConfigRepository;
use MediaWiki\MediaWikiServices;

return [
	'EQLClientData.ConfigRepository' => static function (
		MediaWikiServices $services
	): ConfigRepository {
		return new ConfigRepository(
			$services->getRevisionLookup(),
			$services->getTitleFactory(),
			$services->getMainWANObjectCache(),
			$services->getConnectionProvider(),
			$services->getMainConfig()
		);
	}
];
