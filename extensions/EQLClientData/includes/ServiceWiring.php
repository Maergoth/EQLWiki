<?php

declare( strict_types = 1 );

use EQLClientData\ConfigRepository;
use EQLClientData\ObservationRepository;
use EQLClientData\PlayerListRepository;
use MediaWiki\MediaWikiServices;

return [
	'EQLClientData.PlayerListRepository' => static function ( MediaWikiServices $services ): PlayerListRepository {
		return new PlayerListRepository(
			$services->getConnectionProvider(), $services->getPageStore(), $services->getRevisionLookup(),
			$services->getTitleFactory(), $services->getPermissionManager(), $services->getUserFactory(),
			$services->getParserOutputAccess(), $services->getMainWANObjectCache(), $services->getMainConfig()
		);
	},
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
