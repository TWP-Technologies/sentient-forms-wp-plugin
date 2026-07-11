import { z } from 'zod';
const providerSchema = z.string().min(1);

const nullableProviderSchema = providerSchema.nullable();
const nullableStringSchema = z.string().nullable();
const nullableCredentialIdSchema = z.number().int().nonnegative().nullable();

const modelReasoningSchema = z
	.union([
		z.string(),
		z.looseObject({
			effort: z.string().optional(),
			max_tokens: z.number().int().positive().optional(),
			exclude: z.boolean().optional(),
			enabled: z.boolean().optional()
		})
	])
	.nullable()
	.optional();

const modelSelectionSchema = z.looseObject({
	primary: z.string().min(1),
	backup: nullableStringSchema.optional(),
	is_preset: z.boolean(),
	provider: nullableProviderSchema.optional(),
	credential_id: nullableCredentialIdSchema.optional(),
	require_zdr: z.boolean().optional(),
	managed_zdr_required: z.boolean().optional(),
	reasoning: modelReasoningSchema,
	tools: z.record(z.string(), z.json()).nullable().optional()
});

const providerPathPolicyModelSelectionSchema = z.looseObject({
	provider: providerSchema,
	model: nullableStringSchema.optional(),
	credential_id: nullableCredentialIdSchema.optional(),
	selection: modelSelectionSchema.optional(),
	backup_provider: nullableProviderSchema.optional(),
	backup_credential_id: nullableCredentialIdSchema.optional(),
	backup_model: nullableStringSchema.optional()
});

const providerPathPolicyProviderStatusSchema = z.looseObject({
	ready: z.boolean(),
	credential_id: nullableCredentialIdSchema,
	blocked_reason_code: nullableStringSchema.optional()
});

const providerPathPolicyActionSchema = z.looseObject({
	selected_provider: nullableProviderSchema,
	model_selection: providerPathPolicyModelSelectionSchema.nullable(),
	blocked_reason_code: nullableStringSchema,
	requires_structured_output: z.boolean()
});

export const providerPathPolicyResponseSchema = z.looseObject({
	default_provider: nullableProviderSchema,
	providers: z.looseObject({
		sentient_managed: providerPathPolicyProviderStatusSchema,
		openrouter: providerPathPolicyProviderStatusSchema
	}),
	actions: z.record(z.string(), providerPathPolicyActionSchema)
});

export type ProviderPathPolicyBoundary = z.output<typeof providerPathPolicyResponseSchema>;

export function parseProviderPathPolicy(value: unknown): ProviderPathPolicyBoundary | undefined {
	if (value === null || typeof value === 'undefined') {
		return undefined;
	}

	const result = providerPathPolicyResponseSchema.safeParse(value);
	return result.success ? result.data : undefined;
}
