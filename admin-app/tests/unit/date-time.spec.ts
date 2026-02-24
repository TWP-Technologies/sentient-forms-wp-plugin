import { describe, expect, it } from 'vitest';
import {
	formatTimeOnly,
	formatTimestamp,
	safeParseTimestamp,
	sortCustomActionsByRecency
} from '$lib/utils/date-time';
import type { CustomAction } from '$lib/api/types';

function makeAction(overrides: Partial<CustomAction>): CustomAction {
	return {
		id: 'action-default',
		template_id: 'tmpl-default',
		code: 'default',
		display_name: 'Default',
		description: null,
		prompt_overrides: {},
		model_hint: null,
		base_credit_cost: null,
		status: 'active',
		archived_at: null,
		created_at: '2026-02-23T10:00:00Z',
		updated_at: '2026-02-23T10:00:00Z',
		action_kind: 'template_override',
		definition: null,
		definition_version: 1,
		output_contract: null,
		supported_execution_modes: ['after_submission'],
		...overrides
	};
}

describe('date-time utilities', () => {
	it('parses valid timestamps and rejects invalid timestamp values', () => {
		expect(safeParseTimestamp('2026-02-23T10:00:00Z')).not.toBeNull();
		expect(safeParseTimestamp('2026-02-23 10:00:00')).not.toBeNull();
		expect(safeParseTimestamp('Array')).toBeNull();
		expect(safeParseTimestamp('not-a-date')).toBeNull();
		expect(safeParseTimestamp(null)).toBeNull();
	});

	it('formats invalid timestamps to placeholders without throwing', () => {
		expect(formatTimestamp('not-a-date')).toBe('—');
		expect(formatTimeOnly('not-a-date', 'never')).toBe('never');
	});

	it('sorts by recency while keeping invalid timestamps deterministic', () => {
		const actions: CustomAction[] = [
			makeAction({
				id: 'uuid-old',
				code: 'legacy_1770000000000',
				created_at: '2026-02-23T07:00:00Z',
				updated_at: 'Array'
			}),
			makeAction({
				id: 'uuid-new',
				code: 'fresh',
				created_at: '2026-02-23T08:00:00Z',
				updated_at: '2026-02-23T08:00:00Z'
			}),
			makeAction({
				id: '42',
				code: 'legacy_1770000000001',
				created_at: 'Array',
				updated_at: 'Array'
			})
		];

		const sorted = sortCustomActionsByRecency(actions);
		expect(sorted.map((action) => action.id)).toEqual(['uuid-new', 'uuid-old', '42']);
	});
});
