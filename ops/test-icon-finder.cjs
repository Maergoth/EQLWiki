const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let source = fs.readFileSync('extensions/EQL-Editor-Tools-Icon-Finder/MediaWiki_IconFinder.js', 'utf8');
source = source.replace('window.EQLIconFinder = {', 'window.testFinder = { iconMetadata, decodeServerRecords }; window.EQLIconFinder = {');
const context = {window: {atob}, mediaWiki: {}, jQuery: {}, Uint8Array};
vm.runInNewContext(source, context);
const metadata = context.window.testFinder.iconMetadata('File:Item 500.png');
assert.equal(metadata.id, '500');
assert.equal(metadata.parameter, '|lucy_img_ID = 500');
assert.equal(metadata.rawWiki, '[[File:Item 500.png]]');
const spell = context.window.testFinder.iconMetadata('File:Spellicon J.png');
assert.equal(spell.id, 'J');
assert.equal(spell.parameter, '|spellicon = J');
const aliases = [{title: 'File:Item 3470.png'}, {title: 'File:Item 3471.png'}];
const decoded = context.window.testFinder.decodeServerRecords([
  {title: aliases[0].title, url: '/icon.png', rgb: btoa('abc'), aliases},
]);
assert.equal(decoded.length, 1);
assert.equal(decoded[0].aliases.length, 2);
assert.equal(decoded[0].aliases[1].title, 'File:Item 3471.png');
console.log('Canonical filenames retain numeric copy parameters; server decoding preserves every duplicate alias.');
