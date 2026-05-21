import type {
	RealtimeHiddenFieldExposureMode,
	RealtimeInitialPanelState,
	RealtimePageCheckpointMode,
	RealtimeRefreshMode,
	RealtimeSettings
} from '$lib/api/types';

export const REALTIME_ACTION_ID = 'clarification_assistant_v1';

export const REALTIME_BLOCKING_OPTIONS = [
	{ value: 'advisory', label: 'Advisory: never block submit' },
	{ value: 'require_answers', label: 'Require answers to required AI questions' }
];

export const REALTIME_INITIAL_PANEL_OPTIONS = [
	{ value: 'minimized', label: 'Minimized on form start' },
	{ value: 'hidden_until_interaction', label: 'Hidden until visitor starts' },
	{ value: 'open', label: 'Open on form start' }
];

export const REALTIME_HIDDEN_FIELD_EXPOSURE_OPTIONS = [
	{ value: 'label_hidden', label: 'Label and hidden status' },
	{ value: 'omit_hidden', label: 'Omit hidden fields' },
	{ value: 'label_hidden_value', label: 'Label, hidden status, and value' },
	{ value: 'label_value', label: 'Label and value only' }
];

export const REALTIME_PAGE_CHECKPOINT_OPTIONS = [
	{ value: 'all_pages', label: 'All page transitions' },
	{ value: 'include_pages', label: 'Only selected pages' },
	{ value: 'exclude_pages', label: 'All except selected pages' }
];

export function createDefaultRealtimeSettings(): RealtimeSettings {
	return {
		auto_refresh_enabled: true,
		field_checkpoints_enabled: false,
		checkpoint_field_ids: [],
		page_checkpoints_enabled: false,
		page_checkpoint_mode: 'all_pages',
		page_checkpoint_pages: [],
		page_checkpoint_timeout_ms: 2500,
		storage_target_field_id: '',
		debounce_ms: 900,
		cooldown_ms: 8000,
		manual_refresh_enabled: true,
		blocking_mode: 'advisory',
		refresh_mode: 'auto',
		initial_panel_state: 'minimized',
		hidden_field_exposure_mode: 'label_hidden',
		pre_submit_run_enabled: false,
		pre_submit_timeout_ms: 2500
	};
}

export function isRealtimeEligibleActionId(actionId: string | null | undefined): boolean {
	return actionId === REALTIME_ACTION_ID;
}

export function normalizeRealtimeSettings(value: unknown): RealtimeSettings {
	const defaults = createDefaultRealtimeSettings();
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		return defaults;
	}

	const candidate = value as Record<string, unknown>;
	const refreshMode = normalizeRefreshMode(candidate.refresh_mode);
	const autoRefreshEnabled =
		typeof candidate.auto_refresh_enabled === 'boolean'
			? candidate.auto_refresh_enabled
			: refreshMode === 'auto';
	const fieldCheckpointsEnabled =
		typeof candidate.field_checkpoints_enabled === 'boolean'
			? candidate.field_checkpoints_enabled
			: refreshMode === 'checkpoint';
	const manualRefreshEnabled =
		typeof candidate.manual_refresh_enabled === 'boolean'
			? candidate.manual_refresh_enabled
			: defaults.manual_refresh_enabled;
	const pageCheckpointsEnabled =
		typeof candidate.page_checkpoints_enabled === 'boolean'
			? candidate.page_checkpoints_enabled
			: false;
	const nextRefreshMode: RealtimeRefreshMode = autoRefreshEnabled
		? 'auto'
		: fieldCheckpointsEnabled || pageCheckpointsEnabled
			? 'checkpoint'
			: 'manual';

	return {
		auto_refresh_enabled: autoRefreshEnabled,
		field_checkpoints_enabled: fieldCheckpointsEnabled,
		checkpoint_field_ids: normalizeStringArray(candidate.checkpoint_field_ids),
		page_checkpoints_enabled: pageCheckpointsEnabled,
		page_checkpoint_mode: normalizePageCheckpointMode(candidate.page_checkpoint_mode),
		page_checkpoint_pages: normalizePageNumbers(candidate.page_checkpoint_pages),
		page_checkpoint_timeout_ms: normalizeMillis(
			candidate.page_checkpoint_timeout_ms,
			defaults.page_checkpoint_timeout_ms ?? 2500,
			500,
			10000
		),
		storage_target_field_id:
			typeof candidate.storage_target_field_id === 'string'
				? candidate.storage_target_field_id.trim()
				: defaults.storage_target_field_id,
		debounce_ms: normalizeMillis(candidate.debounce_ms, defaults.debounce_ms ?? 900, 250, 5000),
		cooldown_ms: normalizeMillis(candidate.cooldown_ms, defaults.cooldown_ms ?? 8000, 0, 60000),
		manual_refresh_enabled: manualRefreshEnabled,
		blocking_mode:
			candidate.blocking_mode === 'require_answers' ? 'require_answers' : 'advisory',
		refresh_mode: nextRefreshMode,
		initial_panel_state: normalizeInitialPanelState(candidate.initial_panel_state),
		hidden_field_exposure_mode: normalizeHiddenFieldExposureMode(
			candidate.hidden_field_exposure_mode
		),
		pre_submit_run_enabled:
			typeof candidate.pre_submit_run_enabled === 'boolean'
				? candidate.pre_submit_run_enabled
				: defaults.pre_submit_run_enabled,
		pre_submit_timeout_ms: normalizeMillis(
			candidate.pre_submit_timeout_ms,
			defaults.pre_submit_timeout_ms ?? 2500,
			500,
			10000
		)
	};
}

export function summarizeRealtimeSettings(settings: RealtimeSettings): string {
	const normalized = normalizeRealtimeSettings(settings);
	const triggers = [];
	if (normalized.auto_refresh_enabled) triggers.push('auto');
	if (normalized.manual_refresh_enabled) triggers.push('manual');
	if (normalized.field_checkpoints_enabled) {
		const count = normalized.checkpoint_field_ids?.length ?? 0;
		triggers.push(count > 0 ? `${count} field checkpoint${count === 1 ? '' : 's'}` : 'field checkpoints');
	}
	if (normalized.page_checkpoints_enabled) triggers.push('page checkpoints');

	const storageLabel = normalized.storage_target_field_id
		? `stores in ${normalized.storage_target_field_id}`
		: 'automatic storage';
	const submitPolicy =
		normalized.blocking_mode === 'require_answers' ? 'required answers' : 'advisory';

	return `${triggers.length > 0 ? triggers.join(' + ') : 'manual only'} · ${storageLabel} · ${submitPolicy}`;
}

export function toDerivedRefreshMode(settings: RealtimeSettings): RealtimeRefreshMode {
	const normalized = normalizeRealtimeSettings(settings);
	if (normalized.auto_refresh_enabled) return 'auto';
	if (normalized.field_checkpoints_enabled || normalized.page_checkpoints_enabled) return 'checkpoint';
	return 'manual';
}

function normalizeStringArray(value: unknown): string[] {
	if (!Array.isArray(value)) {
		return [];
	}

	return Array.from(
		new Set(value.map((fieldId) => fieldId?.toString().trim()).filter(Boolean))
	);
}

function normalizePageNumbers(value: unknown): number[] {
	if (!Array.isArray(value)) {
		return [];
	}

	const pages = value
		.map((page) => Number.parseInt(String(page), 10))
		.filter((page) => Number.isFinite(page) && page > 0)
		.map((page) => Math.min(200, page));

	return Array.from(new Set(pages)).sort((a, b) => a - b);
}

function normalizeRefreshMode(value: unknown): RealtimeRefreshMode {
	const normalized = String(value ?? '').trim().toLowerCase();
	return ['auto', 'checkpoint', 'manual'].includes(normalized)
		? (normalized as RealtimeRefreshMode)
		: 'auto';
}

function normalizePageCheckpointMode(value: unknown): RealtimePageCheckpointMode {
	const normalized = String(value ?? '').trim().toLowerCase();
	return ['all_pages', 'include_pages', 'exclude_pages'].includes(normalized)
		? (normalized as RealtimePageCheckpointMode)
		: 'all_pages';
}

function normalizeInitialPanelState(value: unknown): RealtimeInitialPanelState {
	const normalized = String(value ?? '').trim().toLowerCase();
	return ['open', 'minimized', 'hidden_until_interaction'].includes(normalized)
		? (normalized as RealtimeInitialPanelState)
		: 'minimized';
}

function normalizeHiddenFieldExposureMode(value: unknown): RealtimeHiddenFieldExposureMode {
	const normalized = String(value ?? '').trim().toLowerCase();
	return ['omit_hidden', 'label_hidden', 'label_hidden_value', 'label_value'].includes(normalized)
		? (normalized as RealtimeHiddenFieldExposureMode)
		: 'label_hidden';
}

function normalizeMillis(value: unknown, fallback: number, min: number, max: number): number {
	const parsed = Number.parseInt(String(value ?? fallback), 10);
	return Number.isFinite(parsed) ? Math.min(max, Math.max(min, parsed)) : fallback;
}
