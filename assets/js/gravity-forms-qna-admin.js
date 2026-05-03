(function () {
	'use strict';

	function closest(element, selector) {
		if (!element || typeof element.closest !== 'function') {
			return null;
		}

		return element.closest(selector);
	}

	function setTemporaryLabel(button, label) {
		var previous = button.getAttribute('data-sf-qna-default-label') || button.textContent;
		button.textContent = label;
		window.setTimeout(function () {
			button.textContent = previous;
		}, 1400);
	}

	function findSource(container, sourceName) {
		if (!container) {
			return null;
		}

		return container.querySelector('[data-sf-qna-copy-source="' + sourceName + '"]');
	}

	function copyText(text) {
		if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
			return navigator.clipboard.writeText(text);
		}

		return new Promise(function (resolve, reject) {
			var textarea = document.createElement('textarea');
			textarea.value = text;
			textarea.setAttribute('readonly', 'readonly');
			textarea.style.position = 'fixed';
			textarea.style.top = '-1000px';
			document.body.appendChild(textarea);
			textarea.select();

			try {
				document.execCommand('copy');
				resolve();
			} catch (error) {
				reject(error);
			} finally {
				document.body.removeChild(textarea);
			}
		});
	}

	function activatePanelView(panel, viewName) {
		panel.querySelectorAll('[data-sf-qna-view-tab]').forEach(function (tab) {
			var active = tab.getAttribute('data-sf-qna-view-tab') === viewName;
			tab.classList.toggle('is-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		});

		panel.querySelectorAll('[data-sf-qna-view-panel]').forEach(function (view) {
			view.hidden = view.getAttribute('data-sf-qna-view-panel') !== viewName;
		});
	}

	function downloadCsv(button, text) {
		var blob = new Blob([text], { type: 'text/csv;charset=utf-8' });
		var url = URL.createObjectURL(blob);
		var link = document.createElement('a');
		link.href = url;
		link.download = 'sentient-forms-realtime-qna.csv';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		window.setTimeout(function () {
			URL.revokeObjectURL(url);
		}, 0);
		setTemporaryLabel(button, 'Downloaded');
	}

	document.addEventListener('click', function (event) {
		var viewTab = closest(event.target, '[data-sf-qna-view-tab]');
		if (viewTab) {
			var panel = closest(viewTab, '[data-sf-qna-panel]');
			if (panel) {
				activatePanelView(panel, viewTab.getAttribute('data-sf-qna-view-tab') || 'cards');
			}
			return;
		}

		var rowToggle = closest(event.target, '[data-sf-qna-row-toggle]');
		if (rowToggle) {
			var row = closest(rowToggle, '[data-sf-qna-row]');
			var preview = row ? row.querySelector('[data-sf-qna-row-preview]') : null;
			if (preview) {
				var expanded = preview.hidden;
				preview.hidden = !expanded;
				rowToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			}
			return;
		}

		var copyButton = closest(event.target, '[data-sf-qna-copy]');
		if (copyButton) {
			var copyContainer = closest(copyButton, '[data-sf-qna-panel]') || closest(copyButton, '[data-sf-qna-row]');
			var copySource = findSource(copyContainer, copyButton.getAttribute('data-sf-qna-copy') || 'summary');
			if (copySource) {
				copyText(copySource.value).then(function () {
					setTemporaryLabel(copyButton, 'Copied');
				}).catch(function () {
					setTemporaryLabel(copyButton, 'Copy failed');
				});
			}
			return;
		}

		var exportButton = closest(event.target, '[data-sf-qna-export]');
		if (exportButton) {
			var exportContainer = closest(exportButton, '[data-sf-qna-panel]') || closest(exportButton, '[data-sf-qna-row]');
			var exportSource = findSource(exportContainer, exportButton.getAttribute('data-sf-qna-export') || 'csv');
			if (exportSource) {
				downloadCsv(exportButton, exportSource.value);
			}
		}
	});
}());

