<?php
require __DIR__.'/../extensions/DynamicZoneList/NpcLootWikitext.php';
$n=0;$check=static function($yes,$message)use(&$n){$n++;if(!$yes)throw new RuntimeException($message);};
$item=static fn($drops,$notes='')=>'{{Itempage|itemname=Synthetic Relic|statsblock=DMG: 10|notes='.$notes.'|dropsfrom='.$drops.'}}';
$r=EqlNpcLootWikitext::contributionCoverage($item("[[Synthetic Vale]]\n* [[A keeper]]",'Various words outside the affected field'),'item');
$check($r['state']==='exact'&&count($r['edges'])===1,'Unrelated prose does not create an opt-out');
$r=EqlNpcLootWikitext::contributionCoverage($item("Various zones\n* Many skeleton types\n[[Synthetic Vale]]\n* [[A keeper]]"),'item');
$check($r['state']==='broad'&&count($r['edges'])===1&&$r['edges'][0]['npc']==='A keeper','Broad prose is policy, not a fake NPC, and exact positives survive');
$r=EqlNpcLootWikitext::contributionCoverage($item("[[Synthetic Vale]]\n* [[A keeper]] (Male only)"),'item');
$check($r['state']==='unsupported'&&count($r['edges'])===1,'Qualified edge is retained while automatic capability fails closed');
$r=EqlNpcLootWikitext::contributionCoverage($item('* [[A keeper]]'),'item');
$check($r['edges'][0]['precision']==='npc'&&$r['edges'][0]['zone']===''&&$r['edges'][0]['instance']==='normal','Unscoped positive has explicit instance and no invented zone');
$r=EqlNpcLootWikitext::contributionCoverage($item("[[City|West City]]\n* [[A keeper]]\n[[City|East City]]\n* [[Other keeper]]"),'item');
$check(count($r['edges'])===2&&$r['edges'][0]['precision']==='npc'&&$r['edges'][1]['zone']==='','Repeated displayed city contexts retain only NPC precision');
$r=EqlNpcLootWikitext::contributionCoverage($item('') . $item(''),'item');$check($r['state']==='variant','Multiple item variants cannot authorize automatic edits');
$r=EqlNpcLootWikitext::contributionCoverage('{{Itempage|itemname=Relic}}','item');$check($r['state']==='unsupported'&&$r['scopes'][0]['reason']==='missing-field','Missing field is different from supported empty field');
$r=EqlNpcLootWikitext::contributionCoverage($item('<nowiki>Various [[Fake]]</nowiki>'),'item');$check($r['state']==='exact'&&!$r['edges'],'Protected examples are not affected source claims');
$r=EqlNpcLootWikitext::contributionCoverage('{{Namedmobpage|name=Keeper|zone=[[Synthetic Vale]]|known_loot=<ul><li>Always drops two pieces of [[Synthetic Armor]]</li></ul>' . "\n=== Raid Instance ===\n* {{:Staff of Mastery: Water}}" . '|common_loot=* [[Common Relic]]}}','npc');
$check(count($r['collectionRefs'])===3&&$r['collectionRefs'][0]['title']==='Synthetic Armor'&&$r['collectionRefs'][1]['instance']==='raid'&&$r['collectionRefs'][1]['item']==='Staff of Mastery: Water'&&$r['collectionRefs'][2]['field']==='common_loot','Scoped collection candidates include quantity prose, colons and independent loot fields');
$r=EqlNpcLootWikitext::contributionCoverage('{{Namedmobpage|name=Keeper|known_loot=* [[First Relic]], [[Second Relic]] (for [[Research]])}}','npc');
$check(array_column($r['edges'],'item')===['First Relic','Second Relic'],'Inline literal loot groups stop before incidental rarity/quest note links');
$possible=EqlNpcLootWikitext::possibleNpcLootItems('{{Namedmobpage|known_loot=* [[First Relic]] (for [[Research]])}}',$bounded);
$check($bounded&&$possible===['First Relic'],'Incidental note links cannot become unknown collection uncertainty');
$possible=EqlNpcLootWikitext::possibleNpcLootItems('{{Namedmobpage|known_loot=* Low level [[Research]] components}}',$bounded);
$check($bounded&&$possible===['Research']&&!EqlNpcLootWikitext::contributionCoverage('{{Namedmobpage|known_loot=* Low level [[Research]] components}}','npc')['edges'],'A standalone broad loot group remains possible uncertainty, never a positive item drop');
$possible=EqlNpcLootWikitext::possibleNpcLootItems('{{Namedmobpage|known_loot=[[Shadowed Relic]]|known_loot=[[Final Relic]]}}',$bounded);
$check($bounded&&$possible===['Final Relic'],'Global possible-member uncertainty follows MediaWiki final repeated static field values');
foreach([
 ['{{Gear Set|First Relic|Second Relic}}',true,2],
 ['A public finite treasure pool.\n<ul><li>{{:First Relic}}</li><li>[[Second Relic]]</li></ul>',true,2],
 ["* {{:First Relic}}\n* [[Second Relic]]",true,2],
 ['{| class="wikitable"\n| Chest || [[First Relic]]\n|}',false,0],
 ['Only male monsters:<ul><li>[[First Relic]]</li></ul>',false,0],
 ['{{Unknown|<ul><li>[[First Relic]]</li></ul>}}',false,0],
 ['{{Namedmobpage|known_loot=<ul><li>[[First Relic]]</li></ul>}}',false,0],
 ['<ul><li>[[First Relic]] or [[Second Relic]]</li></ul>',false,0],
 ['<nowiki><ul><li>[[First Relic]]</li></ul></nowiki>',false,0]
]as[$source,$complete,$count]){$items=EqlNpcLootWikitext::finiteCollectionItems($source,$actual);$check($actual===$complete&&count($items)===$count,'Finite pool contract: '.$source);}
$check(EqlNpcLootWikitext::authoredPageKind('{{Itempage|itemname=Relic|notes=one|notes=two}}')==='item','Duplicate unrelated static fields do not erase actual item kind');
$check(EqlNpcLootWikitext::authoredPageKind('{{Spellpagesmart|spellname=Spell}}')==='spell','Spell pages are explicit non-item kinds');
$check(EqlNpcLootWikitext::authoredPageKind('{{Unknown|{{Itempage|itemname=Hidden}}}}')==='other','Nested card examples are not page identity');
$possible=EqlNpcLootWikitext::possibleNpcLootItems("{{Namedmobpage|name=Keeper|zone=[[Not an item]]|known_loot\n<ul><li>{{:First Relic}}</li><li>[[Second Relic]]</li></ul>|factions=[[Not loot]]}}",$bounded);
$check($bounded&&$possible===['First Relic','Second Relic'],'Missing equals can localize literal uncertainty without normalizing a loot field or reading faction/zone links');
$possible=EqlNpcLootWikitext::possibleLootItems('{{Unknown dynamic expansion|[[First Relic]]}}',$bounded);
$check(!$bounded&&in_array('First Relic',$possible,true),'Unknown dynamic expansion remains globally uncertain despite literal candidates');
$possible=EqlNpcLootWikitext::possibleLootItems('{| class="wikitable"\n|Chest||[[First Relic]]\n|Feet||[[Second Relic]]\n|}',$bounded);
$check($bounded&&count($possible)===2,'Conditional literal tables constrain possible membership without proving positive Cartesian edges');
$possible=EqlNpcLootWikitext::possibleCollectionItems("Crafted at a [[Forge]], in [[Synthetic Vale]].\n* [[First Relic]]\n* [[Second Relic]]",$bounded);
$check($bounded&&$possible===['First Relic','Second Relic'],'Collection uncertainty ignores narrative forge/zone links outside explicit list bodies');
$possible=EqlNpcLootWikitext::possibleCollectionItems("{| class=wikitable\n|Chest||[[First Relic]]\n|Feet||[[Second Relic]]\n|}\nOnly in [[Synthetic Vale]].",$bounded);
$check($bounded&&$possible===['First Relic','Second Relic'],'Conditional table bodies yield possible members without Cartesian facts or narrative links');
$possible=EqlNpcLootWikitext::possibleCollectionItems('* {{Unknown expansion|[[First Relic]]}}',$bounded);
$check(!$bounded&&in_array('First Relic',$possible,true),'Dynamic generators inside collection bodies cannot prove a bounded possible closure');

$r=EqlNpcLootWikitext::contributionCoverage('{{Itempage|itemname=Relic|notes=one|notes=two|dropsfrom=* [[A keeper]]}}','item');
$check($r['state']==='exact'&&count($r['edges'])===1,'Duplicate unrelated notes do not erase a clear affected source field');
$r=EqlNpcLootWikitext::contributionCoverage('{{Itempage|itemname=Relic|dropsfrom=* [[A keeper]]|dropsfrom=* [[Other keeper]]}}','item');
$check($r['state']==='unsupported'&&!$r['edges'],'Conflicting affected fields never authorize an automatic edit or choose arbitrary positive facts');
$r=EqlNpcLootWikitext::contributionCoverage('{{Namedmobpage|name=Keeper|known_loot=[[First Relic]]|known_loot=[[Second Relic]]}}','npc');
$check($r['state']==='unsupported'&&!$r['edges']&&!$r['collectionRefs'],'Conflicting NPC loot fields never choose arbitrary set or item coverage');
$r=EqlNpcLootWikitext::contributionCoverage($item(implode("\n",array_map(static fn($n)=>'* [[Keeper '.$n.']]',range(1,1001)))),'item');
$check(!$r['complete']&&$r['state']==='unsupported'&&$r['reason']==='coverage-limit'&&count($r['edges'])===1000&&$r['scopes'][0]['state']==='unsupported','Truncated positive facts cannot authorize complete automatic coverage');
$rows=EqlNpcLootWikitext::npcLootSources("{{Namedmobpage|name=Keeper|zone=[[Synthetic Vale]]|known_loot=* [[Normal Relic]]\n=== Raid Instance ===\n* [[Raid Relic]]}}",$complete,true);
$check($rows[0]['instance']==='normal'&&$rows[1]['instance']==='raid','Legacy reverse extraction agrees with typed internal instance scopes');

echo "Typed authored coverage: $n source checks passed.\n";
