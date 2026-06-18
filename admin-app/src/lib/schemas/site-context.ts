import { z } from 'zod';
import type { ModelSelection, SiteContextStatusResponse } from '$lib/api/types';
import { SITE_CONTEXT_WEB_SEARCH_MAX_RESULTS } from '$lib/utils/site-context';

const nullableStringSchema = z.string().nullable();
const optionalNullableStringSchema = z.string().nullable().optional();
const toolModeSchema = z.enum(['inherit', 'off', 'auto', 'required']);
const siteContextModelToolsSchema = z
	.object({
		tool_choice: z.enum(['off', 'auto', 'required']).optional(),
		web_search: z
			.object({
				mode: toolModeSchema,
				max_results: z.number().int().min(1).max(SITE_CONTEXT_WEB_SEARCH_MAX_RESULTS).optional()
			})
			.passthrough()
			.optional(),
		web_fetch: z.object({ mode: toolModeSchema }).passthrough().optional(),
		datetime: z.object({ mode: toolModeSchema }).passthrough().optional()
	})
	.passthrough()
	.nullable()
	.optional();

export const modelSelectionSchema = z
	.object({
		primary: z.string().min(1),
		backup: z.string().nullable().optional(),
		is_preset: z.boolean(),
		provider: z.string().nullable().optional(),
		credential_id: z.number().int().positive().nullable().optional(),
		reasoning: z.string().nullable().optional(),
		tools: siteContextModelToolsSchema
	})
	.passthrough()
	.transform((value) => value as ModelSelection);

const siteContextSettingsSchema = z
	.object({
		consent_status: z.enum(['unset', 'granted', 'declined']),
		consented_at: nullableStringSchema,
		declined_at: nullableStringSchema,
		auto_refresh_enabled: z.boolean(),
		auto_refresh_days: z.number().int().positive(),
		next_refresh_at: nullableStringSchema,
		last_generated_at: nullableStringSchema,
		last_error: nullableStringSchema,
		generation_model_selection: modelSelectionSchema.nullable().optional(),
		first_generation_started_at: optionalNullableStringSchema,
		first_generation_next_attempt_at: optionalNullableStringSchema,
		first_generation_last_attempt_at: optionalNullableStringSchema,
		first_generation_attempt_count: z.number().int().nonnegative().optional(),
		first_generation_last_error: optionalNullableStringSchema,
		first_generation_exhausted_at: optionalNullableStringSchema
	})
	.passthrough();

const siteContextSchema = z
	.object({
		id: z.string(),
		license_id: z.string(),
		summary_text: z.string(),
		source: z.string(),
		auto_include: z.boolean(),
		pii_ack: z.boolean(),
		free_refresh_available: z.boolean(),
		next_free_refresh_at: nullableStringSchema,
		created_at: z.string(),
		updated_at: z.string(),
		metadata: z.record(z.string(), z.unknown()).nullable().optional()
	})
	.passthrough();

const generationAccessSchema = z
	.object({
		can_generate: z.boolean(),
		reason_code: z.string(),
		message: z.string(),
		setup_target: z.string().nullable(),
		provider: z.string().nullable().optional(),
		model: z.string().nullable().optional(),
		credential_id: z.number().int().positive().nullable().optional()
	})
	.passthrough();

export const siteContextGenerationJobSchema = z
	.object({
		id: z.string().min(1),
		status: z.enum(['queued', 'running', 'succeeded', 'failed']),
		requested_at: nullableStringSchema,
		started_at: nullableStringSchema,
		finished_at: nullableStringSchema,
		error: nullableStringSchema,
		code: z.string().nullable().optional(),
		status_code: z.number().int().positive().nullable().optional(),
		diagnostics: z.record(z.string(), z.unknown()).optional(),
		model: z.string().nullable().optional(),
		provider: z.string().nullable().optional(),
		tools: z.array(z.string()).optional(),
		attempts: z.number().int().nonnegative().optional(),
		max_attempts: z.number().int().positive().optional()
	})
	.passthrough();

export const siteContextStatusResponseSchema = z
	.object({
		context: siteContextSchema.nullable(),
		settings: siteContextSettingsSchema,
		has_context: z.boolean(),
		is_empty: z.boolean(),
		is_stale: z.boolean(),
		stale_after_days: z.number().int().positive(),
		status: z.enum(['empty', 'ready', 'stale', 'declined']),
		generation_access: generationAccessSchema,
		generation_job: siteContextGenerationJobSchema.nullable().optional()
	})
	.passthrough()
	.transform((value) => value as SiteContextStatusResponse);

export function parseSiteContextStatusResponse(value: unknown): SiteContextStatusResponse {
	return siteContextStatusResponseSchema.parse(value);
}
