<?php

/*************************************************

eqlwiki - extensions (AjaxHoverHelper)
Version: 0.2

Supports:
- Item hover boxes from Template:Itembox / Template:Itempage
- Named mob stat hovers from Template:Namedmobpage
- Spell hovers from Template:Spellpage / Template:Spellpagesmart

*************************************************/

class AjaxHoverHelper extends SpecialPage {

	function __construct() {
		parent::__construct( 'AjaxHoverHelper' );
	}

	private function getPageTextByTitle( $title ) {
		$page = \MediaWiki\MediaWikiServices::getInstance()
			->getWikiPageFactory()
			->newFromTitle( $title );

		$content = $page->getContent();

		if ( !$content ) {
			return '';
		}

		if ( class_exists( 'ContentHandler' ) && method_exists( 'ContentHandler', 'getContentText' ) ) {
			return ContentHandler::getContentText( $content );
		}

		if ( method_exists( $content, 'getText' ) ) {
			return $content->getText();
		}

		return '';
	}

	private function parseWikitextToHtml( $wikitext ) {
		global $wgOut;

		if ( method_exists( $wgOut, 'parseAsContent' ) ) {
			return $wgOut->parseAsContent( $wikitext );
		}

		if ( method_exists( $wgOut, 'parse' ) ) {
			return $wgOut->parse( $wikitext );
		}

		return $wikitext;
	}

	private function findTemplateStart( $text, $templateNames ) {
		foreach ( $templateNames as $templateName ) {
			if ( preg_match(
				'/\{\{\s*' . preg_quote( $templateName, '/' ) . '\b/i',
				$text,
				$match,
				PREG_OFFSET_CAPTURE
			) ) {
				return [
					'pos' => $match[0][1],
					'name' => $templateName
				];
			}
		}

		return false;
	}

	private function extractTemplateBlock( $text, $templateNames ) {
		$startInfo = $this->findTemplateStart( $text, $templateNames );

		if ( !$startInfo ) {
			return '';
		}

		$start = $startInfo['pos'];
		$length = strlen( $text );
		$depth = 0;

		for ( $i = $start; $i < $length - 1; $i++ ) {
			$pair = substr( $text, $i, 2 );

			if ( $pair === '{{' ) {
				$depth++;
				$i++;
				continue;
			}

			if ( $pair === '}}' ) {
				$depth--;
				$i++;

				if ( $depth === 0 ) {
					return substr( $text, $start, $i - $start + 1 );
				}
			}
		}

		return '';
	}

	private function splitTopLevelPipes( $text ) {
		$parts = [];
		$current = '';
		$templateDepth = 0;
		$linkDepth = 0;
		$length = strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			$pair = $i < $length - 1 ? substr( $text, $i, 2 ) : '';

			if ( $pair === '{{' ) {
				$templateDepth++;
				$current .= $pair;
				$i++;
				continue;
			}

			if ( $pair === '}}' && $templateDepth > 0 ) {
				$templateDepth--;
				$current .= $pair;
				$i++;
				continue;
			}

			if ( $pair === '[[' ) {
				$linkDepth++;
				$current .= $pair;
				$i++;
				continue;
			}

			if ( $pair === ']]' && $linkDepth > 0 ) {
				$linkDepth--;
				$current .= $pair;
				$i++;
				continue;
			}

			$char = $text[$i];

			if ( $char === '|' && $templateDepth === 0 && $linkDepth === 0 ) {
				$parts[] = trim( $current );
				$current = '';
				continue;
			}

			$current .= $char;
		}

		$parts[] = trim( $current );

		return $parts;
	}

	private function parseTemplateParameters( $templateBlock ) {
		$templateBlock = trim( $templateBlock );

		if ( substr( $templateBlock, 0, 2 ) === '{{' ) {
			$templateBlock = substr( $templateBlock, 2 );
		}

		if ( substr( $templateBlock, -2 ) === '}}' ) {
			$templateBlock = substr( $templateBlock, 0, -2 );
		}

		$parts = $this->splitTopLevelPipes( $templateBlock );

		$params = [];

		/* First part is the template name. */
		array_shift( $parts );

		foreach ( $parts as $part ) {
			$equalsPos = strpos( $part, '=' );

			if ( $equalsPos === false ) {
				continue;
			}

			$key = trim( substr( $part, 0, $equalsPos ) );
			$value = trim( substr( $part, $equalsPos + 1 ) );

			if ( $key !== '' ) {
				$params[$key] = $value;
			}
		}

		return $params;
	}

	private function getParam( $params, $key ) {
		return isset( $params[$key] ) ? $params[$key] : '';
	}

	private function buildItemboxFromItempage( $templateText ) {
		$itemboxBlock = $this->extractTemplateBlock( $templateText, [ 'Itembox' ] );

		if ( $itemboxBlock !== '' ) {
			return $itemboxBlock;
		}

		$itempageBlock = $this->extractTemplateBlock( $templateText, [ 'Itempage' ] );

		if ( $itempageBlock === '' ) {
			return '';
		}

		$params = $this->parseTemplateParameters( $itempageBlock );

		return '{{Itembox' . "\n" .
			'|itemname    = ' . $this->getParam( $params, 'itemname' ) . "\n" .
			'|lucy_img_ID = ' . $this->getParam( $params, 'lucy_img_ID' ) . "\n" .
			'|statsblock  = ' . "\n" . $this->getParam( $params, 'statsblock' ) . "\n" .
			'}}';
	}

	private function buildSpellboxFromSpellpage( $templateText ) {
		$spellBlock = $this->extractTemplateBlock( $templateText, [
			'Spellpagesmart',
			'Spellpage',
			'spellpage'
		] );

		if ( $spellBlock === '' ) {
			return '';
		}

		$params = $this->parseTemplateParameters( $spellBlock );

		$fields = [
			'spellname',
			'spellicon',
			'description',
			'classes',
			'slots',
			'skill',
			'mana',
			'range',
			'casting_time',
			'fizzle_time',
			'recast_time',
			'duration',
			'target_type',
			'spell_type',
			'resist',
			'other',
			'msg_cast_on_you',
			'msg_cast_on_other',
			'msg_wears_off'
		];

		$wikitext = "{{Spellbox\n";

		foreach ( $fields as $field ) {
			$wikitext .= '| ' . $field . ' = ' . $this->getParam( $params, $field ) . "\n";
		}

		$wikitext .= "}}";

		return $wikitext;
	}

	private function buildMobStatsFromNamedmobpage( $templateText ) {
		$htmlText = $this->parseWikitextToHtml( $templateText );

		if ( preg_match(
			'/<table[^>]*class="[^"]*mobStatsBox[^"]*"[^>]*>.*?<\/table>/is',
			$htmlText,
			$match
		) ) {
			return $match[0];
		}

		return '';
	}

	function execute( $par ) {
		global $wgOut;

		$wgOut->disable();

		header( 'Content-type: text/html; charset=utf-8' );

		$reqName = trim( (string)$par );

		if ( $reqName === '' ) {
			print '<p>AjaxHoverHelper Error 1.</p>';
			return;
		}

		$reqName = str_replace( '_', ' ', $reqName );

		$title = Title::newFromText( $reqName );

		if ( !$title ) {
			print '<p>AjaxHoverHelper Error 1.</p>';
			return;
		}

		$templateText = $this->getPageTextByTitle( $title );

		if ( $templateText === '' ) {
			print '<p>AjaxHoverHelper Error 2.</p>';
			return;
		}

		$request = $this->getRequest();
		$type = strtolower( $request->getText( 'type', '' ) );

		if ( $type === 'spell' || strpos( $templateText, '{{Spellpage' ) !== false || strpos( $templateText, '{{spellpage' ) !== false ) {
			$spellbox = $this->buildSpellboxFromSpellpage( $templateText );

			if ( $spellbox !== '' ) {
				print $this->parseWikitextToHtml( $spellbox );
				return;
			}
		}

		if ( $type === 'item' || strpos( $templateText, '{{Itembox' ) !== false || strpos( $templateText, '{{Itempage' ) !== false ) {
			$itembox = $this->buildItemboxFromItempage( $templateText );

			if ( $itembox !== '' ) {
				print $this->parseWikitextToHtml( $itembox );
				return;
			}
		}

		if ( $type === 'mob' || strpos( $templateText, '{{Namedmobpage' ) !== false ) {
			$mobstats = $this->buildMobStatsFromNamedmobpage( $templateText );

			if ( $mobstats !== '' ) {
				print $mobstats;
				return;
			}
		}

		print '<p>AjaxHoverHelper Error 2.</p>';
	}
}