/**
 * EQL Wiki - Editor Tools / Dynamic Blueprint Loader
 *
 * Source-editor behavior:
 * - On a new, truly empty page: show the Help:Contents Blueprint selector.
 * - On every source edit page: show the Icon Finder button.
 * - Heavy Icon Finder logic is lazy-loaded only when opened.
 */
( function ( mw, $ ) {
	'use strict';

	var BLUEPRINT_PAGE = 'Help:Contents';
	var TOOLS_ID = 'eql-editor-tools';
	var ICON_FINDER_PAGE = 'MediaWiki:IconFinder.js';

	function htmlToText( html ) {
		var div = document.createElement( 'div' );
		div.innerHTML = String( html || '' );
		return ( div.textContent || div.innerText || '' ).trim();
	}

	function makeLabel( heading ) {
		return heading
			.replace( /\s+Blueprints?\s*$/i, '' )
			.trim() + ' Blueprint';
	}

	function getEditorContents( $textarea ) {
		try {
			return String( $textarea.textSelection( 'getContents' ) || '' );
		} catch ( e ) {
			return String( $textarea.val() || '' );
		}
	}

	function setEditorContents( $textarea, text ) {
		try {
			$textarea.textSelection( 'setContents', text );
		} catch ( e ) {
			$textarea.val( text );
		}

		$textarea.trigger( 'input' );
		$textarea.trigger( 'change' );
		$textarea.trigger( 'focus' );
	}

	function extractBlueprint( sectionText ) {
		var match;
		var blueprint;

		sectionText = String( sectionText || '' );

		match = sectionText.match(
			/<pre(?:\s[^>]*)?>([\s\S]*?)<\/pre>/i
		);

		if ( !match ) {
			return null;
		}

		blueprint = match[ 1 ];

		blueprint = blueprint.replace(
			/^\s*<nowiki>([\s\S]*?)<\/nowiki>\s*$/i,
			'$1'
		);

		blueprint = blueprint
			.replace( /^\s*\n/, '' )
			.replace( /\s+$/, '' );

		return blueprint + '\n';
	}

	function fetchBlueprintSections( api ) {
		return api.get( {
			action: 'parse',
			page: BLUEPRINT_PAGE,
			prop: 'tocdata',
			formatversion: 2
		} ).then( function ( data ) {
			var tocdata =
				data &&
				data.parse &&
				data.parse.tocdata;

			var sections =
				tocdata &&
				Array.isArray( tocdata.sections )
					? tocdata.sections
					: [];

			if ( sections.length ) {
				return sections;
			}

			return api.get( {
				action: 'parse',
				page: BLUEPRINT_PAGE,
				prop: 'sections',
				formatversion: 2
			} ).then( function ( fallbackData ) {
				return (
					fallbackData &&
					fallbackData.parse &&
					Array.isArray( fallbackData.parse.sections )
				)
					? fallbackData.parse.sections
					: [];
			} );
		} );
	}

	function normalizeBlueprintSections( sections ) {
		return sections
			.map( function ( section ) {
				var heading = htmlToText( section.line || '' );

				return {
					heading: heading,
					label: makeLabel( heading ),
					index: String( section.index || '' ),
					anchor:
						section.linkAnchor ||
						section.anchor ||
						''
				};
			} )
			.filter( function ( section ) {
				return (
					section.index &&
					/\bBlueprints?\s*$/i.test( section.heading )
				);
			} );
	}

	function fetchBlueprintWikitext( api, sectionIndex ) {
		return api.get( {
			action: 'parse',
			page: BLUEPRINT_PAGE,
			prop: 'wikitext',
			section: sectionIndex,
			formatversion: 2
		} ).then( function ( data ) {
			var wikitext =
				data &&
				data.parse
					? data.parse.wikitext
					: '';

			if (
				wikitext &&
				typeof wikitext === 'object' &&
				Object.prototype.hasOwnProperty.call( wikitext, '*' )
			) {
				wikitext = wikitext[ '*' ];
			}

			return String( wikitext || '' );
		} );
	}

	function addStyles() {
		if ( document.getElementById( 'eql-editor-tools-styles' ) ) {
			return;
		}

		$( '<style>', {
			id: 'eql-editor-tools-styles',
			text:
				'#' + TOOLS_ID + '{' +
					'box-sizing:border-box;' +
					'margin:0 0 1rem 0;' +
					'padding:1rem 1.1rem;' +
					'background:linear-gradient(180deg,rgba(16,21,29,.97),rgba(9,13,19,.98));' +
					'border:1px solid rgba(216,183,92,.38);' +
					'border-left:5px solid rgba(216,183,92,.82);' +
					'border-radius:8px;' +
					'box-shadow:0 9px 24px rgba(0,0,0,.3),inset 0 1px 0 rgba(255,255,255,.035);' +
				'}' +

				'#' + TOOLS_ID + ' .eql-editor-tools-row{' +
					'display:grid;' +
					'grid-template-columns:minmax(0,1fr) auto;' +
					'gap:1rem;' +
					'align-items:end;' +
				'}' +

				'#' + TOOLS_ID + ' .eql-blueprint-title,' +
				'#' + TOOLS_ID + ' .eql-iconfinder-title{' +
					'margin:0 0 .35rem 0;' +
					'color:#d8b75c;' +
					'font-family:Georgia,Times New Roman,serif;' +
					'font-size:1.05rem;' +
					'font-weight:700;' +
					'letter-spacing:.035em;' +
				'}' +

				'#' + TOOLS_ID + ' .eql-blueprint-description{' +
					'margin:0 0 .7rem 0;' +
					'color:#aeb8c7;' +
					'font-size:.9rem;' +
					'line-height:1.35;' +
				'}' +

				'#' + TOOLS_ID + ' .eql-blueprint-controls{' +
					'display:flex;' +
					'flex-wrap:wrap;' +
					'align-items:center;' +
					'gap:.65rem;' +
				'}' +

				'#' + TOOLS_ID + ' select{' +
					'box-sizing:border-box;' +
					'min-width:260px;' +
					'max-width:100%;' +
					'min-height:2.35rem;' +
					'padding:.35rem .55rem;' +
					'background:#0b1017;' +
					'border:1px solid rgba(216,183,92,.36);' +
					'border-radius:5px;' +
					'color:#e2e2cf;' +
				'}' +

				'#' + TOOLS_ID + ' button{' +
					'min-height:2.35rem;' +
					'padding:.35rem .85rem;' +
					'background:linear-gradient(180deg,#806d32,#594817);' +
					'border:1px solid #b69c4b;' +
					'border-radius:5px;' +
					'color:#fff5cc;' +
					'font-weight:700;' +
					'cursor:pointer;' +
				'}' +

				'#' + TOOLS_ID + ' button:hover:not(:disabled){' +
					'filter:brightness(1.08);' +
				'}' +

				'#' + TOOLS_ID + ' button:disabled{' +
					'cursor:default;' +
					'opacity:.55;' +
				'}' +

				'#' + TOOLS_ID + ' .eql-blueprint-source{' +
					'color:#b9c8ff;' +
					'font-size:.9rem;' +
				'}' +

				'#' + TOOLS_ID + ' .eql-blueprint-status{' +
					'display:block;' +
					'margin-top:.55rem;' +
					'min-height:1.2em;' +
					'color:#aeb8c7;' +
					'font-size:.86rem;' +
				'}' +

				'#' + TOOLS_ID + ' .eql-iconfinder-launch{' +
					'display:flex;' +
					'flex-direction:column;' +
					'align-items:stretch;' +
					'justify-content:flex-end;' +
					'min-width:150px;' +
				'}' +

				'#eql-icon-finder-toggle{' +
					'white-space:nowrap;' +
				'}' +

				'#eql-icon-finder-host{' +
					'margin-top:1rem;' +
					'padding-top:1rem;' +
					'border-top:1px solid rgba(216,183,92,.22);' +
				'}' +

				'#eql-icon-finder-host[hidden]{display:none!important;}' +

				'@media(max-width:760px){' +
					'#' + TOOLS_ID + ' .eql-editor-tools-row{' +
						'grid-template-columns:1fr;' +
					'}' +
					'#' + TOOLS_ID + ' .eql-iconfinder-launch{' +
						'min-width:0;' +
					'}' +
					'#' + TOOLS_ID + ' select{' +
						'width:100%;' +
						'min-width:0;' +
					'}' +
				'}'
		} ).appendTo( document.head );
	}

	function loadIconFinderScript() {
		if ( window.EQLIconFinder ) {
			return Promise.resolve( window.EQLIconFinder );
		}

		if ( window.eqlIconFinderLoadingPromise ) {
			return window.eqlIconFinderLoadingPromise;
		}

		window.eqlIconFinderLoadingPromise = new Promise( function ( resolve, reject ) {
			var script = document.createElement( 'script' );

			script.src = mw.util.getUrl( ICON_FINDER_PAGE, {
				action: 'raw',
				ctype: 'text/javascript',
				v: '9'
			} );

			script.async = true;

			script.onload = function () {
				if ( window.EQLIconFinder ) {
					resolve( window.EQLIconFinder );
				} else {
					reject( new Error( 'IconFinder.js loaded without exposing EQLIconFinder.' ) );
				}
			};

			script.onerror = function () {
				reject( new Error( 'Could not load IconFinder.js.' ) );
			};

			document.head.appendChild( script );
		} );

		return window.eqlIconFinderLoadingPromise;
	}

	function buildIconFinderLaunch( $row, $tools ) {
		var $launch = $( '<div>', {
			class: 'eql-iconfinder-launch'
		} ).appendTo( $row );

		$( '<div>', {
			class: 'eql-iconfinder-title',
			text: 'ICON FINDER'
		} ).appendTo( $launch );

		var $button = $( '<button>', {
			id: 'eql-icon-finder-toggle',
			type: 'button',
			text: 'Icon Finder',
			'aria-expanded': 'false'
		} ).appendTo( $launch );

		var $host = $( '<div>', {
			id: 'eql-icon-finder-host',
			hidden: true
		} ).appendTo( $tools );

		$button.on( 'click', function () {
			var opening = $host.prop( 'hidden' );

			if ( !opening ) {
				$host.prop( 'hidden', true );
				$button.attr( 'aria-expanded', 'false' );
				return;
			}

			$button.prop( 'disabled', true );
			$button.text( 'Loading…' );

			loadIconFinderScript()
				.then( function ( finder ) {
					return finder.init( $host[ 0 ] );
				} )
				.then( function () {
					$host.prop( 'hidden', false );
					$button.attr( 'aria-expanded', 'true' );
				} )
				.catch( function ( error ) {
					if ( window.console && console.error ) {
						console.error( 'EQL Icon Finder:', error );
					}

					$host
						.prop( 'hidden', false )
						.text( 'Icon Finder could not be loaded.' );

					$button.attr( 'aria-expanded', 'true' );
				} )
				.then( function () {
					$button.prop( 'disabled', false );
					$button.text( 'Icon Finder' );
				} );
		} );
	}

	function buildBlueprintArea( $row, $textarea, api ) {
		var $area = $( '<div>', {
			class: 'eql-blueprint-area'
		} ).appendTo( $row );

		$( '<div>', {
			class: 'eql-blueprint-title',
			text: 'CREATE FROM A BLUEPRINT'
		} ).appendTo( $area );

		$( '<div>', {
			class: 'eql-blueprint-description',
			text:
				'Load a standard page skeleton from Help:Contents, ' +
				'or continue editing the current source below.'
		} ).appendTo( $area );

		var $controls = $( '<div>', {
			class: 'eql-blueprint-controls'
		} ).appendTo( $area );

		var $select = $( '<select>', {
			'aria-label': 'Select page blueprint',
			disabled: true
		} ).appendTo( $controls );

		$( '<option>', {
			value: '',
			text: 'Loading blueprints...'
		} ).appendTo( $select );

		var $button = $( '<button>', {
			type: 'button',
			text: 'Load Blueprint',
			disabled: true
		} ).appendTo( $controls );

		var $sourceLink = $( '<a>', {
			class: 'eql-blueprint-source',
			href: mw.util.getUrl( BLUEPRINT_PAGE ),
			text: 'View Blueprints',
			target: '_blank',
			rel: 'noopener'
		} ).appendTo( $controls );

		var $status = $( '<span>', {
			class: 'eql-blueprint-status',
			'aria-live': 'polite'
		} ).appendTo( $area );

		fetchBlueprintSections( api )
			.then( function ( sections ) {
				var blueprints = normalizeBlueprintSections( sections );

				$select.empty();

				$( '<option>', {
					value: '',
					text: blueprints.length ?
						'Select a blueprint...' :
						'No blueprints found'
				} ).appendTo( $select );

				blueprints.forEach( function ( blueprint ) {
					$( '<option>', {
						value: blueprint.index,
						text: blueprint.label
					} )
						.attr( 'data-anchor', blueprint.anchor )
						.appendTo( $select );
				} );

				$select.prop( 'disabled', !blueprints.length );
			} )
			.catch( function ( error ) {
				if ( window.console && console.error ) {
					console.error( 'EQL Blueprint Loader:', error );
				}

				$select.empty().append(
					$( '<option>', {
						value: '',
						text: 'Could not load blueprints'
					} )
				);

				$status.text(
					'Could not read Blueprint headings from Help:Contents.'
				);
			} );

		$select.on( 'change', function () {
			var $option = $select.find( 'option:selected' );
			var anchor = $option.attr( 'data-anchor' ) || '';

			$button.prop( 'disabled', !$select.val() );

			if ( anchor ) {
				$sourceLink.attr(
					'href',
					mw.util.getUrl( BLUEPRINT_PAGE ) + '#' + anchor
				);
				$sourceLink.text( 'View This Blueprint' );
			} else {
				$sourceLink.attr( 'href', mw.util.getUrl( BLUEPRINT_PAGE ) );
				$sourceLink.text( 'View Blueprints' );
			}

			$status.text( '' );
		} );

		$button.on( 'click', function () {
			var sectionIndex = $select.val();
			var existing;

			if ( !sectionIndex ) {
				return;
			}

			existing = getEditorContents( $textarea );

			if (
				existing.trim() &&
				!window.confirm(
					'The editor already contains text. Replace it with the selected blueprint?'
				)
			) {
				return;
			}

			$button.prop( 'disabled', true );
			$select.prop( 'disabled', true );
			$status.text( 'Loading current blueprint from Help:Contents...' );

			fetchBlueprintWikitext( api, sectionIndex )
				.then( function ( sectionText ) {
					var blueprint = extractBlueprint( sectionText );

					if ( blueprint === null ) {
						throw new Error( 'No <pre> block found.' );
					}

					setEditorContents( $textarea, blueprint );

					$status.text(
						'Blueprint loaded. Edit the fields below, then publish normally.'
					);
				} )
				.catch( function ( error ) {
					if ( window.console && console.error ) {
						console.error( 'EQL Blueprint Loader:', error );
					}

					$status.text(
						'Could not load this blueprint. Check Help:Contents and make sure the section contains a <pre> block.'
					);
				} )
				.then( function () {
					$select.prop( 'disabled', false );
					$button.prop( 'disabled', !$select.val() );
				} );
		} );
	}

	function init() {
		var action = mw.config.get( 'wgAction' );
		var $textarea;
		var $tools;
		var $row;
		var $editorHost;
		var api;

		if ( [ 'edit', 'submit' ].indexOf( action ) === -1 ) {
			return;
		}

		$textarea = $( '#wpTextbox1' );

		if ( !$textarea.length ) {
			return;
		}

		if ( document.getElementById( TOOLS_ID ) ) {
			return;
		}

		api = new mw.Api();

		addStyles();

		$tools = $( '<div>', {
			id: TOOLS_ID
		} );

		$row = $( '<div>', {
			class: 'eql-editor-tools-row'
		} ).appendTo( $tools );

		buildBlueprintArea( $row, $textarea, api );

		buildIconFinderLaunch( $row, $tools );

		$editorHost = $textarea.closest( '.wikiEditor-ui' );

		if ( !$editorHost.length ) {
			$editorHost = $textarea;
		}

		$tools.insertBefore( $editorHost );
	}

	mw.loader.using( [
		'mediawiki.api',
		'mediawiki.util',
		'jquery.textSelection'
	] ).then( function () {
		$( init );
	} );

}( mediaWiki, jQuery ) );