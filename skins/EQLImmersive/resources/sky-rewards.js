/* =========================================================
 * Plane of Sky - quest reward number fields
 *
 * This section belongs in MediaWiki:Common.js after EQLUserState.
 * Item titles identify rewards independently of hover text, item
 * levels, stats, and row order. The existing state service owns
 * anonymous/account persistence and first-login migration.
 * ========================================================= */
(function () {
    'use strict';

    var PAGE_NAME = 'Plane_of_Sky';
    var LEGACY_STORAGE_PREFIX = 'eql-plane-of-sky-reward-number:';
    if (mw.config.get('wgPageName') !== PAGE_NAME) {
        return;
    }

    function normalise(text) {
        return String(text || '').replace(/\s+/g, ' ').trim();
    }

    function rewardIdentity(cell) {
        var oldText = normalise(cell.textContent);
        var clone = cell.cloneNode(true);
        clone.querySelectorAll(
            '.hb, .eql-item-hover, .eql-hover-card, .mw-editsection, ' +
            'script, style, button, input, textarea, select'
        ).forEach(function (node) { node.remove(); });
        var label = normalise(clone.textContent);
        var titles = [];
        clone.querySelectorAll('a[href]').forEach(function (link) {
            var url;
            var href = link.getAttribute('href');
            if (!href || href.charAt(0) === '#') { return; }
            try { url = new URL(href, window.location.href); }
            catch (error) { return; }
            if (url.origin !== window.location.origin) { return; }
            var title = url.searchParams.get('title');
            if (!title) {
                try { title = decodeURIComponent(url.pathname).replace(/^\/+/, ''); }
                catch (error) { return; }
            }
            title = normalise(title.replace(/_/g, ' '));
            if (title && !/^(?:File|Image|Special|Category):/i.test(title)) {
                titles.push(title);
            }
        });
        titles = Array.from(new Set(titles)).sort();
        return {
            label: label,
            persist: titles.length ? 'sky-reward:item:' + titles.join('|') : 'sky-reward:text:' + label,
            previousNames: Array.from(new Set([oldText, label]))
        };
    }

    function migrateReward(identity) {
        var state = window.EQLUserState;
        var key = 'field:' + state.hashKey(PAGE_NAME + '|' + identity.persist);
        if (state.has(key)) { return; }
        identity.previousNames.some(function (name) {
            if (!name) { return false; }
            var previousKey = 'field:' + state.hashKey(PAGE_NAME + '|sky-reward:' + name);
            // get() enforces account isolation for direct legacy browser keys.
            var value = state.get(previousKey, undefined, {
                legacyKey: LEGACY_STORAGE_PREFIX + name
            });
            if (typeof value === 'undefined') { return false; }
            state.set(key, value);
            return true;
        });
    }

    function addRewardFields() {
        document.querySelectorAll('table.eoTable3').forEach(function (table) {
            table.querySelectorAll('tr').forEach(function (row) {
                var cells = Array.from(row.children).filter(function (child) {
                    return child.tagName === 'TD';
                });
                if (cells.length !== 5 || cells[4].querySelector('.eql-sky-reward-number')) {
                    return;
                }
                var identity = rewardIdentity(cells[0]);
                if (!identity.label) { return; }
                var rewardCell = cells[4];
                var wrapper = document.createElement('div');
                wrapper.className = 'eql-sky-reward-wrap';
                var content = document.createElement('div');
                content.className = 'eql-sky-reward-content';
                while (rewardCell.firstChild) { content.appendChild(rewardCell.firstChild); }
                var numberWrap = document.createElement('div');
                numberWrap.className = 'eql-sky-reward-number';
                var plus = document.createElement('span');
                plus.className = 'eql-sky-reward-plus';
                plus.textContent = '+';
                var input = document.createElement('input');
                input.type = 'number';
                input.step = '1';
                input.inputMode = 'numeric';
                input.className = 'eql-sky-reward-input';
                input.setAttribute('aria-label', 'Stored number for ' + identity.label);
                input.setAttribute('data-eql-persist', identity.persist);

                // Insert only after migration: an earlier content hook may otherwise
                // bind an empty field before the account bucket has been inspected.
                window.EQLUserState.ready().then(function () {
                    migrateReward(identity);
                    numberWrap.appendChild(input);
                    window.EQLPersistentContent.refresh(input);
                });
                numberWrap.appendChild(plus);
                wrapper.appendChild(content);
                wrapper.appendChild(numberWrap);
                rewardCell.appendChild(wrapper);
            });
        });
    }

    if (mw.hook) {
        mw.hook('wikipage.content').add(addRewardFields);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addRewardFields);
    } else {
        addRewardFields();
    }
})();
