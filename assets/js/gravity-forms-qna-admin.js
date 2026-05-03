(function () {
	'use strict';

	function closest(element, selector) {
		if (!element || typeof element.closest !== 'function') {
			return null;
		}

		return element.closest(selector);
	}

	function setTemporaryLabel(button, label) {
		var labelTarget = button.querySelector('[data-sf-qna-button-label]');
		var previous = button.getAttribute('data-sf-qna-default-label') || (labelTarget ? labelTarget.textContent : button.textContent);

		if (labelTarget) {
			labelTarget.textContent = label;
		} else {
			button.textContent = label;
		}

		window.setTimeout(function () {
			if (labelTarget) {
				labelTarget.textContent = previous;
			} else {
				button.textContent = previous;
			}
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

	function getCards(panel) {
		return Array.prototype.slice.call(panel.querySelectorAll('[data-sf-qna-card]'));
	}

	function getActiveFilter(panel) {
		var active = panel.querySelector('[data-sf-qna-filter].is-active');

		return active ? active.getAttribute('data-sf-qna-filter') || 'all' : 'all';
	}

	function setCardExpanded(card, expanded) {
		var body = card.querySelector('[data-sf-qna-card-body]');
		var toggle = card.querySelector('[data-sf-qna-card-toggle]');

		card.classList.toggle('is-collapsed', !expanded);

		if (body) {
			body.hidden = !expanded;
		}

		if (toggle) {
			toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			toggle.textContent = expanded ? 'Hide details' : 'Show details';
		}
	}

	function cardMatchesFilter(card, filter) {
		var status = card.getAttribute('data-sf-qna-status') || '';

		if (filter === 'all') {
			return true;
		}

		if (filter === 'open') {
			return status === 'open';
		}

		return status === filter;
	}

	function sortCards(panel) {
		var cardsContainer = panel.querySelector('[data-sf-qna-cards]');
		var sort = panel.querySelector('[data-sf-qna-sort]');
		var mode = sort ? sort.value : 'priority';

		if (!cardsContainer) {
			return;
		}

		getCards(panel).sort(function (left, right) {
			if (mode === 'original') {
				return Number(left.getAttribute('data-sf-qna-original-index') || 0) - Number(right.getAttribute('data-sf-qna-original-index') || 0);
			}

			if (mode === 'status') {
				var leftStatus = left.getAttribute('data-sf-qna-status') || '';
				var rightStatus = right.getAttribute('data-sf-qna-status') || '';
				var statusOrder = { open: 0, answered: 1, completed: 2 };
				var leftOrder = Object.prototype.hasOwnProperty.call(statusOrder, leftStatus) ? statusOrder[leftStatus] : 9;
				var rightOrder = Object.prototype.hasOwnProperty.call(statusOrder, rightStatus) ? statusOrder[rightStatus] : 9;
				var statusComparison = leftOrder - rightOrder;

				return statusComparison || Number(left.getAttribute('data-sf-qna-original-index') || 0) - Number(right.getAttribute('data-sf-qna-original-index') || 0);
			}

			return Number(left.getAttribute('data-sf-qna-priority') || 9) - Number(right.getAttribute('data-sf-qna-priority') || 9) ||
				Number(left.getAttribute('data-sf-qna-original-index') || 0) - Number(right.getAttribute('data-sf-qna-original-index') || 0);
		}).forEach(function (card) {
			cardsContainer.appendChild(card);
		});
	}

	function updateReviewPanel(panel) {
		var filter = getActiveFilter(panel);
		var search = panel.querySelector('[data-sf-qna-search-input]');
		var query = search ? search.value.trim().toLowerCase() : '';
		var visibleCount = 0;

		sortCards(panel);

		getCards(panel).forEach(function (card) {
			var searchable = card.getAttribute('data-sf-qna-search') || '';
			var visible = cardMatchesFilter(card, filter) && (!query || searchable.indexOf(query) !== -1);

			card.hidden = !visible;
			if (visible) {
				visibleCount += 1;
			}
		});

		var empty = panel.querySelector('[data-sf-qna-empty-results]');
		if (empty) {
			empty.hidden = visibleCount !== 0;
		}
	}

	function initializeReviewPanel(panel) {
		if (panel.getAttribute('data-sf-qna-review-initialized') === 'true') {
			return;
		}

		panel.setAttribute('data-sf-qna-review-initialized', 'true');
		panel.querySelectorAll('[data-sf-qna-card]').forEach(function (card) {
			var priority = Number(card.getAttribute('data-sf-qna-priority') || 9);

			setCardExpanded(card, priority <= 1);
		});
		updateReviewPanel(panel);
	}

	function initializeReviewPanels(root) {
		root.querySelectorAll('[data-sf-qna-panel][data-sf-qna-high-volume="true"]').forEach(initializeReviewPanel);
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
		var reviewFilter = closest(event.target, '[data-sf-qna-filter]');
		if (reviewFilter) {
			var reviewPanel = closest(reviewFilter, '[data-sf-qna-panel]');
			if (reviewPanel) {
				reviewPanel.querySelectorAll('[data-sf-qna-filter]').forEach(function (button) {
					var active = button === reviewFilter;
					button.classList.toggle('is-active', active);
					button.setAttribute('aria-pressed', active ? 'true' : 'false');
				});
				updateReviewPanel(reviewPanel);
			}
			return;
		}

		var cardToggle = closest(event.target, '[data-sf-qna-card-toggle]');
		if (cardToggle) {
			var card = closest(cardToggle, '[data-sf-qna-card]');
			if (card) {
				setCardExpanded(card, cardToggle.getAttribute('aria-expanded') !== 'true');
			}
			return;
		}

		var densityButton = closest(event.target, '[data-sf-qna-density]');
		if (densityButton) {
			var densityPanel = closest(densityButton, '[data-sf-qna-panel]');
			if (densityPanel) {
				var compact = !densityPanel.classList.contains('is-compact-density');
				densityPanel.classList.toggle('is-compact-density', compact);
				densityButton.setAttribute('aria-pressed', compact ? 'true' : 'false');
			}
			return;
		}

		var expandAllButton = closest(event.target, '[data-sf-qna-expand-all]');
		if (expandAllButton) {
			var expandPanel = closest(expandAllButton, '[data-sf-qna-panel]');
			var expanding = expandAllButton.getAttribute('aria-pressed') !== 'true';
			if (expandPanel) {
				getCards(expandPanel).forEach(function (card) {
					if (!card.hidden) {
						setCardExpanded(card, expanding);
					}
				});
				expandAllButton.setAttribute('aria-pressed', expanding ? 'true' : 'false');
				expandAllButton.textContent = expanding ? 'Collapse all' : 'Expand all';
			}
			return;
		}

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

	document.addEventListener('input', function (event) {
		var searchInput = closest(event.target, '[data-sf-qna-search-input]');
		if (searchInput) {
			var panel = closest(searchInput, '[data-sf-qna-panel]');
			if (panel) {
				updateReviewPanel(panel);
			}
		}
	});

	document.addEventListener('change', function (event) {
		var sort = closest(event.target, '[data-sf-qna-sort]');
		if (sort) {
			var panel = closest(sort, '[data-sf-qna-panel]');
			if (panel) {
				updateReviewPanel(panel);
			}
		}
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initializeReviewPanels(document);
		});
	} else {
		initializeReviewPanels(document);
	}
}());
