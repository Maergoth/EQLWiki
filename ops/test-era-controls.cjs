// Regression: page/editor controls must remain usable for out-of-era articles.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let source = fs.readFileSync('skins/EQLImmersive/resources/era-filter.js', 'utf8');
source = source.replace("\tmw.loader.using( [ 'mediawiki.api' ] )", "\twindow.testEra = { collectLinks, applyStatus };\n\tmw.loader.using( [ 'mediawiki.api' ] )");
const context = {
    window: { location: { href: 'https://wiki.example/Out_Of_Era_Item', origin: 'https://wiki.example' } },
    mw: { config: { get: (_key, fallback) => fallback }, loader: { using: () => ({ then() {} }) } },
    document: {}, URL, Map, Set
};
vm.runInNewContext(source, context);
const { collectLinks, applyStatus } = context.window.testEra;
function link(ancestor = '') {
    const classes = new Set();
    const attrs = new Map();
    return {
        ancestor, href: 'https://wiki.example/Out_Of_Era_Item', nodeType: 1,
        matches: selector => selector === 'a[href]', querySelectorAll: () => [],
        closest(selector) { return selector.split(',').map(s => s.trim()).includes(this.ancestor) ? this : null; },
        classList: { add: s => classes.add(s), remove: s => classes.delete(s), contains: s => classes.has(s) },
        setAttribute: (k, v) => attrs.set(k, v), removeAttribute: k => attrs.delete(k)
    };
}
for (const selector of [
    '.mw-header', '.vector-page-toolbar', '.editOptions', '.ve-ui-toolbar',
    '.ve-ui-overlay', '.oo-ui-windowManager', '#ca-edit', '#ca-ve-edit',
    '#ca-history', '#ca-eql-watch', '.mw-editsection', '#footer', '.mw-footer-container'
]) {
    const control = link(selector);
    control.classList.add('eql-era-out-link');
    control.classList.add('eql-era-out-overlay-target');
    assert.equal(collectLinks(control).size, 0, selector);
    assert.equal(control.classList.contains('eql-era-out-link'), false, 'clear stale control mark');
    applyStatus([control], 'Out_Of_Era_Item', true);
    assert.equal(control.classList.contains('eql-era-out-overlay-target'), false, selector);
}
const article = link();
assert.equal(collectLinks(article).size, 1, 'article links remain eligible');
applyStatus([article], 'Out_Of_Era_Item', true);
assert.equal(article.classList.contains('eql-era-out-link'), true);
article.ancestor = '.editOptions';
applyStatus([article], 'Out_Of_Era_Item', true);
assert.equal(article.classList.contains('eql-era-out-link'), false, 'late responses cannot mark moved controls');
console.log('Era filtering excludes source/visual editor controls and preserves article links.');
