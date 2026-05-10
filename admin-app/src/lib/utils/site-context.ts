import type {
	ModelSelection,
	SiteContext,
	SiteContextConsentStatus,
	SiteContextStatusResponse
} from '$lib/api/types';

export const DEFAULT_SITE_CONTEXT_REFRESH_DAYS = 30;
export const SITE_CONTEXT_REFRESH_DAY_OPTIONS = [7, 14, 30, 60, 90] as const;

export const DEFAULT_SITE_CONTEXT_MODEL_SELECTION: ModelSelection = {
	primary: 'sf_research',
	is_preset: true,
	provider: 'sentient_managed',
	tools: {
		tool_choice: 'auto',
		web_search: {
			mode: 'required',
			max_results: 5
		},
		web_fetch: {
			mode: 'auto'
		}
	}
};

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
			generation_model_selection: DEFAULT_SITE_CONTEXT_MODEL_SELECTION
		},
		has_context: false,
		is_empty: true,
		is_stale: false,
		stale_after_days: 90,
		status: 'empty'
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
	  };

export function normalizeSiteContextResponse(
	response: LegacySiteContextResponse | SiteContextStatusResponse
): SiteContextStatusResponse {
	const empty = emptySiteContextStatus();
	if (!response) return empty;

	if ('summary_text' in response) {
		const context = response;
		return {
			...empty,
			context,
			settings: {
				...empty.settings,
				consent_status: context.pii_ack ? 'granted' : 'unset',
				auto_refresh_enabled: false
			},
			has_context: context.summary_text.trim().length > 0,
			is_empty: context.summary_text.trim().length === 0,
			status: context.summary_text.trim().length > 0 ? 'ready' : 'empty'
		};
	}

	const wrappedContext = 'context' in response ? response.context : response.data;
	const settings = {
		...empty.settings,
		...(response.settings ?? {})
	};
	settings.generation_model_selection ??= DEFAULT_SITE_CONTEXT_MODEL_SELECTION;

	const hasContext =
		typeof response.has_context === 'boolean'
			? response.has_context
			: Boolean(wrappedContext?.summary_text?.trim());
	const isEmpty =
		typeof response.is_empty === 'boolean' ? response.is_empty : !hasContext;
	const isStale = Boolean(response.is_stale);
	const consentDeclined = settings.consent_status === 'declined';

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
		status: response.status ?? (consentDeclined ? 'declined' : isEmpty ? 'empty' : isStale ? 'stale' : 'ready')
	};
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
