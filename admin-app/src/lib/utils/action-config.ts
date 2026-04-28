import type {
	FormActionConfig,
	ModelSelection,
	SpamIndicatorsDisplayMode,
	SpamResultDisplayMode
} from '$lib/api/types';

export const DEFAULT_MODEL_SELECTION: ModelSelection = {
	primary: 'sf_default',
	backup: null,
	is_preset: true
};

const VALID_SITE_CONTEXT_VALUES = new Set(['global', 'always', 'never']);
const VALID_SPAM_RESULT_DISPLAY_MODES = new Set([
	'none',
	'spam_only',
	'all_results',
	'entry_note',
	'silent'
]);
const VALID_SPAM_INDICATORS_DISPLAY = new Set(['simple', 'detailed']);
const SPAM_ACTION_CODES = new Set(['spam_detection_v1', 'spam_analysis']);
export const INHERITABLE_BOOLEAN_MODES = ['inherit', 'enabled', 'disabled'] as const;
export type InheritableBooleanMode = (typeof INHERITABLE_BOOLEAN_MODES)[number];
export type DraftExecutionKind = 'blocking' | 'background' | 'mixed';

export function cloneDefaultModelSelection(): ModelSelection {
	return { ...DEFAULT_MODEL_SELECTION };
}

export function isSpamActionCode(actionId: string | null | undefined): boolean {
	if (typeof actionId !== 'string') {
		return false;
	}

	return SPAM_ACTION_CODES.has(actionId.trim());
}

export function deriveDraftExecutionKind(
	hooks: Iterable<unknown>,
	executionMode: unknown
): DraftExecutionKind {
	const normalizedHooks = new Set(
		Array.from(hooks)
			.map((hook) => hook?.toString().trim())
			.filter((hook): hook is string => Boolean(hook))
	);
	const hasBlockingHook = normalizedHooks.has('gform_validation');
	const hasBackgroundHook = normalizedHooks.has('gform_after_submission');
	if (hasBlockingHook && hasBackgroundHook) return 'mixed';
	if (hasBlockingHook) return 'blocking';
	if (hasBackgroundHook) return 'background';

	const normalizedMode = executionMode?.toString().trim();
	return normalizedMode === 'after_submission' || normalizedMode === 'async'
		? 'background'
		: 'blocking';
}

export function normalizeSpamResultDisplayMode(
	value: unknown,
	fallback: SpamResultDisplayMode = 'entry_note'
): SpamResultDisplayMode {
	if (typeof value !== 'string') {
		return fallback;
	}

	const normalized = value.trim().toLowerCase();
	if (!VALID_SPAM_RESULT_DISPLAY_MODES.has(normalized)) {
		return fallback;
	}

	if (normalized === 'all_results') {
		return 'entry_note';
	}

	if (normalized === 'none') {
		return 'silent';
	}

	return normalized as SpamResultDisplayMode;
}

export function normalizeSpamIndicatorsDisplay(
	value: unknown,
	fallback: SpamIndicatorsDisplayMode = 'simple'
): SpamIndicatorsDisplayMode {
	if (typeof value !== 'string') {
		return fallback;
	}

	const normalized = value.trim().toLowerCase();
	return VALID_SPAM_INDICATORS_DISPLAY.has(normalized)
		? (normalized as SpamIndicatorsDisplayMode)
		: fallback;
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
	const provider =
		typeof candidate.provider === 'string' && candidate.provider.trim().length > 0
			? candidate.provider.trim()
			: null;
	const credentialId =
		typeof candidate.credential_id === 'number' && Number.isFinite(candidate.credential_id)
			? candidate.credential_id
			: typeof candidate.credential_id === 'string' && candidate.credential_id.trim().length > 0
				? Number.parseInt(candidate.credential_id, 10)
				: null;

	return {
		primary,
		backup,
		is_preset: candidate.is_preset === true,
		...(provider ? { provider } : {}),
		...(credentialId && credentialId > 0 ? { credential_id: credentialId } : {}),
		...(typeof candidate.reasoning === 'string' &&
		['none', 'minimal', 'low', 'medium', 'high', 'xhigh'].includes(candidate.reasoning)
			? { reasoning: candidate.reasoning }
			: {})
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
		spam_result_display_mode: normalizeSpamResultDisplayMode(candidate.spam_result_display_mode),
		spam_indicators_display: normalizeSpamIndicatorsDisplay(candidate.spam_indicators_display),
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
