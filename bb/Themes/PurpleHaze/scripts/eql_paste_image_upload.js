(function () {
	'use strict';

	var UPLOAD_URL = '/bb/eql_inline_image_upload.php';
	var FINALIZE_URL = '/bb/eql_inline_image_finalize.php';
	var MAX_IMAGE_SIZE_BYTES = 10 * 1024 * 1024;

	var allowedTypes = {
		'image/jpeg': true,
		'image/png': true,
		'image/gif': true,
		'image/webp': true
	};

	var boundTargets = new WeakMap();
	var lastActiveTarget = null;
	var replayingSubmit = false;

	function getCandidateTextareas() {
		return Array.prototype.slice.call(document.querySelectorAll([
			'textarea[name="message"]',
			'textarea#message',
			'textarea[name*="message"]',
			'textarea[name*="quick"]',
			'textarea[id*="message"]',
			'textarea[id*="quick"]',
			'textarea[id*="editor"]',
			'textarea.sceditor',
			'textarea.editor'
		].join(',')));
	}

	function getEditorInstanceForTextarea(textarea) {
		if (!textarea || !window.sceditor) {
			return null;
		}

		try {
			return window.sceditor.instance(textarea);
		} catch (e) {
			return null;
		}
	}

	function makeTarget(textarea) {
		return {
			textarea: textarea || null,
			editor: getEditorInstanceForTextarea(textarea)
		};
	}

	function rememberTarget(target) {
		if (target && (target.textarea || target.editor)) {
			lastActiveTarget = target;
		}
	}

	function getTargetValue(target) {
		if (!target) {
			return '';
		}

		if (target.editor) {
			try {
				return target.editor.val() || '';
			} catch (e) {}
		}

		if (target.textarea) {
			return target.textarea.value || '';
		}

		return '';
	}

	function setTargetValue(target, value) {
		if (!target) {
			return false;
		}

		if (target.editor) {
			try {
				target.editor.val(value);

				if (typeof target.editor.updateOriginal === 'function') {
					target.editor.updateOriginal();
				}

				return true;
			} catch (e) {}
		}

		if (target.textarea) {
			target.textarea.value = value;
			return true;
		}

		return false;
	}

	function syncAllEditorsToTextareas() {
		getCandidateTextareas().forEach(function (textarea) {
			var editor = getEditorInstanceForTextarea(textarea);

			if (editor && typeof editor.updateOriginal === 'function') {
				try {
					editor.updateOriginal();
				} catch (e) {}
			}
		});
	}

	function getFallbackTarget() {
		if (lastActiveTarget) {
			return lastActiveTarget;
		}

		var active = document.activeElement;

		if (active && active.tagName && active.tagName.toLowerCase() === 'textarea') {
			return makeTarget(active);
		}

		var textareas = getCandidateTextareas();

		if (textareas.length) {
			return makeTarget(textareas[0]);
		}

		return {
			textarea: null,
			editor: null
		};
	}

	function getEventTarget(event) {
		if (event && event.currentTarget && boundTargets.has(event.currentTarget)) {
			var directTarget = boundTargets.get(event.currentTarget);
			rememberTarget(directTarget);
			return directTarget;
		}

		var node = event && event.target ? event.target : null;

		while (node && node !== document) {
			if (boundTargets.has(node)) {
				var mappedTarget = boundTargets.get(node);
				rememberTarget(mappedTarget);
				return mappedTarget;
			}

			node = node.parentNode;
		}

		return getFallbackTarget();
	}

	function insertIntoTarget(text, target) {
		target = target || getFallbackTarget();

		var editor = target.editor || null;
		var textarea = target.textarea || null;

		if (editor) {
			try {
				editor.insertText(text);
				rememberTarget(target);
				return;
			} catch (e) {}

			try {
				editor.insert(text);
				rememberTarget(target);
				return;
			} catch (e) {}
		}

		if (textarea) {
			var start = textarea.selectionStart || 0;
			var end = textarea.selectionEnd || 0;
			var value = textarea.value || '';

			textarea.value = value.substring(0, start) + text + value.substring(end);
			textarea.selectionStart = textarea.selectionEnd = start + text.length;
			textarea.focus();

			rememberTarget(target);
		}
	}

	function replaceInTarget(searchText, replacementText, target) {
		target = target || getFallbackTarget();

		var current = getTargetValue(target);

		if (current && current.indexOf(searchText) !== -1) {
			setTargetValue(target, current.replace(searchText, replacementText));
			return;
		}

		insertIntoTarget(replacementText, target);
	}

	function localImageUpload(file) {
		var form = new FormData();
		form.append('image', file);

		return fetch(UPLOAD_URL, {
			method: 'POST',
			body: form,
			credentials: 'same-origin',
			headers: {
				'X-Requested-With': 'XMLHttpRequest'
			}
		})
		.then(function (response) {
			return response.json();
		})
		.then(function (result) {
			if (result && result.success && result.url) {
				return result.url;
			}

			throw new Error(result && result.message ? result.message : 'Upload failed.');
		});
	}

	function finalizeImages(urls) {
		return fetch(FINALIZE_URL, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: JSON.stringify({
				urls: urls
			})
		})
		.then(function (response) {
			return response.json();
		})
		.then(function (result) {
			if (result && result.success) {
				return result.map || {};
			}

			throw new Error(result && result.message ? result.message : 'Could not finalize uploaded images.');
		});
	}

	function cleanFileName(name) {
		return String(name || 'image').replace(/[\[\]\r\n]/g, '');
	}

	function uploadAndInsertImage(file, target) {
		if (!file || !allowedTypes[file.type]) {
			return false;
		}

		if (file.size > MAX_IMAGE_SIZE_BYTES) {
			alert('That image is too large. Please use an image under 10 MB.');
			return true;
		}

		rememberTarget(target);

		var placeholder = '[Uploading image: ' + cleanFileName(file.name) + '-' + Date.now() + ']';

		insertIntoTarget('\n' + placeholder + '\n', target);

		localImageUpload(file)
			.then(function (url) {
				replaceInTarget(placeholder, '[img]' + url + '[/img]', target);
			})
			.catch(function (error) {
				replaceInTarget(placeholder, '[Image upload failed]', target);
				alert(error && error.message ? error.message : 'Problem uploading image.');
			});

		return true;
	}

	function getImageFileFromClipboard(event) {
		var clipboardData = event.clipboardData || window.clipboardData;

		if (!clipboardData || !clipboardData.items) {
			return null;
		}

		for (var i = 0; i < clipboardData.items.length; i++) {
			var item = clipboardData.items[i];

			if (item.kind === 'file' && allowedTypes[item.type]) {
				return item.getAsFile();
			}
		}

		return null;
	}

	function getImageFileFromDrop(event) {
		var dataTransfer = event.dataTransfer;

		if (!dataTransfer || !dataTransfer.files || !dataTransfer.files.length) {
			return null;
		}

		for (var i = 0; i < dataTransfer.files.length; i++) {
			var file = dataTransfer.files[i];

			if (file && allowedTypes[file.type]) {
				return file;
			}
		}

		return null;
	}

	function handlePasteEvent(event) {
		var file = getImageFileFromClipboard(event);

		if (!file) {
			return;
		}

		var target = getEventTarget(event);

		if (uploadAndInsertImage(file, target)) {
			event.preventDefault();
			event.stopPropagation();
			return false;
		}
	}

	function handleDropEvent(event) {
		var file = getImageFileFromDrop(event);

		if (!file) {
			return;
		}

		var target = getEventTarget(event);

		if (uploadAndInsertImage(file, target)) {
			event.preventDefault();
			event.stopPropagation();
			return false;
		}
	}

	function bindElement(element, label, target) {
		if (!element || !target || (!target.textarea && !target.editor)) {
			return;
		}

		boundTargets.set(element, target);

		if (element.__eqlPasteUploadBound) {
			return;
		}

		element.__eqlPasteUploadBound = true;

		['focus', 'mousedown', 'click', 'keyup', 'touchstart'].forEach(function (eventName) {
			element.addEventListener(eventName, function () {
				rememberTarget(target);
			}, true);
		});

		element.addEventListener('paste', handlePasteEvent, true);
		element.addEventListener('drop', handleDropEvent, true);
		element.addEventListener('dragover', function (event) {
			event.preventDefault();
		}, true);

		if (window.console && console.log) {
			console.log('EQL paste image upload bound:', label);
		}
	}

	function bindDocumentFallback() {
		if (document.__eqlPasteUploadDocumentFallbackBound) {
			return;
		}

		document.__eqlPasteUploadDocumentFallbackBound = true;

		document.addEventListener('paste', handlePasteEvent, true);
		document.addEventListener('drop', handleDropEvent, true);
		document.addEventListener('dragover', function (event) {
			if (getCandidateTextareas().length) {
				event.preventDefault();
			}
		}, true);

		console.log('EQL paste image upload bound: document fallback');
	}

	function bindToTextareas() {
		getCandidateTextareas().forEach(function (textarea, index) {
			var target = makeTarget(textarea);
			bindElement(textarea, 'textarea ' + (textarea.id || textarea.name || index), target);
		});
	}

	function bindToSceditors() {
		getCandidateTextareas().forEach(function (textarea, index) {
			var editor = getEditorInstanceForTextarea(textarea);

			if (!editor) {
				return;
			}

			var target = {
				textarea: textarea,
				editor: editor
			};

			try {
				bindElement(editor.getBody(), 'sceditor body for ' + (textarea.id || textarea.name || index), target);
			} catch (e) {}

			try {
				var container = editor.getContentAreaContainer();
				bindElement(container, 'sceditor container for ' + (textarea.id || textarea.name || index), target);

				var iframe = container.querySelector('iframe');

				if (iframe && iframe.contentDocument) {
					bindElement(iframe.contentDocument, 'sceditor iframe document for ' + (textarea.id || textarea.name || index), target);

					if (iframe.contentDocument.body) {
						bindElement(iframe.contentDocument.body, 'sceditor iframe body for ' + (textarea.id || textarea.name || index), target);
					}
				}
			} catch (e) {}
		});
	}

	function extractTemporaryUrls(text) {
		var urls = [];
		var regex = /https:\/\/eqlwiki\.com\/bb\/uploads\/inline_images\/tmp\/[0-9]+\/[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)/gi;
		var match;

		while ((match = regex.exec(text)) !== null) {
			if (urls.indexOf(match[0]) === -1) {
				urls.push(match[0]);
			}
		}

		return urls;
	}

	function getTargetsInForm(form) {
		var targets = [];

		Array.prototype.slice.call(form.querySelectorAll('textarea')).forEach(function (textarea) {
			targets.push(makeTarget(textarea));
		});

		return targets;
	}

	function collectTemporaryUrlsFromForm(form) {
		var urls = [];

		syncAllEditorsToTextareas();

		getTargetsInForm(form).forEach(function (target) {
			var found = extractTemporaryUrls(getTargetValue(target));

			found.forEach(function (url) {
				if (urls.indexOf(url) === -1) {
					urls.push(url);
				}
			});
		});

		return urls;
	}

	function replaceTemporaryUrlsInForm(form, map) {
		getTargetsInForm(form).forEach(function (target) {
			var value = getTargetValue(target);

			if (!value) {
				return;
			}

			Object.keys(map).forEach(function (oldUrl) {
				var newUrl = map[oldUrl];

				if (newUrl) {
					value = value.split(oldUrl).join(newUrl);
				}
			});

			setTargetValue(target, value);
		});

		syncAllEditorsToTextareas();
	}

	function findNearestForm(element) {
		while (element && element !== document) {
			if (element.tagName && element.tagName.toLowerCase() === 'form') {
				return element;
			}

			element = element.parentNode;
		}

		return null;
	}

	function submitFormWithButton(form, button) {
		if (button && button.name) {
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = button.name;
			hidden.value = button.value || '1';
			hidden.setAttribute('data-eql-submit-proxy', '1');
			form.appendChild(hidden);
		}

		if (button && typeof form.requestSubmit === 'function') {
			form.requestSubmit(button);
		} else if (typeof form.requestSubmit === 'function') {
			form.requestSubmit();
		} else {
			form.submit();
		}
	}

	function looksLikeSubmitButton(element) {
		if (!element || !element.tagName) {
			return false;
		}

		var tag = element.tagName.toLowerCase();

		if (tag !== 'button' && tag !== 'input') {
			return false;
		}

		var type = (element.getAttribute('type') || '').toLowerCase();
		var name = (element.getAttribute('name') || '').toLowerCase();
		var value = (element.getAttribute('value') || element.textContent || '').toLowerCase().trim();

		return (
			type === 'submit' ||
			name === 'post' ||
			name === 'save' ||
			name === 'submit' ||
			value === 'post' ||
			value === 'save' ||
			value === 'save draft' ||
			value === 'reply'
		);
	}

	function finalizeFormAndContinue(form, button) {
		var urls = collectTemporaryUrlsFromForm(form);

		if (!urls.length) {
			return false;
		}

		finalizeImages(urls)
			.then(function (map) {
				replaceTemporaryUrlsInForm(form, map);

				replayingSubmit = true;

				if (button) {
					button.click();
				} else {
					submitFormWithButton(form, null);
				}

				replayingSubmit = false;
			})
			.catch(function (error) {
				alert(error && error.message ? error.message : 'Could not finalize pasted images. Please try again.');
			});

		return true;
	}

	function bindFormFinalizers() {
		Array.prototype.slice.call(document.querySelectorAll('form')).forEach(function (form) {
			if (form.__eqlInlineImageFinalizeBound || !form.querySelector('textarea')) {
				return;
			}

			form.__eqlInlineImageFinalizeBound = true;

			form.addEventListener('submit', function (event) {
				if (replayingSubmit) {
					return;
				}

				var urls = collectTemporaryUrlsFromForm(form);

				if (!urls.length) {
					return;
				}

				event.preventDefault();
				event.stopPropagation();

				finalizeImages(urls)
					.then(function (map) {
						replaceTemporaryUrlsInForm(form, map);

						replayingSubmit = true;
						submitFormWithButton(form, event.submitter || null);
						replayingSubmit = false;
					})
					.catch(function (error) {
						alert(error && error.message ? error.message : 'Could not finalize pasted images. Please try submitting again.');
					});
			}, true);
		});
	}

	function bindGlobalClickFinalizer() {
		if (document.__eqlInlineImageClickFinalizeBound) {
			return;
		}

		document.__eqlInlineImageClickFinalizeBound = true;

		document.addEventListener('click', function (event) {
			if (replayingSubmit) {
				return;
			}

			var button = event.target;

			if (!looksLikeSubmitButton(button)) {
				return;
			}

			var form = findNearestForm(button);

			if (!form || !form.querySelector('textarea')) {
				return;
			}

			var handled = finalizeFormAndContinue(form, button);

			if (handled) {
				event.preventDefault();
				event.stopImmediatePropagation();
			}
		}, true);
	}

	function bindAll() {
		bindDocumentFallback();
		bindToTextareas();
		bindToSceditors();
		bindFormFinalizers();
		bindGlobalClickFinalizer();
	}

	function init() {
		bindAll();

		var intervalAttempts = 0;
		var interval = window.setInterval(function () {
			intervalAttempts++;
			bindAll();

			if (intervalAttempts >= 160) {
				window.clearInterval(interval);
			}
		}, 250);

		var observer = new MutationObserver(function () {
			bindAll();
		});

		observer.observe(document.body, {
			childList: true,
			subtree: true
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();