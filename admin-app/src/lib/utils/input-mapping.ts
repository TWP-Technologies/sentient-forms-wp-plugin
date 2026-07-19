import type { InputMapping } from '$lib/api/types';

export const DEFAULT_INPUT_MAPPING: InputMapping = {
	mode: 'all',
	include_metadata: true
};

export function validateInputMappingForSave(
	mapping: InputMapping | null | undefined
): string | null {
	if (!mapping) return null;

	const fieldIds = Array.isArray(mapping.field_ids) ? mapping.field_ids : [];
	if (mapping.mode === 'selected' && fieldIds.length === 0 && mapping.include_metadata !== true) {
		return 'Select at least one field or include form metadata.';
	}

	return null;
}
