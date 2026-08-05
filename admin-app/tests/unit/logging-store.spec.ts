import { get } from 'svelte/store';
import { describe, expect, it, vi } from 'vitest';
import { SentientFormsApiClient } from '$lib/api/client';
import type { PluginSettingsResponse } from '$lib/api/types';
import { createLoggingStore } from '$lib/stores/logging.svelte';

const olderSettings: PluginSettingsResponse = {
	enable_logging: false,
	execution_global_disabled: false,
	execution_provider_disabled: {},
	execution_event_retention_days: 90,
	submission_ledger_retention_days: 90,
	delete_data_on_uninstall: true,
	store_full_ai_outputs: false,
	managed_zdr_required: false,
	privacy_setup_profile: 'balanced',
	privacy_setup_completed_at: '2026-04-21T00:00:00Z'
};

describe('logging store settings synchronization', () => {
	it('keeps authoritative parsed settings when an older load resolves afterward', async () => {
		let resolveOlderLoad: ((settings: PluginSettingsResponse) => void) | null = null;
		const olderLoad = new Promise<PluginSettingsResponse>((resolve) => {
			resolveOlderLoad = resolve;
		});
		const client = new SentientFormsApiClient({
			baseUrl: 'https://example.test/wp-json/sentient-forms/v1/',
			fetchImpl: vi.fn()
		});
		vi.spyOn(client, 'getSettings').mockReturnValueOnce(olderLoad);
		const logging = createLoggingStore(client);

		const loadPromise = logging.load();
		logging.synchronize({ ...olderSettings, enable_logging: true });
		resolveOlderLoad?.(olderSettings);
		await loadPromise;

		expect(get(logging)).toEqual({
			loading: false,
			saving: false,
			enabled: true,
			lastError: null
		});
	});

	it('keeps authoritative settings when an older load rejects afterward', async () => {
		let rejectOlderLoad: ((error: Error) => void) | null = null;
		const olderLoad = new Promise<PluginSettingsResponse>((_resolve, reject) => {
			rejectOlderLoad = reject;
		});
		const client = new SentientFormsApiClient({
			baseUrl: 'https://example.test/wp-json/sentient-forms/v1/',
			fetchImpl: vi.fn()
		});
		vi.spyOn(client, 'getSettings').mockReturnValueOnce(olderLoad);
		const logging = createLoggingStore(client);

		const loadPromise = logging.load();
		logging.synchronize({ ...olderSettings, enable_logging: true });
		rejectOlderLoad?.(new Error('Older settings request failed'));
		await loadPromise;

		expect(get(logging)).toEqual({
			loading: false,
			saving: false,
			enabled: true,
			lastError: null
		});
	});
});
