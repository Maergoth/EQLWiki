/* Public, read-only Character snapshot directory. No account or local storage. */
( function () {
    'use strict';
    var mounted = new WeakSet(), serial = 0, PAGE_SIZE = 50;
    var CLASSES = [ [ 'WAR', 'Warrior' ], [ 'CLR', 'Cleric' ], [ 'PAL', 'Paladin' ], [ 'RNG', 'Ranger' ],
        [ 'SHD', 'Shadow Knight' ], [ 'DRU', 'Druid' ], [ 'MNK', 'Monk' ], [ 'BRD', 'Bard' ],
        [ 'ROG', 'Rogue' ], [ 'SHM', 'Shaman' ], [ 'NEC', 'Necromancer' ], [ 'WIZ', 'Wizard' ],
        [ 'MAG', 'Magician' ], [ 'ENC', 'Enchanter' ], [ 'BST', 'Beastlord' ], [ 'BER', 'Berserker' ] ];
    var GROUPS = [
        [ 'Resources', [ [ 'hp', 'HP' ], [ 'mp', 'Mana' ], [ 'end', 'Endurance' ], [ 'hp_regen', 'HP regeneration' ], [ 'mana_regen', 'Mana regeneration' ], [ 'end_regen', 'Endurance regeneration' ] ] ],
        [ 'Defense', [ [ 'ac_mitigation', 'AC mitigation' ], [ 'ac_mitigation_softcap', 'AC mitigation softcap' ], [ 'ac_evasion', 'AC evasion' ], [ 'ac', 'AC (legacy)' ] ] ],
        [ 'Offense', [ [ 'atk_offense', 'ATK offense' ], [ 'atk_accuracy', 'ATK accuracy' ], [ 'atk', 'ATK (legacy)' ], [ 'primary_dps', 'Primary DPS' ], [ 'secondary_dps', 'Secondary DPS' ], [ 'ranged_dps', 'Ranged DPS' ] ] ],
        [ 'Attributes', [ [ 'str', 'Strength' ], [ 'sta', 'Stamina' ], [ 'agi', 'Agility' ], [ 'dex', 'Dexterity' ], [ 'wis', 'Wisdom' ], [ 'int', 'Intelligence' ], [ 'cha', 'Charisma' ] ] ],
        [ 'Resists', [ [ 'sv_magic', 'Magic resist' ], [ 'sv_fire', 'Fire resist' ], [ 'sv_cold', 'Cold resist' ], [ 'sv_poison', 'Poison resist' ], [ 'sv_disease', 'Disease resist' ], [ 'sv_corruption', 'Void resist' ] ] ],
        [ 'Additional modifiers', [ [ 'accuracy', 'Accuracy bonus' ], [ 'avoidance', 'Avoidance' ], [ 'combat_effects', 'Combat effects' ], [ 'strikethrough', 'Strikethrough' ], [ 'stun_resist', 'Stun resist' ], [ 'damage_shield', 'Damage shield' ], [ 'damage_shield_mitigation', 'Damage shield mitigation' ], [ 'dot_shielding', 'DoT shielding' ], [ 'melee_shielding', 'Melee shielding' ], [ 'spell_shielding', 'Spell shielding' ], [ 'attack_speed', 'Attack speed' ], [ 'haste', 'Haste' ], [ 'velocity', 'Velocity' ] ] ]
    ];
    var labels = Object.assign( Object.create( null ), { name: 'Character', level: 'Level', aa: 'AA points', totalTier: 'Total equipped tier', achievementCount: 'Achievement count' } ), classCodes = Object.create( null );
    CLASSES.forEach( function ( pair ) { classCodes[ pair[ 0 ] ] = pair[ 0 ]; classCodes[ pair[ 1 ].toUpperCase().replace( /[^A-Z]/g, '' ) ] = pair[ 0 ]; } );
    classCodes.SK = 'SHD'; classCodes.SHD = 'SHD';
    GROUPS.forEach( function ( group ) { group[ 1 ].forEach( function ( pair ) { labels[ pair[ 0 ] ] = pair[ 1 ]; } ); } );
    function element( tag, text, className ) {
        var node = document.createElement( tag );
        if ( text !== undefined ) { node.textContent = text; }
        if ( className ) { node.className = className; }
        return node;
    }
    function button( text, className ) { var node = element( 'button', text, className ); node.type = 'button'; return node; }
    function number( value ) { return typeof value === 'number' && Number.isFinite( value ) ? value : null; }
    function text( value, limit ) { return typeof value === 'string' ? value.slice( 0, limit || 300 ) : ''; }
    function format( value ) { return value === null ? '—' : value.toLocaleString( undefined, { maximumFractionDigits: 2 } ); }
    function profileUrl( player ) {
        if ( !/^User:[^#\r\n<>]{1,240}$/i.test( player.pageTitle ) ) { return null; }
        try {
            var canonical = new URL( mw.util.getUrl( player.pageTitle ), window.location.href );
            if ( !/^https?:$/.test( canonical.protocol ) || canonical.origin !== window.location.origin || canonical.username || canonical.password ) { return null; }
            var candidate = new URL( player.pageUrl || canonical.href, window.location.href );
            return /^https?:$/.test( candidate.protocol ) && candidate.origin === canonical.origin && !candidate.username && !candidate.password && candidate.pathname === canonical.pathname && candidate.search === canonical.search ? candidate.href : canonical.href;
        } catch ( error ) { return null; }
    }
    function buildGuideUrl( player ) {
        if ( !player.hasClassCombination ) { return null; }
        var names = player.classes.map( function ( code ) { return CLASSES.find( function ( pair ) { return pair[ 0 ] === code; } )[ 1 ]; } );
        names.sort( function ( first, second ) { return first.localeCompare( second, 'en', { sensitivity: 'base' } ); } );
        try {
            var url = new URL( mw.util.getUrl( names.join( '_' ).replace( / /g, '_' ) + '_Guide' ), window.location.href );
            return /^https?:$/.test( url.protocol ) && url.origin === window.location.origin && !url.username && !url.password ? url.href : null;
        } catch ( error ) { return null; }
    }
    function normalize( value ) {
        if ( !value || typeof value !== 'object' || !( typeof value.id === 'string' && value.id.length || typeof value.id === 'number' && Number.isFinite( value.id ) ) || typeof value.name !== 'string' || !value.name.trim() ) { throw new Error( 'Invalid player record' ); }
        var classes = [], stats = Object.create( null );
        ( Array.isArray( value.classes ) ? value.classes : [] ).slice( 0, 16 ).forEach( function ( raw ) {
            var code = classCodes[ String( raw ).toUpperCase().replace( /[^A-Z]/g, '' ) ];
            if ( code && classes.indexOf( code ) < 0 ) { classes.push( code ); }
        } );
        if ( value.stats && typeof value.stats === 'object' && !Array.isArray( value.stats ) ) {
            Object.keys( value.stats ).slice( 0, 100 ).forEach( function ( key ) { if ( /^[a-z][a-z0-9_]{0,47}$/.test( key ) ) { stats[ key ] = number( value.stats[ key ] ); } } );
        }
        if ( String( value.id ).length > 1000 ) { throw new Error( 'Invalid player identity' ); }
        return { id: String( value.id ), name: text( value.name ), server: text( value.server, 150 ), classes: classes, hasClassCombination: Array.isArray( value.classes ) && value.classes.length === 3 && classes.length === 3,
            level: number( value.level ), aa: number( value.aa ), totalTier: number( value.totalTier ), achievementCount: Number.isInteger( value.achievementCount ) && value.achievementCount >= 0 && value.achievementCount <= 1000000000 ? value.achievementCount : null, equippedSlots: number( value.equippedSlots ), tieredSlots: number( value.tieredSlots ), stats: stats,
            updated: text( value.updated, 80 ), pageTitle: text( value.pageTitle ), pageUrl: text( value.pageUrl, 2000 ), snapshotConflict: value.snapshotConflict === true };
    }
    function compareName( first, second ) {
        return first.name.localeCompare( second.name, undefined, { sensitivity: 'base', numeric: true } ) || first.server.localeCompare( second.server ) || first.pageTitle.localeCompare( second.pageTitle ) || first.id.localeCompare( second.id );
    }
    function valueFor( player, key ) { return key === 'name' ? player.name : [ 'level', 'aa', 'totalTier', 'achievementCount' ].indexOf( key ) >= 0 ? player[ key ] : Object.prototype.hasOwnProperty.call( player.stats, key ) ? player.stats[ key ] : null; }
    function dateLabel( value ) {
        if ( /^\d{4}-\d{2}-\d{2}$/.test( value ) ) { value += 'T12:00:00'; }
        if ( /^\d{14}$/.test( value ) ) { value = value.slice( 0, 4 ) + '-' + value.slice( 4, 6 ) + '-' + value.slice( 6, 8 ) + 'T' + value.slice( 8, 10 ) + ':' + value.slice( 10, 12 ) + ':' + value.slice( 12, 14 ) + 'Z'; }
        var at = Date.parse( value );
        return Number.isFinite( at ) ? new Date( at ).toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } ) : null;
    }
    function mount( root ) {
        if ( mounted.has( root ) || root.dataset.eqlPlayerListMounted === '1' ) { return; }
        mounted.add( root ); root.dataset.eqlPlayerListMounted = '1';
        var prefix = 'eql-pl-' + ( ++serial ), players = [], maxLevel = null, loading = false, complete = false, generation = 0, page = 0, sortKey = 'totalTier', descending = true, columnsKey = '';
        root.classList.add( 'eql-player-list' );
        var hero = element( 'header', undefined, 'eql-pl-hero' ), intro = element( 'div' ), summary = element( 'div', undefined, 'eql-pl-summary' );
        intro.append( element( 'span', 'THE ADVENTURERS’ REGISTER', 'eql-pl-eyebrow' ), element( 'h2', 'Discover the players of Legends' ), element( 'p', 'Browse public character snapshots shared by players. Find a class combination, compare published stats, and open a profile to explore its equipment.' ) );
        var total = element( 'strong', '—' ), serversTotal = element( 'strong', '—' );
        [ [ total, 'characters' ], [ serversTotal, 'servers' ] ].forEach( function ( pair ) { var tile = element( 'div' ); tile.append( pair[ 0 ], element( 'span', pair[ 1 ] ) ); summary.appendChild( tile ); } );
        hero.append( intro, summary );
        var filters = element( 'section', undefined, 'eql-pl-filters' ); filters.setAttribute( 'aria-label', 'Filter players' );
        var top = element( 'div', undefined, 'eql-pl-filter-row' ), search = element( 'input' ), server = element( 'select' ), maximum = element( 'input' ), maximumText = element( 'span', 'Max level only' );
        search.type = 'search'; search.placeholder = 'Character name or wiki user'; search.maxLength = 240; search.id = prefix + '-search';
        server.id = prefix + '-server'; var allServers = element( 'option', 'All servers' ); allServers.value = ''; server.appendChild( allServers );
        function field( name, control ) { var label = element( 'label', undefined, 'eql-pl-field' ); label.append( element( 'span', name ), control ); return label; }
        maximum.type = 'checkbox'; maximum.dataset.filter = 'maximum'; var maximumLabel = element( 'label', undefined, 'eql-pl-check eql-pl-maximum' ); maximumLabel.append( maximum, maximumText );
        top.append( field( 'Search players', search ), field( 'Server', server ), maximumLabel );
        var classes = element( 'fieldset', undefined, 'eql-pl-class-filter' ), legend = element( 'legend', 'Classes' ), classGrid = element( 'div', undefined, 'eql-pl-class-grid' ), classControls = [];
        var anyClass = element( 'input' ), anyLabel = element( 'label', undefined, 'eql-pl-class-choice' );
        anyClass.type = 'checkbox'; anyClass.checked = true; anyClass.dataset.filter = 'any-class'; anyClass.setAttribute( 'aria-label', 'Any classes' );
        anyLabel.append( anyClass, element( 'span', 'Any' ) ); classGrid.appendChild( anyLabel );
        CLASSES.forEach( function ( pair ) {
            var label = element( 'label', undefined, 'eql-pl-class-choice' ), input = element( 'input' ); input.type = 'checkbox'; input.value = pair[ 0 ]; input.dataset.playerClass = pair[ 0 ];
            label.append( input, element( 'span', pair[ 1 ] ), element( 'small', pair[ 0 ] ) ); classGrid.appendChild( label ); classControls.push( input );
        } );
        classes.append( legend, classGrid ); filters.append( top, classes );
        var resultsBar = element( 'div', undefined, 'eql-pl-results-bar' ), resultCount = element( 'p', '', 'eql-pl-result-count' ), order = element( 'select' ); order.id = prefix + '-order'; resultCount.setAttribute( 'role', 'status' ); resultCount.setAttribute( 'aria-live', 'polite' );
        function option( key, label, parent ) { var node = element( 'option', label ); node.value = key; parent.appendChild( node ); }
        var baseGroup = element( 'optgroup' ); baseGroup.label = 'Character'; [ 'totalTier', 'level', 'aa', 'achievementCount', 'name' ].forEach( function ( key ) { option( key, labels[ key ], baseGroup ); } ); order.appendChild( baseGroup );
        GROUPS.forEach( function ( group ) { var node = element( 'optgroup' ); node.label = group[ 0 ]; group[ 1 ].forEach( function ( pair ) { option( pair[ 0 ], pair[ 1 ], node ); } ); order.appendChild( node ); } ); order.value = sortKey;
        var direction = button( 'Highest first ↓', 'eql-pl-direction' ), refresh = button( 'Refresh list', 'eql-pl-quiet-button' ), ordering = element( 'div', undefined, 'eql-pl-ordering' );
        ordering.append( field( 'Order by', order ), direction, refresh ); resultsBar.append( resultCount, ordering );
        var status = element( 'p', '', 'eql-pl-status' ); status.setAttribute( 'role', 'status' ); status.setAttribute( 'aria-live', 'polite' );
        var retry = button( 'Try again', 'eql-pl-retry' ); retry.hidden = true;
        var scroll = element( 'div', undefined, 'eql-pl-table-scroll' ); scroll.tabIndex = 0; scroll.setAttribute( 'role', 'region' ); scroll.setAttribute( 'aria-label', 'Player comparisons; scroll horizontally for more columns' );
        var table = element( 'table', undefined, 'eql-pl-table' ), caption = element( 'caption' ), head = element( 'thead' ), body = element( 'tbody' );
        table.append( caption, head, body ); scroll.appendChild( table );
        var empty = element( 'div', undefined, 'eql-pl-empty' ); empty.append( element( 'strong', 'No characters match these filters.' ), element( 'p', 'Try another server or class combination, or clear the filters.' ) );
        var reset = button( 'Clear filters', 'eql-pl-retry' ); empty.appendChild( reset ); empty.hidden = true;
        var navigation = element( 'nav', undefined, 'eql-pl-pagination' ); navigation.setAttribute( 'aria-label', 'Player results pages' );
        var previous = button( '← Previous', 'eql-pl-quiet-button' ), next = button( 'Next →', 'eql-pl-quiet-button' ), position = element( 'span' ); navigation.append( previous, position, next );
        var note = element( 'p', 'Player-published snapshots, not online status. Total equipped tier excludes Exaltations. Unreported values appear as —.', 'eql-pl-note' );
        root.replaceChildren( hero, filters, resultsBar, status, retry, scroll, empty, navigation, note );
        function setBusy( value ) { loading = value; root.setAttribute( 'aria-busy', String( value ) ); filters.querySelectorAll( 'input,select,button' ).forEach( function ( control ) { control.disabled = value; } ); order.disabled = direction.disabled = refresh.disabled = value; }
        function sort( key, toggle ) { if ( sortKey === key && toggle ) { descending = !descending; } else { sortKey = key; descending = key !== 'name'; } order.value = sortKey; page = 0; render(); }
        function headers() {
            var fields = [ [ '', '#' ], [ 'name', 'Character / server' ], [ '', 'Classes' ], [ 'level', 'Level' ], [ 'aa', 'AA' ], [ 'totalTier', 'Equipped tier' ] ];
            if ( [ 'name', 'level', 'aa', 'totalTier' ].indexOf( sortKey ) < 0 ) { fields.push( [ sortKey, labels[ sortKey ] || sortKey.replace( /_/g, ' ' ) ] ); }
            var key = JSON.stringify( fields );
            if ( key !== columnsKey ) {
                var focusedSort = head.contains( document.activeElement ) && document.activeElement.dataset.sort;
                columnsKey = key; var row = element( 'tr' );
                fields.forEach( function ( field ) { var cell = element( 'th' ); cell.scope = 'col';
                    if ( field[ 0 ] ) { var control = button( field[ 1 ] ); control.dataset.sort = field[ 0 ]; control.addEventListener( 'click', function () { sort( field[ 0 ], true ); } ); cell.appendChild( control ); }
                    else { cell.textContent = field[ 1 ]; } row.appendChild( cell );
                } ); head.replaceChildren( row );
                if ( focusedSort ) { Array.from( head.querySelectorAll( '[data-sort]' ) ).some( function ( control ) { if ( control.dataset.sort === focusedSort ) { control.focus();return true; } return false; } ); }
            }
            head.querySelectorAll( '[data-sort]' ).forEach( function ( control ) { var active = control.dataset.sort === sortKey; control.parentNode.setAttribute( 'aria-sort', active ? descending ? 'descending' : 'ascending' : 'none' ); control.setAttribute( 'aria-label', 'Sort by ' + ( labels[ control.dataset.sort ] || control.dataset.sort ) + ( active ? descending ? ', highest first' : ', lowest first' : '' ) ); control.dataset.direction = active ? descending ? '↓' : '↑' : '↕'; } );
        }
        function numericCell( row, value, className ) { var cell = element( 'td', format( value ), className || 'eql-pl-number' ); if ( value === null ) { cell.classList.add( 'is-unknown' ); var unknown = element( 'span', '—' ); unknown.setAttribute( 'aria-label', 'Unknown' ); cell.replaceChildren( unknown ); } row.appendChild( cell ); return cell; }
        function render() {
            if ( !complete || loading ) { scroll.hidden = empty.hidden = navigation.hidden = true; return; }
            var query = search.value.trim().toLocaleLowerCase(), selected = classControls.filter( function ( input ) { return input.checked; } ).map( function ( input ) { return input.value; } );
            var filtered = players.filter( function ( player ) {
                return ( !query || ( player.name + ' ' + player.pageTitle ).toLocaleLowerCase().indexOf( query ) >= 0 ) && ( !server.value || player.server === server.value ) && ( !maximum.checked || player.level === maxLevel ) && selected.every( function ( code ) { return player.classes.indexOf( code ) >= 0; } );
            } );
            filtered.sort( function ( first, second ) { var a = valueFor( first, sortKey ), b = valueFor( second, sortKey );
                if ( a === null || b === null ) { return a === b ? compareName( first, second ) : a === null ? 1 : -1; }
                var difference = typeof a === 'string' ? a.localeCompare( b, undefined, { sensitivity: 'base', numeric: true } ) : a - b;
                return difference ? descending ? -difference : difference : compareName( first, second );
            } );
            var pages = Math.max( 1, Math.ceil( filtered.length / PAGE_SIZE ) ); page = Math.max( 0, Math.min( page, pages - 1 ) );
            direction.textContent = sortKey === 'name' ? descending ? 'Z–A ↓' : 'A–Z ↑' : descending ? 'Highest first ↓' : 'Lowest first ↑';
            caption.textContent = 'Public character snapshots · ' + ( labels[ sortKey ] || sortKey.replace( /_/g, ' ' ) ) + ( descending ? ', highest first' : ', lowest first' );
            resultCount.textContent = filtered.length.toLocaleString() + ' of ' + players.length.toLocaleString() + ' characters'; headers(); body.replaceChildren();
            filtered.slice( page * PAGE_SIZE, ( page + 1 ) * PAGE_SIZE ).forEach( function ( player, index ) {
                var row = element( 'tr' ), unranked = sortKey !== 'name' && valueFor( player, sortKey ) === null;
                row.dataset.playerId = player.id;
                var rank = element( 'td', unranked ? '—' : String( page * PAGE_SIZE + index + 1 ), 'eql-pl-rank' );
                if ( unranked ) { rank.setAttribute( 'aria-label', 'Unranked: no reported value' ); } row.appendChild( rank );
                var identity = element( 'td', undefined, 'eql-pl-identity' ), url = profileUrl( player ), name = element( url ? 'a' : 'strong', player.name ); if ( url ) { name.href = url; name.setAttribute( 'aria-label', player.name + ' · view public profile' ); }
                identity.append( name, element( 'span', player.server || 'Server not specified', 'eql-pl-server' ) );
                if ( player.achievementCount !== null ) { identity.appendChild( element( 'small', format( player.achievementCount ) + ' achievements', 'eql-pl-achievement-count' ) ); }
                if ( player.snapshotConflict ) { identity.appendChild( element( 'small', 'Conflicting snapshots · view profile', 'eql-pl-updated' ) ); }
                var updated = dateLabel( player.updated ); if ( updated ) { identity.appendChild( element( 'small', 'Updated ' + updated, 'eql-pl-updated' ) ); } row.appendChild( identity );
                var combinations = element( 'td', undefined, 'eql-pl-combination' ), guideUrl = buildGuideUrl( player ), chips = combinations;
                if ( guideUrl ) { chips = element( 'a', undefined, 'eql-pl-build-guide' ); chips.href = guideUrl; chips.target = '_blank'; chips.rel = 'noopener noreferrer'; chips.setAttribute( 'aria-label', player.classes.map( function ( code ) { return CLASSES.find( function ( pair ) { return pair[ 0 ] === code; } )[ 1 ]; } ).join( ', ' ) + ' build guide (opens in a new tab)' ); combinations.appendChild( chips ); }
                if ( player.classes.length ) { player.classes.forEach( function ( code ) { var chip = element( 'abbr', code ); chip.title = CLASSES.find( function ( pair ) { return pair[ 0 ] === code; } )[ 1 ]; chips.appendChild( chip ); } ); } else { combinations.textContent = 'Not specified'; } row.appendChild( combinations );
                numericCell( row, player.level ); numericCell( row, player.aa ); var tier = numericCell( row, player.totalTier, 'eql-pl-tier' );
                tier.appendChild( element( 'small', format( player.equippedSlots ) + ' equipped · ' + format( player.tieredSlots ) + ' tiered' ) );
                if ( fieldsStat() ) { numericCell( row, valueFor( player, sortKey ), 'eql-pl-number eql-pl-featured-stat' ); } body.appendChild( row );
            } );
            scroll.hidden = filtered.length === 0; empty.hidden = filtered.length !== 0; navigation.hidden = filtered.length <= PAGE_SIZE;
            if ( !players.length ) { empty.firstChild.textContent = 'No public character snapshots yet.'; empty.querySelector( 'p' ).textContent = 'Characters will appear here when players publish their snapshots to the wiki.'; } else { empty.firstChild.textContent = 'No characters match these filters.'; empty.querySelector( 'p' ).textContent = 'Try another server or class combination, or clear the filters.'; }
            previous.disabled = page === 0; next.disabled = page + 1 >= pages; position.textContent = 'Page ' + ( page + 1 ) + ' of ' + pages;
        }
        function fieldsStat() { return [ 'name', 'level', 'aa', 'totalTier' ].indexOf( sortKey ) < 0; }
        function changed() { page = 0; render(); }
        [ search, server, maximum ].forEach( function ( control ) { control.addEventListener( control === search ? 'input' : 'change', changed ); } );
        classControls.forEach( function ( control ) { control.addEventListener( 'change', function () { anyClass.checked = !classControls.some( function ( input ) { return input.checked; } ); changed(); } ); } );
        function clearClasses() { classControls.forEach( function ( control ) { control.checked = false; } ); anyClass.checked = true; changed(); }
        anyClass.addEventListener( 'change', clearClasses );
        reset.addEventListener( 'click', function () { search.value = ''; server.value = ''; maximum.checked = false; clearClasses(); search.focus(); } );
        order.addEventListener( 'change', function () { sort( order.value, false ); } ); direction.addEventListener( 'click', function () { descending = !descending; page = 0; render(); } );
        previous.addEventListener( 'click', function () { page--; render(); } ); next.addEventListener( 'click', function () { page++; render(); } );
        function load() {
            if ( loading ) { return; }
            var owner = ++generation, loaded = [], ids = new Set(), tokens = new Set(), pages = 0, reportedMax = null, api;
            function current() { if ( owner === generation && !root.isConnected ) { generation++; loading = false; mounted.delete( root ); delete root.dataset.eqlPlayerListMounted; } return owner === generation && root.isConnected; }
            complete = false; setBusy( true ); total.textContent = serversTotal.textContent = '—'; resultCount.textContent = 'Loading directory…'; retry.hidden = true; status.hidden = false; status.classList.remove( 'is-error' ); status.textContent = 'Gathering public character snapshots…'; render();
            function read( continuation ) {
                if ( !current() ) { return Promise.resolve(); }
                if ( ++pages > 400 || loaded.length > 20000 ) { return Promise.reject( new Error( 'Directory exceeds browser bounds' ) ); }
                var params = { action: 'eqlplayers', formatversion: 2, limit: 25 }; if ( continuation ) { params.continue = continuation; }
                return Promise.resolve( api.get( params ) ).then( function ( response ) {
                    if ( !current() ) { return; }
                    var result = response && response.eqlplayers;
                    if ( !result || !Array.isArray( result.players ) || !Number.isInteger( result.maxLevel ) || result.maxLevel < 1 || result.maxLevel > 1000 || reportedMax !== null && reportedMax !== result.maxLevel ) { throw new Error( 'Invalid directory response' ); }
                    reportedMax = result.maxLevel;
                    result.players.forEach( function ( value ) { var player = normalize( value ); if ( !ids.has( player.id ) ) { ids.add( player.id ); loaded.push( player ); } } );
                    if ( loaded.length > 20000 ) { throw new Error( 'Directory exceeds browser bounds' ); }
                    status.textContent = 'Gathering public character snapshots… ' + loaded.length.toLocaleString() + ' found.';
                    if ( result.continue !== undefined ) { if ( typeof result.continue !== 'string' || !result.continue || result.continue.length > 4096 || tokens.has( result.continue ) ) { throw new Error( 'Invalid directory continuation' ); } tokens.add( result.continue ); return read( result.continue ); }
                    if ( !current() ) { return; }
                    players = loaded; maxLevel = reportedMax; complete = true;
                    var selectedServer = server.value, names = Array.from( new Set( players.map( function ( player ) { return player.server; } ).filter( Boolean ) ) ).sort(); server.replaceChildren( allServers ); names.forEach( function ( name ) { option( name, name, server ); } ); if ( names.indexOf( selectedServer ) >= 0 ) { server.value = selectedServer; }
                    total.textContent = players.length.toLocaleString(); serversTotal.textContent = names.length.toLocaleString(); maximumText.textContent = 'Max level only (' + maxLevel + ')';
                    var extra = new Set(); players.forEach( function ( player ) { Object.keys( player.stats ).forEach( function ( key ) { if ( !Object.prototype.hasOwnProperty.call( labels, key ) ) { extra.add( key ); } } ); } );
                    var priorExtra = order.querySelector( '[data-extra-stats]' ); if ( priorExtra ) { priorExtra.remove(); }
                    if ( extra.size ) { var group = element( 'optgroup' ); group.label = 'Other published stats'; group.dataset.extraStats = '1'; Array.from( extra ).sort().forEach( function ( key ) { option( key, key.replace( /_/g, ' ' ), group ); } ); order.appendChild( group ); }
                    order.value = sortKey; if ( order.value !== sortKey ) { sortKey = 'totalTier'; descending = true; order.value = sortKey; }
                    setBusy( false ); status.hidden = true; render();
                } );
            }
            Promise.resolve().then( function () { api = new mw.Api(); return read(); } ).catch( function () { if ( !current() ) { return; } setBusy( false ); status.classList.add( 'is-error' ); status.textContent = 'The complete player list could not be loaded. No partial rankings are shown.'; retry.hidden = false; scroll.hidden = empty.hidden = navigation.hidden = true; } );
        }
        refresh.addEventListener( 'click', load ); retry.addEventListener( 'click', load ); load();
    }
    function initialize( root ) {
        root = root && root.querySelectorAll ? root : document;
        var nodes = Array.from( root.querySelectorAll( '.eql-player-list, #eql-player-list' ) ); if ( root.matches && root.matches( '.eql-player-list, #eql-player-list' ) ) { nodes.unshift( root ); }
        nodes.forEach( mount );
    }
    if ( typeof mw !== 'undefined' && mw.hook ) { mw.hook( 'wikipage.content' ).add( function ( content ) { initialize( content && content[ 0 ] || content ); } ); }
    if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', function () { initialize( document ); }, { once: true } ); } else { initialize( document ); }
}() );
