import { z } from 'zod';

export const InputMappingSchema = z.strictObject({
	mode: z.enum(['all', 'selected', 'exclude']),
	field_ids: z.array(z.string().trim().min(1)).default([]),
	include_metadata: z.boolean().default(true)
});

export type InputMapping = z.infer<typeof InputMappingSchema>;

export const DEFAULT_INPUT_MAPPING: InputMapping = {
	mode: 'all',
	field_ids: [],
	include_metadata: true
};

export function parseInputMapping(value: unknown): InputMapping | null {
	if (typeof value === 'undefined') {
		return { ...DEFAULT_INPUT_MAPPING };
	}

	const result = InputMappingSchema.safeParse(value);
	return result.success ? result.data : null;
}

export function validateInputMappingForSave(
	mapping: unknown,
	availableFieldIds?: readonly string[]
): string | null {
	if (typeof mapping === 'undefined') return null;

	const parsed = parseInputMapping(mapping);
	if (!parsed) {
		return 'The saved field selection is invalid. Review and save it again.';
	}

	if (parsed.include_metadata) return null;

	const fieldIds = parsed.field_ids;
	if (typeof availableFieldIds === 'undefined') {
		return parsed.mode === 'selected' && fieldIds.length === 0
			? 'Select at least one field or include form metadata.'
			: null;
	}

	const available = new Set(availableFieldIds);
	const effectiveFieldCount =
		parsed.mode === 'selected'
			? fieldIds.filter((fieldId) => available.has(fieldId)).length
			: parsed.mode === 'exclude'
				? availableFieldIds.filter((fieldId) => !fieldIds.includes(fieldId)).length
				: availableFieldIds.length;
	if (effectiveFieldCount === 0) {
		return 'Select at least one field or include form metadata.';
	}

	return null;
}
