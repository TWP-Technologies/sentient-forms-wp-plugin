import { describe, expect, it } from 'vitest';
import {
	buildDefaultCustomActionMergeTags,
	coerceDisplayString,
	generateCustomActionCode,
	parsePromptOverridesInput,
	sanitizeCustomActionCode,
	sanitizeMergeTagOptions
} from '$lib/utils/custom-actions';

describe('custom action helpers', () => {
	it('sanitizes codes to lowercase alphanumerics and dashes', () => {
		expect(sanitizeCustomActionCode('Follow-Up Reply!')).toBe('follow-upreply');
		expect(sanitizeCustomActionCode('SPAM_check')).toBe('spamcheck');
	});

	it('generates webmaster-safe action codes from display names', () => {
		expect(generateCustomActionCode('Follow-Up Reply!')).toBe('follow-up-reply');
		expect(generateCustomActionCode('  Demo: Lead Intake  ')).toBe('demo-lead-intake');
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

	it('coerces labels from option-like objects without rendering raw object strings', () => {
		expect(coerceDisplayString({ label: 'Customer email' })).toBe('Customer email');
		expect(coerceDisplayString({ id: 7 })).toBe('7');
		expect(coerceDisplayString({ nested: true }, 'Fallback')).toBe('Fallback');
	});

	it('sanitizes merge tag options so UI placeholders never receive object text', () => {
		const tags = sanitizeMergeTagOptions([
			{
				group: { label: 'Fields' } as unknown as string,
				token: { value: 'field:type:email' } as unknown as string,
				label: { label: 'Email field' } as unknown as string,
				description: { title: 'Find the first email field.' } as unknown as string
			}
		]);

		expect(tags).toEqual([
			{
				group: 'Fields',
				token: 'field:type:email',
				label: 'Email field',
				description: 'Find the first email field.'
			}
		]);
		expect(JSON.stringify(tags)).not.toContain('[object Object]');
	});

	it('offers field-aware merge tags for common Gravity Forms field shapes', () => {
		const tokens = buildDefaultCustomActionMergeTags().map((tag) => tag.token);
		expect(tokens).toContain('field:type:name.first');
		expect(tokens).toContain('field:type:name.last');
		expect(tokens).toContain('field:label_contains:reason for calling');
	});
});
