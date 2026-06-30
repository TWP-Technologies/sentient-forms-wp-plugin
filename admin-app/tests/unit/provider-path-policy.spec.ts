import { describe, expect, it } from 'vitest';
import { parseProviderPathPolicy, providerPathPolicyResponseSchema } from '$lib/schemas/provider-path-policy';

describe('provider path policy schema', () => {
	it('preserves managed default route with an OpenRouter backup', () => {
		const result = providerPathPolicyResponseSchema.safeParse({
			default_provider: 'sentient_managed',
			providers: {
				sentient_managed: {
					ready: true,
					credential_id: 14,
					blocked_reason_code: null
				},
				openrouter: {
					ready: true,
					credential_id: 7,
					blocked_reason_code: null
				}
			},
			actions: {
				spam_detection_v1: {
					selected_provider: 'sentient_managed',
					model_selection: {
						provider: 'sentient_managed',
						model: 'sf_default',
						credential_id: 14,
						selection: {
							primary: 'sf_default',
							provider: 'sentient_managed',
							is_preset: true,
							credential_id: 14
						},
						backup_provider: 'openrouter',
						backup_credential_id: 7,
						backup_model: '~openai/gpt-latest'
					},
					blocked_reason_code: null,
					requires_structured_output: true
				}
			}
		});

		expect(result.success).toBe(true);
		if (result.success) {
			expect(result.data.default_provider).toBe('sentient_managed');
			expect(result.data.actions.spam_detection_v1.model_selection).toMatchObject({
				provider: 'sentient_managed',
				backup_provider: 'openrouter',
				backup_model: '~openai/gpt-latest'
			});
		}
	});

	it('keeps blocked built-in action reasons explicit', () => {
		const parsed = parseProviderPathPolicy({
			default_provider: null,
			providers: {
				sentient_managed: {
					ready: false,
					credential_id: null,
					blocked_reason_code: null
				},
				openrouter: {
					ready: false,
					credential_id: null,
					blocked_reason_code: 'structured_openrouter_model_unavailable'
				}
			},
			actions: {
				spam_detection_v1: {
					selected_provider: null,
					model_selection: null,
					blocked_reason_code: 'structured_openrouter_model_unavailable',
					requires_structured_output: true
				}
			}
		});

		expect(parsed?.actions.spam_detection_v1).toMatchObject({
			selected_provider: null,
			blocked_reason_code: 'structured_openrouter_model_unavailable',
			requires_structured_output: true
		});
	});

	it('rejects malformed policy payloads instead of treating unknown JSON as ready', () => {
		expect(parseProviderPathPolicy({ providers: [], actions: null })).toBeUndefined();
	});
});
