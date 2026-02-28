(function () {
	'use strict';

	var RUNTIME_KEY = '__sentientRealtimeSuggestionsRuntime';
	var FIELD_ID_REGEX = /field_(\d+)_(\d+(?:\.\d+)?)$/;

	if (!window.sentientFormsRealtimeSuggestions || !window.sentientFormsRealtimeSuggestions.forms) {
		return;
	}

	if (!window[RUNTIME_KEY]) {
		window[RUNTIME_KEY] = {
			forms: {}
		};
	}

	function asArray(value) {
		return Array.isArray(value) ? value : [];
	}

	function normalizeFieldId(value) {
		if (value === null || value === undefined) {
			return '';
		}
		return String(value).trim();
	}

	function createFormState(config, formElement) {
		return {
			config: config,
			formElement: formElement,
			mappingStates: {},
			widget: null,
			isOpen: true,
			lastGlobalError: null,
			lastUpdatedAt: null,
			lastObservedPage: 1,
			pageProbeTimerId: null
		};
	}

	function ensureWidget(formState) {
		if (formState.widget) {
			return formState.widget;
		}

		var widget = document.createElement('section');
		widget.className = 'sentient-forms-realtime-widget';
		widget.setAttribute('aria-label', 'Sentient Forms Suggestions');
		widget.dataset.formId = String(formState.config.form_id);

		widget.innerHTML = '' +
			'<header class="sentient-forms-realtime-widget__header">' +
				'<div class="sentient-forms-realtime-widget__title-wrap">' +
					'<h3 class="sentient-forms-realtime-widget__title">Suggestions</h3>' +
					'<p class="sentient-forms-realtime-widget__subtitle" data-role="subtitle">Idle</p>' +
				'</div>' +
				'<button type="button" class="sentient-forms-realtime-widget__toggle" data-role="toggle" aria-expanded="true">Hide</button>' +
			'</header>' +
			'<div class="sentient-forms-realtime-widget__body" data-role="body">' +
				'<div class="sentient-forms-realtime-widget__actions">' +
					'<button type="button" class="sentient-forms-realtime-widget__refresh" data-role="refresh">Refresh suggestions</button>' +
				'</div>' +
				'<p class="sentient-forms-realtime-widget__metering" data-role="metering" hidden></p>' +
				'<div class="sentient-forms-realtime-widget__error" data-role="error" hidden></div>' +
				'<ul class="sentient-forms-realtime-widget__list" data-role="list"></ul>' +
				'<p class="sentient-forms-realtime-widget__empty" data-role="empty">No visible suggestions right now.</p>' +
			'</div>';

		document.body.appendChild(widget);
		formState.widget = widget;

		var toggleButton = widget.querySelector('[data-role="toggle"]');
		var refreshButton = widget.querySelector('[data-role="refresh"]');

		toggleButton.addEventListener('click', function () {
			formState.isOpen = !formState.isOpen;
			renderWidget(formState);
		});

		refreshButton.addEventListener('click', function () {
			runMappings(formState, {
				reason: 'manual_refresh',
				manual: true,
				eventFieldId: ''
			});
		});

		widget.addEventListener('click', function (event) {
			var target = event.target;
			if (!(target instanceof HTMLElement)) {
				return;
			}

			if (!target.matches('[data-role="focus-field"]')) {
				return;
			}

			var fieldId = normalizeFieldId(target.getAttribute('data-field-id'));
			if (!fieldId) {
				return;
			}

			focusField(formState.formElement, formState.config.form_id, fieldId);
		});

		return widget;
	}

	function collectCurrentPage(formElement, formId) {
		var pageNodes = formElement.querySelectorAll('.gform_page[id^="gform_page_' + formId + '_"]');
		var detectedPage = 0;

		pageNodes.forEach(function (pageNode) {
			if (!(pageNode instanceof HTMLElement)) {
				return;
			}
			if (!isElementVisible(pageNode)) {
				return;
			}

			var pageMatch = pageNode.id.match(new RegExp('gform_page_' + formId + '_(\\d+)'));
			if (!pageMatch) {
				return;
			}

			var parsedPage = Math.max(1, parseInt(pageMatch[1], 10) || 1);
			if (parsedPage > detectedPage) {
				detectedPage = parsedPage;
			}
		});

		if (detectedPage > 0) {
			return detectedPage;
		}

		var sourceInput = formElement.querySelector('#gform_source_page_number_' + formId);
		if (sourceInput && sourceInput.value) {
			return Math.max(1, parseInt(sourceInput.value, 10) || 1);
		}

		return 1;
	}

	function isElementVisible(element) {
		if (!(element instanceof HTMLElement)) {
			return false;
		}

		var current = element;
		while (current instanceof HTMLElement) {
			if (current.hidden) {
				return false;
			}

			var inlineStyle = (current.getAttribute('style') || '').toLowerCase();
			if (
				inlineStyle.indexOf('display:none') >= 0 ||
				inlineStyle.indexOf('display: none') >= 0 ||
				inlineStyle.indexOf('visibility:hidden') >= 0 ||
				inlineStyle.indexOf('visibility: hidden') >= 0
			) {
				return false;
			}

			var style = window.getComputedStyle(current);
			if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
				return false;
			}

			current = current.parentElement;
		}

		return true;
	}

	function collectVisibleFieldIds(formElement, formId) {
		var fields = formElement.querySelectorAll('.gfield[id^="field_' + formId + '_"]');
		var visible = [];

		fields.forEach(function (field) {
			if (!isElementVisible(field)) {
				return;
			}
			var match = field.id.match(FIELD_ID_REGEX);
			if (!match) {
				return;
			}
			var fieldId = normalizeFieldId(match[2]);
			if (fieldId) {
				visible.push(fieldId);
			}
		});

		return Array.from(new Set(visible));
	}

	function collectKnownValues(formElement) {
		var values = {};
		var fields = formElement.querySelectorAll('input[name^="input_"], select[name^="input_"], textarea[name^="input_"]');

		fields.forEach(function (field) {
			if (!(field instanceof HTMLElement) || field.hasAttribute('disabled')) {
				return;
			}

			var name = field.getAttribute('name') || '';
			var fieldId = normalizeFieldId(name.replace(/^input_/, '').replace(/_/g, '.'));
			if (!fieldId) {
				return;
			}

			if (field instanceof HTMLInputElement && (field.type === 'checkbox' || field.type === 'radio')) {
				if (!field.checked) {
					return;
				}
				if (!Array.isArray(values[fieldId])) {
					values[fieldId] = [];
				}
				values[fieldId].push(field.value);
				return;
			}

			values[fieldId] = field.value;
		});

		return values;
	}

	function collectFutureFieldManifest(config, currentPage) {
		return asArray(config.field_manifest).filter(function (fieldMeta) {
			var pageIndex = parseInt(fieldMeta.page_index, 10) || 1;
			return pageIndex > currentPage;
		});
	}

	function extractEventFieldId(target, formId) {
		if (!(target instanceof HTMLElement)) {
			return '';
		}

		var fieldWrapper = target.closest('.gfield[id^="field_' + formId + '_"]');
		if (fieldWrapper && fieldWrapper.id) {
			var wrapperMatch = fieldWrapper.id.match(FIELD_ID_REGEX);
			if (wrapperMatch) {
				return normalizeFieldId(wrapperMatch[2]);
			}
		}

		var name = target.getAttribute('name') || '';
		if (name.indexOf('input_') === 0) {
			return normalizeFieldId(name.replace(/^input_/, '').replace(/_/g, '.'));
		}

		return '';
	}

	function shouldTriggerMapping(mapping, eventFieldId, reason, manual) {
		if (manual) {
			return true;
		}

		if (reason === 'page_change') {
			return true;
		}

		var checkpoints = asArray(mapping.checkpoint_field_ids).map(normalizeFieldId).filter(Boolean);
		if (!checkpoints.length) {
			return true;
		}

		if (!eventFieldId) {
			return false;
		}

		return checkpoints.indexOf(eventFieldId) >= 0;
	}

	function mappingStateFor(formState, mappingId) {
		if (!formState.mappingStates[mappingId]) {
			formState.mappingStates[mappingId] = {
				inFlight: false,
				lastRunAt: 0,
				timerId: null,
				suggestions: [],
				error: null,
				meta: null
			};
		}
		return formState.mappingStates[mappingId];
	}

	function scheduleMapping(formState, mapping, triggerContext) {
		var mappingId = normalizeFieldId(mapping.mapping_id);
		if (!mappingId) {
			return;
		}

		if (!shouldTriggerMapping(mapping, triggerContext.eventFieldId, triggerContext.reason, triggerContext.manual)) {
			return;
		}

		var state = mappingStateFor(formState, mappingId);
		if (state.timerId) {
			window.clearTimeout(state.timerId);
		}

		var debounceMs = Math.max(100, parseInt(mapping.debounce_ms, 10) || 600);
		state.timerId = window.setTimeout(function () {
			runMapping(formState, mapping, {
				manual: triggerContext.manual,
				reason: triggerContext.reason
			});
		}, debounceMs);
	}

	function runMapping(formState, mapping, triggerOptions) {
		var mappingId = normalizeFieldId(mapping.mapping_id);
		var state = mappingStateFor(formState, mappingId);
		var now = Date.now();
		var cooldownMs = Math.max(0, parseInt(mapping.cooldown_ms, 10) || 0);
		var manual = !!(triggerOptions && triggerOptions.manual);
		var reason = normalizeFieldId(triggerOptions && triggerOptions.reason).toLowerCase();
		var bypassCooldown = manual || reason === 'page_change';

		if (!bypassCooldown && state.lastRunAt > 0 && now - state.lastRunAt < cooldownMs) {
			renderWidget(formState);
			return;
		}

		if (state.inFlight) {
			return;
		}

		state.inFlight = true;
		state.error = null;
		formState.lastGlobalError = null;
		renderWidget(formState);

		var currentPage = collectCurrentPage(formState.formElement, formState.config.form_id);
		var knownValues = collectKnownValues(formState.formElement);
		var visibleFieldIds = collectVisibleFieldIds(formState.formElement, formState.config.form_id);
		var futureFieldManifest = collectFutureFieldManifest(formState.config, currentPage);
		var totalPages = parseInt(formState.config.total_pages, 10) || 1;
		var executionRequestId = 'rt-' + mappingId + '-' + now + '-' + Math.random().toString(16).slice(2, 10);

		var payload = {
			mapping_id: mappingId,
			execution_request_id: executionRequestId,
			all_known_field_values: knownValues,
			visible_field_ids: visibleFieldIds,
			current_page_index: currentPage,
			total_pages: totalPages,
			future_field_manifest: futureFieldManifest
		};

		window.fetch(formState.config.suggest_endpoint_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-Sentient-Forms-Suggest-Nonce': formState.config.nonce
			},
			credentials: 'same-origin',
			body: JSON.stringify(payload)
		})
			.then(function (response) {
				if (!response.ok) {
					return response.json().then(function (payload) {
						var message = payload && payload.message ? payload.message : 'Suggestion request failed.';
						throw new Error(message);
					});
				}
				return response.json();
			})
			.then(function (payload) {
				var suggestions = payload && Array.isArray(payload.suggestions) ? payload.suggestions : [];
				state.suggestions = suggestions;
				state.meta = payload && payload.meta ? payload.meta : null;
				state.lastRunAt = Date.now();
				formState.lastUpdatedAt = state.lastRunAt;
			})
			.catch(function (error) {
				state.error = error && error.message ? error.message : 'Suggestion request failed.';
				formState.lastGlobalError = state.error;
			})
			.finally(function () {
				state.inFlight = false;
				renderWidget(formState);
			});
	}

	function focusField(formElement, formId, fieldId) {
		var fieldWrapper = formElement.querySelector('#field_' + formId + '_' + fieldId.replace('.', '_'));
		if (!(fieldWrapper instanceof HTMLElement)) {
			fieldWrapper = formElement.querySelector('#field_' + formId + '_' + fieldId);
		}
		if (!(fieldWrapper instanceof HTMLElement)) {
			return;
		}

		fieldWrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
		var focusable = fieldWrapper.querySelector('input, select, textarea, button');
		if (focusable instanceof HTMLElement) {
			window.setTimeout(function () {
				focusable.focus();
			}, 100);
		}
	}

	function flattenVisibleSuggestions(formState) {
		var visibleFieldIds = collectVisibleFieldIds(formState.formElement, formState.config.form_id);
		var visibleSet = new Set(visibleFieldIds.map(normalizeFieldId));
		var items = [];

		asArray(formState.config.mappings).forEach(function (mapping) {
			var mappingId = normalizeFieldId(mapping.mapping_id);
			var state = mappingStateFor(formState, mappingId);
			asArray(state.suggestions).forEach(function (suggestion) {
				if (!suggestion || typeof suggestion !== 'object') {
					return;
				}
				var fieldId = normalizeFieldId(suggestion.field_id || suggestion.jump_target_field_id);
				if (!fieldId || !visibleSet.has(fieldId)) {
					return;
				}
				if (suggestion.is_suppressed) {
					return;
				}
				items.push({
					mappingId: mappingId,
					mappingLabel: mapping.action_name_label || mapping.central_action_id || mappingId,
					suggestion: suggestion
				});
			});
		});

		return items;
	}

	function formatTimestamp(value) {
		if (!value) {
			return 'Idle';
		}
		var date = new Date(value);
		return 'Updated ' + date.toLocaleTimeString();
	}

	function summarizeMetering(formState) {
		var latestState = null;
		Object.keys(formState.mappingStates).forEach(function (mappingId) {
			var state = formState.mappingStates[mappingId];
			if (!state || !state.meta || typeof state.meta !== 'object') {
				return;
			}
			if (!latestState || (state.lastRunAt || 0) > (latestState.lastRunAt || 0)) {
				latestState = state;
			}
		});

		if (!latestState || !latestState.meta) {
			return '';
		}

		var meta = latestState.meta;
		var credits = null;
		if (typeof meta.credits_debited === 'number') {
			credits = meta.credits_debited;
		} else if (meta.pricing && typeof meta.pricing.credits_debited_final === 'number') {
			credits = meta.pricing.credits_debited_final;
		}

		var correlationId = normalizeFieldId(meta.correlation_id || meta.execution_request_id);
		var parts = [];
		if (credits !== null) {
			parts.push('Credits: ' + credits);
		}
		if (correlationId) {
			parts.push('Run: ' + correlationId);
		}

		return parts.join(' \u00B7 ');
	}

	function renderWidget(formState) {
		var widget = ensureWidget(formState);
		var body = widget.querySelector('[data-role="body"]');
		var subtitle = widget.querySelector('[data-role="subtitle"]');
		var toggleButton = widget.querySelector('[data-role="toggle"]');
		var metering = widget.querySelector('[data-role="metering"]');
		var list = widget.querySelector('[data-role="list"]');
		var empty = widget.querySelector('[data-role="empty"]');
		var error = widget.querySelector('[data-role="error"]');

		var anyInFlight = Object.values(formState.mappingStates).some(function (state) {
			return state.inFlight;
		});

		subtitle.textContent = anyInFlight ? 'Checking...' : formatTimestamp(formState.lastUpdatedAt);
		toggleButton.textContent = formState.isOpen ? 'Hide' : 'Show';
		toggleButton.setAttribute('aria-expanded', formState.isOpen ? 'true' : 'false');
		body.hidden = !formState.isOpen;

		var meteringSummary = summarizeMetering(formState);
		if (meteringSummary) {
			metering.hidden = false;
			metering.textContent = meteringSummary;
		} else {
			metering.hidden = true;
			metering.textContent = '';
		}

		if (formState.lastGlobalError) {
			error.hidden = false;
			error.textContent = formState.lastGlobalError;
		} else {
			error.hidden = true;
			error.textContent = '';
		}

		var suggestions = flattenVisibleSuggestions(formState);
		list.innerHTML = '';

		if (!suggestions.length) {
			empty.hidden = false;
			return;
		}

		empty.hidden = true;
		suggestions.forEach(function (item) {
			var severity = normalizeFieldId(item.suggestion.severity || 'info').toLowerCase();
			if (['critical', 'warning', 'info'].indexOf(severity) < 0) {
				severity = 'info';
			}

			var listItem = document.createElement('li');
			listItem.className = 'sentient-forms-realtime-widget__item';
			listItem.innerHTML = '' +
				'<span class="sentient-forms-realtime-widget__severity sentient-forms-realtime-widget__severity--' + severity + '">' + severity + '</span>' +
				'<div class="sentient-forms-realtime-widget__content">' +
					'<p class="sentient-forms-realtime-widget__mapping">' + item.mappingLabel + '</p>' +
					'<p class="sentient-forms-realtime-widget__message"></p>' +
				'</div>' +
				'<button type="button" class="sentient-forms-realtime-widget__focus" data-role="focus-field" data-field-id="' +
					String(item.suggestion.jump_target_field_id || item.suggestion.field_id || '').replace(/"/g, '&quot;') +
				'">Focus</button>';

			var message = listItem.querySelector('.sentient-forms-realtime-widget__message');
			message.textContent = item.suggestion.message || '';
			list.appendChild(listItem);
		});
	}

	function runMappings(formState, triggerContext) {
		asArray(formState.config.mappings).forEach(function (mapping) {
			if (!mapping || typeof mapping !== 'object') {
				return;
			}
			if (triggerContext.reason === 'manual_refresh' && mapping.manual_refresh_enabled === false) {
				return;
			}
			scheduleMapping(formState, mapping, triggerContext);
		});
	}

	function runPageChangeMappingsIfNeeded(formState) {
		var currentPage = collectCurrentPage(formState.formElement, formState.config.form_id);
		if (currentPage === formState.lastObservedPage) {
			return false;
		}

		formState.lastObservedPage = currentPage;
		runMappings(formState, {
			reason: 'page_change',
			manual: false,
			eventFieldId: ''
		});

		return true;
	}

	function schedulePageChangeProbe(formState) {
		if (formState.pageProbeTimerId) {
			window.clearTimeout(formState.pageProbeTimerId);
			formState.pageProbeTimerId = null;
		}

		var attempts = 0;
		var maxAttempts = 8;

		function probe() {
			attempts += 1;
			if (runPageChangeMappingsIfNeeded(formState) || attempts >= maxAttempts) {
				formState.pageProbeTimerId = null;
				return;
			}

			formState.pageProbeTimerId = window.setTimeout(probe, 120);
		}

		formState.pageProbeTimerId = window.setTimeout(probe, 80);
	}

	function initializeForm(config) {
		if (!config || typeof config !== 'object') {
			return;
		}

		var formId = parseInt(config.form_id, 10);
		if (!formId) {
			return;
		}

		if (window[RUNTIME_KEY].forms[formId]) {
			return;
		}

		var formElement = document.getElementById('gform_' + formId);
		if (!(formElement instanceof HTMLElement)) {
			return;
		}

		var formState = createFormState(config, formElement);
		formState.lastObservedPage = collectCurrentPage(formElement, formId);
		window[RUNTIME_KEY].forms[formId] = formState;
		ensureWidget(formState);
		renderWidget(formState);

		// Gravity Forms preview/page navigation can reload the document on non-initial pages.
		// If runtime boots on page > 1, trigger a page_change run immediately.
		if (formState.lastObservedPage > 1) {
			window.setTimeout(function () {
				runMappings(formState, {
					reason: 'page_change',
					manual: false,
					eventFieldId: ''
				});
			}, 0);
		}

		formElement.addEventListener(
			'blur',
			function (event) {
				runMappings(formState, {
					reason: 'field_blur',
					manual: false,
					eventFieldId: extractEventFieldId(event.target, formId)
				});
			},
			true
		);

		formElement.addEventListener(
			'change',
			function (event) {
				runMappings(formState, {
					reason: 'field_change',
					manual: false,
					eventFieldId: extractEventFieldId(event.target, formId)
				});
			}
		);

		formElement.addEventListener(
			'click',
			function (event) {
				var target = event.target;
				if (!(target instanceof HTMLElement)) {
					return;
				}
				if (!target.matches('.gform_next_button, .gform_previous_button')) {
					return;
				}

				schedulePageChangeProbe(formState);
			},
			true
		);

		if (window.jQuery) {
			window.jQuery(document).on('gform_page_loaded.sentientFormsRealtime', function (_event, loadedFormId) {
				if (parseInt(loadedFormId, 10) !== formId) {
					return;
				}

				schedulePageChangeProbe(formState);
			});
		}
	}

	function boot() {
		var formsConfig = window.sentientFormsRealtimeSuggestions.forms;
		Object.keys(formsConfig).forEach(function (formIdKey) {
			initializeForm(formsConfig[formIdKey]);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	if (window.jQuery) {
		window.jQuery(document).on('gform_post_render.sentientFormsRealtime', function (_event, formId) {
			var config = window.sentientFormsRealtimeSuggestions.forms[String(formId)] || window.sentientFormsRealtimeSuggestions.forms[formId];
			if (config) {
				initializeForm(config);
			}
		});
	}
})();
