import { describe, it, expect, afterEach, vi } from 'vitest';
import { SentientFormsApiClient, ApiClientError } from '$lib/api/client';
import type { LicenseActivationRequest } from '$lib/api/types';
import { notifications } from '$lib/stores/notifications';

const baseUrl = 'https://example.test/wp-json/sentient-forms/v1/';

const mockFetch = vi.fn();
const client = new SentientFormsApiClient({
	baseUrl,
	fetchImpl: mockFetch,
	getNonce: () => 'nonce'
});

describe('SentientFormsApiClient', () => {
	afterEach(() => {
		vi.restoreAllMocks();
		mockFetch.mockReset();
	});

	it('activates license and normalizes response', async () => {
		const payload: LicenseActivationRequest = {
			licenseKey: 'LIC-123',
			siteUrl: 'https://site.test',
			localSiteIdentifier: 'site-guid'
		};

		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					success: true,
					data: {
						success: true,
						message: 'Activated',
						status: 'active',
						proxy_api_key: 'proxy-123',
						tier: 'starter',
						expiry_date: '2026-01-01',
						license_id: 'lic-1',
						site_id: 'site-1'
					}
				})
		});

		const result = await client.activateLicense(payload, { showNotifications: false });

		expect(mockFetch).toHaveBeenCalledWith(`${baseUrl}license/activate`, expect.objectContaining({
			method: 'POST'
		}));
		expect(result).toEqual({
			success: true,
			message: 'Activated',
			status: 'active',
			proxyApiKey: 'proxy-123',
			tier: 'starter',
			expiryDate: '2026-01-01',
			licenseId: 'lic-1',
			siteId: 'site-1'
		});
	});

	it('unwraps license info envelope', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					success: true,
					data: {
						license_key_masked: 'LIC-****',
						status: 'active',
						proxy_key_present: true,
						expires_at: '2026-01-01',
						last_synced: '2025-10-20 00:00:00',
						tier: 'starter',
						license_id: 'lic-1',
						site_id: 'site-1',
						site_url: 'https://site.test'
					}
				})
		});

		const result = await client.getLicenseInfo({ showNotifications: false });
		expect(result).toEqual({
			license_key_masked: 'LIC-****',
			status: 'active',
			proxy_key_present: true,
			expires_at: '2026-01-01',
			last_synced: '2025-10-20 00:00:00',
			tier: 'starter',
			license_id: 'lic-1',
			site_id: 'site-1',
			site_url: 'https://site.test'
		});
	});

	it('surfaces ApiClientError with code and notification', async () => {
		const notifySpy = vi.spyOn(notifications, 'error');

		mockFetch.mockResolvedValue({
			ok: false,
			status: 400,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve({ error_code: 'invalid_key', message: 'Invalid license' })
		});

		await expect(
			client.activateLicense(
				{ licenseKey: 'bad', siteUrl: 'https://site.test', localSiteIdentifier: 'site-guid' },
				{ showNotifications: true }
			)
		).rejects.toMatchObject({ code: 'invalid_key' });

		expect(notifySpy).toHaveBeenCalledWith('Invalid license');
	});

	it('coerces unknown rejection into ApiClientError', async () => {
		mockFetch.mockRejectedValue(new Error('Network down'));

		await expect(
			client.getLicenseInfo({ showNotifications: false })
		).rejects.toBeInstanceOf(ApiClientError);
	});
});
