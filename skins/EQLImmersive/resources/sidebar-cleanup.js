/* EQLImmersive: Sidebar placeholder cleanup.
 * Removes __EQL_PAGE_TOOLS__ / __EQL_ADMIN_TOOLS__ marker items
 * from the sidebar when NOT using EQLImmersive-family skins.
 * (Hooks.php handles these server-side for this skin.) */

/* Hide EQLImmersive sidebar placeholders outside the EQLImmersive skin. */
$( function () {
	var body = document.body;
	var markers = [
		'__EQL_ADMIN_TOOLS__',
		'EQL_ADMIN_TOOLS',
		'__EQL_PAGE_TOOLS__',
		'EQL_PAGE_TOOLS'
	];

	function hasMarker( text ) {
		text = String( text || '' );

		return markers.some( function ( marker ) {
			return text.indexOf( marker ) !== -1;
		} );
	}

	function removePlaceholderItems() {
		$( '.mw-portlet, .vector-menu, nav, .portal' ).each( function () {
			var $section = $( this );
			var removedAny = false;

			$section.find( 'li, a' ).each( function () {
				var $el = $( this );
				var text = $el.text();
				var href = $el.attr( 'href' ) || '';
				var id = $el.attr( 'id' ) || '';
				var $li;

				if ( hasMarker( text ) || hasMarker( href ) || hasMarker( id ) ) {
					$li = $el.is( 'li' ) ? $el : $el.closest( 'li' );

					if ( $li.length ) {
						$li.remove();
					} else {
						$el.remove();
					}

					removedAny = true;
				}
			} );

			if ( removedAny ) {
				var hasVisibleItems = $section.find( 'li' ).filter( function () {
					return $.trim( $( this ).text() ) !== '';
				} ).length > 0;

				if ( !hasVisibleItems ) {
					$section.remove();
				}
			}
		} );
	}

	if ( !body || body.classList.contains( 'skin-eqlimmersive' ) ) {
		return;
	}

	removePlaceholderItems();
} );

/* Fix punctuation/newline issues caused by block-level item hover transclusions used inline in prose. */