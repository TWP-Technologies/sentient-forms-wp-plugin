import { describe, expect, it } from 'vitest';
import {
	deriveDraftExecutionKind,
	isSpamActionCode,
	normalizeFormActionConfig,
	normalizeModelSelection,
	normalizeSpamIndicatorsDisplay,
	normalizeSpamResultDisplayMode,
	resolveModelSelectionChain
} from '$lib/utils/action-config';
import {
	normalizeSpamGuidanceExamples,
	safeParseFormActionConfigPayload,
	validateFormActionConfig
} from '$lib/schemas/action-config';

describe('action config helpers', () => {
	it('treats both official and legacy spam identifiers as spam actions', () => {
		expect(isSpamActionCode('spam_detection_v1')).toBe(true);
		expect(isSpamActionCode('spam_analysis')).toBe(true);
		expect(isSpamActionCode('entry_summary_v1')).toBe(false);
	});

	it('normalizes spam note display modes to canonical backend values', () => {
		expect(normalizeSpamResultDisplayMode('all_results')).toBe('all_results');
		expect(normalizeSpamResultDisplayMode('none')).toBe('none');
		expect(normalizeSpamResultDisplayMode('spam_only')).toBe('spam_only');
		expect(normalizeSpamResultDisplayMode('unexpected')).toBe('all_results');
	});

	it('normalizes spam indicator display modes', () => {
		expect(normalizeSpamIndicatorsDisplay('detailed')).toBe('detailed');
		expect(normalizeSpamIndicatorsDisplay('unexpected')).toBe('simple');
	});

	it('derives execution kind from hooks before stale mapping settings', () => {
		expect(deriveDraftExecutionKind(['gform_validation'], 'after_submission')).toBe('blocking');
		expect(deriveDraftExecutionKind(['gform_after_submission'], 'sync')).toBe('background');
		expect(deriveDraftExecutionKind(['gform_validation', 'gform_after_submission'], 'async')).toBe(
			'mixed'
		);
		expect(deriveDraftExecutionKind([], 'after_submission')).toBe('background');
		expect(deriveDraftExecutionKind([], 'validation')).toBe('blocking');
	});

	it('preserves restored spam note defaults in normalized form configs', () => {
		expect(
			normalizeFormActionConfig({
				spam_result_display_mode: 'all_results',
				spam_indicators_display: 'detailed',
				action_customization: '  Keep summaries under three sentences.  '
			})
		).toMatchObject({
			spam_result_display_mode: 'all_results',
			spam_indicators_display: 'detailed',
			action_customization: 'Keep summaries under three sentences.'
		});
	});

	it('preserves execution route metadata when normalizing model selections', () => {
		expect(
			normalizeModelSelection({
				primary: 'sf_default',
				backup: '',
				is_preset: true,
				provider: 'sentient_managed',
				credential_id: '42',
				reasoning: 'high'
			})
		).toEqual({
			primary: 'sf_default',
			backup: null,
			is_preset: true,
			provider: 'sentient_managed',
			credential_id: 42,
			reasoning: 'high'
		});
	});

	it('drops invalid reasoning efforts while keeping the model selection', () => {
		expect(
			normalizeModelSelection({
				primary: 'openai/gpt-5.5',
				is_preset: false,
				reasoning: 'extreme'
			})
		).toEqual({
			primary: 'openai/gpt-5.5',
			backup: null,
			is_preset: false
		});
	});

	it('preserves model tool settings without leaking inherited defaults', () => {
		expect(
			normalizeModelSelection({
				primary: 'google/gemini-3-flash-preview',
				is_preset: false,
				tools: {
					tool_choice: 'auto',
					web_search: { mode: 'required', max_results: 25 },
					web_fetch: { mode: 'inherit' },
					datetime: { mode: 'off' }
				}
			})
		).toMatchObject({
			primary: 'google/gemini-3-flash-preview',
			tools: {
				tool_choice: 'auto',
				web_search: { mode: 'required', max_results: 10 },
				datetime: { mode: 'off' }
			}
		});
	});

	it('resolves model defaults from most local to most global scope', () => {
		const action = { primary: 'openai/gpt-5.5', backup: null, is_preset: false };
		const form = { primary: 'google/gemini-3-flash-preview', backup: null, is_preset: false };
		const mapping = { primary: 'anthropic/claude-opus-4.7', backup: null, is_preset: false };

		expect(resolveModelSelectionChain({ action }).source).toBe('action');
		expect(resolveModelSelectionChain({ action, form }).selection.primary).toBe(form.primary);
		expect(resolveModelSelectionChain({ action, form, mapping })).toEqual({
			selection: mapping,
			source: 'mapping'
		});
	});

	it('accepts strict action config payloads for spam guidance and suppression controls', () => {
		const result = safeParseFormActionConfigPayload({
			include_site_context: 'always',
			action_customization: 'Catalog and phone-number requests are spam for this site.',
			spam_positive_examples: [
				{
					text: 'I need warranty help for order SF-1001.',
					rationale: 'Specific existing-customer support request.',
					source: {
						kind: 'entry',
						form_source: 'gravity_forms',
						form_id: '7',
						entry_id: '123',
						native_entry_id: '123',
						selected_at: '2026-07-01T12:00:00Z',
						selected_by_user_id: 5
					}
				}
			],
			spam_negative_examples: [
				{
					text: 'hey I am interested can you send me a catalog and your phone number',
					rationale: 'Known vague lead-harvesting pattern for this customer.'
				}
			],
			suppress_notifications_on_spam: true,
			suppress_webhooks_on_spam: true,
			skip_downstream_on_spam: true,
			spam_result_display_mode: 'spam_only',
			spam_indicators_display: 'detailed'
		});

		expect(result.success).toBe(true);
		if (result.success) {
			expect(result.data.spam_negative_examples?.[0]?.rationale).toContain('lead-harvesting');
			expect(result.data.spam_positive_examples?.[0]?.source?.kind).toBe('entry');
			expect(result.data.action_customization).toContain('phone-number requests');
			expect(result.data.suppress_webhooks_on_spam).toBe(true);
		}
	});

	it('rejects legacy or malformed action config payloads before REST writes', () => {
		expect(
			safeParseFormActionConfigPayload({
				spam_positive_examples: ['legacy string example']
			}).success
		).toBe(false);
		expect(
			safeParseFormActionConfigPayload({
				spam_negative_examples: [{ text: 'Missing rationale' }]
			}).success
		).toBe(false);
		expect(
			safeParseFormActionConfigPayload({
				suppress_webhooks_on_spam: 'true'
			}).success
		).toBe(false);
		expect(
			safeParseFormActionConfigPayload({
				action_customization: 'x'.repeat(2001)
			}).success
		).toBe(false);
		expect(
			safeParseFormActionConfigPayload({
				webmaster_trusted_context: 'legacy public key'
			}).success
		).toBe(false);
		expect(
			safeParseFormActionConfigPayload({
				spam_positive_examples: Array.from({ length: 11 }, (_, index) => ({
					text: `Example ${index}`,
					rationale: `Rationale ${index}`
				}))
			}).success
		).toBe(false);
		expect(
			safeParseFormActionConfigPayload({
				spam_positive_examples: [
					{
						text: 'Bad source',
						rationale: 'Malformed provenance should not be accepted.',
						source: { kind: 'remote_import' }
					}
				]
			}).success
		).toBe(false);
	});

	it('keeps read normalization tolerant while write validation stays strict', () => {
		expect(normalizeSpamGuidanceExamples(['legacy string example'])).toEqual([]);
		expect(
			validateFormActionConfig({
				spam_negative_examples: [{ text: 'Missing rationale' }]
			})
		).toEqual({
			include_site_context: 'global',
			spam_positive_examples: [],
			spam_negative_examples: []
		});
	});
});
