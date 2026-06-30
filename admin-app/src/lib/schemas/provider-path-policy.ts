import { z } from 'zod';
import type {
	LocalProvider,
	ModelSelection,
	ProviderPathPolicyModelSelection,
	ProviderPathPolicyResponse
} from '$lib/api/types';

const providerSchema = z
	.string()
	.min(1)
	.transform((value) => value as LocalProvider);

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

const modelSelectionSchema = z
	.looseObject({
		primary: z.string().min(1),
		backup: nullableStringSchema.optional(),
		is_preset: z.boolean(),
		provider: nullableProviderSchema.optional(),
		credential_id: nullableCredentialIdSchema.optional(),
		require_zdr: z.boolean().optional(),
		managed_zdr_required: z.boolean().optional(),
		reasoning: modelReasoningSchema,
		tools: z.record(z.string(), z.unknown()).nullable().optional()
	})
	.transform((value) => value as ModelSelection);

const providerPathPolicyModelSelectionSchema = z
	.looseObject({
		provider: providerSchema,
		model: nullableStringSchema.optional(),
		credential_id: nullableCredentialIdSchema.optional(),
		selection: modelSelectionSchema.optional(),
		backup_provider: nullableProviderSchema.optional(),
		backup_credential_id: nullableCredentialIdSchema.optional(),
		backup_model: nullableStringSchema.optional()
	})
	.transform((value) => value as ProviderPathPolicyModelSelection);

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

export const providerPathPolicyResponseSchema = z
	.looseObject({
		default_provider: nullableProviderSchema,
		providers: z
			.looseObject({
				sentient_managed: providerPathPolicyProviderStatusSchema,
				openrouter: providerPathPolicyProviderStatusSchema
			}),
		actions: z.record(z.string(), providerPathPolicyActionSchema)
	})
	.transform((value) => value as ProviderPathPolicyResponse);

export function parseProviderPathPolicy(value: unknown): ProviderPathPolicyResponse | undefined {
	if (value === null || typeof value === 'undefined') {
		return undefined;
	}

	const result = providerPathPolicyResponseSchema.safeParse(value);
	return result.success ? result.data : undefined;
}
