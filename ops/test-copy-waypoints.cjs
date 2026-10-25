// Rendered article behavior; no wiki, clipboard, or account writes.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const { JSDOM } = require( 'jsdom' );
const source = fs.readFileSync( 'skins/EQLImmersive/resources/copy-waypoints.js', 'utf8' );

function fixture( html, options = {} ) {
    const dom = new JSDOM( '<body><div id="mw-content-text"><div class="mw-parser-output">' +
        html + '</div></div><nav>(12, -34)</nav></body>', {
        url: 'https://wiki.example/Synthetic_waypoints' + ( options.query || '' ), runScripts: 'outside-only'
    } );
    const w = dom.window, hooks = new Map(), copied = [], timers = new Map();
    let timerId = 0;
    w.setTimeout = callback => { timers.set( ++timerId, callback ); return timerId; };
    w.clearTimeout = id => timers.delete( id );
    w.mw = {
        config: { get: name => ( { wgAction: 'view', wgNamespaceNumber: 0, ...options.config } )[ name ] },
        hook: name => ( {
            add: callback => hooks.set( name, callback ),
            fire: content => hooks.get( name )?.( content )
        } )
    };
    if ( !options.noClipboard ) {
        Object.defineProperty( w.navigator, 'clipboard', { value: {
            writeText: text => { copied.push( text ); return options.reject ? Promise.reject( new Error( 'Denied' ) ) : Promise.resolve(); }
        } } );
    }
    w.document.execCommand = command => {
        assert.equal( command, 'copy' );
        if ( options.fallback ) { copied.push( w.document.querySelector( 'textarea' ).value ); }
        return Boolean( options.fallback );
    };
    w.eval( source );
    const root = w.document.querySelector( '.mw-parser-output' );
    const refresh = ( content = root ) => w.mw.hook( 'wikipage.content' ).fire( content );
    refresh();
    const buttons = () => [ ...root.querySelectorAll( '.eql-copy-waypoint' ) ];
    return { w, root, copied, buttons, refresh, timers, close: () => dom.window.close(),
        flush: async () => { for ( let i = 0; i < 5; i++ ) { await Promise.resolve(); } },
        expire: () => { const callbacks = [ ...timers.values() ]; timers.clear(); callbacks.forEach( callback => callback() ); } };
}

( async () => {
    const f = fixture( `
        <p id="example"><b>Location:</b> (107, -438)</p>
        <p id="decimal">At zone (−10.25, +20, .5).</p>
        <p id="multiple">(15, -27), (42, 63)</p>
        <p id="plain">107, -438</p>
        <p id="sentence">Loc: 4, -5.</p>
        <p id="split">Location: (<em>11.50</em>, <strong>-22</strong>, 0)</p>
        <table><tr><th><b>Succor/Evacuate:</b></th><td id="evac">-644, 158 (Zone line to Greater Faydark)</td></tr>
        <tr><td id="cell">1.25, -2.5, 0</td></tr></table>
        <p id="thousands">Cost (1,234) and 1,234,567 coins.</p>
        <p id="four">(1, 2, 3, 4) and Location: 1, 2, 3, 4</p>
        <p id="unknown">Location: Various - static and roamers</p>
        <p><a id="ordinary-link" href="/Other">(22, -33)</a></p>
        <pre>(12, -34)</pre><code>(12, -34)</code><div hidden>(12, -34)</div>
        <form><span>(12, -34)</span></form><div contenteditable="true">(12, -34)</div>
        <div class="diff">(12, -34)</div>
        <span class="loc-link" data-zone="Firiona Vie" data-loc="-3648, 2043"><a id="loc-anchor" href="/Firiona_Vie">-3648, 2043</a> <a id="map-anchor" href="/Map"><img alt="Map" src="map.png"></a></span>
        <span class="loc-link" data-zone="Town" data-loc="10, -20"><a id="alias-anchor" href="/Town">Loom</a></span>
    ` );
    const find = id => f.root.querySelector( '#' + id );
    assert.equal( find( 'example' ).querySelector( 'button' ).dataset.eqlWaypoint, '/way 107, -438' );
    assert.equal( find( 'decimal' ).querySelector( 'button' ).dataset.eqlWaypoint, '/way -10.25, +20, .5' );
    assert.equal( find( 'multiple' ).querySelectorAll( 'button' ).length, 2, 'Separate tuples stay separate' );
    assert.equal( find( 'plain' ).querySelector( 'button' ).dataset.eqlWaypoint, '/way 107, -438' );
    assert.equal( find( 'sentence' ).querySelector( 'button' ).dataset.eqlWaypoint, '/way 4, -5' );
    assert.equal( find( 'evac' ).querySelector( 'button' ).dataset.eqlWaypoint, '/way -644, 158' );
    assert.equal( find( 'cell' ).querySelector( 'button' ).dataset.eqlWaypoint, '/way 1.25, -2.5, 0' );
    assert( find( 'split' ).querySelector( 'button em' ), 'Inline markup survives' );
    for ( const id of [ 'thousands', 'four', 'unknown', 'ordinary-link', 'alias-anchor' ] ) {
        assert.equal( find( id ).querySelectorAll( '.eql-copy-waypoint' ).length, 0, id );
        assert.equal( find( id ).classList.contains( 'eql-copy-waypoint' ), false, id );
    }
    for ( const el of f.root.querySelectorAll( 'pre, code, [hidden], form, [contenteditable], .diff' ) ) {
        assert.equal( el.querySelectorAll( '.eql-copy-waypoint' ).length, 0, el.outerHTML );
    }
    assert.equal( f.w.document.querySelector( 'nav button' ), null );
    const count = f.buttons().length;
    f.refresh(); f.refresh( { toArray: () => [ f.root ] } );
    assert.equal( f.buttons().length, count, 'Repeated hooks do not duplicate controls' );
    const added = f.w.document.createElement( 'p' ); added.textContent = 'Location: (8, -9)'; f.root.appendChild( added ); f.refresh( added );
    assert.equal( added.querySelector( 'button' ).dataset.eqlWaypoint, '/way 8, -9' );
    const button = find( 'example' ).querySelector( 'button' );
    button.click(); await f.flush();
    assert.equal( f.copied.at( -1 ), '/way 107, -438' );
    assert( button.classList.contains( 'is-copied' ) );
    assert.equal( button.querySelector( '[role="status"]' ).textContent, 'Copied /way 107, -438' );
    f.expire(); assert.equal( button.classList.contains( 'is-copied' ), false );
    assert.equal( button.querySelector( '.eql-copy-waypoint-value' ).textContent, '(107, -438)' );
    const waypoint = find( 'loc-anchor' );
    assert.equal( waypoint.getAttribute( 'href' ), '/Firiona_Vie' );
    assert.equal( find( 'map-anchor' ).getAttribute( 'href' ), '/Map' );
    const click = new f.w.MouseEvent( 'click', { bubbles: true, cancelable: true } );
    waypoint.dispatchEvent( click ); await f.flush();
    assert( click.defaultPrevented ); assert.equal( f.copied.at( -1 ), '/way -3648, 2043' );
    const modified = new f.w.MouseEvent( 'click', { bubbles: true, cancelable: true, ctrlKey: true } );
    waypoint.dispatchEvent( modified ); assert.equal( modified.defaultPrevented, false );
    f.close();

    for ( const options of [ { noClipboard: true, fallback: true }, { reject: true, fallback: true }, { reject: true } ] ) {
        const c = fixture( '<p>(107, -438)</p>', options );
        c.buttons()[ 0 ].click(); await c.flush();
        assert.equal( c.buttons()[ 0 ].classList.contains( 'is-copied' ), Boolean( options.fallback ) );
        if ( options.fallback ) { assert.equal( c.copied.at( -1 ), '/way 107, -438' ); }
        else { assert.match( c.buttons()[ 0 ].querySelector( '[role="status"]' ).textContent, /Could not copy/ ); }
        assert.equal( c.w.document.querySelector( 'textarea' ), null, 'Fallback cleans up its field' );
        c.close();
    }
    for ( const options of [ { config: { wgAction: 'edit' } }, { config: { wgAction: 'history' } },
        { config: { wgNamespaceNumber: -1 } }, { query: '?diff=prev' }, { query: '?oldid=1' }, { query: '?veaction=edit' } ] ) {
        const excluded = fixture( '<p>(107, -438)</p>', options );
        assert.equal( excluded.buttons().length, 0, JSON.stringify( options ) ); excluded.close();
    }
    console.log( 'Waypoints: rendered formats, precision, map links, dynamic content, exclusions, clipboard success/failure and feedback passed.' );
} )().catch( error => { console.error( error ); process.exitCode = 1; } );
