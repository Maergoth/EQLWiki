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
		var banner = document.getElementById( 'eql-ad-banner' );
		var bannerUnit = document.getElementById( 'eql-ad-banner-unit' );
		var content = document.querySelector( '.mw-content-container' );
		var wiki = encodeURIComponent( mw.config.get( 'wgWikiID' ) ||
			window.location.host + ( mw.config.get( 'wgScriptPath' ) || '' ) );
		var cookieName = 'eql_ads_session_v1_' + wiki;
		var sessionKey = 'eql-ads-session-v1:' + wiki;
		var anonymousLifetime = 24 * 60 * 60 * 1000;
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
		var providerReady = false, providerFailed = false, unitRequested = false;
		var api = null, reading = false, saving = false, failedWrite = false, identityInvalid = false;
		var queued = null, revision = 0;
		var rawConfig = mw.config.get( 'wgEQLAdSense' );
		var preview = rawConfig && rawConfig.preview === true && window.location.origin === 'https://test.eqlwiki.com';
		var providerOrigin = rawConfig && rawConfig.environment === 'staging' ? 'https://test.eqlwiki.com' :
			rawConfig && rawConfig.environment === 'production' ? 'https://eqlwiki.com' : null;
		var multiplex = providerOrigin !== null && rawConfig.format === 'multiplex-layouts';
		var manual = rawConfig && ( ( rawConfig.environment === 'staging' && rawConfig.format === 'manual-layouts' ) || multiplex ) &&
			typeof rawConfig.bannerSlot === 'string' && rawConfig.bannerSlot.length === 10 && /^[0-9]{10}$/.test( rawConfig.bannerSlot );
		var manualPreview = preview && rawConfig.manual === true;
		var multiplexLayout = multiplex || ( manualPreview && rawConfig.multiplex === true );
		var layouts = rawConfig && ( ( rawConfig.environment === 'staging' && rawConfig.format === 'layouts' ) || manual ) &&
			typeof rawConfig.sidebarSlot === 'string' && rawConfig.sidebarSlot.length === 10 && /^[0-9]{10}$/.test( rawConfig.sidebarSlot );
		var config = rawConfig && rawConfig.preview !== true && typeof rawConfig.client === 'string' &&
			/^ca-pub-[0-9]{16}$/.test( rawConfig.client ) && rawConfig.client.length === 23 &&
			( rawConfig.format === 'anchor' || layouts ) && window.location.origin === providerOrigin ? rawConfig : null;
		// Blank settings and excluded pages have no ad UI or preference reads.
		// Off retains the selector on eligible pages so readers can enable ads.
		if ( !config && !preview ) { return; }
		control.hidden = false;
		if ( manual || manualPreview ) {
			var bannerChoice = control.querySelector( '[value="anchor"]' );
			bannerChoice.setAttribute( 'aria-label', 'Style 1: Bottom, below content' );
			bannerChoice.parentElement.title = multiplexLayout ? 'Bottom — horizontal ad grid after the content' : 'Bottom — banner after the content';
		}
		if ( sidebar ) { sidebar.classList.toggle( 'eql-ad-multiplex', !!multiplexLayout ); }
		if ( banner ) { banner.classList.toggle( 'eql-ad-multiplex', !!multiplexLayout ); }

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
			if ( content ) { content.classList.toggle( 'eql-ad-side-wide', !!sidebar && !sidebar.hidden &&
				!sidebar.classList.contains( 'eql-ad-awaiting-result' ) &&
				( multiplexLayout ? sidebarRoom() : wideDesktop() ) ); }
		}
		function watchFill( ad, wrapper ) {
			var active = true;
			// Keep pending Google units measurable in normal flow. Only our label,
			// margins and sidebar reservation wait for a visible provider result.
			// Geometry never hides a requested unit or inspects its frame contents.
			function hasVisibleResult() {
				var status = ad.getAttribute( 'data-ad-status' );
				if ( status !== 'filled' && status !== 'unfill-optimized' ) { return false; }
				var box = ad.getBoundingClientRect();
				if ( box.width <= 0 || box.height <= 0 ) { return false; }
				for ( var node = ad; node && node !== wrapper; node = node.parentElement ) {
					var style = window.getComputedStyle( node );
					if ( style.display === 'none' || style.visibility === 'hidden' || style.visibility === 'collapse' ) { return false; }
				}
				return true;
			}
			function update() {
				if ( !active || !ad.isConnected || !wrapper.contains( ad ) ) { return; }
				wrapper.hidden = ad.getAttribute( 'data-ad-status' ) === 'unfilled';
				wrapper.classList.toggle( 'eql-ad-awaiting-result', multiplex && !hasVisibleResult() );
				positionSidebar();
			}
			var observer = new MutationObserver( update );
			var resize = typeof ResizeObserver === 'function' ? new ResizeObserver( update ) : null;
			function start() {
				active = true;
				observer.observe( ad, { attributes: true, attributeFilter: [ 'data-ad-status', 'style', 'class', 'hidden' ] } );
				if ( resize ) { resize.observe( ad ); }
				update();
			}
			window.addEventListener( 'pagehide', function () { active = false; observer.disconnect(); if ( resize ) { resize.disconnect(); } } );
			window.addEventListener( 'pageshow', function ( event ) { if ( event.persisted ) { start(); } } );
			window.addEventListener( 'resize', update );
			start();
		}
		function sidebarRoom() {
			// A non-sticky grid can use portrait/tablet space too, while keeping
			// at least 668px for the article before its own padding.
			return !!content && content.getBoundingClientRect().width >= 1000;
		}
		function positionBanner() {
			var main = document.querySelector( 'main#content' );
			var body = main && main.querySelector( '#bodyContent' );
			if ( !banner || !bannerUnit || !content || !main || !body ) { return false; }
			// Both layouts are siblings of the complete article card. Never put
			// an ad inside the title/body/category or parser-output contracts.
			main.after( banner );
			return true;
		}
		// Choose a fresh anonymous default once. Resizing reflows our column only;
		// it never changes a reader's choice, refreshes ads or requests a second unit.
		var initialDefault = multiplexLayout ?
			( window.innerWidth > window.innerHeight ? 'side' : 'anchor' ) :
			( wideDesktop() && ( preview || layouts ) ? 'side' : 'anchor' );
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
			return next === 'off' ? anonymousOffNotice : 'Ad layout is remembered for 24 hours. Log in to save it to your account.';
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
		function readAnonymousValue( value, fromFallback ) {
			// Retain the existing scoped keys, but version the value so a browser
			// restore or sessionStorage fallback cannot extend the 24-hour choice.
			if ( typeof value !== 'string' ) { return 'off'; }
			var record = /^v2:(off|side|anchor):([0-9]{1,16})$/.exec( value );
			if ( record ) {
				var created = Number( record[ 2 ] ), now = Date.now();
				if ( !Number.isSafeInteger( created ) || created <= 0 || created > now ) { return 'off'; }
				return now - created < anonymousLifetime ? record[ 1 ] : initialDefault;
			}
			// The previous session-only value has no timestamp. Preserve it once,
			// starting a bounded lifetime now; never renew a versioned record.
			if ( [ 'off', 'side', 'anchor', 'on' ].indexOf( value ) !== -1 ) {
				var next = normalize( value );
				if ( fromFallback ) {
					// Timestamp the fallback itself before promoting it to a cookie.
					// If it cannot be updated, never leave an untimed value to revive.
					var upgraded = 'v2:' + next + ':' + Date.now();
					try {
						window.sessionStorage.setItem( sessionKey, upgraded );
						if ( window.sessionStorage.getItem( sessionKey ) !== upgraded ) { return 'off'; }
					} catch ( error ) { return 'off'; }
				}
				return saveAnonymous( next ) ? next : 'off';
			}
			return 'off';
		}
		function readAnonymous() {
			var cookie = readCookie();
			// A present cookie, including an expired or invalid record, wins over
			// any older per-tab fallback. Unreadable cookies fail closed.
			if ( cookie !== null ) {
				if ( typeof cookie === 'string' && cookie.indexOf( 'v2:' ) === 0 ) {
					// An older untimed fallback must not restart its lifetime after
					// the browser expires this authoritative cookie.
					try { window.sessionStorage.removeItem( sessionKey ); } catch ( error ) {}
				}
				return readAnonymousValue( cookie, false );
			}
			try {
				var fallback = window.sessionStorage.getItem( sessionKey );
				if ( fallback !== null ) { return readAnonymousValue( fallback, true ); }
			} catch ( error ) { return 'off'; }
			return initialDefault;
		}
		function saveAnonymous( value ) {
			var record = 'v2:' + value + ':' + Date.now(), saved = false;
			try {
				document.cookie = cookieName + '=' + record + cookieAttributes + '; Max-Age=86400';
				saved = readCookie() === record;
			} catch ( error ) {}
			if ( saved ) {
				try { window.sessionStorage.removeItem( sessionKey ); } catch ( error ) {}
			} else if ( readCookie() === null ) {
				try {
					window.sessionStorage.setItem( sessionKey, record );
					saved = window.sessionStorage.getItem( sessionKey ) === record;
				} catch ( error ) {}
			}
			// Do not recurse through the legacy reader or accept a fallback over
			// an immutable cookie. A retained Off must survive the ensuing reload.
			var cookie = readCookie();
			return saved && ( cookie === record || cookie === null );
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
			if ( requestedMode !== 'anchor' || !manual ) {
				if ( banner ) { banner.hidden = true; }
				if ( bannerUnit ) { bannerUnit.replaceChildren(); }
			}
		}
		function reloadProvider() {
			if ( requestedMode && !reloading && !offLatch ) {
				reloading = true;
				try { window.sessionStorage.setItem( scrollKey, JSON.stringify( {
					page: window.location.pathname + window.location.search, at: Date.now(), x: window.scrollX, y: window.scrollY,
					anonymousOff: !account && mode === 'off'
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
					if ( !account && saved.anonymousOff === true && readAnonymous() === 'off' ) {
						notify( anonymousOffNotice, false );
					}
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
		function gridPlaceholder( host ) {
			var grid = document.createElement( 'div' ); grid.className = 'eql-ad-grid-preview';
			grid.setAttribute( 'aria-label', 'Synthetic grid preview; Google determines real ad count and height' );
			for ( var i = 0; i < 4; i++ ) {
				var tile = document.createElement( 'div' ); tile.className = 'eql-ad-grid-preview-tile';
				tile.textContent = 'Ad preview'; grid.appendChild( tile );
			}
			host.replaceChildren( grid );
		}
		function showPreview() {
			hidePreview();
			if ( mode === 'side' && content && sidebar && sidebarUnit ) {
				if ( multiplexLayout ) { gridPlaceholder( sidebarUnit ); }
				else { placeholder( sidebarUnit, 'Style 2: right side on wide screens, below content on narrow screens. Real ads are off.' ); }
				sidebar.hidden = false; positionSidebar();
			} else if ( mode === 'anchor' && manualPreview && positionBanner() ) {
				if ( multiplexLayout ) { gridPlaceholder( bannerUnit ); }
				else { placeholder( bannerUnit, 'Style 1: bottom banner outside the article. Real ads are off.' ); }
				banner.hidden = false;
			} else if ( mode === 'anchor' && !manualPreview && advertisement && unit && closePreview &&
				document.querySelector( 'main#content #bodyContent > #mw-content-text.mw-body-content' ) &&
				advertisement.nextElementSibling === document.querySelector( '.mw-footer-container' ) ) {
				placeholder( unit, 'Style 1: bottom anchor. Real ads are off.' );
				advertisement.hidden = false; document.body.classList.add( 'eql-ad-preview-open' );
			}
		}
		function requestUnit() {
			if ( !providerReady || providerFailed || unitRequested || reloading || offLatch ||
				mode !== requestedMode || mode === 'off' || !identityMatches() ) { return; }
			unitRequested = true;
			if ( mode === 'side' ) {
				var ad = document.createElement( 'ins' ); ad.className = 'adsbygoogle';
				// Fixed-size display unit in our reserved column; never a custom fixed
				// footer. Its 300px width also fits the narrow in-flow fallback.
				ad.style.cssText = multiplex ? 'display:block' : 'display:inline-block;width:300px;height:250px';
				if ( multiplex ) { ad.dataset.adFormat = 'autorelaxed'; }
				ad.dataset.adClient = config.client; ad.dataset.adSlot = config.sidebarSlot;
				sidebarUnit.appendChild( ad ); sidebar.hidden = false;
				watchFill( ad, sidebar );
			} else if ( manual ) {
				if ( !positionBanner() ) { return; }
				var bannerAd = document.createElement( 'ins' ); bannerAd.className = 'adsbygoogle';
				// Google's documented variable-width, fixed-height responsive pattern.
				// Size the unit inline, never clip a creative or put it in a fixed dock.
				bannerAd.style.cssText = multiplex ? 'display:block' : 'display:block;min-width:250px;max-width:970px;width:100%;height:90px';
				if ( multiplex ) { bannerAd.dataset.adFormat = 'autorelaxed'; }
				bannerAd.dataset.adClient = config.client; bannerAd.dataset.adSlot = config.bannerSlot;
				bannerUnit.appendChild( bannerAd ); banner.hidden = false;
				watchFill( bannerAd, banner );
			}
			if ( mode === 'side' || manual ) { ( window.adsbygoogle = window.adsbygoogle || [] ).push( {} ); }
		}
		function load() {
			if ( mode === 'off' || reloading ) { return; }
			if ( preview ) { showPreview(); return; }
			if ( !config ) { return; }
			if ( requestedMode ) { requestUnit(); return; }
			if ( mode === 'side' && ( !layouts || !content || !sidebar || !sidebarUnit ) ) {
				control.title = 'Style 2 is not configured here yet. Choose 1 for bottom ads.'; return;
			}
			if ( mode !== 'side' && manual && !positionBanner() ) { return; }
			var script = document.createElement( 'script' ); script.async = true; script.crossOrigin = 'anonymous';
			if ( mode === 'anchor' && !manual ) { script.dataset.overlays = 'collapsed-bottom'; }
			script.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + encodeURIComponent( config.client );
			requestedMode = mode;
			if ( mode === 'anchor' && !manual ) { followAnchor(); }
			// A blocked or failed loader must not create an empty panel. Once loaded,
			// mount a visible measurable unit before pushing its one ad request.
			script.addEventListener( 'load', function () { providerReady = true; requestUnit(); }, { once: true } );
			script.addEventListener( 'error', function () { providerFailed = true; }, { once: true } );
			document.head.appendChild( script );
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
				var message = 'This browser could not remember the choice for 24 hours.';
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
