<?php
		 
/*************************************************

eqlwiki - extensions (DynamicZoneList)
Copyright (C) 2013 Dylan Nelson (dnelson@destinati.com)
Version: 0.1

* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, version 3. This license is available
* in its entirety at <http://www.gnu.org/licenses/>.

* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.

*************************************************/

use MediaWiki\Title\Title;

class DynamicZoneList extends SpecialPage {

function __construct()
{
  parent::__construct( "DynamicZoneList" );
}

function isIncludable() { return true; }

function parseTemplateParameters($templateText)
{
  $cbrackets = 2;
  $size = strlen( $templateText );
  $parms = array();
  $parm = '';
  $hasParm = false; 

  for ( $i = 0; $i < $size; $i++ )
  {
    $c = $templateText[$i];

    if ( $c == '{' || $c == '[' ) {
      $cbrackets++; // we count both types of brackets
    }

    if ( $c == '}' || $c == ']' ) {
      $cbrackets--;
    }

    if ( $cbrackets == 4 && $c == '|' ) {
      $parms[] = trim( $parm );
      $hasParm = true;
      $parm = '';
    } else {
      $parm .= $c;
    }

    if ( $cbrackets == 0 ) {
      if ( $hasParm ) {
        $parms[] = trim( substr( $parm, 0, strlen( $parm ) - 2 ) );
      }
      //array_splice( $parms, 0, 1 ); // remove artifact; 
    }
  }

  return $parms;
}

function getCatIntersection($db,$parser,$catNames)
{
  $iCatCount = count($catNames);
  
  $poptions = \MediaWiki\Parser\ParserOptions::newFromAnon();
  
  foreach ($catNames as $catName)
  {
  	$title = Title::newFromText( $parser->transformMsg($catName, $poptions) );
  	if( is_null( $title ) ) {
      $wgOut->addWikiTextAsContent("Error! 8605");
      return;
  	}
  	$aCategories[] = $title;
  }
	
  // query 
	$aTables = Array( 'page' );
	$aFields = Array( 'page_namespace', 'page_title' );
	$aWhere = Array();
	$aJoin = Array();
	$aOptions = Array();
  
	$aOptions['ORDER BY'] = "page_title ASC";
	//$aOptions['LIMIT'] = 0;
	//$aOptions['OFFSET'] = 0;
  
	$iCurrentTableNumber = 1;

	for ($i = 0; $i < $iCatCount; $i++) {
		$clAlias = "c$iCurrentTableNumber";
		$ltAlias = "lt$iCurrentTableNumber";
		$aJoin[$clAlias] = Array( 'INNER JOIN',
			Array( "page_id = {$clAlias}.cl_from" )
		);
		$aJoin[$ltAlias] = Array( 'INNER JOIN',
			Array(
				"{$clAlias}.cl_target_id = {$ltAlias}.lt_id",
				"{$ltAlias}.lt_namespace = 14",
				"{$ltAlias}.lt_title = {$db->addQuotes($aCategories[$i]->getDBKey())}"
			)
		);
		$aTables[$clAlias] = "categorylinks";
		$aTables[$ltAlias] = "linktarget";

		$iCurrentTableNumber++;
	}

  // retrieve list of pages in this category intersection
	$res = $db->select( $aTables, $aFields, $aWhere, __METHOD__, $aOptions, $aJoin );
	
	//if ( $db->numRows( $res ) == 0 ) {
	//	$wgOut->addWikiTextAsContent("No items found.");
  //  return;
	//}

  return $res;
}

function parseQuestPage($templateText)
{
		$regex = "/class=\"questTopTable\"(.*?)\|}\n/s";
		$ret = preg_match($regex, $templateText, $block);		
		
		if (!$ret) {
		  $rowVals = array("?","","","","","");
			return $rowVals;
		}
		
		// quest giver
		$pos = strpos($block[0],"Quest Giver");
		$pos1 = strpos($block[0],"\n",$pos);
		$pos2 = strpos($block[0],"\n",$pos1+1);
		$questGiver = trim(substr($block[0],$pos1+2,$pos2-$pos1-2));
		
		// minimum level
		$pos = strpos($block[0],"Minimum Level");
		$pos1 = strpos($block[0],"\n",$pos);
		$pos2 = strpos($block[0],"\n",$pos1+1);
		$minLevel = trim(substr($block[0],$pos1+2,$pos2-$pos1-2));
		
		// classes
		$pos = strpos($block[0],"Classes");
		$pos1 = strpos($block[0],"\n",$pos);
		$pos2 = strpos($block[0],"\n",$pos1+1);
		$classes = trim(substr($block[0],$pos1+2,$pos2-$pos1-2));
		
		// related zones
		$pos = strpos($block[0],"Related Zones");
		$pos1 = strpos($block[0],"\n",$pos);
		$pos2 = strpos($block[0],"\n",$pos1+1);
		$relZones = trim(substr($block[0],$pos1+2,$pos2-$pos1-2));
		
		// related NPCs
		$pos = strpos($block[0],"Related NPCs");
		$pos1 = strpos($block[0],"\n",$pos);
		$pos2 = strpos($block[0],"\n",$pos1+1);
		$relNPCs = trim(substr($block[0],$pos1+2,$pos2-$pos1-2));
		
		// reward
		$regex = "/Reward(.*?)<\/ul>/s";
		$ret = preg_match($regex, $templateText, $block);
		
		if (!$ret) {
			$reward = "?";
		} else {
		  $regex = "/<li>(.*?)<\/li>/s";
		  $ret = preg_match_all($regex, $block[0], $subBlock);
			
			$reward = "";
			foreach ($subBlock[1] as $rew) {
			  $reward .= trim($rew) . ", ";
			}
			$reward = substr($reward,0,strlen($reward)-2);
			//$reward = $subBlock[1][0];
		}
		
    $rowVals = array($reward,$questGiver,$minLevel,$classes,$relZones,$relNPCs);

		return $rowVals;
}

function parseNPCPage($templateText)
{
		$maxDescLen = 120;
		$maxNumLootItems = 4;

    $parms = DynamicZoneList::parseTemplateParameters($templateText);
		
    foreach ($parms as $parm) {
      if ( strpos($parm,"race") === 0) {
        $ePos = strpos($parm,"=");
        $race = trim(substr($parm,$ePos+1));
      }
      if ( strpos($parm,"class") === 0) {
        $ePos = strpos($parm,"=");
        $class = trim(substr($parm,$ePos+1));
      }
      if ( strpos($parm,"level") === 0) {
        $ePos = strpos($parm,"=");
        $level = trim(substr($parm,$ePos+1));
      }
      if ( strpos($parm,"location") === 0) {
        $ePos = strpos($parm,"=");
        $location = trim(substr($parm,$ePos+1));
      }
      if ( strpos($parm,"description") === 0) {
        $ePos = strpos($parm,"=");
        $desc = trim(substr($parm,$ePos+1));
      }
      if ( strpos($parm,"known_loot") === 0) {
        $ePos = strpos($parm,"=");
        $loot = trim(substr($parm,$ePos+1));
      }
    }
		
		// parse description
		if (strlen($desc) > $maxDescLen) {
		  $desc = substr($desc,0,$maxDescLen) . "...";
		}
		
		// parse loot
		if (strpos($loot,"None") !== False) {
		  $loot = "<span class='drare'>''None''</span>";
		} else {
		  $regex = "/<li>(.*?)<\/li>/s";
		  $ret = preg_match_all($regex, $loot, $subBlock);
			
			if ($ret) {
			  // if 3 or less items, make a comma separated list
			  if (count($subBlock[1]) > $maxNumLootItems) {
			    $loot = "Various";
				} else {
				  $loot = "";
					foreach ($subBlock[1] as $lt) {
					  // strip out additional rarity/db info from loot row
						if (strpos($lt,"<span class='ddb'>")) {
							$lt = substr($lt,0,strpos($lt,"<span class='ddb'>"));
						}
						if (strpos($lt,"<span class='drare'>")) {
							$lt = substr($lt,0,strpos($lt,"<span class='drare'>"));
						}
						
						// add to comma separated list
						$loot .= trim($lt) . ", ";
					}
					$loot = substr($loot,0,strlen($loot)-2);

				}
		  }
		}
		
		if ($class == "[[Shopkeeper]]") {
		  $loot = "<span class='drare'>''(Merchant)''</span>";
		}
		
		$rowVals = array($race,$class,$level,$location,$loot,$desc);
		
		return $rowVals;
}


function extractBalancedTemplate($text, $templateName)
{
	$pattern = '/\{\{\s*' . preg_quote($templateName, '/') . '\b/i';

	if (!preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
		return '';
	}

	$start = $match[0][1];
	$length = strlen($text);
	$depth = 0;

	for ($i = $start; $i < $length - 1; $i++) {
		$pair = substr($text, $i, 2);

		if ($pair === '{{') {
			$depth++;
			$i++;
			continue;
		}

		if ($pair === '}}') {
			$depth--;
			$i++;

			if ($depth === 0) {
				return substr($text, $start, $i + 1 - $start);
			}

			continue;
		}
	}

	return '';
}

function stripTemplateWrapper($templateText, $templateName)
{
	$templateText = preg_replace('/^\{\{\s*' . preg_quote($templateName, '/') . '\b/i', '', $templateText);
	$templateText = preg_replace('/\s*\}\}\s*$/', '', $templateText);

	return $templateText;
}

function extractTemplateParamByLine($templateText, $paramNames)
{
	foreach ($paramNames as $paramName) {
		$paramPattern = preg_quote($paramName, '/');

		if (preg_match(
			'/(?:^|\R)[ \t]*\|[ \t]*' . $paramPattern . '[ \t]*=[ \t]*(.*?)(?=\R[ \t]*\|[ \t]*[A-Za-z0-9_ ]+[ \t]*=|\z)/is',
			$templateText,
			$match
		)) {
			return trim(preg_replace('/<!--.*?-->/s', '', $match[1]));
		}
	}

	return '';
}

function parseWikiLinkTargetAndLabel($text)
{
	$text = trim($text);

	if (preg_match('/\[\[\s*([^|\]]+)\s*\|\s*([^\]]+)\s*\]\]/', $text, $match)) {
		return array(trim($match[1]), trim($match[2]));
	}

	if (preg_match('/\[\[\s*([^\]]+)\s*\]\]/', $text, $match)) {
		$value = trim($match[1]);
		return array($value, $value);
	}

	return array($text, $text);
}

function normalizeZoneComparison($text)
{
	$text = strtolower(trim($text));
	$text = str_replace('_', ' ', $text);
	$text = str_replace('’', "'", $text);
	$text = preg_replace('/\s+/', ' ', $text);

	$aliases = array(
		'the estate of unrest' => 'estate of unrest',
		'estate of unrest' => 'estate of unrest',
	);

	if (isset($aliases[$text])) {
		return $aliases[$text];
	}

	return $text;
}

function extractDropsForZone($dropsFrom, $zoneName, $maxNumNPCs)
{
	$zoneWanted = DynamicZoneList::normalizeZoneComparison($zoneName);
	$lines = preg_split("/\r\n|\n|\r/", (string)$dropsFrom);

	$capturing = false;
	$foundZone = false;
	$mobs = array();

	foreach ($lines as $line) {
		$trimmed = trim($line);

		if ($trimmed === '') {
			continue;
		}

		// A non-bullet wiki link line is treated as a zone heading.
		if (preg_match('/^\[\[[^\]]+\]\]\s*$/', $trimmed)) {
			list($target, $label) = DynamicZoneList::parseWikiLinkTargetAndLabel($trimmed);

			$zoneCandidates = array(
				DynamicZoneList::normalizeZoneComparison($target),
				DynamicZoneList::normalizeZoneComparison($label),
			);

			$capturing = in_array($zoneWanted, $zoneCandidates, true);
			$foundZone = $foundZone || $capturing;

			continue;
		}

		if ($capturing && preg_match('/^\*+\s*(.+?)\s*$/', $trimmed, $match)) {
			$mobs[] = trim($match[1]);
		}
	}

	if (!$foundZone) {
		return "<span style='color:green;'>None?</span>";
	}

	if (count($mobs) > $maxNumNPCs) {
		return "Various";
	}

	if (!$mobs) {
		return "<span style='color:green;'>None?</span>";
	}

	return implode(", ", $mobs);
}

function parseItemPage($templateText,$zoneName)
{
	$weapSlots = array("Primary",
	                   "Secondary",
	                   "Range",
	                   "Ammo",
	                   "1H Blunt",
	                   "2H Blunt",
	                   "1H Slashing",
	                   "2H Slashing",
	                   "Piercing",
	                   "Archery",
	                   "Hand to Hand");

	// defaults
	$npc   = "?";
	$slot  = "?";
	$stats = "?";

	// Remove out-of-era wrappers before parsing.
	$templateText = preg_replace("/\* {{VeliousGray\|(.*?)}}\n/","",$templateText);
	$templateText = preg_replace("/{{VeliousGray\|(.*?)}}\n/","",$templateText);

	/*
	 * The old implementation used parseTemplateParameters($templateText) on the
	 * whole page. That can accidentally read the hover {{Itembox}} instead of
	 * the real {{Itempage}}, because item pages contain both. Extract the
	 * balanced {{Itempage}} block first, then read its fields directly.
	 */
	$itempageText = DynamicZoneList::extractBalancedTemplate($templateText, 'Itempage');

	if ($itempageText === '') {
		$rowVals = array("<span style='color:green;'>None?</span>", "<span class='drare'>(None)</span>", "?");
		return $rowVals;
	}

	$itempageBody = DynamicZoneList::stripTemplateWrapper($itempageText, 'Itempage');

	$statsBlock = DynamicZoneList::extractTemplateParamByLine($itempageBody, array('statsblock', 'stats_block'));
	$dropsFrom  = DynamicZoneList::extractTemplateParamByLine($itempageBody, array('dropsfrom', 'drops_from'));

	// parse for slot
	$regex = "/Slot:(.*?)<br>\n/";
	$ret = preg_match($regex, $statsBlock, $block);

	if ($ret) {
		$slot = ucfirst(strtolower(trim($block[1])));

		if ($slot == "Finger") $slot = "Fingers";

		// split on space for multiple slots
		$slots = explode(" ",$slot);
		$slot = "";

		foreach ($slots as $addslot) {
			$addslot = ucfirst(strtolower(trim($addslot)));
			$slot .= "[[:Category:" . $addslot . "|" . $addslot . "]], ";
		}
		$slot = substr($slot,0,-2);
	} else {
		$slot = "<span class='drare'>(None)</span>";
	}

	// override slot with weapon type if applicable
	$weapTag = "";

	foreach ($weapSlots as $weapSlot) {
		if (strpos($statsBlock,"Skill: ".$weapSlot) !== false) {
			$slot = "[[:Category:" . $weapSlot . "|" . $weapSlot . "]]";
			$weapTag = $weapSlot;
		}
	}

	// create "stats" string
	$start = strpos($statsBlock,"Slot:");
	if ($start !== false) {
		$start = strpos($statsBlock,"<br>",$start);
		$statsBlock = substr($statsBlock,$start+5);
	}

	$end = strpos($statsBlock,"Slot ");
	if ($end)
		$statsBlock = substr($statsBlock,0,$end);

	if ($weapTag)
		$statsBlock = str_replace("Skill: ".$weapTag,"",$statsBlock);

	$statsBlock = str_replace("Any Slot/Can Equip, ","",$statsBlock);
	$statsBlock = str_replace("(Any Slot, Casting Time: Instant)","",$statsBlock);

	// replace newlines by spaces
	$stats = str_replace("<br>"," ",$statsBlock);
	$stats = str_replace("\n","",$stats);

	// tag replace with stylized tags
	$sTag = "'''";
	$eTag = "'''";

	$tags = array("AC:","Atk Delay:","DMG:","WT:","Size:","Class:","Race:",
	              "STR:","STA:","AGI:","DEX:","CHA:","INT:","WIS:","HP:","MANA:",
	              "SV MAGIC:","SV FIRE:","SV COLD:","SV POISON:","SV DISEASE:");

	foreach ($tags as $tag)
		$stats = str_replace($tag,$sTag.$tag.$eTag,$stats);

	// make list of mobs that drop this item in the current zone
	$maxNumNPCs = 3;
	$npc = DynamicZoneList::extractDropsForZone($dropsFrom, $zoneName, $maxNumNPCs);

	$rowVals = array($npc,$slot,$stats);

	return $rowVals;
}


function execute( $par )
{
  global $wgRequest, $wgOut, $wgUser, $wgParser, $wgTitle;

	$zoneNameList = array(// ANTONICA
                  "Befallen",
                  "Blackburrow",
                  "Cazic Thule",
                  "Clan Runnyeye",
                  "East Commonlands",
                  "East Freeport",
                  "Eastern Plains of Karana",
                  "Erud's Crossing",
                  "Everfrost Peaks",
                  "Beholder's Maze",
                  "Grobb",
                  "Halas",
                  "Highpass Keep",
                  "Highpass Hold",
                  "Innothule Swamp",
                  "Kithicor Forest",
                  "Splitpaw Lair",
                  "Lake Rathetear",
                  "Lavastorm Mountains",
                  "Lower Guk",
                  "Misty Thicket",
                  "Rathe Mountains",
                  "Nagafen's Lair",
                  "Najena",
                  "Nektulos Forest",
                  "Neriak Commons",
                  "Neriak Foreign Quarter",
                  "Neriak Third Gate",
                  "New Sebilis Expedition",
                  "North Freeport",
                  "Northern Karana",
                  "North Qeynos",
                  "Northern Desert of Ro",
                  "Oasis of Marr",
                  "Ocean of Tears",
                  "Oggok",
                  "Permafrost",
                  "Qeynos Aqueducts",
                  "Qeynos Hills",
                  "Rivervale",
                  "Solusek's Eye",
                  "Southern Karana",
                  "South Qeynos",
                  "Southern Desert of Ro",
                  "Surefall Glade",
                  "Temple of Solusek Ro",
                  "The Feerrott",
                  "Upper Guk",
                  "West Commonlands",
                  "West Freeport",
                  "Western Plains of Karana",
                  
                  // ODUS
                  "Erudin",
                  "Erudin Palace",
                  "Kerra Island",
                  "Paineel",
                  "Toxxulia Forest",
                  
                  // FAYDWER
                  "Ak'Anon",
                  "Butcherblock Mountains",
                  "Mistmoore Castle",
                  "Crushbone",
                  "Dagnor's Cauldron",
                  "The Estate of Unrest",
                  "Greater Faydark",
                  "Kedge Keep",
                  "Lesser Faydark",
                  "North Kaladim",
                  "Northern Felwithe",
                  "South Kaladim",
                  "Southern Felwithe",
                  "Steamfont Mountains",
                  
                  // PLANES
                  "Plane of Fear",
                  "Plane of Hate",
                  "Plane of Sky",
									
									// KUNARK
									"Burning Woods",
                  "Chardok",
                  "City of Mist",
                  "Crypt of Dalnir",
                  "Dreadlands",
                  "East Cabilis",
                  "Emerald Jungle",
                  "Field of Bone",
                  "Firiona Vie",
                  "Frontier Mountains",
                  "Howling Stones",
                  "Kaesora",
                  "Karnor's Castle",
                  "Kurn's Tower",
                  "Lake of Ill Omen",
                  "Mines of Nurga",
                  "Old Sebilis",
                  "Skyfire Mountains",
                  "Swamp of No Hope",
                  "Temple of Droga",
                  "The Overthere",
                  "Timorous Deep",
                  "Trakanon's Teeth",
                  "Veeshan's Peak",
                  "Warsliks Woods",
                  "West Cabilis",
                  "The Hole", // Odus, post-Kunark

									// VELIOUS
									"Cobalt Scar",
                  "Crystal Caverns",
                  "Dragon Necropolis",
                  "Eastern Wastes",
                  "Great Divide",
                  "Iceclad Ocean",
                  "Icewell Keep",
                  "Kael Drakkel",
                  "Kerafyrm's Lair",
                  "Plane of Growth",
                  "Plane of Mischief",
                  "Siren's Grotto",
                  "Skyshrine",
									"Sleeper's Tomb",
                  "Temple of Veeshan",
                  "The Wakening Land",
                  "Thurgadin",
                  "Tower of Frozen Shadow",
                  "Velketor's Labyrinth",
                  "Western Wastes",
                  "Stonebrunt Mountains", // Odus, post-Velious
                  "The Warrens", // Odus, post-velious
                  "Jaggedpine Forest" //Antonica, post-velious
                  );	
	
	ini_set('display_errors', 1);
	error_reporting(E_ERROR | E_PARSE); //E_WARNING
	
	// config
	
  $parser = \MediaWiki\MediaWikiServices::getInstance()->getParserFactory()->create();
  $db = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
	
  // parse and sanitize zone name
  $zoneName = trim($par);
	//$zoneName = mysql_real_escape_string($zoneName);
	if (strlen($zoneName) > 40) { $zoneName = substr($zoneName,0,40); }
	$zoneName = str_replace("_"," ",$zoneName);
	
	// default page
	// ------------
  if (!$zoneName) {
	  $output = "<span>Hello! This extension is meant to be transcluded into zone pages. <br><br>It takes one parameter (the name of the zone).</span>";

		$wgOut->setPagetitle("EQLegends Dynamic Zone List");
    $wgOut->addHTML($output);
    return;
  }
	
  if (!in_array($zoneName,$zoneNameList)) {
	  $output = "'''DynamicZoneList: Invalid zone name!'''";
    $wgOut->addWikiTextAsContent($output);
    return;
  }	
	
	// DB search for pages
	// -------------------
  $catNames = array($zoneName,"Quests");
	$quests = DynamicZoneList::getCatIntersection($db,$parser,$catNames);
	
  $catNames = array($zoneName,"NPCs");
	$NPCs = DynamicZoneList::getCatIntersection($db,$parser,$catNames);
	
  $catNames = array($zoneName,"Items");
	$items = DynamicZoneList::getCatIntersection($db,$parser,$catNames);

	// output header
	// ------
	$output = "";
	
	// output quests
	// -------------
	if (count($quests))
	{
		$output .= "'''Quests''' - ''Found " . count($quests) . " quests that start in " . $zoneName . ":''";
		
		//table header
		$output .= "\n<table class='eoTable3 sortable' style='width:100%;'>";
		$output .= "\n<tr><th>Quest Name</th>";
		$output .=       "<th>Reward</th>" . 
										 "<th>Quest Giver</th>" . 	
										 "<th>Minimum Level</th>" . 
										 "<th>Classes</th>" . 
										 "<th>Related Zones</th>" . 
										 "<th>Related NPCs</th>" . 							 
										 "</tr>";
	 
		foreach ( $quests as $row )
		{
			$title = Title::makeTitle( $row->page_namespace, $row->page_title);
			$titleText = $title->getText();
			
			if ($titleText == "Faction")
			  continue;

			// get quest header block
			$tTitle = Title::newFromText( $titleText );
			$wikiPage = \MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $tTitle );
			$contentObj = $wikiPage->getContent();
			$templateText = $contentObj ? $contentObj->getText() : '';
			//$templateText = preg_replace( '/<!--.*?-->/s', '', $parser->fetchTemplate( $tTitle ) );

			$rowVals = DynamicZoneList::parseQuestPage($templateText);
			
			// output row
			$output = $output . "\n<tr><td> [[" . $titleText . "]]\n</td>";
			for ($i=0; $i < count($rowVals); $i++)
			{
				$output = $output . "\n    <td> " . $rowVals[$i] . " </td>";
			}
			$output .= "</tr>\n";
		}

		$output .= "</table>\n";
	} else {
		//$output .= "''\nDidn't find any quests that start in " . $zoneName . "!''";
	}
	
	// output NPCs
	// -----------
	if (count($NPCs))
	{
		$output .= "\n\n'''NPCs''' - ''Found " . count($NPCs) . " NPCs that spawn in " . $zoneName . ":''";
		
		//table header
		$output .= "\n<table class='eoTable3 sortable' style='width:100%;'>";
		$output .= "\n<tr><th>NPC Name</th>";
		$output .=       "<th>Race</th>" . 
										 "<th>Class</th>" . 	
										 "<th>Level</th>" . 
										 "<th>Location</th>" . 
										 "<th>Known Loot</th>" . 
										 "<th>Description</th>" . 							 
										 "</tr>";
	 
		foreach ( $NPCs as $row )
		{
			$title = Title::makeTitle( $row->page_namespace, $row->page_title);
			$titleText = $title->getText();

			// get quest header block
			$tTitle = Title::newFromText( $titleText );
			$wikiPage = \MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $tTitle );
			$contentObj = $wikiPage->getContent();
			$templateText = $contentObj ? $contentObj->getText() : '';
			//$templateText = preg_replace( '/<!--.*?-->/s', '', $parser->fetchTemplate( $tTitle ) );

			$rowVals = DynamicZoneList::parseNPCPage($templateText);
			
			// output row
			$output = $output . "\n<tr><td> [[" . $titleText . "]]\n</td>";
			for ($i=0; $i < count($rowVals); $i++)
			{
				$output = $output . "\n    <td> " . $rowVals[$i] . " </td>";
			}
			$output .= "</tr>\n";
		}

		$output .= "</table>\n";
	} else {
		//$output .= "''\nDidn't find any NPCs that spawn in " . $zoneName . "!''";
	}
	
	// output items
	// ------------
	if (count($items))
	{
		$output .= "\n\n'''Items''' - ''Found " . count($items) . " items that drop in " . $zoneName . ":''";
		
		//table header
		$output .= "\n<table class='eoTable3 sortable' style='width:100%;'>";
		$output .= "\n<tr><th>Item Name</th>";
		$output .=       "<th>Drops From</th>" . 
										 "<th>Slot</th>" . 	
										 "<th>Stats</th>" . 						 
										 "</tr>";
	 
		foreach ( $items as $row )
		{
			$title = Title::makeTitle( $row->page_namespace, $row->page_title);
			$titleText = $title->getText();

			// get quest header block
			$tTitle = Title::newFromText( $titleText );
			$wikiPage = \MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $tTitle );
			$contentObj = $wikiPage->getContent();
			$templateText = $contentObj ? $contentObj->getText() : '';
			//$templateText = preg_replace( '/<!--.*?-->/s', '', $parser->fetchTemplate( $tTitle ) );

			$rowVals = DynamicZoneList::parseItemPage($templateText,$zoneName);
			
			// output row
			$output = $output . "\n<tr><td> {{:" . $titleText . "}}\n</td>";
			for ($i=0; $i < count($rowVals); $i++)
			{
				$output = $output . "\n    <td> " . $rowVals[$i] . " </td>";
			}
			$output .= "</tr>\n";
		}

		$output .= "</table>\n";
	} else {
		//$output .= "\n''Didn't find any items that drop in " . $zoneName . "!''";
	}	
	
	// output footer
  $output .= "";
	
	// fix UNIQ problem
  $pOptions = \MediaWiki\Parser\ParserOptions::newFromAnon();
  $result = $parser->parse($output, $wgTitle, $pOptions);
  $wgOut->addHTML($result->getContentHolderText());	
	
	//$wgOut->addWikiTextAsContent($output);

}

}
