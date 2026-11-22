'use strict';
// Synthetic DOM/API responses only. No wiki, account, log or database reads/writes.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const source = fs.readFileSync( path.join( __dirname, '../extensions/EQLClientData/resources/drop-database.js' ), 'utf8' );
const css = fs.readFileSync( path.join( __dirname, '../extensions/EQLClientData/resources/drop-database.css' ), 'utf8' );
const row = ( extra = {} ) => ( { zone: 'Mist Valley', zoneTitle: 'Mist Valley', npc: 'A test creature', npcTitle: 'A test creature', item: 'Test Blade', itemTitle: 'Test Blade', instance: 'unknown', reports: 4, totalReports: 20, reportShare: 20, ...extra } );
const turn = () => new Promise( resolve => setImmediate( resolve ) );
function fixture( get, options = {} ) {
    const dom = new JSDOM( '<head><style>' + css + '</style></head><body><div class="eql-mobpage"><div class="eql-mobpage-hero-loot"><h2>Known Loot</h2><ul><li id="manual"><span class="hbdiv"><a href="/wiki/Test_Blade" title="Displayed alias">My manual label</a><span class="hb"><div class="itemtopbg">Tooltip title</div><div class="itembg"><div class="itemdata">WT:0.5 Class:ALL<div class="ils-slider-container">Rank slider</div></div></div></span></span> <span class="drare">(Common)</span> <span class="ddb">[1] 4x55%(10%)</span></li><li id="no-report"><a href="/wiki/Other_Item">Other item</a><span class="ddb">[9] 40%</span><div class="itembg">Unwanted inline stats</div></li><li id="case"><a href="/wiki/Test_blade">Case-distinct item</a></li><li id="query"><a href="/index.php?title=Query_Item&amp;action=edit&amp;redlink=1">A redlink label</a> (Rare)</li></ul></div><div class="eql-mobpage-common-loot"><ul><li id="common"><a href="/wiki/Common_Item">Common item</a> (notes)</li></ul></div></div></body>', { url: 'https://wiki.example/wiki/A_test_creature', runScripts: 'outside-only' } );
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
    const rows = [ row(), row( { item: 'New & unseen <item>', itemTitle: null, reports: 2 } ), row( { instance: 'raid', reports: 2, totalReports: 5 } ), row( { zone: 'Other Valley', zoneTitle: 'Other Valley', reports: 3 } ), row( { item: 'Query Item', itemTitle: 'Query Item' } ), row( { item: 'Common Item', itemTitle: 'Common Item' } ) ];
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
    assert.match( manual.querySelector( '.eql-drop-observed' ).title, /reported 4 times out of 20 recorded loot events/ );
    assert.doesNotMatch( manual.querySelector( '.eql-drop-observed' ).title, /tier|server|contributor|5 items/i );
    assert.equal( f.window.getComputedStyle( authoredRates ).display, 'none', 'Authored source/count/percentage clutter stays out of the visible loot list.' );
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
    assert.equal( manual.querySelector( '.eql-drop-observed' ).textContent, ' (25%)', 'One accepted report annotates the exact authored item destination using its full source denominator.' );
    assert.equal( label.textContent, 'My manual label' );assert.equal( rarity.textContent, '(Common)' );
    const singleAppend = f.root.querySelector( '.eql-drop-observed-item' );
    assert.equal( singleAppend.querySelector( 'a' ).getAttribute( 'href' ), 'https://wiki.example/wiki/Single_Report_Ring' );
    assert.equal( singleAppend.querySelector( '.eql-drop-observed' ).textContent, ' (25%)', 'One unmatched accepted report appends the item with its compact percentage.' );
    assert.equal( singleAppend.parentNode, manual.parentNode );
    assert.equal( f.window.getComputedStyle( singleAppend ).color, 'rgb(174, 182, 193)', 'An unmatched singleton retains the muted observation styling.' );
    f.render( [ ...singletonRows, ...singletonRows ] );f.render( singletonRows );
    assert.equal( manual.querySelectorAll( '.eql-drop-observed' ).length, 1, 'Re-rendering and duplicate API rows never duplicate a singleton badge.' );
    assert.equal( f.root.querySelectorAll( '.eql-drop-observed-item' ).length, 1, 'Re-rendering and duplicate API rows append a singleton only once.' );
    assert.equal( f.root.querySelectorAll( '.eql-drop-observed' ).length, 2 );
    assert.equal( f.root.querySelectorAll( '.eql-drop-info, .eql-drop-legend, .eql-drop-database-link, .eql-drop-observations' ).length, 0, 'A single source leaves only the beside-item percentages, with no disclosure/link or empty controls.' );
    f.render( [ row( { reports: 0 } ), row( { reports: -1 } ), row( { reports: 1.5 } ), row( { reports: '1' } ),
        row( { reports: 1, totalReports: 0 } ), row( { reports: 1, totalReports: 0.5 } ), row( { npcTitle: 'Other NPC' } ) ] );
    assert.equal( f.root.querySelectorAll( '.eql-drop-generated' ).length, 0, 'Zero, negative, fractional, malformed and other-NPC rows cannot annotate the article.' );
    f.close();
    const normal = fixture();await turn();await turn();normal.root.dataset.dropInstance = 'normal';
    normal.render( [ row(), row( { instance: 'normal', reports: 2 } ), row( { instance: 'raid', reports: 3 } ) ] );
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
        row( { item: 'Mote of Fire', itemTitle: 'Mote of Fire', reports: 2 } ),
        row( { item: 'Winter crystal alias', itemTitle: 'Mote_of_Winter', reports: 1 } ),
        row( { item: 'Mote of Tides', itemTitle: 'Canonical Hidden Relic', reports: 1 } ),
        row( { item: 'Canonical Relic', itemTitle: 'Canonical Relic', reports: 1 } ),
        row( { item: 'Unsafe Relic', itemTitle: 'Unsafe Relic', reports: 1 } ) ];
    const beforeRows = JSON.stringify( evidence );authored.render( evidence );
    assert.equal( plain.querySelector( '.eql-drop-observed' ).textContent, ' (20%)', 'Hiding Motes never renormalizes a non-Mote report percentage.' );
    assert.match( plain.querySelector( '.eql-drop-observed' ).title, /out of 20 recorded loot events/ );
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
    assert.equal( addedManual.textContent, 'New Observed Relic (5%) (Uncommon)' );
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
    paragraphs.render( [ row( { item: 'Plain Ring', itemTitle: 'Plain Ring', reports: 1 } ), row( { item: 'Linked Relic', itemTitle: 'Linked Relic', reports: 1 } ) ] );
    paragraphs.render( [ row( { item: 'Plain Ring', itemTitle: 'Plain Ring', reports: 1 } ), row( { item: 'Linked Relic', itemTitle: 'Linked Relic', reports: 1 } ) ] );
    assert.equal( paragraphs.root.querySelectorAll( '.eql-drop-manual-list' ).length, 1 );
    assert.equal( paragraphs.root.querySelectorAll( '.eql-drop-observed-item' ).length, 0 );
    assert.equal( paragraphs.root.querySelectorAll( '.eql-drop-observed' ).length, 2 );paragraphs.close();

    const prose = fixture( null, { html: card( '<p id="loot-prose">Always drops two pieces of armor</p><ul><li id="loot-qualifier">Drops from low-level humanoids</li><li id="literal-drop">Drop of Rain (Rare)</li></ul>' ) } );await turn();await turn();
    assert.equal( prose.root.querySelector( '#loot-prose' ).tagName, 'P', 'Known quantity/loot prose is not converted into a literal-item list.' );
    assert.equal( prose.root.querySelector( '#loot-prose' ).textContent, 'Always drops two pieces of armor' );
    assert.equal( prose.root.querySelector( '#loot-prose a' ), null );assert.equal( prose.root.querySelector( '#loot-qualifier a' ), null );
    assert.equal( prose.root.querySelector( '#literal-drop a' ).textContent, 'Drop of Rain', 'A literal item containing Drop is not confused with a loot instruction.' );prose.close();

    const aliases = fixture( null, { html: card( '<ul><li id="literal-alias">Reported Relic (Rare)</li></ul>' ) } );await turn();await turn();
    aliases.render( [ row( { item: 'Reported Relic', itemTitle: 'Canonical Relic', reports: 1 } ) ] );
    assert.equal( aliases.root.querySelectorAll( '#literal-alias .eql-drop-observed' ).length, 1, 'A whole reported name can match one unambiguous canonical item.' );
    aliases.render( [ row( { item: 'Reported Relic', itemTitle: 'Canonical Relic', reports: 1 } ), row( { item: 'Reported Relic', itemTitle: 'Other Canonical Relic', reports: 1 } ) ] );
    assert.equal( aliases.root.querySelector( '#literal-alias .eql-drop-observed' ), null, 'Ambiguous raw aliases never merge distinct canonical items.' );aliases.close();

    const unsafeUrl = fixture( null, { html: card( '<ul><li id="safe-literal">Test Blade (Rare)</li></ul>' ), getUrl: () => 'https://unsafe.example/item' } );await turn();await turn();
    unsafeUrl.render( [ row() ] );unsafeUrl.render( [ row() ] );
    assert.equal( unsafeUrl.root.querySelectorAll( 'a' ).length, 0, 'A malformed or off-origin URL builder never creates a link.' );
    assert.equal( unsafeUrl.root.querySelector( '#safe-literal' ).textContent, 'Test Blade (20%) (Rare)', 'An unsafe URL leaves an exact authored literal readable and prevents a duplicate automatic row.' );
    assert.equal( unsafeUrl.root.querySelectorAll( '.eql-drop-observed-item' ).length, 0 );unsafeUrl.close();
    console.log( 'Drop overlay passed: plain bullets/newlines and linked aliases, manual overrides/reciprocal deduplication, Mote display exclusions with unchanged denominators, safe offline links, dynamic re-rendering, compact single-report percentages and bounded silent failure.' );
} )().catch( error => { console.error( error );process.exitCode = 1; } );
