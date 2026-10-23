'use strict';

// Synthetic measurements exercise the actual shell controller; they do not
// verify CSS sticky positioning or live VisualEditor/browser integration.
// Run with jsdom 27 via NODE_PATH: node ops/test-locked-header.cjs
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );
const fullSource = fs.readFileSync( path.join( __dirname,
    '../skins/EQLImmersive/resources/main.js' ), 'utf8' );
const source = fullSource.slice( 0, fullSource.indexOf( '}());' ) + 5 );
let checks = 0;
function check( value, message ) { checks++; assert.ok( value, message ); }

function fixture( skin = 'skin-eqlimmersive' ) {
    const errors = [];
    const vc = new VirtualConsole();
    vc.on( 'jsdomError', error => errors.push( error ) );
    const dom = new JSDOM( '<body class="' + skin + '">' +
        '<div class="vector-header-container"><header class="vector-header">' +
        '<div class="vector-header-start"><nav class="eql-main-menu-landmark">' +
        '<button id="eql-main-menu-toggle">Menu</button></nav></div>' +
        '<div id="vector-user-links-dropdown"><button id="vector-user-links-dropdown-label">User</button></div>' +
        '<ul><li id="ca-edit" class="eql-source-edit-action"><a href="/wiki/Synthetic?action=edit&oldid=42&veswitched=1"><span>Edit</span></a></li></ul>' +
        '</header></div><h1 id="firstHeading">Synthetic page</h1>' +
        '<div id="eql-main-menu-host"><div id="vector-main-menu"><div class="vector-pinnable-header"></div></div></div>' +
        '<div id="eql-user-menu-host"></div><div id="p-personal"><ul><li><a>Preferences</a></li></ul></div>' +
        '<div id="mw-content-text"><div class="mw-parser-output"><div class="mw-heading"><h2 id="One">One' +
        '<button class="eql-todo-add" aria-label="Add to todo list">Add</button></h2></div></div></div></body>',
    { url: 'https://wiki.example/wiki/Synthetic', runScripts: 'outside-only', virtualConsole: vc } );
    const w = dom.window;
    const d = w.document;
    let headerHeight = 60;
    let viewportHeight = 700;
    let resizeCallback;
    const config = new Map();
    const hooks = new Map();
    w.mw = { config: { set( key, value ) { config.set( key, value ); }, get( key ) { return config.get( key ); } },
        hook( name ) { return { add( callback ) { hooks.set( name, callback ); } }; } };
    w.ve = { init: { target: { toolbarScrollOffset: 0 } } };
    d.querySelector( '.vector-header-container' ).getBoundingClientRect = () =>
        ( { height: headerHeight, top: 0, bottom: headerHeight } );
    d.getElementById( 'vector-user-links-dropdown-label' ).getBoundingClientRect = () =>
        ( { top: 8, bottom: 46, right: 340 } );
    Object.defineProperty( w, 'innerHeight', { get: () => viewportHeight } );
    Object.defineProperty( d, 'readyState', { get: () => 'complete' } );
    w.ResizeObserver = class {
        constructor( callback ) { resizeCallback = callback; }
        observe() {}
    };
    w.setTimeout = () => 1;
    w.eval( source );
    return { w, d, errors, config, hooks, resize( height ) { headerHeight = height; resizeCallback(); },
        viewport( height ) { viewportHeight = height; w.dispatchEvent( new w.Event( 'resize' ) ); },
        close() { w.close(); } };
}

// Execute the bundled Vector observer rather than reimplementing its gate.
// Its duplicate header's absence must disable takeover for signed-in readers.
function nativeVectorObserver( fixture, duplicate ) {
    const { w, d, config } = fixture;
    if ( duplicate ) {
        const node = d.createElement( 'div' ); node.id = 'vector-sticky-header'; d.body.appendChild( node );
    }
    config.set( 'wgNamespaceNumber', 0 ); config.set( 'wgAction', 'view' );
    w.mw.user = { isAnon: () => false };
    w.matchMedia = () => ( { matches: false } );
    d.documentElement.classList.add( 'vector-sticky-header-enabled' );
    let initialized = 0;
    let scrollDown;
    const sticky = {
        STICKY_HEADER_ID: 'vector-sticky-header', FIRST_HEADING_ID: 'firstHeading',
        USER_LINKS_DROPDOWN_ID: 'vector-user-links-dropdown',
        isAllowedNamespace: () => true, isAllowedAction: () => true,
        show() { d.body.classList.add( 'vector-sticky-header-visible' ); },
        hide() { d.body.classList.remove( 'vector-sticky-header-visible' ); },
        initStickyHeader() { initialized++; }
    };
    const dependencies = {
        './stickyHeader.js': sticky,
        './scrollObserver.js': { initScrollObserver( show ) { scrollDown = show; return { observe() {} }; }, firePageTitleScrollHook() {} },
        './features.js': { isEnabled: () => false },
        './pinnableElement.js': { movePinnableElement() {} }
    };
    w.__nativeRequire = name => dependencies[ name ] || ( () => {} );
    w.__nativeModule = { exports: {} };
    const nativeSource = fs.readFileSync( path.join( __dirname,
        '../skins/Vector/resources/skins.vector.js/setupIntersectionObservers.js' ), 'utf8' );
    w.eval( '(function(require,module,exports){' + nativeSource +
        '\n})(window.__nativeRequire,window.__nativeModule,window.__nativeModule.exports);' );
    w.__nativeModule.exports.main();
    return { initialized: () => initialized, scrollDown: () => scrollDown() };
}

const f = fixture();
const root = f.d.documentElement;
const native = nativeVectorObserver( f, false );
check( native.initialized() === 0 && !root.classList.contains( 'vector-sticky-header-enabled' ),
    'Omitting the duplicate disables native Vector sticky-header initialization for signed-in readers' );
native.scrollDown();
check( !f.d.body.classList.contains( 'vector-sticky-header-visible' ),
    'Scrolling past the title cannot activate a hidden replacement header' );
check( f.d.getElementById( 'eql-main-menu-toggle' ).tabIndex === 0 &&
    f.d.querySelector( '.vector-header' ).getAttribute( 'aria-hidden' ) !== 'true',
    'The original menu and header remain available after the native scroll callback' );
check( root.classList.contains( 'eql-locked-header' ), 'Locked header activates only on the EQL skin' );
check( root.style.getPropertyValue( '--eql-header-height' ) === '60px', 'Header measurement reaches CSS anchors' );
check( f.config.get( 'wgVisualEditorToolbarScrollOffset' ) === 60 &&
    f.w.ve.init.target.toolbarScrollOffset === 60, 'Native VE floating-toolbar offset matches the header' );
const main = f.d.getElementById( 'eql-main-menu-host' );
check( main.style.top === '68px', 'Floating main menu follows the measured header' );
check( f.d.querySelector( '.eql-main-menu-toc-text' ).textContent.trim() === 'One', 'Todo controls stay out of TOC labels' );
f.d.getElementById( 'vector-user-links-dropdown-label' ).click();
const user = f.d.getElementById( 'eql-user-menu-host' );
check( user.style.top === '66px' && user.style.maxHeight === '586px', 'User menu fits beneath the full header' );
f.resize( 110 );
check( root.style.getPropertyValue( '--eql-header-height' ) === '110px' && main.style.top === '118px',
    'Wrapping toolbar updates anchors and main menu' );
check( f.config.get( 'wgVisualEditorToolbarScrollOffset' ) === 110 &&
    f.w.ve.init.target.toolbarScrollOffset === 110, 'Resizing updates an already-active editor offset' );
check( user.style.top === '116px' && user.style.maxHeight === '536px', 'User panel follows the wrapping header' );
f.viewport( 300 );
check( user.style.maxHeight === '136px', 'User menu remains within a short viewport' );
f.d.getElementById( 'eql-main-menu-toggle' ).click();
f.d.getElementById( 'eql-main-menu-pin-toggle' ).click();
check( f.d.body.classList.contains( 'eql-main-menu-pinned' ) && main.style.top === '',
    'Pinned sidebar retains its full-height position' );
f.d.getElementById( 'eql-main-menu-pin-toggle' ).click();
check( !f.d.body.classList.contains( 'eql-main-menu-pinned' ) && main.style.top === '118px',
    'Unpinning restores placement beneath the header' );

const edit = f.d.querySelector( '#ca-edit a' );
let visualIntercepts = 0;
edit.parentNode.addEventListener( 'click', event => { visualIntercepts++; event.preventDefault(); } );
edit.href = '/wiki/Synthetic?veaction=edit&oldid=42';
// A modified click must retain normal browser navigation instead of calling
// the simulated VE handler on the native tab.
const click = new f.w.MouseEvent( 'click', { bubbles: true, cancelable: true, ctrlKey: true } );
edit.querySelector( 'span' ).dispatchEvent( click );
const url = new URL( edit.href );
check( visualIntercepts === 0 && !click.defaultPrevented, 'Read-page source action bypasses the VE preference and preserves native navigation' );
check( url.searchParams.get( 'action' ) === 'edit' && url.searchParams.get( 'veswitched' ) === '1' &&
    url.searchParams.get( 'oldid' ) === '42' && !url.searchParams.has( 'veaction' ), 'Source navigation keeps revision context' );
const plainClick = new f.w.MouseEvent( 'click', { bubbles: true, cancelable: true } );
edit.dispatchEvent( plainClick );
check( visualIntercepts === 0 && !plainClick.defaultPrevented, 'Unmodified clicks also retain source navigation' );
root.classList.add( 've-activated' );
edit.dispatchEvent( new f.w.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
check( visualIntercepts === 1, 'Active VE sessions retain native switching and unsaved-change handling' );
root.classList.remove( 've-activated' );
edit.querySelector( 'span' ).textContent = 'Edit source';
f.hooks.get( 've.deactivationComplete' )();
check( edit.textContent === 'Edit', 'Leaving VE restores the single Edit label' );
check( f.errors.length === 0, 'Shell fixture completes without script errors' );
f.close();

const other = fixture( 'skin-vector-2022' );
check( !other.d.documentElement.classList.contains( 'eql-locked-header' ), 'Other skins keep their own header behavior' );
other.close();
const vectorControl = fixture();
const nativeEnabled = nativeVectorObserver( vectorControl, true );
nativeEnabled.scrollDown();
check( nativeEnabled.initialized() === 1 && vectorControl.d.body.classList.contains( 'vector-sticky-header-visible' ),
    'Control fixture proves the native takeover still initializes when duplicate markup exists' );
vectorControl.close();
console.log( 'Passed ' + checks + ' synthetic locked-header and source-navigation checks.' );
