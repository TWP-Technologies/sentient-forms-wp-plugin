import { z } from 'zod';

const delimiterPattern = /[\s,;\t|]+/;
const emailSchema = z.email();
const webhookSchema = z.url({ protocol: /^https?$/ });
export const leadGradeSchema = z.enum(['A', 'B', 'C', 'Reject']);
export const leadProfileGenerationSettingsSchema = z.object({
	model: z.enum(['~openai/gpt-latest', '~google/gemini-pro-latest', '~anthropic/claude-opus-latest']),
	reasoning_effort: z.literal('xhigh').optional()
});
export const leadProfileSelfImprovementSchema = z.object({
	consent: z.boolean(),
	frequency: z.enum(['manual', 'weekly', 'monthly']),
	review_required: z.boolean().optional()
});
export const leadProfileReplyRulesSchema = z.object({
	skip_reject_grade: z.boolean()
});
export const leadScoringCorrectionSchema = z.object({
	grade: leadGradeSchema,
	justification: z.string().trim().min(12).max(6000)
});

export function parseDelimitedList(value: string): string[] {
	return Array.from(
		new Set(
			value
				.split(delimiterPattern)
				.map((item) => item.trim())
				.filter(Boolean)
		)
	);
}

export function validateEmailTags(values: string[]): { values: string[]; errors: string[] } {
	const accepted: string[] = [];
	const errors: string[] = [];

	for (const value of values) {
		const result = emailSchema.safeParse(value);
		if (result.success) {
			accepted.push(result.data);
		} else {
			errors.push(`${value}: ${result.error.issues[0]?.message ?? 'Invalid email'}`);
		}
	}

	return { values: Array.from(new Set(accepted)), errors };
}

export function validateWebhookTags(values: string[]): { values: string[]; errors: string[] } {
	const accepted: string[] = [];
	const errors: string[] = [];

	for (const value of values) {
		const result = webhookSchema.safeParse(value);
		if (result.success) {
			accepted.push(result.data);
		} else {
			errors.push(`${value}: ${result.error.issues[0]?.message ?? 'Invalid HTTP URL'}`);
		}
	}

	return { values: Array.from(new Set(accepted)), errors };
}
