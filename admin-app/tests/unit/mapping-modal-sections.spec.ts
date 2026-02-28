import { describe, expect, it } from 'vitest';

import {
	createInitialMappingModalSectionExpansion,
	normalizeMappingModalSectionExpansion,
	toggleMappingModalSectionExpansion
} from '$lib/utils/mapping-modal-sections';

describe('mapping modal section expansion helpers', () => {
	it('creates expected defaults for spam mappings', () => {
		expect(createInitialMappingModalSectionExpansion(true)).toEqual({
			core: true,
			guidance: true,
			spam_advanced: false,
			input_mapping: false,
			attachment_mapping: false,
			conditions: false,
			model_execution: false
		});
	});

	it('creates expected defaults for non-spam mappings', () => {
		expect(createInitialMappingModalSectionExpansion(false)).toEqual({
			core: true,
			guidance: false,
			spam_advanced: false,
			input_mapping: false,
			attachment_mapping: false,
			conditions: false,
			model_execution: false
		});
	});

	it('normalizes unknown inputs to defaults', () => {
		expect(normalizeMappingModalSectionExpansion(null, true)).toEqual(
			createInitialMappingModalSectionExpansion(true)
		);
		expect(normalizeMappingModalSectionExpansion('invalid', false)).toEqual(
			createInitialMappingModalSectionExpansion(false)
		);
	});

	it('normalizes partial boolean input and forces non-spam guidance sections closed', () => {
		const normalized = normalizeMappingModalSectionExpansion(
			{
				core: false,
				guidance: true,
				spam_advanced: true,
				conditions: true,
				unknown_section: true
			},
			false
		);

		expect(normalized).toEqual({
			core: false,
			guidance: false,
			spam_advanced: false,
			input_mapping: false,
			attachment_mapping: false,
			conditions: true,
			model_execution: false
		});
	});

	it('toggles a single section without mutating the rest', () => {
		const initial = createInitialMappingModalSectionExpansion(true);
		const toggled = toggleMappingModalSectionExpansion(initial, 'conditions');

		expect(toggled.conditions).toBe(true);
		expect(toggled.core).toBe(true);
		expect(toggled.guidance).toBe(true);
	});
});
