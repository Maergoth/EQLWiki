/* EQLImmersive: optional browser-local advertising. @license GPL-2.0-or-later */
(function () {
	'use strict';

	function init() {
		var toggle = document.getElementById( 'eql-ads-toggle' );
		if ( !toggle || toggle.dataset.eqlAdsBound === '1' ) {
			return;
		}
		toggle.dataset.eqlAdsBound = '1';
		var label = toggle.closest( 'label' );
		var advertisement = document.getElementById( 'eql-advertisement' );
		var unit = document.getElementById( 'eql-advertisement-unit' );
		var key = 'eql-ads-v1:' + encodeURIComponent( mw.config.get( 'wgWikiID' ) ||
			window.location.host + ( mw.config.get( 'wgScriptPath' ) || '' ) );
		var cookieName = 'eql_ads_off_' + encodeURIComponent( key );
		var cookieAttributes = '; Path=/; SameSite=Lax' +
			( window.location.protocol === 'https:' ? '; Secure' : '' );
		var offLatch = false;
		var enabled = false;
		var requested = false;
		var script = null;
		var reloading = false;
		var rawConfig = mw.config.get( 'wgEQLAdSense' );
		var preview = rawConfig && rawConfig.preview === true &&
			window.location.origin === 'https://test.eqlwiki.com';
		var previewPosition = 'bottom';
		var config = rawConfig && rawConfig.preview !== true && typeof rawConfig.client === 'string' &&
			/^ca-pub-[0-9]{16}$/.test( rawConfig.client ) && rawConfig.client.length === 23 &&
			typeof rawConfig.slot === 'string' && /^[0-9]{1,20}$/.test( rawConfig.slot ) &&
			rawConfig.slot === rawConfig.slot.trim() &&
			window.location.origin === 'https://eqlwiki.com' ? rawConfig : null;

		function cookieVeto() {
			try {
				return document.cookie.split( ';' ).some( function ( cookie ) {
					return cookie.trim().split( '=' )[ 0 ] === cookieName;
				} );
			} catch ( error ) { return null; }
		}

		function tombstone( present ) {
			try {
				document.cookie = cookieName + ( present ? '=off; Max-Age=31536000' : '=; Max-Age=0' ) +
					cookieAttributes;
				return cookieVeto() === present;
			} catch ( error ) { return false; }
		}

		function readStored() {
			if ( cookieVeto() !== false ) { return false; }
			var sessionValue = null;
			var localValue = null;
			try {
				sessionValue = window.sessionStorage.getItem( key );
			} catch ( error ) {
				// An unavailable store cannot establish an opt-in.
			}
			try {
				localValue = window.localStorage.getItem( key );
			} catch ( error ) {
				// A verified session fallback can still remember this tab's choice.
			}
			// OFF or malformed data vetoes a stale ON in the other store.
			if ( ( sessionValue !== null && sessionValue !== 'on' ) ||
				( localValue !== null && localValue !== 'on' ) ) {
				return false;
			}
			return sessionValue === 'on' || localValue === 'on';
		}

		function read() { return !offLatch && readStored(); }

		function cannotSaveOff() {
			offLatch = true;
			var message = requested ?
				'Ads are hidden, but this browser could not save OFF. Close this tab to fully stop the loaded ad provider.' :
				'Ads are off in this document. This browser could not save your preference.';
			label.title = message;
			if ( typeof mw.notify === 'function' ) { mw.notify( message, { type: 'warn', autoHide: false } ); }
		}

		function save( next, explicit ) {
			// A queued storage event must never clear an OFF tombstone. A genuine
			// explicit ON in another tab clears the shared cookie before its event.
			if ( next && ( explicit ? !tombstone( false ) : cookieVeto() !== false ) ) {
				label.title = 'Ads are off. This browser could not clear the saved OFF preference.';
				return false;
			}
			var value = next ? 'on' : 'off';
			var persistent = false;
			var saved = false;
			try {
				window.localStorage.setItem( key, value );
			} catch ( error ) {
				// Session storage below is an explicit, verified fallback.
			}
			try { persistent = window.localStorage.getItem( key ) === value; } catch ( error ) {}
			if ( persistent ) {
				saved = true;
				try { window.sessionStorage.removeItem( key ); } catch ( error ) {}
				// Some storage policies allow reads but reject removals. Align an
				// uncleared fallback if possible; effective OFF always wins otherwise.
				try {
					if ( window.sessionStorage.getItem( key ) !== null ) {
						window.sessionStorage.setItem( key, value );
					}
				} catch ( error ) {}
			} else {
				// Clearing a stale value may succeed even when quota blocks writes.
				try { window.localStorage.removeItem( key ); } catch ( error ) {}
				try {
					window.sessionStorage.setItem( key, value );
				} catch ( error ) {}
				try { saved = window.sessionStorage.getItem( key ) === value; } catch ( error ) {}
			}
			if ( saved && readStored() === next ) {
				offLatch = false;
				label.title = persistent ?
					'Optional ads. Your choice is saved in this browser; no login needed.' :
					'Optional ads. Your choice is saved for this browser tab session.';
				return true;
			}
			if ( next ) {
				// Never leave behind an opt-in after reporting that saving failed.
				try { window.localStorage.setItem( key, 'off' ); } catch ( error ) {}
				try { window.sessionStorage.setItem( key, 'off' ); } catch ( error ) {}
			}
			// A verified OFF-only first-party cookie can veto stale readable ON
			// values when both Web Storage stores reject writes and removals.
			if ( tombstone( true ) ) {
				offLatch = false;
				label.title = 'Optional ads. OFF is saved in a first-party cookie in this browser.';
				return !next;
			}
			cannotSaveOff();
			return false;
		}

		function hide() {
			if ( advertisement ) { advertisement.hidden = true; }
			if ( unit ) { unit.replaceChildren(); }
		}

		function reload() {
			if ( requested && !reloading && !offLatch ) {
				reloading = true;
				window.location.reload();
			}
		}

		function placePreview() {
			var content = document.querySelector( 'main#content #bodyContent > #mw-content-text.mw-body-content' );
			if ( !content || !content.parentNode || !advertisement ) { return false; }
			if ( previewPosition === 'top' ) {
				content.parentNode.insertBefore( advertisement, content );
			} else {
				content.parentNode.insertBefore( advertisement, content.nextSibling );
			}
			return true;
		}

		function showPreview() {
			if ( !placePreview() ) { hide(); return; }
			if ( !unit.querySelector( '.eql-ad-preview' ) ) {
				var placeholder = document.createElement( 'div' );
				placeholder.className = 'eql-ad-preview';
				var heading = document.createElement( 'strong' );
				heading.textContent = 'Ad placement preview';
				var description = document.createElement( 'p' );
				description.textContent = 'Staging only. No live ad, Google request, or impression.';
				var positionLabel = document.createElement( 'label' );
				positionLabel.textContent = 'Preview position: ';
				var position = document.createElement( 'select' );
				[ [ 'bottom', 'After article' ], [ 'top', 'Before article' ] ].forEach( function ( choice ) {
					var option = document.createElement( 'option' );
					option.value = choice[ 0 ]; option.textContent = choice[ 1 ]; position.appendChild( option );
				} );
				position.value = previewPosition;
				position.addEventListener( 'change', function () {
					var hadFocus = document.activeElement === position;
					previewPosition = position.value === 'top' ? 'top' : 'bottom';
					placePreview();
					if ( hadFocus ) { position.focus(); }
				} );
				positionLabel.appendChild( position );
				placeholder.append( heading, description, positionLabel );
				unit.appendChild( placeholder );
			}
			advertisement.hidden = false;
		}

		function load() {
			if ( !enabled || !advertisement || !unit || reloading ) {
				return;
			}
			if ( preview ) { showPreview(); return; }
			if ( !config || requested ) { return; }
			var ad = document.createElement( 'ins' );
			ad.className = 'adsbygoogle';
			ad.style.display = 'block';
			ad.dataset.adClient = config.client;
			ad.dataset.adSlot = config.slot;
			ad.dataset.adFormat = 'auto';
			ad.dataset.fullWidthResponsive = 'true';
			unit.appendChild( ad );
			advertisement.hidden = false;
			script = document.createElement( 'script' );
			script.async = true;
			script.crossOrigin = 'anonymous';
			script.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' +
				encodeURIComponent( config.client );
			script.addEventListener( 'load', function () {
				if ( enabled && !reloading && unit.contains( ad ) ) {
					try {
						( window.adsbygoogle = window.adsbygoogle || [] ).push( {} );
					} catch ( error ) { hide(); }
				}
			}, { once: true } );
			script.addEventListener( 'error', hide );
			requested = true;
			document.head.appendChild( script );
		}

		function render( next ) {
			enabled = next;
			toggle.checked = next;
			if ( next ) { load(); } else { hide(); reload(); }
		}

		toggle.addEventListener( 'change', function () {
			var next = toggle.checked;
			var saved = save( next, true );
			if ( next && saved && requested && !enabled ) { reload(); }
			render( next && saved );
		} );
		window.addEventListener( 'storage', function ( event ) {
			var local;
			try { local = window.localStorage; } catch ( error ) { return; }
			if ( event.storageArea !== local ||
				( event.key !== key && event.key !== null ) ) {
				return;
			}
			// Events can be queued behind a newer choice. Read current storage,
			// then reconcile any older session fallback before honoring opt-in.
			var next = false;
			try { next = local.getItem( key ) === 'on'; } catch ( error ) {}
			next = next && cookieVeto() === false && !offLatch;
			var saved = save( next, false );
			render( next && saved );
		} );
		// Same-tab navigation does not emit storage events. A restored document
		// must honor the choice made on a later page before resuming its ads.
		window.addEventListener( 'pageshow', function () { render( read() ); } );
		render( read() );
		toggle.disabled = false;
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}());
