/* EQLImmersive: page verification banner.
 *
 * EQLClientData resolves the current page's verification status server-side.
 * Normal page views therefore make no VerifiedPages API request.
 *
 * Typing "Verified" performs one append-only edit. The server-side cache is
 * invalidated immediately by the PageSaveComplete hook.
 */
(function ( mw, $ ) {
	'use strict';

	mw.loader.using( [ 'mediawiki.api' ] ).then( function () {
		$( function () {
			var pageTitle = mw.config.get( 'wgPageName' );
			var verifiedPagesTitle = mw.config.get(
				'wgEQLVerifiedPagesTitle',
				'VerifiedPages'
			);
			var serverStatus = mw.config.get( 'wgEQLPageVerified', null );
			var fallbackCacheKey = 'eql-verified-pages-fallback-v2';
			var fallbackCacheTtlMs = 30000;

			function normalizeTitle( title ) {
				return String( title || '' )
					.trim()
					.replace( / /g, '_' )
					.replace( /^:+/, '' );
			}

			function parseVerifiedPages( content ) {
				return String( content || '' )
					.split( /\r?\n/ )
					.map( normalizeTitle )
					.filter( function ( line ) {
						return line !== '' && line.charAt( 0 ) !== '#';
					} );
			}

			function readFallbackCache() {
				try {
					var parsed = JSON.parse(
						window.localStorage.getItem( fallbackCacheKey ) || 'null'
					);

					if (
						parsed &&
						Array.isArray( parsed.pages ) &&
						Number( parsed.expiresAt ) > Date.now()
					) {
						return parsed.pages;
					}
				} catch ( error ) {}

				return null;
			}

			function writeFallbackCache( pages ) {
				try {
					window.localStorage.setItem(
						fallbackCacheKey,
						JSON.stringify( {
							pages: pages,
							expiresAt: Date.now() + fallbackCacheTtlMs
						} )
					);
				} catch ( error ) {}
			}

			/*
			 * Compatibility fallback for a partial deployment. Once
			 * EQLClientData is loaded, this function is never called.
			 */
			function fetchFallbackStatus() {
				var cached = readFallbackCache();

				if ( cached ) {
					return Promise.resolve(
						cached.indexOf( normalizeTitle( pageTitle ) ) !== -1
					);
				}

				return new mw.Api().get( {
					action: 'query',
					titles: verifiedPagesTitle,
					prop: 'revisions',
					rvprop: 'content',
					rvslots: 'main',
					formatversion: 2
				} ).then( function ( data ) {
					var pages = data.query && data.query.pages ? data.query.pages : [];
					var revision = pages[ 0 ] && pages[ 0 ].revisions ?
						pages[ 0 ].revisions[ 0 ] :
						null;
					var content = revision && revision.slots && revision.slots.main ?
						revision.slots.main.content || '' :
						'';
					var verifiedPages = parseVerifiedPages( content );

					writeFallbackCache( verifiedPages );

					return verifiedPages.indexOf(
						normalizeTitle( pageTitle )
					) !== -1;
				} ).catch( function () {
					/* Fail open if verification status cannot be determined. */
					return true;
				} );
			}

			function addToVerifiedPages() {
				var normalizedCurrentPage = normalizeTitle( pageTitle );

				return new mw.Api().postWithEditToken( {
					action: 'edit',
					title: verifiedPagesTitle,
					appendtext: '\n' + normalizedCurrentPage + '\n',
					summary: 'Adding page to verified list',
					watchlist: 'nochange'
				} ).then( function () {
					try {
						window.localStorage.removeItem( fallbackCacheKey );
					} catch ( error ) {}

					mw.notify( 'Page has been verified and added to the list.' );
				} ).catch( function ( error ) {
					mw.notify(
						'Page verification failed. You may not have permission to edit ' +
							verifiedPagesTitle + '.',
						{ type: 'error' }
					);
					throw error;
				} );
			}

			function displayBanner() {
				var bannerHTML;
				var $banner;
				var $content;
				var $minimize;
				var submitting = false;

				if ( $( '#verificationBanner' ).length ) {
					return;
				}

				bannerHTML =
					'<div id="verificationBanner" ' +
						'style="' +
							'position: fixed;' +
							'bottom: 10px;' +
							'right: 10px;' +
							'background-color: #c62828;' +
							'color: white;' +
							'padding: 10px;' +
							'width: 300px;' +
							'box-sizing: border-box;' +
							'box-shadow: 0 4px 8px rgba(0,0,0,0.1);' +
							'text-align: center;' +
							'z-index: 1000;' +
						'">' +
						'<button id="verificationMinimize" ' +
							'type="button" ' +
							'title="Minimize page verification notice" ' +
							'aria-label="Minimize page verification notice" ' +
							'style="' +
								'position: absolute;' +
								'top: 2px;' +
								'right: 5px;' +
								'width: 24px;' +
								'height: 22px;' +
								'padding: 0;' +
								'border: 0;' +
								'background: transparent;' +
								'color: white;' +
								'font-size: 20px;' +
								'font-weight: bold;' +
								'line-height: 18px;' +
								'cursor: pointer;' +
								'opacity: 0.8;' +
							'">&minus;</button>' +
						'<div id="verificationBannerContent">' +
							'Page Content Not Verified for EQLegends<br>' +
							'<input type="text" id="verificationInput" ' +
								'placeholder="Type Verified and hit enter to Verify for ALL." ' +
								'style="width: 90%; margin-top: 5px;" />' +
						'</div>' +
					'</div>';

				$( 'body' ).prepend( bannerHTML );
				$banner = $( '#verificationBanner' );
				$content = $( '#verificationBannerContent' );
				$minimize = $( '#verificationMinimize' );

				function minimizeBanner() {
					$content.hide();
					$minimize.hide();
					$banner
						.attr( 'title', 'Click to restore page verification notice' )
						.attr( 'aria-label', 'Restore page verification notice' )
						.attr( 'role', 'button' )
						.attr( 'tabindex', '0' )
						.data( 'verification-minimized', true )
						.css( {
							width: '160px',
							height: '5px',
							minHeight: '5px',
							padding: '0',
							border: '0',
							backgroundColor: '#d71920',
							boxShadow: '0 1px 4px rgba(0,0,0,0.35)',
							cursor: 'pointer',
							overflow: 'hidden'
						} );
				}

				function restoreBanner() {
					$banner
						.removeAttr( 'title aria-label role tabindex' )
						.data( 'verification-minimized', false )
						.css( {
							width: '300px',
							height: 'auto',
							minHeight: '',
							padding: '10px',
							backgroundColor: '#c62828',
							boxShadow: '0 4px 8px rgba(0,0,0,0.1)',
							cursor: 'default',
							overflow: 'visible'
						} );
					$content.show();
					$minimize.show();
				}

				$minimize.on( 'click', function ( event ) {
					event.preventDefault();
					event.stopPropagation();
					minimizeBanner();
				} );

				$banner.on( 'click', function ( event ) {
					if ( !$banner.data( 'verification-minimized' ) ) {
						return;
					}

					event.preventDefault();
					restoreBanner();
				} );

				$banner.on( 'keydown', function ( event ) {
					if ( !$banner.data( 'verification-minimized' ) ) {
						return;
					}

					if ( event.key === 'Enter' || event.key === ' ' ) {
						event.preventDefault();
						restoreBanner();
					}
				} );

				$( '#verificationInput' ).on( 'keydown', function ( event ) {
					if (
						event.key !== 'Enter' ||
						$( this ).val() !== 'Verified' ||
						submitting
					) {
						return;
					}

					event.preventDefault();
					submitting = true;
					$( this ).prop( 'disabled', true );

					addToVerifiedPages().then( function () {
						$banner.remove();
					} ).catch( function () {
						submitting = false;
						$( '#verificationInput' ).prop( 'disabled', false );
					} );
				} );
			}

			if ( serverStatus === true ) {
				return;
			}

			if ( serverStatus === false ) {
				displayBanner();
				return;
			}

			fetchFallbackStatus().then( function ( isVerified ) {
				if ( !isVerified ) {
					displayBanner();
				}
			} );
		} );
	} );
}( mediaWiki, jQuery ));
