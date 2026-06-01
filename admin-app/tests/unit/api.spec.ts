import { describe, it, expect, vi, afterEach } from 'vitest';
import { apiFetch, ApiError } from '$lib/api/http';
import {
	SESSION_EXPIRED_EVENT,
	resetSessionExpiryAnnouncementForTests
} from '$lib/api/session-expiry';
import {
	SECURITY_ROADBLOCK_EVENT,
	resetSecurityRoadblockAnnouncementForTests
} from '$lib/api/security-roadblock';
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
		resetSecurityRoadblockAnnouncementForTests();
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

	it('classifies Cloudflare challenges separately from WordPress session expiry', async () => {
		window.sentientFormsConfig = config;
		const errors = vi.spyOn(notifications, 'error');
		const sessionHandler = vi.fn();
		const securityHandler = vi.fn();
		window.addEventListener(SESSION_EXPIRED_EVENT, sessionHandler);
		window.addEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);

		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: false,
				status: 403,
				headers: new Headers({
					'content-type': 'text/html',
					'cf-mitigated': 'challenge',
					'cf-ray': 'a037249e8cf5ff58-ORD'
				}),
				text: () => Promise.resolve('<html><title>Just a moment...</title></html>')
			})
		);

		await expect(apiFetch('site-context', { showNotifications: true })).rejects.toMatchObject({
			status: 403,
			code: 'security_roadblock_interrupted_request',
			message: 'This request reached a site security layer before WordPress could process it.'
		});

		expect(securityHandler).toHaveBeenCalledTimes(1);
		expect(securityHandler.mock.calls[0]?.[0].detail).toMatchObject({
			label: 'Confirmed Cloudflare challenge',
			kind: 'cloudflare_challenge',
			confidence: 'confirmed',
			rayId: 'a037249e8cf5ff58-ORD'
		});
		expect(sessionHandler).not.toHaveBeenCalled();
		expect(errors).toHaveBeenCalledWith(
			'Confirmed Cloudflare challenge: This request reached a site security layer before WordPress could process it.'
		);

		window.removeEventListener(SESSION_EXPIRED_EVENT, sessionHandler);
		window.removeEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);
	});

	it('classifies Cloudflare branded HTML block pages without generic security matching', async () => {
		window.sentientFormsConfig = config;
		const securityHandler = vi.fn();
		window.addEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);

		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: false,
				status: 403,
				headers: new Headers({
					'content-type': 'text/html',
					server: 'cloudflare'
				}),
				text: () =>
					Promise.resolve(
						'<html><title>Attention Required! | Cloudflare</title><body>Ray ID: a037249e8cf5ff58-ORD</body></html>'
					)
			})
		);

		await expect(apiFetch('site-context', { showNotifications: false })).rejects.toMatchObject({
			status: 403,
			code: 'security_roadblock_interrupted_request'
		});

		expect(securityHandler).toHaveBeenCalledTimes(1);
		expect(securityHandler.mock.calls[0]?.[0].detail).toMatchObject({
			label: 'Cloudflare block suspected',
			kind: 'cloudflare_block',
			confidence: 'suspected',
			rayId: 'a037249e8cf5ff58-ORD'
		});

		window.removeEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);
	});

	it('does not classify Cloudflare-proxied JSON security errors as Cloudflare blocks', async () => {
		window.sentientFormsConfig = config;
		const errors = vi.spyOn(notifications, 'error');
		const securityHandler = vi.fn();
		window.addEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);

		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: false,
				status: 403,
				headers: new Headers({
					'content-type': 'application/json',
					server: 'cloudflare'
				}),
				json: () => Promise.resolve({ message: 'Your site security settings prevent this action.' })
			})
		);

		await expect(apiFetch('site-context', { showNotifications: true })).rejects.toMatchObject({
			status: 403,
			message: 'Request failed',
			payload: {
				message: 'Your site security settings prevent this action.'
			}
		});

		expect(securityHandler).not.toHaveBeenCalled();
		expect(errors).toHaveBeenCalledWith('Your site security settings prevent this action.');

		window.removeEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);
	});

	it('does not classify Cloudflare-proxied JSON rate limit errors as Cloudflare rate limits', async () => {
		window.sentientFormsConfig = config;
		const errors = vi.spyOn(notifications, 'error');
		const securityHandler = vi.fn();
		window.addEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);

		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: false,
				status: 429,
				headers: new Headers({
					'content-type': 'application/json',
					server: 'cloudflare'
				}),
				json: () =>
					Promise.resolve({
						code: 'rate_limited',
						message: 'You are rate limited by Sentient Forms. Please wait and retry.'
					})
			})
		);

		await expect(apiFetch('suggestions', { showNotifications: true })).rejects.toMatchObject({
			status: 429,
			message: 'Request failed',
			payload: {
				code: 'rate_limited',
				message: 'You are rate limited by Sentient Forms. Please wait and retry.'
			}
		});

		expect(securityHandler).not.toHaveBeenCalled();
		expect(errors).toHaveBeenCalledWith(
			'You are rate limited by Sentient Forms. Please wait and retry.'
		);

		window.removeEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);
	});
});
