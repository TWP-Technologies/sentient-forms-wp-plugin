import { get } from 'svelte/store';
import { afterEach, describe, expect, it, vi } from 'vitest';

describe('local diagnostics bootstrap boundary', () => {
	afterEach(() => {
		Reflect.deleteProperty(window, 'sentientFormsConfig');
		vi.resetModules();
	});

	it('fails closed when WordPress injects malformed local diagnostic settings', async () => {
		Reflect.set(window, 'sentientFormsConfig', {
			apiBaseUrl: '/wp-json/sentient-forms/v1/',
			restNonce: 'nonce',
			ajaxNonce: 'ajax-nonce',
			siteUrl: 'https://example.test',
			telemetry: {
				enabled: 'false',
				updatedAt: 42
			}
		});

		const { localDiagnosticsStore } = await import('$lib/stores/telemetry.svelte');

		expect(get(localDiagnosticsStore)).toMatchObject({
			enabled: false,
			updatedAt: null
		});
	});
});
