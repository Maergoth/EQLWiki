<?php

namespace EQLImmersive;

use MediaWiki\Languages\LanguageConverterFactory;
use MediaWiki\Skins\Vector\FeatureManagement\FeatureManagerFactory;
use MediaWiki\Skins\Vector\SkinVector22;

/**
 * EQL Immersive TEST — dark-fantasy wiki skin (optimized fork).
 *
 * Extends Vector 2022 to reuse its HTML structure, sidebar,
 * search, TOC, sticky header, and responsive behaviour.
 * All visual styling is replaced via skins.EQLImmersive.core.
 */
class SkinEQLImmersive extends SkinVector22 {

	public function __construct(
		LanguageConverterFactory $languageConverterFactory,
		FeatureManagerFactory $featureManagerFactory,
		array $options
	) {
		parent::__construct( $languageConverterFactory, $featureManagerFactory, $options );
	}
}