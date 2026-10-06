(function () {
	'use strict';

	var COLLAPSED_KEY = 'eql-announcements-collapsed-v1';
	var SIGNATURE_KEY = 'eql-announcements-signature-v1';
	var ENTRY_SIGNATURES_KEY = 'eql-announcements-entry-signatures-v1';
	var NEW_ENTRY_SIGNATURES_KEY = 'eql-announcements-new-entry-signatures-v1';

	function safeGet( key ) {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function safeSet( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
	}

	function safeRemove( key ) {
		try {
			window.localStorage.removeItem( key );
		} catch ( e ) {}
	}

	function safeJsonParseArray( value ) {
		try {
			var parsed = JSON.parse( value );

			return Array.isArray( parsed ) ? parsed : [];
		} catch ( e ) {
			return [];
		}
	}

	function hashString( text ) {
		var hash = 0;
		var i;
		var chr;

		text = String( text || '' );

		if ( !text ) {
			return '0';
		}

		for ( i = 0; i < text.length; i++ ) {
			chr = text.charCodeAt( i );
			hash = ( ( hash << 5 ) - hash ) + chr;
			hash |= 0;
		}

		return String( hash );
	}

	function cleanTextFromNodes( nodes ) {
		var wrapper = document.createElement( 'div' );

		nodes.forEach( function ( node ) {
			wrapper.appendChild( node.cloneNode( true ) );
		} );

		wrapper.querySelectorAll(
			'.eql-announcements-toggle, .eql-announcements-new-badge, .mw-editsection, .mw-editsection-bracket'
		).forEach( function ( removable ) {
			removable.remove();
		} );

		return wrapper.textContent
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function getAnnouncementEntries( panel ) {
		var children = Array.from( panel.children );
		var entries = [];
		var current = null;

		children.forEach( function ( child ) {
			if (
				child.classList.contains( 'eql-announcements-title' ) ||
				child.classList.contains( 'eql-announcements-toggle' )
			) {
				return;
			}

			if ( child.classList.contains( 'mw-heading' ) ) {
				if ( current ) {
					entries.push( current );
				}

				current = {
					heading: child,
					nodes: [ child ]
				};

				return;
			}

			if ( current ) {
				current.nodes.push( child );
			}
		} );

		if ( current ) {
			entries.push( current );
		}

		entries.forEach( function ( entry ) {
			entry.text = cleanTextFromNodes( entry.nodes );
			entry.signature = hashString( entry.text );
		} );

		return entries.filter( function ( entry ) {
			return entry.text !== '';
		} );
	}

	function getAnnouncementSignature( entries ) {
		return hashString(
			entries.map( function ( entry ) {
				return entry.signature;
			} ).join( '|' )
		);
	}

	function markNewEntries( entries, newSignatures ) {
		entries.forEach( function ( entry ) {
			var headingText;
			var badge;

			if ( newSignatures.indexOf( entry.signature ) === -1 ) {
				return;
			}

			entry.heading.classList.add( 'eql-announcement-entry-new' );

			if ( entry.heading.querySelector( '.eql-announcements-new-badge' ) ) {
				return;
			}

			badge = document.createElement( 'span' );
			badge.className = 'eql-announcements-new-badge';
			badge.textContent = 'NEW!';

			headingText = entry.heading.querySelector( 'h2' ) || entry.heading;
			headingText.appendChild( badge );
		} );
	}

	function clearNewEntryMarkers( panel ) {
		panel.querySelectorAll( '.eql-announcements-new-badge' ).forEach( function ( badge ) {
			badge.remove();
		} );

		panel.querySelectorAll( '.eql-announcement-entry-new' ).forEach( function ( node ) {
			node.classList.remove( 'eql-announcement-entry-new' );
		} );
	}

	function ensureTogglePlacement( panel, button ) {
		var title = panel.querySelector( '.eql-announcements-title' );

		if ( title ) {
			if ( title.nextSibling !== button ) {
				panel.insertBefore( button, title.nextSibling );
			}

			return;
		}

		if ( panel.firstChild !== button ) {
			panel.insertBefore( button, panel.firstChild );
		}
	}

	function setCollapsed( panel, collapsed ) {
		var button = panel.querySelector( '.eql-announcements-toggle' );

		document.documentElement.classList.toggle( 'eql-announcements-collapsed-initial', collapsed );
		panel.classList.toggle( 'eql-announcements-collapsed', collapsed );

		if ( button ) {
			button.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			button.textContent = collapsed ? 'Show' : 'Hide';
			button.title = collapsed ? 'Show announcements' : 'Hide announcements';
		}
	}

	function initAnnouncementsCollapse() {
		var body = document.body;
		var panel;
		var button;
		var entries;
		var currentSignature;
		var previousSignature;
		var previousEntrySignatures;
		var newEntrySignatures;
		var storedNewSignatures;
		var shouldCollapse;
		var hasNewContent;

		if (
			!body ||
			!body.classList.contains( 'skin-eqlimmersive' ) ||
			!body.classList.contains( 'page-Main_Page' )
		) {
			return;
		}

		panel = document.querySelector( '.eql-announcements' );

		if ( !panel ) {
			return;
		}

		panel.id = panel.id || 'eql-announcements';

		button = panel.querySelector( '.eql-announcements-toggle' );

		if ( !button ) {
			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'eql-announcements-toggle';
		}

		ensureTogglePlacement( panel, button );

		entries = getAnnouncementEntries( panel );
		currentSignature = getAnnouncementSignature( entries );
		previousSignature = safeGet( SIGNATURE_KEY );
		previousEntrySignatures = safeJsonParseArray( safeGet( ENTRY_SIGNATURES_KEY ) );
		storedNewSignatures = safeJsonParseArray( safeGet( NEW_ENTRY_SIGNATURES_KEY ) );
		shouldCollapse = safeGet( COLLAPSED_KEY ) === '1';
		hasNewContent = !!previousSignature && previousSignature !== currentSignature;

		button.setAttribute( 'aria-controls', panel.id );
		button.setAttribute( 'aria-expanded', 'true' );
		button.textContent = 'Hide';
		button.title = 'Hide announcements';

		panel.classList.add( 'eql-announcements-js' );

		if ( hasNewContent ) {
			newEntrySignatures = entries
				.map( function ( entry ) {
					return entry.signature;
				} )
				.filter( function ( signature ) {
					return previousEntrySignatures.indexOf( signature ) === -1;
				} );

			if ( newEntrySignatures.length === 0 && entries.length ) {
				newEntrySignatures = [ entries[ 0 ].signature ];
			}

			safeRemove( COLLAPSED_KEY );
			safeSet( SIGNATURE_KEY, currentSignature );
			safeSet(
				ENTRY_SIGNATURES_KEY,
				JSON.stringify( entries.map( function ( entry ) {
					return entry.signature;
				} ) )
			);
			safeSet( NEW_ENTRY_SIGNATURES_KEY, JSON.stringify( newEntrySignatures ) );

			panel.classList.add( 'eql-announcements-has-new-content' );
			markNewEntries( entries, newEntrySignatures );
			setCollapsed( panel, false );
		} else {
			if ( storedNewSignatures.length ) {
				markNewEntries( entries, storedNewSignatures );
				panel.classList.add( 'eql-announcements-has-new-content' );
			}

			safeSet( SIGNATURE_KEY, currentSignature );
			safeSet(
				ENTRY_SIGNATURES_KEY,
				JSON.stringify( entries.map( function ( entry ) {
					return entry.signature;
				} ) )
			);

			setCollapsed( panel, shouldCollapse );
		}

		if ( button.dataset.eqlAnnouncementsBound === '1' ) {
			return;
		}

		button.dataset.eqlAnnouncementsBound = '1';

		button.addEventListener( 'click', function () {
			var collapsed = !panel.classList.contains( 'eql-announcements-collapsed' );

			safeSet( SIGNATURE_KEY, currentSignature );
			safeSet(
				ENTRY_SIGNATURES_KEY,
				JSON.stringify( entries.map( function ( entry ) {
					return entry.signature;
				} ) )
			);

			if ( collapsed ) {
				safeSet( COLLAPSED_KEY, '1' );
				safeRemove( NEW_ENTRY_SIGNATURES_KEY );
				panel.classList.remove( 'eql-announcements-has-new-content' );
				clearNewEntryMarkers( panel );
			} else {
				safeRemove( COLLAPSED_KEY );
			}

			setCollapsed( panel, collapsed );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAnnouncementsCollapse );
	} else {
		initAnnouncementsCollapse();
	}
}());