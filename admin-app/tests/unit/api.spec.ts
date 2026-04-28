import { describe, it, expect, vi, afterEach } from 'vitest';
import { apiFetch, ApiError } from '$lib/api/http';
import {
	SESSION_EXPIRED_EVENT,
	resetSessionExpiryAnnouncementForTests
} from '$lib/api/session-expiry';
import { notifications } from '$lib/stores/notifications';

declare global {
	interface Window {
		sentientFormsConfig: {
			apiBaseUrl: string;
			restNonce: string;
		};
	}
}

describe('apiFetch', () => {
	const config = {
		apiBaseUrl: 'https://example.com/wp-json/sentient-forms/v1/',
		restNonce: 'abc123'
	};

	afterEach(() => {
		vi.restoreAllMocks();
		resetSessionExpiryAnnouncementForTests();
	});

	it('makes a successful request', async () => {
		window.sentientFormsConfig = config;
		const payload = { success: true };

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: true,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve(payload)
		}));

		const result = await apiFetch('test');
		expect(result).toEqual(payload);
	});

	it('returns null for successful empty responses', async () => {
		window.sentientFormsConfig = config;
		const json = vi.fn();

		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: true,
				status: 204,
				headers: new Headers({ 'content-type': 'application/json', 'content-length': '0' }),
				json
			})
		);

		const result = await apiFetch('empty');
		expect(result).toBeNull();
		expect(json).not.toHaveBeenCalled();
	});

	it('throws ApiError on failure', async () => {
		window.sentientFormsConfig = config;

		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
			ok: false,
			status: 400,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve({ message: 'Bad Request' })
		}));

		await expect(apiFetch('bad')).rejects.toBeInstanceOf(ApiError);
	});

	it('announces expired WordPress sessions from the legacy wpFetch wrapper', async () => {
		window.sentientFormsConfig = config;
		const errors = vi.spyOn(notifications, 'error');
		const sessionHandler = vi.fn();
		window.addEventListener(SESSION_EXPIRED_EVENT, sessionHandler);

		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: false,
				status: 403,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' })
			})
		);

		await expect(apiFetch('bad', { showNotifications: true })).rejects.toBeInstanceOf(ApiError);

		expect(sessionHandler).toHaveBeenCalledTimes(1);
		expect(errors).toHaveBeenCalledWith(
			'WordPress session expired. Reload this admin page before retrying.',
			0
		);

		window.removeEventListener(SESSION_EXPIRED_EVENT, sessionHandler);
	});
});
