import { z } from 'zod';

export const modelSelectionSchema = z.strictObject({
	primary: z.string().min(1),
	backup: z.string().min(1).nullable().optional(),
	is_preset: z.boolean(),
	provider: z.enum(['openrouter', 'sentient_managed']).nullable().optional(),
	credential_id: z.number().int().positive().nullable().optional(),
	require_zdr: z.boolean().optional(),
	managed_zdr_required: z.boolean().optional(),
	reasoning: z
		.union([
			z.enum(['none', 'minimal', 'low', 'medium', 'high', 'xhigh']),
			z.strictObject({
				effort: z.enum(['none', 'minimal', 'low', 'medium', 'high', 'xhigh']).optional(),
				max_tokens: z.number().int().positive().optional(),
				exclude: z.boolean().optional(),
				enabled: z.boolean().optional()
			}),
			z.null()
		])
		.optional(),
	tools: z.record(z.string(), z.json()).nullable().optional()
});

export type ModelSelectionBoundary = z.output<typeof modelSelectionSchema>;
