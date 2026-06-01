import type {
	ModelSelection,
	SiteContext,
	SiteContextConsentStatus,
	SiteContextGenerationAccess,
	SiteContextStatusResponse
} from '$lib/api/types';

export const DEFAULT_SITE_CONTEXT_REFRESH_DAYS = 30;
export const SITE_CONTEXT_REFRESH_DAY_OPTIONS = [7, 14, 30, 60, 90] as const;

export const SITE_CONTEXT_DEFAULT_TOOLS: Record<string, unknown> = {
	tool_choice: 'auto',
	web_search: {
		mode: 'required',
		max_results: 5
	},
	web_fetch: {
		mode: 'auto'
	}
};

export const DEFAULT_SITE_CONTEXT_MODEL_SELECTION: ModelSelection = {
	primary: 'sf_research',
	is_preset: true,
	provider: 'openrouter',
	tools: SITE_CONTEXT_DEFAULT_TOOLS
};

export function defaultSiteContextGenerationAccess(
	overrides: Partial<SiteContextGenerationAccess> = {}
): SiteContextGenerationAccess {
	return {
		can_generate: false,
		reason_code: 'site_context_generation_consent_required',
		message: 'Allow AI-generated Site Context before running generation.',
		setup_target: 'site_context_consent',
		provider: 'openrouter',
		model: 'openai/gpt-5.5',
		...overrides
	};
}

export function emptySiteContextStatus(): SiteContextStatusResponse {
	return {
		context: null,
		settings: {
			consent_status: 'unset',
			consented_at: null,
			declined_at: null,
			auto_refresh_enabled: false,
			auto_refresh_days: DEFAULT_SITE_CONTEXT_REFRESH_DAYS,
			next_refresh_at: null,
			last_generated_at: null,
			last_error: null,
			generation_model_selection: DEFAULT_SITE_CONTEXT_MODEL_SELECTION,
			first_generation_started_at: null,
			first_generation_next_attempt_at: null,
			first_generation_last_attempt_at: null,
			first_generation_attempt_count: 0,
			first_generation_last_error: null,
			first_generation_exhausted_at: null
		},
		has_context: false,
		is_empty: true,
		is_stale: false,
		stale_after_days: 90,
		status: 'empty',
		generation_access: defaultSiteContextGenerationAccess()
	};
}

type LegacySiteContextResponse =
	| SiteContext
	| null
	| {
			context?: SiteContext | null;
			data?: SiteContext | null;
			settings?: Partial<SiteContextStatusResponse['settings']>;
			has_context?: boolean;
			is_empty?: boolean;
			is_stale?: boolean;
			stale_after_days?: number;
			status?: SiteContextStatusResponse['status'];
			generation_access?: Partial<SiteContextGenerationAccess> | null;
	  };

function fallbackGenerationAccessForLegacyResponse(
	settings: SiteContextStatusResponse['settings'],
	empty: SiteContextStatusResponse
): SiteContextGenerationAccess {
	if (settings.consent_status !== 'granted') return empty.generation_access;

	return defaultSiteContextGenerationAccess({
		reason_code: 'site_context_generation_setup_required',
		message:
			'Set up Sentient Forms Managed Service billing or a paid OpenRouter key before generating Site Context.',
		setup_target: 'providers',
		provider: settings.generation_model_selection.provider ?? empty.generation_access.provider,
		model: settings.generation_model_selection.primary ?? empty.generation_access.model
	});
}

export function normalizeSiteContextResponse(
	response: LegacySiteContextResponse | SiteContextStatusResponse
): SiteContextStatusResponse {
	const empty = emptySiteContextStatus();
	if (!response) return empty;

	if ('summary_text' in response) {
		const context = response;
		const hasGenerationConsent = context.pii_ack === true;
		return {
			...empty,
			context,
			settings: {
				...empty.settings,
				consent_status: hasGenerationConsent ? 'granted' : 'unset',
				auto_refresh_enabled: false
			},
			has_context: context.summary_text.trim().length > 0,
			is_empty: context.summary_text.trim().length === 0,
			status: context.summary_text.trim().length > 0 ? 'ready' : 'empty',
			generation_access: hasGenerationConsent
				? defaultSiteContextGenerationAccess({
						reason_code: 'site_context_generation_setup_required',
						message:
							'Set up Sentient Forms Managed Service billing or a paid OpenRouter key before generating Site Context.',
						setup_target: 'providers'
					})
				: empty.generation_access
		};
	}

	const wrappedContext = 'context' in response ? response.context : response.data;
	const settings = {
		...empty.settings,
		...(response.settings ?? {})
	};
	settings.generation_model_selection = normalizeSiteContextModelSelection(
		settings.generation_model_selection
	);

	const hasContext =
		typeof response.has_context === 'boolean'
			? response.has_context
			: Boolean(wrappedContext?.summary_text?.trim());
	const isEmpty =
		typeof response.is_empty === 'boolean' ? response.is_empty : !hasContext;
	const isStale = Boolean(response.is_stale);
	const consentDeclined = settings.consent_status === 'declined';
	const generationAccessFallback = fallbackGenerationAccessForLegacyResponse(settings, empty);

	return {
		context: wrappedContext ?? null,
		settings,
		has_context: hasContext,
		is_empty: isEmpty,
		is_stale: isStale,
		stale_after_days:
			typeof response.stale_after_days === 'number' && response.stale_after_days > 0
				? response.stale_after_days
				: empty.stale_after_days,
		status:
			response.status ??
			(consentDeclined ? 'declined' : isEmpty ? 'empty' : isStale ? 'stale' : 'ready'),
		generation_access: normalizeGenerationAccess(
			response.generation_access ?? generationAccessFallback,
			empty
		)
	};
}

function normalizeGenerationAccess(
	value: Partial<SiteContextGenerationAccess> | null | undefined,
	empty: SiteContextStatusResponse
): SiteContextGenerationAccess {
	if (!value || typeof value !== 'object') return empty.generation_access;

	return {
		...empty.generation_access,
		...value,
		can_generate: value.can_generate === true,
		reason_code:
			typeof value.reason_code === 'string' && value.reason_code.trim().length > 0
				? value.reason_code
				: empty.generation_access.reason_code,
		message:
			typeof value.message === 'string' && value.message.trim().length > 0
				? value.message
				: empty.generation_access.message,
		setup_target:
			typeof value.setup_target === 'string' && value.setup_target.trim().length > 0
				? value.setup_target
				: value.setup_target === null
					? null
					: empty.generation_access.setup_target
	};
}

export function siteContextGenerateDisabledMessage(
	status: SiteContextStatusResponse | null,
	generationConsent: boolean,
	hasUnsavedChanges = false
): string | null {
	if (!generationConsent) {
		return 'Allow AI-generated Site Context before running generation.';
	}

	if (hasUnsavedChanges) {
		return 'Save Site Context setup before generating so Sentient Forms can verify the selected provider, paid model, and consent state.';
	}

	if (!status?.generation_access?.can_generate) {
		return (
			status?.generation_access?.message ??
			'Set up Sentient Forms Managed Service billing or a paid OpenRouter key before generating Site Context.'
		);
	}

	return null;
}

export function siteContextModelSelectionChanged(
	current: ModelSelection,
	saved: ModelSelection | null | undefined
): boolean {
	return (
		stableSerialize(normalizeSiteContextModelSelection(current)) !==
		stableSerialize(normalizeSiteContextModelSelection(saved))
	);
}

export function normalizeSiteContextModelSelection(
	selection: ModelSelection | null | undefined
): ModelSelection {
	return {
		...DEFAULT_SITE_CONTEXT_MODEL_SELECTION,
		...(selection ?? {}),
		tools: isPlainRecord(selection?.tools) ? selection.tools : SITE_CONTEXT_DEFAULT_TOOLS
	};
}

export function compactSiteContextModelSelection(selection: ModelSelection): ModelSelection {
	const normalized = normalizeSiteContextModelSelection(selection);
	if (stableSerialize(normalized.tools) !== stableSerialize(SITE_CONTEXT_DEFAULT_TOOLS)) {
		return normalized;
	}

	const { tools: _tools, ...compact } = normalized;
	return compact;
}

function stableSerialize(value: unknown): string {
	return JSON.stringify(sortObjectKeys(value));
}

function sortObjectKeys(value: unknown): unknown {
	if (Array.isArray(value)) {
		return value.map((item) => sortObjectKeys(item));
	}

	if (!value || typeof value !== 'object') {
		return value;
	}

	const sorted: Record<string, unknown> = {};
	for (const key of Object.keys(value as Record<string, unknown>).sort()) {
		sorted[key] = sortObjectKeys((value as Record<string, unknown>)[key]);
	}
	return sorted;
}

function isPlainRecord(value: unknown): value is Record<string, unknown> {
	if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
	const prototype = Object.getPrototypeOf(value);
	return prototype === Object.prototype || prototype === null;
}

export function siteContextStatusLabel(status: SiteContextStatusResponse): string {
	if (status.settings.consent_status === 'declined') return 'Generation off';
	if (status.is_empty) return 'Empty';
	if (status.is_stale) return 'Outdated';
	return 'Ready';
}

export function siteContextWarningMessage(
	status: SiteContextStatusResponse,
	includeMode: string | null | undefined
): string | null {
	const mode = includeMode ?? 'global';
	if (mode === 'never') return null;

	if (status.settings.consent_status === 'declined') {
		return 'AI-generated Site Context is off. Manual context can still help actions make more correct decisions, especially spam checks.';
	}

	const resolvesToInclude = mode === 'always' || (mode === 'global' && status.context?.auto_include);
	if (!resolvesToInclude) return null;

	if (status.is_empty) {
		return 'Site Context is enabled for this action, but no context is saved yet. Add context so the model can judge submissions against this site.';
	}

	if (status.is_stale) {
		return `Site Context is enabled but looks older than ${status.stale_after_days} days. Refresh it before relying on site-specific decisions.`;
	}

	return null;
}

export function isConsentStatus(value: unknown): value is SiteContextConsentStatus {
	return value === 'unset' || value === 'granted' || value === 'declined';
}
