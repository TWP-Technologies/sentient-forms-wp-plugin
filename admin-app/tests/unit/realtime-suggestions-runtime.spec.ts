import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const runtimeScript = readFileSync(
	resolve(process.cwd(), '../assets/js/realtime-suggestions.js'),
	'utf8'
);

const runtimeConfigEndpointUrl =
	'/wp-json/sentient-forms/v1/gravity_forms/forms/42/actions/runtime-config';
const runtimeConfigToken = 'runtime-config-token-42';

type PreSubmissionData = { form: HTMLFormElement; abort?: boolean };
type PreSubmissionFilter = (data: PreSubmissionData) => Promise<PreSubmissionData>;

async function evaluateRuntimeScript(): Promise<void> {
	const execute = new Function(runtimeScript);
	execute();
	await flushRuntime(0);
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

function createRuntimeConfig(overrides: Record<string, unknown> = {}): Record<string, unknown> {
	const baseConfig = {
		form_id: 42,
		source: 'gravity_forms',
		total_pages: 2,
		runtime_config_endpoint_url: runtimeConfigEndpointUrl,
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
				auto_refresh_enabled: false,
				field_checkpoints_enabled: true,
				manual_refresh_enabled: true,
				storage_target_field_id: '9',
				blocking_mode: 'advisory',
				checkpoint_field_ids: ['1']
			}
		],
			field_manifest: [
				{ field_id: '1', label: 'Name', type: 'text', page_index: 1 },
				{ field_id: '2', label: 'Details', type: 'textarea', page_index: 2 },
				{ field_id: '9', label: 'Sentient Forms Realtime Q&A', type: 'hidden', page_index: 1 }
			]
		};

	return {
		...baseConfig,
		...overrides
	};
}

function setupRuntimeConfig(overrides: Record<string, unknown> = {}): void {
	const runtimeConfig = createRuntimeConfig(overrides);

	const existingFetch = window.fetch;
	if (typeof existingFetch === 'function') {
		vi.stubGlobal(
			'fetch',
			((input: RequestInfo | URL, init?: RequestInit) => {
				const requestUrl =
					typeof input === 'string'
						? input
						: input instanceof URL
							? input.toString()
							: input.url;

				if (requestUrl === runtimeConfigEndpointUrl) {
					return Promise.resolve({
						ok: true,
						status: 200,
						text: async () => JSON.stringify(runtimeConfig)
					});
				}

				return existingFetch(input, init);
			}) as typeof fetch
		);
	}

	(window as unknown as { sentientFormsRealtimeSuggestions: unknown }).sentientFormsRealtimeSuggestions = {
		forms: {
			'42': {
				form_id: 42,
				source: 'gravity_forms',
				runtime_config_endpoint_url: runtimeConfigEndpointUrl,
				runtime_config_token: runtimeConfigToken
			}
		}
	};
}

function setupRuntimeConfigBootstrap(): void {
	(window as unknown as { sentientFormsRealtimeSuggestions: unknown }).sentientFormsRealtimeSuggestions = {
		forms: {
			'42': {
				form_id: 42,
				source: 'gravity_forms',
				runtime_config_endpoint_url: runtimeConfigEndpointUrl,
				runtime_config_token: runtimeConfigToken
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
		window.sessionStorage.clear();
		document.body.innerHTML = '';
		delete (window as Record<string, unknown>).sentientFormsRealtimeSuggestions;
		delete (window as Record<string, unknown>).__sentientRealtimeSuggestionsRuntime;
		delete (window as Record<string, unknown>).gform;
	});

	it('does not boot from stale full inline runtime config without a fresh endpoint', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		const staleInlineConfig = createRuntimeConfig({
			initial_panel_state: 'open'
		});
		delete staleInlineConfig.runtime_config_endpoint_url;
		(window as unknown as { sentientFormsRealtimeSuggestions: unknown }).sentientFormsRealtimeSuggestions =
			{
				forms: {
					'42': staleInlineConfig
				}
			};

		await evaluateRuntimeScript();

		expect(document.querySelector('.sentient-forms-realtime-widget')).toBeNull();
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('rejects runtime-config payloads missing suggestion request credentials', async () => {
		setupRuntimeConfig({ nonce: '' });

		await evaluateRuntimeScript();

		expect(document.querySelector('.sentient-forms-realtime-widget')).toBeNull();
		expect(document.querySelector<HTMLFormElement>('#gform_42')).not.toBeNull();
		const runtimeState = (
			window as unknown as {
				__sentientRealtimeSuggestionsRuntime: { configErrors: Record<number, string> };
			}
		).__sentientRealtimeSuggestionsRuntime;
		expect(runtimeState.configErrors[42]).toContain('incomplete');
	});

	it('sends the signed bootstrap token when fetching runtime config', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			status: 200,
			text: async () => JSON.stringify(createRuntimeConfig())
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfigBootstrap();

		await evaluateRuntimeScript();

		expect(fetchMock).toHaveBeenCalledWith(
			runtimeConfigEndpointUrl,
			expect.objectContaining({
				headers: expect.objectContaining({
					'X-Sentient-Forms-Runtime-Config-Token': runtimeConfigToken
				})
			})
		);
	});

	it('keeps the assistant hidden and does not retry when runtime-config returns an error', async () => {
		const postRenderHandlers: Array<(_event: unknown, formId: number) => void> = [];
		const jQueryMock = vi.fn((_target: unknown) => ({
			on: vi.fn((_eventName: string, handler: (_event: unknown, formId: number) => void) => {
				postRenderHandlers.push(handler);
			})
		}));
		const fetchMock = vi.fn().mockResolvedValue({
			ok: false,
			status: 503,
			headers: new Headers({ 'content-type': 'application/json' }),
			text: async () => JSON.stringify({ message: 'Requested form source is unavailable.' })
		});
		vi.stubGlobal('jQuery', jQueryMock);
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfigBootstrap();

		await evaluateRuntimeScript();

		expect(document.querySelector('.sentient-forms-realtime-widget')).toBeNull();
		expect(document.querySelector<HTMLFormElement>('#gform_42')).not.toBeNull();
		expect(fetchMock).toHaveBeenCalledTimes(1);
		const runtimeState = (
			window as unknown as {
				__sentientRealtimeSuggestionsRuntime: { configErrors: Record<number, string> };
			}
		).__sentientRealtimeSuggestionsRuntime;
		expect(runtimeState.configErrors[42]).toBeTruthy();

		postRenderHandlers[0]?.({}, 42);
		await flushRuntime();

		expect(fetchMock).toHaveBeenCalledTimes(1);
	});

	it('initializes from an already-fetched fresh runtime config after a delayed form render', async () => {
		const postRenderHandlers: Array<(_event: unknown, formId: number) => void> = [];
		const jQueryMock = vi.fn((_target: unknown) => ({
			on: vi.fn((_eventName: string, handler: (_event: unknown, formId: number) => void) => {
				postRenderHandlers.push(handler);
			})
		}));
		vi.stubGlobal('jQuery', jQueryMock);
		const runtimeConfig = createRuntimeConfig();
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			status: 200,
			text: async () => JSON.stringify(runtimeConfig)
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfigBootstrap();
		document.body.innerHTML = '';

		await evaluateRuntimeScript();

		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(document.querySelector('.sentient-forms-realtime-widget')).toBeNull();

		setupFormDom();
		postRenderHandlers[0]?.({}, 42);
		await flushRuntime();

		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(document.querySelector('.sentient-forms-realtime-widget')).not.toBeNull();
	});

	it('keeps the assistant hidden and form usable when runtime-config fetch rejects', async () => {
		const fetchMock = vi.fn().mockRejectedValue(new TypeError('Network error'));
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfigBootstrap();

		await evaluateRuntimeScript();

		expect(document.querySelector('.sentient-forms-realtime-widget')).toBeNull();
		expect(document.querySelector<HTMLFormElement>('#gform_42')).not.toBeNull();
		expect(fetchMock).toHaveBeenCalledTimes(1);
		const runtimeState = (
			window as unknown as {
				__sentientRealtimeSuggestionsRuntime: { configErrors: Record<number, string> };
			}
		).__sentientRealtimeSuggestionsRuntime;
		expect(runtimeState.configErrors[42]).toBeTruthy();
	});

	it('rejects runtime-config payloads that belong to another form', async () => {
		setupRuntimeConfig({ form_id: 99 });

		await evaluateRuntimeScript();

		expect(document.querySelector('.sentient-forms-realtime-widget')).toBeNull();
		expect(document.querySelector<HTMLFormElement>('#gform_42')).not.toBeNull();
		const runtimeState = (
			window as unknown as {
				__sentientRealtimeSuggestionsRuntime: { configErrors: Record<number, string> };
			}
		).__sentientRealtimeSuggestionsRuntime;
		expect(runtimeState.configErrors[42]).toContain('does not match this form');
	});

	it('runs mappings only for configured checkpoint fields', async () => {
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
					auto_refresh_enabled: false,
					field_checkpoints_enabled: true,
					page_checkpoints_enabled: true,
					manual_refresh_enabled: true,
					storage_target_field_id: '9',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['1']
				}
			]
		});
		await evaluateRuntimeScript();

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
			expect(payload.all_known_field_values).toEqual({ '1': 'hello' });
			expect(payload.hidden_field_exposure_mode).toBe('label_hidden');
			expect(payload.supplemental_field_context).toEqual([
				{
					field_id: '2',
					label: 'Details',
					type: 'textarea',
					page_index: 2,
					hidden: true
				}
			]);
			expect(headers['X-Sentient-Forms-Suggest-Nonce']).toBe('nonce-42');
			expect(headers).not.toHaveProperty('X-WP-Nonce');
		});

		it('can include hidden values when the mapping explicitly allows them', async () => {
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
							auto_refresh_enabled: false,
							field_checkpoints_enabled: true,
							manual_refresh_enabled: true,
							storage_target_field_id: '9',
						blocking_mode: 'advisory',
						checkpoint_field_ids: ['1'],
						hidden_field_exposure_mode: 'label_hidden_value'
					}
				]
			});
			await evaluateRuntimeScript();

			triggerBlurOnField('1');
			await flushRuntime();

			const requestInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
			const payload = JSON.parse(String(requestInit.body));
			expect(payload.all_known_field_values).toEqual({ '1': 'hello', '2': 'hidden' });
			expect(payload.supplemental_field_context).toEqual([
				{
					field_id: '2',
					label: 'Details',
					type: 'textarea',
					page_index: 2,
					hidden: true,
					value: 'hidden'
				}
			]);
		});

	it('starts minimized by default so embedded forms are not covered on first paint', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ suggestions: [] })
			})
		);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

		const body = document.querySelector<HTMLElement>('[data-role="body"]');
		const toggle = document.querySelector<HTMLButtonElement>('[data-role="toggle"]');
		expect(body?.hidden).toBe(true);
		expect(toggle?.textContent).toBe('Show');
	});

	it('can hide the assistant from mapping-level config until a visitor starts interacting with the form', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ suggestions: [] })
			})
		);
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 0,
					auto_refresh_enabled: false,
					field_checkpoints_enabled: true,
					manual_refresh_enabled: true,
					storage_target_field_id: '9',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['1'],
					initial_panel_state: 'hidden_until_interaction'
				}
			]
		});
		await evaluateRuntimeScript();

		const widget = document.querySelector<HTMLElement>('.sentient-forms-realtime-widget');
		expect(widget?.hidden).toBe(true);

		const input = document.querySelector<HTMLInputElement>('[name="input_1"]');
		input?.dispatchEvent(new Event('input', { bubbles: true }));
		expect(widget?.hidden).toBe(false);
	});

	it('honors an open mapping-level initial state when no top-level state is present', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ suggestions: [] })
			})
		);
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 0,
					auto_refresh_enabled: false,
					field_checkpoints_enabled: true,
					manual_refresh_enabled: true,
					storage_target_field_id: '9',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['1'],
					initial_panel_state: 'open'
				}
			]
		});
		await evaluateRuntimeScript();

		const widget = document.querySelector<HTMLElement>('.sentient-forms-realtime-widget');
		const body = document.querySelector<HTMLElement>('[data-role="body"]');
		const toggle = document.querySelector<HTMLButtonElement>('[data-role="toggle"]');
		expect(widget?.hidden).toBe(false);
		expect(body?.hidden).toBe(false);
		expect(toggle?.textContent).toBe('Hide');
	});

	it('renders metering summary without exposing internal run identifiers', async () => {
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
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 0,
					auto_refresh_enabled: false,
					field_checkpoints_enabled: true,
					page_checkpoints_enabled: true,
					manual_refresh_enabled: true,
					storage_target_field_id: '9',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['1']
				}
			]
		});
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const metering = document.querySelector<HTMLElement>('[data-role="metering"]');
		expect(metering).not.toBeNull();
		expect(metering?.hidden).toBe(false);
		expect(metering?.textContent).toContain('Credits: 4');
		expect(metering?.textContent).not.toContain('Run: rt-corr-123');
		expect(metering?.textContent).not.toContain('rt-corr-123');
	});

	it('shows HTTP status when suggestion endpoint returns non-JSON error markup', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: false,
			status: 502,
			text: async () => '<html><body>Bad Gateway</body></html>'
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(error).not.toBeNull();
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toContain('Suggestion request failed with HTTP 502.');
		expect(error?.textContent).not.toContain('Unexpected token');
	});

	it('shows a visitor-safe message when a site security layer challenges suggestions', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: false,
			status: 403,
			headers: new Headers({
				'content-type': 'text/html',
				'cf-mitigated': 'challenge',
				'cf-ray': 'a037249e8cf5ff58-ORD'
			}),
			text: async () => '<html><title>Just a moment...</title></html>'
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(error).not.toBeNull();
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toContain(
			'Suggestions could not refresh because this request reached the site security layer before WordPress could process it.'
		);
		expect(error?.textContent).not.toContain('Cloudflare');
		expect(error?.textContent).not.toContain('a037249e8cf5ff58');
	});

	it('shows a visitor-safe message for Cloudflare branded HTML block pages', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: false,
			status: 403,
			headers: new Headers({
				'content-type': 'text/html',
				server: 'cloudflare'
			}),
			text: async () =>
				'<html><title>Attention Required! | Cloudflare</title><body>Ray ID: a037249e8cf5ff58-ORD</body></html>'
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(error).not.toBeNull();
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toContain(
			'Suggestions could not refresh because this request reached the site security layer before WordPress could process it.'
		);
		expect(error?.textContent).not.toContain('Cloudflare');
		expect(error?.textContent).not.toContain('a037249e8cf5ff58');
	});

	it('does not label ordinary JSON rate limits as site security roadblocks', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: false,
			status: 429,
			headers: new Headers({ 'content-type': 'application/json' }),
			text: async () =>
				JSON.stringify({ message: 'Suggestion rate limit exceeded. Please wait and retry.' })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(error).not.toBeNull();
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toContain('Suggestion rate limit exceeded. Please wait and retry.');
		expect(error?.textContent).not.toContain('site security layer');
	});

	it('does not label Cloudflare-proxied JSON rate limits as site security roadblocks', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: false,
			status: 429,
			headers: new Headers({
				'content-type': 'application/json',
				server: 'cloudflare'
			}),
			text: async () =>
				JSON.stringify({
					message: 'You are rate limited by Sentient Forms. Please wait and retry.'
				})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(error).not.toBeNull();
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toContain(
			'You are rate limited by Sentient Forms. Please wait and retry.'
		);
		expect(error?.textContent).not.toContain('site security layer');
		expect(error?.textContent).not.toContain('Cloudflare');
	});

	it('does not label Cloudflare-proxied JSON security errors as site security roadblocks', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: false,
			status: 403,
			headers: new Headers({
				'content-type': 'application/json',
				server: 'cloudflare'
			}),
			text: async () =>
				JSON.stringify({ message: 'Your site security settings prevent this action.' })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(error).not.toBeNull();
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toContain('Your site security settings prevent this action.');
		expect(error?.textContent).not.toContain('site security layer');
	});

	it('shows a reload message when a cached page has an expired realtime token', async () => {
		vi.setSystemTime(new Date('2026-05-30T12:00:00Z'));
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig({
			config_expires_at: Math.floor(Date.now() / 1000) - 60
		});
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(fetchMock).not.toHaveBeenCalled();
		expect(error).not.toBeNull();
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toContain(
			'Suggestions could not refresh because this cached page is using an expired security token.'
		);
	});

	it('replaces nonce diagnostics with a reload-oriented visitor message', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: false,
			status: 403,
			headers: new Headers({ 'content-type': 'application/json' }),
			text: async () =>
				JSON.stringify({
					code: 'rest_cookie_invalid_nonce',
					message: 'Cookie check failed'
				})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(error).not.toBeNull();
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toContain('page security token expired');
		expect(error?.textContent).not.toContain('Cookie check failed');
	});

	it('hides realtime schema diagnostics behind a visitor-safe error message', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: false,
			status: 422,
			text: async () =>
				JSON.stringify({
					code: 'sentient_forms_structured_output_validation_failed',
					message:
						'The provider response did not match the local action schema: virtual_questions is a required property of structured_output.'
				})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(error).not.toBeNull();
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toContain(
			'Suggestions are temporarily unavailable. Try again shortly.'
		);
		expect(error?.textContent).not.toContain('virtual_questions');
		expect(error?.textContent).not.toContain('structured_output');
		expect(error?.textContent).not.toContain('local action schema');
	});

	it('renders suggestions when optional realtime arrays are absent from a successful response', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				suggestions: [
					{
						field_id: '1',
						severity: 'info',
						message: 'Add the requested quantity.',
						jump_target_field_id: '1'
					}
				]
			})
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(error?.hidden ?? true).toBe(true);
		expect(document.body.textContent).toContain('Add the requested quantity.');
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
		await evaluateRuntimeScript();

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

	it('runs page checkpoint suggestions before the next page click continues', async () => {
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
					auto_refresh_enabled: false,
					field_checkpoints_enabled: true,
					page_checkpoints_enabled: true,
					manual_refresh_enabled: true,
					checkpoint_field_ids: ['1']
				}
			]
		});
		await evaluateRuntimeScript();

		triggerNextPageClick();
		await flushRuntime();

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const requestInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
		const payload = JSON.parse(String(requestInit.body));
		expect(payload.request_reason).toBe('page_checkpoint');
		expect(payload.current_page_index).toBe(1);
	});

	it('bypasses cooldown for page checkpoint triggers', async () => {
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
						auto_refresh_enabled: false,
						field_checkpoints_enabled: true,
						page_checkpoints_enabled: true,
						manual_refresh_enabled: true,
						checkpoint_field_ids: ['1']
				}
			]
		});
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();
		expect(fetchMock).toHaveBeenCalledTimes(1);

		triggerNextPageClick();
		await flushRuntime();

		expect(fetchMock).toHaveBeenCalledTimes(2);
		const secondRequestInit = fetchMock.mock.calls[1]?.[1] as RequestInit;
		const secondPayload = JSON.parse(String(secondRequestInit.body));
		expect(secondPayload.request_reason).toBe('page_checkpoint');
		expect(secondPayload.current_page_index).toBe(1);
	});

	it('runs enabled mappings during Gravity Forms async pre-submit', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		let preSubmissionFilter: PreSubmissionFilter | null = null;
		const gform = {
			utils: {
				addAsyncFilter: vi.fn((_name: string, callback: PreSubmissionFilter) => {
					preSubmissionFilter = callback;
				})
			}
		};
		(window as unknown as { gform: typeof gform }).gform = gform;
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
						action_name_label: 'Realtime Action',
						debounce_ms: 100,
						cooldown_ms: 5000,
						auto_refresh_enabled: false,
						field_checkpoints_enabled: true,
						manual_refresh_enabled: true,
						storage_target_field_id: '9',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['1'],
					pre_submit_run_enabled: true,
					pre_submit_timeout_ms: 500
				}
			]
		});
		await evaluateRuntimeScript();

		const form = document.querySelector<HTMLFormElement>('#gform_42');
		expect(form).not.toBeNull();
		expect(preSubmissionFilter).not.toBeNull();
		const result = await preSubmissionFilter?.({ form: form as HTMLFormElement });

		expect(result?.abort).toBeUndefined();
		expect(fetchMock).toHaveBeenCalledTimes(1);
		const requestInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
		const payload = JSON.parse(String(requestInit.body));
		expect(payload.request_reason).toBe('pre_submit');
	});

	it('does not run pre-submit mappings during Gravity Forms pagination navigation', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		let preSubmissionFilter: PreSubmissionFilter | null = null;
		const gform = {
			utils: {
				addAsyncFilter: vi.fn((_name: string, callback: PreSubmissionFilter) => {
					preSubmissionFilter = callback;
				})
			}
		};
		(window as unknown as { gform: typeof gform }).gform = gform;
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
						action_name_label: 'Realtime Action',
						debounce_ms: 100,
						cooldown_ms: 5000,
						auto_refresh_enabled: false,
						field_checkpoints_enabled: true,
						manual_refresh_enabled: true,
						storage_target_field_id: '9',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['1'],
					pre_submit_run_enabled: true,
					pre_submit_timeout_ms: 500
				}
			]
		});
		await evaluateRuntimeScript();

		const form = document.querySelector<HTMLFormElement>('#gform_42');
		expect(form).not.toBeNull();
		const targetPageInput = document.createElement('input');
		targetPageInput.type = 'hidden';
		targetPageInput.id = 'gform_target_page_number_42';
		targetPageInput.value = '2';
		form?.appendChild(targetPageInput);

		const result = await preSubmissionFilter?.({ form: form as HTMLFormElement });

		expect(result?.abort).toBeUndefined();
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('fails open when pre-submit suggestions time out', async () => {
		const fetchMock = vi.fn((_url: string, init?: RequestInit) => {
			return new Promise((_resolve, reject) => {
				const signal = init?.signal as AbortSignal | undefined;
				signal?.addEventListener('abort', () => {
					const error = new Error('Aborted');
					error.name = 'AbortError';
					reject(error);
				});
			});
		});
		vi.stubGlobal('fetch', fetchMock);
		let preSubmissionFilter: PreSubmissionFilter | null = null;
		const gform = {
			utils: {
				addAsyncFilter: vi.fn((_name: string, callback: PreSubmissionFilter) => {
					preSubmissionFilter = callback;
				})
			}
		};
		(window as unknown as { gform: typeof gform }).gform = gform;
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
						action_name_label: 'Realtime Action',
						debounce_ms: 100,
						cooldown_ms: 5000,
						auto_refresh_enabled: false,
						field_checkpoints_enabled: true,
						manual_refresh_enabled: true,
						storage_target_field_id: '9',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['1'],
					pre_submit_run_enabled: true,
					pre_submit_timeout_ms: 500
				}
			]
		});
		await evaluateRuntimeScript();

		const form = document.querySelector<HTMLFormElement>('#gform_42');
		expect(form).not.toBeNull();
		const resultPromise = preSubmissionFilter?.({ form: form as HTMLFormElement });
		await vi.advanceTimersByTimeAsync(500);
		const result = await resultPromise;

		expect(result?.abort).toBeUndefined();
		const error = document.querySelector<HTMLElement>('[data-role="error"]');
		expect(error?.hidden).toBe(true);
	});

	it('does not run page-change suggestions when page index stays the same', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig();
		await evaluateRuntimeScript();

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
						auto_refresh_enabled: false,
						field_checkpoints_enabled: true,
						manual_refresh_enabled: true,
						checkpoint_field_ids: ['4']
				}
			],
			field_manifest: [{ field_id: '4', label: 'Details', type: 'textarea', page_index: 2 }]
		});
		await evaluateRuntimeScript();

		triggerBlurOnField('4');
		await flushRuntime();

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const requestInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
		const payload = JSON.parse(String(requestInit.body));
		expect(payload.current_page_index).toBe(2);
		expect(payload.visible_field_ids).toEqual(['4']);
	});

	it('carries prior page values into page 2 suggestion requests after a non-ajax page reload', async () => {
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
					auto_refresh_enabled: false,
					field_checkpoints_enabled: true,
					manual_refresh_enabled: true,
					storage_target_field_id: '9',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['4']
				}
			],
			field_manifest: [
				{ field_id: '1', label: 'Name', type: 'text', page_index: 1 },
				{ field_id: '4', label: 'Details', type: 'textarea', page_index: 2 },
				{ field_id: '9', label: 'Sentient Forms Realtime Q&A', type: 'hidden', page_index: 1 }
			]
		});
		await evaluateRuntimeScript();

		const pageOneInput = document.querySelector<HTMLInputElement>('input[name="input_1"]');
		expect(pageOneInput).not.toBeNull();
		if (pageOneInput) {
			pageOneInput.value = 'Need a quote for machined aluminum brackets';
			pageOneInput.dispatchEvent(new Event('input', { bubbles: true }));
			pageOneInput.dispatchEvent(new Event('change', { bubbles: true }));
		}
		await flushRuntime(20);

		delete (window as Record<string, unknown>).__sentientRealtimeSuggestionsRuntime;
		document.body.innerHTML = `
			<form id="gform_42">
				<input type="hidden" id="gform_source_page_number_42" value="2" />
				<div id="gform_page_42_2" class="gform_page">
					<div id="field_42_4" class="gfield">
						<textarea name="input_4">Need 500 pieces in two weeks</textarea>
					</div>
				</div>
				<div id="field_42_9" class="gfield" style="display:none">
					<textarea id="input_42_9" name="input_9"></textarea>
				</div>
			</form>
		`;
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 0,
					auto_refresh_enabled: false,
					field_checkpoints_enabled: true,
					manual_refresh_enabled: true,
					storage_target_field_id: '9',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['4']
				}
			],
			field_manifest: [
				{ field_id: '1', label: 'Name', type: 'text', page_index: 1 },
				{ field_id: '4', label: 'Details', type: 'textarea', page_index: 2 },
				{ field_id: '9', label: 'Sentient Forms Realtime Q&A', type: 'hidden', page_index: 1 }
			]
		});
		await evaluateRuntimeScript();

		triggerBlurOnField('4');
		await flushRuntime();

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const requestInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
		const payload = JSON.parse(String(requestInit.body));
		expect(payload.current_page_index).toBe(2);
		expect(payload.visible_field_ids).toEqual(['4']);
		expect(payload.all_known_field_values).toMatchObject({
			'1': 'Need a quote for machined aluminum brackets',
			'4': 'Need 500 pieces in two weeks'
		});
		expect(payload.all_known_field_values).not.toHaveProperty('9');
	});

	it('keeps current visible field values when field manifest metadata is incomplete', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ suggestions: [] })
		});
		vi.stubGlobal('fetch', fetchMock);
		setupRuntimeConfig({
			field_manifest: []
		});
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const requestInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
		const payload = JSON.parse(String(requestInit.body));
		expect(payload.visible_field_ids).toEqual(['1']);
		expect(payload.all_known_field_values).toEqual({
			'1': 'hello'
		});
		expect(payload.all_known_field_values).not.toHaveProperty('2');
		expect(payload.all_known_field_values).not.toHaveProperty('9');
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
						auto_refresh_enabled: false,
						field_checkpoints_enabled: true,
						page_checkpoints_enabled: true,
						manual_refresh_enabled: true,
						checkpoint_field_ids: ['1']
				}
			],
			field_manifest: [{ field_id: '4', label: 'Details', type: 'textarea', page_index: 2 }]
		});
		await evaluateRuntimeScript();
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
		await evaluateRuntimeScript();

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
		await evaluateRuntimeScript();

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

	it('creates a real fallback hidden input when no storage target is configured', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				suggestions: [],
				virtual_questions: [
					{
						question_id: 'fallback-context',
						question: 'What should support know?',
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
						auto_refresh_enabled: false,
						field_checkpoints_enabled: true,
						manual_refresh_enabled: true,
						storage_target_field_id: '',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['1']
				}
			]
		});
		await evaluateRuntimeScript();

		triggerBlurOnField('1');
		await flushRuntime();

		const answer = document.querySelector<HTMLInputElement>(
			'[data-role="answer-question"][data-question-id="fallback-context"]'
		);
		expect(answer).not.toBeNull();
		answer!.value = 'Please call tomorrow.';
		answer?.dispatchEvent(new Event('input', { bubbles: true }));

		const fallback = document.querySelector<HTMLInputElement>(
			'input[name="sentient_forms_realtime_qna_42"]'
		);
		expect(fallback).not.toBeNull();
		const stored = JSON.parse(fallback?.value ?? '{}');
		expect(stored.mappings[0].questions[0].answer).toBe('Please call tomorrow.');
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
		await evaluateRuntimeScript();

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

		const secondRequestInit = fetchMock.mock.calls[1]?.[1] as RequestInit;
		const secondPayload = JSON.parse(String(secondRequestInit.body));
		expect(secondPayload.panel_state.virtual_questions[0]).toMatchObject({
			question_id: 'affected-url',
			answer: 'https://example.test/pricing'
		});

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
		setupRuntimeConfig({
			mappings: [
				{
					mapping_id: 'map_rt_1',
					central_action_id: 'central_rt_1',
					action_name_label: 'Realtime Action',
					debounce_ms: 100,
					cooldown_ms: 0,
					auto_refresh_enabled: false,
					field_checkpoints_enabled: true,
					page_checkpoints_enabled: true,
					manual_refresh_enabled: true,
					storage_target_field_id: '9',
					blocking_mode: 'advisory',
					checkpoint_field_ids: ['1']
				}
			]
		});
		await evaluateRuntimeScript();

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

		await evaluateRuntimeScript();
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
		await evaluateRuntimeScript();

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
						auto_refresh_enabled: false,
						field_checkpoints_enabled: true,
						manual_refresh_enabled: true,
						storage_target_field_id: '9',
					blocking_mode: 'require_answers',
					checkpoint_field_ids: ['1']
				}
			]
		});
		await evaluateRuntimeScript();

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
						auto_refresh_enabled: false,
						field_checkpoints_enabled: true,
						manual_refresh_enabled: true,
						storage_target_field_id: '9',
					blocking_mode: 'require_answers',
					checkpoint_field_ids: ['1']
				}
			]
		});
		await evaluateRuntimeScript();

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
						auto_refresh_enabled: false,
						field_checkpoints_enabled: true,
						manual_refresh_enabled: false,
						checkpoint_field_ids: ['1']
				}
			]
		});
		await evaluateRuntimeScript();

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
		await evaluateRuntimeScript();

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
