/* =====================================================
   EQL Immersive — footer Contribute / Donate buttons
   Buttons are rendered in skin.mustache.
   ===================================================== */

(function () {
	'use strict';

	function bindButton( id, url ) {
		var button = document.getElementById( id );

		if ( !button || button.dataset.eqlBound === '1' ) {
			return;
		}

		button.dataset.eqlBound = '1';

		button.addEventListener( 'click', function () {
			window.open( url, '_blank', 'noopener' );
		} );
	}

	function initFooterButtons() {
		var body = document.body;

		if ( !body || !body.classList.contains( 'skin-eqlimmersive' ) ) {
			return;
		}

		bindButton( 'eql-contribute-button', 'https://eqlwiki.com/Help:Contents' );
		bindButton( 'eql-support-button', 'https://ko-fi.com/eqlwiki' );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initFooterButtons );
	} else {
		initFooterButtons();
	}
}());