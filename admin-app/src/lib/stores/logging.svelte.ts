import { writable } from 'svelte/store';
import { createClientFromConfig, type SentientFormsApiClient } from '$lib/api/client';
import type { PluginSettingsResponse } from '$lib/api/types';
import { notifications } from '$lib/stores/notifications';

type LoggingState = {
	loading: boolean;
	saving: boolean;
	enabled: boolean;
	lastError: string | null;
};

const initialState: LoggingState = {
	loading: false,
	saving: false,
	enabled: false,
	lastError: null
};

export function createLoggingStore(client: SentientFormsApiClient = createClientFromConfig()) {
	const { subscribe, set, update } = writable<LoggingState>({ ...initialState });
	let loadGeneration = 0;

	async function load() {
		const requestGeneration = ++loadGeneration;
		update((s) => ({ ...s, loading: true }));
		try {
			const settings = await client.getSettings({ showNotifications: false });
			if (requestGeneration !== loadGeneration) return settings;
			set({
				loading: false,
				saving: false,
				enabled: Boolean(settings.enable_logging),
				lastError: null
			});
			return settings;
		} catch (error) {
			if (requestGeneration !== loadGeneration) return null;
			console.error('Failed to load logging settings', error);
			update((s) => ({ ...s, loading: false, lastError: 'Failed to load logging settings' }));
			return null;
		}
	}

	async function setEnabled(next: boolean) {
		loadGeneration += 1;
		update((s) => ({ ...s, saving: true }));
		try {
			await client.updateSettings({ enable_logging: next }, { showNotifications: true });
			set({ loading: false, saving: false, enabled: next, lastError: null });
			notifications.success(next ? 'Logging enabled' : 'Logging disabled');
		} catch (error) {
			console.error('Failed to update logging setting', error);
			update((s) => ({ ...s, saving: false, lastError: 'Failed to update logging setting' }));
			notifications.error('Unable to update logging setting');
		}
	}

	function synchronize(settings: PluginSettingsResponse) {
		loadGeneration += 1;
		set({
			loading: false,
			saving: false,
			enabled: Boolean(settings.enable_logging),
			lastError: null
		});
	}

	return { subscribe, load, setEnabled, synchronize };
}

export const loggingStore = createLoggingStore();
