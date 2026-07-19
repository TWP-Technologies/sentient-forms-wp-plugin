import { z } from 'zod';

export const localFormMappingRecordSchema = z.strictObject({
	id: z.number().int().positive(),
	form_source: z.string().min(1),
	form_id: z.string().min(1),
	hook: z.string().min(1),
	action_kind: z.string().min(1),
	action_id: z.number().int().positive().nullable(),
	input_bindings_json: z.record(z.string(), z.unknown()).nullable(),
	execution_mode: z.string().min(1).nullable(),
	enabled: z.boolean()
});

export const localFormMappingsEnvelopeSchema = z.strictObject({
	mappings: z.array(localFormMappingRecordSchema)
});

export type LocalFormMappingRecord = z.infer<typeof localFormMappingRecordSchema>;
export type LocalFormMappingsEnvelope = z.infer<typeof localFormMappingsEnvelopeSchema>;

const persistedIntegerSchema = z
	.union([z.number().int(), z.string().regex(/^\d+$/)])
	.transform((value) => Number(value))
	.pipe(z.number().int().nonnegative());

const localNativeEffectOutcomeSchema = z.looseObject({
	effect: z.string().optional(),
	status: z.string().optional(),
	reason: z.string().optional()
});

const localExecutionResultSchema = z.looseObject({
	result_summary: z.string().optional(),
	skip_reason: z.string().optional(),
	structured: z.record(z.string(), z.unknown()).optional(),
	native_effect_outcomes: z.array(localNativeEffectOutcomeSchema).optional()
});

export const localExecutionEventSchema = z.looseObject({
	id: persistedIntegerSchema.optional(),
	status: z.string().optional(),
	mapping_id: persistedIntegerSchema.nullable().optional(),
	mapping_key: z.string().nullable().optional(),
	action_code: z.string().nullable().optional(),
	entry_id: z.string().nullable().optional(),
	provider: z.string().nullable().optional(),
	model: z.string().nullable().optional(),
	result_json: localExecutionResultSchema.nullable().optional()
});

export const nullableLocalExecutionEventSchema = localExecutionEventSchema.nullable();
export const localExecutionEventsSchema = z.array(localExecutionEventSchema);

export type LocalExecutionEvent = z.infer<typeof localExecutionEventSchema>;
export type LocalExecutionEvents = z.infer<typeof localExecutionEventsSchema>;

const localOpenRouterMessageSchema = z.looseObject({
	role: z.string().optional(),
	content: z.string().optional()
});

const localOpenRouterRequestBodySchema = z.looseObject({
	model: z.string().optional(),
	messages: z.array(localOpenRouterMessageSchema).optional()
});

export const localOpenRouterRequestSchema = z.looseObject({
	body: localOpenRouterRequestBodySchema.optional()
});

export const localOpenRouterRequestsSchema = z.array(localOpenRouterRequestSchema);

export const localOpenRouterSmokeUrlsEnvelopeSchema = z.strictObject({
	urls: z.array(z.url())
});

export type LocalOpenRouterRequest = z.infer<typeof localOpenRouterRequestSchema>;
export type LocalOpenRouterRequests = z.infer<typeof localOpenRouterRequestsSchema>;
export type LocalOpenRouterSmokeUrlsEnvelope = z.infer<
	typeof localOpenRouterSmokeUrlsEnvelopeSchema
>;

const localSpamSummaryChainSeedSuccessSchema = z.strictObject({
	spam_mapping_id: z.number().int().positive(),
	summary_mapping_id: z.number().int().positive()
});

const localSpamSummaryChainSeedErrorSchema = z.strictObject({
	error: z.string().min(1)
});

export const localSpamSummaryChainSeedSchema = z.union([
	localSpamSummaryChainSeedSuccessSchema,
	localSpamSummaryChainSeedErrorSchema
]);

export type LocalSpamSummaryChainSeed = z.infer<typeof localSpamSummaryChainSeedSuccessSchema>;
export type LocalSpamSummaryChainSeedEnvelope = z.infer<typeof localSpamSummaryChainSeedSchema>;

function parseJsonPayload<T>(payload: string, schema: z.ZodType<T>, label: string): T {
	let decoded: unknown;

	try {
		decoded = JSON.parse(payload);
	} catch {
		throw new Error(`Failed to parse ${label} payload.`);
	}

	const result = schema.safeParse(decoded);
	if (!result.success) {
		throw new Error(`Failed to parse ${label} payload.`);
	}

	return result.data;
}

export function parseLocalFormMappings(payload: string): LocalFormMappingRecord[] {
	return parseJsonPayload(payload, localFormMappingsEnvelopeSchema, 'local form mappings').mappings;
}

export function parseLocalExecutionEvent(payload: string): LocalExecutionEvent | null {
	return parseJsonPayload(payload, nullableLocalExecutionEventSchema, 'local execution event');
}

export function parseLocalExecutionEvents(payload: string): LocalExecutionEvents {
	return parseJsonPayload(payload, localExecutionEventsSchema, 'local execution events');
}

export function parseLocalOpenRouterRequests(payload: string): LocalOpenRouterRequests {
	return parseJsonPayload(payload, localOpenRouterRequestsSchema, 'local OpenRouter requests');
}

export function parseLocalOpenRouterSmokeUrls(payload: string): string[] {
	return parseJsonPayload(
		payload,
		localOpenRouterSmokeUrlsEnvelopeSchema,
		'local OpenRouter smoke URLs'
	).urls;
}

export function parseLocalSpamSummaryChainSeed(payload: string): LocalSpamSummaryChainSeedEnvelope {
	return parseJsonPayload(
		payload,
		localSpamSummaryChainSeedSchema,
		'local spam-summary chain seed'
	);
}
