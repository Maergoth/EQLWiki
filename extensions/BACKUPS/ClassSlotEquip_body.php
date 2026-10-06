<?php

/*************************************************

eqlwiki - extensions (ClassSlotEquip)
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

class ClassSlotEquip extends SpecialPage {

	function __construct() {
		parent::__construct( "ClassSlotEquip" );
	}

	function pageLooksLikeItemPage( $templateText ) {
		return preg_match( '/\{\{\s*(Itempage|Itembox)\b/i', $templateText ) === 1;
	}

	function hasEraTemplate( $templateText, $eraTemplateName ) {
		return preg_match(
			'/\{\{\s*' . preg_quote( $eraTemplateName, '/' ) . '\s*(?:\||\}\})/i',
			$templateText
		) === 1;
	}

	function extractFirstWikiLink( $text ) {
		if ( preg_match( '/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/', $text, $match ) ) {
			$target = trim( $match[1] );
			$label = isset( $match[2] ) ? trim( $match[2] ) : '';

			if ( $target === '' ) {
				return '';
			}

			if ( $label !== '' ) {
				return '[[' . $target . '|' . $label . ']]';
			}

			return '[[' . $target . ']]';
		}

		return '';
	}

	function cleanDropsFromText( $text ) {
		$text = trim( (string)$text );

		if ( $text === '' ) {
			return '';
		}

		$link = $this->extractFirstWikiLink( $text );

		if ( $link !== '' ) {
			return $link;
		}

		$text = preg_replace( '/<!--.*?-->/s', '', $text );
		$text = preg_replace( '/<[^>]+>/', '', $text );
		$text = preg_replace( '/\{\{[^{}]*\}\}/', '', $text );
		$text = str_replace( array( "\r", "\n", '*', '#', ';' ), ' ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		if ( strlen( $text ) > 80 ) {
			$text = substr( $text, 0, 77 ) . '...';
		}

		return $text;
	}

	function extractFirstBulletedDropsFromItem( $dropsFrom ) {
		$dropsFrom = trim( (string)$dropsFrom );

		if ( $dropsFrom === '' ) {
			return '';
		}

		// HTML list form: <li>[[Mob Name]]</li>
		if ( preg_match( '/<li[^>]*>\s*(.*?)\s*<\/li>/is', $dropsFrom, $match ) ) {
			return $this->cleanDropsFromText( $match[1] );
		}

		// Wikitext list form: * [[Mob Name]]
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $dropsFrom );
		$lines = explode( "\n", $normalized );

		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*[*#]\s*(.+?)\s*$/', $line, $match ) ) {
				$item = $this->cleanDropsFromText( $match[1] );

				if ( $item !== '' ) {
					return $item;
				}
			}
		}

		return '';
	}

	function formatDropsFromValue( $dropsFrom, $playerCrafted, $questOrigin, $purchasedFrom ) {
		$dropsFrom = trim( (string)$dropsFrom );

		if ( $dropsFrom !== '' ) {
			// Prefer the first actual bullet/list item, not the zone heading.
			$firstBullet = $this->extractFirstBulletedDropsFromItem( $dropsFrom );

			if ( $firstBullet !== '' ) {
				return $firstBullet;
			}

			// Fallback for simple dropsfrom values that only contain a zone/link.
			$clean = $this->cleanDropsFromText( $dropsFrom );

			if ( $clean !== '' ) {
				return $clean;
			}
		}

		if ( $playerCrafted !== '' ) {
			return $playerCrafted;
		}

		if ( $questOrigin !== '' ) {
			return $questOrigin;
		}

		if ( $purchasedFrom !== '' ) {
			return $purchasedFrom;
		}

		return '';
	}

	function parseStatsBlock( $statsBlock, $dropsFrom, $playerCrafted, $questOrigin, $purchasedFrom, $slot ) {
		$weapSlots = array(
			"Primary",
			"Seconary",
			"Range",
			"Ammo",
			"1H Blunt",
			"2H Blunt",
			"1H Slashing",
			"2H Slashing",
			"Piercing",
			"Archery",
			"Hand to Hand"
		);

		$vals = array();

		if ( in_array( $slot, $weapSlots ) ) {
			if ( preg_match( '/DMG:\s*(\d+)/i', $statsBlock, $regMatch ) ) {
				$t1 = $regMatch[1];
			} else {
				$t1 = '';
			}

			if ( preg_match( '/Atk Delay:\s*(\d+)/i', $statsBlock, $regMatch ) ) {
				$t2 = $regMatch[1];
			} else {
				$t2 = '';
			}

			if ( $t1 && $t2 ) {
				$vals[] = "<span class='ddb'>(" . sprintf( "%4.2f", $t1 / $t2 ) . ") </span> " . $t1 . " / " . $t2;
			} else {
				$vals[] = '';
			}
		}

		// Drops From / source
		$vals[] = $this->formatDropsFromValue(
			$dropsFrom,
			$playerCrafted,
			$questOrigin,
			$purchasedFrom
		);

		if ( preg_match( '/AC:\s*\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/WT:\s*\d+(?:\.\d+)?/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/STR:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/STA:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/AGI:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/DEX:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/CHA:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/INT:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/WIS:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/HP:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/MANA:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/SV MAGIC:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/SV FIRE:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/SV COLD:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/SV POISON:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		if ( preg_match( '/SV DISEASE:\s*[+\-]?\d+/i', $statsBlock, $regMatch ) ) {
			$vals[] = trim( substr( $regMatch[0], strpos( $regMatch[0], ":" ) + 1 ) );
		} else {
			$vals[] = '';
		}

		return $vals;
	}

	function findTemplateStart( $templateText, $templateName ) {
		if ( preg_match( '/\{\{\s*' . preg_quote( $templateName, '/' ) . '\b/i', $templateText, $match, PREG_OFFSET_CAPTURE ) ) {
			return $match[0][1];
		}

		return false;
	}

	function parseTemplateParameters( $templateText ) {
		/*
		 * IMPORTANT:
		 * Prefer {{Itempage}} over {{Itembox}}.
		 *
		 * Most item pages have an <onlyinclude> hover {{Itembox}} first,
		 * followed by the full {{Itempage}} later. The hover Itembox usually
		 * does not contain dropsfrom, playercrafted, relatedquests, etc.
		 *
		 * If we parse the first Itembox, Drops From becomes blank or falls
		 * back to Quested/Player Crafted/Purchased. That is the bug.
		 */
		$itempagePos = $this->findTemplateStart( $templateText, 'Itempage' );
		$itemboxPos = $this->findTemplateStart( $templateText, 'Itembox' );

		if ( $itempagePos !== false ) {
			$templateText = substr( $templateText, $itempagePos + 2 );
		} else if ( $itemboxPos !== false ) {
			$templateText = substr( $templateText, $itemboxPos + 2 );
		}

		$cbrackets = 2;
		$size = strlen( $templateText );
		$parms = array();
		$parm = '';
		$hasParm = false;

		for ( $i = 0; $i < $size; $i++ ) {
			$c = $templateText[$i];

			if ( $c == '{' || $c == '[' ) {
				$cbrackets++;
			}

			if ( $c == '}' || $c == ']' ) {
				$cbrackets--;
			}

			if ( $cbrackets == 2 && $c == '|' ) {
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
				break;
			}
		}

		return $parms;
	}

	function execute( $par ) {
		global $wgOut;

		$classNames = array(
			'BRD' => 'Bard',
			'CLR' => 'Cleric',
			'DRU' => 'Druid',
			'ENC' => 'Enchanter',
			'MAG' => 'Magician',
			'MNK' => 'Monk',
			'NEC' => 'Necromancer',
			'PAL' => 'Paladin',
			'RNG' => 'Ranger',
			'ROG' => 'Rogue',
			'SHM' => 'Shaman',
			'SHD' => 'Shadow Knight',
			'WAR' => 'Warrior',
			'WIZ' => 'Wizard'
		);

		$slotNames = array(
			'ARMS'      => 'Arms',
			'BACK'      => 'Back',
			'CHEST'     => 'Chest',
			'EAR'       => 'Ear',
			'FACE'      => 'Face',
			'FEET'      => 'Feet',
			'FINGER'    => 'Fingers',
			'HANDS'     => 'Hands',
			'HEAD'      => 'Head',
			'LEGS'      => 'Legs',
			'NECK'      => 'Neck',
			'SHOULDERS' => 'Shoulders',
			'WAIST'     => 'Waist',
			'WRIST'     => 'Wrist',
			'PRIMARY'   => 'Primary',
			'SECONDARY' => 'Secondary',
			'RANGE'     => 'Range',
			'AMMO'      => 'Ammo',
			'INST'      => 'Bard Instrument'
		);

		$weapSlots = array(
			"Primary",
			"Seconary",
			"Range",
			"Ammo",
			"1H Blunt",
			"2H Blunt",
			"1H Slashing",
			"2H Slashing",
			"Piercing",
			"Archery",
			"Hand to Hand"
		);

		list( $class, $slot, $era ) = array_pad( explode( "/", $par, 3 ), 3, '' );

		$class = str_replace( "_", " ", $class );
		$slot  = str_replace( "_", " ", $slot );
		$era   = str_replace( "_", " ", $era );

		if (
			!$class ||
			!$slot ||
			!in_array( $class, $classNames ) ||
			( !in_array( $slot, $slotNames ) && !in_array( $slot, $weapSlots ) )
		) {
			$wgOut->setPagetitle( "Class Slot Equipment List" );
			$wgOut->addWikiTextAsContent( "Error! Didn't recognize the class/slot combination, sorry." );
			return;
		}

		if ( $era != "" && $era != "PreVelOnly" && $era != "VelOnly" && $era != "AllItems" ) {
			$wgOut->setPagetitle( "Class Slot Equipment List" );
			$wgOut->addWikiTextAsContent( "Error! Didn't recognize the era selection, sorry." );
			return;
		}

		$catNames = array( $class . " Equipment", $slot );
		$aCategories = array();

		foreach ( $catNames as $catName ) {
			$title = Title::newFromText( $catName );

			if ( is_null( $title ) ) {
				$wgOut->addWikiTextAsContent( "Error! 8602" );
				return;
			}

			$aCategories[] = $title;
		}

		$this->setHeaders();
		$wgOut->setPagetitle( "$class :: $slot" );

		$db = \MediaWiki\MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_REPLICA );

		$aTables = array( 'page' );
		$aFields = array( 'page_namespace', 'page_title' );
		$aWhere = array();
		$aJoin = array();
		$aOptions = array();

		$aOptions['ORDER BY'] = "page_title ASC";

		$iCurrentTableNumber = 1;

		for ( $i = 0; $i < count( $aCategories ); $i++ ) {
			$tAlias = "c{$iCurrentTableNumber}";
			$ltAlias = "lt{$iCurrentTableNumber}";

			$aTables[$tAlias] = 'categorylinks';
			$aTables[$ltAlias] = 'linktarget';

			$aJoin[$tAlias] = array(
				'INNER JOIN',
				array( "page_id = {$tAlias}.cl_from" )
			);

			$aJoin[$ltAlias] = array(
				'INNER JOIN',
				array(
					"{$tAlias}.cl_target_id = {$ltAlias}.lt_id",
					"{$ltAlias}.lt_namespace = 14",
					"{$ltAlias}.lt_title = {$db->addQuotes( $aCategories[$i]->getDBKey() )}"
				)
			);

			$iCurrentTableNumber++;
		}

		$res = $db->select( $aTables, $aFields, $aWhere, __METHOD__, $aOptions, $aJoin );
		$rows = iterator_to_array( $res, false );

		$outputRows = array();

		foreach ( $rows as $row ) {
			$title = Title::makeTitle( $row->page_namespace, $row->page_title );
			$titleText = $title->getText();

			$tTitle = Title::newFromText( $titleText );

			if ( !$tTitle ) {
				continue;
			}

			$wikiPage = \MediaWiki\MediaWikiServices::getInstance()
				->getWikiPageFactory()
				->newFromTitle( $tTitle );

			$content = $wikiPage->getContent();

			if ( $content && method_exists( $content, 'getText' ) ) {
				$templateText = $content->getText();
			} else {
				$templateText = '';
			}

			if ( !$this->pageLooksLikeItemPage( $templateText ) ) {
				continue;
			}

			if ( $era == "" || $era == "PreVelOnly" ) {
				if (
					$this->hasEraTemplate( $templateText, "Velious Era" ) ||
					$this->hasEraTemplate( $templateText, "WarrensFearHateRevamp Era" ) ||
					$this->hasEraTemplate( $templateText, "StonebruntChardokRevamp Era" )
				) {
					continue;
				}
			} else if ( $era == "VelOnly" ) {
				if (
					!$this->hasEraTemplate( $templateText, "Velious Era" ) &&
					!$this->hasEraTemplate( $templateText, "WarrensFearHateRevamp Era" ) &&
					!$this->hasEraTemplate( $templateText, "StonebruntChardokRevamp Era" )
				) {
					continue;
				}
			}

			$statsBlock = '';
			$dropsFrom  = '';
			$playerCrafted = '';
			$questOrigin   = '';
			$purchasedFrom = '';

			$parms = $this->parseTemplateParameters( $templateText );

			foreach ( $parms as $parm ) {
				$ePos = strpos( $parm, "=" );

				if ( $ePos === false ) {
					continue;
				}

				$key = strtolower( trim( substr( $parm, 0, $ePos ) ) );
				$value = trim( substr( $parm, $ePos + 1 ) );

				if ( $key === "statsblock" ) {
					$statsBlock = $value;
				} else if ( $key === "dropsfrom" || $key === "drops_from" ) {
					$dropsFrom = $value;
				}
			}

			if ( preg_match( '/\|\s*playercrafted\b/i', $templateText ) ) {
				$playerCrafted = "Player Crafted";
			}

			if ( preg_match( '/\|\s*relatedquests\b/i', $templateText ) ) {
				$questOrigin = "Quested";
			}

			if ( preg_match( '/\|\s*soldby\b/i', $templateText ) ) {
				$purchasedFrom = "Purchased";
			}

			$rowVals = $this->parseStatsBlock(
				$statsBlock,
				$dropsFrom,
				$playerCrafted,
				$questOrigin,
				$purchasedFrom,
				$slot
			);

			$rowOutput = "\n<tr><td>{{:" . $titleText . "}}\n</td>";

			for ( $i = 0; $i < count( $rowVals ); $i++ ) {
				$rowOutput .= "\n    <td> " . $rowVals[$i] . " </td>";
			}

			$rowOutput .= "</tr>\n";

			$outputRows[] = $rowOutput;
		}

		$rowCount = count( $outputRows );

		if ( $rowCount == 0 ) {
			$wgOut->addWikiTextAsContent( "No items found." );
			return;
		}

		$wgOut->addWikiTextAsContent(
			"Showing all [[:Category:$class Equipment|$class Equipment]]" .
			" for the [[:Category:$slot|$slot]] slot (" . $rowCount . " items found)."
		);

		if ( $era == "" || $era == "PreVelOnly" ) {
			$wgOut->addWikiTextAsContent(
				"Currently listing '''pre-Velious items only.''' " .
				" (switch to [[Special:ClassSlotEquip/$class/$slot/VelOnly|Velious Only]] or [[Special:ClassSlotEquip/$class/$slot/AllItems|all eras]]?)"
			);
		} else if ( $era == "VelOnly" ) {
			$wgOut->addWikiTextAsContent(
				"Currently listing '''Velious era items only.'''" .
				" (switch to [[Special:ClassSlotEquip/$class/$slot/PreVelOnly|pre-Velious Only]] or [[Special:ClassSlotEquip/$class/$slot/AllItems|all eras]]?)"
			);
		} else if ( $era == "AllItems" ) {
			$wgOut->addWikiTextAsContent(
				"Currently listing '''items of all eras.'''" .
				" (switch to [[Special:ClassSlotEquip/$class/$slot/PreVelOnly|pre-Velious Only]] or [[Special:ClassSlotEquip/$class/$slot/VelOnly|Velious Only]]?)"
			);
		}

		$output = "\n<table class='eoTable sortable'>";
		$output .= "\n<tr><th>Name</th>";

		if ( in_array( $slot, $weapSlots ) ) {
			$output .= "<th>Damage/Delay</th>";
		}

		$output .=
			"<th>Drops From</th>" .
			"<th>AC</th>" .
			"<th>WT</th>" .
			"<th>STR</th>" .
			"<th>STA</th>" .
			"<th>AGI</th>" .
			"<th>DEX</th>" .
			"<th>CHA</th>" .
			"<th>INT</th>" .
			"<th>WIS</th>" .
			"<th>HP</th>" .
			"<th>MANA</th>" .
			"<th>MR</th>" .
			"<th>FR</th>" .
			"<th>CR</th>" .
			"<th>PR</th>" .
			"<th>DR</th>" .
			"</tr>";

		$output .= implode( '', $outputRows );
		$output .= "</table>\n";

		$wgOut->addWikiTextAsContent( $output );
	}
}