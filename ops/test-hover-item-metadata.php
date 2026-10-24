<?php
/** Pure adapter regression; no MediaWiki bootstrap/network/database. */
class SpecialPage {}
require __DIR__ . '/../extensions/AjaxHoverHelper/AjaxHoverHelper_body.php';
$object=( new ReflectionClass( AjaxHoverHelper::class ) )->newInstanceWithoutConstructor();
$method=new ReflectionMethod( AjaxHoverHelper::class, 'buildItemboxFromItempage' );
$source='<onlyinclude>{{Itempage|itemname=Synthetic Focus Relic|lucy_img_ID=501|statsblock=HP: 50<br>Effect: [[Rampage|Rampage (Combat, Casting Time: Instant)]]|focus_effect=[[Synthetic Focus|Preserve this focus effect]]}}</onlyinclude>';
$result=$method->invoke( $object, $source );
foreach ( [ '{{Itembox', 'Synthetic Focus Relic', 'HP: 50', '[[Rampage|Rampage (Combat, Casting Time: Instant)]]', '|focus_effect = [[Synthetic Focus|Preserve this focus effect]]' ] as $expected ) { if ( !str_contains( $result, $expected ) ) { throw new RuntimeException( 'Missing preserved hover metadata: ' . $expected ); } }
$direct='{{Itembox|itemname=Synthetic Direct|statsblock=HP: 5|focus_effect=Direct focus}}';
if ( $method->invoke( $object, $direct ) !== $direct ) { throw new RuntimeException( 'Direct Itembox source must stay unchanged.' ); }
echo "Itempage hover adapter preserves numeric/proc/focus metadata and direct Itembox source.\n";
