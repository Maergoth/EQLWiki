'use strict';

// Synthetic DOM/range regression. Does not fetch pages or verify real text-selection layout.
// Run with jsdom 27 available through NODE_PATH: node ops/test-selection-search.cjs
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname,
    '../skins/EQLImmersive/resources/selection-search.js' ), 'utf8' );
let cases = 0;

async function visit( options = {} ) {
    const errors = [];
    const virtualConsole = new VirtualConsole();
    virtualConsole.on( 'jsdomError', error => errors.push( error ) );
    const dom = new JSDOM( '<body class="' + ( options.skin || 'skin-eqlimmersive' ) + '">' +
        '<div class="vector-header-container"><header>Wiki header</header></div>' +
        '<div id="mw-content-text"><div class="mw-parser-output" id="article">' +
        '<p id="plain">Raku</p><p id="mixed">  Rakusha \n cloak  </p>' +
        '<p id="before">Allowed before</p>' +
        '<div contenteditable="true" id="editor">Private draft</div>' +
        '<p id="after">Allowed after</p>' +
        '<form><input id="input" value="Private input"><textarea id="textarea">Private textarea</textarea>' +
        '<select id="select"><option>Private option</option></select><button id="button">Form button</button></form>' +
        '<div class="hb" id="hover">Hover text</div>' +
        '<div class="item-hoverbox" id="hoverbox">Hover card text</div>' +
        '<nav id="navigation">Navigation text</nav><div class="toc" id="toc">Contents text</div>' +
        '<span class="mw-editsection" id="editlink">Edit section</span>' +
        '</div><div class="mw-parser-output" id="second">Another parsed root</div></div>' +
        '<aside id="outside">Outside article</aside>' +
        '<div class="eql-todo-notes" contenteditable="true" id="notes">Private todo notes</div></body>', {
        url: 'https://wiki.example/wiki/Synthetic_page', runScripts: 'outside-only', virtualConsole
    } );
    const w = dom.window;
    const doc = w.document;
    const calls = [];
    const frames = new Map();
    let frameId = 0;
    let rangeRect = { left: 100, top: 120, right: 180, bottom: 140, width: 80, height: 20 };
    Object.defineProperty( w, 'innerWidth', { configurable: true, value: options.width || 800 } );
    Object.defineProperty( w, 'innerHeight', { configurable: true, value: options.height || 600 } );
    w.requestAnimationFrame = callback => { const id = ++frameId; frames.set( id, callback ); return id; };
    w.cancelAnimationFrame = id => frames.delete( id );
    w.Range.prototype.getBoundingClientRect = () => rangeRect;
    const popup = { width: options.popupWidth || 220, height: options.popupHeight || 40 };
    w.HTMLElement.prototype.getBoundingClientRect = function () {
        if ( this.id === 'eql-selection-search' ) { return { left: 0, top: 0, right: popup.width, bottom: popup.height, ...popup }; }
        if ( this.classList.contains( 'vector-header-container' ) ) {
            const bottom = options.headerBottom === undefined ? 50 : options.headerBottom;
            return { left: 0, top: 0, bottom, width: w.innerWidth, height: bottom, right: w.innerWidth };
        }
        return { left: 0, top: 0, right: 0, bottom: 0, width: 0, height: 0 };
    };
    w.fetch = () => { throw new Error( 'Selection search must not fetch or transmit selected text' ); };
    w.XMLHttpRequest = function () { throw new Error( 'Selection search must not issue requests' ); };
    w.mw = w.mediaWiki = {
        config: { get: name => name === 'wgAction' ? ( options.action || 'view' ) : null },
        util: { getUrl( title, params ) {
            calls.push( { title, params: { ...params } } );
            const url = new w.URL( '/wiki/' + title, w.location.origin );
            for ( const [ key, value ] of Object.entries( params || {} ) ) { url.searchParams.set( key, value ); }
            return url.pathname + url.search;
        } },
        Api: function () { throw new Error( 'Selection search must not use the API' ); }
    };
    w.eval( source );
    await new Promise( resolve => setTimeout( resolve, 0 ) );
    function fire( type, target = doc, init = {} ) {
        const event = type === 'keyup' ? new w.KeyboardEvent( type, { bubbles: true, ...init } ) :
            new w.Event( type, { bubbles: true, ...init } );
        target.dispatchEvent( event );
    }
    function frame() {
        const pending = Array.from( frames.values() ); frames.clear();
        for ( const callback of pending ) { callback( 0 ); }
    }
    function select( selector, text, endSelector ) {
        const start = doc.querySelector( selector ); assert.ok( start );
        if ( text !== undefined ) { start.textContent = text; }
        const range = doc.createRange();
        range.selectNodeContents( start );
        if ( endSelector ) {
            const end = doc.querySelector( endSelector ); assert.ok( end );
            range.setEnd( end, end.childNodes.length );
        }
        const selection = w.getSelection(); selection.removeAllRanges(); selection.addRange( range );
        fire( 'selectionchange' );
        return range;
    }
    return {
        w, doc, calls, errors, frames, popup, fire, frame, select,
        action: () => doc.getElementById( 'eql-selection-search' ),
        rect: next => { rangeRect = { ...rangeRect, ...next }; },
        close() { assert.deepEqual( errors, [], 'No uncaught browser errors' ); dom.window.close(); }
    };
}

function shown( page, expected, message ) {
    assert.equal( Boolean( page.action() && !page.action().hidden ), expected, message ); cases++;
}

async function main() {
    let page = await visit();
    shown( page, false, 'Hidden until article text is selected' );
    assert.equal( page.calls.length, 0 );
    page.select( '#plain' );
    page.fire( 'mouseup' ); page.fire( 'keyup', page.doc, { key: 'Shift' } ); page.fire( 'touchend' );
    assert.equal( page.frames.size, 1, 'Repeated selection/pointer/keyboard events share one animation frame' );
    assert.equal( page.calls.length, 0, 'No work before the animation frame' );
    page.frame();
    shown( page, true, 'Article selection exposes the native search link' );
    let link = page.action();
    assert.equal( link.textContent, 'Search wiki: “Raku”' );
    assert.equal( link.getAttribute( 'aria-label' ), 'Search wiki for Raku' );
    let url = new URL( link.href );
    assert.equal( url.origin, 'https://wiki.example' );
    assert.equal( url.pathname, '/wiki/Special:Search' );
    assert.equal( url.searchParams.get( 'search' ), 'Raku' );
    assert.equal( url.searchParams.get( 'fulltext' ), '1' );
    assert.equal( page.calls.length, 1, 'Only URL construction occurs before activation' );
    page.select( '#mixed' ); page.frame();
    assert.equal( new URL( link.href ).searchParams.get( 'search' ), 'Rakusha cloak', 'Whitespace normalized' );
    page.select( '#plain', '<img src=x onerror=alert(1)> & "text"' ); page.frame();
    assert.equal( link.childElementCount, 0, 'Selected markup is displayed as plain text' );
    assert.equal( new URL( link.href ).searchParams.get( 'search' ), '<img src=x onerror=alert(1)> & "text"',
        'URL parameters are encoded without losing selected text' );
    page.select( '#plain', 'x'.repeat( 120 ) ); page.frame(); shown( page, true, '120 normalized characters are allowed' );
    assert.equal( link.textContent, 'Search wiki: “' + 'x'.repeat( 36 ) + '…”', 'Long labels are shortened' );
    assert.equal( link.getAttribute( 'aria-label' ), 'Search wiki for ' + 'x'.repeat( 120 ), 'Accessible label retains full query' );
    page.select( '#plain', 'x'.repeat( 121 ) ); page.frame(); shown( page, false, 'Long selections are rejected' );
    page.select( '#plain', ' \n\t ' ); page.frame(); shown( page, false, 'Whitespace-only selections are rejected' );
    page.select( '#plain', 'Raku' ); page.frame();
    page.fire( 'keyup', page.doc, { key: 'Escape' } ); shown( page, false, 'Escape dismisses the action' );
    page.fire( 'selectionchange' ); page.frame(); shown( page, false, 'Same selection stays dismissed after Escape' );
    page.w.getSelection().removeAllRanges(); page.fire( 'selectionchange' ); page.frame();
    page.select( '#plain', 'Raku' ); page.frame(); shown( page, true, 'A new selection can be searched again' );
    link.focus(); page.fire( 'selectionchange' ); page.frame();
    shown( page, true, 'Keyboard focus on the action is preserved' );
    assert.equal( page.doc.activeElement, link );
    page.fire( 'keyup', page.doc, { key: 'Escape' } ); shown( page, false, 'Escape dismisses a focused action' );
    page.close();

    for ( const selector of [ '#outside', '#notes', '#editor', '#textarea', '#select', '#button',
        '#hover', '#hoverbox', '#navigation', '#toc', '#editlink' ] ) {
        page = await visit(); page.select( selector ); page.frame();
        shown( page, false, selector + ': nonarticle, editable, form, or hover text is excluded' );
        assert.equal( page.calls.length, 0, 'Excluded text does not construct a search URL' ); page.close();
    }
    page = await visit(); page.select( '#before', undefined, '#second' ); page.frame();
    shown( page, false, 'A selection spanning different parsed roots is excluded' ); page.close();
    page = await visit(); page.select( '#before', undefined, '#after' ); page.frame();
    shown( page, false, 'A range spanning editable draft content is excluded even with allowed endpoints' ); page.close();

    for ( const options of [ { action: 'edit' }, { action: 'submit' }, { skin: 'skin-vector' } ] ) {
        page = await visit( options ); assert.equal( page.action(), null, 'Only EQL article views initialize the feature' );
        page.close(); cases++;
    }
    page = await visit(); page.doc.documentElement.classList.add( 've-activated' );
    page.select( '#plain' ); page.frame(); shown( page, false, 'VisualEditor suppresses the action' ); page.close();

    page = await visit(); page.rect( { left: 790, top: 520, bottom: 540 } );
    page.select( '#plain' ); page.frame(); shown( page, true, 'Visible selection near viewport edge is searchable' );
    link = page.action();
    assert.ok( Number.parseFloat( link.style.left ) >= 8 );
    assert.ok( Number.parseFloat( link.style.left ) + page.popup.width <= page.w.innerWidth - 8, 'Horizontal position clamps to viewport' );
    assert.ok( Number.parseFloat( link.style.top ) >= 58, 'Action clears the sticky header' );
    assert.ok( Number.parseFloat( link.style.top ) + page.popup.height <= page.w.innerHeight - 48, 'Vertical position clamps to viewport/footer' );
    page.fire( 'scroll', page.w ); shown( page, false, 'Scrolling hides stale selection coordinates' );
    page.w.getSelection().removeAllRanges(); page.fire( 'selectionchange' ); page.frame();
    page.select( '#plain' ); page.frame(); page.fire( 'resize', page.w ); shown( page, false, 'Resize hides stale selection coordinates' );
    page.rect( { top: -50, bottom: -30 } ); page.select( '#plain' ); page.frame(); shown( page, false, 'Offscreen selections do not show actions' );
    page.close();
    page = await visit(); page.select( '#plain' );
    page.fire( 'scroll', page.w ); page.frame();
    shown( page, false, 'A pending selection frame cannot reopen the action after scrolling' ); page.close();
    page = await visit( { height: 180, headerBottom: 160 } ); page.select( '#plain' ); page.frame();
    assert.ok( page.action().hidden || Number.parseFloat( page.action().style.top ) + page.popup.height <= page.w.innerHeight,
        'Short viewports never place the action outside the viewport' ); page.close(); cases++;
    console.log( 'Selection search: ' + cases + ' source-only text, privacy, URL, throttling, keyboard, and position checks pass.' );
}

main().catch( error => { console.error( error ); process.exitCode = 1; } );
