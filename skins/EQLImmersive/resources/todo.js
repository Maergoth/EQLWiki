/* Native personal todo list. Page content is never copied into entries. */
( function () {
	'use strict';
	var store = window.EQLTodoStore;
	var panel, list, status, count, retry, toggle, opener;
	var saving = false;
	var ready = false;
	var lastModel = '';
	var drafts = Object.create( null );
	var allowed = /^(P|DIV|BR|STRONG|B|EM|I|U|S|UL|OL|LI|A|INPUT|SPAN)$/;

	function element( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) { node.className = className; }
		if ( text ) { node.textContent = text; }
		return node;
	}

	// Rebuild untrusted rich text in an inert document: no media, styles or handlers.
	function cleanNotes( html ) {
		var template = document.createElement( 'template' );
		template.innerHTML = String( html );
		var out = document.createElement( 'div' );
		function copy( source, parent ) {
			Array.from( source.childNodes ).forEach( function ( node ) {
				if ( node.nodeType === 3 ) { parent.appendChild( document.createTextNode( node.textContent ) ); return; }
				if ( node.nodeType !== 1 ) { return; }
				var tag = node.tagName.toUpperCase();
				if ( /^(SCRIPT|STYLE|SVG|MATH|IFRAME|OBJECT|TEMPLATE)$/.test( tag ) ) { return; }
				if ( !allowed.test( tag ) ) { copy( node, parent ); return; }
				if ( tag === 'INPUT' && node.getAttribute( 'type' ) !== 'checkbox' ) { return; }
				var safe = document.createElement( tag.toLowerCase() );
				if ( tag === 'A' && node.getAttribute( 'href' ) ) {
					try {
						var url = new URL( node.getAttribute( 'href' ), location.href );
						if ( /^(https?:)$/.test( url.protocol ) ) { safe.href = url.href; safe.rel = 'noopener noreferrer'; }
					} catch ( e ) {}
				}
				if ( tag === 'INPUT' ) {
					safe.type = 'checkbox';
					safe.setAttribute( 'contenteditable', 'false' );
					safe.setAttribute( 'aria-label', 'Note checklist item' );
					if ( node.hasAttribute( 'checked' ) ) { safe.setAttribute( 'checked', '' ); }
				}
				if ( tag === 'UL' && node.classList.contains( 'eql-todo-note-checklist' ) ) { safe.className = 'eql-todo-note-checklist'; }
				copy( node, safe );
				parent.appendChild( safe );
			} );
		}
		copy( template.content, out );
		return out.innerHTML;
	}

	function message( text, error ) {
		status.textContent = text;
		status.classList.toggle( 'eql-todo-error', !!error );
	}

	function mutate( fn ) {
		try { saving = true; fn(); lastModel = JSON.stringify( store.getItems() ); return true; }
		catch ( error ) { message( error.message, true ); return false; }
		finally { saving = false; }
	}

	function updateStatus() {
		var state = store.getStatus();
		var draft = Object.values( drafts )[ 0 ];
		var fallback = { idle: '', loading: 'Loading todo list…', saving: 'Saving…', saved: state.account ? 'Saved to your wiki account.' : 'Saved in this browser. Sign in to use a separate account list.' };
		message( draft ? draft.error : state.message || fallback[ state.state ], !!draft || state.state === 'error' );
		retry.hidden = !state.retry;
		panel.classList.toggle( 'eql-todo-unavailable', !state.canWrite );
		list.querySelectorAll( 'button, input' ).forEach( function ( node ) { node.disabled = !state.canWrite; } );
		list.querySelectorAll( '.eql-todo-notes' ).forEach( function ( node ) { node.contentEditable = state.canWrite ? 'true' : 'false'; } );
	}

	function button( text, action, callback ) {
		var node = element( 'button', '', text );
		node.type = 'button';
		if ( action ) { node.dataset.action = action; }
		if ( callback ) { node.addEventListener( 'click', callback ); }
		return node;
	}

	function labelFor( item ) { return item.title + ( item.sectionTitle ? ' > ' + item.sectionTitle : '' ); }

	function entryView( item ) {
		var entry = element( 'li', 'eql-todo-entry' );
		entry.dataset.id = item.id;
		var row = element( 'div', 'eql-todo-row' );
		var done = element( 'input' );
		done.type = 'checkbox'; done.checked = item.done; done.dataset.action = 'done';
		done.setAttribute( 'aria-label', 'Complete ' + labelFor( item ) );
		var details = element( 'details' );
		details.open = !item.collapsed;
		var summary = element( 'summary' );
		var link = element( 'a', 'eql-todo-source', labelFor( item ) );
		link.href = mw.util.getUrl( item.title ) + ( item.section ? '#' + encodeURIComponent( item.section ) : '' );
		link.addEventListener( 'click', function ( event ) { event.stopPropagation(); } );
		summary.appendChild( link );
		details.appendChild( summary );
		var content = element( 'div', 'eql-todo-entry-content' );
		var options = element( 'div', 'eql-todo-entry-options' );
		var sliderLabel = element( 'label' );
		var useSlider = element( 'input' );
		useSlider.type = 'checkbox'; useSlider.checked = item.slider; useSlider.dataset.action = 'slider';
		sliderLabel.append( useSlider, document.createTextNode( ' Use progress slider' ) );
		var remove = button( 'Remove', 'remove', function () {
			if ( window.confirm( 'Remove “' + labelFor( item ) + '” and its notes from your todo list?' ) && mutate( function () { store.remove( item.id ); } ) ) { delete drafts[ item.id ]; render(); }
		} );
		options.append( sliderLabel, remove );
		var progressRow = element( 'label', 'eql-todo-progress' );
		progressRow.hidden = !item.slider;
		progressRow.appendChild( document.createTextNode( 'Progress ' ) );
		var slider = element( 'input' );
		slider.type = 'range'; slider.min = '0'; slider.max = '100'; slider.step = '1'; slider.value = item.progress;
		slider.dataset.action = 'progress'; slider.setAttribute( 'aria-label', 'Progress for ' + labelFor( item ) );
		var value = element( 'output', '', item.progress + '%' );
		progressRow.append( slider, value );
		var notes = element( 'div', 'eql-todo-notes' );
		notes.contentEditable = 'true'; notes.setAttribute( 'role', 'textbox' ); notes.setAttribute( 'aria-multiline', 'true' );
		notes.setAttribute( 'aria-label', 'Notes for ' + labelFor( item ) );
		notes.innerHTML = cleanNotes( drafts[ item.id ] ? drafts[ item.id ].html : item.notes );
		var toolbar = element( 'div', 'eql-todo-format' );
		toolbar.setAttribute( 'role', 'group' ); toolbar.setAttribute( 'aria-label', 'Format notes' );
		function persistNotes() {
			var html = cleanNotes( notes.innerHTML );
			try {
				saving = true;
				store.update( item.id, { notes: html } );
				delete drafts[ item.id ];
				lastModel = JSON.stringify( store.getItems() );
			} catch ( error ) { drafts[ item.id ] = { item: item, html: html, error: error.message + ' These notes are unsaved; shorten them or download a backup.' }; }
			finally { saving = false; updateStatus(); }
		}
		function command( name, value ) {
			notes.focus();
			document.execCommand( name, false, value );
			persistNotes();
		}
		[ [ 'Bold', 'bold' ], [ 'Italic', 'italic' ], [ 'Underline', 'underline' ], [ 'Bullets', 'insertUnorderedList' ], [ 'Numbered list', 'insertOrderedList' ] ].forEach( function ( format ) {
			var control = button( format[ 0 ], '', function () { command( format[ 1 ] ); } );
			// Preserve selection when the pointer moves to the formatting toolbar.
			control.addEventListener( 'mousedown', function ( event ) { event.preventDefault(); } );
			toolbar.appendChild( control );
		} );
		var checklist = button( 'Checklist item', '', function () {
			command( 'insertHTML', '<ul class="eql-todo-note-checklist"><li><input type="checkbox" contenteditable="false" aria-label="Note checklist item"> New task</li></ul>' );
		} );
		checklist.addEventListener( 'mousedown', function ( event ) { event.preventDefault(); } );
		toolbar.appendChild( checklist );
		var addLink = button( 'Link', '', function () {
			var url = window.prompt( 'Link address (https:// or http://)' );
			if ( url && /^https?:\/\//i.test( url.trim() ) ) { command( 'createLink', url.trim() ); }
		} );
		addLink.addEventListener( 'mousedown', function ( event ) { event.preventDefault(); } );
		toolbar.appendChild( addLink );
		notes.addEventListener( 'input', persistNotes );
		notes.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( 'input[type="checkbox"]' ) ) {
				event.target.toggleAttribute( 'checked', event.target.checked ); persistNotes();
			}
		} );
		notes.addEventListener( 'paste', function ( event ) {
			if ( !event.clipboardData ) { return; }
			event.preventDefault();
			var html = event.clipboardData.getData( 'text/html' );
			if ( html ) { command( 'insertHTML', cleanNotes( html ) ); }
			else { command( 'insertText', event.clipboardData.getData( 'text/plain' ) ); }
		} );
		notes.addEventListener( 'drop', function ( event ) { event.preventDefault(); } );
		done.addEventListener( 'change', function () {
			if ( mutate( function () { item = store.update( item.id, { done: done.checked } ); } ) ) {
				slider.value = item.progress; value.textContent = item.progress + '%'; entry.classList.toggle( 'eql-todo-done', item.done ); updateCount();
			}
		} );
		slider.addEventListener( 'input', function () {
			if ( mutate( function () { item = store.update( item.id, { progress: Number( slider.value ) } ); } ) ) {
				done.checked = item.done; value.textContent = item.progress + '%'; entry.classList.toggle( 'eql-todo-done', item.done ); updateCount();
			}
		} );
		useSlider.addEventListener( 'change', function () {
			if ( mutate( function () { store.update( item.id, { slider: useSlider.checked } ); } ) ) { progressRow.hidden = !useSlider.checked; }
		} );
		details.addEventListener( 'toggle', function () {
			var current = store.getItems().find( function ( row ) { return row.id === item.id; } );
			if ( current && current.collapsed !== !details.open ) { mutate( function () { store.update( item.id, { collapsed: !details.open } ); } ); }
		} );
		content.append( options, progressRow, toolbar, notes );
		details.appendChild( content );
		row.append( done, details ); entry.appendChild( row );
		entry.classList.toggle( 'eql-todo-done', item.done );
		return entry;
	}

	function updateCount() {
		var items = store.getItems();
		count.textContent = items.filter( function ( item ) { return item.done; } ).length + ' / ' + items.length + ' complete';
	}

	function render() {
		list.replaceChildren();
		var items = store.getItems();
		if ( !items.length ) { list.appendChild( element( 'li', 'eql-todo-empty', 'Click a page title or section heading to add your first item.' ) ); }
		items.forEach( function ( item ) { list.appendChild( entryView( item ) ); } );
		lastModel = JSON.stringify( items );
		updateCount(); updateStatus();
	}

	function open( source ) {
		opener = source || toggle;
		if ( !panel.open ) { panel.showModal(); toggle.setAttribute( 'aria-expanded', 'true' ); }
		message( 'Loading todo list…' );
		return store.ready().then( function () { ready = true; render(); } ).catch( updateStatus );
	}

	function add( title, section, sectionTitle, source ) {
		var label = title + ( sectionTitle ? ' > ' + sectionTitle : '' );
		if ( !window.confirm( 'Add “' + label + '” to your todo list?' ) ) { return; }
		open( source ).then( function () {
			if ( ready && mutate( function () { store.add( { title: title, section: section, sectionTitle: sectionTitle } ); } ) ) { render(); }
		} );
	}

	function headingLabel( heading ) {
		var clone = heading.cloneNode( true );
		clone.querySelectorAll( '.mw-editsection, .eql-todo-add, .mw-headline-number, .headerlink, script, style' ).forEach( function ( node ) { node.remove(); } );
		return clone.textContent.replace( /\s+/g, ' ' ).trim();
	}

	function bindHeadings() {
		if ( mw.config.get( 'wgAction' ) !== 'view' || Number( mw.config.get( 'wgNamespaceNumber' ) ) < 0 || !mw.config.get( 'wgArticleId' ) || document.documentElement.classList.contains( 've-activated' ) ) { return; }
		var title = mw.config.get( 'wgPageName' ).replace( /_/g, ' ' );
		var headings = document.querySelectorAll( '#firstHeading, #mw-content-text .mw-parser-output h1, #mw-content-text .mw-parser-output h2, #mw-content-text .mw-parser-output h3, #mw-content-text .mw-parser-output h4, #mw-content-text .mw-parser-output h5, #mw-content-text .mw-parser-output h6' );
		headings.forEach( function ( heading ) {
			if ( heading.dataset.eqlTodoBound || heading.closest( 'table, nav, .toc, .mw-editsection, .hb, .item-hoverbox, [contenteditable="true"]' ) ) { return; }
			var pageTitle = heading.id === 'firstHeading';
			var headline = heading.querySelector( '.mw-headline[id]' );
			var section = pageTitle ? '' : ( headline ? headline.id : heading.id );
			var label = pageTitle ? '' : headingLabel( heading );
			if ( !pageTitle && ( !section || !label ) ) { return; }
			heading.dataset.eqlTodoBound = '1';
			var control = button( '', '', function ( event ) { event.stopPropagation(); add( title, section, label, control ); } );
			control.className = 'eql-todo-add'; control.setAttribute( 'aria-label', 'Add ' + ( label ? title + ' > ' + label : title ) + ' to todo list' );
			control.title = control.getAttribute( 'aria-label' );
			heading.appendChild( control );
			heading.classList.add( 'eql-todo-heading' );
			heading.addEventListener( 'click', function ( event ) {
				if ( document.documentElement.classList.contains( 've-activated' ) || event.target.closest( 'a, button, input, .mw-editsection' ) || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || String( window.getSelection() ).trim() ) { return; }
				add( title, section, label, control );
			} );
		} );
	}

	function init() {
		toggle = document.getElementById( 'eql-todo-toggle' );
		if ( !store || !toggle || typeof HTMLDialogElement === 'undefined' ) { return; }
		toggle.hidden = false;
		// Replace only the direct header shortcut; the personal menu retains Watchlist.
		document.querySelectorAll( '.vector-user-links-main #pt-watchlist, .vector-user-links-main #pt-watchlist-2' ).forEach( function ( node ) { node.hidden = true; node.classList.add( 'eql-todo-replaced-watchlist' ); } );
		panel = element( 'dialog', 'eql-todo-panel' ); panel.id = 'eql-todo-panel'; panel.setAttribute( 'aria-labelledby', 'eql-todo-title' );
		var header = element( 'div', 'eql-todo-panel-header' );
		var title = element( 'h2', '', 'Todo list' ); title.id = 'eql-todo-title';
		header.append( title, button( 'Close', '', function () { panel.close(); } ) );
		var help = element( 'p', 'eql-todo-help', 'Add pages or sections by clicking their titles. Each entry links to its source. Notes are private to your list.' );
		count = element( 'p', 'eql-todo-count' );
		status = element( 'p', 'eql-todo-status' ); status.setAttribute( 'role', 'status' );
		retry = button( 'Retry saving / loading', '', function () { store.retry().then( function () { ready = true; render(); } ).catch( updateStatus ); } ); retry.hidden = true;
		var exportDraft = button( 'Download list backup', '', function () {
			var backup = store.getItems().map( function ( item ) { if ( drafts[ item.id ] ) { item.notes = drafts[ item.id ].html; } return item; } );
			Object.keys( drafts ).forEach( function ( id ) {
				if ( !backup.some( function ( item ) { return item.id === id; } ) ) { backup.push( Object.assign( {}, drafts[ id ].item, { notes: drafts[ id ].html } ) ); }
			} );
			var blob = new Blob( [ JSON.stringify( backup, null, 2 ) ], { type: 'application/json' } );
			var url = URL.createObjectURL( blob ); var link = element( 'a' );
			link.href = url; link.download = 'eql-todo-backup.json'; link.click(); setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );
		} );
		list = element( 'ul', 'eql-todo-items' ); list.id = 'eql-todo-items';
		panel.append( header, help, count, status, retry, exportDraft, list ); document.body.appendChild( panel );
		panel.addEventListener( 'close', function () { toggle.setAttribute( 'aria-expanded', 'false' ); if ( opener && opener.isConnected ) { opener.focus(); } } );
		toggle.addEventListener( 'click', function () { open( toggle ); } );
		store.subscribe( function () {
			if ( panel.open ) {
				updateStatus();
				// Status-only autosave events must preserve the active editor and caret.
				if ( ready && !saving && !store.getStatus().dirty && lastModel !== JSON.stringify( store.getItems() ) ) { render(); }
			}
		} );
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( Object.keys( drafts ).length ) { event.preventDefault(); event.returnValue = ''; }
		} );
		bindHeadings(); mw.hook( 'wikipage.content' ).add( bindHeadings );
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
}() );
