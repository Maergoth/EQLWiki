'use strict';

// Fixed public schema. Generate reviewed database sources, never publish them.
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const rows = [
    [ [ 'ear1', 'Left ear' ], [ 'neck', 'Neck' ], [ 'face', 'Face' ], [ 'head', 'Head' ], [ 'ear2', 'Right ear' ] ],
    [ [ 'finger1', 'Left finger' ], [ 'wrist1', 'Left wrist' ], [ 'arms', 'Arms' ], [ 'hands', 'Hands' ], [ 'wrist2', 'Right wrist' ], [ 'finger2', 'Right finger' ] ],
    [ [ 'shoulders', 'Shoulders' ], [ 'chest', 'Chest' ], [ 'back', 'Back' ], [ 'waist', 'Waist' ], [ 'legs', 'Legs' ], [ 'feet', 'Feet' ] ],
    [ [ 'primary', 'Primary' ], [ 'secondary', 'Secondary' ], [ 'range', 'Range' ], [ 'ammo', 'Ammo' ], [ 'charm', 'Charm' ] ],
    [ [ 'any1', 'Any 1' ], [ 'any2', 'Any 2' ] ]
];
const vitals = [ [ 'hp', 'HP' ], [ 'mp', 'Mana' ], [ 'end', 'Endurance' ] ];
const combat = [ [ 'ac_mitigation', 'Mitigation AC' ], [ 'ac_mitigation_softcap', 'AC softcap' ], [ 'ac_evasion', 'Evasion AC' ], [ 'atk_offense', 'Offense' ], [ 'atk_accuracy', 'Accuracy' ], [ 'hp_regen', 'HP regen' ], [ 'mana_regen', 'Mana regen' ], [ 'end_regen', 'End. regen' ], [ 'velocity', 'Velocity' ], [ 'primary_dps', 'Primary DPS' ], [ 'secondary_dps', 'Secondary DPS' ], [ 'ranged_dps', 'Ranged DPS' ] ];
const attributes = [ [ 'str', 'STR' ], [ 'sta', 'STA' ], [ 'agi', 'AGI' ], [ 'dex', 'DEX' ], [ 'wis', 'WIS' ], [ 'int', 'INT' ], [ 'cha', 'CHA' ], [ 'sv_magic', 'Magic resist' ], [ 'sv_fire', 'Fire resist' ], [ 'sv_cold', 'Cold resist' ], [ 'sv_poison', 'Poison resist' ], [ 'sv_disease', 'Disease resist' ], [ 'sv_corruption', 'Void resist' ] ];
const modifiers = [ [ 'accuracy', 'Accuracy bonus' ], [ 'avoidance', 'Avoidance' ], [ 'combat_effects', 'Combat effects' ], [ 'strikethrough', 'Strikethrough' ], [ 'stun_resist', 'Stun resist' ], [ 'damage_shield', 'Damage shield' ], [ 'damage_shield_mitigation', 'Damage shield mitigation' ], [ 'dot_shielding', 'DoT shielding' ], [ 'melee_shielding', 'Melee shielding' ], [ 'spell_shielding', 'Spell shielding' ] ];
const parameter = ( name, fallback = '' ) => `{{{${name}|${fallback}}}}`;
const stat = ( key, label, optional = false ) => {
    const value = parameter( `total_${key}`, '?' );
    const row = `<div><dt>${label}</dt><dd>${value}</dd></div>`;
    return optional ? `{{#if:${parameter( `total_${key}` )}|${row}}}` : row;
};
const itemFields = [ 'item', 'tier', 'icon', 'stats', 'details', 'augment_slots', ...Array.from( { length: 8 }, ( _, i ) => [ 'item', 'tier', 'icon', 'stats', 'details' ].map( field => `augment_${i + 1}_${field}` ) ).flat() ];
function slot( [ id, label ] ) {
    return '{{Character_sheet_item\n|slot=' + label + '\n' + itemFields.map( field => `|${field}=${parameter( `${id}_${field}` )}` ).join( '\n' ) + '\n}}';
}
function icon( prefix = '' ) {
    return `{{#if:${parameter( `${prefix}icon` )}|[[File:item_${parameter( `${prefix}icon` )}.png|48x48px|link=${parameter( `${prefix}item` )}|alt=${parameter( `${prefix}item` )}]]|[[${parameter( `${prefix}item` )}|◇]]}}`;
}
function tier( prefix = '' ) {
    return `{{#if:${parameter( `${prefix}tier` )}|<span class="eql-character-tier">+${parameter( `${prefix}tier` )}</span>}}`;
}
function augment( number ) {
    const prefix = `augment_${number}_`;
    const present = `<li><span class="eql-character-augment-icon">${icon( prefix )}</span><span><strong>[[${parameter( `${prefix}item` )}]]</strong>${tier( prefix )}<span class="eql-character-augment-stats">${parameter( `${prefix}stats` )}</span><span class="eql-character-item-details">${parameter( `${prefix}details` )}</span></span></li>`;
    const empty = `{{#if:${parameter( 'augment_slots' )}|{{#ifexpr:${parameter( 'augment_slots', '0' )} >= ${number}|<li class="eql-character-empty-socket">Socket ${number}: Empty</li>}}}}`;
    return `{{#if:${parameter( `${prefix}item` )}|${present}|${empty}}}`;
}
const main = '<includeonly><!-- EQL_CHARACTER_SHEET_V1 -->\n<div class="eql-character-sheet eql-character-sheet-v1">\n' +
    '<div class="eql-character-heading"><strong>' + parameter( 'name', 'Unnamed character' ) + ' (' + parameter( 'server', '?' ) + ')</strong>' +
    '<span class="eql-character-metadata">Level ' + parameter( 'level', '?' ) + ' · ' + parameter( 'classes', '?' ) + ' · ' + parameter( 'aa', '?' ) + ' AAs' +
    '{{#if:' + parameter( 'race' ) + '| · ' + parameter( 'race' ) + '}}</span></div>\n' +
    '<div class="eql-character-body"><div class="eql-character-layout"><div class="eql-character-totals"><div class="eql-character-group-heading">Character</div><dl>' +
    vitals.map( row => stat( ...row ) ).join( '' ) + combat.map( row => stat( ...row, true ) ).join( '' ) +
    '{{#if:' + parameter( 'total_attack_speed' ) + '|' + stat( 'attack_speed', 'Attack speed %', true ) + '|' + stat( 'haste', 'Haste %', true ) + '}}' +
    '{{#if:' + [ 'ac_mitigation', 'ac_mitigation_softcap', 'ac_evasion' ].map( key => parameter( `total_${key}` ) ).join( '' ) + '||' + stat( 'ac', 'AC' ) + '}}' +
    '{{#if:' + [ 'atk_offense', 'atk_accuracy' ].map( key => parameter( `total_${key}` ) ).join( '' ) + '||' + stat( 'atk', 'Attack' ) + '}}' +
    '</dl><div class="eql-character-group-heading">Stats and resists</div><dl>' + attributes.map( row => stat( ...row ) ).join( '' ) + '</dl>' +
    '{{#if:' + modifiers.map( ( [ key ] ) => parameter( `total_${key}` ) ).join( '' ) + '|<div class="eql-character-group-heading">Additional modifiers</div><dl>' + modifiers.map( row => stat( ...row, true ) ).join( '' ) + '</dl>}}</div>\n' +
    '<div class="eql-character-equipment" aria-label="Equipment">' + rows.map( row => '<div class="eql-character-equipment-row">' + row.map( slot ).join( '' ) + '</div>' ).join( '' ) + '</div></div>\n' +
    '<p class="eql-character-note">? Unknown · * Estimate. Player-reviewed snapshot; buffs and server formulas may differ.{{#if:' + parameter( 'updated' ) + '| Updated: ' + parameter( 'updated' ) + '}}</p>{{#if:' + parameter( 'caption' ) + '|<div class="eql-character-caption">' + parameter( 'caption' ) + '</div>}}</div></div></includeonly>\n' +
    '<noinclude>Hand-editable Character sheet schema 1. See [[Help:Contents]] and the tracked <code>docs/CHARACTER_SHEETS.md</code> guide. Generated by <code>ops/build-character-sheet-templates.cjs</code>; do not independently change the two-template contract. Identity: name, server, level, classes, aa, race, updated. Totals use <code>total_</code> plus a lowercase canonical stat key. Equipment uses the fixed slot prefix plus <code>_item</code>, <code>_tier</code>, <code>_icon</code>, <code>_stats</code>, <code>_augment_slots</code> and <code>_augment_1_item</code> through <code>_augment_8_stats</code>. No progress, raw screenshots, private OCR evidence or game artwork is embedded.</noinclude>\n';
const item = '<includeonly><!-- EQL_CHARACTER_SHEET_ITEM_V1 --><div class="eql-character-slot"><span class="eql-character-slot-label">' + parameter( 'slot', 'Equipment' ) + '</span>' +
    '{{#if:' + parameter( 'item' ) + '|<div class="eql-character-item"><span class="eql-character-item-icon">' + icon() + '</span>' + tier() +
    '<span class="eql-character-item-name">[[' + parameter( 'item' ) + ']]</span><div class="eql-character-inspect-v1"><strong>[[' + parameter( 'item' ) + ']]</strong>' + tier() +
    '<div class="eql-character-item-stats">' + parameter( 'stats' ) + '</div><div class="eql-character-item-details">' + parameter( 'details' ) + '</div>' +
    '{{#if:' + parameter( 'augment_slots' ) + Array.from( { length: 8 }, ( _, i ) => parameter( `augment_${i + 1}_item` ) ).join( '' ) + '|<div class="eql-character-augments"><strong>Exaltations{{#if:' + parameter( 'augment_slots' ) + '|&#32;(' + parameter( 'augment_slots' ) + ' sockets)}}</strong><ul>' + Array.from( { length: 8 }, ( _, i ) => augment( i + 1 ) ).join( '' ) + '</ul></div>}}' +
    '</div></div>|<span class="eql-character-empty" aria-label="Empty slot">—</span>}}</div></includeonly>\n' +
    '<noinclude>Character sheet schema 1 item helper. EQL_CHARACTER_SHEET_ITEM_V1. Supply a canonical item page title, optional tier 0–10, existing numeric item icon ID, reviewed stat lines, optional socket capacity 0–8 and up to eight indexed Exaltations. Optional details contain reviewed descriptive metadata and effect links. This helper computes no game statistics.</noinclude>\n';
for ( const [ file, source ] of [ [ 'character-sheet.wiki', main ], [ 'character-sheet-item.wiki', item ] ] ) {
    const target = path.join( __dirname, file );
    if ( process.argv.includes( '--check' ) ) {
        if ( fs.readFileSync( target, 'utf8' ).replace( /\r\n/g, '\n' ) !== source ) throw Error( `${file} differs from its generator` );
    } else fs.writeFileSync( target, source );
}
console.log( process.argv.includes( '--check' ) ? 'Character sheet templates match schema 1.' : 'Generated both Character sheet template sources.' );
