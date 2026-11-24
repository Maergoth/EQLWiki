/* EQLImmersive: browser-only audience measurement choice. @license GPL-2.0-or-later */
( function () {
	'use strict';
	var settings = mw.config.get( 'wgEQLAnalyticsPreferences' );
	if ( !settings || settings.origin !== 'https://eqlwiki.com' ||
		window.location.origin !== settings.origin || typeof mw.hook !== 'function' ) { return; }
	var cookieName = 'eql_analytics_opt_out';
	var notificationKey = 'eql-analytics-preference-change';
	var stateHook = mw.hook( 'eql.analytics.state' );
	var failedSave = false, reloading = false, allowed = false, errorMessage = '';
	var dialog, checkbox, status, opener;

	function gpc() { return navigator.globalPrivacyControl === true; }
	function read() {
		try {
			if ( navigator.cookieEnabled === false ) { throw new Error( 'Cookies unavailable' ); }
			var cookies = document.cookie;
			if ( typeof cookies !== 'string' ) { throw new Error( 'Cookies unreadable' ); }
			var records = cookies.split( ';' ).map( function ( entry ) { return entry.trim(); } )
				.filter( function ( entry ) { return entry.indexOf( cookieName + '=' ) === 0; } );
			// Any present value is an opt-out, including a malformed older record.
			return { allowed: records.length === 0, records: records, readable: true };
		} catch ( error ) {
			return { allowed: false, records: [], readable: false };
		}
	}
	function publish( enabled ) {
		allowed = enabled === true;
		stateHook.fire( allowed );
	}
	function updateDialog() {
		if ( !dialog ) { return; }
		checkbox.checked = allowed;
		checkbox.disabled = gpc();
		status.textContent = errorMessage || ( gpc() ?
			'Global Privacy Control is on. Audience measurement stays off.' : '' );
		status.classList.toggle( 'eql-analytics-settings-error', !!errorMessage );
	}
	function refresh() {
		var result = read();
		if ( !failedSave ) {
			errorMessage = result.readable ? '' :
				'This browser could not read your preference. Audience measurement is off.';
		}
		publish( !failedSave && !reloading && result.allowed && !gpc() );
		updateDialog();
	}
	function save( event ) {
		event.preventDefault();
		if ( reloading ) { return; }
		var enable = checkbox.checked && !gpc();
		// Stop first, including a failed opt-out write. A successful save reloads
		// before any opt-in can initialize a fresh consent/measurement runtime.
		publish( false );
		try {
			document.cookie = cookieName + ( enable ? '=; Max-Age=0' : '=1; Max-Age=31536000' ) +
				'; Path=/; SameSite=Lax; Secure';
			var result = read();
			if ( !result.readable || ( enable ? !result.allowed :
				result.records.length !== 1 || result.records[ 0 ] !== cookieName + '=1' ) ) {
				throw new Error( 'Preference not saved' );
			}
		} catch ( error ) {
			failedSave = true;
			errorMessage = 'Your choice could not be saved. Audience measurement is off in this tab. ' +
				'Other pages may use your previous choice; retry to save it.';
			updateDialog();
			return;
		}
		failedSave = false;
		reloading = true;
		errorMessage = '';
		// This timestamp only asks other tabs to reread the authoritative cookie.
		// Storage is optional and contains no account or measurement identifier.
		try { window.localStorage.setItem( notificationKey, String( Date.now() ) ); } catch ( error ) { /* Focus rechecks too. */ }
		window.location.reload();
	}
	function element( tag, text ) {
		var node = document.createElement( tag );
		if ( text ) { node.textContent = text; }
		return node;
	}
	function init() {
		var footer = document.getElementById( 'footer-places' );
		if ( !footer || document.getElementById( 'eql-analytics-settings-link' ) ) { return; }
		var item = element( 'li' ); item.id = 'footer-places-eql-analytics';
		opener = element( 'a', 'Analytics settings' );
		opener.id = 'eql-analytics-settings-link'; opener.href = '#eql-analytics-settings';
		opener.setAttribute( 'aria-haspopup', 'dialog' ); opener.setAttribute( 'aria-controls', 'eql-analytics-settings' );
		item.appendChild( opener ); footer.appendChild( item );
		dialog = element( 'dialog' ); dialog.id = 'eql-analytics-settings';
		dialog.setAttribute( 'aria-labelledby', 'eql-analytics-settings-title' );
		dialog.setAttribute( 'aria-describedby', 'eql-analytics-settings-description' );
		var form = element( 'form' );
		var heading = element( 'h2', 'Analytics settings' ); heading.id = 'eql-analytics-settings-title';
		var description = element( 'p', 'Help us understand visits and reading time with Google Analytics. ' +
			'This choice applies to this browser, whether you are signed in or out. Ads settings are separate. ' +
			'Google consent choices and Global Privacy Control still apply.' );
		description.id = 'eql-analytics-settings-description';
		var label = element( 'label' ); label.className = 'eql-analytics-settings-choice';
		checkbox = element( 'input' ); checkbox.type = 'checkbox'; checkbox.name = 'allow-audience-measurement';
		label.append( checkbox, document.createTextNode( 'Allow audience measurement' ) );
		status = element( 'p' ); status.className = 'eql-analytics-settings-status';
		status.setAttribute( 'role', 'status' ); status.setAttribute( 'aria-live', 'polite' );
		var note = element( 'p', 'Saving reloads this page. Turning measurement off does not delete data already sent.' );
		var policy = element( 'a', 'Privacy and cookie policy' ); policy.href = '/EQLWiki:Privacy_policy';
		var actions = element( 'div' ); actions.className = 'eql-analytics-settings-actions';
		var close = element( 'button', 'Close' ); close.type = 'button';
		var submit = element( 'button', 'Save preferences' ); submit.type = 'submit';
		actions.append( close, submit );
		form.append( heading, description, label, status, note, policy, actions );
		dialog.appendChild( form ); document.body.appendChild( dialog );
		form.addEventListener( 'submit', save );
		close.addEventListener( 'click', function () { dialog.close(); } );
		dialog.addEventListener( 'close', function () { opener.focus(); } );
		opener.addEventListener( 'click', function ( event ) {
			event.preventDefault(); refresh();
			if ( !dialog.open ) { dialog.showModal(); }
			if ( checkbox.disabled ) { close.focus(); } else { checkbox.focus(); }
		} );
		updateDialog();
	}
	// The remembered hook must be available to a later-loaded analytics module,
	// including on tag-free pages where only this first-party control runs.
	refresh();
	window.addEventListener( 'focus', refresh );
	window.addEventListener( 'pageshow', function ( event ) { if ( event.persisted ) { refresh(); } } );
	document.addEventListener( 'visibilitychange', function () { if ( document.visibilityState === 'visible' ) { refresh(); } } );
	window.addEventListener( 'storage', function ( event ) { if ( event.key === notificationKey || event.key === null ) { refresh(); } } );
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init, { once: true } ); }
	else { init(); }
}() );
