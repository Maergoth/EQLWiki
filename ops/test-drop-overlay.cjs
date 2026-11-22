'use strict';
// Synthetic DOM/API responses only. No wiki, account, log or database reads/writes.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname, '../extensions/EQLClientData/resources/drop-database.js' ), 'utf8' );
const css = fs.readFileSync( path.join( __dirname, '../extensions/EQLClientData/resources/drop-database.css' ), 'utf8' );
const row = ( extra = {} ) => ( { zone: 'Mist Valley', zoneTitle: 'Mist Valley', npc: 'A test creature', npcTitle: 'A test creature', item: 'Test Blade', itemTitle: 'Test Blade', instance: 'unknown', reports: 40, totalReports: 200, reportShare: 20, ...extra } );
const turn = () => new Promise( resolve => setImmediate( resolve ) );
function fixture( get, options = {} ) {
    const dom = new JSDOM( '<head><style>' + css + '</style></head><body><div class="eql-mobpage"><div class="eql-mobpage-hero-loot"><h2>Known Loot</h2><ul><li id="manual"><span class="hbdiv"><a href="/wiki/Test_Blade" title="Displayed alias">My manual label</a><span class="hb"><div class="itemtopbg">Tooltip title</div><div class="itembg"><div class="itemdata">WT:0.5 Class:ALL<div class="ils-slider-container">Rank slider</div></div></div></span></span> <span class="drare">(Common)</span> <span class="ddb">[1] 4x</span></li><li id="no-report"><a href="/wiki/Other_Item">Other item</a><span class="ddb">[9] 40%</span><div class="itembg">Unwanted inline stats</div></li><li id="case"><a href="/wiki/Test_blade">Case-distinct item</a></li><li id="query"><a href="/index.php?title=Query_Item&amp;action=edit&amp;redlink=1">A redlink label</a> (Rare)</li></ul></div><div class="eql-mobpage-common-loot"><ul><li id="common"><a href="/wiki/Common_Item">Common item</a> (notes)</li></ul></div></div></body>', { url: 'https://wiki.example/wiki/A_test_creature', runScripts: 'outside-only' } );
    const window = dom.window, document = window.document, calls = [], hooks = [];
    if ( options.html ) { document.body.innerHTML = options.html; }
    const config = { wgPageName: 'A_test_creature', wgArticlePath: '/wiki/$1', wgIsArticle: true, wgAction: 'view' };
    window.mw = { config: { get: key => config[ key ] }, util: { getUrl: options.getUrl || ( title => '/wiki/' + encodeURIComponent( title.replace( / /g, '_' ) ) ) }, hook: () => ( { add: fn => hooks.push( fn ) } ), Api: function () { this.get = ( params, options ) => { calls.push( { params, options } );return get ? get() : Promise.resolve( { eqldrops: { rows: [] } } ); }; } };
    window.eval( source );document.dispatchEvent( new window.Event( 'DOMContentLoaded' ) );
    const root = document.querySelector( '.eql-mobpage' );
    return { dom, window, document, root, calls, hooks, render: rows => window.EQLDropDatabase.render( root, rows ), close: () => window.close() };
}
( async () => {
    const f = fixture();await turn();await turn();
    const manual = f.document.querySelector( '#manual' ), label = manual.querySelector( 'a' );
    const originalNodes = [ ...manual.childNodes ], hover = manual.querySelector( '.hb' ), rarity = manual.querySelector( '.drare' ), authoredRates = manual.querySelector( '.ddb' );
    const hoverHtml = hover.innerHTML, sourceRates = authoredRates.textContent;
    const rows = [ row(), row( { item: 'New & unseen <item>', itemTitle: null, reports: 2 } ), row( { instance: 'raid', reports: 20, totalReports: 50 } ), row( { zone: 'Other Valley', zoneTitle: 'Other Valley', reports: 30 } ), row( { item: 'Query Item', itemTitle: 'Query Item' } ), row( { item: 'Common Item', itemTitle: 'Common Item' } ) ];
    f.render( rows );
    assert.equal( label.textContent, 'My manual label', 'The authored label stays byte-for-byte unchanged.' );
    assert.equal( label.getAttribute( 'title' ), 'Displayed alias', 'Matching uses the href destination, not display/tooltip text.' );
    assert.deepEqual( [ ...manual.childNodes ].filter( node => !node.classList?.contains( 'eql-drop-generated' ) ), originalNodes, 'Authored rarity notes and their DOM identities remain untouched.' );
    assert.equal( manual.querySelectorAll( '.eql-drop-observed' ).length, 1, 'Only the selected exact context annotates a manual item.' );
    assert.equal( manual.querySelector( '.eql-drop-observed' ).textContent, ' (20%)', 'The literal beside-item badge stays compact.' );
    assert.equal( label.nextSibling, manual.querySelector( '.eql-drop-observed' ), 'The badge appears immediately beside the native transcluded link, before (Common).' );
    const selector = () => f.root.querySelector( '.eql-drop-source-select' );
    assert.equal( f.root.querySelectorAll( '.eql-drop-observations' ).length, 1, 'Multiple sources keep one source-control wrapper.' );
    assert.equal( selector().options.length, 3, 'Zone/instance contexts remain separate selectable sources.' );
    assert.match( selector().selectedOptions[ 0 ].textContent, /Mist Valley.*instance unknown/ );
    assert.match( manual.querySelector( '.eql-drop-observed' ).title, /reported 40 times out of 200 recorded loot events/ );
    assert.doesNotMatch( manual.querySelector( '.eql-drop-observed' ).title, /tier|server|contributor|5 items/i );
    assert.equal( f.window.getComputedStyle( authoredRates ).display, 'none', 'Count-only source blocks stay out of the visible loot list.' );
    assert.equal( authoredRates.textContent, sourceRates, 'The underlying authored percentages remain untouched.' );
    assert.equal( hover.innerHTML, hoverHtml, 'All native tooltip artwork/stats/slider source nodes remain intact.' );
    assert.equal( f.window.getComputedStyle( hover ).display, 'none' );
    assert.equal( f.window.getComputedStyle( hover ).position, 'absolute', 'The tooltip cannot become an inline item card.' );
    assert.notEqual( f.window.getComputedStyle( rarity ).display, 'none', 'Optional rarity stays visible.' );
    assert.equal( f.window.getComputedStyle( f.document.querySelector( '#no-report .itembg' ) ).display, 'none', 'Inline stat cards are suppressed independently of observation availability.' );
    assert.equal( f.document.querySelector( '#case .eql-drop-observed' ), null, 'Different internal case is never silently merged.' );
    assert.equal( f.document.querySelector( '#no-report .eql-drop-observed' ), null );
    assert.equal( f.document.querySelectorAll( '#query .eql-drop-observed' ).length, 1, 'Query-form redlink destinations match without mutating authored notes.' );
    assert.equal( f.document.querySelectorAll( '#common .eql-drop-observed' ).length, 1, 'Common Loot joins the same integration.' );
    assert.equal( f.root.querySelectorAll( '.eql-drop-observed-item' ).length, 1 );
    const appended = f.root.querySelector( '.eql-drop-observed-item' );
    assert.match( appended.textContent, /New & unseen <item>/ );
    assert.equal( appended.parentNode, manual.parentNode, 'Unmatched evidence appends to the same existing Known Loot list.' );
    assert.equal( appended, manual.parentNode.lastElementChild );
    assert.equal( f.root.querySelectorAll( 'script,img' ).length, 0, 'Untrusted evidence remains text.' );
    assert.equal( f.root.querySelectorAll( '.eql-drop-info, .eql-drop-legend, .eql-drop-database-link' ).length, 0, 'The disclosure, explanation and database link are absent even when the source selector is shown.' );
    assert.doesNotMatch( f.root.textContent, /Share of reported drops|Browse the drop database/ );
    f.render( [ row( { reports: 19, totalReports: 125, reportShare: 15.2 } ) ] );
    assert.equal( manual.querySelector( '.eql-drop-observed' ).textContent, ' (15.2%)', 'Removing the disclosure preserves the existing numeric share and compact badge.' );
    f.render( rows );
    const count = f.root.querySelectorAll( '.eql-drop-observed' ).length;
    f.render( rows );assert.equal( f.root.querySelectorAll( '.eql-drop-observed' ).length, count, 'Refreshing the rendered evidence does not duplicate annotations.' );
    selector().value = [ ...selector().options ].find( option => /Raid/.test( option.textContent ) ).value;
    selector().dispatchEvent( new f.window.Event( 'change' ) );
    assert.equal( manual.querySelector( '.eql-drop-observed' ).textContent, ' (40%)' );
    assert.equal( manual.querySelectorAll( '.eql-drop-observed' ).length, 1, 'A source change replaces rather than combines counts.' );
    assert.equal( f.root.querySelectorAll( '.eql-drop-observed-item' ).length, 0, 'Prior context additions disappear on source change.' );
    assert.equal( f.document.activeElement, selector(), 'Changing context preserves native keyboard focus.' );
    selector().value = [ ...selector().options ].find( option => /Other Valley/.test( option.textContent ) ).value;
    selector().dispatchEvent( new f.window.Event( 'change' ) );
    assert.equal( manual.querySelector( '.eql-drop-observed' ).textContent, ' (15%)' );
    f.render( [ row(), row() ] );
    assert.deepEqual( [ ...manual.querySelectorAll( '.eql-drop-observed' ) ].map( node => node.textContent ), [ ' (20%)' ], 'One item never receives duplicate displayed percentages.' );
    assert.equal( selector(), null, 'A single source does not add a redundant selector.' );
    assert.equal( f.root.querySelector( '.eql-drop-observations' ), null, 'A single source adds no empty control wrapper or its margins.' );
    f.render( [ row( { itemTitle: null } ) ] );
    assert.equal( manual.querySelectorAll( '.eql-drop-observed' ).length, 1, 'An exact raw item name matches a link destination when no canonical title was supplied.' );
    assert.equal( f.root.querySelectorAll( '.eql-drop-observed-item' ).length, 0 );
    const singletonRows = [ row( { reports: 1, totalReports: 4 } ), row( { item: 'Single Report Ring', itemTitle: 'Single Report Ring', reports: 1, totalReports: 4 } ) ];
    f.render( singletonRows );
    assert.equal( manual.querySelector( '.eql-drop-observed' ), null, 'One accepted report keeps the authored item visible without an early percentage.' );
    assert.equal( label.textContent, 'My manual label' );assert.equal( rarity.textContent, '(Common)' );
    const singleAppend = f.root.querySelector( '.eql-drop-observed-item' );
    assert.equal( singleAppend.querySelector( 'a' ).getAttribute( 'href' ), 'https://wiki.example/wiki/Single_Report_Ring' );
    assert.equal( singleAppend.querySelector( '.eql-drop-observed' ), null, 'One unmatched accepted report appends the item without an early percentage.' );
    assert.equal( singleAppend.parentNode, manual.parentNode );
    assert.equal( f.window.getComputedStyle( singleAppend ).color, 'rgb(174, 182, 193)', 'An unmatched singleton retains the muted observation styling.' );
    f.render( [ ...singletonRows, ...singletonRows ] );f.render( singletonRows );
    assert.equal( manual.querySelectorAll( '.eql-drop-observed' ).length, 0, 'Re-rendering and duplicate API rows never expose a singleton percentage.' );
    assert.equal( f.root.querySelectorAll( '.eql-drop-observed-item' ).length, 1, 'Re-rendering and duplicate API rows append a singleton only once.' );
    assert.equal( f.root.querySelectorAll( '.eql-drop-observed' ).length, 0 );
    assert.equal( f.root.querySelectorAll( '.eql-drop-info, .eql-drop-legend, .eql-drop-database-link, .eql-drop-observations' ).length, 0, 'A single source leaves the item names without disclosure/link or empty controls.' );
    f.render( [ row( { reports: 0 } ), row( { reports: -1 } ), row( { reports: 1.5 } ), row( { reports: '1' } ),
        row( { reports: 1, totalReports: 0 } ), row( { reports: 1, totalReports: 0.5 } ), row( { npcTitle: 'Other NPC' } ) ] );
    assert.equal( f.root.querySelectorAll( '.eql-drop-generated' ).length, 0, 'Zero, negative, fractional, malformed and other-NPC rows cannot annotate the article.' );
    f.close();
    const normal = fixture();await turn();await turn();normal.root.dataset.dropInstance = 'normal';
    normal.render( [ row(), row( { instance: 'normal', reports: 20 } ), row( { instance: 'raid', reports: 30 } ) ] );
    assert.match( normal.root.querySelector( '.eql-drop-source-select' ).selectedOptions[ 0 ].textContent, /Normal/ );
    assert.equal( normal.root.querySelector( '#manual .eql-drop-observed' ).textContent, ' (10%)', 'An explicitly scoped card prefers its matching source even if another scope has more reports.' );normal.close();
    const live = fixture( () => Promise.resolve( { eqldrops: { rows: [ row() ] } } ) );await turn();await turn();
    assert.equal( live.calls.length, 1 );assert.equal( live.calls[ 0 ].params.npc, 'A test creature' );assert.equal( live.calls[ 0 ].options.timeout, 10000 );
    live.hooks[ 0 ]( live.document );await turn();assert.equal( live.calls.length, 1, 'Dynamic hooks do not fan out duplicate requests.' );
    assert.equal( live.root.querySelectorAll( '#manual .eql-drop-observed' ).length, 1 );live.close();
    const offline = fixture( () => Promise.reject( new Error( 'timeout' ) ) );await turn();await turn();
    assert.equal( offline.document.querySelector( '#manual a' ).textContent, 'My manual label' );
    assert.equal( offline.window.getComputedStyle( offline.document.querySelector( '#manual .ddb' ) ).display, 'none' );
    assert.equal( offline.root.querySelectorAll( '.eql-drop-generated' ).length, 0 );offline.close();
    const syncFailure = fixture( () => { throw new Error( 'transport unavailable' ); } );await turn();await turn();
    assert.equal( syncFailure.root.querySelectorAll( '.eql-drop-generated' ).length, 0 );syncFailure.close();

    const card = content => '<div class="eql-mobpage"><div class="eql-mobpage-loot"><h2>Known Loot</h2>' + content + '</div></div>';
    for ( const annotation of [
        '<span class="dcommon">(72.3%)</span>', '(70%)', '<span class="drare">(~0–1.5%)</span>',
        '<span>Approximately <span>72</span><span>.3%</span> in this zone</span>',
        '<span class="ddb">[1] 4x55%(10%) <span>or ~2–3%</span></span>',
        '<div class="ddb">(72.3%)<div><div class="itembg">Inline haste 40%</div></div></div>'
    ] ) {
        const rated = fixture( () => Promise.reject( new Error( 'offline' ) ), { html: card( '<ul><li id="rated"><span class="hbdiv"><a href="/wiki/Test_Blade">Authored item label</a><span class="hb"><div class="itembg">Haste 90%</div></span></span> ' + annotation + ' <span class="drare">(Common)</span></li></ul>' ) } );
        await turn();await turn();
        const li = rated.root.querySelector( '#rated' ), sourceNodes = [ ...li.childNodes ], original = li.textContent;
        const rateBlock = li.querySelector( '.ddb' );
        if ( rateBlock ) { assert.notEqual( rated.window.getComputedStyle( rateBlock ).display, 'none', 'An explicit legacy .ddb rate stays visible even while the API is unavailable.' ); }
        const inlineCard = li.querySelector( '.ddb .itembg' );
        if ( inlineCard ) { assert.equal( rated.window.getComputedStyle( inlineCard ).display, 'none', 'Restoring an authored rate block never reveals its nested inline item stat cards.' ); }
        for ( const reports of [ 1, 9, 10, 40 ] ) {
            rated.render( [ row( { reports } ) ] );rated.render( [ row( { reports } ) ] );
            assert.equal( li.querySelector( '.eql-drop-observed' ), null, 'Any authored rate wins over the generated percentage, above and below its sample floor.' );
            assert.equal( rated.root.querySelector( '.eql-drop-observed-item' ), null, 'A manually rated row does not gain a duplicate automatic item.' );
            assert.equal( li.textContent, original, 'Literal ranges, approximations and multiple legacy rates are never reinterpreted or rewritten.' );
            assert.deepEqual( [ ...li.childNodes ], sourceNodes, 'The authored annotation and native hover DOM keep their identity.' );
            assert.notEqual( rated.window.getComputedStyle( li.querySelector( '.drare' ) ).display, 'none', 'Real rarity notes remain visible.' );
            assert.equal( rated.window.getComputedStyle( li.querySelector( '.hb' ) ).display, 'none', 'Manual-rate priority never expands the native hover card inline.' );
        }
        rated.close();
    }
    const literalRates = fixture( null, { html: card( '<p>Test Blade (70%) (Common)<br>Unknown Rate Relic <span class="ddb">(~<span>0</span>%)</span></p>' ) } );
    await turn();await turn();
    const literalBefore = literalRates.root.textContent;
    literalRates.render( [ row(), row( { item: 'Unknown Rate Relic', itemTitle: 'Unknown Rate Relic' } ) ] );
    literalRates.render( [ row(), row( { item: 'Unknown Rate Relic', itemTitle: 'Unknown Rate Relic' } ) ] );
    assert.deepEqual( [ ...literalRates.root.querySelectorAll( 'li > a' ) ].map( anchor => anchor.textContent ), [ 'Test Blade', 'Unknown Rate Relic' ], 'Simple whole item names accept trailing literal percentage/rarity annotations without special markup.' );
    assert.equal( literalRates.root.textContent, literalBefore );assert.equal( literalRates.root.querySelector( '.eql-drop-generated' ), null );
    assert.notEqual( literalRates.window.getComputedStyle( literalRates.root.querySelector( '.ddb' ) ).display, 'none' );literalRates.close();

    const notRates = fixture( null, { html: card( '<ul><li id="not-rated"><span class="hbdiv"><a href="/wiki/Test_Blade">100% Authored Blade</a><span class="hb"><span class="ddb">Tooltip drop 90%</span><div class="itembg"><div class="itemdata">Haste 60%</div></div></span></span> <span class="drare">(Common)</span><span class="ddb">[4] 20x</span><div class="itembg">Haste 40%</div><span class="ils-stat">Accuracy 30%</span><div class="ils-slider-container">Rank 20%</div><ul><li><a href="/wiki/Nested_Relic">Nested relic</a> (50%)</li></ul></li><li id="nested-owner"><ul><li><a href="/wiki/Other_Nested_Relic">Other nested relic</a> (25%)</li></ul></li></ul>' ) } );
    await turn();await turn();notRates.render( [ row(), row( { item: 'Other Nested Relic', itemTitle: 'Other Nested Relic' } ) ] );
    const notRated = notRates.root.querySelector( '#not-rated' );
    assert.equal( notRated.querySelector( '.hbdiv > .eql-drop-observed' ).textContent, ' (20%)', 'Item-label, tooltip, weapon/stat, slider and nested-list percentages never count as this item’s authored rate.' );
    assert.equal( notRates.root.querySelector( '#nested-owner' ).classList.contains( 'eql-drop-clean-item' ), false, 'A parent list row never steals its nested item’s identity.' );
    assert.equal( notRates.window.getComputedStyle( notRated.querySelector( ':scope > .ddb' ) ).display, 'none', 'Count-only blocks retain their existing cleanup.' );
    assert.equal( notRates.window.getComputedStyle( notRated.querySelector( '.hb .ddb' ) ).display, 'none', 'A tooltip rate is never flagged as an authored inline annotation.' );
    const changeable = notRates.document.createElement( 'span' );changeable.className = 'ddb';changeable.textContent = '(72.3%)';notRated.appendChild( changeable );
    notRates.render( [ row() ] );assert.equal( notRated.querySelector( '.hbdiv > .eql-drop-observed' ), null );
    assert.notEqual( notRates.window.getComputedStyle( changeable ).display, 'none' );
    changeable.textContent = '[5] 10x';notRates.render( [ row() ] );notRates.render( [ row() ] );
    assert.equal( changeable.classList.contains( 'eql-drop-manual-content' ), false, 'Removing an authored rate clears its visibility exemption on the next render.' );
    assert.equal( notRates.window.getComputedStyle( changeable ).display, 'none' );
    assert.equal( notRated.querySelectorAll( '.hbdiv > .eql-drop-observed' ).length, 1, 'Removing a manual rate restores exactly one eligible computed badge.' );
    notRates.close();

    for ( const wrapperClass of [ 'ddb', 'drare' ] ) {
        const wrapped = fixture( null, { html: card( '<ul><li id="wrapped"><span class="' + wrapperClass + '"><a href="/wiki/Test_Blade">Authored wrapper label</a> (72.3%)</span> (Common)</li></ul>' ) } );
        await turn();await turn();const wrappedItem = wrapped.root.querySelector( '#wrapped' ), wrappedSource = wrappedItem.textContent;
        wrapped.render( [ row() ] );wrapped.render( [ row() ] );
        assert.equal( wrappedItem.querySelector( '.eql-drop-observed' ), null, 'A leading item anchor remains authoritative inside any authored rate wrapper.' );
        assert.equal( wrapped.root.querySelector( '.eql-drop-observed-item' ), null, 'A .ddb/.drare wrapper never causes a duplicate automatic row.' );
        assert.equal( wrappedItem.textContent, wrappedSource );
        assert.notEqual( wrapped.window.getComputedStyle( wrappedItem.firstElementChild ).display, 'none', 'The complete authored wrapper stays visible.' );wrapped.close();
    }
    const labelOnly = fixture( null, { html: card( '<ul><li id="label-only"><span class="ddb"><a href="/wiki/Test_Blade">100% Pure Authored Blade</a></span> (Common)</li></ul>' ) } );
    await turn();await turn();labelOnly.render( [ row() ] );labelOnly.render( [ row() ] );
    assert.notEqual( labelOnly.window.getComputedStyle( labelOnly.root.querySelector( '#label-only > .ddb' ) ).display, 'none', 'A label-containing .ddb stays visible even with no authored rate.' );
    assert.equal( labelOnly.root.querySelector( '#label-only .eql-drop-observed' ).textContent, ' (20%)', 'A percentage in the item label is never interpreted as a manual rate.' );
    assert.equal( labelOnly.root.querySelector( '.eql-drop-observed-item' ), null );labelOnly.close();

    const nestedNote = fixture( null, { html: card( '<ul><li id="note-owner"><a href="/wiki/Test_Blade">Test Blade</a><ul><li><span class="ddb">(~72.3%)<div class="itembg">Haste 40%</div></span></li></ul></li></ul>' ) } );
    await turn();await turn();const nestedSource = nestedNote.root.textContent;
    nestedNote.render( [ row() ] );nestedNote.render( [ row() ] );
    assert.equal( nestedNote.root.querySelector( '.eql-drop-observed' ), null, 'A rate-only nested note belongs to its owning item.' );
    assert.notEqual( nestedNote.window.getComputedStyle( nestedNote.root.querySelector( '.ddb' ) ).display, 'none' );
    assert.equal( nestedNote.window.getComputedStyle( nestedNote.root.querySelector( '.ddb .itembg' ) ).display, 'none' );
    assert.equal( nestedNote.root.textContent, nestedSource );nestedNote.close();
    for ( const nestedItem of [ '<a href="/wiki/Other_Relic">Other relic</a> (50%)', 'Other Relic(50%)' ] ) {
        const separate = fixture( null, { html: card( '<ul><li id="outer-item"><a href="/wiki/Test_Blade">Test Blade</a><ul><li id="separate-item">' + nestedItem + '</li></ul></li></ul>' ) } );
        await turn();await turn();separate.render( [ row(), row( { item: 'Other Relic', itemTitle: 'Other Relic' } ) ] );separate.render( [ row(), row( { item: 'Other Relic', itemTitle: 'Other Relic' } ) ] );
        assert.equal( separate.root.querySelector( '#outer-item > .eql-drop-observed' ).textContent, ' (20%)', 'A linked or conservative plain nested item owns its rate; it cannot suppress the outer item’s percentage.' );
        assert.equal( separate.root.querySelector( '#separate-item .eql-drop-observed' ), null );
        assert.equal( separate.root.querySelector( '.eql-drop-observed-item' ), null );separate.close();
    }
    const adjacent = fixture( null, { html: card( '<ul><li>Test Blade<span class="dcommon">(70%)</span></li><li>Adjacent Relic(0%)</li></ul>' ) } );
    await turn();await turn();adjacent.render( [ row(), row( { item: 'Adjacent Relic', itemTitle: 'Adjacent Relic' } ) ] );
    assert.deepEqual( [ ...adjacent.root.querySelectorAll( 'li > a' ) ].map( anchor => anchor.textContent ), [ 'Test Blade', 'Adjacent Relic' ] );
    assert.equal( adjacent.root.querySelector( '.eql-drop-generated' ), null, 'A recognized percentage suffix needs no whitespace and is preserved as the authored rate.' );adjacent.close();

    const authored = fixture( () => Promise.reject( new Error( 'offline' ) ), { html: card( '<ul id="authored-list">' +
        '<li id="plain">Test Blade (Common)</li><li id="unknown">Unrecorded Manual Relic (Rare)</li>' +
        '<li id="alias"><a href="/wiki/Canonical_Relic">My chosen alias</a> (Unique)</li>' +
        '<li id="manual-mote">Mote of Fire</li><li id="qualified">Test Blade (quest only)</li>' +
        '<li id="url-name">https://unsafe.example/Test Blade</li><li id="unsafe-link"><a href="javascript:bad()">Unsafe Relic</a></li>' +
        '<li id="external-link"><a href="https://unsafe.example/Test_Blade">Test Blade</a></li></ul>' +
        '<ul class="eql-recorded-loot"><li id="reciprocal-duplicate" class="eql-recorded-loot-item"><span class="ih"><a href="/wiki/Test_Blade">Generated source label</a></span><span class="hb">Native hover data</span></li>' +
        '<li id="reciprocal-mote" class="eql-recorded-loot-item"><a href="/wiki/Mote_of_Frost">Winter crystal alias</a></li>' +
        '<li id="reciprocal-mote-alias" class="eql-recorded-loot-item"><a href="/wiki/Winter_crystal_alias">Winter crystal alias</a></li>' +
        '<li id="reciprocal-raw-mote" class="eql-recorded-loot-item"><a href="/wiki/Canonical_Hidden_Relic">Canonical hidden relic</a></li>' +
        '<li id="reciprocal-manual-mote" class="eql-recorded-loot-item"><a href="/wiki/Mote_of_Fire">Generated fire mote</a></li>' +
        '<li id="reciprocal-only" class="eql-recorded-loot-item"><a href="/wiki/Unobserved_Reciprocal_Relic">Unobserved reciprocal relic</a></li></ul>' ) } );
    await turn();await turn();
    const plain = authored.root.querySelector( '#plain' ), unknownManual = authored.root.querySelector( '#unknown' );
    assert.equal( plain.querySelector( 'a' ).getAttribute( 'href' ), 'https://wiki.example/wiki/Test_Blade', 'An ordinary literal item name links before the observation API succeeds.' );
    assert.equal( plain.textContent, 'Test Blade (Common)' );
    assert.equal( unknownManual.querySelector( 'a' ).textContent, 'Unrecorded Manual Relic', 'Unknown authored items remain usable literal wiki targets without an observation.' );
    assert.equal( unknownManual.textContent, 'Unrecorded Manual Relic (Rare)' );
    assert.equal( authored.window.getComputedStyle( authored.root.querySelector( '#reciprocal-duplicate' ) ).display, 'none', 'A plain authored name supersedes a generated reciprocal duplicate even while offline.' );
    assert.equal( authored.root.querySelector( '#reciprocal-duplicate .hb' ).textContent, 'Native hover data', 'Suppression retains reciprocal source/hover nodes.' );
    assert.equal( authored.window.getComputedStyle( authored.root.querySelector( '#reciprocal-mote' ) ).display, 'none', 'A generated Mote is filtered by link destination, independently of its displayed alias.' );
    assert.equal( authored.window.getComputedStyle( authored.root.querySelector( '#reciprocal-manual-mote' ) ).display, 'none' );
    assert.notEqual( authored.window.getComputedStyle( authored.root.querySelector( '#manual-mote' ) ).display, 'none', 'An explicitly authored Mote remains authoritative.' );
    assert.notEqual( authored.window.getComputedStyle( authored.root.querySelector( '#reciprocal-only' ) ).display, 'none' );
    assert.equal( authored.root.querySelector( '#qualified a' ), null, 'Arbitrary parenthetical qualifiers do not become a partial plain-name match.' );
    assert.equal( authored.root.querySelector( '#url-name a' ), null, 'A URL is never treated as a plain item name.' );
    const evidence = [ row(), row( { item: 'New Observed Relic', itemTitle: 'New Observed Relic', reports: 1 } ),
        row( { item: 'Mote of Frost', itemTitle: 'Mote of Frost', reports: 13 } ),
        row( { item: 'Mote of Fire', itemTitle: 'Mote of Fire', reports: 20 } ),
        row( { item: 'Winter crystal alias', itemTitle: 'Mote_of_Winter', reports: 1 } ),
        row( { item: 'Mote of Tides', itemTitle: 'Canonical Hidden Relic', reports: 1 } ),
        row( { item: 'Canonical Relic', itemTitle: 'Canonical Relic', reports: 10 } ),
        row( { item: 'Unsafe Relic', itemTitle: 'Unsafe Relic', reports: 1 } ) ];
    const beforeRows = JSON.stringify( evidence );authored.render( evidence );
    assert.equal( plain.querySelector( '.eql-drop-observed' ).textContent, ' (20%)', 'Hiding Motes never renormalizes a non-Mote report percentage.' );
    assert.match( plain.querySelector( '.eql-drop-observed' ).title, /out of 200 recorded loot events/ );
    assert.equal( authored.root.querySelector( '#manual-mote .eql-drop-observed' ).textContent, ' (10%)', 'An explicitly authored Mote can retain its observed annotation.' );
    assert.equal( authored.root.querySelector( '#alias a' ).textContent, 'My chosen alias' );
    assert.equal( authored.root.querySelector( '#alias' ).textContent, 'My chosen alias (5%) (Unique)', 'Ordinary wiki-link destinations take precedence over their labels and preserve rarity notes.' );
    assert.equal( authored.root.querySelector( '#qualified .eql-drop-observed' ), null );
    assert.equal( authored.root.querySelector( '#external-link .eql-drop-observed' ), null );
    assert.equal( authored.root.querySelector( '#unsafe-link .eql-drop-observed' ), null );
    for ( const id of [ 'reciprocal-mote-alias', 'reciprocal-raw-mote' ] ) {
        assert.equal( authored.window.getComputedStyle( authored.root.querySelector( '#' + id ) ).display, 'none', 'A reciprocal row is still generated when API raw/canonical evidence identifies its alias as a Mote.' );
        assert.equal( authored.root.querySelector( '#' + id + ' .eql-drop-observed' ), null );
    }
    assert.deepEqual( [ ...authored.root.querySelectorAll( '.eql-drop-observed-item' ) ].map( item => item.querySelector( 'a' ).textContent ), [ 'New Observed Relic', 'Unsafe Relic' ], 'Only safe unmatched non-Mote observations are appended.' );
    assert.equal( JSON.stringify( evidence ), beforeRows, 'Display filtering never edits API evidence or denominators.' );
    authored.render( evidence );authored.render( evidence );
    assert.equal( plain.querySelectorAll( 'a' ).length, 1 );assert.equal( plain.querySelectorAll( '.eql-drop-observed' ).length, 1 );
    assert.equal( authored.root.querySelectorAll( '.eql-drop-observed-item' ).length, 2 );
    const addedManual = authored.document.createElement( 'li' );addedManual.textContent = 'New Observed Relic (Uncommon)';authored.root.querySelector( '#authored-list' ).appendChild( addedManual );
    authored.render( evidence );
    assert.equal( addedManual.textContent, 'New Observed Relic (Uncommon)', 'A low-sample authored override keeps its rarity without a percentage.' );
    assert.equal( authored.root.querySelectorAll( '.eql-drop-observed-item' ).length, 1, 'Adding a normal authored name replaces its automatic entry on re-render.' );
    plain.remove();authored.render( evidence );
    assert.notEqual( authored.window.getComputedStyle( authored.root.querySelector( '#reciprocal-duplicate' ) ).display, 'none', 'Removing a manual override reveals the retained reciprocal fallback.' );
    assert.equal( authored.root.querySelectorAll( '#reciprocal-duplicate .eql-drop-observed' ).length, 1 );
    authored.close();

    const paragraphs = fixture( null, { html: card( '<p>Plain Ring (Rare)\n<a href="/wiki/Linked_Relic">Authored link label</a> (Common)<br>Unknown Manual Gem</p>' ) } );
    await turn();await turn();
    assert.equal( paragraphs.root.querySelector( 'p' ), null );
    assert.equal( paragraphs.root.querySelectorAll( '.eql-drop-manual-list > li' ).length, 3, 'Bare one-per-line names and ordinary links use the native loot-list layout.' );
    assert.deepEqual( [ ...paragraphs.root.querySelectorAll( 'li > a' ) ].map( anchor => anchor.textContent ), [ 'Plain Ring', 'Authored link label', 'Unknown Manual Gem' ] );
    paragraphs.render( [ row( { item: 'Plain Ring', itemTitle: 'Plain Ring', reports: 10 } ), row( { item: 'Linked Relic', itemTitle: 'Linked Relic', reports: 10 } ) ] );
    paragraphs.render( [ row( { item: 'Plain Ring', itemTitle: 'Plain Ring', reports: 10 } ), row( { item: 'Linked Relic', itemTitle: 'Linked Relic', reports: 10 } ) ] );
    assert.equal( paragraphs.root.querySelectorAll( '.eql-drop-manual-list' ).length, 1 );
    assert.equal( paragraphs.root.querySelectorAll( '.eql-drop-observed-item' ).length, 0 );
    assert.equal( paragraphs.root.querySelectorAll( '.eql-drop-observed' ).length, 2 );paragraphs.close();

    const prose = fixture( null, { html: card( '<p id="loot-prose">Always drops two pieces of armor</p><ul><li id="loot-qualifier">Drops from low-level humanoids</li><li id="literal-drop">Drop of Rain (Rare)</li></ul>' ) } );await turn();await turn();
    assert.equal( prose.root.querySelector( '#loot-prose' ).tagName, 'P', 'Known quantity/loot prose is not converted into a literal-item list.' );
    assert.equal( prose.root.querySelector( '#loot-prose' ).textContent, 'Always drops two pieces of armor' );
    assert.equal( prose.root.querySelector( '#loot-prose a' ), null );assert.equal( prose.root.querySelector( '#loot-qualifier a' ), null );
    assert.equal( prose.root.querySelector( '#literal-drop a' ).textContent, 'Drop of Rain', 'A literal item containing Drop is not confused with a loot instruction.' );prose.close();

    const aliases = fixture( null, { html: card( '<ul><li id="literal-alias">Reported Relic (Rare)</li></ul>' ) } );await turn();await turn();
    aliases.render( [ row( { item: 'Reported Relic', itemTitle: 'Canonical Relic', reports: 10 } ) ] );
    assert.equal( aliases.root.querySelectorAll( '#literal-alias .eql-drop-observed' ).length, 1, 'A whole reported name can match one unambiguous canonical item.' );
    aliases.render( [ row( { item: 'Reported Relic', itemTitle: 'Canonical Relic', reports: 10 } ), row( { item: 'Reported Relic', itemTitle: 'Other Canonical Relic', reports: 10 } ) ] );
    assert.equal( aliases.root.querySelector( '#literal-alias .eql-drop-observed' ), null, 'Ambiguous raw aliases never merge distinct canonical items.' );aliases.close();

    const unsafeUrl = fixture( null, { html: card( '<ul><li id="safe-literal">Test Blade (Rare)</li></ul>' ), getUrl: () => 'https://unsafe.example/item' } );await turn();await turn();
    unsafeUrl.render( [ row() ] );unsafeUrl.render( [ row() ] );
    assert.equal( unsafeUrl.root.querySelectorAll( 'a' ).length, 0, 'A malformed or off-origin URL builder never creates a link.' );
    assert.equal( unsafeUrl.root.querySelector( '#safe-literal' ).textContent, 'Test Blade (20%) (Rare)', 'An unsafe URL leaves an exact authored literal readable and prevents a duplicate automatic row.' );
    assert.equal( unsafeUrl.root.querySelectorAll( '.eql-drop-observed-item' ).length, 0 );unsafeUrl.close();

    const boundary = fixture();await turn();await turn();
    const boundaryManual = boundary.root.querySelector( '#manual' );
    for ( const reports of [ 1, 9, 10, 9, 1 ] ) {
        const samples = [ row( { reports, totalReports: 100, reportShare: reports, quantity: 1000 } ),
            row( { item: 'Boundary Ring', itemTitle: 'Boundary Ring', reports, totalReports: 100, reportShare: reports, quantity: 1000 } ) ];
        const unchanged = JSON.stringify( samples );boundary.render( samples );boundary.render( samples );
        const generated = boundary.root.querySelector( '.eql-drop-observed-item' );
        assert.equal( boundaryManual.querySelector( 'a' ).textContent, 'My manual label' );
        assert.equal( boundaryManual.querySelector( '.drare' ).textContent, '(Common)' );
        assert.equal( boundary.root.querySelectorAll( '.eql-drop-observed-item' ).length, 1, 'Every positive sample count keeps one unmatched item visible after re-render.' );
        assert.equal( generated.querySelector( 'a' ).textContent, 'Boundary Ring' );
        assert.equal( boundary.window.getComputedStyle( generated ).color, 'rgb(174, 182, 193)' );
        assert.equal( boundary.root.querySelectorAll( '.eql-drop-observed' ).length, reports >= 10 ? 2 : 0, 'Both authored and generated item percentages require their own ten reports, including after a lower-count refresh.' );
        if ( reports >= 10 ) {
            assert.equal( boundaryManual.querySelector( '.eql-drop-observed' ).textContent, ' (10%)' );
            assert.equal( generated.querySelector( '.eql-drop-observed' ).textContent, ' (10%)' );
        }
        assert.equal( JSON.stringify( samples ), unchanged, 'The NPC presentation floor never mutates numeric API shares or counts.' );
        assert.equal( boundary.root.querySelector( '#no-report a' ).textContent, 'Other item', 'Unobserved authored items stay visible.' );
    }
    const denominatorRows = [ row( { reports: 10, totalReports: 20, reportShare: 50 } ),
        row( { item: 'Nine Report Ring', itemTitle: 'Nine Report Ring', reports: 9, totalReports: 20, reportShare: 45 } ),
        row( { item: 'One Report Gem', itemTitle: 'One Report Gem', reports: 1, totalReports: 20, reportShare: 5 } ) ];
    boundary.render( denominatorRows );
    assert.equal( boundaryManual.querySelector( '.eql-drop-observed' ).textContent, ' (50%)', 'The qualifying item keeps all low-sample items in its denominator: ten out of twenty, not ten out of ten.' );
    assert.equal( boundary.root.querySelectorAll( '.eql-drop-observed-item' ).length, 2 );
    assert.equal( boundary.root.querySelectorAll( '.eql-drop-observed-item .eql-drop-observed' ).length, 0 );
    boundary.render( [ row( { reports: 9, totalReports: 9, reportShare: 100 } ),
        row( { zone: 'Other Valley', zoneTitle: 'Other Valley', reports: 9, totalReports: 9, reportShare: 100 } ) ] );
    const sourceSelector = boundary.root.querySelector( '.eql-drop-source-select' );
    assert.equal( sourceSelector.options.length, 2 );assert.equal( boundaryManual.querySelector( '.eql-drop-observed' ), null );
    sourceSelector.value = [ ...sourceSelector.options ].find( option => /Other Valley/.test( option.textContent ) ).value;
    sourceSelector.dispatchEvent( new boundary.window.Event( 'change' ) );
    assert.equal( boundaryManual.querySelector( '.eql-drop-observed' ), null, 'Counts from different zone/instance contexts never combine to unlock a percentage.' );
    boundary.render( [ row( { reports: 9, totalReports: 20, reportShare: 45 } ),
        row( { item: 'Popular Valley Relic', itemTitle: 'Popular Valley Relic', reports: 11, totalReports: 20, reportShare: 55 } ),
        row( { zone: 'Other Valley', zoneTitle: 'Other Valley', reports: 10, totalReports: 20, reportShare: 50 } ),
        row( { zone: 'Other Valley', zoneTitle: 'Other Valley', item: 'Nine Report Ring', itemTitle: 'Nine Report Ring', reports: 9, totalReports: 20, reportShare: 45 } ),
        row( { zone: 'Other Valley', zoneTitle: 'Other Valley', item: 'One Report Gem', itemTitle: 'One Report Gem', reports: 1, totalReports: 20, reportShare: 5 } ) ] );
    let switcher = boundary.root.querySelector( '.eql-drop-source-select' );
    switcher.value = [ ...switcher.options ].find( option => /Mist Valley/.test( option.textContent ) ).value;switcher.dispatchEvent( new boundary.window.Event( 'change' ) );
    assert.equal( boundaryManual.querySelector( '.eql-drop-observed' ), null, 'The selected nine-report source hides this item’s percentage even when another source qualifies.' );
    switcher = boundary.root.querySelector( '.eql-drop-source-select' );
    switcher.value = [ ...switcher.options ].find( option => /Other Valley/.test( option.textContent ) ).value;switcher.dispatchEvent( new boundary.window.Event( 'change' ) );
    assert.equal( boundaryManual.querySelector( '.eql-drop-observed' ).textContent, ' (50%)', 'Switching to this item’s ten-report source uses its full denominator including the nine/one-report items.' );
    assert.equal( boundary.root.querySelectorAll( '.eql-drop-observed-item' ).length, 2 );
    assert.equal( boundary.root.querySelectorAll( '.eql-drop-observed-item .eql-drop-observed' ).length, 0 );
    boundary.render( [ row( { reports: 1, totalReports: 100, reportShare: 1 } ), row( { item: 'Popular Relic', itemTitle: 'Popular Relic', reports: 99, totalReports: 100, reportShare: 99 } ) ] );
    assert.equal( boundaryManual.querySelector( '.eql-drop-observed' ), null, 'A large source total or another item reaching the floor cannot unlock a one-report item.' );
    assert.equal( boundary.root.querySelector( '.eql-drop-observed-item .eql-drop-observed' ).textContent, ' (99%)' );
    boundary.render( [ row( { reports: '10' } ), row( { reports: 9.5 } ), row( { reports: NaN } ), row( { reports: Infinity } ),
        row( { reports: 10, totalReports: 9 } ), row( { reports: 10, totalReports: '100' } ), row( { reports: 10, totalReports: NaN } ), row( { reports: 10, totalReports: Infinity } ) ] );
    assert.equal( boundary.root.querySelectorAll( '.eql-drop-generated' ).length, 0, 'Malformed counts cannot bypass the floor or produce invalid percentages.' );boundary.close();

    const oldReply = { minimumReports: 1, rows: [ row( { reports: 1, totalReports: 1, reportShare: 100 } ),
        row( { item: 'Old Cached Relic', itemTitle: 'Old Cached Relic', reports: 9, totalReports: 9, reportShare: 100 } ) ] };
    const cached = fixture( () => Promise.resolve( { eqldrops: oldReply } ) );await turn();await turn();
    assert.equal( cached.root.querySelectorAll( '.eql-drop-observed' ).length, 0, 'Old cached API responses with numeric percentages and no new metadata still obey the fixed local floor.' );
    assert.equal( cached.root.querySelector( '#manual a' ).textContent, 'My manual label' );
    assert.equal( cached.root.querySelector( '.eql-drop-observed-item a' ).textContent, 'Old Cached Relic' );
    assert.equal( oldReply.rows[ 0 ].reportShare, 100 );assert.equal( oldReply.rows[ 1 ].reportShare, 100, 'The full database/API percentage remains intact.' );cached.close();
    console.log( 'Drop overlay passed: first-report names, NPC-only ten-report percentage floor, exact authored-rate priority, tooltip/stat isolation, full denominators, source/manual/Mote preservation and idempotent safe rendering.' );
} )().catch( error => { console.error( error );process.exitCode = 1; } );
