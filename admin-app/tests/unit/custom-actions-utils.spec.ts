import { describe, expect, it } from 'vitest';
import { parsePromptOverridesInput, sanitizeCustomActionCode } from '$lib/utils/custom-actions';

describe('custom action helpers', () => {
	it('sanitizes codes to lowercase alphanumerics and dashes', () => {
		expect(sanitizeCustomActionCode('Follow-Up Reply!')).toBe('follow-upreply');
		expect(sanitizeCustomActionCode('SPAM_check')).toBe('spamcheck');
	});

	it('parses prompt overrides JSON objects', () => {
		const { result, error } = parsePromptOverridesInput('{"tone":"friendly"}');
		expect(error).toBeUndefined();
		expect(result).toEqual({ tone: 'friendly' });
	});

	it('flags invalid JSON input', () => {
		const { error } = parsePromptOverridesInput('{"tone":"friendly"');
		expect(error).toContain('JSON');
	});

	it('requires object payloads', () => {
		const { error } = parsePromptOverridesInput('["array"]');
		expect(error).toContain('object');
	});
});
