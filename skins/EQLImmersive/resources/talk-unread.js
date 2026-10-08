/* EQLImmersive: browser-local unread Talk changes. @license GPL-2.0-or-later */
(function () {
	'use strict';

	var talkTitle = mw.config.get( 'wgEQLTalkPageName' );
	var isTalk = mw.config.get( 'wgEQLTalkIsTalkPage' );
	var userId = Number( mw.config.get( 'wgUserId' ) ) || 0;
	var prefix = 'eql-talk-seen-v1:' + encodeURIComponent( mw.config.get( 'wgWikiID' ) ) +
		':' + ( userId > 0 ? 'user-' + userId : 'anon' ) + ':';
	var linkSelector = '#ca-talk > a, #n-eql-discussion > a, a#ca-talk-sticky-header';
	var started = false;
	var generation = 0;
	var activeTitle = talkTitle;
	var currentCount = 0;
	var originals = new WeakMap();

	function validRevision( value ) {
		return value && Number.isSafeInteger( value.revision ) && value.revision > 0 &&
			typeof value.timestamp === 'string' &&
			/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test( value.timestamp ) &&
			Number.isFinite( Date.parse( value.timestamp ) );
	}

	function readSeen( title ) {
		try {
			var value = JSON.parse( window.localStorage.getItem( prefix + encodeURIComponent( title ) ) );
			return validRevision( value ) ? value : null;
		} catch ( error ) {
			return null;
		}
	}

	function newerThan( revision, seen ) {
		return !seen || revision.timestamp > seen.timestamp ||
			( revision.timestamp === seen.timestamp && revision.revision > seen.revision );
	}

	function markSeen() {
		var url = new URL( window.location.href );
		var revision = {
			revision: Number( mw.config.get( 'wgRevisionId' ) ),
			timestamp: mw.config.get( 'wgEQLTalkRevisionTimestamp' ),
			pageId: Number( mw.config.get( 'wgArticleId' ) )
		};
		if ( document.visibilityState === 'hidden' || !validRevision( revision ) ||
			revision.revision !== Number( mw.config.get( 'wgCurRevisionId' ) ) ||
			url.searchParams.has( 'oldid' ) || url.searchParams.has( 'diff' ) ||
			url.searchParams.has( 'veaction' ) || !document.querySelector( '.mw-parser-output' ) ) {
			return;
		}
		var seen = readSeen( talkTitle );
		if ( seen && ( !seen.pageId || seen.pageId === revision.pageId ) &&
			!newerThan( revision, seen ) ) {
			return;
		}
		try {
			window.localStorage.setItem( prefix + encodeURIComponent( talkTitle ), JSON.stringify( revision ) );
		} catch ( error ) {
			// Storage can be disabled; Talk navigation must remain usable.
		}
	}

	function renderCount( count ) {
		currentCount = count;
		document.querySelectorAll( linkSelector ).forEach( function ( link ) {
			if ( !originals.has( link ) ) {
				originals.set( link, {
					label: link.getAttribute( 'aria-label' ),
					title: link.getAttribute( 'title' ),
					text: link.textContent.trim() || 'Talk'
				} );
			}
			var original = originals.get( link );
			var badge = link.querySelector( '.eql-talk-unread-badge' );
			if ( badge ) {
				badge.remove();
			}
			link.classList.toggle( 'eql-talk-has-unread', count > 0 );
			if ( !count ) {
				[ 'aria-label', 'title' ].forEach( function ( attribute ) {
					var value = attribute === 'title' ? original.title : original.label;
					if ( value === null ) {
						link.removeAttribute( attribute );
					} else {
						link.setAttribute( attribute, value );
					}
				} );
				return;
			}
			var description = count > 99 ? mw.msg( 'eql-talk-unread-label-more' ) :
				mw.msg( 'eql-talk-unread-label', count );
			badge = document.createElement( 'span' );
			badge.className = 'eql-talk-unread-badge';
			badge.setAttribute( 'aria-hidden', 'true' );
			var number = document.createElement( 'span' );
			number.className = 'eql-talk-unread-number';
			number.textContent = count > 99 ? '99+' : String( count );
			badge.appendChild( number );
			link.appendChild( badge );
			link.setAttribute( 'aria-label', ( original.label || original.text ) + ', ' + description );
			link.setAttribute( 'title', ( original.title ? original.title + '\n' : '' ) + description );
		} );
	}

	function readableTalkPage( result ) {
		var pages = result && !result.error && result.query && result.query.pages;
		var page = Array.isArray( pages ) && pages.length === 1 ? pages[ 0 ] : null;
		return page && page.actions && page.actions.read === true && !page.missing && !page.invalid &&
			Number.isSafeInteger( page.ns ) && page.ns > 0 && page.ns % 2 === 1 &&
			typeof page.title === 'string' && page.title ? page : null;
	}

	function boundaryFor( title, pageId ) {
		var seen = readSeen( title );
		return seen && seen.pageId && seen.pageId !== pageId ? null : seen;
	}

	function requestHistory( api, title, seen ) {
		var params = {
			action: 'query', prop: 'info|revisions', intestactions: 'read', intestactionsdetail: 'boolean',
			titles: title, redirects: true, rvprop: 'ids|timestamp',
			rvdir: 'older', rvlimit: 101, formatversion: 2
		};
		if ( seen ) {
			params.rvend = seen.timestamp;
		}
		return Promise.resolve( api.get( params ) );
	}

	function refreshCount() {
		if ( !document.querySelector( linkSelector ) || document.visibilityState === 'hidden' ) {
			return;
		}
		var requestGeneration = ++generation;
		var seen = readSeen( talkTitle );
		var api = new mw.Api();
		requestHistory( api, talkTitle, seen ).then( function ( result ) {
			var page = readableTalkPage( result );
			if ( !page ) {
				return null;
			}
			var resolvedTitle = page.title.replace( / /g, '_' );
			var resolvedSeen = boundaryFor( resolvedTitle, page.pageid );
			// Redirects and recreated pages must use the displayed destination's marker.
			if ( resolvedTitle !== talkTitle ||
				( seen && seen.pageId && seen.pageId !== page.pageid ) ) {
				return requestHistory( api, resolvedTitle, resolvedSeen ).then( function ( resolved ) {
					var destination = readableTalkPage( resolved );
					if ( !destination || destination.title.replace( / /g, '_' ) !== resolvedTitle ) {
						return null;
					}
					return { page: destination, seen: resolvedSeen, title: resolvedTitle };
				} );
			}
			return { page: page, seen: seen, title: resolvedTitle };
		} ).then( function ( result ) {
			if ( requestGeneration !== generation ) {
				return;
			}
			var count = 0;
			if ( result ) {
				activeTitle = result.title;
				( Array.isArray( result.page.revisions ) ? result.page.revisions : [] ).forEach( function ( row ) {
					var revision = { revision: row.revid, timestamp: row.timestamp };
					if ( validRevision( revision ) && newerThan( revision, result.seen ) ) {
						count++;
					}
				} );
			}
			// Newest-first rows cover 100 unread changes plus the inclusive seen boundary,
			// even when many earlier edits share the seen revision's timestamp.
			renderCount( Math.min( count, 100 ) );
		} ).catch( function () {
			if ( requestGeneration === generation ) {
				renderCount( 0 );
			}
		} );
	}

	function init() {
		if ( !talkTitle || !document.body.classList.contains( 'skin-eqlimmersive' ) ||
			mw.config.get( 'wgAction' ) !== 'view' ) {
			return;
		}
		if ( started ) {
			if ( !isTalk ) {
				renderCount( currentCount );
			}
			return;
		}
		started = true;
		if ( isTalk ) {
			markSeen();
		} else {
			refreshCount();
		}
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState !== 'hidden' ) {
				if ( isTalk ) {
					markSeen();
				} else {
					refreshCount();
				}
			}
		} );
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				if ( isTalk ) {
					markSeen();
				} else {
					refreshCount();
				}
			}
		} );
		if ( !isTalk ) {
			window.addEventListener( 'storage', function ( event ) {
				if ( event.key === null || event.key === prefix + encodeURIComponent( talkTitle ) ||
					event.key === prefix + encodeURIComponent( activeTitle ) ) {
					refreshCount();
				}
			} );
		}
	}

	mw.hook( 'wikipage.content' ).add( init );
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}());
