<?php

/*************************************************

EQLegends - extensions (ItemLevelSlider)
Copyright (C) 2026

* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, version 3. This license is available
* in its entirety at <http://www.gnu.org/licenses/>.

*************************************************/

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension.' );
}

// ─── TOGGLE ──────────────────────────────────────────────────────────────────
// Set to false to disable the slider entirely.
$wgItemLevelSliderEnabled = true;

// Default item level progress.
// Examples:
//   0, 0 = base
//   4, 0 = 4 + 0/16
//   4, 7 = 4 + 7/16
//   10, 0 = 10
$wgItemLevelDefault = 0;
$wgItemLevelDefaultFraction = 0;
// ─────────────────────────────────────────────────────────────────────────────

$wgExtensionCredits['other'][] = array(
	'name'        => 'ItemLevelSlider',
	'author'      => 'EQLegends',
	'description' => 'Adds a fractional item level slider to item pages, scaling stats with spreadsheet-derived formulas.',
	'version'     => '2.4',
);

if ( $wgItemLevelSliderEnabled ) {
	$wgHooks['BeforePageDisplay'][] = 'ItemLevelSliderHooks::onBeforePageDisplay';
}

$wgResourceModules['ext.itemLevelSlider'] = array(
	'scripts'       => array( 'itemlevelslider.js' ),
	'styles'        => array( 'itemlevelslider.css' ),
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'ItemLevelSlider',
	'dependencies'  => array( 'mediawiki.util' ),
);

class ItemLevelSliderHooks {

	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		global $wgItemLevelDefault, $wgItemLevelDefaultFraction;

		$out->addJsConfigVars( 'wgItemLevelDefault', (int)$wgItemLevelDefault );
		$out->addJsConfigVars( 'wgItemLevelDefaultFraction', (int)$wgItemLevelDefaultFraction );

		$out->addModules( 'ext.itemLevelSlider' );

		return true;
	}
}
