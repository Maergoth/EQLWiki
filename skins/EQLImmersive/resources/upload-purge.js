/* EQLImmersive: Post-upload cache purge.
 * After uploading an image, purges the source page and related pages
 * so the new image appears immediately without manual purge. */
(function () {
	'use strict';

	function isUploadLink( anchor ) {
		return !!( anchor && anchor.href && anchor.href.indexOf( 'Special:Upload' ) !== -1 && anchor.href.indexOf( 'wpDestFile=' ) !== -1 );
	}

	function getClosestNpcTitle( anchor ) {
		var el = anchor;
		var npcLink;

		while ( el && el !== document.body ) {
			if ( el.getAttribute ) {
				if ( el.getAttribute( 'data-npc-title' ) ) {
					return el.getAttribute( 'data-npc-title' );
				}

				npcLink = el.querySelector && el.querySelector( '[data-npc-title]' );

				if ( npcLink ) {
					return npcLink.getAttribute( 'data-npc-title' );
				}
			}

			el = el.parentNode;
		}

		return '';
	}

	function patchUploadLink( anchor ) {
		var url;
		var npcTitle;

		if ( !isUploadLink( anchor ) ) {
			return;
		}

		url = new URL( anchor.href, window.location.origin );

		if ( !url.searchParams.get( 'eqlReturnTo' ) ) {
			url.searchParams.set( 'eqlReturnTo', mw.config.get( 'wgPageName' ) );
		}

		if ( !url.searchParams.get( 'eqlAlsoPurge' ) ) {
			npcTitle = getClosestNpcTitle( anchor );

			if ( npcTitle ) {
				url.searchParams.set( 'eqlAlsoPurge', npcTitle );
			}
		}

		anchor.href = url.toString();
	}

	function patchExistingUploadLinks() {
		document.querySelectorAll( 'a[href*="Special:Upload"][href*="wpDestFile="]' ).forEach( function ( anchor ) {
			patchUploadLink( anchor );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var anchor = event.target.closest && event.target.closest( 'a' );

		if ( anchor ) {
			patchUploadLink( anchor );
		}
	}, true );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', patchExistingUploadLinks );
	} else {
		patchExistingUploadLinks();
	}
}() );

/* EQL Wiki — persistent checkbox lists */