import { z } from 'zod';
import { modelSelectionSchema } from '$lib/schemas/model-selection';

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

export const spamGuidanceExampleSourceSchema = z.strictObject({
	kind: z.enum(['manual', 'entry']),
	form_source: z.string().trim().min(1).optional(),
	form_id: z.string().trim().min(1).optional(),
	entry_id: z.string().trim().min(1).optional(),
	native_entry_id: z.string().trim().min(1).nullable().optional(),
	selected_at: z.string().trim().min(1).optional(),
	selected_by_user_id: z.number().int().nullable().optional()
});

export const spamGuidanceExampleSchema = z.strictObject({
	text: spamGuidanceTextSchema,
	rationale: spamGuidanceTextSchema,
	source: spamGuidanceExampleSourceSchema.optional()
});

const spamGuidanceExamplesSchema = z
	.array(spamGuidanceExampleSchema)
	.max(MAX_SPAM_GUIDANCE_EXAMPLES, {
		error: `Must contain at most ${MAX_SPAM_GUIDANCE_EXAMPLES} items`
	});

export const formActionConfigPayloadSchema = z.strictObject({
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
	model_selection: modelSelectionSchema.optional(),
	model_override: z.string().optional(),
	realtime_settings: z.json().optional(),
	updated_at: z.string().optional()
});

export type SpamGuidanceExampleSourceBoundary = z.output<typeof spamGuidanceExampleSourceSchema>;
export type SpamGuidanceExampleBoundary = z.output<typeof spamGuidanceExampleSchema>;
export type FormActionConfigBoundary = z.output<typeof formActionConfigPayloadSchema>;

export const formActionConfigSchema = {
	safeParse: safeParseFormActionConfigPayload
};

export function normalizeSpamGuidanceExamples(value: unknown): SpamGuidanceExampleBoundary[] {
	const result = spamGuidanceExamplesSchema.safeParse(value);
	return result.success === true ? result.data : [];
}

export function validateFormActionConfig(value: unknown): FormActionConfigBoundary {
	const result = safeParseFormActionConfigPayload(value);
	if (result.success === false) {
		console.warn('[SentientForms] Rejected form action config payload.', {
			schema: 'formActionConfigPayload',
			issues: result.error.issues.map((issue) => ({
				code: issue.code,
				path: issue.path.filter(
					(segment): segment is string | number =>
						typeof segment === 'string' || typeof segment === 'number'
				)
			}))
		});
		return {
			include_site_context: 'global',
			spam_positive_examples: [],
			spam_negative_examples: []
		};
	}

	return result.data;
}

export function safeParseFormActionConfigPayload(value: unknown) {
	return formActionConfigPayloadSchema.safeParse(value);
}
