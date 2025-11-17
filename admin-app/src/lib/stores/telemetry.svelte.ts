import { writable } from 'svelte/store';
import { createClientFromConfig, type SentientFormsApiClient } from '$lib/api/client';
import type { TelemetrySettingsResponse } from '$lib/api/types';
import { notifications } from '$lib/stores/notifications';

export interface TelemetryState {
	loading: boolean;
	saving: boolean;
	optIn: boolean;
	updatedAt: string | null;
	syncedAt: string | null;
	remoteUpdatedAt: string | null;
	lastError: string | null;
}

const runtime = typeof window === 'undefined' ? undefined : window.sentientFormsConfig?.telemetry;
const initialState: TelemetryState = {
	loading: false,
	saving: false,
	optIn: runtime?.optIn ?? false,
	updatedAt: runtime?.updatedAt ?? null,
	syncedAt: runtime?.syncedAt ?? null,
	remoteUpdatedAt: runtime?.remoteUpdatedAt ?? null,
	lastError: runtime?.lastError ?? null
};

function mapResponse(payload: TelemetrySettingsResponse): TelemetryState {
	return {
		loading: false,
		saving: false,
		optIn: Boolean(payload.telemetry_opt_in),
		updatedAt: payload.updated_at ?? null,
		syncedAt: payload.synced_at ?? null,
		remoteUpdatedAt: payload.remote_updated_at ?? null,
		lastError: payload.last_error ?? null
	};
}

export function createTelemetryStore(client: SentientFormsApiClient = createClientFromConfig()) {
	const { subscribe, set, update } = writable<TelemetryState>({ ...initialState });

	return {
		subscribe,
		async load() {
			update((state) => ({ ...state, loading: true }));
			try {
				const response = await client.getTelemetrySettings({ showNotifications: false });
				const mapped = mapResponse(response);
				set(mapped);
				return mapped;
			} catch (error) {
				console.error('Failed to load telemetry settings', error);
				update((state) => ({ ...state, loading: false }));
				return null;
			}
		},
		async setOptIn(next: boolean) {
			update((state) => ({ ...state, saving: true }));
			try {
				const response = await client.updateTelemetrySettings(next, { showNotifications: true });
				const mapped = mapResponse(response);
				set(mapped);
				notifications.success(next ? 'Telemetry enabled' : 'Telemetry disabled');
				return mapped;
			} catch (error) {
				console.error('Failed to update telemetry', error);
				update((state) => ({ ...state, saving: false }));
				notifications.error('Unable to update telemetry preference');
				return null;
			}
		},
		reset() {
			set({ ...initialState });
		}
	};
}

export const telemetryStore = createTelemetryStore();
