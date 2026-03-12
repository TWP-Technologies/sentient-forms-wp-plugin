import { describe, expect, it, beforeEach } from 'vitest';
import { get } from 'svelte/store';
import { licenseSummary, mockSessionState, sessionStore } from '$lib/stores/session';

describe('sessionStore', () => {
	beforeEach(() => {
		sessionStore.reset();
	});

	it('hydrates state with partial payloads', () => {
		sessionStore.hydrate({ siteUrl: mockSessionState.siteUrl, licenseStatus: 'active' });

		const state = get({ subscribe: sessionStore.subscribe });
		expect(state.siteUrl).toBe(mockSessionState.siteUrl);
		expect(state.licenseStatus).toBe('active');
	});

	it('computes license summary', () => {
		sessionStore.hydrate({ licenseStatus: 'active', proxyKeyPresent: true });

		const summary = get(licenseSummary);
		expect(summary).toBe('License active');
	});

	it('treats trial as connected in the summary', () => {
		sessionStore.hydrate({ licenseStatus: 'trial', proxyKeyPresent: true });

		const summary = get(licenseSummary);
		expect(summary).toBe('Trial active');
	});
});
