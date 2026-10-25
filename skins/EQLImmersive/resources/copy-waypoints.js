/* EQLImmersive: copy rendered article waypoints. @license GPL-2.0-or-later */
( function () {
	'use strict';

	var number = '[+\\-\\u2212]?(?:\\d+(?:\\.\\d+)?|\\.\\d+)';
	var tuple = number + '(?:\\s*,\\s*' + number + '){1,2}';
	var candidates = new RegExp( '\\(\\s*' + tuple + '\\s*\\)|' + tuple, 'g' );
	var singleTuple = new RegExp( '^(?:\\(\\s*' + tuple + '\\s*\\)|' + tuple + ')$' );
	var locationLabel = /(?:\b(?:location|loc|waypoint|coordinates?|position|succor(?:\s*\/\s*(?:evacuate|evac))?|evacuate|evac|safe(?:\s+(?:location|loc))?|bind(?:\s+(?:location|loc))?)\s*[:=]?|\/loc)\s*$/i;
	var skip = 'a, button, input, select, textarea, option, form, label, pre, code, kbd, samp, ' +
		'script, style, noscript, svg, math, nav, aside, [role="navigation"], [role="button"], ' +
		'[contenteditable]:not([contenteditable="false"]), [hidden], [aria-hidden="true"], ' +
		'.mw-editsection, .mw-jump-link, .toc, .navbox, .vertical-navbox, .metadata, ' +
		'.diff, .mw-diff, .mw-history, .mw-history-legend, .ve-ui-surface, .ve-ce-surface';
	var blocks = /^(?:ADDRESS|ARTICLE|BLOCKQUOTE|BR|CAPTION|DD|DETAILS|DIV|DL|DT|FIELDSET|FIGCAPTION|FIGURE|FOOTER|H[1-6]|HEADER|HR|LI|MAIN|OL|P|SECTION|SUMMARY|TABLE|TBODY|TD|TFOOT|TH|THEAD|TR|UL)$/;
	var states = new WeakMap();

	function canEnhance() {
		var action = mw.config.get( 'wgAction' );
		var url = new URL( window.location.href );
		return ( !action || action === 'view' ) && Number( mw.config.get( 'wgNamespaceNumber' ) ) >= 0 &&
			!url.searchParams.has( 'diff' ) && !url.searchParams.has( 'oldid' ) &&
			!url.searchParams.has( 'veaction' );
	}

	function eligible( match, text, container ) {
		var before = text.slice( 0, match.index );
		var after = text.slice( match.index + match[ 0 ].length );
		var wrapped = match[ 0 ].charAt( 0 ) === '(';
		var labeled = locationLabel.test( before );
		if ( !before.trim() && /^(?:TD|TH)$/.test( container.tagName ) ) {
			var previous = container.previousElementSibling;
			labeled = labeled || previous && previous.tagName === 'TH' &&
				locationLabel.test( previous.textContent.trim() );
		}
		// Do not copy a fragment of a larger number, identifier, or four-number list.
		if ( /[\w.+\-\u2212]$/.test( before ) || /^[\w+\-\u2212]/.test( after ) || /^\.\d/.test( after ) ||
			!wrapped && ( /,\s*$/.test( before ) || /^\s*,/.test( after ) ) ) {
			return false;
		}
		var values = match[ 0 ].replace( /^\(\s*|\s*\)$/g, '' );
		// Without a label, “(1,234)” is ambiguous with ordinary thousands grouping.
		if ( !labeled && /^\+?\d{1,3}(?:,\d{3})+$/.test( values ) ) {
			return false;
		}
		if ( wrapped || labeled ) {
			return true;
		}
		return /^(?:TD|TH|P|LI)$/.test( container.tagName ) &&
			!before.trim() && !after.trim();
	}

	function commandFor( value ) {
		return '/loc ' + value.replace( /^\(\s*|\s*\)$/g, '' ).replace( /\u2212/g, '-' )
			.split( ',' ).map( function ( part ) { return part.trim(); } ).join( ', ' );
	}

	function restore( button, state ) {
		button.classList.remove( 'is-copied' );
		button.title = 'Copy ' + state.command;
		state.status.textContent = '';
		state.timer = null;
	}

	function fallbackCopy( command ) {
		var active = document.activeElement;
		var selection = window.getSelection();
		var ranges = [];
		var field = document.createElement( 'textarea' );
		for ( var i = 0; selection && i < selection.rangeCount; i++ ) {
			ranges.push( selection.getRangeAt( i ).cloneRange() );
		}
		field.value = command;
		field.setAttribute( 'aria-hidden', 'true' );
		field.tabIndex = -1;
		field.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0;';
		document.body.appendChild( field );
		var copied = false;
		try {
			field.select();
			copied = document.execCommand( 'copy' ) === true;
		} catch ( error ) {
			copied = false;
		} finally {
			field.remove();
			if ( active && typeof active.focus === 'function' ) {
				try { active.focus( { preventScroll: true } ); } catch ( error ) { active.focus(); }
			}
			if ( selection ) {
				selection.removeAllRanges();
				ranges.forEach( function ( range ) { selection.addRange( range ); } );
			}
		}
		return copied;
	}

	function copy( button ) {
		var state = states.get( button );
		var operation = ++state.operation;
		if ( state.timer !== null ) {
			window.clearTimeout( state.timer );
			state.timer = null;
		}
		function finish( copied ) {
			if ( operation !== state.operation ) {
				return;
			}
			button.classList.toggle( 'is-copied', copied );
			state.status.textContent = copied ? 'Copied ' + state.command :
				'Could not copy waypoint. Copy this command manually: ' + state.command;
			button.title = copied ? 'Copied ' + state.command :
				'Could not copy. Select and copy ' + state.command + ' manually.';
			state.timer = window.setTimeout( function () { restore( button, state ); }, 1200 );
		}
		try {
			if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
				Promise.resolve( navigator.clipboard.writeText( state.command ) ).then( function () {
					finish( true );
				}, function () {
					if ( operation === state.operation ) { finish( fallbackCopy( state.command ) ); }
				} );
				return;
			}
		} catch ( error ) {
			// Older browsers and denied clipboard access may still allow copy on a click.
		}
		finish( fallbackCopy( state.command ) );
	}

	function prepareControl( control, command, contents ) {
		control.classList.add( 'eql-copy-waypoint' );
		control.setAttribute( 'data-eql-waypoint', command );
		control.setAttribute( 'aria-label', 'Copy waypoint: ' + command );
		control.title = 'Copy ' + command;
		var value = document.createElement( 'span' );
		value.className = 'eql-copy-waypoint-value';
		value.appendChild( contents );
		var feedback = document.createElement( 'span' );
		feedback.className = 'eql-copy-waypoint-feedback';
		feedback.setAttribute( 'aria-hidden', 'true' );
		feedback.textContent = 'Copied';
		var status = document.createElement( 'span' );
		status.className = 'eql-copy-waypoint-status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		control.appendChild( value );
		control.appendChild( feedback );
		control.appendChild( status );
		states.set( control, { command: command, status: status, operation: 0, timer: null } );
		control.addEventListener( 'click', function ( event ) {
			if ( control.tagName === 'A' ) {
				if ( event.button !== 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey ) { return; }
				event.preventDefault();
				event.stopPropagation();
			}
			copy( control );
		} );
	}

	function enhanceLocationLinks( root ) {
		var locations = Array.from( root.querySelectorAll( '.loc-link[data-zone][data-loc]' ) );
		if ( root.matches( '.loc-link[data-zone][data-loc]' ) ) { locations.unshift( root ); }
		locations.forEach( function ( location ) {
			if ( location.closest( skip ) ) { return; }
			var raw = location.getAttribute( 'data-loc' ).trim();
			if ( !singleTuple.test( raw ) ) { return; }
			var command = commandFor( raw );
			var links = Array.from( location.querySelectorAll( 'a' ) ).filter( function ( link ) {
				return !link.querySelector( 'img' ) && !states.has( link ) &&
					singleTuple.test( link.textContent.trim() ) && commandFor( link.textContent.trim() ) === command;
			} );
			if ( links.length !== 1 ) { return; }
			var link = links[ 0 ];
			var contents = document.createDocumentFragment();
			while ( link.firstChild ) { contents.appendChild( link.firstChild ); }
			prepareControl( link, command, contents );
		} );
	}

	function endpoint( parts, offset, end ) {
		for ( var i = 0; i < parts.length; i++ ) {
			var part = parts[ i ];
			if ( offset < part.end || end && offset === part.end ) {
				return { node: part.node, offset: offset - part.start };
			}
		}
		return null;
	}

	function enhanceRun( run ) {
		var text = run.parts.map( function ( part ) { return part.node.nodeValue; } ).join( '' );
		var matches = [];
		var match;
		candidates.lastIndex = 0;
		while ( ( match = candidates.exec( text ) ) !== null ) {
			if ( eligible( match, text, run.container ) ) {
				matches.push( { index: match.index, value: match[ 0 ] } );
			}
		}
		matches.reverse().forEach( function ( item ) {
			var start = endpoint( run.parts, item.index, false );
			var end = endpoint( run.parts, item.index + item.value.length, true );
			if ( !start || !end ) { return; }
			var command = commandFor( item.value );
			var range = document.createRange();
			range.setStart( start.node, start.offset );
			range.setEnd( end.node, end.offset );
			var button = document.createElement( 'button' );
			button.type = 'button';
			prepareControl( button, command, range.extractContents() );
			range.insertNode( button );
		} );
	}

	function scan( root ) {
		enhanceLocationLinks( root );
		var runs = [];
		var current = null;
		function flush() { current = null; }
		function visit( node, container ) {
			if ( node.nodeType === 3 ) {
				if ( !current ) {
					current = { container: container, parts: [], length: 0 };
					runs.push( current );
				}
				current.parts.push( { node: node, start: current.length, end: current.length + node.nodeValue.length } );
				current.length += node.nodeValue.length;
				return;
			}
			if ( node.nodeType !== 1 ) { return; }
			if ( node.matches( skip ) ) { flush(); return; }
			var block = blocks.test( node.tagName );
			if ( block ) { flush(); container = node; }
			Array.from( node.childNodes ).forEach( function ( child ) { visit( child, container ); } );
			if ( block ) { flush(); }
		}
		visit( root, root );
		runs.forEach( enhanceRun );
	}

	function enhance( content ) {
		if ( !canEnhance() ) { return; }
		var article = document.getElementById( 'mw-content-text' );
		if ( !article || article.closest( skip ) ) { return; }
		var roots = content && typeof content.toArray === 'function' ? content.toArray() :
			content && content.nodeType ? [ content ] : [ article ];
		roots.forEach( function ( root ) {
			if ( !root || root.nodeType !== 1 || !article.contains( root ) && root !== article || root.closest( skip ) ) {
				return;
			}
			if ( root.closest( '.mw-parser-output' ) ) {
				scan( root );
			} else {
				root.querySelectorAll( '.mw-parser-output' ).forEach( scan );
			}
		} );
	}

	mw.hook( 'wikipage.content' ).add( enhance );
}() );
