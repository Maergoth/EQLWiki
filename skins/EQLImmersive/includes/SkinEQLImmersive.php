<?php

namespace EQLImmersive;

use MediaWiki\Languages\LanguageConverterFactory;
use MediaWiki\Skins\Vector\FeatureManagement\FeatureManagerFactory;
use MediaWiki\Skins\Vector\SkinVector22;
use SkinTemplate;

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

	/** EQL follows the actual header, so Vector must not initialize a duplicate. */
	public function getTemplateData(): array {
		$data = parent::getTemplateData();
		// Vector's browser controller gates initialization on the rendered node.
		// Hiding it in CSS alone still initializes its scroll/menu behavior.
		unset( $data['data-vector-sticky-header'] );
		return $data;
	}

	public function getHtmlElementAttributes() {
		$attributes = parent::getHtmlElementAttributes();
		$attributes['class'] = trim( preg_replace(
			'/(?:^|\s)vector-sticky-header-enabled(?=\s|$)/', '', $attributes['class'] ?? ''
		) );
		return $attributes;
	}

	/** Apply EQL's source-first action after every extension has built its tabs. */
	protected function runOnSkinTemplateNavigationHooks( SkinTemplate $skin, &$content_navigation ) {
		parent::runOnSkinTemplateNavigationHooks( $skin, $content_navigation );
		( new Hooks() )->finalizeNavigation( $skin, $content_navigation );
	}
}
