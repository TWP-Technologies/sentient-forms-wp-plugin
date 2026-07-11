import { writable } from 'svelte/store';
import { createClientFromConfig, type SentientFormsApiClient } from '$lib/api/client';
import type { TelemetrySettingsResponse } from '$lib/api/types';
import { notifications } from '$lib/stores/notifications';
import { readRuntimeConfigSafely } from '$lib/schemas/runtime-config';

export interface TelemetryState {
	loading: boolean;
	saving: boolean;
	optIn: boolean;
	updatedAt: string | null;
	lastError: string | null;
}

const runtime = readRuntimeConfigSafely()?.telemetry;
const initialState: TelemetryState = {
	loading: false,
	saving: false,
	optIn: runtime?.optIn ?? false,
	updatedAt: runtime?.updatedAt ?? null,
	lastError: null
};

function mapResponse(payload: TelemetrySettingsResponse): TelemetryState {
	return {
		loading: false,
		saving: false,
		optIn: Boolean(payload.telemetry_opt_in),
		updatedAt: payload.updated_at ?? null,
		lastError: null
	};
}

export function createTelemetryStore(client: SentientFormsApiClient = createClientFromConfig()) {
	const { subscribe, set, update } = writable<TelemetryState>({ ...initialState });

	return {
		subscribe,
		async load() {
			update((state) => ({ ...state, loading: true, lastError: null }));
			try {
				const response = await client.getTelemetrySettings({ showNotifications: false });
				const mapped = mapResponse(response);
				set(mapped);
				return mapped;
			} catch (error) {
				console.error('Failed to load telemetry settings', error);
				update((state) => ({
					...state,
					loading: false,
					lastError: 'Unable to load the local diagnostic preference.'
				}));
				return null;
			}
		},
		async setOptIn(next: boolean) {
			update((state) => ({ ...state, saving: true, lastError: null }));
			try {
				const response = await client.updateTelemetrySettings(next, { showNotifications: true });
				const mapped = mapResponse(response);
				set(mapped);
				notifications.success(
					next ? 'Local diagnostic events enabled' : 'Local diagnostic events disabled'
				);
				return mapped;
			} catch (error) {
				console.error('Failed to update telemetry', error);
				update((state) => ({
					...state,
					saving: false,
					lastError: 'Unable to save the local diagnostic preference.'
				}));
				notifications.error('Unable to update local diagnostic preference');
				return null;
			}
		},
		reset() {
			set({ ...initialState });
		}
	};
}

export const telemetryStore = createTelemetryStore();
