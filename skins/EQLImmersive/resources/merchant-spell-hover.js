/* EQLImmersive: Merchant spell hover hydration.
 * Bulk-loads spell hovers for all 'Spell:' items in merchant sold-items lists.
 * Uses the existing #itemHoverContainer for display. */
/* =====================================================
   EQL Wiki — merchant spell hover hydration
   Layout-stable overlay version.

   Loads all merchant spell hovers on page entry, but does
   NOT insert hover payloads into the merchant list.
   The visible list remains unchanged.
   ===================================================== */

(function (mw, $) {
	'use strict';

	var merchantSpellHoverCache = {};
	var hideTimer = null;

	function getParsedHtml(response) {
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

	function titleFromLink(anchor) {
		var href;
		var url;
		var title;

		title = anchor.getAttribute('title') || '';

		if (title) {
			return title.replace(/_/g, ' ').trim();
		}

		href = anchor.getAttribute('href') || '';

		try {
			url = new URL(href, window.location.origin);

			title = url.searchParams.get('title');

			if (title) {
				return title.replace(/_/g, ' ').trim();
			}

			title = decodeURIComponent(url.pathname.replace(/^\/+/, ''));

			if (title && title !== 'index.php') {
				return title.replace(/_/g, ' ').trim();
			}
		} catch (e) {}

		return '';
	}

	function escapeTemplateValue(value) {
		return String(value || '')
			.replace(/\|/g, '{{!}}')
			.replace(/\n/g, ' ')
			.trim();
	}

	function buildWikitext(items) {
		return items.map(function (item, index) {
			return '<span class="eql-merchant-spell-hover-fragment" data-eql-merchant-spell-index="' + index + '">' +
				'{{SpellHoverLink|' + escapeTemplateValue(item.spellTitle) + '}}' +
				'</span>';
		}).join('\n');
	}

	function ensureHoverContainer() {
		var $container = $('#itemHoverContainer');

		if ($container.length) {
			return $container;
		}

		$container = $(
			'<div id="itemHoverContainer">' +
				'<div id="itemHoverContent"></div>' +
			'</div>'
		);

		$('body').append($container);

		return $container;
	}

	function positionHover(e) {
		var $container = ensureHoverContainer();
		var mousex = e.pageX + 20;
		var mousey = e.pageY + 20;
		var tipWidth = $container.outerWidth() || 590;
		var tipHeight = $container.outerHeight() || 240;
		var tipVisX;
		var tipVisY;

		tipVisX = $(window).width() - (mousex + tipWidth);
		tipVisY = $(window).height() - (mousey + tipHeight);

		if (tipVisX < 20) {
			if (tipWidth > e.pageX - 20) {
				mousex = 0;
			} else {
				mousex = e.pageX - tipWidth - 20;
			}
		}

		if (tipVisY < 20) {
			mousey = e.pageY - tipHeight - 20;
		}

		$container.css({
			top: mousey,
			left: mousex
		});
	}

	function showMerchantSpellHover(anchor, e) {
		var key = anchor.getAttribute('data-eql-merchant-spell-hover-key');
		var html = key ? merchantSpellHoverCache[key] : '';
		var $container;

		if (!html) {
			return;
		}

		if (hideTimer) {
			clearTimeout(hideTimer);
			hideTimer = null;
		}

		$container = ensureHoverContainer();

		$('#itemHoverContent').html(html);

		positionHover(e);

		$container.css('display', 'block');
	}

	function hideMerchantSpellHover() {
		if (hideTimer) {
			clearTimeout(hideTimer);
		}

		hideTimer = setTimeout(function () {
			ensureHoverContainer().css('display', 'none');
		}, 0);
	}

	function extractHoverHtml($fragment) {
		var $hover = $fragment.find('span.hb').first();

		if ($hover.length) {
			return $hover.html();
		}

		/* Fallback if SpellHoverLink output changes and returns the hover body directly. */
		if ($fragment.find('.spell-examine-window, .spelltopbg, .spellbg').length) {
			return $fragment.html();
		}

		return '';
	}

	function hydrateMerchantSpellHovers($root) {
		var items = [];
		var seen = {};
		var api;

		$root = $root && $root.length ? $root : $(document);

		$root.find('.merchant-page-items-sold a').each(function () {
			var anchor = this;
			var $anchor = $(anchor);
			var label = $.trim($anchor.text());
			var rowText = $.trim($anchor.closest('li').text());
			var spellTitle;
			var key;

			if ($anchor.attr('data-eql-merchant-spell-hover-scanned') === '1') {
				return;
			}

			/*
			 * Supports both formats:
			 *   [[Fury|Spell: Fury]]
			 *   Spell: [[Fury|Fury]]
			 */
			if (!/^Spell:\s*/i.test(label) && !/^Spell:\s*/i.test(rowText)) {
				return;
			}

			spellTitle = titleFromLink(anchor);

			if (!spellTitle) {
				return;
			}

			key = spellTitle;

			$anchor.attr('data-eql-merchant-spell-hover-scanned', '1');
			$anchor.attr('data-eql-merchant-spell-hover-key', key);

			if (seen[key] !== undefined) {
				items[seen[key]].anchors.push($anchor);
				return;
			}

			seen[key] = items.length;

			items.push({
				spellTitle: spellTitle,
				key: key,
				anchors: [$anchor]
			});
		});

		if (!items.length) {
			return;
		}

		api = new mw.Api();

		api.post({
			action: 'parse',
			format: 'json',
			title: mw.config.get('wgPageName'),
			contentmodel: 'wikitext',
			text: buildWikitext(items),
			prop: 'text',
			disablelimitreport: 1,
			disableeditsection: 1
		}).done(function (response) {
			var html = getParsedHtml(response);
			var $parsed;

			if (!html) {
				return;
			}

			$parsed = $('<div>').html(html);

			items.forEach(function (item, index) {
				var $fragment = $parsed.find('[data-eql-merchant-spell-index="' + index + '"]').first();
				var hoverHtml = extractHoverHtml($fragment);

				if (!hoverHtml) {
					return;
				}

				merchantSpellHoverCache[item.key] = hoverHtml;

				item.anchors.forEach(function ($anchor) {
					$anchor.attr('data-eql-merchant-spell-hover-ready', '1');
				});
			});
		});
	}

	function bindMerchantSpellHoverEvents() {
		$(document)
			.off('mouseenter.eqlMerchantSpellHover')
			.on('mouseenter.eqlMerchantSpellHover', '.merchant-page-items-sold a[data-eql-merchant-spell-hover-ready="1"]', function (e) {
				showMerchantSpellHover(this, e);
			});

		$(document)
			.off('mousemove.eqlMerchantSpellHover')
			.on('mousemove.eqlMerchantSpellHover', '.merchant-page-items-sold a[data-eql-merchant-spell-hover-ready="1"]', function (e) {
				positionHover(e);
			});

		$(document)
			.off('mouseleave.eqlMerchantSpellHover')
			.on('mouseleave.eqlMerchantSpellHover', '.merchant-page-items-sold a[data-eql-merchant-spell-hover-ready="1"]', function () {
				hideMerchantSpellHover();
			});

		$(document)
			.off('mouseenter.eqlMerchantSpellHoverContainer')
			.on('mouseenter.eqlMerchantSpellHoverContainer', '#itemHoverContainer', function () {
				if (hideTimer) {
					clearTimeout(hideTimer);
					hideTimer = null;
				}
			});

		$(document)
			.off('mouseleave.eqlMerchantSpellHoverContainer')
			.on('mouseleave.eqlMerchantSpellHoverContainer', '#itemHoverContainer', function () {
				hideMerchantSpellHover();
			});
	}

	function init() {
		ensureHoverContainer();
		bindMerchantSpellHoverEvents();
		hydrateMerchantSpellHovers($(document));
	}

	mw.loader.using(['mediawiki.api', 'jquery']).then(function () {
		$(init);
	});

	if (mw.hook) {
		mw.hook('wikipage.content').add(function ($content) {
			hydrateMerchantSpellHovers($content);
		});
	}
}(mediaWiki, jQuery));
