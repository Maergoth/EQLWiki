<?php
# Not a valid entry point, skip unless MEDIAWIKI is defined
if ( !defined( 'MEDIAWIKI' ) ) {
	echo <<<EOT
To install my extension, put the following line in LocalSettings.php:
require_once( "\$IP/extensions/DynamicQuestItemList/DynamicQuestItemList.php" );
EOT;
	exit( 1 );
}

$wgExtensionCredits['specialpage'][] = array(
	'path' => __FILE__,
	'name' => 'DynamicQuestItemList',
	'version' => '0.8',
	'author' => 'EQLegends',
	'description' => 'Builds a dynamic quest item table from pages in Category:Quest Items.',
);

$dir = dirname( __FILE__ ) . '/';

/*
 * MediaWiki 1.45's SpecialPage ObjectFactory expects an array spec.
 * Because this extension is loaded legacy-style with require_once,
 * explicitly load the class file before registering the special page.
 */
require_once $dir . 'DynamicQuestItemList_body.php';

$wgSpecialPages['DynamicQuestItemList'] = array(
	'class' => 'DynamicQuestItemList'
);

$wgResourceModules['ext.dynamicQuestItemList'] = array(
	'scripts' => array(
		'dynamicquestitemlist.js'
	),
	'styles' => array(
		'dynamicquestitemlist.css'
	),
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'DynamicQuestItemList',
	'dependencies' => array(
		'mediawiki.util'
	),
);
