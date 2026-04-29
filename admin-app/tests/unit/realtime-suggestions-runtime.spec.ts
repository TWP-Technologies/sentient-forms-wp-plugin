import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const runtimeScript = readFileSync(
	resolve(process.cwd(), '../assets/js/realtime-suggestions.js'),
	'utf8'
);

function evaluateRuntimeScript(): void {
	const execute = new Function(runtimeScript);
	execute();
}

function setupFormDom(): void {
	document.body.innerHTML = `
		<form id="gform_42">
			<input type="hidden" id="gform_source_page_number_42" value="1" />
			<div id="field_42_1" class="gfield">
				<input name="input_1" type="text" value="hello" />
			</div>
			<div id="field_42_2" class="gfield" style="display:none">
				<input name="input_2" type="text" value="hidden" />
			</div>
			<div id="field_42_9" class="gfield" style="display:none">
				<textarea id="input_42_9" name="input_9"></textarea>
			</div>
			<input id="gform_next_button_42_3" class="gform_next_button" type="button" value="Next" />
			<input id="gform_previous_button_42" class="gform_previous_button" type="button" value="Previous" />
		</form>
	`;
}

function setupRuntimeConfig(overrides: Record<string, unknown> = {}): void {
	const baseConfig = {
		form_id: 42,
		source: 'gravity_forms',
		total_pages: 2,
		suggest_endpoint_url: '/wp-json/sentient-forms/v1/gravity_forms/forms/42/actions/suggest',
		nonce: 'nonce-42',
		rest_nonce: 'rest-nonce-42',
		mappings: [
			{
				mapping_id: 'map_rt_1',
				central_action_id: 'central_rt_1',
				action_name_label: 'Realtime Action',
				debounce_ms: 100,
				cooldown_ms: 0,
				manual_refresh_enabled: true,
				storage_target_field_id: '9',
				blocking_mode: 'advisory',
				checkpoint_field_ids: ['1']
			}
		],
		field_manifest: [
			{ field_id: '1', label: 'Name', type: 'text', page_index: 1 },
			{ field_id: '2', label: 'Details', type: 'textarea', page_index: 2 }
		]
	};

	(window as unknown as { sentientFormsRealtimeSuggestions: unknown }).sentientFormsRealtimeSuggestions = {
		forms: {
			'42': {
				...baseConfig,
				...overrides
			}
		}
	};
}

async function flushRuntime(ms = 150): Promise<void> {
	await vi.advanceTimersByTimeAsync(ms);
	await Promise.resolve();
	await Promise.resolve();
}

function triggerBlurOnField(fieldId: string): void {
	const field = document.querySelector<HTMLElement>(`[name="input_${fieldId}"]`);
	expect(field).not.toBeNull();
	field?.dispatchEvent(new Event('blur', { bubbles: true }));
}

function triggerChangeOnField(fieldId: string): void {
	const field = document.querySelector<HTMLElement>(`[name="input_${fieldId}"]`);
	expect(field).not.toBeNull();
	field?.dispatchEvent(new Event('change', { bubbles: true }));
}

function triggerNextPageClick(): void {
	const nextButton = document.querySelector<HTMLInputElement>('.gform_next_button');
	expect(nextButton).not.toBeNull();
	nextButton?.click();
}

describe('realtime suggestions runtime', () => {
	beforeEach(() => {
		vi.useFakeTimers();
		setupFormDom();
		Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
			configurable: true,
			value: vi.fn()
		});
	});

	afterEach(() => {
		vi.useRealTimers();
		vi.unstubAllGlobals();
		document.body.innerHTML = '';
		delete (window as Record<string, unknown>).sentientFormsRealtimeSuggestions;
		delete (window as Record<string, unknown>).__sentientRealtimeSuggestionsRuntime;
	});

	it('runs mappings only for configured checkpoint fields', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		triggerChangeOnField('2');
		await flushRuntime();
		expect(fetchMock).not.toHaveBeenCalled();

		triggerBlurOnField('1');
		await flushRuntime();
		expect(fetchMock).toHaveBeenCalledTimes(1);

		const requestInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
		const payload = JSON.parse(String(requestInit.body));
		const headers = requestInit.headers as Record<string, string>;
		expect(payload.mapping_id).toBe('map_rt_1');
		expect(payload.visible_field_ids).toEqual(['1']);
		expect(headers['X-Sentient-Forms-Suggest-Nonce']).toBe('nonce-42');
		expect(headers['X-WP-Nonce']).toBe('rest-nonce-42');
	});

	it('renders metering summary with credits and correlation id', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				suggestions: [],
				meta: {
					correlation_id: 'rt-corr-123',
					credits_debited: 4
				}
			})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const metering = document.querySelector<HTMLElement>('[data-role="metering"]');
		expect(metering).not.toBeNull();
		expect(metering?.hidden).toBe(false);
		expect(metering?.textContent).toContain('Credits: 4');
		expect(metering?.textContent).toContain('Run: rt-corr-123');
	});

	it('dispatches conditional decision events with mapping context', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				suggestions: [],
				conditional_decisions: [
					{
						decision_id: 'needs-location',
						condition_key: 'needs_location',
						met: true,
						confidence: 0.9
					}
				]
			})
		});
		const events: Array<CustomEvent> = [];
		const form = document.querySelector<HTMLFormElement>('#gform_42');
		form?.addEventListener('sentientforms:conditional-decisions', (event) => {
			events.push(event as CustomEvent);
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		expect(events).toHaveLength(1);
		expect(events[0].detail).toMatchObject({
			form_id: 42,
			source: 'gravity_forms',
			mapping_id: 'map_rt_1',
			central_action_id: 'central_rt_1'
		});
		expect(events[0].detail.decisions).toEqual([
			{
				decision_id: 'needs-location',
				condition_key: 'needs_location',
				met: true,
				confidence: 0.9,
				reason: ''
			}
		]);
	});

	it('runs page-change suggestions when source page index changes after next click', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		setTimeout(() => {
			const sourceInput = document.querySelector<HTMLInputElement>('#gform_source_page_number_42');
			if (sourceInput) {
				sourceInput.value = '2';
			}
		}, 30);

		triggerNextPageClick();
		await flushRuntime(600);

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const requestInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
		const payload = JSON.parse(String(requestInit.body));
		expect(payload.current_page_index).toBe(2);
	});

	it('bypasses cooldown for page-change triggers', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 5000,
					manual_refresh_enabled: true,
					checkpoint_field_ids: ['1']
				}
			]
		});
		evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();
		expect(fetchMock).toHaveBeenCalledTimes(1);

		setTimeout(() => {
			const sourceInput = document.querySelector<HTMLInputElement>('#gform_source_page_number_42');
			if (sourceInput) {
				sourceInput.value = '2';
			}
		}, 30);

		triggerNextPageClick();
		await flushRuntime(600);

		expect(fetchMock).toHaveBeenCalledTimes(2);
		const secondRequestInit = fetchMock.mock.calls[1]?.[1] as RequestInit;
		const secondPayload = JSON.parse(String(secondRequestInit.body));
		expect(secondPayload.current_page_index).toBe(2);
	});

	it('does not run page-change suggestions when page index stays the same', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		triggerNextPageClick();
		await flushRuntime(600);

		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('derives current page from visible gform page markup before source input fallback', async () => {
		document.body.innerHTML = `
			<form id="gform_42">
				<input type="hidden" id="gform_source_page_number_42" value="1" />
				<div id="gform_page_42_1" class="gform_page" style="display:none">
					<div id="field_42_1" class="gfield"><input name="input_1" type="text" value="hello" /></div>
				</div>
				<div id="gform_page_42_2" class="gform_page">
					<div id="field_42_4" class="gfield"><textarea name="input_4">details</textarea></div>
				</div>
			</form>
		`;

		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 0,
					manual_refresh_enabled: true,
					checkpoint_field_ids: ['4']
				}
			],
			field_manifest: [{ field_id: '4', label: 'Details', type: 'textarea', page_index: 2 }]
		});
		evaluateRuntimeScript();

		triggerBlurOnField('4');
		await flushRuntime();

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const requestInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
		const payload = JSON.parse(String(requestInit.body));
		expect(payload.current_page_index).toBe(2);
		expect(payload.visible_field_ids).toEqual(['4']);
	});

	it('runs a page-change request on boot when form loads directly on page 2+', async () => {
		document.body.innerHTML = `
			<form id="gform_42">
				<input type="hidden" id="gform_source_page_number_42" value="2" />
				<div id="gform_page_42_1" class="gform_page" style="display:none">
					<div id="field_42_1" class="gfield"><input name="input_1" type="text" value="hello" /></div>
				</div>
				<div id="gform_page_42_2" class="gform_page">
					<div id="field_42_4" class="gfield"><textarea name="input_4">details</textarea></div>
				</div>
			</form>
		`;

		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 0,
					manual_refresh_enabled: true,
					checkpoint_field_ids: ['1']
				}
			],
			field_manifest: [{ field_id: '4', label: 'Details', type: 'textarea', page_index: 2 }]
		});
		evaluateRuntimeScript();
		await flushRuntime(300);

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const requestInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
		const payload = JSON.parse(String(requestInit.body));
		expect(payload.current_page_index).toBe(2);
	});

	it('renders only visible unsuppressed suggestions in the widget', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				suggestions: [
					{
						suggestion_id: '9f73d317-17eb-4462-8e3b-56adf0d49359',
						field_id: '1',
						severity: 'warning',
						message: 'Keep this field concise.',
						jump_target_field_id: '1',
						is_suppressed: false
					},
					{
						suggestion_id: 'e7d01afa-0d8d-400a-bddf-7d916f3f5d0b',
						field_id: '2',
						severity: 'warning',
						message: 'Hidden-field suggestion should not render.',
						jump_target_field_id: '2',
						is_suppressed: false
					},
					{
						suggestion_id: '78bba499-9413-4bb3-99b4-64b4d7bddb18',
						field_id: '1',
						severity: 'info',
						message: 'Suppressed should not render.',
						jump_target_field_id: '1',
						is_suppressed: true
					}
				]
			})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const listItems = Array.from(
			document.querySelectorAll<HTMLLIElement>('.sentient-forms-realtime-widget__item')
		);
		expect(listItems).toHaveLength(1);
		expect(listItems[0]?.textContent ?? '').toContain('Keep this field concise.');
		expect(document.body.textContent ?? '').not.toContain('Hidden-field suggestion should not render.');
		expect(document.body.textContent ?? '').not.toContain('Suppressed should not render.');
	});

	it('renders virtual questions and stores question plus answer JSON in the configured field', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				suggestions: [],
				virtual_questions: [
					{
						question_id: 'browser-context',
						question: 'Which browser and device did this happen on?',
						reason: 'Support needs reproduction context.',
						target_field_id: '1',
						required: true,
						answer_type: 'long_text'
					}
				]
			})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const textarea = document.querySelector<HTMLTextAreaElement>(
			'[data-role="answer-question"][data-question-id="browser-context"]'
		);
		expect(textarea).not.toBeNull();
		expect(textarea?.value).toBe('');
		textarea!.value = 'Chrome on Windows 11';
		textarea?.dispatchEvent(new Event('input', { bubbles: true }));

		const storage = document.querySelector<HTMLTextAreaElement>('[name="input_9"]');
		expect(storage).not.toBeNull();
		const stored = JSON.parse(storage?.value ?? '{}');
		expect(stored.schema).toBe('sentient_forms_realtime_clarification_qna.v1');
		expect(stored.mappings[0].questions[0].question).toBe(
			'Which browser and device did this happen on?'
		);
		expect(stored.mappings[0].questions[0].answer).toBe('Chrome on Windows 11');
	});

	it('preserves answered virtual questions when a later run asks new questions', async () => {
		const fetchMock = vi
			.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: async () => ({
					suggestions: [],
					virtual_questions: [
						{
							question_id: 'affected-url',
							question: 'What page URL did this happen on?',
							reason: 'Support needs the affected page.',
							target_field_id: '1',
							required: false,
							answer_type: 'long_text'
						}
					],
					conditional_decisions: [
						{
							decision_id: 'needs-location',
							condition_key: 'needs_location',
							met: true,
							confidence: 0.9
						}
					]
				})
			})
			.mockResolvedValueOnce({
				ok: true,
				json: async () => ({
					suggestions: [],
					virtual_questions: [
						{
							question_id: 'browser-context',
							question: 'Which browser and device did this happen on?',
							reason: 'Support needs reproduction context.',
							target_field_id: '1',
							required: false,
							answer_type: 'long_text'
						}
					],
					conditional_decisions: [
						{
							decision_id: 'has-location',
							condition_key: 'has_location',
							met: true,
							confidence: 1
						}
					]
				})
			});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const firstAnswer = document.querySelector<HTMLTextAreaElement>(
			'[data-role="answer-question"][data-question-id="affected-url"]'
		);
		expect(firstAnswer).not.toBeNull();
		firstAnswer!.value = 'https://example.test/pricing';
		firstAnswer?.dispatchEvent(new Event('input', { bubbles: true }));

		document
			.querySelector<HTMLButtonElement>('[data-role="refresh"]')
			?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		await flushRuntime();

		const storage = document.querySelector<HTMLTextAreaElement>('[name="input_9"]');
		const stored = JSON.parse(storage?.value ?? '{}');
		expect(stored.mappings[0].questions).toHaveLength(2);
		expect(stored.mappings[0].questions.map((question: { question_id: string }) => question.question_id)).toEqual([
			'affected-url',
			'browser-context'
		]);
		expect(stored.mappings[0].questions[0].answer).toBe('https://example.test/pricing');
		expect(stored.mappings[0].questions[1].answer).toBe('');
		expect(
			stored.mappings[0].conditional_decisions.map(
				(decision: { decision_id: string }) => decision.decision_id
			)
		).toEqual(['needs-location', 'has-location']);
	});

	it('hydrates stored virtual answers after Gravity Forms reloads the page markup', async () => {
		const fetchMock = vi
			.fn()
			.mockResolvedValueOnce({
				ok: true,
				json: async () => ({
					suggestions: [],
					virtual_questions: [
						{
							question_id: 'affected-url',
							question: 'What page URL did this happen on?',
							reason: 'Support needs the affected page.',
							target_field_id: '1',
							required: false,
							answer_type: 'long_text'
						}
					],
					conditional_decisions: [
						{
							decision_id: 'needs-location',
							condition_key: 'needs_location',
							met: true,
							confidence: 0.9
						}
					]
				})
			})
			.mockResolvedValueOnce({
				ok: true,
				json: async () => ({
					suggestions: [],
					virtual_questions: [
						{
							question_id: 'browser-context',
							question: 'Which browser and device did this happen on?',
							reason: 'Support needs reproduction context.',
							target_field_id: '4',
							required: false,
							answer_type: 'long_text'
						}
					],
					conditional_decisions: [
						{
							decision_id: 'has-location',
							condition_key: 'has_location',
							met: true,
							confidence: 1
						}
					]
				})
			});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const firstAnswer = document.querySelector<HTMLTextAreaElement>(
			'[data-role="answer-question"][data-question-id="affected-url"]'
		);
		expect(firstAnswer).not.toBeNull();
		firstAnswer!.value = 'https://example.test/pricing';
		firstAnswer?.dispatchEvent(new Event('input', { bubbles: true }));

		const sourceInput = document.querySelector<HTMLInputElement>('#gform_source_page_number_42');
		expect(sourceInput).not.toBeNull();
		sourceInput!.value = '2';
		delete (window as Record<string, unknown>).__sentientRealtimeSuggestionsRuntime;

		evaluateRuntimeScript();
		await flushRuntime(300);

		const storage = document.querySelector<HTMLTextAreaElement>('[name="input_9"]');
		const stored = JSON.parse(storage?.value ?? '{}');
		expect(fetchMock).toHaveBeenCalledTimes(2);
		expect(stored.mappings[0].questions).toHaveLength(2);
		expect(stored.mappings[0].questions.map((question: { question_id: string }) => question.question_id)).toEqual([
			'affected-url',
			'browser-context'
		]);
		expect(stored.mappings[0].questions[0].answer).toBe('https://example.test/pricing');
		expect(stored.mappings[0].questions[1].answer).toBe('');
		expect(
			stored.mappings[0].conditional_decisions.map(
				(decision: { decision_id: string }) => decision.decision_id
			)
		).toEqual(['needs-location', 'has-location']);
	});

	it('renders choice virtual questions as selects and stores the selected answer', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				suggestions: [],
				virtual_questions: [
					{
						question_id: 'priority',
						question: 'How urgent is this?',
						required: true,
						answer_type: 'choice',
						choices: ['Today', 'This week']
					}
				]
			})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const select = document.querySelector<HTMLSelectElement>(
			'select[data-role="answer-question"][data-question-id="priority"]'
		);
		expect(select).not.toBeNull();
		select!.value = 'This week';
		select?.dispatchEvent(new Event('change', { bubbles: true }));

		const storage = document.querySelector<HTMLTextAreaElement>('[name="input_9"]');
		const stored = JSON.parse(storage?.value ?? '{}');
		expect(stored.mappings[0].questions[0].answer_type).toBe('choice');
		expect(stored.mappings[0].questions[0].choices).toEqual(['Today', 'This week']);
		expect(stored.mappings[0].questions[0].answer).toBe('This week');
	});

	it('blocks submit when required virtual questions are unanswered and policy requires answers', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				suggestions: [],
				virtual_questions: [
					{
						question_id: 'required-context',
						question: 'What URL did this occur on?',
						required: true,
						answer_type: 'short_text'
					}
				]
			})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 0,
					manual_refresh_enabled: true,
					storage_target_field_id: '9',
					blocking_mode: 'require_answers',
					checkpoint_field_ids: ['1']
				}
			]
		});
		evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const form = document.querySelector<HTMLFormElement>('#gform_42');
		expect(form).not.toBeNull();
		const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
		const allowed = form!.dispatchEvent(submitEvent);

		expect(allowed).toBe(false);
		expect(submitEvent.defaultPrevented).toBe(true);
		expect(document.body.textContent ?? '').toContain(
			'Answer the required follow-up questions before continuing.'
		);
	});

	it('blocks next-page clicks when required virtual questions are unanswered', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				suggestions: [],
				virtual_questions: [
					{
						question_id: 'missing-url',
						question: 'What URL did this occur on?',
						required: true,
						answer_type: 'short_text'
					}
				]
			})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 0,
					manual_refresh_enabled: true,
					storage_target_field_id: '9',
					blocking_mode: 'require_answers',
					checkpoint_field_ids: ['1']
				}
			]
		});
		evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const nextButton = document.querySelector<HTMLInputElement>('.gform_next_button');
		expect(nextButton).not.toBeNull();
		const clickEvent = new MouseEvent('click', { bubbles: true, cancelable: true });
		const allowed = nextButton!.dispatchEvent(clickEvent);

		expect(allowed).toBe(false);
		expect(clickEvent.defaultPrevented).toBe(true);
	});

	it('skips manual refresh for mappings that disable it', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 0,
					manual_refresh_enabled: false,
					checkpoint_field_ids: ['1']
				}
			]
		});
		evaluateRuntimeScript();

		const refreshButton = document.querySelector<HTMLButtonElement>(
			'.sentient-forms-realtime-widget__refresh'
		);
		expect(refreshButton).not.toBeNull();
		refreshButton?.click();
		await flushRuntime();

		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('focus button scrolls and focuses the mapped field', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				suggestions: [
					{
						suggestion_id: '0fc2032d-113c-4220-8408-6a4c8350bc65',
						field_id: '1',
						severity: 'info',
						message: 'Focus this field.',
						jump_target_field_id: '1',
						is_suppressed: false
					}
				]
			})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		evaluateRuntimeScript();

		const input = document.querySelector<HTMLInputElement>('[name="input_1"]');
		expect(input).not.toBeNull();
		const focusSpy = vi.fn();
		if (input) {
			input.focus = focusSpy;
		}

		triggerBlurOnField('1');
		await flushRuntime();

		const focusButton = document.querySelector<HTMLButtonElement>(
			'.sentient-forms-realtime-widget__focus[data-field-id="1"]'
		);
		expect(focusButton).not.toBeNull();
		focusButton?.click();
		await flushRuntime();

		expect(focusSpy).toHaveBeenCalledTimes(1);
	});
});
