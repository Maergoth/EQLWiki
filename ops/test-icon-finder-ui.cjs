// Requires jsdom (also used by the Sky rewards regression tests).
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');

async function main() {
  const dom = new JSDOM('<div id="results"></div>', {url: 'https://wiki.example/'});
  const {window} = dom;
  const writes = [];
  Object.defineProperty(window.navigator, 'clipboard', {
    value: {writeText: async text => { writes.push(text); }},
  });
  let source = fs.readFileSync('extensions/EQL-Editor-Tools-Icon-Finder/MediaWiki_IconFinder.js', 'utf8');
  source = source.replace('window.EQLIconFinder = {',
    'window.testFinder = {renderMatches}; window.EQLIconFinder = {');
  vm.runInNewContext(source, {
    window, document: window.document, navigator: window.navigator,
    mediaWiki: {util: {getUrl: title => '/wiki/' + encodeURIComponent(title)}},
    jQuery: {}, Uint8Array,
  });
  const aliases = [3470, 3471].map(id => ({title: `File:Item ${id}.png`, url: `/${id}.png`}));
  const matches = Array.from({length: 100}, (_, i) => ({
    record: i === 0 ? {...aliases[0], aliases} : {title: `File:Item ${i}.png`, url: `/${i}.png`},
  }));
  const container = window.document.getElementById('results');
  const counts = [];
  window.testFinder.renderMatches(container, matches, (shown, total) => counts.push([shown, total]));
  assert.equal(container.querySelectorAll('.eql-iconfinder-result').length, 8);
  assert.deepEqual(counts, [[8, 100]]);
  const card = container.querySelector('.eql-iconfinder-result');
  const image = card.querySelector('.eql-iconfinder-image-copy');
  image.click();
  await Promise.resolve();
  assert.equal(writes.pop(), '3470');
  assert.equal(card.querySelector('[role="status"]').textContent, 'Copied');
  assert.equal(image.getAttribute('aria-label'), 'Copy icon ID 3470');
  const choices = card.querySelector('select');
  choices.value = '1';
  choices.dispatchEvent(new window.Event('change'));
  image.click();
  assert.equal(writes.pop(), '3471');
  assert.equal(image.getAttribute('aria-label'), 'Copy icon ID 3471');
  assert.equal(card.querySelector('a').textContent, 'Item 3471.png');
  assert.equal(card.querySelector('img').getAttribute('src'), '/3471.png');
  const menu = card.querySelector('details');
  const formats = ['3471', '|lucy_img_ID = 3471', '[[File:Item 3471.png]]',
    '[[File:Item 3471.png|32x32px]]', '[[File:Item 3471.png|frameless|upright=1.5]]'];
  const buttons = [...menu.querySelectorAll('button')];
  assert.equal(buttons.length, formats.length);
  buttons.forEach((button, i) => {
    menu.open = true;
    button.click();
    assert.equal(writes.pop(), formats[i]);
    assert.equal(menu.open, false);
    assert.equal(window.document.activeElement, menu.querySelector('summary'));
  });
  menu.open = true;
  menu.dispatchEvent(new window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
  assert.equal(menu.open, false);
  menu.open = true;
  menu.dispatchEvent(new window.FocusEvent('focusout', {relatedTarget: image}));
  assert.equal(menu.open, false);
  const more = container.querySelector('.eql-iconfinder-copy');
  while (!more.hidden) more.click();
  assert.equal(container.querySelectorAll('.eql-iconfinder-result').length, 100);
  assert.deepEqual(counts.at(-1), [100, 100]);
  window.testFinder.renderMatches(container, [{record: {title: 'File:Unknown.png', url: '/unknown.png'}}], () => {});
  assert.equal(container.querySelector('.eql-iconfinder-image-copy').disabled, true);
  assert.equal(container.querySelectorAll('details button').length, 3);
  window.testFinder.renderMatches(container, [{record: {title: 'File:Item 0.png', url: '/0.png'}}], () => {});
  container.querySelector('.eql-iconfinder-image-copy').click();
  assert.equal(writes.pop(), '0');
  window.testFinder.renderMatches(container, [{record: {title: 'File:Spellicon J.png', url: '/J.png'}}], () => {});
  container.querySelector('.eql-iconfinder-image-copy').click();
  assert.equal(writes.pop(), 'J');
  dom.window.close();
  console.log('Eight initial matches; all 100 reachable; one-click IDs, aliases, copy formats and keyboard dismissal pass.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
