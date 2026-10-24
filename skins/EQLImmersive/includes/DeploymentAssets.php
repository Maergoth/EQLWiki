<?php

namespace EQLImmersive;

use MediaWiki\Output\Hook\OutputPageCheckLastModifiedHook;
use MediaWiki\Settings\SettingsBuilder;

/** Release-specific local asset URLs without changing ResourceLoader versions. */
final class DeploymentAssets implements OutputPageCheckLastModifiedHook {

	private static ?string $activationTimestamp = null;

	public static function onRegistration( array $info, SettingsBuilder $settings ): void {
		self::configure( $settings, __DIR__ . '/../deployment-version.txt' );
	}

	/** @internal The marker path is installation metadata, never request input. */
	public static function configure( SettingsBuilder $settings, string $markerPath ): void {
		self::$activationTimestamp = null;
		$release = @file_get_contents( $markerPath, false, null, 0, 128 );
		if ( !is_string( $release ) || !preg_match( '/\A([a-f0-9]{40})(?:\r?\n)?\z/', $release, $match ) ) {
			return;
		}
		$loadScript = $settings->getConfig()->get( 'LoadScript' );
		$versioned = self::versionedLoadScript( $loadScript, $match[1] );
		$activePath = 'eql-assets/' . $match[1] . '/load.php';
		if ( $versioned === $loadScript && $loadScript !== $activePath && !str_ends_with( $loadScript, '/' . $activePath ) ) {
			return;
		}
		if ( $versioned !== $loadScript ) {
			$settings->overrideConfigValue( 'LoadScript', $versioned );
		}

		$stat = @stat( $markerPath );
		if ( $stat ) {
			// Linux ctime advances on deployment AND when a backup restores an older
			// marker with its original mtime. Do not reuse the previous HTML via 304.
			self::$activationTimestamp = gmdate( 'YmdHis', max( $stat['mtime'], $stat['ctime'] ) );
		}
	}

	public static function versionedLoadScript( string $url, string $release ): string {
		if ( !preg_match( '/\A[a-f0-9]{40}\z/', $release ) || strpbrk( $url, '?#' ) !== false ) {
			return $url;
		}
		// Keep the configured origin/install path. Query-free paths also work in
		// mw.loader, mw.util.wikiScript('load') and Parsoid's stylesheet builder.
		return preg_replace(
			'~(^|/)(?:eql-assets/[a-f0-9]{40}/)?load\.php\z~',
			'${1}eql-assets/' . $release . '/load.php',
			$url
		);
	}

	public function onOutputPageCheckLastModified( &$modifiedTimes, $out ): void {
		if ( self::$activationTimestamp !== null ) {
			$modifiedTimes['eql-deployment'] = self::$activationTimestamp;
		}
	}
}
