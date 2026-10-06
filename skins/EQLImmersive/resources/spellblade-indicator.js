/* EQLImmersive: Spellblade compatibility indicator.
 * Adds green sword icon to spells where reuse_time <= casting_time.
 * Reads Cast/Reuse from hidden hover content. Excludes Bard songs. */
/* =====================================================
   EQL Spell Tables — Spellblade compatibility indicator

   Adds / updates a green sword icon beside spell names when:
   reuse_time <= casting_time

   Excludes Bard songs.

   Reads existing hidden spell hover content:
   "Cast: X.XX sec"
   "Reuse: X.XX sec"
   ===================================================== */

(function () {
	'use strict';

	var ICON_CLASS = 'eql-fast-reuse-icon';
	var ROW_CLASS = 'eql-fast-reuse-spell-row';
	var SPELLBLADE_URL = 'https://eqlwiki.com/Disciplines#Invocations';
	var SPELLBLADE_LABEL = 'Spellblade Compatible';

	function normalizeText( text ) {
		return String( text || '' )
			.replace( /\u00a0/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function parseSpellTime( text, label ) {
		var pattern = new RegExp( label + '\\s*:\\s*([0-9]+(?:\\.[0-9]+)?)\\s*sec', 'i' );
		var match = text.match( pattern );

		if ( !match ) {
			return null;
		}

		return parseFloat( match[ 1 ] );
	}

	function isCurrentBardPage() {
		var pageName = '';
		var title = '';

		if ( window.mw && mw.config ) {
			pageName = normalizeText( mw.config.get( 'wgPageName' ) || '' ).replace( /_/g, ' ' );
			title = normalizeText( mw.config.get( 'wgTitle' ) || '' ).replace( /_/g, ' ' );

			if ( pageName === 'Bard' || title === 'Bard' ) {
				return true;
			}
		}

		if (
			document.body &&
			document.body.classList &&
			document.body.classList.contains( 'page-Bard' )
		) {
			return true;
		}

		return false;
	}

	function isInsideBardLazySection( wrapper ) {
		var section;
		var spellClass;

		if ( !wrapper || !wrapper.closest ) {
			return false;
		}

		section = wrapper.closest( '.eql-spell-lazy-section[data-eql-spell-class]' );

		if ( !section ) {
			return false;
		}

		spellClass = normalizeText( section.getAttribute( 'data-eql-spell-class' ) || '' );

		return spellClass.toLowerCase() === 'bard';
	}

	function textLooksLikeBardClassList( text ) {
		var normalized = normalizeText( text );

		if ( normalized === '' ) {
			return false;
		}

		/*
		 * Rendered Spellpage / Spellpagesmart class-list forms:
		 *   Bard - Level 8
		 *   Bard – Level 8
		 *   Bard Level 8
		 *   BRD - Level 8
		 */
		if ( /\b(?:Bard|BRD)\s*(?:[-–—]\s*)?Level\s*\d+\b/i.test( normalized ) ) {
			return true;
		}

		/*
		 * Raw or lightly-rendered wiki-link forms:
		 *   [[Bard]] - Level 8
		 *   [[Bard|Bard]] - Level 8
		 */
		if ( /\[\[\s*Bard(?:\s*\|[^\]]*)?\s*\]\]\s*(?:[-–—]\s*)?Level\s*\d+\b/i.test( normalized ) ) {
			return true;
		}

		/*
		 * Alternate compact class-summary forms.
		 */
		if ( /\bClass(?:es)?\s*:\s*[A-Za-z\s,\/-]*\b(?:Bard|BRD)\b/i.test( normalized ) ) {
			return true;
		}

		return false;
	}

	function isBardSongWrapper( wrapper, hoverText ) {
		/*
		 * Best signal for the Master Spell List / lazy transclusions:
		 * the section wrapper explicitly says which class was loaded.
		 */
		if ( isInsideBardLazySection( wrapper ) ) {
			return true;
		}

		/*
		 * Best signal for the Bard class page itself, where RadSpellRow2
		 * rows do not necessarily contain "Bard - Level #".
		 */
		if ( isCurrentBardPage() ) {
			return true;
		}

		/*
		 * Fallback for individual Bard spell pages or mixed tables where
		 * the class list is actually present in the hover content.
		 */
		if ( textLooksLikeBardClassList( hoverText ) ) {
			return true;
		}

		return false;
	}

	function makeSpellbladeIcon() {
		var icon = document.createElement( 'a' );

		icon.className = ICON_CLASS;
		icon.href = SPELLBLADE_URL;
		icon.title = SPELLBLADE_LABEL;
		icon.setAttribute( 'aria-label', SPELLBLADE_LABEL );
		icon.setAttribute( 'role', 'img' );

		/* Prevent surrounding hover/click handlers from swallowing the link click. */
		icon.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
		} );

		return icon;
	}

	function upgradeExistingIcon( existingIcon ) {
		var replacement;

		if ( !existingIcon ) {
			return null;
		}

		/* Already an anchor: just normalize it. */
		if ( existingIcon.tagName && existingIcon.tagName.toLowerCase() === 'a' ) {
			existingIcon.href = SPELLBLADE_URL;
			existingIcon.title = SPELLBLADE_LABEL;
			existingIcon.setAttribute( 'aria-label', SPELLBLADE_LABEL );
			existingIcon.setAttribute( 'role', 'img' );

			existingIcon.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
			} );

			return existingIcon;
		}

		/* Old span/icon: replace it with a real link. */
		replacement = makeSpellbladeIcon();
		existingIcon.replaceWith( replacement );

		return replacement;
	}

	function removeExistingIcon( wrapper ) {
		var existingIcons;
		var row;

		if ( !wrapper ) {
			return;
		}

		existingIcons = wrapper.querySelectorAll( '.' + ICON_CLASS );

		existingIcons.forEach( function ( existingIcon ) {
			existingIcon.remove();
		} );

		row = wrapper.closest( 'tr' );

		if ( row ) {
			row.classList.remove( ROW_CLASS );
		}
	}

	function annotateSpellWrapper( wrapper ) {
		var hover;
		var link;
		var hoverText;
		var castTime;
		var reuseTime;
		var existingIcon;
		var icon;
		var row;

		if ( !wrapper ) {
			return;
		}

		hover = wrapper.querySelector( ':scope > span.hb' ) || wrapper.querySelector( 'span.hb' );
		link = wrapper.querySelector( 'a' );

		if ( !hover || !link ) {
			return;
		}

		hoverText = hover.textContent || '';

		/*
		 * Bard exclusion only. Cast/Reuse parsing below is unchanged from
		 * the original working script.
		 */
		if ( isBardSongWrapper( wrapper, hoverText ) ) {
			removeExistingIcon( wrapper );
			return;
		}

		castTime = parseSpellTime( hoverText, 'Cast' );
		reuseTime = parseSpellTime( hoverText, 'Reuse' );

		if ( castTime === null || reuseTime === null ) {
			return;
		}

		/*
		 * This excludes instant / zero-cast spells.
		 * To include instant spells, remove "castTime <= 0 ||".
		 */
		if ( castTime <= 0 || reuseTime > castTime + 0.0001 ) {
			return;
		}

		existingIcon = wrapper.querySelector( '.' + ICON_CLASS );

		if ( existingIcon ) {
			icon = upgradeExistingIcon( existingIcon );
		} else {
			icon = makeSpellbladeIcon();
			link.insertAdjacentElement( 'afterend', icon );
		}

		row = wrapper.closest( 'tr' );
		if ( row ) {
			row.classList.add( ROW_CLASS );
		}
	}

	function annotateSpellTables( root ) {
		var scope = root || document;

		scope.querySelectorAll(
			'table.eoTable2 .spell-hbdiv, ' +
			'table.eoTable .spell-hbdiv, ' +
			'table.wikitable .spell-hbdiv'
		).forEach( annotateSpellWrapper );
	}

	function initSpellbladeIndicators() {
		annotateSpellTables( document );

		if ( window.mw && mw.hook ) {
			mw.hook( 'wikipage.content' ).add( function ( $content ) {
				if ( $content && $content[ 0 ] ) {
					annotateSpellTables( $content[ 0 ] );
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initSpellbladeIndicators );
	} else {
		initSpellbladeIndicators();
	}
}());
