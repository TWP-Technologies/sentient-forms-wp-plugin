import { writable } from 'svelte/store';
import { createClientFromConfig, type SentientFormsApiClient } from '$lib/api/client';
import {
	parseLocalDiagnosticsBootstrap,
	type LocalDiagnosticsSettingsResponse
} from '$lib/api/local-diagnostics-contract';
import { notifications } from '$lib/stores/notifications';
import { readRuntimeConfigSafely } from '$lib/schemas/runtime-config';

export interface LocalDiagnosticsState {
	loading: boolean;
	saving: boolean;
	enabled: boolean;
	updatedAt: string | null;
	lastError: string | null;
}

const runtime = parseLocalDiagnosticsBootstrap(
	readRuntimeConfigSafely()?.telemetry
);
const initialState: LocalDiagnosticsState = {
	loading: false,
	saving: false,
	enabled: runtime?.enabled ?? false,
	updatedAt: runtime?.updatedAt ?? null,
	lastError: null
};

function mapResponse(payload: LocalDiagnosticsSettingsResponse): LocalDiagnosticsState {
	return {
		loading: false,
		saving: false,
		enabled: payload.local_diagnostics_enabled,
		updatedAt: payload.updated_at ?? null,
		lastError: null
	};
}

export function createLocalDiagnosticsStore(
	client?: SentientFormsApiClient
) {
	const { subscribe, set, update } = writable<LocalDiagnosticsState>({ ...initialState });
	const resolveClient = () => client ?? createClientFromConfig();

	return {
		subscribe,
		async load() {
			update((state) => ({ ...state, loading: true }));
			try {
				const response = await resolveClient().getLocalDiagnosticsSettings({
					showNotifications: false
				});
				const mapped = mapResponse(response);
				set(mapped);
				return mapped;
			} catch (error) {
				console.error('Failed to load local diagnostic settings', error);
				update((state) => ({
					...state,
					loading: false,
					lastError: 'Local diagnostic settings could not be loaded.'
				}));
				return null;
			}
		},
		async setEnabled(next: boolean) {
			update((state) => ({ ...state, saving: true }));
			try {
				const response = await resolveClient().updateLocalDiagnosticsSettings(next, {
					showNotifications: true
				});
				const mapped = mapResponse(response);
				set(mapped);
				notifications.success(next ? 'Local diagnostics enabled' : 'Local diagnostics disabled');
				return mapped;
			} catch (error) {
				console.error('Failed to update local diagnostic settings', error);
				update((state) => ({
					...state,
					saving: false,
					lastError: 'Local diagnostic preference could not be saved.'
				}));
				notifications.error('Unable to update local diagnostics');
				return null;
			}
		},
		reset() {
			set({ ...initialState });
		}
	};
}

export const localDiagnosticsStore = createLocalDiagnosticsStore();
