(function () {
	'use strict';

	var RUNTIME_KEY = '__sentientRealtimeSuggestionsRuntime';
	var FIELD_ID_REGEX = /field_(\d+)_(\d+(?:\.\d+)?)$/;

	if (!window.sentientFormsRealtimeSuggestions || !window.sentientFormsRealtimeSuggestions.forms) {
		return;
	}

	if (!window[RUNTIME_KEY]) {
		window[RUNTIME_KEY] = {
			forms: {},
			pendingConfigs: {},
			configErrors: {}
		};
	}
	if (!window[RUNTIME_KEY].pendingConfigs) {
		window[RUNTIME_KEY].pendingConfigs = {};
	}
	if (!window[RUNTIME_KEY].configErrors) {
		window[RUNTIME_KEY].configErrors = {};
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

	function normalizePanelInitialState(value) {
		var normalized = normalizeFieldId(value).toLowerCase();
		return ['open', 'minimized', 'hidden_until_interaction'].indexOf(normalized) >= 0
			? normalized
			: 'minimized';
	}

	function formIdFromConfig(config) {
		var formId = parseInt(config && config.form_id, 10);
		return Number.isFinite(formId) && formId > 0 ? formId : 0;
	}

	function hasFullRuntimeConfig(config) {
		return !!(
			config &&
			typeof config === 'object' &&
			Array.isArray(config.mappings) &&
			normalizeFieldId(config.runtime_config_endpoint_url) &&
			normalizeFieldId(config.suggest_endpoint_url) &&
			normalizeFieldId(config.nonce)
		);
	}

	function fetchRuntimeConfig(config) {
		var formId = formIdFromConfig(config);
		var endpointUrl = normalizeFieldId(config && config.runtime_config_endpoint_url);
		var runtimeConfigToken = normalizeFieldId(config && config.runtime_config_token);
		var headers = {
			Accept: 'application/json'
		};
		if (!formId || !endpointUrl || typeof window.fetch !== 'function') {
			return Promise.resolve(null);
		}

		if (runtimeConfigToken) {
			headers['X-Sentient-Forms-Runtime-Config-Token'] = runtimeConfigToken;
		}

		if (window[RUNTIME_KEY].pendingConfigs[formId]) {
			return window[RUNTIME_KEY].pendingConfigs[formId];
		}

		window[RUNTIME_KEY].pendingConfigs[formId] = window.fetch(endpointUrl, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: headers
		}).then(function (response) {
			return response.text().then(function (bodyText) {
				if (!response.ok) {
					throw new Error(publicRoadblockMessage(response, bodyText));
				}

				try {
					return JSON.parse(bodyText);
				} catch (error) {
					throw new Error('Runtime configuration could not be read.');
				}
			});
		}).then(function (runtimeConfig) {
			if (!hasFullRuntimeConfig(runtimeConfig)) {
				throw new Error('Runtime configuration is incomplete.');
			}
			if (formIdFromConfig(runtimeConfig) !== formId) {
				throw new Error('Runtime configuration does not match this form.');
			}

			if (!runtimeConfig.runtime_config_endpoint_url) {
				runtimeConfig.runtime_config_endpoint_url = endpointUrl;
			}
			window.sentientFormsRealtimeSuggestions.forms[formId] = runtimeConfig;
			return runtimeConfig;
		}).catch(function (error) {
			window[RUNTIME_KEY].configErrors[formId] =
				error && error.message ? error.message : 'Runtime configuration could not be loaded.';
			return null;
		}).finally(function () {
			delete window[RUNTIME_KEY].pendingConfigs[formId];
		});

		return window[RUNTIME_KEY].pendingConfigs[formId];
	}

	function publicSuggestionErrorMessage(message, status) {
		var raw = normalizeFieldId(message);
		if (!raw && status) {
			raw = 'Suggestion request failed with HTTP ' + status + '.';
		}

		if (
			/rest_cookie_invalid_nonce/i.test(raw) ||
			/cookie check failed/i.test(raw) ||
			/invalid nonce/i.test(raw)
		) {
			return 'Suggestions could not refresh because this page security token expired. Reload this page before trying again.';
		}

		if (
			/cloudflare/i.test(raw) ||
			/\berror\s*1015\b/i.test(raw) ||
			/\berror\s*1020\b/i.test(raw) ||
			/access denied/i.test(raw) ||
			/just a moment/i.test(raw) ||
			/site security layer/i.test(raw)
		) {
			return 'Suggestions could not refresh because this request reached the site security layer before WordPress could process it. You can keep filling out the form and try again shortly.';
		}

		if (
			/structured_output/i.test(raw) ||
			/local action schema/i.test(raw) ||
			/provider response did not match/i.test(raw) ||
			/required property/i.test(raw) ||
			/virtual_questions/i.test(raw) ||
			/conditional_decisions/i.test(raw)
		) {
			return 'Suggestions are temporarily unavailable. Try again shortly.';
		}

		return raw || 'Suggestion request failed.';
	}

	function responseHeader(response, name) {
		if (!response || !response.headers || typeof response.headers.get !== 'function') {
			return '';
		}
		return normalizeFieldId(response.headers.get(name));
	}

	function hasCloudflareBlockSignal(text, isHtml) {
		var cloudflareHtmlSignal =
			isHtml &&
			/cloudflare|cf-error|cf-chl|cf-error-details|ray id|attention required|you are unable to access|sorry, you have been blocked/i.test(
				text
			);

		return (
			/\berror\s*1020\b/i.test(text) ||
			/(cloudflare.{0,80}(access denied|request blocked|blocked by|firewall|waf|blocked)|(?:access denied|request blocked|blocked by|firewall|waf|blocked).{0,80}cloudflare)/i.test(
				text
			) ||
			cloudflareHtmlSignal
		);
	}

	function hasCloudflareRateLimitSignal(response, text, isHtml) {
		var lower = normalizeFieldId(text).toLowerCase();
		var cfMitigated = responseHeader(response, 'cf-mitigated').toLowerCase();
		var cloudflareHtmlSignal =
			isHtml && /cloudflare|cf-error|cf-error-details|ray id/i.test(text);

		return (
			/\berror\s*1015\b/i.test(text) ||
			(cfMitigated === 'challenge' && lower.indexOf('rate limit') >= 0) ||
			(cloudflareHtmlSignal &&
				(lower.indexOf('you are being rate limited') >= 0 ||
					lower.indexOf('rate limit') >= 0))
		);
	}

	function publicRoadblockMessage(response, bodyText) {
		var text = normalizeFieldId(bodyText);
		var lower = text.toLowerCase();
		var contentType = responseHeader(response, 'content-type').toLowerCase();
		var isHtml =
			contentType.indexOf('text/html') >= 0 ||
			/<!doctype html|<html|<title/i.test(text);
		var cfMitigated = responseHeader(response, 'cf-mitigated').toLowerCase();
		var hasCloudflareSignal =
			!!responseHeader(response, 'cf-ray') ||
			responseHeader(response, 'server').toLowerCase().indexOf('cloudflare') >= 0 ||
			/cloudflare|cf-error|cf-chl|cf-browser-verification/i.test(text);

		if (cfMitigated === 'challenge') {
			return publicSuggestionErrorMessage('Cloudflare challenge interrupted the request.', response.status);
		}

		if (
			hasCloudflareSignal &&
			hasCloudflareRateLimitSignal(response, text, isHtml)
		) {
			return publicSuggestionErrorMessage('Cloudflare rate limited this request.', response.status);
		}

		if (
			hasCloudflareSignal &&
			(response.status === 403 || response.status === 429) &&
			hasCloudflareBlockSignal(text, isHtml)
		) {
			return publicSuggestionErrorMessage('Cloudflare block interrupted the request.', response.status);
		}

		if (
			isHtml &&
			(response.status === 401 ||
				response.status === 403 ||
				response.status === 406 ||
				response.status === 429 ||
				response.status === 503) &&
			/(access denied|blocked|challenge|captcha|verify you are human|just a moment|rate limit|security)/i.test(text)
		) {
			return publicSuggestionErrorMessage('Site security layer interrupted the request.', response.status);
		}

		return '';
	}

	function runtimeConfigExpiredMessage() {
		return 'Suggestions could not refresh because this cached page is using an expired security token. Reload this page before trying again.';
	}

	function isRuntimeConfigExpired(formState) {
		var expiresAt = parseInt(formState.config && formState.config.config_expires_at, 10);
		if (!Number.isFinite(expiresAt) || expiresAt <= 0) {
			return false;
		}
		return Math.floor(Date.now() / 1000) > expiresAt;
	}

	function resolveInitialPanelState(config) {
		if (
			config &&
			Object.prototype.hasOwnProperty.call(config, 'initial_panel_state')
		) {
			return normalizePanelInitialState(config.initial_panel_state);
		}

		var hasHiddenUntilInteraction = false;
		asArray(config && config.mappings).forEach(function (mapping) {
			if (!mapping || typeof mapping !== 'object') {
				return;
			}

			var state = normalizeFieldId(mapping.initial_panel_state).toLowerCase();
			if (state === 'open') {
				hasHiddenUntilInteraction = false;
				return 'open';
			}
			if (state === 'hidden_until_interaction') {
				hasHiddenUntilInteraction = true;
			}
		});

		if (asArray(config && config.mappings).some(function (mapping) {
			return normalizeFieldId(mapping && mapping.initial_panel_state).toLowerCase() === 'open';
		})) {
			return 'open';
		}

		return hasHiddenUntilInteraction ? 'hidden_until_interaction' : 'minimized';
	}

	function hashString(value) {
		var text = normalizeFieldId(value).toLowerCase();
		var hash = 0;
		for (var i = 0; i < text.length; i += 1) {
			hash = ((hash << 5) - hash) + text.charCodeAt(i);
			hash |= 0;
		}
		return String(Math.abs(hash));
	}

	function createFormState(config, formElement) {
		var initialPanelState = resolveInitialPanelState(config);
		return {
			config: config,
			formElement: formElement,
			mappingStates: {},
			widget: null,
			isOpen: initialPanelState === 'open',
			initialPanelState: initialPanelState,
			hasUserInteracted: false,
			lastGlobalError: null,
			lastUpdatedAt: null,
			lastObservedPage: 1,
			pageProbeTimerId: null,
			pageCheckpointBypass: false,
			pageCheckpointPending: false,
			preSubmitPending: false,
			preSubmissionFilterRegistered: false
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
					'<button type="button" class="sentient-forms-realtime-widget__toggle" data-role="toggle" aria-expanded="false">Show</button>' +
			'</header>' +
			'<div class="sentient-forms-realtime-widget__body" data-role="body">' +
				'<div class="sentient-forms-realtime-widget__actions">' +
					'<button type="button" class="sentient-forms-realtime-widget__refresh" data-role="refresh">Refresh suggestions</button>' +
				'</div>' +
				'<p class="sentient-forms-realtime-widget__metering" data-role="metering" hidden></p>' +
				'<div class="sentient-forms-realtime-widget__error" data-role="error" hidden></div>' +
				'<ul class="sentient-forms-realtime-widget__list" data-role="list"></ul>' +
				'<div class="sentient-forms-realtime-widget__questions" data-role="questions"></div>' +
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

				if (target.matches('[data-role="focus-field"]')) {
					var fieldId = normalizeFieldId(target.getAttribute('data-field-id'));
					if (!fieldId) {
						return;
					}

					focusField(formState.formElement, formState.config.form_id, fieldId);
					return;
				}

				if (target.matches('[data-role="complete-item"]')) {
					var mappingId = normalizeFieldId(target.getAttribute('data-mapping-id'));
					var itemId = normalizeFieldId(target.getAttribute('data-item-id'));
					if (!mappingId || !itemId) {
						return;
					}

					var state = mappingStateFor(formState, mappingId);
					state.completedItems[itemId] = !state.completedItems[itemId];
					state.suggestions = suggestionsFromHistory(state);
					state.virtualQuestions = virtualQuestionsFromHistory(state);
					persistQuestionAnswers(formState);
					renderWidget(formState);
				}
			});

		function handleAnswerInput(event) {
			var target = event.target;
			if (
				!(
					target instanceof HTMLTextAreaElement ||
					target instanceof HTMLInputElement ||
					target instanceof HTMLSelectElement
				) ||
				!target.matches('[data-role="answer-question"]')
			) {
				return;
			}

			var mappingId = normalizeFieldId(target.getAttribute('data-mapping-id'));
			var questionId = normalizeFieldId(target.getAttribute('data-question-id'));
			if (!mappingId || !questionId) {
				return;
			}

				var state = mappingStateFor(formState, mappingId);
				state.virtualAnswers[questionId] = target.value;
				state.completedItems[questionId] = normalizeFieldId(target.value).length > 0;
				state.virtualQuestions = virtualQuestionsFromHistory(state);
				persistQuestionAnswers(formState);
			}

		widget.addEventListener('input', handleAnswerInput);
		widget.addEventListener('change', handleAnswerInput);

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

	function isFinalFormSubmission(formState) {
		var formId = formState && formState.config ? formState.config.form_id : '';
		var targetInput = formState.formElement.querySelector('#gform_target_page_number_' + formId);
		if (!targetInput) {
			return true;
		}

		var targetPage = parseInt(targetInput.value, 10);
		return !Number.isFinite(targetPage) || targetPage <= 0;
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

	function collectAllKnownValues(formElement) {
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

	function normalizeHiddenFieldExposureMode(value) {
		var normalized = normalizeFieldId(value || 'label_hidden').toLowerCase();
		if (['omit_hidden', 'label_hidden', 'label_hidden_value', 'label_value'].indexOf(normalized) >= 0) {
			return normalized;
		}
		return 'label_hidden';
	}

	function hiddenFieldModeIncludesValue(mode) {
		return mode === 'label_hidden_value' || mode === 'label_value';
	}

	function rootFieldId(fieldId) {
		var normalized = normalizeFieldId(fieldId);
		var dotIndex = normalized.indexOf('.');
		return dotIndex > 0 ? normalized.slice(0, dotIndex) : normalized;
	}

	function fieldMetaForValueId(config, fieldId) {
		var normalized = normalizeFieldId(fieldId);
		var rootId = rootFieldId(normalized);
		var manifest = asArray(config.field_manifest);
		for (var index = 0; index < manifest.length; index += 1) {
			var fieldMeta = manifest[index];
			if (!fieldMeta || typeof fieldMeta !== 'object') {
				continue;
			}
			var manifestFieldId = normalizeFieldId(fieldMeta.field_id);
			if (manifestFieldId === normalized || manifestFieldId === rootId) {
				return fieldMeta;
			}
			var inputIds = asArray(fieldMeta.input_ids).map(normalizeFieldId);
			if (inputIds.indexOf(normalized) >= 0) {
				return fieldMeta;
			}
		}
		return null;
	}

	function isVisibleValueField(fieldId, visibleSet) {
		var normalized = normalizeFieldId(fieldId);
		return visibleSet.has(normalized) || visibleSet.has(rootFieldId(normalized));
	}

	function isRealtimeStorageField(mapping, fieldId) {
		var targetFieldId = normalizeFieldId(mapping && mapping.storage_target_field_id);
		if (!targetFieldId || targetFieldId === '__sentient_forms_realtime_qna') {
			return false;
		}
		var normalized = normalizeFieldId(fieldId);
		return normalized === targetFieldId || rootFieldId(normalized) === targetFieldId;
	}

	function buildRealtimeFieldContext(config, mapping, allValues, visibleFieldIds) {
		var mode = normalizeHiddenFieldExposureMode(mapping && mapping.hidden_field_exposure_mode);
		var includeHiddenValues = hiddenFieldModeIncludesValue(mode);
		var visibleSet = new Set(asArray(visibleFieldIds).map(normalizeFieldId).filter(Boolean));
		var knownValues = {};
		var supplementalFieldContext = [];

		Object.keys(allValues).forEach(function (fieldId) {
			var visible = isVisibleValueField(fieldId, visibleSet);
			var storageField = isRealtimeStorageField(mapping, fieldId);
			if (visible && !storageField) {
				knownValues[fieldId] = allValues[fieldId];
				return;
			}

			if (storageField || mode === 'omit_hidden') {
				return;
			}

			var fieldMeta = fieldMetaForValueId(config, fieldId);
			var context = {
				field_id: fieldId,
				label: fieldMeta && fieldMeta.label ? String(fieldMeta.label) : '',
				type: fieldMeta && fieldMeta.type ? String(fieldMeta.type) : '',
				page_index: fieldMeta && fieldMeta.page_index ? parseInt(fieldMeta.page_index, 10) || 1 : 1
			};
			if (mode !== 'label_value') {
				context.hidden = true;
			}
			if (includeHiddenValues) {
				context.value = allValues[fieldId];
				knownValues[fieldId] = allValues[fieldId];
			}
			supplementalFieldContext.push(context);
		});

		return {
			hiddenFieldExposureMode: mode,
			knownValues: knownValues,
			supplementalFieldContext: supplementalFieldContext
		};
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

	function normalizeRefreshMode(mapping) {
		var refreshMode = normalizeFieldId(mapping && mapping.refresh_mode || 'auto').toLowerCase();
		return ['auto', 'checkpoint', 'manual'].indexOf(refreshMode) >= 0 ? refreshMode : 'auto';
	}

	function realtimeBoolean(mapping, key, fallback) {
		return typeof (mapping && mapping[key]) === 'boolean' ? mapping[key] : fallback;
	}

	function mappingAutoRefreshEnabled(mapping) {
		return realtimeBoolean(mapping, 'auto_refresh_enabled', normalizeRefreshMode(mapping) === 'auto');
	}

	function mappingFieldCheckpointsEnabled(mapping) {
		return realtimeBoolean(mapping, 'field_checkpoints_enabled', normalizeRefreshMode(mapping) === 'checkpoint');
	}

	function mappingPageCheckpointsEnabled(mapping) {
		return realtimeBoolean(mapping, 'page_checkpoints_enabled', false);
	}

	function pageCheckpointApplies(mapping, currentPage) {
		if (!mappingPageCheckpointsEnabled(mapping)) {
			return false;
		}

		var page = Math.max(1, parseInt(currentPage, 10) || 1);
		var mode = normalizeFieldId(mapping.page_checkpoint_mode || 'all_pages').toLowerCase();
		if (['all_pages', 'include_pages', 'exclude_pages'].indexOf(mode) < 0) {
			mode = 'all_pages';
		}

		if (mode === 'all_pages') {
			return true;
		}

		var pages = asArray(mapping.page_checkpoint_pages)
			.map(function (item) {
				return Math.max(1, parseInt(item, 10) || 0);
			})
			.filter(Boolean);
		var selected = pages.indexOf(page) >= 0;

		return mode === 'include_pages' ? selected : !selected;
	}

	function shouldTriggerMapping(mapping, eventFieldId, reason, manual, currentPage) {
		if (manual) {
			return true;
		}

		if (reason === 'page_checkpoint' || reason === 'page_change') {
			return pageCheckpointApplies(mapping, currentPage);
		}

		var checkpoints = asArray(mapping.checkpoint_field_ids).map(normalizeFieldId).filter(Boolean);
		if (mappingAutoRefreshEnabled(mapping)) {
			return true;
		}

		if (!mappingFieldCheckpointsEnabled(mapping) || !checkpoints.length || !eventFieldId) {
			return false;
		}

		return checkpoints.indexOf(eventFieldId) >= 0;
	}

	function mappingStateFor(formState, mappingId) {
		if (!formState.mappingStates[mappingId]) {
			formState.mappingStates[mappingId] = {
					inFlight: false,
					inFlightPromise: null,
					lastRunAt: 0,
					timerId: null,
					suggestions: [],
					suggestionHistory: {},
					suggestionOrder: [],
					virtualQuestions: [],
					virtualQuestionHistory: {},
					virtualQuestionOrder: [],
					virtualAnswers: {},
					conditionalDecisions: [],
					conditionalDecisionHistory: {},
					conditionalDecisionOrder: [],
					completedItems: {},
					error: null,
					meta: null
				};
		}
		return formState.mappingStates[mappingId];
	}

	function questionIdFor(mappingId, question, index) {
		var raw = normalizeFieldId(question && question.question_id);
		if (raw) {
			return raw;
		}
		var text = normalizeFieldId(question && question.question).toLowerCase();
		var hash = 0;
		for (var i = 0; i < text.length; i += 1) {
			hash = ((hash << 5) - hash) + text.charCodeAt(i);
			hash |= 0;
		}
		return mappingId + '-q-' + index + '-' + Math.abs(hash);
	}

	function normalizeVirtualQuestions(value, mappingId, previousAnswers) {
		return asArray(value).map(function (item, index) {
			if (!item || typeof item !== 'object') {
				return null;
			}
			var question = normalizeFieldId(item.question);
			if (!question) {
				return null;
			}
			var questionId = questionIdFor(mappingId, item, index);
			return {
				question_id: questionId,
				question: question,
				reason: normalizeFieldId(item.reason),
				target_field_id: normalizeFieldId(item.target_field_id),
				required: item.required === true,
				answer_type: ['short_text', 'long_text', 'choice'].indexOf(normalizeFieldId(item.answer_type)) >= 0
					? normalizeFieldId(item.answer_type)
					: 'long_text',
				choices: asArray(item.choices).map(normalizeFieldId).filter(Boolean),
				answer: previousAnswers[questionId] || ''
			};
			}).filter(Boolean);
	}

	function suggestionIdFor(mappingId, suggestion, index) {
		var raw = normalizeFieldId(suggestion && suggestion.suggestion_id);
		if (raw) {
			return raw;
		}
		var source = [
			suggestion && (suggestion.field_id || suggestion.jump_target_field_id),
			suggestion && suggestion.severity,
			suggestion && suggestion.message
		].map(normalizeFieldId).join(':');
		return mappingId + '-s-' + index + '-' + hashString(source);
	}

	function normalizeSuggestions(value, mappingId, completedItems) {
		return asArray(value).map(function (item, index) {
			if (!item || typeof item !== 'object') {
				return null;
			}
			var message = normalizeFieldId(item.message);
			var fieldId = normalizeFieldId(item.field_id || item.jump_target_field_id);
			if (!message && !fieldId) {
				return null;
			}
			var suggestionId = suggestionIdFor(mappingId, item, index);
			return {
				...item,
				suggestion_id: suggestionId,
				field_id: fieldId,
				jump_target_field_id: normalizeFieldId(item.jump_target_field_id || fieldId),
				severity: normalizeFieldId(item.severity || 'info').toLowerCase(),
				message: message,
				completed: completedItems[suggestionId] === true
			};
		}).filter(Boolean);
	}

	function normalizeConditionalDecisions(value) {
		return asArray(value).map(function (item) {
			if (!item || typeof item !== 'object') {
				return null;
			}
			var conditionKey = normalizeFieldId(item.condition_key);
			if (!conditionKey) {
				return null;
			}
			return {
				decision_id: normalizeFieldId(item.decision_id) || conditionKey,
				condition_key: conditionKey,
				met: item.met === true,
				confidence: typeof item.confidence === 'number' ? item.confidence : null,
				reason: normalizeFieldId(item.reason)
			};
		}).filter(Boolean);
	}

	function rememberVirtualQuestions(state, questions) {
		asArray(questions).forEach(function (question) {
			if (!question || !question.question_id) {
				return;
			}

			if (state.virtualQuestionOrder.indexOf(question.question_id) < 0) {
				state.virtualQuestionOrder.push(question.question_id);
			}

			state.virtualQuestionHistory[question.question_id] = {
				question_id: question.question_id,
				question: question.question,
				reason: question.reason || '',
				target_field_id: question.target_field_id || '',
				required: question.required === true,
				answer_type: question.answer_type || 'long_text',
				choices: asArray(question.choices)
			};
		});
	}

	function rememberSuggestions(state, suggestions) {
		asArray(suggestions).forEach(function (suggestion) {
			if (!suggestion || !suggestion.suggestion_id) {
				return;
			}

			if (state.suggestionOrder.indexOf(suggestion.suggestion_id) < 0) {
				state.suggestionOrder.push(suggestion.suggestion_id);
			}

			state.suggestionHistory[suggestion.suggestion_id] = {
				...suggestion,
				completed: state.completedItems[suggestion.suggestion_id] === true
			};
		});
	}

	function suggestionsFromHistory(state) {
		return asArray(state.suggestionOrder).map(function (suggestionId) {
			var suggestion = state.suggestionHistory[suggestionId];
			if (!suggestion) {
				return null;
			}
			return {
				...suggestion,
				completed: state.completedItems[suggestionId] === true
			};
		}).filter(Boolean);
	}

	function virtualQuestionsFromHistory(state) {
		return asArray(state.virtualQuestionOrder).map(function (questionId) {
			var question = state.virtualQuestionHistory[questionId];
			if (!question) {
				return null;
			}
			return {
				...question,
				answer: state.virtualAnswers[questionId] || '',
				completed: state.completedItems[questionId] === true
			};
		}).filter(Boolean);
	}

	function rememberConditionalDecisions(state, decisions) {
		asArray(decisions).forEach(function (decision) {
			if (!decision || !decision.decision_id) {
				return;
			}

			if (state.conditionalDecisionOrder.indexOf(decision.decision_id) < 0) {
				state.conditionalDecisionOrder.push(decision.decision_id);
			}

			state.conditionalDecisionHistory[decision.decision_id] = decision;
		});
	}

	function serializePanelStateForRequest(formState, mappingId) {
		var state = mappingStateFor(formState, mappingId);
		return {
			suggestions: asArray(state.suggestions).map(function (suggestion) {
				return {
					suggestion_id: suggestion.suggestion_id || '',
					field_id: suggestion.field_id || '',
					message: suggestion.message || '',
					severity: suggestion.severity || '',
					completed: state.completedItems[suggestion.suggestion_id] === true
				};
			}),
			virtual_questions: virtualQuestionsFromHistory(state).map(function (question) {
				return {
					question_id: question.question_id,
					question: question.question,
					reason: question.reason || '',
					target_field_id: question.target_field_id || '',
					required: question.required === true,
					answer_type: question.answer_type || 'long_text',
					choices: asArray(question.choices),
					answer: state.virtualAnswers[question.question_id] || '',
					completed: state.completedItems[question.question_id] === true
				};
			}),
			conditional_decisions: asArray(state.conditionalDecisions)
		};
	}

	function hydrateQuestionAnswers(formState) {
		asArray(formState.config.mappings).forEach(function (mapping) {
			var mappingId = normalizeFieldId(mapping.mapping_id);
			var targetFieldId = normalizeFieldId(mapping.storage_target_field_id);
			if (!mappingId) {
				return;
			}

			var target = targetFieldId
				? findInputForFieldId(formState.formElement, formState.config.form_id, targetFieldId)
				: findOrCreatePersistenceTarget(formState, '');
			if (!target || !target.value) {
				return;
			}

			var stored;
			try {
				stored = JSON.parse(target.value);
			} catch (error) {
				return;
			}

			if (!stored || stored.schema !== 'sentient_forms_realtime_clarification_qna.v1') {
				return;
			}

			var storedMapping = asArray(stored.mappings).find(function (item) {
				return item && normalizeFieldId(item.mapping_id) === mappingId;
			});
			if (!storedMapping) {
				return;
			}

				var state = mappingStateFor(formState, mappingId);
				rememberVirtualQuestions(state, asArray(storedMapping.questions));
				asArray(storedMapping.questions).forEach(function (question) {
				if (!question || !question.question_id) {
					return;
				}

					if (typeof question.answer === 'string') {
						state.virtualAnswers[question.question_id] = question.answer;
					}
					if (question.completed === true) {
						state.completedItems[question.question_id] = true;
					}
				});
				rememberConditionalDecisions(state, asArray(storedMapping.conditional_decisions));
				state.virtualQuestions = virtualQuestionsFromHistory(state);
			});
	}

	function dispatchConditionalDecisions(formState, mapping, decisions) {
		if (!decisions.length) {
			return;
		}
		formState.formElement.dispatchEvent(new CustomEvent('sentientforms:conditional-decisions', {
			bubbles: true,
			detail: {
				form_id: formState.config.form_id,
				source: formState.config.source || 'gravity_forms',
				mapping_id: normalizeFieldId(mapping.mapping_id),
				central_action_id: mapping.central_action_id || '',
				decisions: decisions
			}
		}));
	}

	function scheduleMapping(formState, mapping, triggerContext) {
		var mappingId = normalizeFieldId(mapping.mapping_id);
		if (!mappingId) {
			return;
		}

		if (
			!shouldTriggerMapping(
				mapping,
				triggerContext.eventFieldId,
				triggerContext.reason,
				triggerContext.manual,
				triggerContext.currentPageIndex
			)
		) {
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
		var bypassCooldown = manual || reason === 'page_change' || reason === 'page_checkpoint' || reason === 'pre_submit';
		var timeoutMs = Math.max(0, parseInt(triggerOptions && triggerOptions.timeoutMs, 10) || 0);
		var isPreSubmit = reason === 'pre_submit';

		if (isRuntimeConfigExpired(formState)) {
			var expiredMessage = runtimeConfigExpiredMessage();
			if (!isPreSubmit) {
				state.error = expiredMessage;
				formState.lastGlobalError = expiredMessage;
				renderWidget(formState);
			}
			return Promise.resolve({
				status: 'error',
				reason: 'runtime_config_expired',
				error: expiredMessage
			});
		}

		if (!bypassCooldown && state.lastRunAt > 0 && now - state.lastRunAt < cooldownMs) {
			renderWidget(formState);
			return Promise.resolve({ status: 'skipped', reason: 'cooldown' });
		}

		if (state.inFlight) {
			return state.inFlightPromise || Promise.resolve({ status: 'skipped', reason: 'in_flight' });
		}

		state.inFlight = true;
		state.inFlightPromise = null;
		state.error = null;
		formState.lastGlobalError = null;
		renderWidget(formState);

		var currentPage = collectCurrentPage(formState.formElement, formState.config.form_id);
		var allValues = collectAllKnownValues(formState.formElement);
		var visibleFieldIds = collectVisibleFieldIds(formState.formElement, formState.config.form_id);
		var fieldContext = buildRealtimeFieldContext(formState.config, mapping, allValues, visibleFieldIds);
		var futureFieldManifest = collectFutureFieldManifest(formState.config, currentPage);
		var totalPages = parseInt(formState.config.total_pages, 10) || 1;
		var executionRequestId = 'rt-' + mappingId + '-' + now + '-' + Math.random().toString(16).slice(2, 10);

			var payload = {
				mapping_id: mappingId,
				execution_request_id: executionRequestId,
				request_reason: reason || 'field_change',
				all_known_field_values: fieldContext.knownValues,
				visible_field_ids: visibleFieldIds,
				current_page_index: currentPage,
				total_pages: totalPages,
				future_field_manifest: futureFieldManifest,
				hidden_field_exposure_mode: fieldContext.hiddenFieldExposureMode,
				supplemental_field_context: fieldContext.supplementalFieldContext,
				panel_state: serializePanelStateForRequest(formState, mappingId)
			};

		var headers = {
			'Content-Type': 'application/json',
			'X-Sentient-Forms-Suggest-Nonce': formState.config.nonce
		};
		if (formState.config.rest_nonce) {
			headers['X-WP-Nonce'] = formState.config.rest_nonce;
		}

		var controller = timeoutMs > 0 && typeof window.AbortController === 'function'
			? new window.AbortController()
			: null;
		var timeoutId = controller
			? window.setTimeout(function () {
				controller.abort();
			}, timeoutMs)
			: null;

		var requestOptions = {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify(payload)
		};
		if (controller) {
			requestOptions.signal = controller.signal;
		}

		state.inFlightPromise = window.fetch(formState.config.suggest_endpoint_url, requestOptions)
			.then(function (response) {
				if (!response.ok) {
					return response.text().then(function (bodyText) {
						var payload = null;
						try {
							payload = JSON.parse(bodyText);
						} catch (error) {
							payload = null;
						}

						var roadblockMessage = publicRoadblockMessage(response, bodyText);
						if (roadblockMessage) {
							throw new Error(roadblockMessage);
						}

						var message = payload && (payload.message || (payload.error && payload.error.message))
							? payload.message || payload.error.message
							: 'Suggestion request failed with HTTP ' + response.status + '.';
						throw new Error(publicSuggestionErrorMessage(message, response.status));
					});
				}
				return response.json();
			})
			.then(function (payload) {
					var suggestions = payload && Array.isArray(payload.suggestions) ? payload.suggestions : [];
					var virtualQuestions = payload && Array.isArray(payload.virtual_questions) ? payload.virtual_questions : [];
					var conditionalDecisions = payload && Array.isArray(payload.conditional_decisions) ? payload.conditional_decisions : [];
					var normalizedSuggestions = normalizeSuggestions(
						suggestions,
						mappingId,
						state.completedItems
					);
					rememberSuggestions(state, normalizedSuggestions);
					state.suggestions = suggestionsFromHistory(state);
					var normalizedQuestions = normalizeVirtualQuestions(virtualQuestions, mappingId, state.virtualAnswers);
					rememberVirtualQuestions(state, normalizedQuestions);
					state.virtualQuestions = virtualQuestionsFromHistory(state);
					state.conditionalDecisions = normalizeConditionalDecisions(conditionalDecisions);
					state.virtualQuestions.forEach(function (question) {
						if (typeof state.virtualAnswers[question.question_id] !== 'string') {
							state.virtualAnswers[question.question_id] = question.answer || '';
						}
					});
					rememberConditionalDecisions(state, state.conditionalDecisions);
				state.meta = payload && payload.meta ? payload.meta : null;
				state.lastRunAt = Date.now();
				formState.lastUpdatedAt = state.lastRunAt;
				persistQuestionAnswers(formState);
				dispatchConditionalDecisions(formState, mapping, state.conditionalDecisions);
				return { status: 'success' };
			})
			.catch(function (error) {
				var message = error && error.name === 'AbortError'
					? 'Suggestion request timed out.'
					: publicSuggestionErrorMessage(
						error && error.message ? error.message : 'Suggestion request failed.'
					);
				if (!isPreSubmit) {
					state.error = message;
					formState.lastGlobalError = state.error;
				}
				return { status: 'error', error: message };
			})
			.finally(function () {
				if (timeoutId) {
					window.clearTimeout(timeoutId);
				}
				state.inFlight = false;
				state.inFlightPromise = null;
				renderWidget(formState);
			});

		return state.inFlightPromise;
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

	function findInputForFieldId(formElement, formId, fieldId) {
		var normalized = normalizeFieldId(fieldId);
		if (!normalized) {
			return null;
		}

		var idCandidates = [
			'input_' + formId + '_' + normalized.replace('.', '_'),
			'input_' + formId + '_' + normalized
		];
		for (var i = 0; i < idCandidates.length; i += 1) {
			var byId = document.getElementById(idCandidates[i]);
			if (byId instanceof HTMLInputElement || byId instanceof HTMLTextAreaElement) {
				return byId;
			}
		}

		var nameCandidates = [
			'input_' + normalized,
			'input_' + normalized.replace('.', '_')
		];
		var fields = formElement.querySelectorAll('input, textarea');
		for (var j = 0; j < fields.length; j += 1) {
			var field = fields[j];
			if (
				(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) &&
				nameCandidates.indexOf(field.getAttribute('name') || '') >= 0
			) {
				return field;
			}
		}

		return null;
	}

	function fallbackStorageKey(formState, targetFieldId) {
		var normalized = normalizeFieldId(targetFieldId);
		return normalized ? normalized : '__sentient_forms_realtime_qna';
	}

	function findOrCreatePersistenceTarget(formState, targetFieldId) {
		var normalized = normalizeFieldId(targetFieldId);
		var target = normalized
			? findInputForFieldId(formState.formElement, formState.config.form_id, normalized)
			: null;
		if (target) {
			return target;
		}

		var key = fallbackStorageKey(formState, normalized);
		var existing = formState.formElement.querySelector(
			'input[data-sentient-forms-realtime-storage="' + key + '"]'
		);
		if (existing instanceof HTMLInputElement) {
			return existing;
		}

		var input = document.createElement('input');
		input.type = 'hidden';
		input.dataset.sentientFormsRealtimeStorage = key;
		input.name = normalized
			? 'input_' + normalized.replace(/\./g, '_')
			: 'sentient_forms_realtime_qna_' + String(formState.config.form_id);
		input.id = normalized
			? 'input_' + String(formState.config.form_id) + '_' + normalized.replace(/\./g, '_')
			: 'sentient_forms_realtime_qna_' + String(formState.config.form_id);
		formState.formElement.appendChild(input);
		return input;
	}

	function serializeQuestionAnswers(formState, mapping) {
		var mappingId = normalizeFieldId(mapping.mapping_id);
		var state = mappingStateFor(formState, mappingId);
		var questions = asArray(state.virtualQuestionOrder).map(function (questionId) {
			return state.virtualQuestionHistory[questionId] || null;
		}).filter(Boolean);

		if (!questions.length) {
			questions = asArray(state.virtualQuestions);
		}

		questions = questions.map(function (question) {
			var answer = state.virtualAnswers[question.question_id] || '';
			return {
				question_id: question.question_id,
				question: question.question,
				reason: question.reason || '',
				target_field_id: question.target_field_id || '',
				required: question.required === true,
					answer_type: question.answer_type || 'long_text',
					choices: asArray(question.choices),
					answer: answer,
					completed: state.completedItems[question.question_id] === true
				};
			});

		var conditionalDecisions = asArray(state.conditionalDecisionOrder).map(function (decisionId) {
			return state.conditionalDecisionHistory[decisionId] || null;
		}).filter(Boolean);

		if (!conditionalDecisions.length) {
			conditionalDecisions = asArray(state.conditionalDecisions);
		}

		return {
			mapping_id: mappingId,
			central_action_id: mapping.central_action_id || '',
			action_name_label: mapping.action_name_label || '',
			questions: questions,
			conditional_decisions: conditionalDecisions
		};
	}

	function persistQuestionAnswers(formState) {
		var byTargetField = {};
			asArray(formState.config.mappings).forEach(function (mapping) {
				var targetFieldId = fallbackStorageKey(formState, mapping.storage_target_field_id);

				if (!byTargetField[targetFieldId]) {
					byTargetField[targetFieldId] = [];
			}
			byTargetField[targetFieldId].push(serializeQuestionAnswers(formState, mapping));
		});

			Object.keys(byTargetField).forEach(function (targetFieldId) {
				var target = findOrCreatePersistenceTarget(
					formState,
					targetFieldId === '__sentient_forms_realtime_qna' ? '' : targetFieldId
				);
				if (!target) {
					return;
				}

			var payload = {
				schema: 'sentient_forms_realtime_clarification_qna.v1',
				form_id: String(formState.config.form_id),
				source: formState.config.source || 'gravity_forms',
				updated_at: new Date().toISOString(),
				mappings: byTargetField[targetFieldId]
			};
			target.value = JSON.stringify(payload);
			target.dispatchEvent(new Event('input', { bubbles: true }));
		});
	}

	function getRequiredVirtualQuestionGaps(formState) {
		var gaps = [];
		asArray(formState.config.mappings).forEach(function (mapping) {
			if (mapping.blocking_mode !== 'require_answers') {
				return;
			}

			var mappingId = normalizeFieldId(mapping.mapping_id);
			var state = mappingStateFor(formState, mappingId);
			asArray(state.virtualQuestions).forEach(function (question) {
				var answer = normalizeFieldId(state.virtualAnswers[question.question_id]);
				if (question.required === true && !answer) {
					gaps.push({
						mapping_id: mappingId,
						question_id: question.question_id,
						question: question.question
					});
				}
			});
		});
		return gaps;
	}

	function guardRequiredVirtualAnswers(formState) {
		persistQuestionAnswers(formState);
		var gaps = getRequiredVirtualQuestionGaps(formState);
		if (!gaps.length) {
			return true;
		}

		formState.lastGlobalError = 'Answer the required follow-up questions before continuing.';
		formState.isOpen = true;
		renderWidget(formState);
		var firstAnswerInput = formState.widget && formState.widget.querySelector('[data-role="answer-question"]');
		if (firstAnswerInput instanceof HTMLElement) {
			firstAnswerInput.focus();
		}
		return false;
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

	function flattenVirtualQuestions(formState) {
		var items = [];
		asArray(formState.config.mappings).forEach(function (mapping) {
			var mappingId = normalizeFieldId(mapping.mapping_id);
			var state = mappingStateFor(formState, mappingId);
			asArray(state.virtualQuestions).forEach(function (question) {
				if (!question || typeof question !== 'object') {
					return;
				}

				items.push({
					mappingId: mappingId,
					mappingLabel: mapping.action_name_label || mapping.central_action_id || mappingId,
					question: question,
					answer: state.virtualAnswers[question.question_id] || ''
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

		var parts = [];
		if (credits !== null) {
			parts.push('Credits: ' + credits);
		}

		return parts.join(' \u00B7 ');
	}

	function renderWidget(formState) {
		var widget = ensureWidget(formState);
		var body = widget.querySelector('[data-role="body"]');
		var subtitle = widget.querySelector('[data-role="subtitle"]');
		var toggleButton = widget.querySelector('[data-role="toggle"]');
		var metering = widget.querySelector('[data-role="metering"]');
		var refreshButton = widget.querySelector('[data-role="refresh"]');
		var list = widget.querySelector('[data-role="list"]');
		var questions = widget.querySelector('[data-role="questions"]');
		var empty = widget.querySelector('[data-role="empty"]');
		var error = widget.querySelector('[data-role="error"]');

			var anyInFlight = Object.values(formState.mappingStates).some(function (state) {
				return state.inFlight;
			});

			widget.hidden =
				formState.initialPanelState === 'hidden_until_interaction' && !formState.hasUserInteracted;
			subtitle.textContent = anyInFlight
				? 'Checking...'
				: (formState.lastGlobalError ? 'Needs attention' : formatTimestamp(formState.lastUpdatedAt));
			toggleButton.textContent = formState.isOpen ? 'Hide' : 'Show';
		toggleButton.setAttribute('aria-expanded', formState.isOpen ? 'true' : 'false');
		body.hidden = !formState.isOpen;
		if (refreshButton instanceof HTMLElement) {
			refreshButton.hidden = !asArray(formState.config.mappings).some(function (mapping) {
				return mapping && mapping.manual_refresh_enabled !== false;
			});
		}

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
		var virtualQuestions = flattenVirtualQuestions(formState);
		list.innerHTML = '';
		questions.innerHTML = '';

		if (!suggestions.length && !virtualQuestions.length) {
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
				listItem.className = 'sentient-forms-realtime-widget__item' +
					(item.suggestion.completed ? ' sentient-forms-realtime-widget__item--completed' : '');

			var severityLabel = document.createElement('span');
			severityLabel.className = 'sentient-forms-realtime-widget__severity sentient-forms-realtime-widget__severity--' + severity;
			severityLabel.textContent = severity;
			listItem.appendChild(severityLabel);

			var content = document.createElement('div');
			content.className = 'sentient-forms-realtime-widget__content';
			var mapping = document.createElement('p');
			mapping.className = 'sentient-forms-realtime-widget__mapping';
			mapping.textContent = item.mappingLabel;
			var message = document.createElement('p');
			message.className = 'sentient-forms-realtime-widget__message';
			message.textContent = item.suggestion.message || '';
			content.appendChild(mapping);
			content.appendChild(message);
			listItem.appendChild(content);

				var focusButton = document.createElement('button');
				focusButton.type = 'button';
				focusButton.className = 'sentient-forms-realtime-widget__focus';
				focusButton.dataset.role = 'focus-field';
				focusButton.dataset.fieldId = String(item.suggestion.jump_target_field_id || item.suggestion.field_id || '');
				focusButton.textContent = 'Focus';
				listItem.appendChild(focusButton);

				var completeButton = document.createElement('button');
				completeButton.type = 'button';
				completeButton.className = 'sentient-forms-realtime-widget__complete';
				completeButton.dataset.role = 'complete-item';
				completeButton.dataset.mappingId = item.mappingId;
				completeButton.dataset.itemId = item.suggestion.suggestion_id || '';
				completeButton.textContent = item.suggestion.completed ? 'Done' : 'Mark done';
				listItem.appendChild(completeButton);

				list.appendChild(listItem);
		});

		if (virtualQuestions.length) {
			var heading = document.createElement('h4');
			heading.className = 'sentient-forms-realtime-widget__questions-title';
			heading.textContent = 'Follow-up questions';
			questions.appendChild(heading);
		}

		virtualQuestions.forEach(function (item) {
			var question = item.question;
			var wrapper = document.createElement('div');
				wrapper.className = 'sentient-forms-realtime-widget__question';
				if (item.question.completed) {
					wrapper.className += ' sentient-forms-realtime-widget__question--completed';
				}

			var label = document.createElement('label');
			label.className = 'sentient-forms-realtime-widget__question-label';
			label.setAttribute('for', 'sentient-forms-rt-question-' + item.mappingId + '-' + question.question_id);
			label.textContent = question.question + (question.required ? ' *' : '');
			wrapper.appendChild(label);

			if (question.reason) {
				var reason = document.createElement('p');
				reason.className = 'sentient-forms-realtime-widget__question-reason';
				reason.textContent = question.reason;
				wrapper.appendChild(reason);
			}

			var inputId = 'sentient-forms-rt-question-' + item.mappingId + '-' + question.question_id;
			var answerInput;
			if (question.answer_type === 'choice' && asArray(question.choices).length) {
				answerInput = document.createElement('select');
				var blankOption = document.createElement('option');
				blankOption.value = '';
				blankOption.textContent = 'Select an answer';
				answerInput.appendChild(blankOption);
				asArray(question.choices).forEach(function (choice) {
					var option = document.createElement('option');
					option.value = choice;
					option.textContent = choice;
					answerInput.appendChild(option);
				});
			} else if (question.answer_type === 'short_text') {
				answerInput = document.createElement('input');
				answerInput.type = 'text';
			} else {
				answerInput = document.createElement('textarea');
				answerInput.rows = 4;
			}

			answerInput.className = 'sentient-forms-realtime-widget__answer';
			answerInput.id = inputId;
			answerInput.dataset.role = 'answer-question';
			answerInput.dataset.mappingId = item.mappingId;
			answerInput.dataset.questionId = question.question_id;
			answerInput.value = item.answer;
				answerInput.required = question.required === true;
				wrapper.appendChild(answerInput);

				var questionCompleteButton = document.createElement('button');
				questionCompleteButton.type = 'button';
				questionCompleteButton.className = 'sentient-forms-realtime-widget__complete';
				questionCompleteButton.dataset.role = 'complete-item';
				questionCompleteButton.dataset.mappingId = item.mappingId;
				questionCompleteButton.dataset.itemId = question.question_id;
				questionCompleteButton.textContent = item.question.completed ? 'Done' : 'Mark done';
				wrapper.appendChild(questionCompleteButton);

				questions.appendChild(wrapper);
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

	function normalizePageCheckpointTimeoutMs(mapping) {
		var value = parseInt(mapping && mapping.page_checkpoint_timeout_ms, 10);
		if (!Number.isFinite(value)) {
			value = 2500;
		}
		return Math.min(10000, Math.max(500, value));
	}

	function normalizePreSubmitTimeoutMs(mapping) {
		var value = parseInt(mapping && mapping.pre_submit_timeout_ms, 10);
		if (!Number.isFinite(value)) {
			value = 2500;
		}
		return Math.min(10000, Math.max(500, value));
	}

	function preSubmitTimeoutResult(timeoutMs) {
		return new Promise(function (resolve) {
			window.setTimeout(function () {
				resolve({ status: 'timeout' });
			}, timeoutMs);
		});
	}

	function runPreSubmitMappings(formState) {
		var mappings = asArray(formState.config.mappings).filter(function (mapping) {
			return mapping && typeof mapping === 'object' && mapping.pre_submit_run_enabled === true;
		});
		if (!mappings.length) {
			return Promise.resolve([]);
		}

		formState.preSubmitPending = true;
		renderWidget(formState);

		var runs = mappings.map(function (mapping) {
			var mappingId = normalizeFieldId(mapping.mapping_id);
			var state = mappingStateFor(formState, mappingId);
			if (state.timerId) {
				window.clearTimeout(state.timerId);
				state.timerId = null;
			}
			var timeoutMs = normalizePreSubmitTimeoutMs(mapping);
			return Promise.race([
				runMapping(formState, mapping, {
					manual: true,
					reason: 'pre_submit',
					timeoutMs: timeoutMs
				}),
				preSubmitTimeoutResult(timeoutMs)
			]).catch(function (error) {
				return {
					status: 'error',
					error: error && error.message ? error.message : 'Suggestion request failed.'
				};
			});
		});

		return Promise.all(runs).finally(function () {
			formState.preSubmitPending = false;
			persistQuestionAnswers(formState);
			renderWidget(formState);
		});
	}

	function pageCheckpointMappingsForCurrentPage(formState, currentPage) {
		return asArray(formState.config.mappings).filter(function (mapping) {
			return mapping && typeof mapping === 'object' && pageCheckpointApplies(mapping, currentPage);
		});
	}

	function runPageCheckpointMappings(formState, currentPage) {
		var mappings = pageCheckpointMappingsForCurrentPage(formState, currentPage);
		if (!mappings.length) {
			return Promise.resolve([]);
		}

		formState.pageCheckpointPending = true;
		renderWidget(formState);

		var runs = mappings.map(function (mapping) {
			var mappingId = normalizeFieldId(mapping.mapping_id);
			var state = mappingStateFor(formState, mappingId);
			if (state.timerId) {
				window.clearTimeout(state.timerId);
				state.timerId = null;
			}

			var timeoutMs = normalizePageCheckpointTimeoutMs(mapping);
			return Promise.race([
				runMapping(formState, mapping, {
					manual: false,
					reason: 'page_checkpoint',
					timeoutMs: timeoutMs
				}),
				preSubmitTimeoutResult(timeoutMs)
			]).catch(function (error) {
				return {
					status: 'error',
					error: error && error.message ? error.message : 'Suggestion request failed.'
				};
			});
		});

		return Promise.all(runs).finally(function () {
			formState.pageCheckpointPending = false;
			persistQuestionAnswers(formState);
			renderWidget(formState);
		});
	}

	function markFormInteraction(formState) {
		if (formState.hasUserInteracted) {
			return;
		}
		formState.hasUserInteracted = true;
		renderWidget(formState);
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
			eventFieldId: '',
			currentPageIndex: currentPage
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

	function registerPreSubmissionFilter(formState) {
		if (formState.preSubmissionFilterRegistered) {
			return;
		}

		function attachFilter() {
			if (
				formState.preSubmissionFilterRegistered ||
				!window.gform ||
				!window.gform.utils ||
				typeof window.gform.utils.addAsyncFilter !== 'function'
			) {
				return;
			}

			window.gform.utils.addAsyncFilter('gform/submission/pre_submission', async function (data) {
				if (!data || data.form !== formState.formElement) {
					return data;
				}

				if (!isFinalFormSubmission(formState)) {
					return data;
				}

				await runPreSubmitMappings(formState);
				if (!guardRequiredVirtualAnswers(formState)) {
					data.abort = true;
				}

				return data;
			});
			formState.preSubmissionFilterRegistered = true;
		}

		attachFilter();
		document.addEventListener('gform/theme/scripts_loaded', attachFilter, { once: true });
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
		hydrateQuestionAnswers(formState);
		ensureWidget(formState);
		renderWidget(formState);
		registerPreSubmissionFilter(formState);

		// Gravity Forms preview/page navigation can reload the document on non-initial pages.
		// If runtime boots on page > 1, trigger a page_change run immediately.
		if (formState.lastObservedPage > 1) {
			window.setTimeout(function () {
				runMappings(formState, {
					reason: 'page_change',
					manual: false,
					eventFieldId: '',
					currentPageIndex: formState.lastObservedPage
				});
			}, 0);
		}

			formElement.addEventListener(
				'blur',
				function (event) {
					markFormInteraction(formState);
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
					markFormInteraction(formState);
					runMappings(formState, {
					reason: 'field_change',
					manual: false,
					eventFieldId: extractEventFieldId(event.target, formId)
				});
				}
			);

			formElement.addEventListener(
				'input',
				function () {
					markFormInteraction(formState);
				},
				true
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
					markFormInteraction(formState);
					if (formState.pageCheckpointBypass) {
						formState.pageCheckpointBypass = false;
						schedulePageChangeProbe(formState);
						return;
					}
					if (target.matches('.gform_next_button') && !guardRequiredVirtualAnswers(formState)) {
					event.preventDefault();
					event.stopPropagation();
					event.stopImmediatePropagation();
					return;
				}

				if (target.matches('.gform_next_button')) {
					var currentPage = collectCurrentPage(formState.formElement, formId);
					if (pageCheckpointMappingsForCurrentPage(formState, currentPage).length) {
						event.preventDefault();
						event.stopPropagation();
						event.stopImmediatePropagation();

						runPageCheckpointMappings(formState, currentPage).then(function () {
							if (!guardRequiredVirtualAnswers(formState)) {
								return;
							}

							formState.pageCheckpointBypass = true;
							target.click();
						});
						return;
					}
				}

				schedulePageChangeProbe(formState);
			},
			true
		);

		formElement.addEventListener('submit', function (event) {
			if (!guardRequiredVirtualAnswers(formState)) {
				event.preventDefault();
				event.stopPropagation();
			}
		}, true);

		if (window.jQuery) {
			window.jQuery(document).on('gform_page_loaded.sentientFormsRealtime', function (_event, loadedFormId) {
				if (parseInt(loadedFormId, 10) !== formId) {
					return;
				}

				schedulePageChangeProbe(formState);
			});
		}
	}

	function loadAndInitializeForm(config) {
		var formId = formIdFromConfig(config);
		if (
			!formId ||
			window[RUNTIME_KEY].forms[formId] ||
			window[RUNTIME_KEY].pendingConfigs[formId] ||
			window[RUNTIME_KEY].configErrors[formId]
		) {
			return;
		}

		if (hasFullRuntimeConfig(config)) {
			initializeForm(config);
			return;
		}

		fetchRuntimeConfig(config).then(function (runtimeConfig) {
			if (runtimeConfig) {
				initializeForm(runtimeConfig);
				return;
			}
			if (!window[RUNTIME_KEY].configErrors[formId]) {
				window[RUNTIME_KEY].configErrors[formId] = 'Runtime configuration could not be loaded.';
			}
		});
	}

	function boot() {
		var formsConfig = window.sentientFormsRealtimeSuggestions.forms;
		Object.keys(formsConfig).forEach(function (formIdKey) {
			loadAndInitializeForm(formsConfig[formIdKey]);
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
				loadAndInitializeForm(config);
			}
		});
	}
})();
