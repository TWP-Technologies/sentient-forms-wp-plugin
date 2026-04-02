import type { FormActionConfig, ModelSelection } from '$lib/api/types';

export const DEFAULT_MODEL_SELECTION: ModelSelection = {
	primary: 'sf_default',
	backup: null,
	is_preset: true
};

const VALID_SITE_CONTEXT_VALUES = new Set(['global', 'always', 'never']);
export const INHERITABLE_BOOLEAN_MODES = ['inherit', 'enabled', 'disabled'] as const;
export type InheritableBooleanMode = (typeof INHERITABLE_BOOLEAN_MODES)[number];

export function cloneDefaultModelSelection(): ModelSelection {
	return { ...DEFAULT_MODEL_SELECTION };
}

export function isSpamActionCode(actionId: string | null | undefined): boolean {
	return actionId === 'spam_detection_v1' || actionId === 'spam_analysis';
}

export function normalizeModelSelection(value: unknown): ModelSelection | undefined {
	if (!value) {
		return undefined;
	}

	if (typeof value === 'string') {
		const primary = value.trim();
		if (!primary) {
			return undefined;
		}

		return {
			primary,
			backup: null,
			is_preset: primary.startsWith('sf_')
		};
	}

	if (typeof value !== 'object' || Array.isArray(value)) {
		return undefined;
	}

	const candidate = value as Record<string, unknown>;
	const primary = candidate.primary?.toString().trim() ?? '';
	if (!primary) {
		return undefined;
	}

	const backupRaw = candidate.backup;
	const backup =
		typeof backupRaw === 'string' && backupRaw.trim().length > 0 ? backupRaw.trim() : null;

	return {
		primary,
		backup,
		is_preset: candidate.is_preset === true
	};
}

export function normalizeFormActionConfig(value: unknown): FormActionConfig {
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		return {
			include_site_context: 'global',
			spam_positive_examples: [],
			spam_negative_examples: []
		};
	}

	const candidate = value as Record<string, unknown>;
	const includeSiteContext =
		typeof candidate.include_site_context === 'string' &&
		VALID_SITE_CONTEXT_VALUES.has(candidate.include_site_context)
			? (candidate.include_site_context as FormActionConfig['include_site_context'])
			: 'global';

	const updatedAt =
		typeof candidate.updated_at === 'string' && candidate.updated_at.trim().length > 0
			? candidate.updated_at
			: undefined;

	return {
		include_site_context: includeSiteContext,
		suppress_notifications_on_spam: normalizeOptionalBoolean(
			candidate.suppress_notifications_on_spam
		),
		skip_downstream_on_spam: normalizeOptionalBoolean(candidate.skip_downstream_on_spam),
		spam_positive_examples: Array.isArray(candidate.spam_positive_examples)
			? candidate.spam_positive_examples
					.map((entry) => entry?.toString().trim())
					.filter((entry): entry is string => Boolean(entry))
			: [],
		spam_negative_examples: Array.isArray(candidate.spam_negative_examples)
			? candidate.spam_negative_examples
					.map((entry) => entry?.toString().trim())
					.filter((entry): entry is string => Boolean(entry))
			: [],
		model_selection: normalizeModelSelection(
			candidate.model_selection ?? candidate.model_override ?? null
		),
		updated_at: updatedAt
	};
}

export function normalizeOptionalBoolean(value: unknown): boolean | undefined {
	if (value === true || value === false) {
		return value;
	}

	if (typeof value === 'string') {
		const normalized = value.trim().toLowerCase();
		if (normalized === 'true' || normalized === '1') {
			return true;
		}
		if (normalized === 'false' || normalized === '0') {
			return false;
		}
	}

	return undefined;
}

export function getInheritableBooleanMode(value: unknown): InheritableBooleanMode {
	if (value === true) {
		return 'enabled';
	}

	if (value === false) {
		return 'disabled';
	}

	return 'inherit';
}

export function modeToOptionalBoolean(mode: InheritableBooleanMode): boolean | undefined {
	if (mode === 'enabled') {
		return true;
	}

	if (mode === 'disabled') {
		return false;
	}

	return undefined;
}

export function applyInheritableBooleanToConfig(
	config: FormActionConfig,
	field: 'suppress_notifications_on_spam' | 'skip_downstream_on_spam',
	mode: InheritableBooleanMode
): FormActionConfig {
	const nextConfig = { ...config };
	const nextValue = modeToOptionalBoolean(mode);

	if (typeof nextValue === 'undefined') {
		delete nextConfig[field];
	} else {
		nextConfig[field] = nextValue;
	}

	return nextConfig;
}

export function resolveInheritableBoolean(
	values: Array<boolean | undefined>,
	fallback: boolean
): boolean {
	for (const value of values) {
		if (typeof value === 'boolean') {
			return value;
		}
	}

	return fallback;
}

export function resolveInheritableBooleanSource(
	values: Array<{ level: string; value: boolean | undefined }>,
	fallbackLevel: string
): string {
	for (const candidate of values) {
		if (typeof candidate.value === 'boolean') {
			return candidate.level;
		}
	}

	return fallbackLevel;
}
