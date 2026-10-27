'use strict';

// Source-only browser regression. All page content, notes, and identities are synthetic.
// Run with jsdom 27 available through NODE_PATH: node ops/test-todo-ui.cjs
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );
const root = path.join( __dirname, '..' );
const source = name => fs.readFileSync( path.join( root,
    'skins/EQLImmersive/resources/' + name ), 'utf8' );
const jquery = fs.readFileSync( path.join( root, 'resources/lib/jquery/jquery.js' ), 'utf8' );
const KEY = 'eql-todo-v1:fixturewiki:anon';
const TITLE = 'Synthetic_page';
const template = fs.readFileSync( path.join( root,
    'skins/EQLImmersive/includes/templates/skin.mustache' ), 'utf8' );
const serverToggle = template.match( /<button id="eql-todo-toggle"[^>]*>[\s\S]*?<\/button>/ )[ 0 ];

async function visit( options = {} ) {
    const errors = [];
    const notices = [];
    const commands = [];
    const downloads = [];
    const virtualConsole = new VirtualConsole();
    virtualConsole.on( 'jsdomError', error => errors.push( error ) );
    const dom = new JSDOM( '<body class="skin-eqlimmersive">' +
        '<div class="vector-header-container"><header class="vector-header">' +
        ( options.legacyToggle || serverToggle ) +
        '<div class="vector-user-links-main"><a id="pt-watchlist" href="/wiki/Special:Watchlist">Watchlist</a></div>' +
        '</header></div><nav id="p-personal"><a href="/wiki/Special:Watchlist">Watchlist</a></nav>' +
        '<main id="content"><h1 id="firstHeading">Synthetic page</h1>' +
        '<div id="bodyContent"><div id="mw-content-text"><div class="mw-parser-output">' +
        '<div class="mw-heading mw-heading2"><h2 id="Alpha_&_Beta">Alpha &amp; Beta</h2>' +
        '<span class="mw-editsection">[<a href="?action=edit&section=1">edit</a>]</span></div>' +
        '<p>Page body must not enter the to-do list.</p>' +
        '<h3><span class="mw-headline" id="Classic">Classic section</span>' +
        '<span class="mw-editsection">[<a href="?action=edit&section=2">edit</a>]</span></h3>' +
        '<h2 id="Interactive"><a href="/wiki/Linked_page">Linked heading</a></h2>' +
        '<table><tbody><tr><td><h3 id="Table_heading">Table heading</h3></td></tr></tbody></table>' +
        '</div></div></div></main></body>', {
        url: 'https://wiki.example/wiki/' + TITLE, runScripts: 'outside-only', virtualConsole
    } );
    const w = dom.window;
    // jsdom does not implement modal dialog presentation or editable focus.
    w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    w.HTMLDialogElement.prototype.close = function () {
        this.open = false; this.dispatchEvent( new w.Event( 'close' ) );
    };
    Object.defineProperty( w.HTMLElement.prototype, 'contentEditable', {
        configurable: true,
        get() { return this.getAttribute( 'contenteditable' ) || 'inherit'; },
        set( value ) { this.setAttribute( 'contenteditable', value ); }
    } );
    w.document.execCommand = ( command, _showUI, value ) => {
        commands.push( { command, value } );
        if ( options.editCommands && w.document.activeElement.matches( '.eql-todo-notes' ) ) {
            // jsdom has no editing engine; simulate insertion after capturing the
            // exact string sent to the browser, so unsafe input is observable.
            const editor = w.document.activeElement;
            if ( command === 'insertHTML' ) { editor.insertAdjacentHTML( 'beforeend', value ); }
            if ( command === 'insertText' ) { editor.appendChild( w.document.createTextNode( value ) ); }
            return true;
        }
        return false;
    };
    w.URL.createObjectURL = blob => { downloads.push( blob ); return 'blob:synthetic/' + downloads.length; };
    w.URL.revokeObjectURL = () => {};
    const anchorClick = w.HTMLAnchorElement.prototype.click;
    w.HTMLAnchorElement.prototype.click = function () {
        if ( !this.download ) { anchorClick.call( this ); }
    };
    const config = {
        wgPageName: TITLE, wgTitle: 'Synthetic page', wgWikiID: 'fixturewiki',
        wgScriptPath: '', wgUserId: null, wgUserName: null, wgUserIsTemp: false,
        wgNamespaceNumber: 0, wgAction: 'view', wgIsArticle: true,
        wgArticleId: 23, wgRevisionId: 400, wgCurRevisionId: 400,
        ...options.config
    };
    for ( const [ key, value ] of Object.entries( options.local || {} ) ) {
        w.localStorage.setItem( key, value );
    }
    const hooks = new Map();
    let confirmations = [];
    w.confirm = text => { confirmations.push( text ); return options.confirm !== false; };
    w.prompt = () => null;
    w.matchMedia = () => ( { matches: false, addEventListener() {}, removeEventListener() {} } );
    w.mw = w.mediaWiki = {
        config: { get: ( name, fallback ) => Object.hasOwn( config, name ) ? config[ name ] : fallback,
            set: ( name, value ) => { config[ name ] = value; } },
        hook: name => ( {
            add( callback ) { if ( !hooks.has( name ) ) { hooks.set( name, [] ); } hooks.get( name ).push( callback ); },
            fire( ...args ) { for ( const callback of hooks.get( name ) || [] ) { callback( ...args ); } }
        } ),
        util: {
            getUrl: title => '/wiki/' + encodeURIComponent( title.replace( / /g, '_' ) ),
            wikiScript: () => '/w/api.php',
            escapeRegExp: value => value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' )
        },
        msg: name => name,
        notify: ( message, details ) => notices.push( { message, details } ),
        log: { error: ( ...args ) => errors.push( args ) },
        Api: function () { throw new Error( 'Anonymous to-do UI must not request the account API' ); }
    };
    w.eval( jquery );
    const initialToggle = w.document.getElementById( 'eql-todo-toggle' );
    if ( options.beforeInit ) { options.beforeInit( w.document ); }
    if ( options.noDialog ) { w.HTMLDialogElement = undefined; }
    if ( !options.noStore ) { w.eval( source( 'todo-store.js' ) ); }
    w.eval( source( 'todo.js' ) );
    const tick = async () => {
        for ( let i = 0; i < 3; i++ ) { await new Promise( resolve => setTimeout( resolve, 0 ) ); }
    };
    await tick();
    return {
        w, config, notices, errors, confirmations, tick, commands, downloads,
        doc: w.document, store: w.EQLTodoStore, initialToggle,
        click( selector ) {
            const el = typeof selector === 'string' ? w.document.querySelector( selector ) : selector;
            assert.ok( el, 'Expected clickable element: ' + selector );
            el.dispatchEvent( new w.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
        },
        input( el, value, type = 'input' ) {
            if ( typeof el === 'string' ) { el = w.document.querySelector( el ); }
            assert.ok( el, 'Expected input element' ); el.value = value;
            el.dispatchEvent( new w.Event( type, { bubbles: true, cancelable: true } ) );
        },
        content() { w.mw.hook( 'wikipage.content' ).fire( w.jQuery( w.document.querySelector( '.mw-parser-output' ) ) ); },
        snapshot: () => Object.fromEntries( Array.from( { length: w.localStorage.length }, ( _, i ) => {
            const key = w.localStorage.key( i ); return [ key, w.localStorage.getItem( key ) ];
        } ) ),
        async downloadText( index = downloads.length - 1 ) {
            assert.ok( downloads[ index ], 'Expected a downloaded backup' );
            return new Promise( ( resolve, reject ) => {
                const reader = new w.FileReader();
                reader.onload = () => resolve( reader.result );
                reader.onerror = () => reject( reader.error );
                reader.readAsText( downloads[ index ] );
            } );
        },
        close() { assert.deepEqual( errors, [], 'No uncaught browser errors' ); dom.window.close(); }
    };
}

async function main() {
    const registration = JSON.parse( fs.readFileSync( path.join( root,
        'skins/EQLImmersive/skin.json' ), 'utf8' ) );
    assert.ok( registration.ResourceModules[ 'skins.EQLImmersive.core' ].styles.includes( 'resources/todo.css' ),
        'Todo icon and fixed slot styles are in the first-paint core CSS' );
    let page = await visit( { beforeInit( doc ) {
        const control = doc.getElementById( 'eql-todo-toggle' );
        assert.equal( control.hidden, false, 'The actual server template exposes the control before JS' );
        assert.equal( control.disabled, true, 'Before binding, the native button is visibly inert' );
        assert.equal( control.textContent.trim(), '', 'The header icon does not wait for a font glyph' );
        assert.equal( control.getAttribute( 'aria-label' ), 'Todo list' );
        assert.equal( control.getAttribute( 'aria-haspopup' ), 'dialog' );
        assert.equal( doc.querySelector( '#eql-todo-panel' ), null );
    } } );
    assert.equal( page.doc.getElementById( 'eql-todo-toggle' ), page.initialToggle, 'JS binds the original server node' );
    assert.equal( page.initialToggle.disabled, false );
    assert.equal( page.initialToggle.hidden, false );
    assert.equal( page.initialToggle.getAttribute( 'aria-controls' ), 'eql-todo-panel' );
    assert.equal( page.store.getStatus().ready, false, 'Binding the header does not load list data' );
    assert.equal( page.doc.querySelector( '#pt-watchlist' ).hidden, true );
    assert.equal( page.doc.querySelector( '#p-personal a' ).hidden, false, 'The personal-menu Watchlist remains available' );
    page.w.eval( source( 'todo.js' ) ); await page.tick(); page.content(); page.content();
    assert.equal( page.doc.querySelectorAll( '#eql-todo-toggle' ).length, 1 );
    assert.equal( page.doc.querySelectorAll( '#eql-todo-panel' ).length, 1, 'Repeated initialization does not duplicate the dialog' );
    page.initialToggle.focus();
    page.click( '#eql-todo-toggle' );
    await page.tick();
    await page.store.ready();
    assert.ok( page.doc.querySelector( '#eql-todo-panel' ), 'Header icon opens the to-do panel' );
    page.doc.querySelector( '#eql-todo-panel' ).close();
    assert.equal( page.doc.activeElement, page.initialToggle, 'Closing returns focus to the original header control' );
    assert.equal( page.initialToggle.getAttribute( 'aria-expanded' ), 'false' );
    page.click( '#eql-todo-toggle' ); await page.tick();
    assert.equal( page.store.getItems().length, 0 );
    page.click( '#firstHeading' ); await page.tick();
    assert.equal( page.confirmations.length, 1, 'Page title requires confirmation' );
    assert.equal( page.store.getItems().length, 1 );
    assert.equal( page.store.getItems()[ 0 ].title, 'Synthetic page' );
    assert.equal( page.store.getItems()[ 0 ].section, '' );
    assert.equal( page.store.getItems()[ 0 ].notes, '', 'No article body is copied into notes' );
    page.click( '#firstHeading' ); await page.tick();
    assert.equal( page.store.getItems().length, 1, 'Repeated page adds are deduplicated' );
    page.click( page.doc.getElementById( 'Alpha_&_Beta' ) ); await page.tick();
    assert.equal( page.store.getItems().length, 2 );
    const section = page.store.getItems().find( entry => entry.section );
    assert.equal( section.section, 'Alpha_&_Beta', 'Crosslink identity uses the original section ID' );
    assert.equal( section.sectionTitle, 'Alpha & Beta', 'Only the section title is stored' );
    assert.equal( section.notes, '' );
    assert.equal( page.doc.getElementById( 'Alpha_&_Beta' ).textContent.trim(), 'Alpha & Beta',
        'Add affordances do not contaminate heading/TOC labels' );
    const entry = Array.from( page.doc.querySelectorAll( '.eql-todo-entry' ) ).find( el => el.dataset.id === section.id );
    assert.ok( entry, 'Section becomes one action item' );
    const link = entry.querySelector( 'summary a' );
    assert.ok( link, 'Action item crosslinks to its source' );
    assert.equal( new URL( link.href ).hash, '#Alpha_%26_Beta', 'Fragment is encoded safely' );
    const note = entry.querySelector( '.eql-todo-notes' );
    assert.ok( note, 'Each item has rich notes' );
    note.focus();
    note.innerHTML = '<p><strong>Bold</strong> and <em>italic</em></p><ul><li>Personal subtask</li></ul>';
    note.dispatchEvent( new page.w.Event( 'input', { bubbles: true } ) );
    await page.tick();
    assert.ok( page.store.getItems().find( item => item.id === section.id ).notes.includes( '<strong>Bold</strong>' ),
        'Rich formatting survives editing' );
    assert.equal( note.isConnected, true, 'Typing does not replace the focused note editor' );
    assert.equal( page.doc.activeElement, note, 'Typing retains note focus' );
    await page.store.flush();
    assert.equal( note.isConnected, true, 'Successful autosave retains the active note editor' );
    assert.equal( page.doc.activeElement, note, 'Successful autosave retains note focus' );
    const sliderChoice = entry.querySelector( 'input[data-action="slider"]' );
    if ( sliderChoice ) { page.click( sliderChoice ); await page.tick(); }
    const progress = page.doc.querySelector( '.eql-todo-entry[data-id="' + section.id + '"] input[data-action="progress"]' );
    assert.ok( progress, 'Optional progress slider is available' );
    page.input( progress, '100' ); await page.tick();
    assert.equal( page.store.getItems().find( item => item.id === section.id ).done, true, '100% completes the item' );
    const currentProgress = page.doc.querySelector( '.eql-todo-entry[data-id="' + section.id + '"] input[data-action="progress"]' );
    page.input( currentProgress, '42' ); await page.tick();
    assert.equal( page.store.getItems().find( item => item.id === section.id ).done, false,
        'Moving below 100% reopens the item' );
    const done = page.doc.querySelector( '.eql-todo-entry[data-id="' + section.id + '"] input[data-action="done"]' );
    page.click( done ); await page.tick();
    assert.equal( page.store.getItems().find( item => item.id === section.id ).progress, 100,
        'Checkbox completion updates progress' );
    await page.store.flush();
    const persisted = page.snapshot(); page.close();

    for ( const options of [ { noDialog: true }, { noStore: true } ] ) {
        const unavailable = await visit( options );
        assert.equal( unavailable.initialToggle.hidden, false );
        assert.equal( unavailable.initialToggle.disabled, true, 'Missing support leaves the prepaint control inert' );
        assert.equal( unavailable.doc.querySelector( '#eql-todo-panel' ), null );
        unavailable.close();
    }
    const legacy = await visit( { legacyToggle: '<button id="eql-todo-toggle" class="eql-todo-toggle" hidden><span aria-hidden="true">☑</span></button>' } );
    assert.equal( legacy.doc.getElementById( 'eql-todo-toggle' ), legacy.initialToggle );
    assert.equal( legacy.initialToggle.hidden, false, 'An older cached template is still activated without creating a second control' );
    legacy.click( '#eql-todo-toggle' ); await legacy.tick();
    assert.equal( legacy.doc.querySelectorAll( '#eql-todo-panel[open]' ).length, 1 );
    legacy.close();

    page = await visit( { local: persisted } );
    page.click( '#eql-todo-toggle' ); await page.tick();
    await page.store.ready();
    assert.equal( page.store.getItems().length, 2, 'Entries survive a fresh page instance' );
    assert.equal( page.store.getItems().find( item => item.id === section.id ).done, true );
    page.close();

    page = await visit( { confirm: false } );
    page.click( '#firstHeading' ); await page.tick();
    await page.store.ready();
    assert.equal( page.confirmations.length, 1 );
    assert.deepEqual( Array.from( page.store.getItems() ), [], 'Cancelled confirmation does not add an item' );
    page.close();

    page = await visit();
    page.click( '#firstHeading' ); await page.tick();
    page.click( page.doc.getElementById( 'Alpha_&_Beta' ) ); await page.tick();
    await page.store.flush();
    const draftId = page.store.getItems()[ 0 ].id;
    const otherId = page.store.getItems()[ 1 ].id;
    const findEntry = id => Array.from( page.doc.querySelectorAll( '.eql-todo-entry' ) ).find( el => el.dataset.id === id );
    const draftHtml = '<p>' + 'Oversized personal note. '.repeat( 800 ) + '</p>';
    let draftEditor = findEntry( draftId ).querySelector( '.eql-todo-notes' );
    draftEditor.innerHTML = draftHtml;
    draftEditor.dispatchEvent( new page.w.Event( 'input', { bubbles: true } ) );
    assert.equal( page.store.getItems().find( item => item.id === draftId ).notes, '',
        'Oversized note is rejected atomically by the model' );
    assert.equal( page.store.getStatus().dirty, false,
        'Oversized UI draft exists independently of successfully saved model state' );
    assert.ok( page.doc.querySelector( '.eql-todo-status' ).textContent.includes( 'These notes are unsaved' ) );
    page.click( findEntry( otherId ).querySelector( 'input[data-action="done"]' ) );
    await page.store.flush(); await page.tick();
    assert.equal( draftEditor.isConnected, true, 'An unrelated save preserves the rejected note editor' );
    assert.equal( draftEditor.innerHTML, draftHtml, 'An unrelated save preserves every rejected note character' );
    assert.ok( page.doc.querySelector( '.eql-todo-status' ).classList.contains( 'eql-todo-error' ),
        'A successful unrelated save cannot clear the unsaved draft error' );
    assert.ok( page.doc.querySelector( '.eql-todo-status' ).textContent.includes( 'These notes are unsaved' ) );
    page.click( page.doc.getElementById( 'Classic' ) ); await page.tick();
    await page.store.flush();
    draftEditor = findEntry( draftId ).querySelector( '.eql-todo-notes' );
    assert.equal( draftEditor.innerHTML, draftHtml, 'Structural list rendering restores the rejected draft' );
    const unloadWithDraft = new page.w.Event( 'beforeunload', { cancelable: true } );
    page.w.dispatchEvent( unloadWithDraft );
    assert.equal( unloadWithDraft.defaultPrevented, true,
        'The UI warns before leaving even when only a rejected draft is unsaved' );
    const backupButton = Array.from( page.doc.querySelectorAll( '#eql-todo-panel button' ) )
        .find( el => el.textContent === 'Download list backup' );
    page.click( backupButton );
    const backup = JSON.parse( await page.downloadText() );
    assert.equal( backup.find( item => item.id === draftId ).notes, draftHtml,
        'Backup includes the complete rejected draft rather than the older stored notes' );
    page.click( Array.from( page.doc.querySelectorAll( '#eql-todo-panel button' ) ).find( el => el.textContent === 'Close' ) );
    page.click( '#eql-todo-toggle' ); await page.tick();
    draftEditor = findEntry( draftId ).querySelector( '.eql-todo-notes' );
    assert.equal( draftEditor.innerHTML, draftHtml, 'Closing and reopening retains rejected notes' );
    draftEditor.innerHTML = '<p>Shortened note now fits.</p>';
    draftEditor.dispatchEvent( new page.w.Event( 'input', { bubbles: true } ) );
    await page.store.flush(); await page.tick();
    assert.equal( page.store.getItems().find( item => item.id === draftId ).notes, '<p>Shortened note now fits.</p>' );
    assert.equal( page.doc.querySelector( '.eql-todo-status' ).classList.contains( 'eql-todo-error' ), false,
        'Successfully shortening the note clears its rejected draft' );
    const unloadAfterRepair = new page.w.Event( 'beforeunload', { cancelable: true } );
    page.w.dispatchEvent( unloadAfterRepair );
    assert.equal( unloadAfterRepair.defaultPrevented, false, 'Saved repaired notes no longer trigger an unload warning' );
    page.close();

    const attack = '<p id="clobber" class="foreign" style="position:fixed" onclick="alert(1)"><b>Safe bold</b>' +
        '<a href="javascript:alert(1)" target="_top" onmouseover="alert(1)">Unsafe URL</a>' +
        '<a href="https://example.test/notes" style="color:red">Safe URL</a></p>' +
        '<img src="https://example.test/private" onerror="alert(1)">' +
        '<script>alert(1)</script><svg><script>alert(1)</script></svg><iframe src="https://example.test"></iframe>' +
        '<ul class="eql-todo-note-checklist foreign" onclick="alert(1)"><li>' +
        '<input type="checkbox" checked onclick="alert(1)" name="clobber" form="external"> Checked task</li>' +
        '<li><input type="checkbox"> Unchecked task</li><li><input type="password" value="private"></li></ul>';
    page = await visit( { local: { [ KEY ]: JSON.stringify( { version: 1,
        items: [ { title: 'Synthetic page', notes: attack } ] } ) } } );
    page.click( '#eql-todo-toggle' ); await page.tick();
    let safeEditor = page.doc.querySelector( '.eql-todo-notes' );
    assert.equal( safeEditor.querySelectorAll( 'script, svg, iframe, img, input:not([type="checkbox"])' ).length, 0,
        'Stored rich text cannot restore executable content, embedded media, or arbitrary inputs' );
    assert.equal( safeEditor.querySelectorAll( '[id], [name], [style], [onclick], [onmouseover], [form], [target]' ).length, 0,
        'Stored rich text loses handlers, styles, clobbering attributes, and form attachments' );
    assert.equal( safeEditor.querySelector( 'b' ).textContent, 'Safe bold', 'Useful rich text formatting remains' );
    assert.equal( safeEditor.querySelectorAll( 'a[href]' ).length, 1, 'Unsafe URL protocols cannot become active links' );
    assert.equal( safeEditor.querySelector( 'a[href]' ).rel, 'noopener noreferrer' );
    assert.equal( safeEditor.querySelector( 'ul' ).className, 'eql-todo-note-checklist' );
    let boxes = safeEditor.querySelectorAll( 'input[type="checkbox"]' );
    assert.equal( boxes.length, 2 );
    assert.equal( boxes[ 0 ].checked, true, 'Sanitization retains a checked nested checklist item' );
    assert.equal( boxes[ 1 ].checked, false, 'Sanitization retains an unchecked nested checklist item' );
    boxes[ 0 ].checked = false;
    boxes[ 0 ].dispatchEvent( new page.w.Event( 'change', { bubbles: true } ) );
    boxes[ 1 ].checked = true;
    boxes[ 1 ].dispatchEvent( new page.w.Event( 'change', { bubbles: true } ) );
    await page.store.flush();
    const checklistPersisted = page.snapshot(); page.close();
    page = await visit( { local: checklistPersisted } );
    page.click( '#eql-todo-toggle' ); await page.tick();
    safeEditor = page.doc.querySelector( '.eql-todo-notes' );
    boxes = safeEditor.querySelectorAll( 'input[type="checkbox"]' );
    assert.equal( boxes[ 0 ].checked, false, 'Unchecking a note checklist item survives save and sanitized reload' );
    assert.equal( boxes[ 1 ].checked, true, 'Checking a note checklist item survives save and sanitized reload' );
    assert.equal( safeEditor.querySelectorAll( 'script, img, [onclick], [style]' ).length, 0 );
    page.close();

    page = await visit( { editCommands: true } );
    page.click( '#firstHeading' ); await page.tick();
    safeEditor = page.doc.querySelector( '.eql-todo-notes' );
    safeEditor.focus();
    const paste = new page.w.Event( 'paste', { bubbles: true, cancelable: true } );
    Object.defineProperty( paste, 'clipboardData', { value: {
        getData: type => type === 'text/html' ? attack : 'Fallback text'
    } } );
    safeEditor.dispatchEvent( paste );
    assert.equal( paste.defaultPrevented, true, 'Rich paste bypasses the browser default insertion path' );
    assert.equal( page.commands.at( -1 ).command, 'insertHTML' );
    const sanitizedInsertion = page.commands.at( -1 ).value;
    assert.equal( /<script|<svg|<iframe|<img|\sonclick=|\sstyle=|href="javascript:/i.test( sanitizedInsertion ), false,
        'Paste sanitizes content before passing HTML to the editing command' );
    assert.ok( sanitizedInsertion.includes( '<b>Safe bold</b>' ) );
    assert.equal( safeEditor.querySelectorAll( 'script, svg, iframe, img, [onclick], [style]' ).length, 0 );
    await page.store.flush();
    const savedPaste = page.doc.createElement( 'template' );
    savedPaste.innerHTML = page.store.getItems()[ 0 ].notes;
    assert.equal( savedPaste.content.querySelectorAll( 'script, svg, iframe, img, [onclick], [style]' ).length, 0,
        'Only safe pasted content reaches storage' );
    assert.equal( savedPaste.content.querySelector( 'a' ).hasAttribute( 'href' ), false,
        'Repeated sanitization keeps a rejected link inert instead of assigning a new URL' );
    const plainPaste = new page.w.Event( 'paste', { bubbles: true, cancelable: true } );
    Object.defineProperty( plainPaste, 'clipboardData', { value: {
        getData: type => type === 'text/html' ? '' : '<img src=x onerror=alert(1)>'
    } } );
    safeEditor.dispatchEvent( plainPaste );
    assert.equal( page.commands.at( -1 ).command, 'insertText', 'Plain paste uses text insertion rather than HTML' );
    assert.equal( safeEditor.querySelectorAll( 'img' ).length, 0 );
    assert.ok( safeEditor.textContent.includes( '<img src=x onerror=alert(1)>' ) );
    const drop = new page.w.Event( 'drop', { bubbles: true, cancelable: true } );
    safeEditor.dispatchEvent( drop );
    assert.equal( drop.defaultPrevented, true, 'Drops cannot bypass the note paste sanitizer' );
    await page.store.flush(); page.close();

    page = await visit();
    page.click( '#firstHeading' ); await page.tick();
    await page.store.flush();
    const removedDraftId = page.store.getItems()[ 0 ].id;
    const removedDraftEditor = page.doc.querySelector( '.eql-todo-notes' );
    removedDraftEditor.innerHTML = draftHtml;
    removedDraftEditor.dispatchEvent( new page.w.Event( 'input', { bubbles: true } ) );
    const removedElsewhere = JSON.stringify( { version: 1, items: [] } );
    page.w.localStorage.setItem( KEY, removedElsewhere );
    page.w.dispatchEvent( new page.w.StorageEvent( 'storage', {
        key: KEY, newValue: removedElsewhere, storageArea: page.w.localStorage
    } ) );
    await page.tick();
    assert.equal( page.store.getItems().length, 0, 'A clean model reflects removal by another tab' );
    page.click( Array.from( page.doc.querySelectorAll( '#eql-todo-panel button' ) )
        .find( el => el.textContent === 'Download list backup' ) );
    const removedDraftBackup = JSON.parse( await page.downloadText() );
    assert.ok( removedDraftBackup.some( item => item.id === removedDraftId && item.notes === draftHtml ),
        'A rejected draft remains downloadable even when another tab removes its source entry' );
    page.close();

    console.log( 'Synthetic to-do UI confirmation, identity, rich text, progress, persistence, rejected-draft recovery, unload, checklist, and paste security checks pass.' );
}

main().catch( error => { console.error( error ); process.exitCode = 1; } );
