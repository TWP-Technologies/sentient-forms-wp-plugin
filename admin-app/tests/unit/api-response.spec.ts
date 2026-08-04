import { describe, expect, it } from 'vitest';
import { unwrapRestResponse } from '$lib/api/response';

describe('unwrapRestResponse', () => {
	it('keeps direct WordPress REST payloads intact', () => {
		const payload = {
			models: [{ id: 'gemini-2.5-flash' }],
			presets: [{ code: 'sf_default' }]
		};

		expect(unwrapRestResponse(payload)).toBe(payload);
	});

	it('unwraps CPS-style envelopes returned through older proxy paths', () => {
		const data = {
			models: [{ id: 'gemini-2.5-flash-lite' }],
			presets: [{ code: 'sf_economy' }]
		};

		expect(unwrapRestResponse({ success: true, data })).toBe(data);
	});

	it('does not misclassify failure envelopes as successful null responses', () => {
		const failure = { success: false, data: null, code: 'request_failed' };

		expect(unwrapRestResponse(failure)).toBe(failure);
	});

	it('returns nullish payloads unchanged', () => {
		expect(unwrapRestResponse(null)).toBeNull();
		expect(unwrapRestResponse(undefined)).toBeUndefined();
	});
});
