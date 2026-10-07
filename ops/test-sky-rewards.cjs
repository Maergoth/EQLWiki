// Run with jsdom 27 available through NODE_PATH and a reviewed Common.js snapshot:
// node ops/test-sky-rewards.cjs /private/path/Common.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const common = fs.readFileSync(process.argv[2], 'utf8');
const section = fs.readFileSync('skins/EQLImmersive/resources/sky-rewards.js', 'utf8');
const stateEnd = common.lastIndexOf('/* =====', common.indexOf(' * Raid Instance View'));
assert.ok(stateEnd > 0, 'Common.js must contain the reviewed EQLUserState section');
const stateSource = common.slice(0, stateEnd);
const oldAt = common.indexOf(' * Plane of Sky - quest reward number fields');
const oldStart = common.lastIndexOf('/* =====', oldAt);
const oldEnd = common.indexOf('/* =====', oldAt);
const oldSection = common.slice(oldStart, oldEnd);
const localKey = 'eql-user-state-v2:anon:Plane_of_Sky';
function markup(hover, label = 'Mask of Song', href = '/Mask_of_Song') {
    return `<div id="mw-content-text"><div class="mw-parser-output"><h3 id="Bard">Bard</h3>
    <table class="eoTable3"><tr><td><span class="hbdiv"><a href="${href}">${label}</a>
    ${hover ? '<span class="hb">Item Level:10 AC:15 STR:+8</span>' : ''}</span></td>
    <td>Test of Tone</td><td>Tone</td><td><ul class="checkbox-list"><li>Wind Rune Meda</li></ul></td><td>Reward</td></tr></table>
    </div></div>`;
}
async function visit({ hover = false, label, href, local = {}, user = null, account, old = false, loadDelay = 0 } = {}) {
    const dom = new JSDOM(markup(hover, label, href), { url: 'https://wiki.example/Plane_of_Sky', runScripts: 'outside-only' });
    const w = dom.window;
    Object.entries(local).forEach(([key, value]) => w.localStorage.setItem(key, value));
    const hooks = [];
    const saved = [];
    w.mw = w.mediaWiki = {
        config: { get: key => ({ wgPageName: 'Plane_of_Sky', wgUserId: user })[key] },
        hook: () => ({ add: callback => hooks.push(callback) }),
        loader: { using: () => new Promise(resolve => setTimeout(resolve, loadDelay)) },
        user: { options: { get: () => account, set() {} } },
        Api: function () {
            this.postWithToken = (_token, args) => ({
                done(callback) { saved.push(JSON.parse(args.optionvalue)); callback(); return { fail() {} }; }
            });
        }
    };
    w.eval(stateSource);
    w.eval(old ? oldSection : section);
    hooks.forEach(callback => callback([w.document.querySelector('.mw-parser-output')]));
    await w.EQLUserState.ready();
    await new Promise(resolve => setTimeout(resolve, 0));
    const input = w.document.querySelector('.eql-sky-reward-input');
    const checkbox = w.document.querySelector('.checkbox-list li');
    const snapshot = () => Object.fromEntries(Array.from({ length: w.localStorage.length }, (_, i) => {
        const key = w.localStorage.key(i); return [key, w.localStorage.getItem(key)];
    }));
    return {
        w, input, checkbox, saved, snapshot,
        change(value) { input.value = value; input.dispatchEvent(new w.Event('input', { bubbles: true })); },
        tick() { checkbox.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); },
        close() { dom.window.close(); }
    };
}
(async () => {
    // Reproduce the actual old failure: injected hover stats change the storage key.
    let page = await visit({ old: true, hover: true });
    page.change('17'); page.tick();
    const oldLocal = page.snapshot(); page.close();
    page = await visit({ old: true, local: oldLocal });
    assert.equal(page.input.value, '', 'old reward numbers disappear when tooltip text differs');
    assert.equal(page.checkbox.classList.contains('checked'), true, 'checkboxes survive the same change');
    page.close();

    // Preserve the old key when its rendered identity can still be recognized.
    page = await visit({ hover: true, local: oldLocal });
    assert.equal(page.input.value, '17', 'migrate an existing tooltip-derived number');
    const fixedLocal = page.snapshot(); page.close();
    page = await visit({ local: fixedLocal, label: 'A renamed display label' });
    assert.equal(page.input.value, '17', 'stable item title survives hover and label changes');
    assert.equal(page.checkbox.classList.contains('checked'), true);
    assert.equal(page.input.getAttribute('aria-label').includes('AC:'), false);
    const key = page.input.getAttribute('data-eql-state-key');
    page.close();
    page = await visit({ local: fixedLocal, href: '/index.php?title=Mask_of_Song' });
    assert.equal(page.input.getAttribute('data-eql-state-key'), key, 'pretty and index.php titles have the same identity');
    assert.equal(page.input.value, '17');
    for (const value of ['0', '-2', '29', '']) {
        page.change(value);
        const restartLocal = page.snapshot(); page.close();
        page = await visit({ local: restartLocal, hover: true });
        assert.equal(page.input.value, value, 'preserve numeric/cleared values across a fresh DOM');
        assert.equal(page.input.getAttribute('data-eql-state-key'), key);
    }
    page.close();

    const legacyLocal = { 'eql-plane-of-sky-reward-number:Mask of Song': '42' };
    page = await visit({ local: legacyLocal });
    assert.equal(page.input.value, '42', 'migrate the original browser-only key');
    const anon = page.snapshot(); page.close();
    page = await visit({ user: 123, local: anon });
    assert.equal(page.input.value, '42', 'first-login migration keeps anonymous reward progress');
    page.change('7');
    // Backgrounding flushes the account option through the actual state service.
    Object.defineProperty(page.w.document, 'visibilityState', { value: 'hidden' });
    page.w.document.dispatchEvent(new page.w.Event('visibilitychange'));
    const account = JSON.stringify(page.saved.at(-1));
    assert.equal(page.saved.at(-1).values[key], '7', 'API:Options receives account changes');
    assert.equal(page.snapshot()[localKey], anon[localKey], 'account saves never overwrite anonymous state');
    page.close();
    page = await visit({ user: 123, account, local: legacyLocal, hover: true });
    assert.equal(page.input.value, '7', 'existing account option wins over different browser progress');
    page.close();
    page = await visit({ user: 123, hover: true, account: oldLocal[localKey], local: anon, loadDelay: 25 });
    assert.equal(page.input.value, '17', 'migrate old account field keys without importing anonymous values');
    page.close();
    page = await visit({ user: 123, account: JSON.stringify({ page: 'Plane_of_Sky', values: {} }), local: anon });
    assert.equal(page.input.value, '', 'an existing empty account bucket must not import browser progress');
    page.close();
    page = await visit({ local: anon });
    assert.equal(page.input.value, '42', 'returning to anonymous restores its independent snapshot');
    page.close();
    console.log('Reproduced old tooltip-key failure; stable reward IDs, legacy migration, restart persistence, and account isolation pass.');
})().catch(error => { console.error(error); process.exit(1); });

