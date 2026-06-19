import { describe, expect, it } from 'vitest';
import {
	SITE_CONTEXT_DEFAULT_TOOLS,
	compactSiteContextModelSelection,
	normalizeSiteContextModelSelection,
	normalizeSiteContextResponse,
	siteContextModelSelectionChanged
} from '$lib/utils/site-context';
import { parseSiteContextStatusResponse } from '$lib/schemas/site-context';

describe('normalizeSiteContextResponse', () => {
	it('does not claim consent is missing for legacy context responses with consent acknowledged', () => {
		const status = normalizeSiteContextResponse({
			id: 'ctx-1',
			license_id: 'lic-1',
			summary_text: 'Legacy context payload.',
			source: 'manual',
			auto_include: true,
			pii_ack: true,
			free_refresh_available: true,
			next_free_refresh_at: null,
			created_at: '2026-05-28T00:00:00Z',
			updated_at: '2026-05-28T00:00:00Z'
		});

		expect(status.settings.consent_status).toBe('granted');
		expect(status.generation_access.can_generate).toBe(false);
		expect(status.generation_access.reason_code).toBe('site_context_generation_setup_required');
		expect(status.generation_access.message).toContain('paid OpenRouter key');
	});

	it('does not claim consent is missing for legacy wrapped responses with consent granted', () => {
		const status = normalizeSiteContextResponse({
			context: null,
			settings: {
				consent_status: 'granted',
				generation_model_selection: {
					primary: 'openai/gpt-5.5',
					provider: 'openrouter',
					is_preset: false
				}
			},
			has_context: false,
			is_empty: true,
			status: 'empty'
		});

		expect(status.settings.consent_status).toBe('granted');
		expect(status.generation_access.can_generate).toBe(false);
		expect(status.generation_access.reason_code).toBe('site_context_generation_setup_required');
		expect(status.generation_access.setup_target).toBe('providers');
		expect(status.generation_access.provider).toBe('openrouter');
		expect(status.generation_access.model).toBe('openai/gpt-5.5');
	});

	it('preserves latest-alias model selection, reasoning, tools, and background job state through schema parsing', () => {
		const status = parseSiteContextStatusResponse({
			context: null,
			settings: {
				consent_status: 'granted',
				consented_at: '2026-06-18T00:00:00Z',
				declined_at: null,
				auto_refresh_enabled: false,
				auto_refresh_days: 30,
				next_refresh_at: null,
				last_generated_at: null,
				last_error: null,
				generation_model_selection: {
					primary: '~google/gemini-pro-latest',
					is_preset: false,
					provider: 'openrouter',
					credential_id: 1,
					reasoning: {
						effort: 'xhigh',
						exclude: true
					},
					tools: {
						tool_choice: 'auto',
						web_search: { mode: 'required', max_results: 5 },
						web_fetch: { mode: 'off' },
						datetime: { mode: 'required' }
					}
				}
			},
			has_context: false,
			is_empty: true,
			is_stale: false,
			stale_after_days: 90,
			status: 'empty',
			generation_access: {
				can_generate: true,
				reason_code: 'ready',
				message: 'Ready.',
				setup_target: null,
				provider: 'openrouter',
				model: '~google/gemini-pro-latest',
				credential_id: 1
			},
			generation_job: {
				id: 'site-context-abc',
				status: 'running',
				requested_at: '2026-06-18T00:00:00Z',
				started_at: '2026-06-18T00:00:01Z',
				finished_at: null,
				error: null,
				status_code: 0,
				attempts: 1,
				max_attempts: 2
			}
		});

		expect(status.settings.generation_model_selection).toMatchObject({
			primary: '~google/gemini-pro-latest',
			provider: 'openrouter',
			credential_id: 1,
			reasoning: {
				effort: 'xhigh',
				exclude: true
			},
			tools: {
				tool_choice: 'auto',
				web_search: { mode: 'required', max_results: 5 },
				web_fetch: { mode: 'off' },
				datetime: { mode: 'required' }
			}
		});
		expect(status.generation_job).toMatchObject({
			id: 'site-context-abc',
			status: 'running',
			status_code: 0,
			attempts: 1,
			max_attempts: 2
		});
	});

	it('clamps legacy saved Site Context web-search depth during schema parsing', () => {
		const status = parseSiteContextStatusResponse({
			context: null,
			settings: {
				consent_status: 'granted',
				consented_at: '2026-06-18T00:00:00Z',
				declined_at: null,
				auto_refresh_enabled: false,
				auto_refresh_days: 30,
				next_refresh_at: null,
				last_generated_at: null,
				last_error: null,
				generation_model_selection: {
					primary: '~google/gemini-pro-latest',
					is_preset: false,
					provider: 'openrouter',
					credential_id: 1,
					tools: {
						tool_choice: 'auto',
						web_search: { mode: 'required', max_results: 8 }
					}
				}
			},
			has_context: false,
			is_empty: true,
			is_stale: false,
			stale_after_days: 90,
			status: 'empty',
			generation_access: {
				can_generate: true,
				reason_code: 'ready',
				message: 'Ready.',
				setup_target: null,
				provider: 'openrouter',
				model: '~google/gemini-pro-latest',
				credential_id: 1
			},
			generation_job: null
		});

		expect(status.settings.generation_model_selection?.tools).toMatchObject({
			web_search: { mode: 'required', max_results: 5 }
		});
	});

	it('accepts empty generation job diagnostics arrays from PHP responses', () => {
		const status = parseSiteContextStatusResponse({
			context: null,
			settings: {
				consent_status: 'granted',
				consented_at: '2026-06-18T00:00:00Z',
				declined_at: null,
				auto_refresh_enabled: false,
				auto_refresh_days: 30,
				next_refresh_at: null,
				last_generated_at: null,
				last_error: null
			},
			has_context: false,
			is_empty: true,
			is_stale: false,
			stale_after_days: 90,
			status: 'empty',
			generation_access: {
				can_generate: true,
				reason_code: 'ready',
				message: 'Ready.',
				setup_target: null,
				provider: 'openrouter',
				model: 'openai/gpt-5.5',
				credential_id: 1
			},
			generation_job: {
				id: 'site-context-queued',
				status: 'queued',
				requested_at: '2026-06-18T00:00:00Z',
				started_at: null,
				finished_at: null,
				error: null,
				diagnostics: []
			}
		});

		expect(status.generation_job?.diagnostics).toEqual({});
	});
});

describe('siteContextModelSelectionChanged', () => {
	it('treats semantically identical model selections as unchanged when key order differs', () => {
		expect(
			siteContextModelSelectionChanged(
				{
					primary: 'sf_research',
					is_preset: true,
					provider: 'openrouter',
					tools: {
						tool_choice: 'auto',
						web_search: {
							mode: 'required',
							max_results: 5
						}
					}
				},
				{
					provider: 'openrouter',
					tools: {
						web_search: {
							max_results: 5,
							mode: 'required'
						},
						tool_choice: 'auto'
					},
					is_preset: true,
					primary: 'sf_research'
				}
			)
		).toBe(false);
	});

	it('treats omitted default tool settings as unchanged', () => {
		expect(
			siteContextModelSelectionChanged(
				{
					primary: 'sf_research',
					is_preset: true,
					provider: 'openrouter'
				},
				{
					primary: 'sf_research',
					is_preset: true,
					provider: 'openrouter',
					tools: {
						tool_choice: 'auto',
						web_search: {
							mode: 'required',
							max_results: 5
						}
					}
				}
			)
		).toBe(false);
	});

	it('omits default tool settings from outgoing Site Context payloads', () => {
		expect(
			compactSiteContextModelSelection({
				primary: 'sf_research',
				is_preset: true,
				provider: 'openrouter',
				tools: {
					tool_choice: 'auto',
					web_search: {
						mode: 'required',
						max_results: 5
					}
				}
			})
		).toEqual({
			primary: 'sf_research',
			is_preset: true,
			provider: 'openrouter'
		});
	});

	it('does not enable OpenRouter server-fetch by default', () => {
		expect(SITE_CONTEXT_DEFAULT_TOOLS).toEqual({
			tool_choice: 'auto',
			web_search: {
				mode: 'required',
				max_results: 5
			}
		});
	});

	it('falls back to default tool settings for malformed tool payloads', () => {
		expect(
			normalizeSiteContextModelSelection({
				primary: 'sf_research',
				is_preset: true,
				provider: 'openrouter',
				tools: ['not-a-record']
			} as unknown as Parameters<typeof normalizeSiteContextModelSelection>[0]).tools
		).toEqual(SITE_CONTEXT_DEFAULT_TOOLS);
	});
});
