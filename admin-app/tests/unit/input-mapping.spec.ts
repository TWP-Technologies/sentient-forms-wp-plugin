import { describe, expect, it } from 'vitest';

import type { InputMapping } from '$lib/api/types';
import { DEFAULT_INPUT_MAPPING, validateInputMappingForSave } from '$lib/utils/input-mapping';

describe('validateInputMappingForSave', () => {
	it('rejects selected mode when no fields or metadata would be sent', () => {
		const mapping: InputMapping = {
			mode: 'selected',
			field_ids: [],
			include_metadata: false
		};

		expect(validateInputMappingForSave(mapping)).toBe(
			'Select at least one field or include form metadata.'
		);
	});

	it('allows selected mode with at least one field', () => {
		expect(
			validateInputMappingForSave({
				mode: 'selected',
				field_ids: ['2'],
				include_metadata: false
			})
		).toBeNull();
	});

	it('allows a metadata-only projection', () => {
		expect(
			validateInputMappingForSave({
				mode: 'selected',
				field_ids: [],
				include_metadata: true
			})
		).toBeNull();
	});

	it('describes an absent legacy mapping as the full-input behavior the runtime preserves', () => {
		expect(DEFAULT_INPUT_MAPPING).toEqual({
			mode: 'all',
			include_metadata: true
		});
		expect(validateInputMappingForSave(undefined)).toBeNull();
	});
});
