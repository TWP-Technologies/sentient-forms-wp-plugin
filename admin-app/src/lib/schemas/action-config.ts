import { z } from 'zod';
import type { FormActionConfig, SpamGuidanceExample } from '$lib/api/types';

export const MAX_SPAM_GUIDANCE_EXAMPLES = 10;
export const MAX_SPAM_GUIDANCE_TEXT_LENGTH = 800;
export const MAX_ACTION_CUSTOMIZATION_LENGTH = 2000;

const spamGuidanceTextSchema = z
	.string()
	.trim()
	.min(1, { error: `Must contain between 1 and ${MAX_SPAM_GUIDANCE_TEXT_LENGTH} characters` })
	.max(MAX_SPAM_GUIDANCE_TEXT_LENGTH, {
		error: `Must contain between 1 and ${MAX_SPAM_GUIDANCE_TEXT_LENGTH} characters`
	});

const spamGuidanceExampleSchema = z.strictObject({
	text: spamGuidanceTextSchema,
	rationale: spamGuidanceTextSchema
});

const spamGuidanceExamplesSchema = z
	.array(spamGuidanceExampleSchema)
	.max(MAX_SPAM_GUIDANCE_EXAMPLES, {
		error: `Must contain at most ${MAX_SPAM_GUIDANCE_EXAMPLES} items`
	});

const formActionConfigPayloadSchema = z
	.strictObject({
		spam_positive_examples: spamGuidanceExamplesSchema.optional(),
		spam_negative_examples: spamGuidanceExamplesSchema.optional(),
		action_customization: z
			.string()
			.trim()
			.max(MAX_ACTION_CUSTOMIZATION_LENGTH, {
				error: `Must contain at most ${MAX_ACTION_CUSTOMIZATION_LENGTH} characters`
			})
			.optional(),
		include_site_context: z.enum(['global', 'always', 'never']).optional(),
		suppress_notifications_on_spam: z.boolean().optional(),
		suppress_webhooks_on_spam: z.boolean().optional(),
		skip_downstream_on_spam: z.boolean().optional(),
		spam_result_display_mode: z.enum(['none', 'spam_only', 'all_results']).optional(),
		spam_indicators_display: z.enum(['simple', 'detailed']).optional(),
		model_selection: z.unknown().optional(),
		realtime_settings: z.unknown().optional(),
		updated_at: z.string().optional()
	})
	.transform((value) => value as Partial<FormActionConfig>);

export const formActionConfigSchema = {
	safeParse: safeParseFormActionConfigPayload
};

export function normalizeSpamGuidanceExamples(value: unknown): SpamGuidanceExample[] {
	const result = spamGuidanceExamplesSchema.safeParse(value);
	return result.success === true ? result.data : [];
}

export function validateFormActionConfig(value: unknown): FormActionConfig {
	const result = safeParseFormActionConfigPayload(value);
	if (result.success === false) {
		return {
			include_site_context: 'global',
			spam_positive_examples: [],
			spam_negative_examples: []
		};
	}

	return result.data as FormActionConfig;
}

export function safeParseFormActionConfigPayload(value: unknown) {
	return formActionConfigPayloadSchema.safeParse(value);
}
