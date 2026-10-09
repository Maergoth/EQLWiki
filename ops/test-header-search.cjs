'use strict';

// Source-only behavior regression. Geometry is synthetic; CSS flex layout and
// native search suggestions still require real browser integration checks.
// Run with jsdom 27 available through NODE_PATH: node ops/test-header-search.cjs
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );
const fullSource = fs.readFileSync( path.join( __dirname,
    '../skins/EQLImmersive/resources/header-search.js' ), 'utf8' );
const endMarker = '}());';
const firstEnd = fullSource.indexOf( endMarker );
assert.ok( firstEnd > 0, 'Header search controller must have its own IIFE' );
// Exercise the actual controller, leaving the separate suggestion throttle out.
const source = fullSource.slice( 0, firstEnd + endMarker.length );
let checks = 0;

function check( condition, label ) {
    checks++;
    assert.ok( condition, label );
}

function fixture( options = {} ) {
    const errors = [];
    const virtualConsole = new VirtualConsole();
    virtualConsole.on( 'jsdomError', error => errors.push( error ) );
    const dom = new JSDOM( '<body class="' + ( options.skin || 'skin-eqlimmersive' ) + '">' +
        '<div class="vector-header-container"><header class="vector-header mw-header">' +
        '<div class="vector-header-start"><a class="mw-logo">Synthetic wiki</a></div>' +
        '<div class="vector-header-end">' +
        ( options.search === false ? '' : '<div id="p-search" class="vector-search-box">' +
            '<a class="search-toggle" href="/wiki/Special:Search" title="Search">Search</a>' +
            '<div class="vector-typeahead-search-container"><div class="cdx-typeahead-search">' +
            '<form id="searchform" class="cdx-search-input" action="/index.php">' +
            '<div id="simpleSearch" class="cdx-search-input__input-wrapper"><div class="cdx-text-input">' +
            '<input id="searchInput" name="search" type="search" aria-label="Search wiki"></div></div>' +
            '<button class="cdx-search-input__end-button">Search</button></form></div></div></div>' ) +
        '<div class="eql-header-page-actions-wrap"><a>Read</a><a>Talk</a><a>Edit source</a><a>History</a></div>' +
        '<button id="eql-era-filter-toggle">Era</button><div class="vector-user-links"><a>Example user</a></div>' +
        '</div></header></div><button id="outside">Outside</button>' +
        '<div id="eql-header-search-suggestions"><a id="custom-suggestion" href="/wiki/Synthetic">Synthetic</a></div>' +
        '<div class="suggestions"><a id="native-suggestion" href="/wiki/Synthetic">Synthetic</a></div></body>', {
        url: 'https://wiki.example/wiki/Synthetic', runScripts: 'outside-only', virtualConsole
    } );
    const w = dom.window;
    const d = w.document;
    const search = d.getElementById( 'p-search' );
    const input = d.getElementById( 'searchInput' );
    const toggle = search && search.querySelector( '.search-toggle' );
    const form = d.getElementById( 'searchform' );
    const header = d.querySelector( '.vector-header' );
    const start = d.querySelector( '.vector-header-start' );
    const end = d.querySelector( '.vector-header-end' );
    const actions = d.querySelector( '.eql-header-page-actions-wrap' );
    const era = d.getElementById( 'eql-era-filter-toggle' );
    const user = d.querySelector( '.vector-user-links' );
    const state = { viewport: 1400, endWidth: 832, startWidth: 240,
        actionsWidth: 280, userWidth: 96, eraWidth: 38, gap: 8, padding: 12,
        actionMargin: 3, userMargin: 2, hideActions: false, hideUser: false, ...options.geometry };
    let now = 0;
    let sequence = 0;
    const timers = new Map();
    const frames = new Map();
    const observers = [];
    const widgets = [];
    const lifecycle = [];
    let ready = 'loading';
    Object.defineProperty( d, 'readyState', { configurable: true, get: () => ready } );
    Object.defineProperty( w, 'innerWidth', { configurable: true, get: () => state.viewport } );
    w.matchMedia = query => ( { matches: /max-width/.test( query ) && state.viewport <= 1500,
        addEventListener() {}, removeEventListener() {} } );
    w.setTimeout = ( callback, delay = 0 ) => { const id = ++sequence;
        timers.set( id, { callback, due: now + Number( delay ) } ); return id; };
    w.clearTimeout = id => timers.delete( id );
    w.requestAnimationFrame = callback => { const id = ++sequence; frames.set( id, callback ); return id; };
    w.cancelAnimationFrame = id => frames.delete( id );
    class FixtureResizeObserver {
        constructor( callback ) { this.callback = callback; this.targets = new Set(); observers.push( this ); }
        observe( target ) { this.targets.add( target ); }
        unobserve( target ) { this.targets.delete( target ); }
        disconnect() { this.targets.clear(); }
    }
    w.ResizeObserver = options.resizeObserver === false ? undefined : FixtureResizeObserver;
    const realComputedStyle = w.getComputedStyle.bind( w );
    function hidden( element ) {
        return element === actions && state.hideActions || element === user && state.hideUser || element.hidden;
    }
    function available() {
        const fixed = [ [ actions, state.actionsWidth, state.actionMargin ], [ era, state.eraWidth, 0 ],
            [ user, state.userWidth, state.userMargin ] ].concat( widgets.map( widget =>
            [ widget.element, widget.width, 0 ] ) ).filter( ( [ element ] ) => !hidden( element ) );
        return state.endWidth - state.padding * 2 -
            fixed.reduce( ( sum, [ , width, margin ] ) => sum + width + margin * 2, 0 ) - state.gap * fixed.length;
    }
    function rect( width, left = 0, top = 10, height = 38 ) {
        return { x: left, y: top, left, top, width, height, right: left + width, bottom: top + height,
            toJSON() { return { left, top, width, height }; } };
    }
    function inlineRect() {
        const free = available();
        const width = d.body.classList.contains( 'eql-search-compact' ) ? 38 : Math.max( 76, Math.min( free, 544 ) );
        const left = state.startWidth + 24 + Math.max( 0, ( free - width ) / 2 );
        return rect( width, left );
    }
    function formRect() {
        if ( !d.body.classList.contains( 'eql-search-compact' ) ||
            !d.body.classList.contains( 'eql-search-open' ) ) { return inlineRect(); }
        const width = parseFloat( search.style.getPropertyValue( '--eql-search-overlay-width' ) ) || 320;
        const left = parseFloat( search.style.getPropertyValue( '--eql-search-overlay-left' ) ) || 8;
        const top = parseFloat( search.style.getPropertyValue( '--eql-search-overlay-top' ) ) || 10;
        return rect( width, left, top );
    }
    for ( const [ element, geometry ] of [
        [ header, () => rect( state.endWidth + state.startWidth + 40 ) ],
        [ start, () => rect( state.startWidth, 16 ) ],
        [ end, () => rect( state.endWidth, state.startWidth + 24 ) ],
        [ actions, () => rect( state.hideActions ? 0 : state.actionsWidth ) ],
        [ user, () => rect( state.hideUser ? 0 : state.userWidth ) ],
        [ era, () => rect( state.eraWidth ) ],
        [ search, inlineRect ], [ toggle, () => rect( 38, inlineRect().left ) ], [ form, formRect ]
    ] ) {
        if ( !element ) { continue; }
        element.getBoundingClientRect = geometry;
        Object.defineProperty( element, 'offsetWidth', { configurable: true, get: () => geometry().width } );
        Object.defineProperty( element, 'clientWidth', { configurable: true, get: () => geometry().width } );
        element.getClientRects = () => hidden( element ) ? [] : [ geometry() ];
    }
    w.getComputedStyle = element => {
        const base = realComputedStyle( element );
        const overrides = { display: hidden( element ) ? 'none' : 'flex', visibility: 'visible',
            marginLeft: '0px', marginRight: '0px', paddingLeft: '0px', paddingRight: '0px',
            borderLeftWidth: '0px', borderRightWidth: '0px', gap: '0px', columnGap: '0px' };
        if ( element === end ) { overrides.paddingLeft = overrides.paddingRight = state.padding + 'px';
            overrides.gap = overrides.columnGap = state.gap + 'px'; }
        if ( element === actions ) { overrides.marginLeft = overrides.marginRight = state.actionMargin + 'px'; }
        if ( element === user ) { overrides.marginLeft = overrides.marginRight = state.userMargin + 'px'; }
        return new Proxy( base, { get( target, property ) {
            if ( Object.hasOwn( overrides, property ) ) { return overrides[ property ]; }
            if ( property === 'getPropertyValue' ) { return name => {
                const camel = name.replace( /-([a-z])/g, ( _, letter ) => letter.toUpperCase() );
                return Object.hasOwn( overrides, camel ) ? overrides[ camel ] : target.getPropertyValue( name );
            }; }
            const value = target[ property ]; return typeof value === 'function' ? value.bind( target ) : value;
        } } );
    };
    d.addEventListener( 'eqlSearchOpen', () => lifecycle.push( 'open' ) );
    d.addEventListener( 'eqlSearchClose', () => lifecycle.push( 'close' ) );
    w.addEventListener( 'error', event => errors.push( event.error || event.message ) );
    function flushFrames() {
        let rounds = 0;
        while ( frames.size ) {
            assert.ok( rounds++ < 20, 'Resize updates must settle instead of scheduling an endless frame loop' );
            const callbacks = Array.from( frames.values() ); frames.clear();
            callbacks.forEach( callback => callback( now ) );
        }
    }
    function advance( duration ) {
        const endTime = now + duration;
        let count = 0;
        while ( true ) {
            const next = Array.from( timers.entries() ).filter( ( [ , timer ] ) => timer.due <= endTime )
                .sort( ( a, b ) => a[ 1 ].due - b[ 1 ].due )[ 0 ];
            if ( !next ) { break; }
            assert.ok( count++ < 200, 'Timers must remain bounded' );
            now = next[ 1 ].due; timers.delete( next[ 0 ] ); next[ 1 ].callback(); flushFrames();
        }
        now = endTime; flushFrames();
    }
    w.eval( source ); ready = 'complete';
    d.dispatchEvent( new w.Event( 'DOMContentLoaded' ) ); flushFrames(); advance( 0 );
    return { w, d, state, search, input, toggle, form, end, start, actions, era, user,
        observers, lifecycle, errors, advance, flushFrames, available,
        compact: () => d.body.classList.contains( 'eql-search-compact' ),
        narrow: () => d.body.classList.contains( 'eql-search-narrow-inline' ),
        open: () => d.body.classList.contains( 'eql-search-open' ),
        insertWidget( width ) {
            const widget = { element: d.createElement( 'div' ), width };
            widget.element.textContent = 'Late account widget';
            widget.element.getBoundingClientRect = () => rect( widget.width );
            widgets.push( widget ); end.appendChild( widget.element );
            return widget;
        },
        notify( target = end, burst = 1 ) {
            for ( let i = 0; i < burst; i++ ) {
                for ( const observer of observers ) {
                    if ( observer.targets.has( target ) ) {
                        observer.callback( [ { target, contentRect: target.getBoundingClientRect() } ], observer );
                    }
                }
            }
            return frames.size;
        },
        resize() { w.dispatchEvent( new w.Event( 'resize' ) ); flushFrames(); advance( 100 ); },
        clickToggle() { toggle.dispatchEvent( new w.MouseEvent( 'click', { bubbles: true, cancelable: true } ) ); },
        escape() { d.dispatchEvent( new w.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) ); },
        pointer( target ) { target.dispatchEvent( new w.MouseEvent( 'pointerdown', { bubbles: true } ) ); },
        submit() { const event = new w.Event( 'submit', { bubbles: true, cancelable: true } );
            form.dispatchEvent( event ); return event; },
        close() { assert.deepEqual( errors, [], 'No uncaught controller errors' ); dom.window.close(); }
    };
}

async function main() {
    let page = fixture();
    check( !page.compact() && !page.narrow(), 'Roomy header below old viewport cutoff remains inline' );
    check( page.search.classList.contains( 'eql-search-native-ready' ), 'Native form initializes once' );
    check( page.observers.some( observer => observer.targets.has( page.actions ) && observer.targets.has( page.user ) ),
        'Fixed header content is observed for changes without viewport resize' );
    page.state.viewport = 1900; page.resize();
    check( !page.compact(), 'Growing viewport alone does not change a stable content budget' );
    page.state.viewport = 1250; page.resize();
    check( !page.compact(), 'Shrinking viewport alone does not impose the former media cutoff' );
    page.state.actionsWidth += 240;
    page.notify( page.actions ); page.flushFrames();
    check( !page.compact() && page.narrow(), 'Additional page actions shrink into a narrow inline field' );
    page.state.userWidth += 90;
    page.notify( page.user ); page.flushFrames();
    check( page.compact() && !page.narrow(), 'Longer account controls collapse search only after inline space is exhausted' );
    const pending = page.notify( page.end, 40 );
    check( pending <= 1, 'Observer bursts coalesce into a single pending frame' ); page.flushFrames();
    check( page.compact() && page.lifecycle.length === 0, 'Stable repeated observations do not oscillate/open search' );
    page.state.hideActions = true; page.notify( page.actions ); page.flushFrames();
    check( !page.compact(), 'Hidden actions stop consuming measured header width' );
    page.close();

    page = fixture();
    page.input.value = 'Late widget selected query'; page.input.focus(); page.input.setSelectionRange( 1, 6 );
    const widget = page.insertWidget( 300 );
    await Promise.resolve(); page.flushFrames();
    check( page.compact() && page.open() && page.d.activeElement === page.input,
        'Late direct header widget triggers layout and keeps the focused field visible' );
    check( page.observers.some( observer => observer.targets.has( widget.element ) ),
        'Late direct header widget is added to ResizeObserver' );
    page.advance( 200 );
    check( page.input.value === 'Late widget selected query' && page.input.selectionStart === 1 &&
        page.input.selectionEnd === 6, 'Late insertion preserves query and selected text through delayed work' );
    widget.width = 160; page.notify( widget.element ); page.flushFrames();
    check( !page.compact() && !page.open() && page.d.activeElement === page.input,
        'Resizing the newly observed widget restores the focused inline field without viewport resize' );
    check( page.input.selectionStart === 1 && page.input.selectionEnd === 6,
        'New widget resize preserves selected text' ); page.close();

    page = fixture( { geometry: { viewport: 2100, endWidth: 510 } } );
    check( page.compact(), 'Crowded content can require an icon even on a wide viewport' );
    page.input.value = 'Synthetic query'; page.clickToggle(); page.advance( 150 );
    check( page.open() && page.d.activeElement === page.input, 'Icon opens native field and transfers focus' );
    check( page.toggle.getAttribute( 'aria-expanded' ) === 'true' &&
        page.toggle.getAttribute( 'aria-controls' ) === 'searchform', 'Toggle exposes accessible open state' );
    check( !page.submit().defaultPrevented, 'Nonempty native submit remains immediate' );
    page.escape();
    check( !page.open() && page.d.activeElement === page.toggle, 'Escape closes overlay and returns focus to icon' );
    check( page.input.value === 'Synthetic query', 'Closing search preserves entered query' );
    page.advance( 200 );
    check( page.d.activeElement === page.toggle, 'Delayed focus cannot undo Escape' );
    page.clickToggle(); page.escape(); page.advance( 200 );
    check( !page.open() && page.d.activeElement === page.toggle, 'Immediate Escape cancels queued opening focus' );
    page.clickToggle(); page.advance( 0 );
    page.pointer( page.d.getElementById( 'custom-suggestion' ) );
    check( page.open(), 'Custom suggestion pointer is treated as search UI' );
    page.pointer( page.d.getElementById( 'native-suggestion' ) );
    check( page.open(), 'Native suggestion pointer is treated as search UI' );
    const outside = page.d.getElementById( 'outside' );
    page.pointer( outside ); outside.focus(); page.advance( 200 );
    check( !page.open() && page.d.activeElement === outside, 'Outside pointer closes overlay without delayed focus theft' );
    page.input.value = '   ';
    check( page.submit().defaultPrevented, 'Empty submission stays on page' ); page.advance( 150 );
    check( page.open() && page.d.activeElement === page.input, 'Empty submission exposes focused native search' );
    page.escape(); page.advance( 150 );
    page.state.endWidth = 832; page.toggle.focus(); page.notify( page.end ); page.flushFrames();
    check( !page.compact() && page.d.activeElement === page.input,
        'Returning inline transfers focus from the disappearing icon to input' );
    check( page.toggle.getAttribute( 'aria-expanded' ) === 'false', 'Returning inline leaves overlay closed' );
    page.close();

    page = fixture();
    page.input.value = 'Synthetic selected query'; page.input.focus(); page.input.setSelectionRange( 2, 8 );
    page.state.endWidth = 510; page.notify( page.end ); page.flushFrames();
    check( page.compact() && page.open() && page.d.activeElement === page.input,
        'Focused inline field opens overlay synchronously when the content budget collapses' );
    check( page.input.selectionStart === 2 && page.input.selectionEnd === 8,
        'Collapse preserves selected text/caret' ); page.advance( 200 );
    check( page.input.selectionStart === 2 && page.input.selectionEnd === 8,
        'Delayed focus preserves selection while adapting the existing field' );
    page.state.endWidth = 832; page.notify( page.end ); page.flushFrames();
    check( !page.compact() && !page.open() && page.d.activeElement === page.input,
        'Focused overlay returns inline without losing focus' );
    check( page.input.value === 'Synthetic selected query' && page.input.selectionStart === 2 &&
        page.input.selectionEnd === 8, 'Growing header preserves query and selection' );
    check( page.search.style.getPropertyValue( '--eql-search-overlay-width' ) === '',
        'Returning inline clears obsolete overlay positioning' ); page.close();

    page = fixture( { geometry: { viewport: 375, endWidth: 160, hideActions: true, userWidth: 38 } } );
    check( page.compact(), 'Phone-sized content budget uses the icon' );
    page.clickToggle(); page.advance( 150 );
    const width = parseFloat( page.search.style.getPropertyValue( '--eql-search-overlay-width' ) );
    const left = parseFloat( page.search.style.getPropertyValue( '--eql-search-overlay-left' ) );
    check( width > 0 && left >= 0 && left + width <= page.state.viewport,
        'Phone overlay remains within the viewport' );
    const suggestions = page.d.getElementById( 'eql-header-search-suggestions' );
    check( parseFloat( suggestions.style.getPropertyValue( '--eql-search-suggest-width' ) ) === width,
        'Suggestion panel follows the opened form geometry' );
    page.state.viewport = 480; page.resize();
    check( page.open() && page.d.activeElement === page.input, 'Orientation resize preserves open overlay focus' );
    const resizedWidth = parseFloat( page.search.style.getPropertyValue( '--eql-search-overlay-width' ) );
    const resizedLeft = parseFloat( page.search.style.getPropertyValue( '--eql-search-overlay-left' ) );
    check( resizedLeft + resizedWidth <= page.state.viewport, 'Resized overlay stays within viewport' );
    page.escape(); page.advance( 150 ); page.close();

    page = fixture( { resizeObserver: false } );
    check( !page.compact(), 'Inline mode works without ResizeObserver' );
    page.state.endWidth = 510; page.resize();
    check( page.compact(), 'Window resize is a working observer fallback' );
    page.state.endWidth = 832; page.resize();
    check( !page.compact(), 'Fallback also restores inline mode when space returns' ); page.close();
    for ( const options of [ { skin: 'skin-vector' }, { search: false } ] ) {
        page = fixture( options );
        check( page.observers.length === 0 && !page.compact(), 'Unrelated skin/missing search stays untouched' ); page.close();
    }
    console.log( 'Passed ' + checks + ' synthetic elastic header-search geometry, focus, keyboard and lifecycle checks.' );
    console.log( 'Actual flex widths, compressed input chrome, native suggestions and mobile/browser painting require integration checks.' );
}

main().catch( error => { console.error( error ); process.exitCode = 1; } );
