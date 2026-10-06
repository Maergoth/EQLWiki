(function () {
	'use strict';

	var MAIN_MENU_STORAGE_KEY = 'eqlimmersive-main-menu-pinned-v5';

	function getBody() {
		return document.body;
	}

	function hasBodyClass( className ) {
		var body = getBody();
		return !!( body && body.classList.contains( className ) );
	}

	function setBodyClass( className, enabled ) {
		var body = getBody();

		if ( !body ) {
			return;
		}

		body.classList.toggle( className, !!enabled );
	}

	function removeOldSidebarState() {
		try {
			window.localStorage.removeItem( 'eqlimmersive-main-menu-pinned' );
			window.localStorage.removeItem( 'eqlimmersive-main-menu-pinned-v2' );
			window.localStorage.removeItem( 'eqlimmersive-main-menu-pinned-v3' );
			window.localStorage.removeItem( 'eqlimmersive-main-menu-pinned-v4' );
		} catch ( e ) {
			/* Ignore storage failures. */
		}
	}

	function isMainMenuPinnedStored() {
		try {
			return window.localStorage.getItem( MAIN_MENU_STORAGE_KEY ) === '1';
		} catch ( e ) {
			return false;
		}
	}

	function saveMainMenuPinnedState( pinned ) {
		try {
			if ( pinned ) {
				window.localStorage.setItem( MAIN_MENU_STORAGE_KEY, '1' );
			} else {
				window.localStorage.removeItem( MAIN_MENU_STORAGE_KEY );
			}
		} catch ( e ) {
			/* Ignore storage failures. */
		}
	}

	function ensureMainMenuToggle() {
		var existing = document.getElementById( 'eql-main-menu-toggle' );
		var landmark;
		var oldVectorLandmark;
		var headerStart;
		var button;
		var icon;
		var label;

		if ( existing ) {
			existing.style.display = 'inline-flex';
			existing.hidden = false;
			existing.removeAttribute( 'hidden' );

			if (
				existing.parentElement &&
				existing.parentElement.classList.contains( 'vector-main-menu-landmark' )
			) {
				headerStart = document.querySelector( '.vector-header-start' );

				if ( headerStart ) {
					landmark = document.querySelector( '.vector-header-start .eql-main-menu-landmark' );

					if ( !landmark ) {
						landmark = document.createElement( 'nav' );
						landmark.className = 'eql-main-menu-landmark';
						landmark.setAttribute( 'aria-label', 'Site' );
						headerStart.insertBefore( landmark, headerStart.firstChild );
					}

					landmark.appendChild( existing );
				}
			}

			return existing;
		}

		landmark = document.querySelector( '.vector-header-start .eql-main-menu-landmark' );
		oldVectorLandmark = document.querySelector( '.vector-header-start .vector-main-menu-landmark' );
		headerStart = document.querySelector( '.vector-header-start' );

		if ( !landmark && headerStart ) {
			landmark = document.createElement( 'nav' );
			landmark.className = 'eql-main-menu-landmark';
			landmark.setAttribute( 'aria-label', 'Site' );

			if ( oldVectorLandmark && oldVectorLandmark.parentNode === headerStart ) {
				headerStart.insertBefore( landmark, oldVectorLandmark );
			} else {
				headerStart.insertBefore( landmark, headerStart.firstChild );
			}
		}

		if ( !landmark ) {
			return null;
		}

		button = document.createElement( 'button' );
		button.id = 'eql-main-menu-toggle';
		button.type = 'button';
		button.className = 'eql-main-menu-toggle cdx-button cdx-button--fake-button cdx-button--fake-button--enabled cdx-button--weight-quiet cdx-button--icon-only';
		button.setAttribute( 'aria-label', 'Main menu' );
		button.setAttribute( 'aria-expanded', 'false' );
		button.setAttribute( 'title', 'Main menu' );

		icon = document.createElement( 'span' );
		icon.className = 'vector-icon mw-ui-icon-menu mw-ui-icon-wikimedia-menu';
		button.appendChild( icon );

		label = document.createElement( 'span' );
		label.className = 'vector-dropdown-label-text';
		label.textContent = 'Main menu';
		button.appendChild( label );

		landmark.textContent = '';
		landmark.appendChild( button );

		return button;
	}

	function syncMainMenuHostVisibility() {
		var host = document.getElementById( 'eql-main-menu-host' );
		var toggle = document.getElementById( 'eql-main-menu-toggle' );
		var shouldShow = hasBodyClass( 'eql-main-menu-open' ) || hasBodyClass( 'eql-main-menu-pinned' );

		if ( host ) {
			host.style.display = shouldShow ? 'block' : 'none';
		}

		if ( toggle ) {
			toggle.style.display = 'inline-flex';
			toggle.hidden = false;
			toggle.removeAttribute( 'hidden' );
			toggle.setAttribute( 'aria-expanded', shouldShow ? 'true' : 'false' );
		}
	}

	function updateMainMenuPinButton( pinned ) {
		var button = document.getElementById( 'eql-main-menu-pin-toggle' );

		if ( !button ) {
			return;
		}

		button.setAttribute( 'aria-pressed', pinned ? 'true' : 'false' );
		button.setAttribute( 'title', pinned ? 'Unpin main menu' : 'Pin main menu' );
		button.setAttribute( 'aria-label', pinned ? 'Unpin main menu' : 'Pin main menu' );
		button.classList.toggle( 'eql-main-menu-pin-toggle-pinned', pinned );
	}

	function updateNativePinnableHeaderState( menu, pinned ) {
		var header;

		if ( !menu ) {
			return;
		}

		header = menu.querySelector( '.vector-pinnable-header' );

		if ( !header ) {
			return;
		}

		header.classList.toggle( 'vector-pinnable-header-pinned', pinned );
		header.classList.toggle( 'vector-pinnable-header-unpinned', !pinned );
	}

	function setMainMenuPinnedState( pinned ) {
		var menu = document.getElementById( 'vector-main-menu' );

		saveMainMenuPinnedState( pinned );

		setBodyClass( 'eql-main-menu-js', true );
		setBodyClass( 'eql-main-menu-pinned', pinned );
		setBodyClass( 'eql-main-menu-open', pinned );

		document.documentElement.classList.toggle( 'eql-main-menu-pinned-initial', pinned );

		updateNativePinnableHeaderState( menu, pinned );
		ensureMainMenuPinButton( menu );
		updateMainMenuPinButton( pinned );
		syncMainMenuHostVisibility();
	}

	function closeMainMenu() {
		/* Pinned sidebar must persist across outside clicks. */
		if ( hasBodyClass( 'eql-main-menu-pinned' ) ) {
			return;
		}

		setBodyClass( 'eql-main-menu-open', false );
		syncMainMenuHostVisibility();
	}

	function forceCloseMainMenu() {
		setMainMenuPinnedState( false );
		setBodyClass( 'eql-main-menu-open', false );
		syncMainMenuHostVisibility();
	}

	function toggleMainMenu() {
		/* When pinned, only the sidebar X/pin control should close it. */
		if ( hasBodyClass( 'eql-main-menu-pinned' ) ) {
			return;
		}

		setBodyClass( 'eql-main-menu-open', !hasBodyClass( 'eql-main-menu-open' ) );
		setBodyClass( 'eql-user-menu-open', false );
		syncUserMenuHostVisibility();
		syncMainMenuHostVisibility();
	}

	function ensureMainMenuPinButton( menu ) {
		var header;
		var nativePinButtons;
		var button;

		if ( !menu ) {
			return null;
		}

		header = menu.querySelector( '.vector-pinnable-header' );

		if ( !header ) {
			return null;
		}

		nativePinButtons = header.querySelectorAll(
			'.vector-pinnable-header-pin-button, .vector-pinnable-header-unpin-button'
		);

		nativePinButtons.forEach( function ( nativeButton ) {
			nativeButton.setAttribute( 'aria-hidden', 'true' );
			nativeButton.tabIndex = -1;
			nativeButton.classList.add( 'eql-native-pin-button-disabled' );
		} );

		button = document.getElementById( 'eql-main-menu-pin-toggle' );

		if ( !button ) {
			button = document.createElement( 'button' );
			button.id = 'eql-main-menu-pin-toggle';
			button.type = 'button';
			button.className = 'eql-main-menu-pin-toggle';
			button.setAttribute( 'aria-label', 'Pin main menu' );
			button.setAttribute( 'title', 'Pin main menu' );
			button.setAttribute( 'aria-pressed', 'false' );
			button.textContent = 'Pin main menu';
		}

		if ( button.parentNode !== header ) {
			header.appendChild( button );
		}

		button.hidden = false;
		button.style.display = '';

		return button;
	}

	var eqlLastMainMenuTocSignature = '';

	function ensureMainMenuTocSlot( menu ) {
		var slot;
		var header;

		if ( !menu ) {
			return null;
		}

		slot = document.getElementById( 'eql-main-menu-toc-slot' );

		if ( slot ) {
			return slot;
		}

		slot = document.createElement( 'div' );
		slot.id = 'eql-main-menu-toc-slot';
		slot.className = 'eql-main-menu-toc-slot';
		slot.setAttribute( 'aria-label', 'Contents' );

		header = menu.querySelector( '.vector-pinnable-header' );

		if ( header && header.parentNode === menu ) {
			header.insertAdjacentElement( 'afterend', slot );
		} else {
			menu.insertBefore( slot, menu.firstChild );
		}

		return slot;
	}

	function normalizeTocText( text ) {
		return String( text || '' )
			.replace( /\u00a0/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function getHeadingText( heading ) {
		var clone;
		var removable;

		if ( !heading ) {
			return '';
		}

		clone = heading.cloneNode( true );
		removable = clone.querySelectorAll(
			'.mw-editsection, .mw-editsection-bracket, .mw-editsection-divider, ' +
			'.mw-headline-number, .headerlink, script, style'
		);

		removable.forEach( function ( node ) {
			node.remove();
		} );

		return normalizeTocText( clone.textContent );
	}

	function slugifyHeadingId( text, index ) {
		var base;

		base = normalizeTocText( text )
			.toLowerCase()
			.replace( /&/g, ' and ' )
			.replace( /[^a-z0-9_ -]+/g, '' )
			.replace( /\s+/g, '_' )
			.replace( /_+/g, '_' )
			.replace( /^_+|_+$/g, '' );

		if ( !base ) {
			base = 'section';
		}

		return 'eql-section-' + base + '-' + index;
	}

	function ensureHeadingId( heading, text, index ) {
		var headline;
		var id;
		var candidate;
		var suffix;

		if ( heading.id ) {
			return heading.id;
		}

		headline = heading.querySelector( '.mw-headline[id]' );

		if ( headline && headline.id ) {
			return headline.id;
		}

		id = slugifyHeadingId( text, index );
		candidate = id;
		suffix = 2;

		while ( document.getElementById( candidate ) ) {
			candidate = id + '-' + suffix;
			suffix++;
		}

		heading.id = candidate;

		return candidate;
	}

	function getHeadingLevel( heading ) {
		var level;

		if ( !heading || !heading.tagName ) {
			return 2;
		}

		level = parseInt( heading.tagName.replace( /^h/i, '' ), 10 );

		if ( isNaN( level ) || level < 1 || level > 6 ) {
			return 2;
		}

		return level;
	}

	function isHiddenHeading( heading ) {
		var style;

		if ( !heading ) {
			return true;
		}

		style = window.getComputedStyle ? window.getComputedStyle( heading ) : null;

		if (
			style &&
			(
				style.display === 'none' ||
				style.visibility === 'hidden'
			)
		) {
			return true;
		}

		return false;
	}

	function isIgnoredTocHeading( heading ) {
		if ( !heading ) {
			return true;
		}

		if ( !heading.closest( '#mw-content-text .mw-parser-output, #bodyContent .mw-parser-output' ) ) {
			return true;
		}

		if ( isHiddenHeading( heading ) ) {
			return true;
		}

		/* Ignore headings in existing/generated TOCs, menus, navigation, and page chrome. */
		if (
			heading.closest(
				[
					'#toc',
					'.toc',
					'.mw-toc',
					'.vector-toc',
					'#vector-toc',
					'#mw-panel-toc-list',
					'#eql-main-menu-host',
					'#eql-user-menu-host',
					'#eql-main-menu-toc',
					'.eql-main-menu',
					'.eql-main-menu-toc',
					'.eql-main-menu-toc-slot',
					'.eql-announcements',
					'.navbox',
					'.metadata',
					'.catlinks',
					'.mw-indicators'
				].join( ', ' )
			)
		) {
			return true;
		}

		/* Ignore headings from transcluded ability/spell/AA panels and table content.
		   This prevents the huge repeated "Level 1", "Level 2", etc. sections from
		   taking over the guide TOC. */
		if (
			heading.closest(
				[
					'.eql-build-ability-panel',
					'.eql-build-ability-panel-content',
					'.mw-collapsible',
					'table',
					'thead',
					'tbody',
					'tfoot',
					'tr',
					'td',
					'th'
				].join( ', ' )
			)
		) {
			return true;
		}

		return false;
	}

	function collectContentHeadings() {
		var content = document.querySelector( '#mw-content-text .mw-parser-output' ) ||
			document.querySelector( '#bodyContent .mw-parser-output' ) ||
			document.querySelector( '#bodyContent' );
		var headings;
		var items = [];

		if ( !content ) {
			return items;
		}

		headings = content.querySelectorAll( 'h1, h2, h3, h4, h5, h6' );

		headings.forEach( function ( heading, index ) {
			var text;
			var id;
			var level;

			if ( isIgnoredTocHeading( heading ) ) {
				return;
			}

			text = getHeadingText( heading );

			if ( !text ) {
				return;
			}

			id = ensureHeadingId( heading, text, index );
			level = getHeadingLevel( heading );

			if ( !id ) {
				return;
			}

			items.push( {
				id: id,
				text: text,
				level: level
			} );
		} );

		return items;
	}

	function getTocSignatureFromHeadings( headings ) {
		return headings.map( function ( heading ) {
			return heading.level + ':' + heading.id + ':' + heading.text;
		} ).join( '|' );
	}

	function buildStaticTocFromHeadings() {
		var headings = collectContentHeadings();
		var wrapper;
		var list;
		var baseLevel;
		var signature;

		if ( headings.length === 0 ) {
			return null;
		}

		baseLevel = headings.reduce( function ( min, item ) {
			return Math.min( min, item.level );
		}, 6 );

		signature = getTocSignatureFromHeadings( headings );

		wrapper = document.createElement( 'nav' );
		wrapper.id = 'eql-main-menu-toc';
		wrapper.className = 'eql-main-menu-toc';
		wrapper.setAttribute( 'aria-label', 'Contents' );
		wrapper.setAttribute( 'data-eql-main-menu-toc-signature', signature );

		list = document.createElement( 'ul' );
		list.className = 'eql-main-menu-toc-list';

		headings.forEach( function ( heading, index ) {
			var item = document.createElement( 'li' );
			var link = document.createElement( 'a' );
			var number = document.createElement( 'span' );
			var text = document.createElement( 'span' );
			var normalizedLevel = Math.max( 1, heading.level - baseLevel + 1 );

			item.className = [
				'eql-main-menu-toc-item',
				'eql-main-menu-toc-level-' + normalizedLevel,
				'vector-toc-level-' + normalizedLevel
			].join( ' ' );

			item.setAttribute( 'data-eql-toc-heading-id', heading.id );
			item.setAttribute( 'data-eql-toc-heading-level', String( normalizedLevel ) );

			link.className = 'eql-main-menu-toc-link';
			link.setAttribute( 'href', '#' + heading.id );

			number.className = 'eql-main-menu-toc-number';
			number.textContent = String( index + 1 );

			text.className = 'eql-main-menu-toc-text';
			text.textContent = heading.text;

			link.appendChild( number );
			link.appendChild( text );
			item.appendChild( link );
			list.appendChild( item );
		} );

		wrapper.appendChild( list );

		return wrapper;
	}

	function updateStaticTocActiveState() {
		var currentHash = window.location.hash;
		var links = document.querySelectorAll( '#eql-main-menu-toc .eql-main-menu-toc-link' );

		links.forEach( function ( link ) {
			link.parentNode.classList.toggle(
				'eql-main-menu-toc-item-active',
				currentHash && link.getAttribute( 'href' ) === currentHash
			);
		} );
	}

	function applyPageTocLimitClass() {
		var body = document.body;
		var root;
		var marker;
		var match;
		var i;

		if ( !body ) {
			return;
		}

		for ( i = 1; i <= 6; i++ ) {
			body.classList.remove( 'eql-page-toclimit-' + i );
		}

		root = document.querySelector( '#mw-content-text .mw-parser-output' ) ||
			document.querySelector( '#bodyContent .mw-parser-output' );

		if ( !root ) {
			return;
		}

		marker = root.querySelector( '[class*="toclimit-"]' );

		if ( !marker ) {
			return;
		}

		match = String( marker.className || '' ).match( /\btoclimit-([1-6])\b/ );

		if ( match ) {
			body.classList.add( 'eql-page-toclimit-' + match[ 1 ] );
		}
	}

	function disableNativeVectorToc() {
		var tocCheckbox = document.getElementById( 'vector-page-titlebar-toc-checkbox' );
		var tocDropdown = document.getElementById( 'vector-page-titlebar-toc' );
		var tocPinnedContainer = document.getElementById( 'vector-toc-pinned-container' );
		var tocUnpinnedContainer = document.getElementById( 'vector-page-titlebar-toc-unpinned-container' );
		var stickyTocDropdown = document.getElementById( 'vector-sticky-header-toc' );

		if ( tocCheckbox ) {
			tocCheckbox.checked = false;
			tocCheckbox.setAttribute( 'aria-hidden', 'true' );
			tocCheckbox.tabIndex = -1;
		}

		if ( tocDropdown ) {
			tocDropdown.classList.add( 'eql-original-toc-disabled' );
		}

		if ( tocPinnedContainer ) {
			tocPinnedContainer.classList.add( 'eql-original-toc-disabled' );
		}

		if ( tocUnpinnedContainer ) {
			tocUnpinnedContainer.classList.add( 'eql-original-toc-disabled' );
		}

		if ( stickyTocDropdown ) {
			stickyTocDropdown.classList.add( 'eql-original-toc-disabled' );
		}
	}

	function buildTocIntoMainMenu() {
		var body = document.body;
		var menu;
		var slot;
		var staticToc;
		var existingToc;
		var signature;

		if ( !body || !body.classList.contains( 'skin-eqlimmersive' ) ) {
			return;
		}

		menu = document.getElementById( 'vector-main-menu' );

		if ( !menu ) {
			return;
		}

		applyPageTocLimitClass();
		staticToc = buildStaticTocFromHeadings();

		if ( !staticToc ) {
			body.classList.remove( 'eql-main-menu-has-toc' );

			existingToc = document.getElementById( 'eql-main-menu-toc' );

			if ( existingToc && existingToc.parentNode ) {
				existingToc.parentNode.removeChild( existingToc );
			}

			eqlLastMainMenuTocSignature = '';
			disableNativeVectorToc();
			document.documentElement.classList.add( 'eql-toc-stable' );

			return;
		}

		signature = staticToc.getAttribute( 'data-eql-main-menu-toc-signature' ) || '';

		if ( signature === eqlLastMainMenuTocSignature ) {
			disableNativeVectorToc();
			body.classList.add( 'eql-main-menu-has-toc' );
			updateStaticTocActiveState();
			document.documentElement.classList.add( 'eql-toc-stable' );
			return;
		}

		slot = ensureMainMenuTocSlot( menu );

		if ( !slot ) {
			return;
		}

		existingToc = document.getElementById( 'eql-main-menu-toc' );

		if ( existingToc && existingToc.parentNode ) {
			existingToc.parentNode.removeChild( existingToc );
		}

		slot.appendChild( staticToc );
		eqlLastMainMenuTocSignature = signature;

		body.classList.add( 'eql-main-menu-has-toc' );

		disableNativeVectorToc();
		updateStaticTocActiveState();
		document.documentElement.classList.add( 'eql-toc-stable' );
	}

	function initMainMenu() {
		var body = document.body;
		var toggle;
		var menu;
		var host;
		var pinButton;
		var pinned;

		if ( !body || !body.classList.contains( 'skin-eqlimmersive' ) ) {
			return;
		}

		removeOldSidebarState();

		toggle = ensureMainMenuToggle();
		menu = document.getElementById( 'vector-main-menu' );
		host = document.getElementById( 'eql-main-menu-host' );

		if ( !toggle || !menu || !host ) {
			return;
		}

		body.classList.add( 'eql-main-menu-js' );

		pinButton = ensureMainMenuPinButton( menu );
		pinned = isMainMenuPinnedStored();

		setMainMenuPinnedState( pinned );

		if ( !pinned ) {
			setBodyClass( 'eql-main-menu-open', false );
			syncMainMenuHostVisibility();
		}

		buildTocIntoMainMenu();
		window.setTimeout( buildTocIntoMainMenu, 250 );
		window.setTimeout( buildTocIntoMainMenu, 750 );
		window.setTimeout( buildTocIntoMainMenu, 1500 );

		window.addEventListener( 'hashchange', updateStaticTocActiveState );

		toggle.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			toggleMainMenu();
		} );

		if ( pinButton ) {
			pinButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();

				if ( hasBodyClass( 'eql-main-menu-pinned' ) ) {
					forceCloseMainMenu();
				} else {
					setMainMenuPinnedState( true );
				}
			} );
		}

		host.addEventListener( 'click', function ( event ) {
			var pinToggle = event.target.closest( '#eql-main-menu-pin-toggle' );

			if ( pinToggle ) {
				event.preventDefault();
				event.stopPropagation();

				if ( hasBodyClass( 'eql-main-menu-pinned' ) ) {
					forceCloseMainMenu();
				} else {
					setMainMenuPinnedState( true );
				}

				return;
			}

			event.stopPropagation();
		} );

		host.addEventListener( 'mousedown', function ( event ) {
			event.stopPropagation();
		} );
	}

	function syncUserMenuHostVisibility() {
		var host = document.getElementById( 'eql-user-menu-host' );
		var isOpen = hasBodyClass( 'eql-user-menu-open' );

		if ( host ) {
			host.style.display = isOpen ? 'block' : 'none';
		}
	}

	function positionUserMenu() {
		var body = document.body;
		var label = document.getElementById( 'vector-user-links-dropdown-label' );
		var host = document.getElementById( 'eql-user-menu-host' );
		var rect;
		var right;

		if (
			!body ||
			!host ||
			!label ||
			!body.classList.contains( 'eql-user-menu-open' )
		) {
			return;
		}

		rect = label.getBoundingClientRect();
		right = Math.max( 8, window.innerWidth - rect.right );

		host.style.top = Math.round( rect.bottom + 6 ) + 'px';
		host.style.right = Math.round( right ) + 'px';
	}

	function openUserMenu() {
		setBodyClass( 'eql-user-menu-open', true );

		if ( !hasBodyClass( 'eql-main-menu-pinned' ) ) {
			setBodyClass( 'eql-main-menu-open', false );
			syncMainMenuHostVisibility();
		}

		syncUserMenuHostVisibility();
		positionUserMenu();
	}

	function closeUserMenu() {
		setBodyClass( 'eql-user-menu-open', false );
		syncUserMenuHostVisibility();
	}

	function toggleUserMenu() {
		if ( hasBodyClass( 'eql-user-menu-open' ) ) {
			closeUserMenu();
		} else {
			openUserMenu();
		}
	}

	function initUserMenu() {
		var body = document.body;
		var dropdown;
		var checkbox;
		var label;
		var personal;
		var host;

		if ( !body || !body.classList.contains( 'skin-eqlimmersive' ) ) {
			return;
		}

		dropdown = document.getElementById( 'vector-user-links-dropdown' );
		checkbox = document.getElementById( 'vector-user-links-dropdown-checkbox' );
		label = document.getElementById( 'vector-user-links-dropdown-label' );
		personal = document.getElementById( 'p-personal' );
		host = document.getElementById( 'eql-user-menu-host' );

		if ( !dropdown || !label || !personal || !host ) {
			return;
		}

		if ( personal.parentNode !== host ) {
			host.appendChild( personal );
		}

		body.classList.add( 'eql-user-menu-js' );

		if ( checkbox ) {
			checkbox.checked = false;
			checkbox.setAttribute( 'aria-hidden', 'true' );
			checkbox.tabIndex = -1;
		}

		syncUserMenuHostVisibility();

		document.addEventListener( 'click', function ( event ) {
			if ( !dropdown.contains( event.target ) ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			event.stopImmediatePropagation();

			toggleUserMenu();
		}, true );

		host.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
		} );

		host.addEventListener( 'mousedown', function ( event ) {
			event.stopPropagation();
		} );

		window.addEventListener( 'resize', positionUserMenu );
		window.addEventListener( 'scroll', positionUserMenu, true );
	}

	function initClickableCards() {
		var body = document.body;
		var cards;

		if (
			!body ||
			!body.classList.contains( 'skin-eqlimmersive' ) ||
			!body.classList.contains( 'page-Main_Page' )
		) {
			return;
		}

		cards = document.querySelectorAll( '.eql-immersive-card' );

		cards.forEach( function ( card ) {
			var imageLink = card.querySelector( '.eql-card-art a[href]' );
			var title = card.querySelector( '.eql-card-title' );

			if ( !imageLink ) {
				return;
			}

			card.classList.add( 'eql-card-clickable' );
			card.setAttribute( 'tabindex', '0' );
			card.setAttribute( 'role', 'link' );

			if ( title && title.textContent ) {
				card.setAttribute( 'aria-label', title.textContent.trim() );
			}

			card.addEventListener( 'click', function ( event ) {
				var selectedText = window.getSelection && String( window.getSelection() );

				if (
					event.target.closest( 'a, button, input, textarea, select, label' ) ||
					selectedText
				) {
					return;
				}

				window.location.href = imageLink.href;
			} );

			card.addEventListener( 'keydown', function ( event ) {
				if ( event.key !== 'Enter' && event.key !== ' ' ) {
					return;
				}

				if ( event.target.closest( 'a, button, input, textarea, select, label' ) ) {
					return;
				}

				event.preventDefault();
				window.location.href = imageLink.href;
			} );
		} );
	}

	function initGlobalClosers() {
		document.addEventListener( 'click', function ( event ) {
			var mainHost = document.getElementById( 'eql-main-menu-host' );
			var mainToggle = document.getElementById( 'eql-main-menu-toggle' );
			var userHost = document.getElementById( 'eql-user-menu-host' );
			var userDropdown = document.getElementById( 'vector-user-links-dropdown' );

			if (
				( mainHost && mainHost.contains( event.target ) ) ||
				( mainToggle && mainToggle.contains( event.target ) ) ||
				( userHost && userHost.contains( event.target ) ) ||
				( userDropdown && userDropdown.contains( event.target ) )
			) {
				return;
			}

			closeMainMenu();
			closeUserMenu();
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				closeMainMenu();
				closeUserMenu();
			}
		} );
	}

	function init() {
		initMainMenu();
		initUserMenu();
		initClickableCards();
		initGlobalClosers();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}());
/* =====================================================
   EQL Immersive — Zone Connection World map magnifier

   File:Connections.png is the single source of truth.
   Resolve the current uploaded file through the MediaWiki API so
   replacing the file on-wiki automatically updates both the visible
   map and the magnifier. The file timestamp is added to the image URL
   so a newly uploaded revision cannot be hidden by an old browser cache.
   ===================================================== */

(function () {
	'use strict';

	var FILE_TITLE = 'File:Connections.png';
	var FILE_PAGE_URL = 'https://eqlwiki.com/File:Connections.png';
	var API_URL = '/api.php';
	var ZOOM = 2.0;
	var MAX_LENS_SIZE = 625;
	var VIEWPORT_GAP = 16;

	function addRevisionToUrl( url, timestamp ) {
		var separator;

		if ( !url ) {
			return '';
		}

		separator = url.indexOf( '?' ) === -1 ? '?' : '&';

		return url + separator + 'eqlrev=' + encodeURIComponent( timestamp || String( Date.now() ) );
	}

	function resolveCurrentConnectionsImage() {
		var url = API_URL +
			'?action=query' +
			'&format=json' +
			'&formatversion=2' +
			'&prop=imageinfo' +
			'&iiprop=url%7Ctimestamp' +
			'&maxage=0' +
			'&smaxage=0' +
			'&titles=' + encodeURIComponent( FILE_TITLE );

		return window.fetch( url, {
			credentials: 'same-origin',
			cache: 'no-store'
		} ).then( function ( response ) {
			if ( !response.ok ) {
				throw new Error( 'Connections image lookup returned HTTP ' + response.status );
			}

			return response.json();
		} ).then( function ( data ) {
			var pages = data && data.query && data.query.pages;
			var info;

			if ( !pages || !pages.length || !pages[ 0 ].imageinfo || !pages[ 0 ].imageinfo.length ) {
				throw new Error( 'Connections image information was not returned.' );
			}

			info = pages[ 0 ].imageinfo[ 0 ];

			if ( !info.url ) {
				throw new Error( 'Connections image URL was not returned.' );
			}

			return addRevisionToUrl( info.url, info.timestamp );
		} );
	}

	function initConnectionMapMagnifier() {
		var body = document.body;
		var wrap;
		var mapImg;
		var mapLink;
		var lens;
		var fullImageUrl;

		if (
			!body ||
			!body.classList.contains( 'skin-eqlimmersive' ) ||
			!body.classList.contains( 'page-Zone_Connection_World' )
		) {
			return;
		}

		wrap = document.querySelector( '.eql-connection-map-wrap' );

		if ( !wrap ) {
			return;
		}

		mapImg = wrap.querySelector( 'img' );

		if ( !mapImg ) {
			return;
		}

		mapLink = mapImg.closest( 'a' );
		fullImageUrl = mapImg.currentSrc || mapImg.src || '';

		/* File:Connections.png remains the canonical destination. */
		if ( mapLink ) {
			mapLink.href = FILE_PAGE_URL;
		}

		/*
		 * Let the map use the full available content width, then calculate
		 * its displayed dimensions so the whole image fits in the remaining
		 * browser viewport without changing its aspect ratio.
		 */
		wrap.style.setProperty( 'width', '100%', 'important' );
		wrap.style.setProperty( 'max-width', 'calc(100vw - 32px)', 'important' );

		function fitMapToViewport() {
			var viewportWidth = document.documentElement.clientWidth || window.innerWidth || 0;
			var viewportHeight = document.documentElement.clientHeight || window.innerHeight || 0;
			var wrapRect = wrap.getBoundingClientRect();
			var naturalWidth = mapImg.naturalWidth || mapImg.width || 1;
			var naturalHeight = mapImg.naturalHeight || mapImg.height || 1;
			var availableWidth;
			var availableHeight;
			var scale;
			var displayWidth;
			var displayHeight;

			if ( !viewportWidth || !viewportHeight || !naturalWidth || !naturalHeight ) {
				return;
			}

			availableWidth = Math.max(
				240,
				Math.min(
					wrap.clientWidth || viewportWidth,
					viewportWidth - ( VIEWPORT_GAP * 2 )
				)
			);

			availableHeight = Math.max(
				220,
				viewportHeight - Math.max( VIEWPORT_GAP, wrapRect.top ) - VIEWPORT_GAP
			);

			scale = Math.min(
				availableWidth / naturalWidth,
				availableHeight / naturalHeight
			);

			if ( !isFinite( scale ) || scale <= 0 ) {
				return;
			}

			displayWidth = Math.max( 1, Math.round( naturalWidth * scale ) );
			displayHeight = Math.max( 1, Math.round( naturalHeight * scale ) );

			mapImg.style.setProperty( 'width', displayWidth + 'px', 'important' );
			mapImg.style.setProperty( 'height', displayHeight + 'px', 'important' );
			mapImg.style.setProperty( 'max-width', '100%', 'important' );
			mapImg.style.setProperty( 'max-height', availableHeight + 'px', 'important' );
			mapImg.style.setProperty( 'object-fit', 'contain', 'important' );
			mapImg.style.setProperty( 'margin-left', 'auto', 'important' );
			mapImg.style.setProperty( 'margin-right', 'auto', 'important' );
		}

		function useCurrentImage( url ) {
			if ( !url ) {
				return;
			}

			fullImageUrl = url;

			/*
			 * Prevent an older generated thumbnail/srcset candidate from being
			 * selected after File:Connections.png has been replaced.
			 */
			mapImg.removeAttribute( 'srcset' );
			mapImg.removeAttribute( 'sizes' );

			if ( mapImg.src !== url ) {
				mapImg.src = url;
			} else {
				fitMapToViewport();
			}
		}

		mapImg.addEventListener( 'load', fitMapToViewport );

		/* Fit the MediaWiki-rendered image immediately as a fallback. */
		if ( mapImg.complete ) {
			fitMapToViewport();
		}

		/*
		 * Resolve the current revision from File:Connections.png. If the API
		 * is temporarily unavailable, the rendered MediaWiki image remains
		 * usable and the magnifier falls back to that image.
		 */
		if ( window.fetch ) {
			resolveCurrentConnectionsImage().then( useCurrentImage ).catch( function () {
				fitMapToViewport();
			} );
		}

		if ( document.getElementById( 'eql-connection-map-magnifier' ) ) {
			return;
		}

		lens = document.createElement( 'div' );
		lens.id = 'eql-connection-map-magnifier';
		lens.setAttribute( 'aria-hidden', 'true' );

		document.body.appendChild( lens );

		function hideLens() {
			lens.classList.remove( 'eql-connection-map-magnifier-visible' );
		}

		function showLens() {
			lens.classList.add( 'eql-connection-map-magnifier-visible' );
		}

		function getLensSize() {
			var viewportWidth = document.documentElement.clientWidth || window.innerWidth || MAX_LENS_SIZE;
			var viewportHeight = document.documentElement.clientHeight || window.innerHeight || MAX_LENS_SIZE;
			var preferred = Math.round( Math.min( viewportWidth * 0.42, viewportHeight * 0.68 ) );

			return Math.max(
				160,
				Math.min(
					MAX_LENS_SIZE,
					viewportWidth - ( VIEWPORT_GAP * 2 ),
					viewportHeight - ( VIEWPORT_GAP * 2 ),
					preferred
				)
			);
		}

		function updateLens( event ) {
			var rect = mapImg.getBoundingClientRect();
			var x = event.clientX - rect.left;
			var y = event.clientY - rect.top;
			var lensSize = getLensSize();
			var half = lensSize / 2;
			var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
			var viewportHeight = document.documentElement.clientHeight || window.innerHeight;
			var centerX;
			var centerY;
			var localCursorX;
			var localCursorY;
			var bgX;
			var bgY;

			if (
				x < 0 ||
				y < 0 ||
				x > rect.width ||
				y > rect.height
			) {
				hideLens();
				return;
			}

			showLens();

			lens.style.width = lensSize + 'px';
			lens.style.height = lensSize + 'px';

			/* Keep the magnifier fully inside the browser window. */
			centerX = Math.max(
				half + VIEWPORT_GAP,
				Math.min( viewportWidth - half - VIEWPORT_GAP, event.clientX )
			);
			centerY = Math.max(
				half + VIEWPORT_GAP,
				Math.min( viewportHeight - half - VIEWPORT_GAP, event.clientY )
			);

			lens.style.left = centerX + 'px';
			lens.style.top = centerY + 'px';
			lens.style.backgroundImage = 'url("' + fullImageUrl + '")';
			lens.style.backgroundSize =
				( rect.width * ZOOM ) + 'px ' +
				( rect.height * ZOOM ) + 'px';

			/*
			 * If the lens had to move away from the cursor near a viewport edge,
			 * keep the pixel beneath the cursor aligned with the same map point.
			 */
			localCursorX = half + ( event.clientX - centerX );
			localCursorY = half + ( event.clientY - centerY );
			bgX = localCursorX - ( x * ZOOM );
			bgY = localCursorY - ( y * ZOOM );

			lens.style.backgroundPosition = bgX + 'px ' + bgY + 'px';
		}

		wrap.addEventListener( 'mousemove', updateLens );
		wrap.addEventListener( 'mouseenter', showLens );
		wrap.addEventListener( 'mouseleave', hideLens );

		window.addEventListener( 'scroll', hideLens, true );
		window.addEventListener( 'resize', function () {
			hideLens();
			fitMapToViewport();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initConnectionMapMagnifier );
	} else {
		initConnectionMapMagnifier();
	}
}());

/* =====================================================
   EQL Immersive — Build Guide empty ability-panel cleanup

   Some build-guide panels intentionally use direct #lst/#lsth
   transclusions. Empty transclusions still leave the surrounding
   collapsed table shell in the rendered page. This removes only
   those empty shells after render, without wrapping wiki-table
   transclusions in parser functions.
   ===================================================== */

(function () {
	'use strict';

	function isIgnorableText( text ) {
		text = String( text || '' )
			.replace( /\u00a0/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim();

		return text === '';
	}

	function getPanelContentCell( panel ) {
		var rows = Array.from( panel.querySelectorAll( 'tr' ) );
		var candidateRows;
		var cells;

		if ( rows.length === 0 ) {
			return null;
		}

		/* Prefer body rows after the heading row. */
		candidateRows = rows.filter( function ( row ) {
			return row.querySelector( 'td' );
		} );

		if ( candidateRows.length === 0 ) {
			return null;
		}

		cells = candidateRows[ candidateRows.length - 1 ].querySelectorAll( 'td' );

		return cells.length ? cells[ cells.length - 1 ] : null;
	}

	function hasSubstantiveRenderedContent( cell ) {
		var clone;
		var text;

		if ( !cell ) {
			return false;
		}

		clone = cell.cloneNode( true );

		clone.querySelectorAll(
			'.mw-collapsible-toggle, .mw-editsection, .mw-editsection-bracket, script, style'
		).forEach( function ( removable ) {
			removable.remove();
		} );

		text = clone.textContent;

		if ( !isIgnorableText( text ) ) {
			return true;
		}

		/*
		 * If the transclusion rendered media or a non-empty structural element
		 * with no text, keep the panel. Empty wrapper divs are ignored.
		 */
		if ( clone.querySelector( 'img, figure, video, audio, canvas, svg' ) ) {
			return true;
		}

		if ( clone.querySelector( 'table tr, ul li, ol li, dl dt, dl dd' ) ) {
			return true;
		}

		return false;
	}

	function cleanupEmptyBuildGuidePanels() {
		var body = document.body;
		var panels;

		if ( !body || !body.classList.contains( 'skin-eqlimmersive' ) ) {
			return;
		}

		panels = document.querySelectorAll( '.eql-build-ability-panel' );

		panels.forEach( function ( panel ) {
			var cell = getPanelContentCell( panel );

			if ( hasSubstantiveRenderedContent( cell ) ) {
				panel.classList.add( 'eql-build-ability-panel-has-content' );
				return;
			}

			panel.remove();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', cleanupEmptyBuildGuidePanels );
	} else {
		cleanupEmptyBuildGuidePanels();
	}

	/*
	 * Some MediaWiki collapsible/table behaviors can finish after DOMContentLoaded.
	 * Run a second pass after ResourceLoader modules have had a chance to settle.
	 */
	window.setTimeout( cleanupEmptyBuildGuidePanels, 250 );
	window.setTimeout( cleanupEmptyBuildGuidePanels, 1000 );
}());
