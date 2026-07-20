import { describe, expect, it } from 'vitest';

import {
	localExecutionEventSchema,
	localFormMappingRecordSchema,
	localOpenRouterRequestSchema,
	parseLocalExecutionEvent,
	parseLocalExecutionEvents,
	parseLocalFormMappings,
	parseLocalOpenRouterRequests,
	parseLocalOpenRouterSmokeUrls,
	parseLocalSpamSummaryChainSeed,
	type LocalFormMappingRecord
} from '../e2e/utils/local-runtime-schemas';

const validLocalFormMapping: LocalFormMappingRecord = {
	id: 17,
	form_source: 'gravity-forms',
	form_id: '8',
	hook: 'gform_after_submission',
	action_kind: 'local_openrouter',
	action_id: null,
	input_bindings_json: { message: '3' },
	execution_mode: 'sync',
	enabled: true
};

describe('parseLocalFormMappings', () => {
	it('parses the strict persisted mapping envelope', () => {
		expect(
			parseLocalFormMappings(
				JSON.stringify({
					mappings: [validLocalFormMapping]
				})
			)
		).toEqual([validLocalFormMapping]);
	});

	it('rejects unexpected persisted mapping fields', () => {
		expect(
			localFormMappingRecordSchema.safeParse({
				...validLocalFormMapping,
				legacy_action_code: 'spam_detection'
			}).success
		).toBe(false);
	});

	it('does not expose malformed or invalid raw payloads in errors', () => {
		const malformedSecret = 'sk-or-malformed-secret';
		const invalidSecret = 'sk-or-invalid-secret';

		expect(() => parseLocalFormMappings(`{"mappings":["${malformedSecret}"]`)).toThrow(
			/local form mappings/i
		);
		expect(() =>
			parseLocalFormMappings(JSON.stringify({ mappings: [{ secret: invalidSecret }] }))
		).toThrow(/local form mappings/i);

		for (const rawSecret of [malformedSecret, invalidSecret]) {
			try {
				parseLocalFormMappings(
					rawSecret === malformedSecret
						? `{"mappings":["${rawSecret}"]`
						: JSON.stringify({ mappings: [{ secret: rawSecret }] })
				);
			} catch (error) {
				expect(String(error)).not.toContain(rawSecret);
			}
		}
	});
});

describe('local execution event parsers', () => {
	it('parses nullable single events and normalizes persisted integer fields', () => {
		expect(parseLocalExecutionEvent('null')).toBeNull();
		expect(
			parseLocalExecutionEvent(
				JSON.stringify({
					id: '42',
					mapping_id: '17',
					entry_id: '91',
					status: 'succeeded',
					result_json: {
						structured: { message: 'Submission accepted.' },
						native_effect_outcomes: [{ effect: 'entry_note', status: 'applied' }]
					},
					created_at: '2026-07-18 22:00:00'
				})
			)
		).toMatchObject({
			id: 42,
			mapping_id: 17,
			entry_id: '91',
			status: 'succeeded'
		});
	});

	it('parses event arrays without trusting malformed records', () => {
		expect(
			parseLocalExecutionEvents(
				JSON.stringify([
					{ id: '43', mapping_id: 18, status: 'skipped', result_json: { skip_reason: 'spam' } }
				])
			)
		).toEqual([
			expect.objectContaining({
				id: 43,
				mapping_id: 18,
				status: 'skipped'
			})
		]);

		expect(localExecutionEventSchema.safeParse({ id: 'not-an-integer' }).success).toBe(false);
		expect(() => parseLocalExecutionEvents(JSON.stringify([{ id: 'sk-event-secret' }]))).toThrow(
			/local execution events/i
		);
	});
});

describe('local OpenRouter smoke parsers', () => {
	it('parses the captured request fields used by the browser assertions', () => {
		const requests = parseLocalOpenRouterRequests(
			JSON.stringify([
				{
					body: {
						model: 'openrouter/auto',
						messages: [{ role: 'user', content: 'Summarize this submission.' }],
						response_format: { type: 'json_schema' }
					}
				}
			])
		);

		expect(requests[0]?.body?.model).toBe('openrouter/auto');
		expect(requests[0]?.body?.messages?.[0]?.content).toBe('Summarize this submission.');
		expect(
			localOpenRouterRequestSchema.safeParse({ body: { messages: 'not-an-array' } }).success
		).toBe(false);
	});

	it('accepts only URL-shaped values in the strict smoke URL envelope', () => {
		expect(
			parseLocalOpenRouterSmokeUrls(
				JSON.stringify({ urls: ['https://openrouter.ai/api/v1/chat/completions'] })
			)
		).toEqual(['https://openrouter.ai/api/v1/chat/completions']);

		expect(() =>
			parseLocalOpenRouterSmokeUrls(
				JSON.stringify({ urls: ['not-a-url'], diagnostic: 'sk-url-secret' })
			)
		).toThrow(/local OpenRouter smoke URLs/i);
	});
});

describe('local spam-summary seed parser', () => {
	it('strictly parses success and error envelopes', () => {
		expect(
			parseLocalSpamSummaryChainSeed(
				JSON.stringify({ spam_mapping_id: 41, summary_mapping_id: 42 })
			)
		).toEqual({ spam_mapping_id: 41, summary_mapping_id: 42 });
		expect(parseLocalSpamSummaryChainSeed(JSON.stringify({ error: 'seed_failed' }))).toEqual({
			error: 'seed_failed'
		});
	});

	it('rejects expanded or malformed seed payloads without exposing raw values', () => {
		const secret = 'sk-seed-secret';
		expect(() =>
			parseLocalSpamSummaryChainSeed(
				JSON.stringify({ spam_mapping_id: 41, summary_mapping_id: 42, secret })
			)
		).toThrow(/local spam-summary chain seed/i);
		try {
			parseLocalSpamSummaryChainSeed(JSON.stringify({ error: secret, extra: true }));
		} catch (error) {
			expect(String(error)).not.toContain(secret);
		}
	});
});
