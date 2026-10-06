/* EQLImmersive: Item effect spell hovers.
 * Lazy-loads spell examine tooltips for .itemeff links on item pages.
 * Uses SpellHoverLink template via API parse, caches results. */
/* =====================================================
   EQL item effect spell hovers

   Enables spell examine hovers on item stat effects marked as:
   [[Earthcall|<span class='itemeff'>Earthcall</span>]]

   The spell tooltip is lazy-loaded through the existing
   Template:SpellHoverLink / Module:SpellHover path, so item pages
   do not pre-render hidden spell windows.
   ===================================================== */

(function (mw) {
	'use strict';

	var cache = {};
	var pending = {};
	var activeElement = null;
	var activeSpellName = '';
	var lastMouseEvent = null;

	function escapeHtml(value) {
		var div = document.createElement('div');
		div.textContent = String(value || '');
		return div.innerHTML;
	}

	function getContainer() {
		var container = document.getElementById('eqlItemEffectSpellHoverContainer');
		var content;

		if (container) {
			return container;
		}

		container = document.createElement('div');
		container.id = 'eqlItemEffectSpellHoverContainer';

		content = document.createElement('div');
		content.id = 'eqlItemEffectSpellHoverContent';

		container.appendChild(content);
		document.body.appendChild(container);

		return container;
	}

	function getContent() {
		getContainer();
		return document.getElementById('eqlItemEffectSpellHoverContent');
	}

	function showContainer() {
		getContainer().classList.add('eql-itemeff-hover-visible');
	}

	function hideContainer() {
		var container = getContainer();
		var content = getContent();

		container.classList.remove('eql-itemeff-hover-visible');
		content.innerHTML = '';
		activeElement = null;
		activeSpellName = '';
		lastMouseEvent = null;
	}

	function positionContainer(event) {
		var container = getContainer();
		var padding = 12;
		var offset = 18;
		var width;
		var height;
		var x;
		var y;

		if (!event) {
			return;
		}

		width = container.offsetWidth || 590;
		height = container.offsetHeight || 260;

		x = event.clientX + offset;
		y = event.clientY + offset;

		if (x + width + padding > window.innerWidth) {
			x = event.clientX - width - offset;
		}

		if (y + height + padding > window.innerHeight) {
			y = event.clientY - height - offset;
		}

		if (x < padding) {
			x = padding;
		}

		if (y < padding) {
			y = padding;
		}

		container.style.left = x + 'px';
		container.style.top = y + 'px';
	}

	function suppressNativeTitle(element) {
		var link = element && element.closest ? element.closest('a') : null;

		if (element && element.getAttribute && element.getAttribute('title')) {
			element.dataset.eqlOriginalTitle = element.getAttribute('title');
			element.removeAttribute('title');
		}

		if (link && link.getAttribute('title')) {
			link.dataset.eqlOriginalTitle = link.getAttribute('title');
			link.removeAttribute('title');
		}
	}

	function titleFromHref(link) {
		var href;
		var url;
		var title;

		if (!link || !link.getAttribute('href')) {
			return '';
		}

		href = link.getAttribute('href');

		try {
			url = new URL(href, window.location.origin);
			title = url.searchParams.get('title');

			if (title) {
				return title.replace(/_/g, ' ');
			}

			title = decodeURIComponent(url.pathname.replace(/^\/+/, ''));

			if (title.indexOf('index.php/') === 0) {
				title = title.replace(/^index\.php\//, '');
			}

			if (title && title !== 'index.php') {
				return title.replace(/_/g, ' ');
			}
		} catch (e) {}

		return '';
	}

	function getSpellName(element) {
		var link = element && element.closest ? element.closest('a') : null;
		var title = '';
		var text = '';

		if (link) {
			title = link.dataset.eqlOriginalTitle || link.getAttribute('title') || '';

			if (title) {
				return title.replace(/_/g, ' ').trim();
			}

			title = titleFromHref(link);

			if (title) {
				return title.trim();
			}
		}

		text = element ? (element.textContent || '') : '';

		return text.replace(/\s+/g, ' ').trim();
	}

	function getParsedText(response) {
		if (!response || !response.parse || !response.parse.text) {
			return '';
		}

		if (typeof response.parse.text === 'string') {
			return response.parse.text;
		}

		if (typeof response.parse.text['*'] === 'string') {
			return response.parse.text['*'];
		}

		return '';
	}

	function extractSpellHoverHtml(rawHtml) {
		var wrap = document.createElement('div');
		var parserOutput;
		var spellWindow;
		var hiddenHover;

		wrap.innerHTML = rawHtml || '';

		wrap.querySelectorAll(
			'#toc, .toc, .mw-toc, .vector-toc, .toctitle, .mw-editsection'
		).forEach(function (node) {
			node.remove();
		});

		parserOutput = wrap.querySelector('.mw-parser-output');

		if (parserOutput) {
			wrap = parserOutput;
		}

		spellWindow = wrap.querySelector('.spell-examine-window');

		if (spellWindow) {
			return spellWindow.outerHTML;
		}

		hiddenHover = wrap.querySelector('span.hb');

		if (hiddenHover) {
			return hiddenHover.innerHTML;
		}

		return wrap.innerHTML;
	}

	function renderLoading(spellName) {
		getContent().innerHTML =
			'<div class="eql-itemeff-hover-loading">Loading spell: ' +
			escapeHtml(spellName) +
			'</div>';
	}

	function renderError(spellName, message) {
		getContent().innerHTML =
			'<div class="eql-itemeff-hover-error">Could not load spell hover for ' +
			escapeHtml(spellName) +
			'.' +
			(message ? '<br>' + escapeHtml(message) : '') +
			'</div>';
	}

	function spellHoverWikitext(spellName) {
		return '{{SpellHoverLink|' + spellName + '}}';
	}

	function loadSpellHover(spellName) {
		var api;

		if (cache[spellName]) {
			return Promise.resolve(cache[spellName]);
		}

		if (pending[spellName]) {
			return pending[spellName];
		}

		api = new mw.Api();

		pending[spellName] = api.post({
			action: 'parse',
			format: 'json',
			title: mw.config.get('wgPageName') || 'Special:Blank',
			contentmodel: 'wikitext',
			text: spellHoverWikitext(spellName),
			prop: 'text',
			disablelimitreport: 1,
			disableeditsection: 1,
			disabletoc: 1
		}).then(function (response) {
			var html = extractSpellHoverHtml(getParsedText(response));

			if (!html) {
				throw new Error('The parser returned no spell hover HTML.');
			}

			cache[spellName] = html;
			delete pending[spellName];

			return html;
		}, function (code, details) {
			var message = code || 'request failed';

			if (details && details.error && details.error.info) {
				message = details.error.info;
			}

			delete pending[spellName];
			throw new Error(message);
		});

		return pending[spellName];
	}

	function onEnter(event) {
		var element = event.target.closest && event.target.closest('.itemeff');
		var spellName;

		if (!element || (event.relatedTarget && element.contains(event.relatedTarget))) {
			return;
		}

		spellName = getSpellName(element);

		if (!spellName) {
			return;
		}

		activeElement = element;
		activeSpellName = spellName;
		lastMouseEvent = event;

		suppressNativeTitle(element);
		renderLoading(spellName);
		showContainer();
		positionContainer(event);

		loadSpellHover(spellName).then(function (html) {
			if (activeElement !== element || activeSpellName !== spellName) {
				return;
			}

			getContent().innerHTML = html;
			positionContainer(lastMouseEvent || event);
		}).catch(function (error) {
			if (activeElement !== element || activeSpellName !== spellName) {
				return;
			}

			renderError(spellName, error && error.message ? error.message : '');
			positionContainer(lastMouseEvent || event);
		});
	}

	function onMove(event) {
		var element = event.target.closest && event.target.closest('.itemeff');

		if (!element || element !== activeElement) {
			return;
		}

		lastMouseEvent = event;
		positionContainer(event);
	}

	function onLeave(event) {
		var element = event.target.closest && event.target.closest('.itemeff');

		if (!element || element !== activeElement) {
			return;
		}

		if (event.relatedTarget && element.contains(event.relatedTarget)) {
			return;
		}

		hideContainer();
	}

	function initItemEffectSpellHovers(root) {
		root = root || document;

		root.querySelectorAll('.itemeff').forEach(function (element) {
			if (element.dataset.eqlItemEffReady === '1') {
				return;
			}

			element.dataset.eqlItemEffReady = '1';
			suppressNativeTitle(element);
		});
	}

	function init() {
		initItemEffectSpellHovers(document);

		document.addEventListener('mouseover', onEnter, true);
		document.addEventListener('mousemove', onMove, true);
		document.addEventListener('mouseout', onLeave, true);

		if (mw.hook) {
			mw.hook('wikipage.content').add(function ($content) {
				initItemEffectSpellHovers($content && $content[0] ? $content[0] : document);
			});
		}
	}

	if (!window.mw || !mw.loader) {
		return;
	}

	mw.loader.using(['mediawiki.api', 'mediawiki.util']).then(init);
}(mediaWiki));
