import { describe, expect, it } from 'vitest';
import {
	normalizeSiteContextResponse,
	siteContextModelSelectionChanged
} from '$lib/utils/site-context';

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
						},
						web_fetch: {
							mode: 'auto'
						}
					}
				},
				{
					provider: 'openrouter',
					tools: {
						web_fetch: {
							mode: 'auto'
						},
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
});
