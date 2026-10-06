/* EQLImmersive: Persistent checkbox lists.
 * Saves checked/unchecked state to localStorage per page.
 * Works with {{CheckboxList}} template and table checklist cells. */
/* =====================================================
   EQL Spell List Lazy Loader
   Page target: Category:Spells

   Loads each class spell/song transclusion when its
   collapsible section is opened, instead of rendering
   every class at initial page parse time.

   Fixes:
   - Removes generated TOC from API parse output.
   - Loads on MediaWiki collapsible expand events, not only clicks.
   - Retries after failed loads.
   - Auto-loads an already-open or hash-targeted section.
   ===================================================== */

(function ( mw, $ ) {
	'use strict';

	function isSpellCategoryPage() {
		return mw.config.get( 'wgPageName' ) === 'Category:Spells';
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( String( value || '' ) ).html();
	}

	function getSectionWikitext( pageName, sectionName ) {
		return [
			'__NOTOC__',
			'{{#vardefine:eqlSpellHoverMode|hover}}',
			'{{#vardefine:eqlSpellHoverClass|' + pageName + '}}',
			'{{#lsth:' + pageName + '|' + sectionName + '}}'
		].join( '\n' );
	}

	function getParsedHtml( response ) {
		var html;
		var $wrap;
		var $parserOutput;

		if ( !response || !response.parse || !response.parse.text ) {
			return '';
		}

		if ( typeof response.parse.text === 'string' ) {
			html = response.parse.text;
		} else if ( typeof response.parse.text[ '*' ] === 'string' ) {
			html = response.parse.text[ '*' ];
		} else {
			return '';
		}

		$wrap = $( '<div>' ).html( html );

		$wrap.find(
			'#toc, ' +
			'.toc, ' +
			'.mw-toc, ' +
			'.vector-toc, ' +
			'.toctitle, ' +
			'.mw-parser-output > .toc'
		).remove();

		$wrap.find( '.mw-editsection' ).remove();

		$parserOutput = $wrap.children( '.mw-parser-output' ).first();

		if ( $parserOutput.length ) {
			return $parserOutput.html();
		}

		return $wrap.html();
	}

	function notifyContentReady( $content ) {
		mw.hook( 'wikipage.content' ).fire( $content );

		if ( window.eqlEraFilter && typeof window.eqlEraFilter.refresh === 'function' ) {
			window.eqlEraFilter.refresh( $content );
		}

		if ( window.eqlItemLevelSlider && typeof window.eqlItemLevelSlider.refresh === 'function' ) {
			window.eqlItemLevelSlider.refresh( $content );
		}
	}

	function loadSpellSection( $section ) {
		var pageName;
		var sectionName;
		var $target;
		var api;

		if ( !$section || !$section.length ) {
			return;
		}

		pageName = $section.attr( 'data-eql-spell-class' );
		sectionName = $section.attr( 'data-eql-spell-section' );
		$target = $section.find( '.eql-spell-lazy-target' ).first();

		if ( !pageName || !sectionName || !$target.length ) {
			return;
		}

		if ( $section.attr( 'data-eql-spell-loaded' ) === '1' ) {
			return;
		}

		if ( $section.attr( 'data-eql-spell-loading' ) === '1' ) {
			return;
		}

		$section.attr( 'data-eql-spell-loading', '1' );

		$target.html(
			'<div class="eql-spell-lazy-loading">Loading ' +
			escapeHtml( pageName ) +
			' ' +
			escapeHtml( sectionName ) +
			'...</div>'
		);

		api = new mw.Api();

		api.post( {
			action: 'parse',
			format: 'json',
			title: 'Category:Spells',
			contentmodel: 'wikitext',
			text: getSectionWikitext( pageName, sectionName ),
			prop: 'text',
			disablelimitreport: 1,
			disableeditsection: 1,
			disabletoc: 1
		} ).done( function ( response ) {
			var html = getParsedHtml( response );

			if ( html === '' ) {
				$target.html(
					'<div class="eql-spell-lazy-error">Could not load this spell list. The parser returned no content.</div>'
				);
				return;
			}

			$target.html( html );
			$section.attr( 'data-eql-spell-loaded', '1' );

			notifyContentReady( $target );
		} ).fail( function ( code, details ) {
			var message = 'Could not load this spell list.';

			if ( code ) {
				message += ' Request failed: ' + code + '.';
			}

			if ( details && details.error && details.error.info ) {
				message += ' ' + details.error.info;
			}

			$target.html(
				'<div class="eql-spell-lazy-error">' +
				escapeHtml( message ) +
				'</div>'
			);
		} ).always( function () {
			$section.removeAttr( 'data-eql-spell-loading' );
		} );
	}

	function getSectionFromElement( element ) {
		return $( element ).closest( '.eql-spell-lazy-section' );
	}

	function loadOpenSections() {
		$( '.eql-spell-lazy-section' ).each( function () {
			var $section = $( this );
			var $collapsible = $section.find( '.mw-collapsible' ).first();

			if ( $collapsible.length && !$collapsible.hasClass( 'mw-collapsed' ) ) {
				loadSpellSection( $section );
			}
		} );
	}

	function loadHashTargetSection() {
		var hash = window.location.hash;
		var id;
		var $target;
		var $section;

		if ( !hash || hash.length < 2 ) {
			return;
		}

		id = decodeURIComponent( hash.slice( 1 ) );
		$target = $( document.getElementById( id ) );

		if ( !$target.length ) {
			return;
		}

		$section = $target.closest( '.eql-spell-lazy-section' );

		if ( $section.length ) {
			loadSpellSection( $section );
		}
	}

	function initSpellLazyLoader() {
		if ( !isSpellCategoryPage() ) {
			return;
		}

		$( document ).on(
			'beforeExpand.mw-collapsible afterExpand.mw-collapsible',
			'.eql-spell-lazy-section .mw-collapsible',
			function () {
				loadSpellSection( getSectionFromElement( this ) );
			}
		);

		$( document ).on(
			'click',
			'.eql-spell-lazy-section .mw-collapsible-toggle, ' +
			'.eql-spell-lazy-section .eql-spell-lazy-heading, ' +
			'.eql-spell-lazy-section table > tbody > tr:first-child > th',
			function () {
				loadSpellSection( getSectionFromElement( this ) );
			}
		);

		$( document ).on(
			'keydown',
			'.eql-spell-lazy-section .mw-collapsible-toggle, ' +
			'.eql-spell-lazy-section .eql-spell-lazy-heading',
			function ( event ) {
				if ( event.key === 'Enter' || event.key === ' ' ) {
					loadSpellSection( getSectionFromElement( this ) );
				}
			}
		);

		window.setTimeout( loadOpenSections, 100 );
		window.setTimeout( loadHashTargetSection, 150 );
	}

	mw.loader.using( [
		'mediawiki.api',
		'mediawiki.util',
		'jquery',
		'jquery.makeCollapsible'
	] ).then( initSpellLazyLoader );
}( mediaWiki, jQuery ) );
