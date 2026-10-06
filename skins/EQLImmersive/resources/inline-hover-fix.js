/* EQLImmersive: Inline hover punctuation fix.
 * Converts block-level .hbdiv to inline span, merges orphaned
 * punctuation paragraphs caused by item transclusions in prose. */
(function () {
	'use strict';

	function isPunctuationParagraph( el ) {
		var text;

		if ( !el || el.nodeType !== 1 || el.tagName !== 'P' ) {
			return false;
		}

		text = ( el.textContent || '' ).trim();

		return /^[.,;:!?)]/.test( text );
	}

	function convertHbdivToSpan( el ) {
		var span;
		var i;

		if ( !el || el.tagName !== 'DIV' || !el.classList.contains( 'hbdiv' ) ) {
			return el;
		}

		span = document.createElement( 'span' );

		for ( i = 0; i < el.attributes.length; i++ ) {
			span.setAttribute( el.attributes[ i ].name, el.attributes[ i ].value );
		}

		while ( el.firstChild ) {
			span.appendChild( el.firstChild );
		}

		el.parentNode.replaceChild( span, el );

		return span;
	}

	function mergeBrokenInlineHover( hbdiv ) {
		var previous;
		var next;

		hbdiv = convertHbdivToSpan( hbdiv );

		if ( !hbdiv || !hbdiv.parentNode ) {
			return;
		}

		previous = hbdiv.previousElementSibling;
		next = hbdiv.nextElementSibling;

		if ( previous && previous.tagName === 'P' && isPunctuationParagraph( next ) ) {
			previous.appendChild( hbdiv );

			while ( next.firstChild ) {
				previous.appendChild( next.firstChild );
			}

			next.parentNode.removeChild( next );
			return;
		}

		if ( isPunctuationParagraph( next ) ) {
			while ( next.firstChild ) {
				hbdiv.appendChild( next.firstChild );
			}

			next.parentNode.removeChild( next );
		}
	}

	function fixInlineItemHoverPunctuation() {
		var root = document.querySelector( '.mw-parser-output' );

		if ( !root ) {
			return;
		}

		root.querySelectorAll( '.hbdiv' ).forEach( mergeBrokenInlineHover );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', fixInlineItemHoverPunctuation );
	} else {
		fixInlineItemHoverPunctuation();
	}
}() );

/* Footer page-load stats gear widget. */