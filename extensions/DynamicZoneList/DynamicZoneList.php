<?php
# Not a valid entry point, skip unless MEDIAWIKI is defined
if ( !defined( 'MEDIAWIKI' ) ) {
        echo <<<EOT
To install my extension, put the following line in LocalSettings.php:
require_once( "\$IP/extensions/DynamicZoneList/DynamicZoneList.php" );
EOT;
        exit( 1 );
}

$wgExtensionCredits['specialpage'][] = array(
	'path' => __FILE__,
	'name' => 'DynamicZoneList',
	'version' => '0.4.2',
	'author' => 'Ravhin',
	'url' => 'http://www.destinati.com/',
	'descriptionmsg' => 'dynamiczonelist-desc',
);

$dir = dirname( __FILE__ ) . '/';
$wgAutoloadClasses['DynamicZoneList'] = $dir . 'DynamicZoneList_body.php';
$wgAutoloadClasses['EqlNpcLootWikitext'] = $dir . 'NpcLootWikitext.php';
$wgAutoloadClasses['EqlNpcLootLookup'] = $dir . 'NpcLootLookup.php';
$wgAutoloadClasses['EqlNpcLootRenderer'] = $dir . 'NpcLootRenderer.php';
$wgAutoloadClasses['EqlNpcLootInvalidation'] = $dir . 'NpcLootInvalidation.php';
$wgAutoloadClasses['EqlItemDropLookup'] = $dir . 'ItemDropLookup.php';
$wgHooks['ParserBeforeInternalParse'][] = 'EqlNpcLootRenderer::onParserBeforeInternalParse';
$wgHooks['OutputPageParserOutput'][] = 'EqlNpcLootRenderer::onOutputPageParserOutput';
$wgHooks['BeforePageDisplay'][] = 'EqlNpcLootRenderer::onBeforePageDisplay';
$wgHooks['RejectParserCacheValue'][] = 'EqlNpcLootRenderer::onRejectParserCacheValue';
$wgHooks['PageSaveComplete'][] = 'EqlNpcLootInvalidation::onPageSaveComplete';
$wgHooks['LinksUpdateComplete'][] = 'EqlNpcLootInvalidation::onLinksUpdateComplete';
$wgHooks['PageDeleteComplete'][] = 'EqlNpcLootInvalidation::onPageDeleteComplete';
$wgHooks['PageMoveComplete'][] = 'EqlNpcLootInvalidation::onPageMoveComplete';
$wgSpecialPages['DynamicZoneList'] = 'DynamicZoneList';
$wgExtensionMessagesFiles['DynamicZoneList'] = $dir . 'DynamicZoneList.i18n.php';
$wgExtensionAliasesFiles['DynamicZoneList'] = $dir . 'DynamicZoneList.alias.php';
