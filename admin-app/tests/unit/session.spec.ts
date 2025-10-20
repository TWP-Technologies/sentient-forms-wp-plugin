import { describe, expect, it, beforeEach } from 'vitest';
import { licenseSummary, mockSessionState, sessionStore } from '$lib/stores/session';

describe('sessionStore', () => {
	beforeEach(() => {
		sessionStore.reset();
	});

	it('hydrates state with partial payloads', () => {
		sessionStore.hydrate({ siteUrl: mockSessionState.siteUrl, licenseStatus: 'active' });

		sessionStore.subscribe((state) => {
			expect(state.siteUrl).toBe(mockSessionState.siteUrl);
			expect(state.licenseStatus).toBe('active');
		})();
	});

	it('computes license summary', () => {
		sessionStore.hydrate({ licenseStatus: 'active', proxyKeyPresent: true });

		licenseSummary.subscribe((summary) => {
			expect(summary).toBe('License active');
		})();
	});
});
