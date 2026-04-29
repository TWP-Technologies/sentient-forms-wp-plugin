export const MAPPING_MODAL_SECTION_IDS = [
	'core',
	'guidance',
	'spam_advanced',
	'input_mapping',
	'attachment_mapping',
	'realtime',
	'conditions',
	'model_execution'
] as const;

export type MappingModalSectionId = (typeof MAPPING_MODAL_SECTION_IDS)[number];

export type MappingModalSectionExpansion = Record<MappingModalSectionId, boolean>;

export function createInitialMappingModalSectionExpansion(
	isSpamAction: boolean
): MappingModalSectionExpansion {
	return {
		core: true,
		guidance: isSpamAction,
		spam_advanced: false,
		input_mapping: false,
		attachment_mapping: false,
		realtime: false,
		conditions: false,
		model_execution: false
	};
}

export function normalizeMappingModalSectionExpansion(
	value: unknown,
	isSpamAction: boolean
): MappingModalSectionExpansion {
	const defaults = createInitialMappingModalSectionExpansion(isSpamAction);
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		return defaults;
	}

	const candidate = value as Record<string, unknown>;
	const normalized: MappingModalSectionExpansion = { ...defaults };
	for (const sectionId of MAPPING_MODAL_SECTION_IDS) {
		const nextValue = candidate[sectionId];
		if (typeof nextValue === 'boolean') {
			normalized[sectionId] = nextValue;
		}
	}

	if (!isSpamAction) {
		normalized.guidance = false;
		normalized.spam_advanced = false;
	}

	return normalized;
}

export function toggleMappingModalSectionExpansion(
	expansion: MappingModalSectionExpansion,
	sectionId: MappingModalSectionId
): MappingModalSectionExpansion {
	return {
		...expansion,
		[sectionId]: !expansion[sectionId]
	};
}
