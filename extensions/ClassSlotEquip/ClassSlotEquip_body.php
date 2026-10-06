<?php

/*************************************************

eqlwiki - extensions (ClassSlotEquip)
Copyright (C) 2013 Dylan Nelson (dnelson@destinati.com)
Version: 0.9

*************************************************/

use MediaWiki\Title\Title;

class ClassSlotEquip extends SpecialPage {

	private $classNames = array(
		'BRD' => 'Bard',
		'BST' => 'Beastlord',
		'BER' => 'Berserker',
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

	private $slotNames = array(
		'ANY'        => 'Any',
		'ARMS'       => 'Arms',
		'BACK'       => 'Back',
		'CHEST'      => 'Chest',
		'EAR'        => 'Ear',
		'FACE'       => 'Face',
		'FEET'       => 'Feet',
		'FINGER'     => 'Fingers',
		'HANDS'      => 'Hands',
		'HEAD'       => 'Head',
		'LEGS'       => 'Legs',
		'NECK'       => 'Neck',
		'SHOULDERS'  => 'Shoulders',
		'WAIST'      => 'Waist',
		'WRIST'      => 'Wrist',
		'ONEHANDED'  => 'One-Handed',
		'TWOHANDED'  => 'Two-Handed',
		'PRIMARY'    => 'Primary',
		'SECONDARY'  => 'Secondary',
		'RANGE'      => 'Range',
		'AMMO'       => 'Ammo',
		'INST'       => 'Bard Instrument'
	);

	private $weaponSlots = array(
		'One-Handed',
		'Two-Handed',
		'Primary',
		'Secondary',
		'Seconary',
		'Range',
		'Ammo',
		'1H Blunt',
		'2H Blunt',
		'1H Slashing',
		'2H Slashing',
		'Piercing',
		'2H Piercing',
		'Archery',
		'Hand to Hand'
	);

	private $statPatterns = array(
		'AC' => '/\bAC:\s*\d+/i',
		'WT' => '/\bWT:\s*\d+(?:\.\d+)?/i',
		'STR' => '/\bSTR:\s*[+\-]?\d+/i',
		'STA' => '/\bSTA:\s*[+\-]?\d+/i',
		'AGI' => '/\bAGI:\s*[+\-]?\d+/i',
		'DEX' => '/\bDEX:\s*[+\-]?\d+/i',
		'CHA' => '/\bCHA:\s*[+\-]?\d+/i',
		'INT' => '/\bINT:\s*[+\-]?\d+/i',
		'WIS' => '/\bWIS:\s*[+\-]?\d+/i',
		'HP' => '/\bHP:\s*[+\-]?\d+/i',
		'MANA' => '/\bMANA:\s*[+\-]?\d+/i',
		'END' => '/\bEND(?:UR(?:ANCE)?)?:\s*[+\-]?\d+/i',
		'MR' => '/\bSV MAGIC:\s*[+\-]?\d+/i',
		'FR' => '/\bSV FIRE:\s*[+\-]?\d+/i',
		'CR' => '/\bSV COLD:\s*[+\-]?\d+/i',
		'PR' => '/\bSV POISON:\s*[+\-]?\d+/i',
		'DR' => '/\bSV DISEASE:\s*[+\-]?\d+/i',
	);

	private $statLabels = array(
		'EFFECT' => 'Effect',
		'AC' => 'AC',
		'STR' => 'Strength',
		'STA' => 'Stamina',
		'AGI' => 'Agility',
		'DEX' => 'Dexterity',
		'CHA' => 'Charisma',
		'INT' => 'Intelligence',
		'WIS' => 'Wisdom',
		'HP' => 'HP',
		'MANA' => 'Mana',
		'END' => 'Endurance',
		'MR' => 'Magic Resist',
		'FR' => 'Fire Resist',
		'CR' => 'Cold Resist',
		'PR' => 'Poison Resist',
		'DR' => 'Disease Resist',
	);

	private $weaponTypeLabels = array(
		'1H Slashing' => '1H Slash',
		'1H Blunt' => '1H Blunt',
		'1H Piercing' => '1H Piercing',
		'2H Slashing' => '2H Slash',
		'2H Blunt' => '2H Blunt',
		'2H Piercing' => '2H Piercing',
	);

	private $effectPageInfoCache = array();

	function __construct() {
		parent::__construct( 'ClassSlotEquip' );
	}

	private function normalizeClassName( $class ) {
		$class = trim( str_replace( '_', ' ', (string)$class ) );

		foreach ( $this->classNames as $abbr => $name ) {
			if ( strcasecmp( $class, $abbr ) === 0 || strcasecmp( $class, $name ) === 0 ) {
				return $name;
			}
		}

		return '';
	}

	private function normalizeSlotName( $slot ) {
		$slot = trim( str_replace( '_', ' ', (string)$slot ) );
		$slotKey = strtolower( preg_replace( '/[\s\-]+/', ' ', $slot ) );

		if ( $slotKey === 'one handed' ) {
			return 'One-Handed';
		}

		if ( $slotKey === 'two handed' ) {
			return 'Two-Handed';
		}

		if ( $slotKey === '1h piercing' ) {
			return 'Piercing';
		}

		foreach ( $this->slotNames as $abbr => $name ) {
			if ( strcasecmp( $slot, $abbr ) === 0 || strcasecmp( $slot, $name ) === 0 ) {
				return $name;
			}
		}

		foreach ( $this->weaponSlots as $name ) {
			if ( strcasecmp( $slot, $name ) === 0 ) {
				return $name;
			}
		}

		return '';
	}

	private function normalizeStatName( $stat ) {
		$stat = strtolower( trim( str_replace( array( '_', '-' ), ' ', (string)$stat ) ) );
		$stat = preg_replace( '/\s+/', ' ', $stat );

		if ( $stat === '' || $stat === 'all' || $stat === 'any' || $stat === 'any stat' ) {
			return '';
		}

		$aliases = array(
			'effect' => 'EFFECT',
			'effects' => 'EFFECT',
			'ac' => 'AC',
			'armor class' => 'AC',
			'str' => 'STR',
			'strength' => 'STR',
			'sta' => 'STA',
			'stamina' => 'STA',
			'agi' => 'AGI',
			'agility' => 'AGI',
			'dex' => 'DEX',
			'dexterity' => 'DEX',
			'cha' => 'CHA',
			'charisma' => 'CHA',
			'int' => 'INT',
			'intelligence' => 'INT',
			'wis' => 'WIS',
			'wisdom' => 'WIS',
			'hp' => 'HP',
			'health' => 'HP',
			'mana' => 'MANA',
			'end' => 'END',
			'endurance' => 'END',
			'mr' => 'MR',
			'magic resist' => 'MR',
			'fr' => 'FR',
			'fire resist' => 'FR',
			'cr' => 'CR',
			'cold resist' => 'CR',
			'pr' => 'PR',
			'poison resist' => 'PR',
			'dr' => 'DR',
			'disease resist' => 'DR',
		);

		return isset( $aliases[$stat] ) ? $aliases[$stat] : '';
	}

	private function normalizeWeaponTypeName( $weaponType ) {
		$weaponType = strtolower( trim( str_replace( array( '_', '-' ), ' ', (string)$weaponType ) ) );
		$weaponType = preg_replace( '/\s+/', ' ', $weaponType );

		if (
			$weaponType === '' ||
			$weaponType === 'all' ||
			$weaponType === 'any' ||
			$weaponType === 'any damage type'
		) {
			return '';
		}

		$aliases = array(
			'1h slash' => '1H Slashing',
			'1h slashing' => '1H Slashing',
			'one handed slash' => '1H Slashing',
			'one handed slashing' => '1H Slashing',
			'1h blunt' => '1H Blunt',
			'one handed blunt' => '1H Blunt',
			'piercing' => '1H Piercing',
			'1h piercing' => '1H Piercing',
			'one handed piercing' => '1H Piercing',
			'2h slash' => '2H Slashing',
			'2h slashing' => '2H Slashing',
			'two handed slash' => '2H Slashing',
			'two handed slashing' => '2H Slashing',
			'2h blunt' => '2H Blunt',
			'two handed blunt' => '2H Blunt',
			'2h piercing' => '2H Piercing',
			'two handed piercing' => '2H Piercing',
		);

		return isset( $aliases[$weaponType] ) ? $aliases[$weaponType] : '';
	}

	private function getAllowedWeaponTypesForSlot( $slot ) {
		if ( $slot === 'One-Handed' ) {
			return array( '1H Slashing', '1H Blunt', '1H Piercing' );
		}

		if ( $slot === 'Two-Handed' ) {
			return array( '2H Slashing', '2H Blunt', '2H Piercing' );
		}

		if ( $slot === 'Primary' || $slot === 'Secondary' ) {
			return array_keys( $this->weaponTypeLabels );
		}

		return array();
	}

	private function shouldShowDamageColumn( $slot ) {
		return $slot === 'Any' || in_array( $slot, $this->weaponSlots, true );
	}

	private function itemMatchesWeaponFilters( $slot, $requestedWeaponType, $itemWeaponType ) {
		if (
			$slot === 'One-Handed' &&
			$itemWeaponType !== '' &&
			!in_array( $itemWeaponType, $this->getAllowedWeaponTypesForSlot( $slot ), true )
		) {
			return false;
		}

		if (
			$slot === 'Two-Handed' &&
			$itemWeaponType !== '' &&
			!in_array( $itemWeaponType, $this->getAllowedWeaponTypesForSlot( $slot ), true )
		) {
			return false;
		}

		if (
			$slot === 'Piercing' &&
			$itemWeaponType !== '' &&
			$itemWeaponType !== '1H Piercing'
		) {
			return false;
		}

		if ( $requestedWeaponType !== '' ) {
			return $itemWeaponType === $requestedWeaponType;
		}

		return true;
	}

	private function extractItemWeaponType( $statsBlock, $templateText ) {
		if ( preg_match( '/\bSkill:\s*([^<\r\n]+)/i', $statsBlock, $match ) ) {
			$skillText = html_entity_decode( strip_tags( $match[1] ), ENT_QUOTES );
			$skillParts = preg_split(
				'/\s+(?:Atk\s+Delay|DMG|AC|WT|Slot|Class|Race)\s*:/i',
				$skillText,
				2
			);
			$weaponType = $this->normalizeWeaponTypeName( $skillParts[0] );

			if ( $weaponType !== '' ) {
				return $weaponType;
			}
		}

		if (
			preg_match_all(
				'/\[\[\s*Category\s*:\s*([^\]|]+)(?:\|[^\]]*)?\]\]/i',
				$templateText,
				$categoryMatches
			)
		) {
			$categoryWeaponTypes = array();

			foreach ( $categoryMatches[1] as $categoryName ) {
				$weaponType = $this->normalizeWeaponTypeName( $categoryName );

				if ( $weaponType !== '' ) {
					$categoryWeaponTypes[] = $weaponType;
				}
			}

			/*
			 * Prefer an explicit two-handed category if an older page also has
			 * the generic Piercing category.
			 */
			foreach ( array( '2H Slashing', '2H Blunt', '2H Piercing' ) as $twoHandedType ) {
				if ( in_array( $twoHandedType, $categoryWeaponTypes, true ) ) {
					return $twoHandedType;
				}
			}

			if ( count( $categoryWeaponTypes ) > 0 ) {
				return $categoryWeaponTypes[0];
			}
		}

		return '';
	}

	private function extractStatValues( $statsBlock ) {
		$values = array();

		foreach ( $this->statPatterns as $stat => $pattern ) {
			if ( preg_match( $pattern, $statsBlock, $match ) ) {
				$values[$stat] = trim( substr( $match[0], strpos( $match[0], ':' ) + 1 ) );
			} else {
				$values[$stat] = '';
			}
		}

		return $values;
	}

	private function getItemLevelSliderStatKey( $stat ) {
		$aliases = array(
			'MANA' => 'MP',
			'MR' => 'SV_MAGIC',
			'FR' => 'SV_FIRE',
			'CR' => 'SV_COLD',
			'PR' => 'SV_POISON',
			'DR' => 'SV_DISEASE',
		);

		return isset( $aliases[$stat] ) ? $aliases[$stat] : $stat;
	}

	private function normalizeEffectPlainText( $text ) {
		$text = preg_replace( '/<!--.*?-->/s', ' ', (string)$text );
		$text = preg_replace( '/\[\[[^\]|]+\|([^\]]+)\]\]/', '$1', $text );
		$text = preg_replace( '/\[\[([^\]]+)\]\]/', '$1', $text );
		$text = str_replace( array( "'''", "''", '{{', '}}', '|' ), ' ', $text );
		$text = strip_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	private function extractSpellSlotDescriptions( $slots ) {
		$slots = (string)$slots;
		$descriptions = array();
		$offset = 0;
		$pattern = '/\{\{\s*SpellSlotRow(?:Smart)?\b/i';

		while (
			preg_match( $pattern, $slots, $match, PREG_OFFSET_CAPTURE, $offset )
		) {
			$rowStart = $match[0][1];
			$rowText = substr( $slots, $rowStart );
			$parameters = $this->parseNamedTemplateParameters(
				$rowText,
				array( 'SpellSlotRowSmart', 'SpellSlotRow' )
			);

			if ( isset( $parameters[2] ) ) {
				$description = trim( $parameters[2] );

				if ( $description !== '' ) {
					$descriptions[] = $description;
				}
			}

			$offset = $rowStart + strlen( $match[0][0] );
		}

		if ( count( $descriptions ) === 0 ) {
			$plainSlots = trim( $this->normalizeEffectPlainText( $slots ) );

			if ( $plainSlots !== '' ) {
				$descriptions[] = $plainSlots;
			}
		}

		return $descriptions;
	}

	private function spellSlotsAreExclusivelyHealing( $slots ) {
		$descriptions = $this->extractSpellSlotDescriptions( $slots );

		if ( count( $descriptions ) !== 1 ) {
			return false;
		}

		$description = strtolower(
			$this->normalizeEffectPlainText( $descriptions[0] )
		);

		if (
			preg_match( '/\bincrease\s+max\s+(?:hit\s*points?|hitpoints?|hp)\b/i', $description ) ||
			preg_match( '/\bincrease\s+(?:current\s+)?(?:hit\s*points?|hitpoints?|hp)\s+when\s+cast\b/i', $description ) ||
			preg_match( '/[;\r\n]/', $description )
		) {
			return false;
		}

		return preg_match(
			'/^\s*increase\s+(?:current\s+)?(?:hit\s*points?|hitpoints?|hp)\s+by\b/i',
			$description
		) === 1;
	}

	private function extractEffectLevel( $effectText ) {
		if (
			preg_match(
				'/\b(?:(?:at|as)\s+|req(?:uired)?\.?\s*)Level\s*:?\s*(\d+)\b/i',
				$effectText,
				$match
			)
		) {
			return $match[1];
		}

		if ( preg_match( '/\bLevel\s*:?\s*(\d+)\b\s*$/i', $effectText, $match ) ) {
			return $match[1];
		}

		return '';
	}

	private function parseItemEffectLine( $line, $source ) {
		$value = trim( (string)$line );
		$linkTarget = '';
		$displayName = '';
		$activation = '';

		if ( preg_match( '/\[\[\s*([^\]|]+)(?:\|([^\]]+))?\]\]/', $value, $linkMatch ) ) {
			$linkTarget = trim( $linkMatch[1] );
			$displayName = isset( $linkMatch[2] ) ?
				$this->normalizeEffectPlainText( $linkMatch[2] ) :
				$this->normalizeEffectPlainText( $linkTarget );
		} else {
			$nameParts = preg_split(
				'/\s*(?:\(|\b(?:at|as)\s+Level\b)/i',
				$value,
				2
			);
			$displayName = $this->normalizeEffectPlainText( $nameParts[0] );
			$linkTarget = $displayName;
		}

		if ( preg_match( '/\(([^)]*)\)/', $value, $activationMatch ) ) {
			$activation = $this->normalizeEffectPlainText( $activationMatch[1] );
		}

		if (
			$displayName === '' ||
			preg_match( '/^(?:none|unknown|n\/?a|-+)$/i', $displayName )
		) {
			return null;
		}

		$pageTitle = preg_replace( '/#.*$/', '', $linkTarget );

		return array(
			'source' => $source,
			'level' => $this->extractEffectLevel( $value ),
			'name' => $displayName,
			'link_target' => $linkTarget,
			'page_title' => trim( $pageTitle ),
			'activation' => $activation,
		);
	}

	private function extractItemEffects( $statsBlock ) {
		$normalized = preg_replace( '/<br\s*\/?\s*>/i', "\n", (string)$statsBlock );
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $normalized );
		$lines = explode( "\n", $normalized );
		$effects = array();
		$seen = array();

		foreach ( $lines as $line ) {
			$effect = null;

			if ( preg_match( '/^\s*(Effect|Focus\s+Effect)\s*:\s*(.*?)\s*$/i', $line, $match ) ) {
				$source = preg_match( '/^Focus/i', $match[1] ) ? 'Focus Effect' : 'Effect';
				$effect = $this->parseItemEffectLine( $match[2], $source );
			} else if (
				preg_match(
					'/^\s*Haste\s*:\s*([+\-]?\s*\d+(?:\.\d+)?\s*%?)(?:\s|$)/i',
					$line,
					$hasteMatch
				)
			) {
				$hasteValue = preg_replace( '/\s+/', '', $hasteMatch[1] );
				$effect = array(
					'source' => 'Haste',
					'level' => '',
					'name' => 'Haste ' . $hasteValue,
					'link_target' => '',
					'page_title' => '',
					'activation' => 'Worn',
				);
			}

			if ( !$effect ) {
				continue;
			}

			$effectKey = strtolower(
				$effect['source'] . '|' .
				$effect['level'] . '|' .
				$effect['page_title'] . '|' .
				$effect['name']
			);

			if ( isset( $seen[$effectKey] ) ) {
				continue;
			}

			$seen[$effectKey] = true;
			$effects[] = $effect;
		}

		return $effects;
	}

	private function classifyEffectType( $effect, $spellPageInfo ) {
		$source = strtolower( $effect['source'] );
		$name = strtolower( $effect['name'] );
		$activation = strtolower( $effect['activation'] );
		$params = isset( $spellPageInfo['params'] ) ? $spellPageInfo['params'] : array();

		if ( $source === 'focus effect' || $source === 'haste' ) {
			return 'Buff';
		}

		$description = isset( $params['description'] ) ? $params['description'] : '';
		$slots = isset( $params['slots'] ) ? $params['slots'] : '';
		$spellType = strtolower( isset( $params['spell_type'] ) ? $params['spell_type'] : '' );
		$targetType = strtolower( isset( $params['target_type'] ) ? $params['target_type'] : '' );
		$isSpellPage = !empty( $spellPageInfo['is_spell_page'] );
		$content = strtolower( $this->normalizeEffectPlainText(
			$effect['name'] . ' ' . $description . ' ' . $slots
		) );

		/*
		 * A spell page's explicit spell_type is the source of truth for heals.
		 * As a legacy fallback, a page with one direct-healing slot and no other
		 * slot effects is also a heal. Mixed Beneficial spells such as Courage
		 * remain buffs even when they include "Increase HP when cast".
		 */
		if (
			$isSpellPage &&
			(
				preg_match( '/\bheal(?:ing)?\b/i', $spellType ) ||
				(
					strpos( $spellType, 'detrimental' ) === false &&
					$this->spellSlotsAreExclusivelyHealing( $slots )
				)
			)
		) {
			return 'Heal';
		}

		$hasDecreaseHp = preg_match(
			'/\b(?:decrease|reduce|drain)\s+(?:(?:current|max)\s+)?(?:hit\s*points?|hitpoints?|hp)\b/i',
			$content
		) === 1;
		$hasIncreaseHp = preg_match(
			'/\bincrease\s+(?:current\s+)?(?:hit\s*points?|hitpoints?|hp)\b/i',
			$content
		) === 1;
		$isDetrimental = strpos( $spellType, 'detrimental' ) !== false;

		if (
			preg_match( '/\blife\s*tap\b|\blifetap\b|\bdrain(?:s|ing)?\s+(?:life|health|hit\s*points?)/i', $content ) ||
			( $hasDecreaseHp && $hasIncreaseHp && $isDetrimental )
		) {
			return 'Lifetap';
		}

		if ( preg_match( '/\bstun(?:s|ned|ning)?\b/i', $content ) ) {
			return 'Stun';
		}

		if (
			preg_match(
				'/(?:^|[^a-z])(?:pb\s*ae|pbae|targeted\s*ae|ae(?:\s+(?:pc|npc))?|area(?:\s+of\s+effect)?|caster\s+pb)(?:[^a-z]|$)/i',
				$targetType
			)
		) {
			return 'AoE';
		}

		if (
			preg_match(
				'/(?:decrease|reduce|drain).{0,60}(?:hit\s*points?|hitpoints?|hp).{0,40}per\s+tick|damage\s+over\s+time|\bdot\b/i',
				$content
			) ||
			( !$spellPageInfo['is_spell_page'] && preg_match( '/\b(?:affliction|poison|venom|rot)\b/i', $name ) )
		) {
			return 'DoT';
		}

		if (
			!$isSpellPage &&
			(
				$hasIncreaseHp ||
				preg_match(
					'/\bheal(?:s|ed|ing)?\b|\breplenish(?:es|ed|ment|ing)?\b|\brestoration\b|\bresurrect(?:s|ed|ion)?\b|\brevive(?:s|d)?\b|\breviviscence\b/i',
					$content
				)
			)
		) {
			return 'Heal';
		}

		if (
			preg_match( '/\b(?:charm|mez|mesmerize|pacify|lull)\b/i', $content )
		) {
			return 'Debuff';
		}

		if (
			$isDetrimental &&
			preg_match(
				'/\broot(?:s|ed|ing)?\b|\bsnare(?:s|d)?\b|\bslow(?:s|ed|ing)?\b|decrease\s+(?:movement|attack)\s+speed/i',
				$content
			)
		) {
			return 'Debuff';
		}

		if (
			$isDetrimental &&
			(
				$hasDecreaseHp ||
				preg_match(
					'/\b(?:direct\s+damage|(?:deal|deals|does|doing|cause|causes|causing)\s+\d+[^.]{0,30}\s+damage)\b/i',
					$content
				)
			)
		) {
			return 'DD';
		}

		if ( $isDetrimental ) {
			return 'Debuff';
		}

		if (
			!$spellPageInfo['is_spell_page'] &&
			preg_match( '/\b(?:heal|healing|replenishment|renewal|remedy)\b/i', $name )
		) {
			return 'Heal';
		}

		if ( !$spellPageInfo['is_spell_page'] && strpos( $activation, 'combat' ) !== false ) {
			return 'DD';
		}

		return 'Buff';
	}

	private function getEffectPageInfo( $titleText ) {
		$titleText = trim( preg_replace( '/#.*$/', '', (string)$titleText ) );
		$cacheKey = strtolower( str_replace( '_', ' ', $titleText ) );

		if ( isset( $this->effectPageInfoCache[$cacheKey] ) ) {
			return $this->effectPageInfoCache[$cacheKey];
		}

		$pageText = $titleText !== '' ? $this->getPageTextByTitleText( $titleText ) : '';
		$redirectCount = 0;

		while (
			$pageText !== '' &&
			$redirectCount < 3 &&
			preg_match( '/^\s*#redirect\s*\[\[([^\]|#]+)(?:#[^\]|]+)?/i', $pageText, $redirectMatch )
		) {
			$titleText = trim( $redirectMatch[1] );
			$pageText = $this->getPageTextByTitleText( $titleText );
			$redirectCount++;
		}

		$isSpellPage = preg_match( '/\{\{\s*Spellpage(?:smart)?\b/i', $pageText ) === 1;
		$params = $isSpellPage ?
			$this->getTemplateParameterMap( $pageText, array( 'Spellpagesmart', 'Spellpage' ) ) :
			array();

		$info = array(
			'is_spell_page' => $isSpellPage,
			'params' => $params,
		);

		$this->effectPageInfoCache[$cacheKey] = $info;

		return $info;
	}

	private function formatEffectNameWikitext( $effect, $isSpellPage ) {
		$label = htmlspecialchars( $effect['name'], ENT_QUOTES, 'UTF-8' );
		$linkTarget = trim( $effect['link_target'] );

		if ( $linkTarget === '' ) {
			return $label;
		}

		$linkTitle = Title::newFromText( $linkTarget );

		if ( !$linkTitle ) {
			return $label;
		}

		$linkTarget = $linkTitle->getPrefixedText();

		if ( $isSpellPage ) {
			/*
			 * EQLImmersive lazily resolves .itemeff links through the existing
			 * SpellHoverLink parser. This keeps the spell page as the tooltip's
			 * source of truth without expanding a large tooltip for every row.
			 */
			return '[[' . $linkTarget . '|<span class="itemeff">' . $label . '</span>]]';
		}

		return '[[' . $linkTarget . '|' . $label . ']]';
	}

	private function formatItemEffects( $statsBlock, $effects = null ) {
		if ( $effects === null ) {
			$effects = $this->extractItemEffects( $statsBlock );
		}

		if ( count( $effects ) === 0 ) {
			return array(
				'wikitext' => '',
				'sort_value' => '',
			);
		}

		$displayValues = array();
		$sortValues = array();

		foreach ( $effects as $effect ) {
			$pageInfo = $this->getEffectPageInfo( $effect['page_title'] );
			$effectType = $this->classifyEffectType( $effect, $pageInfo );
			$levelValue = $effect['level'] !== '' ? (int)$effect['level'] : 0;
			$levelLabel = (string)$levelValue;
			$nameWikitext = $this->formatEffectNameWikitext(
				$effect,
				$pageInfo['is_spell_page']
			);

			$displayValues[] =
				"<span class='eql-equipment-effect-entry'>" .
				$levelLabel . ' - ' . $nameWikitext . ' - ' . $effectType .
				'</span>';
			$sortValues[] =
				sprintf( '%05d', $levelValue ) .
				'|' . strtolower( $effect['name'] ) .
				'|' . strtolower( $effectType );
		}

		return array(
			'wikitext' => implode( '<br>', $displayValues ),
			'sort_value' => implode( '~', $sortValues ),
		);
	}

	function pageLooksLikeItemPage( $templateText ) {
		return preg_match( '/\{\{\s*(Itempage|Itembox)\b/i', $templateText ) === 1;
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

		if ( preg_match( '/<li[^>]*>\s*(.*?)\s*<\/li>/is', $dropsFrom, $match ) ) {
			return $this->cleanDropsFromText( $match[1] );
		}

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
			$firstBullet = $this->extractFirstBulletedDropsFromItem( $dropsFrom );

			if ( $firstBullet !== '' ) {
				return $firstBullet;
			}

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

	function parseStatsBlock(
		$statsBlock,
		$dropsFrom,
		$playerCrafted,
		$questOrigin,
		$purchasedFrom,
		$slot,
		$effectsWikitext = ''
	) {
		$vals = array();

		if ( $this->shouldShowDamageColumn( $slot ) ) {
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

		$vals[] = $effectsWikitext;

		$vals[] = $this->formatDropsFromValue(
			$dropsFrom,
			$playerCrafted,
			$questOrigin,
			$purchasedFrom
		);

		$statValues = $this->extractStatValues( $statsBlock );

		foreach ( $this->statPatterns as $stat => $pattern ) {
			$vals[] = $statValues[$stat];
		}

		return $vals;
	}

	function findTemplateStart( $templateText, $templateName ) {
		if ( preg_match( '/\{\{\s*' . preg_quote( $templateName, '/' ) . '\b/i', $templateText, $match, PREG_OFFSET_CAPTURE ) ) {
			return $match[0][1];
		}

		return false;
	}

	private function parseNamedTemplateParameters( $templateText, $templateNames ) {
		$templatePos = false;

		foreach ( $templateNames as $templateName ) {
			$currentPos = $this->findTemplateStart( $templateText, $templateName );

			if (
				$currentPos !== false &&
				( $templatePos === false || $currentPos < $templatePos )
			) {
				$templatePos = $currentPos;
			}
		}

		if ( $templatePos === false ) {
			return array();
		}

		$templateText = substr( $templateText, $templatePos + 2 );

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

	function parseTemplateParameters( $templateText ) {
		return $this->parseNamedTemplateParameters(
			$templateText,
			array( 'Itempage', 'Itembox' )
		);
	}

	private function getTemplateParameterMap( $templateText, $templateNames ) {
		$parameterMap = array();
		$parameters = $this->parseNamedTemplateParameters( $templateText, $templateNames );

		foreach ( $parameters as $parameter ) {
			$equalsPos = strpos( $parameter, '=' );

			if ( $equalsPos === false ) {
				continue;
			}

			$key = strtolower( trim( substr( $parameter, 0, $equalsPos ) ) );
			$value = trim( substr( $parameter, $equalsPos + 1 ) );

			if ( $key !== '' ) {
				$parameterMap[$key] = $value;
			}
		}

		return $parameterMap;
	}

	private function getPageTextByTitleText( $titleText ) {
		$tTitle = Title::newFromText( $titleText );

		if ( !$tTitle ) {
			return '';
		}

		$wikiPage = \MediaWiki\MediaWikiServices::getInstance()
			->getWikiPageFactory()
			->newFromTitle( $tTitle );

		$content = $wikiPage->getContent();

		if ( $content && method_exists( $content, 'getText' ) ) {
			return $content->getText();
		}

		return '';
	}

	private function buildCategoryIntersectionRows( $categories ) {
		$db = \MediaWiki\MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_REPLICA );

		$aTables = array( 'page' );
		$aFields = array( 'page_namespace', 'page_title' );
		$aWhere = array(
			'page_namespace' => NS_MAIN,
			'page_is_redirect' => 0
		);
		$aJoin = array();
		$aOptions = array(
			'ORDER BY' => 'page_title ASC'
		);

		$iCurrentTableNumber = 1;

		foreach ( $categories as $categoryName ) {
			$title = Title::newFromText( $categoryName );

			if ( is_null( $title ) ) {
				return array();
			}

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
					"{$ltAlias}.lt_title = {$db->addQuotes( $title->getDBKey() )}"
				)
			);

			$iCurrentTableNumber++;
		}

		$res = $db->select( $aTables, $aFields, $aWhere, __METHOD__, $aOptions, $aJoin );

		return iterator_to_array( $res, false );
	}

	private function getSlotCategorySets( $slot ) {
		/*
		 * One-Handed and Two-Handed are virtual builder slots.
		 * They are built by unioning the existing weapon-skill categories.
		 *
		 * Do not include Primary here. On Project 1999, weapon pages are
		 * categorized by weapon type, e.g. 1H Blunt, Piercing, 2H Slashing.
		 */
		if ( $slot === 'Any' ) {
			return array(
				array()
			);
		}

		if ( $slot === 'One-Handed' ) {
			return array(
				array( '1H Blunt' ),
				array( '1H Slashing' ),
				array( 'Piercing' ),
				array( '1H Piercing' )
			);
		}

		if ( $slot === 'Two-Handed' ) {
			return array(
				array( '2H Blunt' ),
				array( '2H Slashing' ),
				array( '2H Piercing' )
			);
		}

		if ( $slot === 'Piercing' ) {
			return array(
				array( 'Piercing' ),
				array( '1H Piercing' )
			);
		}

		return array(
			array( $slot )
		);
	}

	private function buildEquipmentListWikitext( $classes, $slot, $stat = '', $weaponType = '' ) {
		/*
		 * Equipment builder behavior:
		 * - Slot is always required.
		 * - Selected classes are OR logic, not AND logic.
		 * - Virtual slots such as One-Handed and Two-Handed are OR logic
		 *   across their component weapon categories.
		 *
		 * Example:
		 *   Druid + Paladin + Shaman + One-Handed
		 *
		 * Means:
		 *   (Druid Equipment AND 1H Blunt)
		 *   OR
		 *   (Druid Equipment AND 1H Slashing)
		 *   OR
		 *   (Druid Equipment AND Piercing)
		 *   OR the same category union for Paladin and Shaman.
		 *
		 * Not:
		 *   Druid Equipment AND Paladin Equipment AND Shaman Equipment
		 */
		$rowMap = array();
		$slotCategorySets = $this->getSlotCategorySets( $slot );

		foreach ( $classes as $class ) {
			foreach ( $slotCategorySets as $slotCategorySet ) {
				$categories = array_merge(
					array( $class . ' Equipment' ),
					$slotCategorySet
				);

				$classRows = $this->buildCategoryIntersectionRows( $categories );

				foreach ( $classRows as $classRow ) {
					$key = $classRow->page_namespace . ':' . $classRow->page_title;
					$rowMap[$key] = $classRow;
				}
			}
		}

		$rows = array_values( $rowMap );

		usort( $rows, function ( $a, $b ) {
			return strcasecmp( $a->page_title, $b->page_title );
		} );

		$outputRows = array();

		foreach ( $rows as $row ) {
			$title = Title::makeTitle( $row->page_namespace, $row->page_title );
			$titleText = $title->getText();
			$templateText = $this->getPageTextByTitleText( $titleText );

			if ( !$this->pageLooksLikeItemPage( $templateText ) ) {
				continue;
			}

			$statsBlock = '';
			$dropsFrom = '';
			$playerCrafted = '';
			$questOrigin = '';
			$purchasedFrom = '';

			$parms = $this->parseTemplateParameters( $templateText );

			foreach ( $parms as $parm ) {
				$ePos = strpos( $parm, '=' );

				if ( $ePos === false ) {
					continue;
				}

				$key = strtolower( trim( substr( $parm, 0, $ePos ) ) );
				$value = trim( substr( $parm, $ePos + 1 ) );

				if ( $key === 'statsblock' ) {
					$statsBlock = $value;
				} else if ( $key === 'dropsfrom' || $key === 'drops_from' ) {
					$dropsFrom = $value;
				}
			}

			if ( preg_match( '/\|\s*playercrafted\b/i', $templateText ) ) {
				$playerCrafted = 'Player Crafted';
			}

			if ( preg_match( '/\|\s*relatedquests\b/i', $templateText ) ) {
				$questOrigin = 'Quested';
			}

			if ( preg_match( '/\|\s*soldby\b/i', $templateText ) ) {
				$purchasedFrom = 'Purchased';
			}

			$statValues = $this->extractStatValues( $statsBlock );
			$itemEffects = null;

			if ( $stat === 'EFFECT' ) {
				$itemEffects = $this->extractItemEffects( $statsBlock );

				if ( count( $itemEffects ) === 0 ) {
					continue;
				}
			} else if ( $stat !== '' && $statValues[$stat] === '' ) {
				continue;
			}

			$itemWeaponType = $this->extractItemWeaponType( $statsBlock, $templateText );

			if ( !$this->itemMatchesWeaponFilters( $slot, $weaponType, $itemWeaponType ) ) {
				continue;
			}

			$effectValue = $this->formatItemEffects( $statsBlock, $itemEffects );
			$rowVals = $this->parseStatsBlock(
				$statsBlock,
				$dropsFrom,
				$playerCrafted,
				$questOrigin,
				$purchasedFrom,
				$slot,
				$effectValue['wikitext']
			);

			$columnKeys = array();

			if ( $this->shouldShowDamageColumn( $slot ) ) {
				$columnKeys[] = 'DMG';
			}

			$columnKeys[] = '';
			$columnKeys[] = '';

			foreach ( array_keys( $this->statPatterns ) as $statKey ) {
				$columnKeys[] = $this->getItemLevelSliderStatKey( $statKey );
			}

			$rowOutput = "\n<tr class='eql-equipment-item-row'>";
			$rowOutput .= "<td class='eql-equipment-item-cell'>{{:" . $titleText . "}}\n</td>";
			$effectColumnIndex = $this->shouldShowDamageColumn( $slot ) ? 1 : 0;

			for ( $i = 0; $i < count( $rowVals ); $i++ ) {
				$columnKey = $columnKeys[$i];
				$attributes = '';
				$cellClasses = array();

				if ( $columnKey !== '' ) {
					$cellClasses[] = 'eql-equipment-scaled-cell';
					$attributes .= " data-eql-stat='" . $columnKey . "'";
				}

				if ( $i === $effectColumnIndex ) {
					$cellClasses[] = 'eql-equipment-effects-cell';
					$attributes .= " data-sort-value='" . htmlspecialchars(
						$effectValue['sort_value'],
						ENT_QUOTES,
						'UTF-8'
					) . "'";
				}

				if (
					$columnKey === 'DMG' &&
					preg_match( '/Atk Delay:\s*(\d+)/i', $statsBlock, $delayMatch )
				) {
					$attributes .= " data-eql-delay='" . $delayMatch[1] . "'";
				}

				if ( count( $cellClasses ) > 0 ) {
					$attributes = " class='" . implode( ' ', $cellClasses ) . "'" . $attributes;
				}

				$rowOutput .= "\n    <td" . $attributes . "> " . $rowVals[$i] . " </td>";
			}

			$rowOutput .= "</tr>\n";
			$outputRows[] = $rowOutput;
		}

		$rowCount = count( $outputRows );

		if ( $rowCount === 0 ) {
			return "No items found.";
		}

		$titleLabel = implode( ' / ', $classes ) . ' :: ' . $slot;

		if ( $weaponType !== '' ) {
			$titleLabel .= ' :: ' . $this->weaponTypeLabels[$weaponType];
		}

		if ( $stat !== '' ) {
			$titleLabel .= ' :: ' . $this->statLabels[$stat];
		}

		$output = "'''{$titleLabel}''' — {$rowCount} matching items found.\n\n";
		$output .= "\n<table class='eoTable sortable'>";
		$output .= "\n<tr><th>Name</th>";

		if ( $this->shouldShowDamageColumn( $slot ) ) {
			$output .= "<th>Damage/Delay</th>";
		}

		$output .=
			"<th>Effects</th>" .
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
			"<th>Endurance</th>" .
			"<th>MR</th>" .
			"<th>FR</th>" .
			"<th>CR</th>" .
			"<th>PR</th>" .
			"<th>DR</th>" .
			"</tr>";

		$output .= implode( '', $outputRows );
		$output .= "</table>\n";

		return $output;
	}

	private function parseWikitextToHtml( $wikitext ) {
		global $wgOut;

		if ( method_exists( $wgOut, 'parseAsContent' ) ) {
			return $wgOut->parseAsContent( $wikitext );
		}

		return $wikitext;
	}

	private function handleBuilderAjax() {
		global $wgOut;

		$request = $this->getRequest();

		$classA = $this->normalizeClassName( $request->getText( 'classA' ) );
		$classB = $this->normalizeClassName( $request->getText( 'classB' ) );
		$classC = $this->normalizeClassName( $request->getText( 'classC' ) );
		$slot = $this->normalizeSlotName( $request->getText( 'slot' ) );
		$stat = $this->normalizeStatName( $request->getText( 'stat' ) );
		$weaponType = $this->normalizeWeaponTypeName( $request->getText( 'weaponType' ) );

		$wgOut->disable();
		header( 'Content-Type: text/html; charset=UTF-8' );

		$classes = array();

		foreach ( array( $classA, $classB, $classC ) as $class ) {
			if ( $class !== '' && !in_array( $class, $classes, true ) ) {
				$classes[] = $class;
			}
		}

		if ( count( $classes ) < 1 || !$slot ) {
			echo '<div class="errorbox">Please select at least one class and a valid slot.</div>';
			return;
		}

		if (
			$weaponType !== '' &&
			!in_array( $weaponType, $this->getAllowedWeaponTypesForSlot( $slot ), true )
		) {
			echo '<div class="errorbox">Please select a damage type that is valid for the chosen slot.</div>';
			return;
		}

		$wikitext = $this->buildEquipmentListWikitext( $classes, $slot, $stat, $weaponType );
		echo $this->parseWikitextToHtml( $wikitext );
	}

	function execute( $par ) {
		global $wgOut;

		$request = $this->getRequest();

		if ( $request->getBool( 'eqlBuilder' ) || $request->getBool( 'eqlTrio' ) ) {
			$this->handleBuilderAjax();
			return;
		}

		list( $class, $slot ) = array_pad( explode( '/', $par, 2 ), 2, '' );

		$class = $this->normalizeClassName( $class );
		$slot = $this->normalizeSlotName( $slot );
		$stat = $this->normalizeStatName( $request->getText( 'stat' ) );
		$weaponType = $this->normalizeWeaponTypeName( $request->getText( 'weaponType' ) );

		if ( !$class || !$slot ) {
			$wgOut->setPagetitle( 'Class Slot Equipment List' );
			$wgOut->addWikiTextAsContent( "Error! Didn't recognize the class/slot combination, sorry." );
			return;
		}

		$this->setHeaders();
		$wgOut->setPagetitle( "$class :: $slot" );

		if (
			$weaponType !== '' &&
			!in_array( $weaponType, $this->getAllowedWeaponTypesForSlot( $slot ), true )
		) {
			$weaponType = '';
		}

		$wikitext = $this->buildEquipmentListWikitext( array( $class ), $slot, $stat, $weaponType );
		$wgOut->addWikiTextAsContent( $wikitext );
	}
}
