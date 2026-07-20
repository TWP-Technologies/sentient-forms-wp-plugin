import { describe, expect, it } from 'vitest';

import {
	DEFAULT_INPUT_MAPPING,
	InputMappingSchema,
	parseInputMapping,
	type InputMapping,
	validateInputMappingForSave
} from '$lib/utils/input-mapping';

describe('validateInputMappingForSave', () => {
	it('rejects selected mode when no fields or metadata would be sent', () => {
		const mapping: InputMapping = {
			mode: 'selected',
			field_ids: [],
			include_metadata: false
		};

		expect(validateInputMappingForSave(mapping, ['1', '2'])).toBe(
			'Select at least one field or include form metadata.'
		);
	});

	it('allows selected mode with at least one field', () => {
		expect(
			validateInputMappingForSave(
				{
				mode: 'selected',
				field_ids: ['2'],
				include_metadata: false
				},
				['1', '2']
			)
		).toBeNull();
	});

	it('rejects excluding every available field when metadata is disabled', () => {
		expect(
			validateInputMappingForSave(
				{
					mode: 'exclude',
					field_ids: ['1', '2'],
					include_metadata: false
				},
				['1', '2']
			)
		).toBe('Select at least one field or include form metadata.');
	});

	it('allows exclude mode when at least one available field remains', () => {
		expect(
			validateInputMappingForSave(
				{
					mode: 'exclude',
					field_ids: ['1'],
					include_metadata: false
				},
				['1', '2']
			)
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
			field_ids: [],
			include_metadata: true
		});
		expect(validateInputMappingForSave(undefined)).toBeNull();
	});

	it('parses valid persisted mapping data through the named schema', () => {
		expect(
			InputMappingSchema.parse({
				mode: 'exclude',
				field_ids: ['2', '5'],
				include_metadata: false
			})
		).toEqual({
			mode: 'exclude',
			field_ids: ['2', '5'],
			include_metadata: false
		});
	});

	it('fails closed for malformed or unexpectedly expanded persisted mappings', () => {
		expect(parseInputMapping({ mode: 'selected', field_ids: [false] })).toBeNull();
		expect(parseInputMapping({ mode: 'all', include_metadata: true, raw_prompt: 'secret' })).toBeNull();
		expect(validateInputMappingForSave({ mode: 'everything' })).toBe(
			'The saved field selection is invalid. Review and save it again.'
		);
	});

	it('uses the documented legacy default only when the persisted value is absent', () => {
		expect(parseInputMapping(undefined)).toEqual(DEFAULT_INPUT_MAPPING);
		expect(parseInputMapping(null)).toBeNull();
	});

	it('normalizes omitted persisted mapping defaults before rendering summaries', () => {
		expect(parseInputMapping({ mode: 'all' })).toEqual({
			mode: 'all',
			field_ids: [],
			include_metadata: true
		});
	});
});
