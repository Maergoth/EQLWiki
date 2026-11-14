/* EQLImmersive: optional ads with session/account preferences. @license GPL-2.0-or-later */
(function () {
	'use strict';

	// Google owns the anchor. Move only our footer and reserve its space, never
	// resize, clip, restyle or inspect the contents of the provider's ad frames.
	function followAnchor() {
		var root = document.documentElement;
		var backdrop = document.createElement( 'div' );
		backdrop.id = 'eql-ad-anchor-backdrop';
		backdrop.setAttribute( 'aria-hidden', 'true' );
		document.body.appendChild( backdrop );
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
		var control = document.getElementById( 'eql-ads-toggle' );
		if ( !control || control.dataset.eqlAdsBound === '1' ) { return; }
		control.dataset.eqlAdsBound = '1';
		var choices = Array.from( control.querySelectorAll( 'input[type="radio"]' ) );
		var advertisement = document.getElementById( 'eql-advertisement' );
		var unit = document.getElementById( 'eql-advertisement-unit' );
		var closePreview = document.getElementById( 'eql-ad-preview-close' );
		var sidebar = document.getElementById( 'eql-ad-sidebar' );
		var sidebarUnit = document.getElementById( 'eql-ad-sidebar-unit' );
		var content = document.querySelector( '.mw-content-container' );
		var wiki = encodeURIComponent( mw.config.get( 'wgWikiID' ) ||
			window.location.host + ( mw.config.get( 'wgScriptPath' ) || '' ) );
		var cookieName = 'eql_ads_session_v1_' + wiki;
		var sessionKey = 'eql-ads-session-v1:' + wiki;
		var scrollKey = 'eql-ads-scroll-v1:' + wiki;
		var cookieAttributes = '; Path=/; SameSite=Lax' +
			( window.location.protocol === 'https:' ? '; Secure' : '' );
		var option = 'userjs-eql-ads';
		var anonymousOffNotice = 'Ads disabled. Log in to save your preference';
		var userId = Number( mw.config.get( 'wgUserId' ) ) || 0;
		var userName = mw.config.get( 'wgUserName' );
		var temporary = Boolean( mw.config.get( 'wgUserIsTemp' ) );
		var account = userId > 0 && typeof userName === 'string' && userName.length > 0 && !temporary;
		var offLatch = false, mode = 'off', requestedMode = null, reloading = false;
		var api = null, reading = false, saving = false, failedWrite = false, identityInvalid = false;
		var queued = null, revision = 0;
		var rawConfig = mw.config.get( 'wgEQLAdSense' );
		var preview = rawConfig && rawConfig.preview === true && window.location.origin === 'https://test.eqlwiki.com';
		var providerOrigin = rawConfig && rawConfig.environment === 'staging' ? 'https://test.eqlwiki.com' :
			rawConfig && rawConfig.environment === 'production' ? 'https://eqlwiki.com' : null;
		var layouts = rawConfig && rawConfig.environment === 'staging' && rawConfig.format === 'layouts' &&
			typeof rawConfig.sidebarSlot === 'string' && rawConfig.sidebarSlot.length === 10 && /^[0-9]{10}$/.test( rawConfig.sidebarSlot );
		var config = rawConfig && rawConfig.preview !== true && typeof rawConfig.client === 'string' &&
			/^ca-pub-[0-9]{16}$/.test( rawConfig.client ) && rawConfig.client.length === 23 &&
			( rawConfig.format === 'anchor' || layouts ) && window.location.origin === providerOrigin ? rawConfig : null;

		function normalize( value ) {
			// Read the current boolean-era choice without a background migration write.
			if ( value === 'on' ) { return 'anchor'; }
			return value === 'side' || value === 'anchor' ? value : 'off';
		}
		function select( next ) { choices.forEach( function ( input ) { input.checked = input.value === next; } ); }
		function disable( value ) { choices.forEach( function ( input ) { input.disabled = value; } ); }
		function notify( message, error ) {
			control.title = message;
			if ( typeof mw.notify === 'function' ) { mw.notify( message, { type: error ? 'warn' : 'info' } ); }
		}
		function wideDesktop() {
			return !!content && content.getBoundingClientRect().width >= 1280 && window.innerWidth > window.innerHeight &&
			window.innerHeight >= 560 && !navigator.maxTouchPoints && typeof window.matchMedia === 'function' &&
			window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches;
		}
		function positionSidebar() {
			if ( content ) { content.classList.toggle( 'eql-ad-side-wide', !!sidebar && !sidebar.hidden && wideDesktop() ); }
		}
		// Choose a fresh anonymous default once. Resizing reflows our column only;
		// it never changes a reader's choice, refreshes ads or requests a second unit.
		var initialDefault = wideDesktop() && ( preview || layouts ) ? 'side' : 'anchor';
		window.addEventListener( 'resize', positionSidebar );
		if ( typeof ResizeObserver === 'function' && content ) {
			var sideObserver = new ResizeObserver( positionSidebar );
			sideObserver.observe( content );
			window.addEventListener( 'pagehide', function () { sideObserver.disconnect(); } );
			window.addEventListener( 'pageshow', function ( event ) { if ( event.persisted ) { sideObserver.observe( content ); positionSidebar(); } } );
		}
		function identityMatches() {
			var matches = ( Number( mw.config.get( 'wgUserId' ) ) || 0 ) === userId &&
				mw.config.get( 'wgUserName' ) === userName && Boolean( mw.config.get( 'wgUserIsTemp' ) ) === temporary;
			if ( !matches ) { identityInvalid = true; }
			return !identityInvalid;
		}
		function guardIdentity() {
			if ( identityMatches() ) { return true; }
			offLatch = true; render( 'off' );
			control.title = 'Your login changed. Reload this page to use the correct ad preference.';
			return false;
		}
		function anonymousTitle( next ) {
			return next === 'off' ? anonymousOffNotice : 'Ad layout lasts until this browser session ends. Log in to save it.';
		}
		// undefined means unreadable; null means no choice. Never read localStorage
		// or the superseded long-lived browser preferences.
		function readCookie() {
			try {
				var cookies = document.cookie.split( ';' );
				for ( var i = 0; i < cookies.length; i++ ) {
					var entry = cookies[ i ].trim();
					if ( entry.indexOf( cookieName + '=' ) === 0 ) { return entry.slice( cookieName.length + 1 ); }
				}
				return null;
			} catch ( error ) { return undefined; }
		}
		function readAnonymous() {
			var cookie = readCookie();
			if ( cookie !== null ) { return normalize( cookie ); }
			try {
				var fallback = window.sessionStorage.getItem( sessionKey );
				if ( fallback !== null ) { return normalize( fallback ); }
			} catch ( error ) { return 'off'; }
			return initialDefault;
		}
		function saveAnonymous( value ) {
			var saved = false;
			try {
				document.cookie = cookieName + '=' + value + cookieAttributes;
				saved = readCookie() === value;
			} catch ( error ) {}
			if ( saved ) {
				try { window.sessionStorage.removeItem( sessionKey ); } catch ( error ) {}
			} else {
				try { window.sessionStorage.setItem( sessionKey, value ); saved = window.sessionStorage.getItem( sessionKey ) === value; } catch ( error ) {}
			}
			return saved && readCookie() !== undefined && readAnonymous() === value;
		}
		function hidePreview() {
			document.body.classList.remove( 'eql-ad-preview-open' );
			if ( advertisement ) { advertisement.hidden = true; }
			if ( unit ) { unit.replaceChildren(); }
			// A requested real unit stays intact until navigation, even on a failed
			// preference save. Do not hide or remove a live creative to simulate Off.
			if ( requestedMode !== 'side' ) {
				if ( sidebar ) { sidebar.hidden = true; }
				if ( sidebarUnit ) { sidebarUnit.replaceChildren(); }
				positionSidebar();
			}
		}
		function reloadProvider() {
			if ( requestedMode && !reloading && !offLatch ) {
				reloading = true;
				try { window.sessionStorage.setItem( scrollKey, JSON.stringify( {
					page: window.location.pathname + window.location.search, at: Date.now(), x: window.scrollX, y: window.scrollY
				} ) ); } catch ( error ) {}
				window.location.reload();
			}
		}
		function restorePosition() {
			try {
				var saved = JSON.parse( window.sessionStorage.getItem( scrollKey ) || 'null' );
				if ( !saved ) { return; }
				window.sessionStorage.removeItem( scrollKey );
				if ( saved.page === window.location.pathname + window.location.search && Date.now() - saved.at < 60000 &&
					Date.now() >= saved.at && Number.isFinite( saved.x ) && Number.isFinite( saved.y ) ) {
					window.scrollTo( saved.x, saved.y );
				}
			} catch ( error ) {}
		}
		if ( document.readyState === 'complete' ) { restorePosition(); }
		else { window.addEventListener( 'load', restorePosition, { once: true } ); }
		function placeholder( host, description ) {
			var box = document.createElement( 'div' ); box.className = 'eql-ad-preview';
			var heading = document.createElement( 'strong' ); heading.textContent = 'Staging preview';
			var text = document.createElement( 'p' ); text.textContent = description;
			box.append( heading, text ); host.replaceChildren( box );
		}
		function showPreview() {
			hidePreview();
			if ( mode === 'side' && content && sidebar && sidebarUnit ) {
				placeholder( sidebarUnit, 'Style 1: side on wide screens, in-page on narrow screens. Real ads are off.' );
				sidebar.hidden = false; positionSidebar();
			} else if ( mode === 'anchor' && advertisement && unit && closePreview &&
				document.querySelector( 'main#content #bodyContent > #mw-content-text.mw-body-content' ) &&
				advertisement.nextElementSibling === document.querySelector( '.mw-footer-container' ) ) {
				placeholder( unit, 'Style 2: bottom anchor. Real ads are off.' );
				advertisement.hidden = false; document.body.classList.add( 'eql-ad-preview-open' );
			}
		}
		function load() {
			if ( mode === 'off' || reloading ) { return; }
			if ( preview ) { showPreview(); return; }
			if ( !config || requestedMode ) { return; }
			if ( mode === 'side' ) {
				if ( !layouts || !content || !sidebar || !sidebarUnit ) {
					control.title = 'Style 1 is not configured here yet. Choose 2 for bottom ads.'; return;
				}
				var ad = document.createElement( 'ins' ); ad.className = 'adsbygoogle';
				// Fixed-size display unit in our reserved column; never a custom fixed
				// footer. Its 300px width also fits the narrow in-flow fallback.
				ad.style.cssText = 'display:inline-block;width:300px;height:250px';
				ad.dataset.adClient = config.client; ad.dataset.adSlot = config.sidebarSlot;
				sidebarUnit.appendChild( ad ); sidebar.hidden = false; positionSidebar();
			}
			var script = document.createElement( 'script' ); script.async = true; script.crossOrigin = 'anonymous';
			if ( mode === 'anchor' ) { script.dataset.overlays = 'collapsed-bottom'; }
			script.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + encodeURIComponent( config.client );
			requestedMode = mode;
			if ( mode === 'anchor' ) { followAnchor(); }
			document.head.appendChild( script );
			if ( mode === 'side' ) { ( window.adsbygoogle = window.adsbygoogle || [] ).push( {} ); }
		}
		function render( next ) {
			mode = normalize( next ); select( mode );
			if ( requestedMode && requestedMode !== mode ) { hidePreview(); reloadProvider(); return; }
			if ( mode !== 'off' ) { load(); } else { hidePreview(); }
		}
		function failAccount( writing ) {
			if ( writing ) { failedWrite = true; }
			offLatch = true; render( 'off' );
			var message = writing ? 'Your ad preference could not be saved to this account. Reload or retry.' :
				'Your ad preference could not be read. Ads are off; reload or retry.';
			if ( identityInvalid ) { message = 'Your login changed. Reload this page to use the correct ad preference.'; }
			if ( requestedMode ) { message += ' Close this tab to fully stop the loaded ad provider.'; }
			if ( writing ) { notify( message, true ); } else { control.title = message; }
		}
		function cacheAccountValue( value ) {
			if ( mw.user && mw.user.options && typeof mw.user.options.set === 'function' ) { mw.user.options.set( option, value ); }
		}
		function getApi() { api = api || new mw.Api(); return api; }
		function refreshAccount() {
			if ( reading || saving || queued !== null ) { return; }
			if ( !identityMatches() ) { failAccount( false ); disable( false ); return; }
			reading = true;
			var startedAt = revision;
			Promise.resolve().then( function () {
				return getApi().get( { action: 'query', meta: 'userinfo', uiprop: 'options',
					assert: 'user', assertuser: userName, formatversion: 2 } );
			} ).then( function ( result ) {
				var info = result && !result.error && result.query && result.query.userinfo;
				if ( !identityMatches() || !info || info.id !== userId || info.name !== userName ||
					!info.options || typeof info.options !== 'object' || Array.isArray( info.options ) ) { throw new Error( 'Account changed or could not be verified' ); }
				if ( failedWrite || revision !== startedAt || saving || queued !== null ) { return; }
				offLatch = false;
				var next = normalize( info.options[ option ] ); cacheAccountValue( next );
				control.title = 'Ad layout is saved to your wiki account.'; render( next );
			} ).catch( function () { if ( revision === startedAt && !saving ) { failAccount( false ); } } )
				.finally( function () { reading = false; disable( false ); } );
		}
		function flushAccount() {
			if ( saving || queued === null ) { return; }
			var job = queued; queued = null;
			if ( !identityMatches() ) { failAccount( true ); return; }
			saving = true; control.setAttribute( 'aria-busy', 'true' ); control.title = 'Saving your ad preference to this account…';
			Promise.resolve().then( function () {
				if ( !identityMatches() ) { throw new Error( 'Account changed' ); }
				return getApi().saveOption( option, job.next, { assert: 'user', assertuser: userName } );
			} ).then( function ( result ) {
				if ( !identityMatches() || !result || result.error || result.warnings || result.options !== 'success' ) { throw new Error( 'Account preference was not saved' ); }
				if ( queued !== null || revision !== job.revision ) { return; }
				cacheAccountValue( job.next ); failedWrite = false; offLatch = false;
				control.title = 'Ad layout is saved to your wiki account.'; render( job.next );
			} ).catch( function () { queued = null; failAccount( true ); } ).finally( function () {
				saving = false; control.removeAttribute( 'aria-busy' ); if ( queued !== null ) { flushAccount(); }
			} );
		}
		function choose( value ) {
			if ( !guardIdentity() || choices.every( function ( input ) { return input.disabled; } ) || reloading ) { return; }
			var next = normalize( value );
			if ( account ) {
				revision++; queued = { next: next, revision: revision }; offLatch = true; select( next );
				if ( next === 'off' ) { render( 'off' ); }
				flushAccount(); return;
			}
			var saved = saveAnonymous( next ); offLatch = !saved;
			if ( next === 'off' ) { notify( anonymousOffNotice, false ); }
			if ( !saved ) {
				var message = 'This browser could not remember the choice for this session.';
				if ( requestedMode ) { message += ' Close this tab to fully stop the loaded ad provider.'; }
				notify( message, true ); if ( next === 'off' ) { control.title = anonymousOffNotice; }
			} else if ( next !== 'off' ) { control.title = anonymousTitle( next ); }
			render( saved ? next : 'off' );
		}
		function refresh() {
			if ( !guardIdentity() ) { return; }
			if ( account ) { refreshAccount(); }
			else if ( !offLatch ) { var next = readAnonymous(); control.title = anonymousTitle( next ); render( next ); }
		}
		choices.forEach( function ( input ) { input.addEventListener( 'change', function () { if ( input.checked ) { choose( input.value ); } } ); } );
		if ( closePreview ) {
			closePreview.addEventListener( 'click', function () { choices[ 0 ].focus(); choose( 'off' ); } );
		}
		window.addEventListener( 'pageshow', function ( event ) { if ( !account || event.persisted ) { refresh(); } } );
		window.addEventListener( 'focus', refresh );
		document.addEventListener( 'visibilitychange', function () { if ( document.visibilityState === 'visible' ) { refresh(); } } );
		if ( account ) { control.title = 'Loading your saved ad preference…'; refreshAccount(); }
		else { var initial = readAnonymous(); control.title = anonymousTitle( initial ); disable( false ); render( initial ); }
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else { init(); }
}());
