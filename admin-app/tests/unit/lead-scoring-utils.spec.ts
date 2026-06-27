import { describe, expect, it } from 'vitest';
import { leadScoringViewFromLocation, setupJumpPath } from '$lib/components/lead-scoring/utils';

describe('lead scoring utilities', () => {
	it('builds setup jump paths with an explicit setup view', () => {
		expect(setupJumpPath({ form_source: 'gravity_forms', form_id: 7 })).toBe(
			'/actions/gravity_forms/7/lead-value?view=setup'
		);
	});

	it('routes Elementor setup jumps to the form actions page', () => {
		expect(setupJumpPath({ form_source: 'elementor_forms', form_id: '91:formabc' })).toBe(
			'/actions/elementor_forms/91:formabc'
		);
	});

	it('reads setup view from hash-router query strings', () => {
		expect(leadScoringViewFromLocation('', '#/actions/gravity_forms/7/lead-value?view=setup')).toBe(
			'setup'
		);
	});

	it('falls back to normal search params for pathname routing', () => {
		expect(
			leadScoringViewFromLocation('?view=historical', '#/actions/gravity_forms/7/lead-value')
		).toBe('historical');
	});

	it('ignores unknown lead scoring views', () => {
		expect(leadScoringViewFromLocation('?view=profile', '')).toBeNull();
	});
});
