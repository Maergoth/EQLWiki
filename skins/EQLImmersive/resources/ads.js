/* EQLImmersive: optional ads with session/account preferences. @license GPL-2.0-or-later */
(function () {
	'use strict';

	// Google owns the anchor. Move only our footer and reserve its space, never
	// resize, clip, restyle or inspect the contents of the provider's ad frames.
	function followAnchor() {
		var root = document.documentElement;
		var selector = 'ins.adsbygoogle[data-anchor-status]';
		var property = '--eql-ad-anchor-clearance';
		var anchors = [];
		var frame = null;
		var active = true;
		var resize = typeof ResizeObserver === 'function' ? new ResizeObserver( schedule ) : null;
		var requestFrame = window.requestAnimationFrame || function ( callback ) { return window.setTimeout( callback, 16 ); };
		var cancelFrame = window.cancelAnimationFrame || window.clearTimeout;

		function measure() {
			frame = null;
			var next = Array.from( document.querySelectorAll( selector ) );
			if ( next.length !== anchors.length || next.some( function ( el, i ) { return el !== anchors[ i ]; } ) ) {
				anchors = next;
				if ( resize ) {
					resize.disconnect();
					anchors.forEach( function ( el ) { resize.observe( el ); } );
				}
			}
			var height = root.clientHeight || window.innerHeight;
			var clearance = 0;
			anchors.forEach( function ( el ) {
				var style = window.getComputedStyle( el );
				var box = el.getBoundingClientRect();
				if ( style.position !== 'fixed' || style.bottom === 'auto' ||
					style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0' ||
					box.width <= 0 || box.height <= 0 || box.right <= 0 || box.left >= window.innerWidth ||
					box.top < 0 || box.bottom < height - 1 ) { return; }
				// Meet the ad container's top edge. Google's collapse tab may
				// overlap the footer; it must not add a blank band above the ad.
				if ( box.top < height ) { clearance = Math.max( clearance, Math.ceil( height - box.top ) ); }
			} );
			var value = clearance + 'px';
			if ( clearance === 0 ) {
				if ( root.style.getPropertyValue( property ) ) { root.style.removeProperty( property ); }
			} else if ( root.style.getPropertyValue( property ) !== value ) {
				root.style.setProperty( property, value );
			}
		}

		function schedule() {
			if ( active && frame === null ) { frame = requestFrame( measure ); }
		}

		function related( node ) {
			return node.nodeType === 1 && ( node.matches( 'ins.adsbygoogle' ) ||
				node.closest( selector ) || node.querySelector( selector ) );
		}

		var changes = new MutationObserver( function ( records ) {
			if ( records.some( function ( record ) {
				return ( record.type === 'attributes' && ( anchors.indexOf( record.target ) !== -1 ||
					record.target.matches( 'ins.adsbygoogle' ) || record.target.closest( selector ) ) ) ||
					( record.type === 'childList' && ( record.target.closest( selector ) ||
						Array.from( record.addedNodes ).some( related ) || Array.from( record.removedNodes ).some( related ) ) );
			} ) ) { schedule(); }
		} );

		function start() {
			active = true;
			changes.observe( root, { subtree: true, childList: true, attributes: true,
				attributeFilter: [ 'style', 'class', 'hidden', 'data-anchor-status' ] } );
			anchors = [];
			schedule();
		}
		window.addEventListener( 'resize', schedule );
		window.addEventListener( 'scroll', schedule, { passive: true } );
		if ( window.visualViewport ) { window.visualViewport.addEventListener( 'resize', schedule ); }
		document.addEventListener( 'transitionend', function ( event ) { if ( related( event.target ) ) { schedule(); } }, true );
		window.addEventListener( 'pagehide', function () {
			active = false;
			changes.disconnect();
			if ( resize ) { resize.disconnect(); }
			if ( frame !== null ) { cancelFrame( frame ); frame = null; }
		} );
		window.addEventListener( 'pageshow', function ( event ) { if ( event.persisted ) { start(); } } );
		start();
	}

	function init() {
		var toggle = document.getElementById( 'eql-ads-toggle' );
		if ( !toggle || toggle.dataset.eqlAdsBound === '1' ) { return; }
		toggle.dataset.eqlAdsBound = '1';
		var label = toggle.closest( 'label' );
		var advertisement = document.getElementById( 'eql-advertisement' );
		var unit = document.getElementById( 'eql-advertisement-unit' );
		var closePreview = document.getElementById( 'eql-ad-preview-close' );
		var wiki = encodeURIComponent( mw.config.get( 'wgWikiID' ) ||
			window.location.host + ( mw.config.get( 'wgScriptPath' ) || '' ) );
		var cookieName = 'eql_ads_session_v1_' + wiki;
		var sessionKey = 'eql-ads-session-v1:' + wiki;
		var cookieAttributes = '; Path=/; SameSite=Lax' +
			( window.location.protocol === 'https:' ? '; Secure' : '' );
		var option = 'userjs-eql-ads';
		var anonymousOffNotice = 'Ads disabled. Log in to save your preference';
		var userId = Number( mw.config.get( 'wgUserId' ) ) || 0;
		var userName = mw.config.get( 'wgUserName' );
		var temporary = Boolean( mw.config.get( 'wgUserIsTemp' ) );
		var account = userId > 0 && typeof userName === 'string' && userName.length > 0 && !temporary;
		var offLatch = false;
		var enabled = false;
		var requested = false;
		var reloading = false;
		var api = null;
		var reading = false;
		var saving = false;
		var failedWrite = false;
		var identityInvalid = false;
		var queued = null;
		var revision = 0;
		var rawConfig = mw.config.get( 'wgEQLAdSense' );
		var preview = rawConfig && rawConfig.preview === true &&
			window.location.origin === 'https://test.eqlwiki.com';
		var providerOrigin = rawConfig && rawConfig.environment === 'staging' ? 'https://test.eqlwiki.com' :
			rawConfig && rawConfig.environment === 'production' ? 'https://eqlwiki.com' : null;
		var config = rawConfig && rawConfig.preview !== true && typeof rawConfig.client === 'string' &&
			/^ca-pub-[0-9]{16}$/.test( rawConfig.client ) && rawConfig.client.length === 23 &&
			rawConfig.format === 'anchor' &&
			window.location.origin === providerOrigin ? rawConfig : null;

		function notify( message, error ) {
			label.title = message;
			if ( typeof mw.notify === 'function' ) {
				mw.notify( message, { type: error ? 'warn' : 'info' } );
			}
		}

		function identityMatches() {
			var matches = ( Number( mw.config.get( 'wgUserId' ) ) || 0 ) === userId &&
				mw.config.get( 'wgUserName' ) === userName &&
				Boolean( mw.config.get( 'wgUserIsTemp' ) ) === temporary;
			if ( !matches ) { identityInvalid = true; }
			return !identityInvalid;
		}

		function guardIdentity() {
			if ( identityMatches() ) { return true; }
			offLatch = true;
			render( false );
			label.title = 'Your login changed. Reload this page to use the correct ad preference.';
			return false;
		}

		function anonymousTitle( next ) {
			return next ? 'Ads are on by default. Changes last until this browser session ends; log in to save them.' :
				anonymousOffNotice;
		}

		// undefined means unreadable; null means no choice. Neither old localStorage
		// preferences nor old persistent cookies are read or migrated.
		function readCookie() {
			try {
				var cookies = document.cookie.split( ';' );
				for ( var i = 0; i < cookies.length; i++ ) {
					var entry = cookies[ i ].trim();
					if ( entry.indexOf( cookieName + '=' ) === 0 ) {
						return entry.slice( cookieName.length + 1 );
					}
				}
				return null;
			} catch ( error ) { return undefined; }
		}

		function readAnonymous() {
			var cookie = readCookie();
			if ( cookie === 'on' || cookie === 'off' ) { return cookie === 'on'; }
			if ( cookie !== null ) { return false; }
			try {
				var fallback = window.sessionStorage.getItem( sessionKey );
				if ( fallback !== null ) { return fallback === 'on'; }
			} catch ( error ) { return false; }
			return true;
		}

		function saveAnonymous( next ) {
			var value = next ? 'on' : 'off';
			var saved = false;
			try {
				// Intentionally no Max-Age/Expires: this is only a browser-session choice.
				document.cookie = cookieName + '=' + value + cookieAttributes;
				saved = readCookie() === value;
			} catch ( error ) {}
			if ( saved ) {
				try { window.sessionStorage.removeItem( sessionKey ); } catch ( error ) {}
			} else {
				try {
					window.sessionStorage.setItem( sessionKey, value );
					saved = window.sessionStorage.getItem( sessionKey ) === value;
				} catch ( error ) {}
			}
			return saved && readCookie() !== undefined && readAnonymous() === next;
		}

		function hide() {
			document.body.classList.remove( 'eql-ad-preview-open' );
			if ( advertisement ) { advertisement.hidden = true; }
			if ( unit ) { unit.replaceChildren(); }
		}

		function reloadProvider() {
			if ( requested && !reloading && !offLatch ) {
				reloading = true;
				window.location.reload();
			}
		}

		function showPreview() {
			var content = document.querySelector( 'main#content #bodyContent > #mw-content-text.mw-body-content' );
			var footer = document.querySelector( '.mw-footer-container' );
			if ( !content || !footer || !advertisement || !unit || !closePreview ||
				advertisement.nextElementSibling !== footer ) { hide(); return; }
			if ( !unit.querySelector( '.eql-ad-preview' ) ) {
				var placeholder = document.createElement( 'div' );
				placeholder.className = 'eql-ad-preview';
				var heading = document.createElement( 'strong' );
				heading.textContent = 'Staging preview';
				var description = document.createElement( 'p' );
				description.textContent = 'Real ads are off.';
				placeholder.append( heading, description );
				unit.appendChild( placeholder );
			}
			advertisement.hidden = false;
			document.body.classList.add( 'eql-ad-preview-open' );
		}

		function load() {
			if ( !enabled || reloading ) { return; }
			if ( preview ) { showPreview(); return; }
			if ( !config || requested ) { return; }
			var script = document.createElement( 'script' );
			script.async = true;
			script.crossOrigin = 'anonymous';
			script.dataset.overlays = 'collapsed-bottom';
			script.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' +
				encodeURIComponent( config.client );
			script.addEventListener( 'error', hide );
			requested = true;
			followAnchor();
			document.head.appendChild( script );
		}

		function render( next ) {
			enabled = next;
			toggle.checked = next;
			if ( next ) { load(); } else { hide(); reloadProvider(); }
		}

		function failAccount( writing ) {
			if ( writing ) { failedWrite = true; }
			offLatch = true;
			render( false );
			var message = writing ?
				'Your ad preference could not be saved to this account. Reload or retry.' :
				'Your ad preference could not be read. Ads are off; reload or retry.';
			if ( identityInvalid ) { message = 'Your login changed. Reload this page to use the correct ad preference.'; }
			if ( requested ) { message += ' Close this tab to fully stop the loaded ad provider.'; }
			if ( writing ) { notify( message, true ); } else { label.title = message; }
		}

		function cacheAccountValue( value ) {
			if ( mw.user && mw.user.options && typeof mw.user.options.set === 'function' ) {
				mw.user.options.set( option, value );
			}
		}

		function getApi() {
			api = api || new mw.Api();
			return api;
		}

		function refreshAccount() {
			if ( reading || saving || queued !== null ) { return; }
			if ( !identityMatches() ) { failAccount( false ); toggle.disabled = false; return; }
			reading = true;
			var startedAt = revision;
			Promise.resolve().then( function () {
				return getApi().get( {
					action: 'query', meta: 'userinfo', uiprop: 'options',
					assert: 'user', assertuser: userName, formatversion: 2
				} );
			} ).then( function ( result ) {
				var info = result && !result.error && result.query && result.query.userinfo;
				if ( !identityMatches() || !info || info.id !== userId || info.name !== userName ||
					!info.options || typeof info.options !== 'object' || Array.isArray( info.options ) ) {
					throw new Error( 'Account changed or could not be verified' );
				}
				if ( failedWrite || revision !== startedAt || saving || queued !== null ) { return; }
				offLatch = false;
				cacheAccountValue( info.options[ option ] === 'on' ? 'on' : 'off' );
				label.title = 'Ads preference is saved to your wiki account.';
				render( info.options[ option ] === 'on' );
			} ).catch( function () {
				if ( revision === startedAt && !saving ) { failAccount( false ); }
			} ).finally( function () {
				reading = false;
				toggle.disabled = false;
			} );
		}

		function flushAccount() {
			if ( saving || queued === null ) { return; }
			var job = queued;
			queued = null;
			if ( !identityMatches() ) { failAccount( true ); return; }
			saving = true;
			label.title = 'Saving your ad preference to this account…';
			Promise.resolve().then( function () {
				if ( !identityMatches() ) { throw new Error( 'Account changed' ); }
				return getApi().saveOption( option, job.next ? 'on' : 'off', {
					assert: 'user', assertuser: userName
				} );
			} ).then( function ( result ) {
				if ( !identityMatches() || !result || result.error || result.warnings || result.options !== 'success' ) {
					throw new Error( 'Account preference was not saved' );
				}
				if ( queued !== null || revision !== job.revision ) { return; }
				cacheAccountValue( job.next ? 'on' : 'off' );
				failedWrite = false;
				offLatch = false;
				label.title = 'Ads preference is saved to your wiki account.';
				if ( job.next && requested && !enabled ) { reloadProvider(); }
				render( job.next );
			} ).catch( function () {
				// An uncertain write is never retried automatically or sent to a new account.
				queued = null;
				failAccount( true );
			} ).finally( function () {
				saving = false;
				if ( queued !== null ) { flushAccount(); }
			} );
		}

		function choose( next ) {
			if ( !guardIdentity() ) { return; }
			if ( toggle.disabled ) { return; }
			if ( account ) {
				revision++;
				queued = { next: next, revision: revision };
				offLatch = true;
				if ( next ) { toggle.checked = true; } else { render( false ); }
				flushAccount();
				return;
			}
			var saved = saveAnonymous( next );
			offLatch = !saved;
			if ( !next ) { notify( anonymousOffNotice, false ); }
			if ( !saved ) {
				var message = 'This browser could not remember the choice for this session.';
				if ( requested ) { message += ' Close this tab to fully stop the loaded ad provider.'; }
				notify( message, true );
				if ( !next ) { label.title = anonymousOffNotice; }
			} else if ( next ) {
				label.title = 'Ads preference lasts until this browser session ends. Log in to save it.';
			}
			if ( next && saved && requested && !enabled ) { reloadProvider(); }
			render( next && saved );
		}

		function refresh() {
			if ( !guardIdentity() ) { return; }
			if ( account ) { refreshAccount(); }
			else if ( !offLatch ) {
				var next = readAnonymous();
				label.title = anonymousTitle( next );
				render( next );
			}
		}

		toggle.addEventListener( 'change', function () { choose( toggle.checked ); } );
		if ( closePreview ) {
			closePreview.addEventListener( 'click', function () { toggle.focus(); choose( false ); } );
		}
		window.addEventListener( 'pageshow', function ( event ) {
			if ( !account || event.persisted ) { refresh(); }
		} );
		window.addEventListener( 'focus', refresh );
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'visible' ) { refresh(); }
		} );
		if ( account ) {
			label.title = 'Loading your saved ad preference…';
			refreshAccount();
		} else {
			var initial = readAnonymous();
			label.title = anonymousTitle( initial );
			toggle.disabled = false;
			render( initial );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else { init(); }
}());
