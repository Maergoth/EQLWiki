<?php

declare( strict_types = 1 );

use EQLClientData\ConfigRepository;
use EQLClientData\ObservationRepository;
use MediaWiki\MediaWikiServices;

return [
	'EQLClientData.ObservationRepository' => static function ( MediaWikiServices $services ): ObservationRepository {
		return new ObservationRepository( $services->getConnectionProvider(), $services->getActorStore(), $services->getMainConfig() );
	},
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
