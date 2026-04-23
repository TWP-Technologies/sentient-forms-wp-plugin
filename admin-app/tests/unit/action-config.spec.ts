import { describe, expect, it } from 'vitest';
import {
	isSpamActionCode,
	normalizeFormActionConfig,
	normalizeSpamIndicatorsDisplay,
	normalizeSpamResultDisplayMode
} from '$lib/utils/action-config';

describe('action config helpers', () => {
	it('treats both official and legacy spam identifiers as spam actions', () => {
		expect(isSpamActionCode('spam_detection_v1')).toBe(true);
		expect(isSpamActionCode('spam_analysis')).toBe(true);
		expect(isSpamActionCode('entry_summary_v1')).toBe(false);
	});

	it('normalizes spam note display aliases for frontend controls', () => {
		expect(normalizeSpamResultDisplayMode('all_results')).toBe('entry_note');
		expect(normalizeSpamResultDisplayMode('none')).toBe('silent');
		expect(normalizeSpamResultDisplayMode('spam_only')).toBe('spam_only');
		expect(normalizeSpamResultDisplayMode('unexpected')).toBe('entry_note');
	});

	it('normalizes spam indicator display modes', () => {
		expect(normalizeSpamIndicatorsDisplay('detailed')).toBe('detailed');
		expect(normalizeSpamIndicatorsDisplay('unexpected')).toBe('simple');
	});

	it('preserves restored spam note defaults in normalized form configs', () => {
		expect(
			normalizeFormActionConfig({
				spam_result_display_mode: 'all_results',
				spam_indicators_display: 'detailed'
			})
		).toMatchObject({
			spam_result_display_mode: 'entry_note',
			spam_indicators_display: 'detailed'
		});
	});
});
