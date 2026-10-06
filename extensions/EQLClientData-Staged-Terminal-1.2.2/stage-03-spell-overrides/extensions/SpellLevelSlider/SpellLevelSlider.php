<?php

/*************************************************

EQLegends - extensions (SpellLevelSlider)
Copyright (C) 2026

* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, version 3. This license is available
* in its entirety at <http://www.gnu.org/licenses/>.

*************************************************/

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension.' );
}

// ─── CONFIGURATION ───────────────────────────────────────────────────────────

// Set this before require_once() in LocalSettings.php to disable the extension.
if ( !isset( $wgSpellLevelSliderEnabled ) ) {
	$wgSpellLevelSliderEnabled = true;
}

// Spell levels are whole numbers only.
if ( !isset( $wgSpellLevelDefault ) ) {
	$wgSpellLevelDefault = 0;
}

if ( !isset( $wgSpellLevelMaximum ) ) {
	$wgSpellLevelMaximum = 10;
}

/*
 * Single source of truth for spell-level rates.
 *
 * Each rate is applied linearly from the page's base value:
 *     scaled value = base value * ( 1 + rate * spell level )
 *
 * null means that field does not scale for that category.
 */
if ( !isset( $wgSpellLevelSliderRules ) ) {
	$wgSpellLevelSliderRules = [
		'nuke_lifetap' => [
			'label' => 'Nuke / lifetap',
			'cast' => -0.02,
			'mana' => -0.02,
			'duration' => null
		],
		'dot' => [
			'label' => 'DoT',
			'cast' => -0.04,
			'mana' => -0.02,
			'duration' => 0.05
		],
		'heal' => [
			'label' => 'Heal',
			'cast' => -0.04,
			'mana' => -0.02,
			'duration' => null
		],
		'hot' => [
			'label' => 'HoT',
			'cast' => -0.04,
			'mana' => -0.02,
			'duration' => 0.05
		],
		'debuff' => [
			'label' => 'Debuff',
			'cast' => -0.04,
			'mana' => -0.04,
			'duration' => 0.10
		],
		'charm_mez' => [
			'label' => 'Charm / mez',
			'cast' => -0.04,
			'mana' => -0.04,
			'duration' => 0.10
		],
		'buff' => [
			'label' => 'Buff',
			'cast' => -0.04,
			'mana' => -0.04,
			'duration' => 0.10
		]
	];
}

// Wiki page containing the community-editable classification overrides.
if ( !isset( $wgSpellLevelOverridesPage ) ) {
	$wgSpellLevelOverridesPage = 'SpellLevelSliderOverrides';
}

// ─────────────────────────────────────────────────────────────────────────────

$wgExtensionCredits['other'][] = [
	'name' => 'SpellLevelSlider',
	'author' => 'EQLegends',
	'description' =>
		'Adds a whole-level spell slider to EQL spell pages, with wiki-managed spell category overrides.',
	'version' => '1.2.0'
];

if ( $wgSpellLevelSliderEnabled ) {
	$wgHooks['BeforePageDisplay'][] = 'SpellLevelSliderHooks::onBeforePageDisplay';
}

$wgResourceModules['ext.spellLevelSlider'] = [
	'scripts' => [
		'spelllevelslider.js'
	],
	'styles' => [
		'spelllevelslider.css'
	],
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'SpellLevelSlider',
	'dependencies' => [
		'mediawiki.api',
		'mediawiki.util'
	]
];

class SpellLevelSliderHooks {

	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		global $wgSpellLevelSliderEnabled;
		global $wgSpellLevelDefault;
		global $wgSpellLevelMaximum;
		global $wgSpellLevelSliderRules;
		global $wgSpellLevelOverridesPage;

		if ( !$wgSpellLevelSliderEnabled ) {
			return true;
		}

		/*
		 * Do not send the slider module and its configuration to unrelated
		 * pages. The broader lazy-container checks preserve class/guide pages
		 * that load spell content after the initial response.
		 */
		$html = $out->getHTML();
		$containsSpellUi =
			str_contains( $html, 'eql-spellpage' ) ||
			str_contains( $html, 'eql-spell-lazy' ) ||
			str_contains( $html, 'eql-spellpage-items' );

		if ( !$containsSpellUi ) {
			return true;
		}

		$maximum = max( 1, (int)$wgSpellLevelMaximum );
		$default = max( 0, min( $maximum, (int)$wgSpellLevelDefault ) );

		$out->addJsConfigVars( 'wgSpellLevelDefault', $default );
		$out->addJsConfigVars( 'wgSpellLevelMaximum', $maximum );
		$out->addJsConfigVars( 'wgSpellLevelSliderRules', $wgSpellLevelSliderRules );
		$out->addJsConfigVars(
			'wgSpellLevelOverridesPage',
			(string)$wgSpellLevelOverridesPage
		);

		$out->addModules( 'ext.spellLevelSlider' );

		return true;
	}
}
