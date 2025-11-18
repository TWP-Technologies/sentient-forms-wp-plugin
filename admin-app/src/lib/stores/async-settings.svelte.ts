import { writable } from 'svelte/store';
import { createClientFromConfig, type AsyncSettingsPayload, type SentientFormsApiClient } from '$lib/api/client';
import type { AsyncSettingsResponse } from '$lib/api/types';
import { notifications } from '$lib/stores/notifications';

export interface AsyncSettingsState {
	loading: boolean;
	saving: boolean;
	maxAttempts: number;
	baseDelaySeconds: number;
	maxDelaySeconds: number;
	updatedAt: string | null;
	updatedBy: string | null;
	lastError: string | null;
}

const runtime =
	typeof window === 'undefined' ? undefined : window.sentientFormsConfig?.asyncSettings;

const initialState: AsyncSettingsState = {
	loading: false,
	saving: false,
	maxAttempts: runtime?.maxAttempts ?? 3,
	baseDelaySeconds: runtime?.baseDelaySeconds ?? 60,
	maxDelaySeconds: runtime?.maxDelaySeconds ?? 3600,
	updatedAt: runtime?.updatedAt ?? null,
	updatedBy: runtime?.updatedBy ?? null,
	lastError: null
};

function mapResponse(payload: AsyncSettingsResponse): AsyncSettingsState {
	return {
		loading: false,
		saving: false,
		maxAttempts: payload.max_attempts,
		baseDelaySeconds: payload.base_delay_seconds,
		maxDelaySeconds: payload.max_delay_seconds,
		updatedAt: payload.updated_at,
		updatedBy: payload.updated_by ?? null,
		lastError: null
	};
}

export function createAsyncSettingsStore(client: SentientFormsApiClient = createClientFromConfig()) {
	const { subscribe, set, update } = writable<AsyncSettingsState>({ ...initialState });

	return {
		subscribe,
		async load() {
			update((state) => ({ ...state, loading: true }));
			try {
				const response = await client.getAsyncSettings({ showNotifications: false });
				const mapped = mapResponse(response);
				set(mapped);
				return mapped;
			} catch (error) {
				console.error('Failed to load async settings', error);
				update((state) => ({ ...state, loading: false, lastError: 'load_failed' }));
				return null;
			}
		},
		async save(payload: AsyncSettingsPayload) {
			update((state) => ({ ...state, saving: true }));
			try {
				const response = await client.updateAsyncSettings(payload, { showNotifications: true });
				const mapped = mapResponse(response);
				set(mapped);
				notifications.success('Async settings saved');
				return mapped;
			} catch (error) {
				console.error('Failed to update async settings', error);
				update((state) => ({ ...state, saving: false, lastError: 'update_failed' }));
				notifications.error('Unable to save async settings');
				return null;
			}
		},
		reset() {
			set({ ...initialState });
		}
	};
}

export const asyncSettingsStore = createAsyncSettingsStore();
