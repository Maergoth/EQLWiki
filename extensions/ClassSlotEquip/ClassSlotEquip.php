<?php
# Not a valid entry point, skip unless MEDIAWIKI is defined
if ( !defined( 'MEDIAWIKI' ) ) {
	echo <<<EOT
To install my extension, put the following line in LocalSettings.php:
require_once( "\$IP/extensions/ClassSlotEquip/ClassSlotEquip.php" );
EOT;
	exit( 1 );
}

	$wgExtensionCredits['specialpage'][] = array(
	'path' => __FILE__,
	'name' => 'ClassSlotEquip',
		'version' => '0.9',
	'author' => 'Ravhin, EQLegends',
	'url' => 'http://www.destinati.com/',
	'descriptionmsg' => 'classslotequip-desc',
);

$dir = dirname( __FILE__ ) . '/';

/*
 * MediaWiki 1.45's SpecialPage ObjectFactory expects an array spec.
 * Because this extension is still loaded legacy-style with require_once,
 * explicitly load the class file before registering the special page.
 */
require_once $dir . 'ClassSlotEquip_body.php';

$wgSpecialPages['ClassSlotEquip'] = array(
	'class' => 'ClassSlotEquip'
);

$wgExtensionMessagesFiles['ClassSlotEquip'] = $dir . 'ClassSlotEquip.i18n.php';
$wgExtensionAliasesFiles['ClassSlotEquip'] = $dir . 'ClassSlotEquip.alias.php';

$wgResourceModules['ext.classSlotEquip.builder'] = array(
	'scripts' => array(
		'classslotequip-builder.js'
	),
	'styles' => array(
		'classslotequip-builder.css'
	),
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'ClassSlotEquip',
	'dependencies' => array(
		'mediawiki.util'
	),
);

/**
 * Load the builder UI only on Equipment By Class.
 */
$wgHooks['BeforePageDisplay'][] = function ( $out, $skin ) {
	global $wgItemLevelSliderEnabled, $wgResourceModules;

	$title = $out->getTitle();

	if ( !$title ) {
		return true;
	}

	$dbKey = $title->getDBkey();
	$text = $title->getText();

	if (
		$title->getNamespace() === NS_MAIN &&
		(
			$dbKey === 'Equipment_By_Class' ||
			strtolower( str_replace( ' ', '_', $text ) ) === 'equipment_by_class'
		)
	) {
		/*
		 * Reuse ItemLevelSlider's real module when that extension is present
		 * and enabled. ClassSlotEquip contains no item-level formulas.
		 */
		if (
			isset( $wgResourceModules['ext.itemLevelSlider'] ) &&
			( !isset( $wgItemLevelSliderEnabled ) || $wgItemLevelSliderEnabled )
		) {
			$out->addModules( 'ext.itemLevelSlider' );
		}

		$out->addModules( 'ext.classSlotEquip.builder' );
	}

	return true;
};
