/* EQLImmersive: consent-gated public article engagement. @license GPL-2.0-or-later */
( function () {
	'use strict';
	var settings = mw.config.get( 'wgEQLAnalytics' );
	var provider = mw.config.get( 'wgEQLAdSense' );
	if ( !settings || !provider || provider.preview === true || provider.environment !== 'production' ||
		navigator.globalPrivacyControl === true ||
		settings.origin !== 'https://eqlwiki.com' || window.location.origin !== settings.origin ||
		typeof settings.measurementId !== 'string' || !/^G-[A-Z0-9]{6,20}$/.test( settings.measurementId ) ||
		settings.measurementId.trim() !== settings.measurementId || typeof mw.hook !== 'function' ) { return; }
	var canonical;
	try {
		canonical = new URL( settings.pageLocation );
		if ( canonical.origin !== settings.origin || canonical.username || canonical.password ||
			canonical.search || canonical.hash || /%(?:3f|23|0a|0d)/i.test( canonical.pathname ) ||
			canonical.pathname.indexOf( ':' ) !== -1 || /%3a/i.test( canonical.pathname ) ||
			/^\/(?:index|api|load)\.php(?:\/|$)/i.test( canonical.pathname ) ||
			/^\/bb(?:\/|$)/i.test( canonical.pathname ) ||
			typeof settings.pageTitle !== 'string' || !settings.pageTitle ) { return; }
	} catch ( error ) { return; }
	var id = settings.measurementId;
	var disableKey = 'ga-disable-' + id;
	var adsEnabled = false, consentReady = false, registered = false, tcfRegistered = false;
	var consentPromptOpen = false, awaitingTcfSnapshot = false, tcfSnapshotSeen = false;
	var requested = false, configured = false, stopped = false, active = true;
	var userId = mw.config.get( 'wgUserId' ), userName = mw.config.get( 'wgUserName' );
	var temporary = mw.config.get( 'wgUserIsTemp' );
	window[ disableKey ] = true;

	function sameIdentity() {
		return mw.config.get( 'wgUserId' ) === userId && mw.config.get( 'wgUserName' ) === userName &&
			mw.config.get( 'wgUserIsTemp' ) === temporary;
	}
	function stop( permanent ) {
		window[ disableKey ] = true;
		if ( permanent ) { stopped = true; }
		// The documented kill switch prevents further GA requests/cookie writes.
		// Do not call consent update with denied: advanced-mode pings are unwanted.
	}
	function consentPermits() {
		if ( !consentReady || !window.googlefc || typeof window.googlefc.getGoogleConsentModeValues !== 'function' ) { return false; }
		try {
			var values = window.googlefc.getGoogleConsentModeValues();
			var status = values && values.analyticsStoragePurposeConsentStatus;
			// GoogleFC's documented enum: 1 GRANTED; 3 NOT_APPLICABLE.
			// UNKNOWN, DENIED and NOT_CONFIGURED all remain blocked.
			return status === 1 || status === 3;
		} catch ( error ) { return false; }
	}
	function permitted() {
		if ( !sameIdentity() || navigator.globalPrivacyControl === true ) { stop( true ); }
		return active && adsEnabled && !stopped && !consentPromptOpen && !awaitingTcfSnapshot && sameIdentity() && consentPermits();
	}
	function reconcile() {
		if ( !permitted() ) { stop( requested && consentReady && !consentPermits() ); return; }
		// A downloaded tag executes before its load event. Keep its kill switch
		// engaged until that event rechecks consent and installs our configuration.
		window[ disableKey ] = !configured;
		if ( requested ) { return; }
		requested = true;
		// Use a dedicated command queue, but Google consent state is shared: only
		// set analytics_storage here. The existing CMP owns advertising consent.
		// No remote loader or cookieless measurement request exists before this gate.
		window.eqlAnalyticsDataLayer = window.eqlAnalyticsDataLayer || [];
		function tag() { window.eqlAnalyticsDataLayer.push( arguments ); }
		var parameters = {
			page_location: canonical.href, page_title: settings.pageTitle, page_referrer: '',
			allow_google_signals: false, allow_ad_personalization_signals: false,
			send_page_view: false, cookie_prefix: 'eql', cookie_domain: 'none', cookie_path: '/',
			cookie_expires: 2592000, cookie_update: false, cookie_flags: 'SameSite=Lax;Secure'
		};
		tag( 'consent', 'default', { analytics_storage: 'granted' } );
		// Google's tag can initialize its configured destination while processing
		// js, before our load handler. Supply safe defaults before that bootstrap,
		// including cookie settings and suppression of its automatic pageview.
		tag( 'set', parameters );
		tag( 'js', new Date() );
		var script = document.createElement( 'script' );
		script.async = true;
		script.referrerPolicy = 'no-referrer';
		script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( id ) + '&l=eqlAnalyticsDataLayer';
		script.addEventListener( 'load', function () {
			if ( configured || !permitted() ) { stop( true ); return; }
			tag( 'config', id, parameters );
			if ( !permitted() ) { stop( true ); return; }
			configured = true;
			window[ disableKey ] = false;
			// Explicit event fields also win over a destination's page defaults.
			// A single manual view avoids both early and duplicate auto-pageviews.
			tag( 'event', 'page_view', {
				send_to: id,
				page_location: canonical.href, page_title: settings.pageTitle, page_referrer: '',
				allow_google_signals: false, allow_ad_personalization_signals: false
			} );
		}, { once: true } );
		script.addEventListener( 'error', function () { stop( true ); }, { once: true } );
		document.head.appendChild( script );
	}
	function registerConsent() {
		if ( registered ) { return; }
		registered = true;
		window.googlefc = window.googlefc || {};
		window.googlefc.callbackQueue = window.googlefc.callbackQueue || [];
		if ( typeof window.googlefc.callbackQueue.push !== 'function' ) { stop( true ); return; }
		window.googlefc.callbackQueue.push( {
			CONSENT_API_READY: function () {
				if ( tcfRegistered || typeof window.__tcfapi !== 'function' ) { return; }
				tcfRegistered = true; awaitingTcfSnapshot = true;
				try { window.__tcfapi( 'addEventListener', 0, function ( data, success ) {
					var firstSnapshot = !tcfSnapshotSeen;
					tcfSnapshotSeen = true; awaitingTcfSnapshot = false;
					if ( !success || !data || data.cmpStatus === 'error' ) { stop( true ); return; }
					if ( data.eventStatus === 'cmpuishown' ) {
						consentPromptOpen = true;
						// An initial UNKNOWN snapshot can be ready before its first
						// prompt. Readiness alone is not a prior consent decision.
						stop( requested || ( !firstSnapshot && consentPermits() ) );
						return;
					}
					// Once requested, any new consent interaction stops measurement for
					// this document. A fresh navigation can use the new saved decision;
					// no stale CMP callback can resume a revoked session.
					if ( requested && !firstSnapshot && data.eventStatus === 'useractioncomplete' ) {
						stop( true );
					}
					if ( data.eventStatus === 'useractioncomplete' || data.eventStatus === 'tcloaded' ) {
						consentPromptOpen = false;
						reconcile();
					}
				} ); } catch ( error ) { stop( true ); }
			}
		} );
		window.googlefc.callbackQueue.push( {
			CONSENT_MODE_DATA_READY: function () {
				consentReady = true;
				reconcile();
			}
		} );
	}
	mw.hook( 'eql.ads.state' ).add( function ( enabled ) {
		adsEnabled = enabled === true;
		if ( !adsEnabled ) { stop( false ); return; }
		if ( !sameIdentity() ) { stop( true ); return; }
		registerConsent(); reconcile();
	} );
	window.addEventListener( 'pagehide', function () {
		// Allow an already-consented GA tag to finish its normal unload engagement
		// event; prevent a delayed loader/CMP from starting new measurement.
		active = false;
	} );
	window.addEventListener( 'pageshow', function ( event ) {
		if ( !event.persisted ) { return; }
		active = true; stop( false ); reconcile();
	} );
	window.addEventListener( 'focus', reconcile );
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' ) { reconcile(); }
	} );
}() );
