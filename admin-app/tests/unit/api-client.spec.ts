import { describe, it, expect, afterEach, vi } from 'vitest';
import {
	clearSentientFormsApiCache,
	SentientFormsApiClient,
	ApiClientError,
	ApiContractError
} from '$lib/api/client';
import {
	SESSION_EXPIRED_EVENT,
	resetSessionExpiryAnnouncementForTests
} from '$lib/api/session-expiry';
import {
	SECURITY_ROADBLOCK_EVENT,
	resetSecurityRoadblockAnnouncementForTests
} from '$lib/api/security-roadblock';
import type { LicenseActivationRequest } from '$lib/api/types';
import { notifications } from '$lib/stores/notifications';
import { z } from 'zod';

const baseUrl = 'https://example.test/wp-json/sentient-forms/v1/';

const mockFetch = vi.fn();
const client = new SentientFormsApiClient({
	baseUrl,
	fetchImpl: mockFetch,
	getNonce: () => 'nonce'
});

const objectPayloadSchema = z.record(z.string(), z.unknown());

function requestObject(
	path: string,
	options: Parameters<SentientFormsApiClient['requestParsed']>[2] = {}
) {
	return client.requestParsed(path, objectPayloadSchema, options);
}

function licenseInfoFixture(status: string) {
	return {
		license_key_masked: 'LIC-…-123',
		status,
		proxy_key_present: true,
		expires_at: null,
		last_synced: null,
		tier: null,
		license_id: 'license-1',
		site_id: 'site-1',
		site_url: 'https://example.test'
	};
}

function localActionTemplateFixture() {
	return {
		id: 1,
		source: 'plugin',
		external_id: 'spam_detection_v1',
		code: 'spam_detection_v1',
		display_name: 'Spam detection',
		description: null,
		prompt_template: null,
		default_model: null,
		structured_output_schema: null,
		override_schema: null,
		version: '1',
		is_active: true,
		created_at: null,
		updated_at: null
	};
}

function localCustomActionFixture(model: string) {
	return {
		id: 1,
		external_id: 'custom-action-1',
		template_id: null,
		code: 'custom-action-1',
		display_name: 'Custom action',
		definition_json: { model },
		model_selection_json: null,
		status: 'active',
		created_at: null,
		updated_at: null
	};
}

function jsonResponse(body: unknown, status = 200) {
	return {
		ok: status >= 200 && status < 300,
		status,
		headers: new Headers({ 'content-type': 'application/json' }),
		json: () => Promise.resolve(body)
	};
}

function formActionsBootstrapFixture(providerPathPolicy?: unknown) {
	return {
		form_source: 'gravity_forms',
		form_id: 42,
		actions: [],
		execution_status: {
			status: 'unknown',
			message: null,
			entry_id: null,
			last_error_code: null,
			last_result: null
		},
		disabled_state: {
			sf_disabled: false,
			global_disabled: false,
			provider_disabled: false,
			effective_disabled: false
		},
		...(providerPathPolicy === undefined ? {} : { provider_path_policy: providerPathPolicy }),
		generated_at: '2030-01-05T10:00:00Z'
	};
}

describe('SentientFormsApiClient', () => {
	afterEach(() => {
		vi.restoreAllMocks();
		mockFetch.mockReset();
		clearSentientFormsApiCache();
		resetSessionExpiryAnnouncementForTests();
		resetSecurityRoadblockAnnouncementForTests();
	});

	it('deduplicates concurrent cached GET requests', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve({ success: true, data: { ready: true } })
		});

		const first = requestObject('meta/capabilities', {
			cacheTtlMs: 60_000,
			cacheTags: ['meta'],
			showNotifications: false
		});
		const second = requestObject('meta/capabilities', {
			cacheTtlMs: 60_000,
			cacheTags: ['meta'],
			showNotifications: false
		});

		await expect(Promise.all([first, second])).resolves.toEqual([
			{ success: true, data: { ready: true } },
			{ success: true, data: { ready: true } }
		]);
		expect(mockFetch).toHaveBeenCalledTimes(1);
	});

	it('parses additive versioned responses before returning them', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				version: 'v2',
				ready: true,
				future_field: 'accepted without becoming trusted'
			})
		);

		const result = await client.requestParsed(
			'meta/capabilities',
			z.object({ version: z.literal('v2'), ready: z.boolean() }),
			{ showNotifications: false }
		);

		expect(result).toEqual({ version: 'v2', ready: true });
	});

	it('resolves named endpoint contracts through the central registry', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				session_id: 'bps_123',
				portal_url: 'https://billing.example.test/session',
				customer_id: 'cus_123',
				future_field: 'not trusted'
			})
		);

		const result = await client.requestEndpoint('billing.portal.create', {
			method: 'POST',
			body: { return_url: 'https://example.test/settings' },
			showNotifications: false
		});

		expect(result).toEqual({
			session_id: 'bps_123',
			portal_url: 'https://billing.example.test/session',
			customer_id: 'cus_123'
		});
	});

	it('rejects a missing required registered request body before network I/O', async () => {
		await expect(
			client.requestEndpoint('provider.openrouter.validate', {
				method: 'POST',
				showNotifications: false
			})
		).rejects.toBeInstanceOf(ApiContractError);
		expect(mockFetch).not.toHaveBeenCalled();
	});

	it.each(['//evil.example/steal', 'https://evil.example/steal'])(
		'rejects foreign endpoint override %s before reading the nonce',
		async (pathOverride) => {
			const getNonce = vi.fn(() => 'secret-nonce');
			const isolatedClient = new SentientFormsApiClient({
				baseUrl,
				fetchImpl: mockFetch,
				getNonce
			});

			await expect(
				isolatedClient.requestEndpoint(
					'billing.portal.create',
					{
						method: 'POST',
						body: { return_url: 'https://example.test/settings' },
						showNotifications: false
					},
					pathOverride
				)
			).rejects.toThrow('same-origin');
			expect(getNonce).not.toHaveBeenCalled();
			expect(mockFetch).not.toHaveBeenCalled();
		}
	);

	it('reports contract failures without retaining the rejected payload', async () => {
		const secret = 'sk-must-not-escape';
		mockFetch.mockResolvedValue(jsonResponse({ version: 'v2', secret }));

		let rejected: unknown;
		try {
			await client.requestParsed(
				'meta/capabilities',
				z.object({ version: z.literal('v2'), ready: z.boolean() }),
				{ showNotifications: false }
			);
		} catch (error) {
			rejected = error;
		}

		expect(rejected).toBeInstanceOf(ApiContractError);
		expect(rejected).toMatchObject({
			endpoint: 'meta/capabilities',
			issues: [expect.objectContaining({ code: 'invalid_type', path: ['ready'] })]
		});
		expect(JSON.stringify(rejected)).not.toContain(secret);
	});

	it('evicts malformed cached values before retrying the network contract', async () => {
		mockFetch
			.mockResolvedValueOnce(jsonResponse({ version: 'v2', ready: 'not-a-boolean' }))
			.mockResolvedValueOnce(jsonResponse({ version: 'v2', ready: true }));
		const cacheOptions = {
			cacheTtlMs: 60_000,
			cacheTags: ['meta'],
			cacheStorage: 'session' as const,
			showNotifications: false
		};

		await requestObject('meta/capabilities', cacheOptions);
		const result = await client.requestParsed(
			'meta/capabilities',
			z.object({ version: z.literal('v2'), ready: z.boolean() }),
			cacheOptions
		);
		const cachedResult = await client.requestParsed(
			'meta/capabilities',
			z.object({ version: z.literal('v2'), ready: z.boolean() }),
			cacheOptions
		);

		expect(result).toEqual({ version: 'v2', ready: true });
		expect(cachedResult).toEqual(result);
		expect(mockFetch).toHaveBeenCalledTimes(2);
	});

	it('parses non-2xx endpoint errors without retaining secret-bearing payload fields', async () => {
		const secret = 'sk-error-must-not-escape';
		mockFetch.mockResolvedValue(
			jsonResponse(
				{
					error_code: 'checkout_rejected',
					message: 'Checkout could not be created.',
					secret,
					error: {
						code: 'checkout_rejected',
						message: 'Checkout could not be created.',
						provider_secret: secret
					}
				},
				400
			)
		);

		let rejected: unknown;
		try {
			await client.requestEndpoint('billing.portal.create', {
				method: 'POST',
				body: { return_url: 'https://example.test/settings' },
				showNotifications: false
			});
		} catch (error) {
			rejected = error;
		}

		expect(rejected).toBeInstanceOf(ApiClientError);
		expect(rejected).toMatchObject({
			code: 'checkout_rejected',
			payload: {
				code: 'checkout_rejected',
				message: 'Checkout could not be created.'
			}
		});
		expect(JSON.stringify(rejected)).not.toContain(secret);
	});

	it('sends the parsed request body and ignores inherited toJSON hooks', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				session_id: 'bps_123',
				portal_url: 'https://billing.example.test/session',
				customer_id: 'cus_123'
			})
		);
		class PortalRequestBody {
			return_url = '  https://example.test/settings  ';

			toJSON() {
				return { return_url: 'javascript:alert(1)' };
			}
		}
		const body = new PortalRequestBody();

		await client.requestEndpoint('billing.portal.create', {
			method: 'POST',
			body,
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}license/billing/portal-session`,
			expect.objectContaining({
				body: JSON.stringify({ return_url: 'https://example.test/settings' })
			})
		);
	});

	it('invalidates tagged in-flight GETs before they can rewrite stale cache entries', async () => {
		let resolveStaleDashboard!: (response: unknown) => void;
		let dashboardRequests = 0;
		const staleDashboard = { success: true, data: { version: 'stale' } };
		const freshDashboard = { success: true, data: { version: 'fresh' } };

		mockFetch.mockImplementation((requestUrl, init) => {
			const url = String(requestUrl);
			const method = String(init?.method ?? 'GET').toUpperCase();

			if (url.endsWith('/admin/dashboard-summary') && method === 'GET') {
				dashboardRequests += 1;
				if (dashboardRequests === 1) {
					return new Promise((resolve) => {
						resolveStaleDashboard = resolve;
					});
				}

				return Promise.resolve(jsonResponse(freshDashboard));
			}

			if (url.endsWith('/settings') && method === 'PUT') {
				return Promise.resolve(jsonResponse({ success: true, data: { saved: true } }));
			}

			throw new Error(`Unexpected request: ${method} ${url}`);
		});

		const firstDashboard = requestObject('admin/dashboard-summary', {
			cacheTtlMs: 60_000,
			cacheTags: ['dashboard']
		});
		expect(dashboardRequests).toBe(1);

		await requestObject('settings', {
			method: 'PUT',
			body: { enable_logging: true }
		});

		const secondDashboard = await requestObject('admin/dashboard-summary', {
			cacheTtlMs: 60_000,
			cacheTags: ['dashboard']
		});
		resolveStaleDashboard(jsonResponse(staleDashboard));
		await expect(firstDashboard).resolves.toEqual(staleDashboard);

		const thirdDashboard = await requestObject('admin/dashboard-summary', {
			cacheTtlMs: 60_000,
			cacheTags: ['dashboard']
		});

		expect(secondDashboard).toEqual(freshDashboard);
		expect(thirdDashboard).toEqual(freshDashboard);
		expect(dashboardRequests).toBe(2);
		expect(mockFetch).toHaveBeenCalledTimes(3);
	});

	it('prevents stale in-flight GETs from overriding forced refresh cache entries', async () => {
		let resolveStaleDashboard!: (response: unknown) => void;
		let dashboardRequests = 0;
		const staleDashboard = { success: true, data: { version: 'stale' } };
		const freshDashboard = { success: true, data: { version: 'fresh' } };

		mockFetch.mockImplementation((requestUrl, init) => {
			const url = String(requestUrl);
			const method = String(init?.method ?? 'GET').toUpperCase();

			if (url.endsWith('/admin/dashboard-summary') && method === 'GET') {
				dashboardRequests += 1;
				if (dashboardRequests === 1) {
					return new Promise((resolve) => {
						resolveStaleDashboard = resolve;
					});
				}

				return Promise.resolve(jsonResponse(freshDashboard));
			}

			throw new Error(`Unexpected request: ${method} ${url}`);
		});

		const firstDashboard = requestObject('admin/dashboard-summary', {
			cacheTtlMs: 60_000,
			cacheTags: ['dashboard']
		});
		expect(dashboardRequests).toBe(1);

		const forcedDashboard = await requestObject('admin/dashboard-summary', {
			cacheTtlMs: 60_000,
			cacheTags: ['dashboard'],
			forceRefresh: true
		});

		resolveStaleDashboard(jsonResponse(staleDashboard));
		await expect(firstDashboard).resolves.toEqual(staleDashboard);

		const cachedDashboard = await requestObject('admin/dashboard-summary', {
			cacheTtlMs: 60_000,
			cacheTags: ['dashboard']
		});

		expect(forcedDashboard).toEqual(freshDashboard);
		expect(cachedDashboard).toEqual(freshDashboard);
		expect(dashboardRequests).toBe(2);
		expect(mockFetch).toHaveBeenCalledTimes(2);
	});

	it('keeps endpoint cache tags when callers add custom tags', async () => {
		let dashboardRequests = 0;
		mockFetch.mockImplementation((requestUrl, init) => {
			const url = String(requestUrl);
			const method = String(init?.method ?? 'GET').toUpperCase();
			if (url.endsWith('/admin/dashboard-summary') && method === 'GET') {
				dashboardRequests += 1;
				return Promise.resolve(
					jsonResponse({
						generated_at: `2030-01-01T00:00:0${dashboardRequests}Z`,
						providers: [],
						templates: [],
						custom_actions: [],
						recent_events: [],
						version: dashboardRequests
					})
				);
			}

			throw new Error(`Unexpected request: ${method} ${url}`);
		});

		const firstDashboard = await client.getDashboardSummary({ cacheTags: ['custom'] });
		clearSentientFormsApiCache(['dashboard']);
		const secondDashboard = await client.getDashboardSummary({ cacheTags: ['custom'] });

		expect(firstDashboard).toMatchObject({ generated_at: '2030-01-01T00:00:01Z' });
		expect(secondDashboard).toMatchObject({ generated_at: '2030-01-01T00:00:02Z' });
		expect(dashboardRequests).toBe(2);
		expect(mockFetch).toHaveBeenCalledTimes(2);
	});

	it('rejects malformed dashboard summary collection responses', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					success: true,
					data: {
						generated_at: '2030-01-01T00:00:00Z',
						providers: { openrouter: true },
						templates: [],
						custom_actions: [],
						recent_events: []
					}
				})
		});

		await expect(client.getDashboardSummary({ showNotifications: false })).rejects.toThrow();
	});

	it('normalizes concurrent cached GET failures for deduped callers', async () => {
		let rejectFetch!: (reason: unknown) => void;
		mockFetch.mockReturnValue(
			new Promise((_resolve, reject) => {
				rejectFetch = reject;
			})
		);

		const first = requestObject('meta/capabilities', {
			cacheTtlMs: 60_000,
			cacheTags: ['meta'],
			showNotifications: false
		});
		const second = requestObject('meta/capabilities', {
			cacheTtlMs: 60_000,
			cacheTags: ['meta'],
			showNotifications: false
		});

		rejectFetch(new Error('Network down'));

		const [firstResult, secondResult] = await Promise.allSettled([first, second]);

		expect(firstResult.status).toBe('rejected');
		expect(secondResult.status).toBe('rejected');
		if (firstResult.status === 'rejected') {
			expect(firstResult.reason).toBeInstanceOf(ApiClientError);
		}
		if (secondResult.status === 'rejected') {
			expect(secondResult.reason).toBeInstanceOf(ApiClientError);
		}
		expect(mockFetch).toHaveBeenCalledTimes(1);
	});

	it('applies deduped caller notification settings to shared GET failures', async () => {
		const notifySpy = vi.spyOn(notifications, 'error');
		let resolveFetch!: (response: unknown) => void;
		mockFetch.mockReturnValue(
			new Promise((resolve) => {
				resolveFetch = resolve;
			})
		);

		const first = requestObject('settings', {
			cacheTtlMs: 60_000,
			cacheTags: ['settings'],
			showNotifications: false
		});
		const second = requestObject('settings', {
			cacheTtlMs: 60_000,
			cacheTags: ['settings'],
			showNotifications: true
		});

		resolveFetch({
			ok: false,
			status: 400,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve({ message: 'Shared failure' })
		});

		const [firstResult, secondResult] = await Promise.allSettled([first, second]);

		expect(firstResult.status).toBe('rejected');
		expect(secondResult.status).toBe('rejected');
		if (firstResult.status === 'rejected') {
			expect(firstResult.reason).toBeInstanceOf(ApiClientError);
		}
		if (secondResult.status === 'rejected') {
			expect(secondResult.reason).toBeInstanceOf(ApiClientError);
		}
		expect(notifySpy).toHaveBeenCalledTimes(1);
		expect(notifySpy).toHaveBeenCalledWith('Shared failure');
		expect(mockFetch).toHaveBeenCalledTimes(1);
	});

	it('emits one notification for shared GET failures when multiple callers request one', async () => {
		const notifySpy = vi.spyOn(notifications, 'error');
		let resolveFetch!: (response: unknown) => void;
		mockFetch.mockReturnValue(
			new Promise((resolve) => {
				resolveFetch = resolve;
			})
		);

		const first = requestObject('settings', {
			cacheTtlMs: 60_000,
			cacheTags: ['settings'],
			showNotifications: true
		});
		const second = requestObject('settings', {
			cacheTtlMs: 60_000,
			cacheTags: ['settings'],
			showNotifications: true
		});

		resolveFetch({
			ok: false,
			status: 400,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve({ message: 'Shared failure' })
		});

		await Promise.allSettled([first, second]);

		expect(notifySpy).toHaveBeenCalledTimes(1);
		expect(notifySpy).toHaveBeenCalledWith('Shared failure');
		expect(mockFetch).toHaveBeenCalledTimes(1);
	});

	it('preserves default notifications for shared GET failures without caller overrides', async () => {
		const notifySpy = vi.spyOn(notifications, 'error');
		let resolveFetch!: (response: unknown) => void;
		mockFetch.mockReturnValue(
			new Promise((resolve) => {
				resolveFetch = resolve;
			})
		);

		const first = requestObject('settings', {
			cacheTtlMs: 60_000,
			cacheTags: ['settings']
		});
		const second = requestObject('settings', {
			cacheTtlMs: 60_000,
			cacheTags: ['settings']
		});

		resolveFetch({
			ok: false,
			status: 400,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve({ message: 'Shared failure' })
		});

		await Promise.allSettled([first, second]);

		expect(notifySpy).toHaveBeenCalledTimes(1);
		expect(notifySpy).toHaveBeenCalledWith('Shared failure');
		expect(mockFetch).toHaveBeenCalledTimes(1);
	});

	it('suppresses notifications for shared GET failures when all callers opt out', async () => {
		const notifySpy = vi.spyOn(notifications, 'error');
		let resolveFetch!: (response: unknown) => void;
		mockFetch.mockReturnValue(
			new Promise((resolve) => {
				resolveFetch = resolve;
			})
		);

		const first = requestObject('settings', {
			cacheTtlMs: 60_000,
			cacheTags: ['settings'],
			showNotifications: false
		});
		const second = requestObject('settings', {
			cacheTtlMs: 60_000,
			cacheTags: ['settings'],
			showNotifications: false
		});

		resolveFetch({
			ok: false,
			status: 400,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve({ message: 'Shared failure' })
		});

		await Promise.allSettled([first, second]);

		expect(notifySpy).not.toHaveBeenCalled();
		expect(mockFetch).toHaveBeenCalledTimes(1);
	});

	it('serves cached GET responses within the TTL', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve({ success: true, data: { value: 'cached' } })
		});

		await expect(
			requestObject('settings', {
				cacheTtlMs: 60_000,
				cacheTags: ['settings'],
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { value: 'cached' } });
		await expect(
			requestObject('settings', {
				cacheTtlMs: 60_000,
				cacheTags: ['settings'],
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { value: 'cached' } });

		expect(mockFetch).toHaveBeenCalledTimes(1);
	});

	it('separates cached GET responses by query string', async () => {
		mockFetch
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { page: 1 } })
			})
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { page: 2 } })
			});

		await expect(
			requestObject('local/execution-events?limit=5', {
				cacheTtlMs: 60_000,
				cacheTags: ['events'],
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { page: 1 } });
		await expect(
			requestObject('local/execution-events?limit=10', {
				cacheTtlMs: 60_000,
				cacheTags: ['events'],
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { page: 2 } });

		expect(mockFetch).toHaveBeenCalledTimes(2);
	});

	it('does not cache failed GET responses', async () => {
		mockFetch
			.mockResolvedValueOnce({
				ok: false,
				status: 500,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ message: 'Failed' })
			})
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { recovered: true } })
			});

		await expect(
			requestObject('settings', {
				cacheTtlMs: 60_000,
				cacheTags: ['settings'],
				showNotifications: false
			})
		).rejects.toBeInstanceOf(ApiClientError);
		await expect(
			requestObject('settings', {
				cacheTtlMs: 60_000,
				cacheTags: ['settings'],
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { recovered: true } });

		expect(mockFetch).toHaveBeenCalledTimes(2);
	});

	it('invalidates cached GET responses after successful mutations', async () => {
		mockFetch
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { version: 'before' } })
			})
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { saved: true } })
			})
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { version: 'after' } })
			});

		await expect(
			requestObject('settings', {
				cacheTtlMs: 60_000,
				cacheTags: ['settings'],
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { version: 'before' } });
		await expect(
			requestObject('settings', {
				method: 'PUT',
				body: { enable_logging: true },
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { saved: true } });
		await expect(
			requestObject('settings', {
				cacheTtlMs: 60_000,
				cacheTags: ['settings'],
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { version: 'after' } });

		expect(mockFetch).toHaveBeenCalledTimes(3);
	});

	it('invalidates cached GET responses after successful 204 mutations', async () => {
		mockFetch
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve(licenseInfoFixture('active'))
			})
			.mockResolvedValueOnce({
				ok: true,
				status: 204,
				headers: new Headers()
			})
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve(licenseInfoFixture('inactive'))
			});

		await expect(client.getLicenseInfo({ showNotifications: false })).resolves.toMatchObject({
			status: 'active'
		});
		await expect(client.deactivateLicense({ showNotifications: false })).resolves.toBeUndefined();
		await expect(client.getLicenseInfo({ showNotifications: false })).resolves.toMatchObject({
			status: 'inactive'
		});

		expect(mockFetch).toHaveBeenCalledTimes(3);
	});

	it('keeps unrelated cached GET responses warm after targeted mutations', async () => {
		mockFetch
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { version: 'before' } })
			})
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { feature: 'cached' } })
			})
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { saved: true } })
			})
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { version: 'after' } })
			});

		await expect(
			requestObject('settings', {
				cacheTtlMs: 60_000,
				cacheTags: ['settings'],
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { version: 'before' } });
		await expect(
			requestObject('meta/capabilities', {
				cacheTtlMs: 60_000,
				cacheTags: ['capabilities'],
				cacheStorage: 'session',
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { feature: 'cached' } });
		await expect(
			requestObject('settings', {
				method: 'PUT',
				body: { enable_logging: true },
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { saved: true } });
		await expect(
			requestObject('settings', {
				cacheTtlMs: 60_000,
				cacheTags: ['settings'],
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { version: 'after' } });
		await expect(
			requestObject('meta/capabilities', {
				cacheTtlMs: 60_000,
				cacheTags: ['capabilities'],
				cacheStorage: 'session',
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { feature: 'cached' } });

		expect(mockFetch).toHaveBeenCalledTimes(4);
	});

	it('invalidates cached capabilities after license mutations', async () => {
		const capabilitiesBefore = {
			supports_credits: true,
			supports_custom_actions: true
		};
		const capabilitiesAfter = {
			supports_credits: false,
			supports_custom_actions: false
		};

		mockFetch
			.mockResolvedValueOnce(jsonResponse(capabilitiesBefore))
			.mockResolvedValueOnce(jsonResponse({ success: true, data: { deactivated: true } }))
			.mockResolvedValueOnce(jsonResponse(capabilitiesAfter));

		await expect(client.getCapabilities()).resolves.toEqual(capabilitiesBefore);
		await expect(
			requestObject('license/deactivate', {
				method: 'POST',
				body: { confirm: true },
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { deactivated: true } });
		await expect(client.getCapabilities()).resolves.toEqual(capabilitiesAfter);

		expect(mockFetch).toHaveBeenCalledTimes(3);
	});

	it('keeps local action templates warm after custom action mutations', async () => {
		const templates = [localActionTemplateFixture()];
		mockFetch
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve(templates)
			})
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { id: 'custom-action-1' } })
			});

		await expect(client.getLocalActionTemplates({ showNotifications: false })).resolves.toEqual(
			templates
		);
		await expect(
			requestObject('local/custom-actions', {
				method: 'POST',
				body: { name: 'Custom action' },
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { id: 'custom-action-1' } });
		await expect(client.getLocalActionTemplates({ showNotifications: false })).resolves.toEqual(
			templates
		);

		expect(mockFetch).toHaveBeenCalledTimes(2);
	});

	it('loads form actions bootstrap through the consolidated form endpoint', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					form_source: 'gravity_forms',
					form_id: 42,
					form_source_descriptor: {
						slug: 'gravity_forms',
						label: 'Gravity Forms',
						is_active: true,
						lifecycles: {
							validation: {
								id: 'validation',
								supported: true,
								label: 'During validation',
								native_hook: 'gform_validation',
								execution_mode: 'blocking',
								requires_ledger: false,
								unsupported_reason: null
							}
						},
						ledger: {
							required_for_parity: false,
							enabled: false,
							settings_source: 'sentient_submission_ledger_settings',
							unavailable_reason: null
						}
					},
					actions: [
						{
							local_mapping_id: 'map-1',
							central_action_id: 'spam_detection_v1',
							action_type_indicator: 'master',
							trigger_hooks: ['validation']
						}
					],
					execution_status: {
						status: 'success',
						message: null,
						entry_id: 99,
						last_error_code: null,
						last_result: null
					},
					disabled_state: {
						sf_disabled: false,
						global_disabled: false,
						provider_disabled: false,
						effective_disabled: false
					},
					ledger_settings: {
						form_source: 'gravity_forms',
						form_id: 42,
						enabled: false,
						enabled_at: null,
						enabled_by_user_id: null,
						disabled_at: null,
						disabled_by_user_id: null,
						settings_source: 'sentient_submission_ledger_settings',
						ledger_records_endpoint: '/sentient-forms/v1/gravity_forms/forms/42/submissions',
						record_count: 0
					},
					provider_path_policy: {
						default_provider: 'sentient_managed',
						providers: {
							sentient_managed: {
								ready: true,
								credential_id: 14,
								blocked_reason_code: null
							},
							openrouter: {
								ready: true,
								credential_id: 7,
								blocked_reason_code: null
							}
						},
						actions: {
							spam_detection_v1: {
								selected_provider: 'sentient_managed',
								model_selection: {
									provider: 'sentient_managed',
									model: 'sf_default',
									credential_id: 14,
									selection: {
										primary: 'sf_default',
										provider: 'sentient_managed',
										is_preset: true,
										credential_id: 14
									},
									backup_provider: 'openrouter',
									backup_credential_id: 7,
									backup_model: '~openai/gpt-latest'
								},
								blocked_reason_code: null,
								requires_structured_output: true
							}
						}
					},
					generated_at: '2030-01-05T10:00:00Z'
				})
		});

		const result = await client.getFormActionsBootstrap('gravity_forms', 42, {
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}gravity_forms/forms/42/actions/bootstrap`,
			expect.objectContaining({ credentials: 'same-origin' })
		);
		expect(result.actions).toHaveLength(1);
		expect(result.execution_status.status).toBe('success');
		expect(result.disabled_state.effective_disabled).toBe(false);
		expect(result.form_source_descriptor?.lifecycles.validation.native_hook).toBe(
			'gform_validation'
		);
		expect(result.provider_path_policy?.default_provider).toBe('sentient_managed');
		expect(
			result.provider_path_policy?.actions.spam_detection_v1.model_selection?.backup_provider
		).toBe('openrouter');
		expect(result.ledger_settings?.enabled).toBe(false);
		expect(result.ledger_settings?.ledger_records_endpoint).toContain('/submissions');
	});

	it('rejects malformed provider path policy payloads from the network', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve(formActionsBootstrapFixture({ providers: [], actions: null }))
		});

		await expect(
			client.getFormActionsBootstrap('gravity_forms', 42, { showNotifications: false })
		).rejects.toBeInstanceOf(ApiContractError);
	});

	it('evicts malformed cached provider policy before retrying the network', async () => {
		const path = 'gravity_forms/forms/42/actions/bootstrap';
		mockFetch
			.mockResolvedValueOnce(
				jsonResponse(formActionsBootstrapFixture({ providers: [], actions: null }))
			)
			.mockResolvedValueOnce(jsonResponse(formActionsBootstrapFixture()));

		await requestObject(path, {
			cacheTtlMs: 60_000,
			cacheTags: ['form-actions'],
			showNotifications: false
		});
		const result = await client.getFormActionsBootstrap('gravity_forms', 42, {
			showNotifications: false
		});

		expect(result.provider_path_policy).toBeUndefined();
		expect(mockFetch).toHaveBeenCalledTimes(2);
	});

	it('rejects malformed form actions bootstrap responses', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					form_source: 'gravity_forms',
					form_id: 42,
					actions: { map_1: { central_action_id: 'spam_detection_v1' } },
					execution_status: {
						status: 'success',
						message: null,
						entry_id: 99,
						last_error_code: null,
						last_result: null
					},
					disabled_state: {
						sf_disabled: false,
						global_disabled: false,
						provider_disabled: false,
						effective_disabled: false
					},
					generated_at: '2030-01-05T10:00:00Z'
				})
		});

		await expect(
			client.getFormActionsBootstrap('gravity_forms', 42, { showNotifications: false })
		).rejects.toThrow();
	});

	it('checks action compatibility without creating a mapping or provider request', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				form_source: 'contact_form_7',
				form_id: 42,
				action_code: 'clarification_assistant_v1',
				lifecycle: 'real_time',
				policy_decision: 'rejected',
				rejection_code: 'rest_unsupported_form_source_lifecycle',
				reason: 'Realtime lifecycle unavailable',
				request_trace_id: 'request-trace:123e4567-e89b-42d3-a456-426614174000',
				rejection_trace_id: 'source-rejection:123e4567-e89b-42d3-a456-426614174001',
				mapping_created: false,
				provider_request_executed: false,
				generated_at: '2030-01-05T10:00:00Z'
			})
		);

		const result = await client.checkActionCompatibility(
			'contact_form_7',
			42,
			{ action_code: 'clarification_assistant_v1', lifecycle: 'real_time' },
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}contact_form_7/forms/42/actions/compatibility?action_code=clarification_assistant_v1&lifecycle=real_time`,
			expect.objectContaining({ method: 'GET', credentials: 'same-origin' })
		);
		expect(result.policy_decision).toBe('rejected');
		expect(result.mapping_created).toBe(false);
		expect(result.provider_request_executed).toBe(false);
	});

	it('rejects malformed action compatibility evidence from the network', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				form_source: 'contact_form_7',
				form_id: 42,
				action_code: 'clarification_assistant_v1',
				lifecycle: 'real_time',
				policy_decision: 'rejected',
				rejection_code: 'rest_unsupported_form_source_lifecycle',
				reason: 'Realtime lifecycle unavailable',
				request_trace_id: 'invalid-trace',
				rejection_trace_id: null,
				mapping_created: false,
				provider_request_executed: false,
				generated_at: '2030-01-05T10:00:00Z'
			})
		);

		await expect(
			client.checkActionCompatibility(
				'contact_form_7',
				42,
				{ action_code: 'clarification_assistant_v1', lifecycle: 'real_time' },
				{ showNotifications: false }
			)
		).rejects.toBeInstanceOf(ApiContractError);
	});

	it('updates submission ledger settings through the form-scoped endpoint', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					form_source: 'gravity_forms',
					form_id: 42,
					enabled: true,
					enabled_at: '2030-01-05T10:00:00Z',
					enabled_by_user_id: 7,
					disabled_at: null,
					disabled_by_user_id: null,
					settings_source: 'sentient_submission_ledger_settings',
					ledger_records_endpoint: '/sentient-forms/v1/gravity_forms/forms/42/submissions',
					record_count: 0
				})
		});

		const result = await client.updateSubmissionLedgerSettings('gravity_forms', 42, true, {
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}gravity_forms/forms/42/ledger-settings`,
			expect.objectContaining({
				method: 'PUT',
				body: JSON.stringify({ enabled: true }),
				credentials: 'same-origin'
			})
		);
		expect(result.enabled).toBe(true);
	});

	it('keeps provider-native form identifiers opaque for submission ledger settings', async () => {
		const opaqueFormId = 'form alpha/2026#north%';
		const encodedFormId = encodeURIComponent(opaqueFormId);
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					form_source: 'opaque_forms',
					form_id: opaqueFormId,
					enabled: true,
					enabled_at: '2030-01-05T10:00:00Z',
					enabled_by_user_id: 7,
					disabled_at: null,
					disabled_by_user_id: null,
					settings_source: 'sentient_submission_ledger_settings',
					ledger_records_endpoint: `/sentient-forms/v1/opaque_forms/forms/${encodedFormId}/submissions`,
					record_count: 0
				})
		});

		const result = await client.updateSubmissionLedgerSettings('opaque_forms', opaqueFormId, true, {
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}opaque_forms/forms/${encodedFormId}/ledger-settings`,
			expect.objectContaining({
				method: 'PUT',
				body: JSON.stringify({ enabled: true }),
				credentials: 'same-origin'
			})
		);
		expect(result.form_id).toBe(opaqueFormId);
	});

	it('invalidates cached form-scoped ledger settings for encoded opaque IDs', async () => {
		const opaqueFormId = 'form alpha/2026#north%';
		const encodedFormId = encodeURIComponent(opaqueFormId);
		let settingsRequests = 0;

		mockFetch.mockImplementation((requestUrl, init) => {
			const url = String(requestUrl);
			const method = String(init?.method ?? 'GET').toUpperCase();

			if (url === `${baseUrl}opaque_forms/forms/${encodedFormId}/ledger-settings`) {
				if (method === 'GET') {
					settingsRequests += 1;
					return Promise.resolve(
						jsonResponse({
							form_source: 'opaque_forms',
							form_id: opaqueFormId,
							enabled: settingsRequests > 1,
							enabled_at: null,
							enabled_by_user_id: null,
							disabled_at: null,
							disabled_by_user_id: null,
							settings_source: 'sentient_submission_ledger_settings',
							ledger_records_endpoint: `/sentient-forms/v1/opaque_forms/forms/${encodedFormId}/submissions`,
							record_count: 0
						})
					);
				}

				if (method === 'PUT') {
					return Promise.resolve(
						jsonResponse({
							form_source: 'opaque_forms',
							form_id: opaqueFormId,
							enabled: true,
							enabled_at: '2030-01-05T10:00:00Z',
							enabled_by_user_id: 7,
							disabled_at: null,
							disabled_by_user_id: null,
							settings_source: 'sentient_submission_ledger_settings',
							ledger_records_endpoint: `/sentient-forms/v1/opaque_forms/forms/${encodedFormId}/submissions`,
							record_count: 0
						})
					);
				}
			}

			throw new Error(`Unexpected request: ${method} ${url}`);
		});

		const first = await client.getSubmissionLedgerSettings('opaque_forms', opaqueFormId, {
			showNotifications: false
		});
		await client.updateSubmissionLedgerSettings('opaque_forms', opaqueFormId, true, {
			showNotifications: false
		});
		const second = await client.getSubmissionLedgerSettings('opaque_forms', opaqueFormId, {
			showNotifications: false
		});

		expect(first.enabled).toBe(false);
		expect(second.enabled).toBe(true);
		expect(settingsRequests).toBe(2);
	});

	it('loads submission ledger records through the form-scoped endpoint', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					form_source: 'gravity_forms',
					form_id: 42,
					submissions: [
						{
							id: 11,
							submission_uuid: '123e4567-e89b-12d3-a456-426614174000',
							form_source: 'gravity_forms',
							form_id: 42,
							native_entry_id: '99',
							native_entry_url: 'https://example.test/entry/99',
							source_submitted_at: '2030-01-05T10:00:00Z',
							captured_at: '2030-01-05T10:00:01Z',
							logical_fields: { email: 'redacted' },
							provider_metadata: {},
							file_refs: [],
							redaction_summary: { redacted_fields: ['email'] },
							expires_at: null,
							detail_endpoint:
								'/sentient-forms/v1/gravity_forms/forms/42/submissions/123e4567-e89b-12d3-a456-426614174000'
						}
					],
					count: 1,
					per_page: 10,
					offset: 0
				})
		});

		const result = await client.getSubmissionLedgerRecords('gravity_forms', 42, {
			perPage: 10,
			offset: 0,
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}gravity_forms/forms/42/submissions?per_page=10&offset=0`,
			expect.objectContaining({ credentials: 'same-origin' })
		);
		expect(result.records[0]?.submission_uuid).toBe('123e4567-e89b-12d3-a456-426614174000');
		expect(result.total).toBe(1);
	});

	it('passes Submission Ledger search, filter, sort, and pagination params to the REST endpoint', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					form_source: 'gravity_forms',
					form_id: 42,
					submissions: [],
					total: 0,
					count: 0,
					per_page: 25,
					offset: 50
				})
		});

		await client.getSubmissionLedgerRecords('gravity_forms', 42, {
			q: 'needle prospect',
			nativeEntry: 'entry-77',
			capturedFrom: '2030-01-01T00:00:00Z',
			capturedTo: '2030-01-31T23:59:59Z',
			hasFiles: true,
			sort: 'captured_asc',
			perPage: 25,
			offset: 50,
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}gravity_forms/forms/42/submissions?per_page=25&offset=50&q=needle+prospect&native_entry=entry-77&captured_from=2030-01-01T00%3A00%3A00Z&captured_to=2030-01-31T23%3A59%3A59Z&has_files=true&sort=captured_asc`,
			expect.objectContaining({ credentials: 'same-origin' })
		);
	});

	it('normalizes nullable ledger JSON containers from PHP responses', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					form_source: 'gravity_forms',
					form_id: 42,
					records: [
						{
							id: 11,
							submission_uuid: '123e4567-e89b-12d3-a456-426614174000',
							form_source: 'gravity_forms',
							form_id: 42,
							native_entry_id: '99',
							native_entry_url: null,
							source_submitted_at: null,
							captured_at: '2030-01-05T10:00:01Z',
							logical_fields: { email: 'redacted' },
							provider_metadata: null,
							file_refs: null,
							redaction_summary: null,
							expires_at: null,
							detail_endpoint:
								'/sentient-forms/v1/gravity_forms/forms/42/submissions/123e4567-e89b-12d3-a456-426614174000'
						}
					],
					total: 1,
					per_page: 10,
					offset: 0
				})
		});

		const result = await client.getSubmissionLedgerRecords('gravity_forms', 42, {
			showNotifications: false
		});

		expect(result.records[0]?.provider_metadata).toEqual({});
		expect(result.records[0]?.file_refs).toEqual([]);
		expect(result.records[0]?.redaction_summary).toEqual({});
	});

	it('searches historical spam guidance entries through the spam-specific endpoint', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				form_source: 'gravity_forms',
				form_id: '42',
				availability: {
					source: 'native',
					native_read: true,
					ledger_read: false,
					unavailable_reason: null
				},
				entries: [
					{
						id: '99',
						source_type: 'native',
						date_created: '2030-01-05T10:00:00Z',
						status: 'spam',
						native_entry_id: '99',
						native_entry_url:
							'https://example.test/wp-admin/admin.php?page=gf_entries&id=42&lid=99',
						field_summary: [
							{ field_id: '1', label: 'Email', value: 'spam@example.test' },
							{ field_id: '2', label: 'Message', value: 'Buy crypto traffic now.' }
						]
					}
				]
			})
		);

		const result = await client.searchSpamGuidanceEntries('gravity_forms', 42, {
			q: 'crypto',
			limit: 5,
			status: 'spam',
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}spam-guidance/forms/gravity_forms/42/entries/search?q=crypto&limit=5&status=spam`,
			expect.objectContaining({ credentials: 'same-origin' })
		);
		expect(result.entries[0]?.status).toBe('spam');
		expect(result.entries[0]?.source_type).toBe('native');
		expect(result.availability.source).toBe('native');
	});

	it('keeps opaque Form Source IDs encoded for historical spam guidance search', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				form_source: 'elementor_pro_forms',
				form_id: '123:formabc',
				availability: {
					source: 'ledger',
					native_read: false,
					ledger_read: true,
					ledger_enabled: true,
					unavailable_reason: null,
					native_unavailable_reason: 'elementor_form_submissions_unavailable'
				},
				entries: []
			})
		);

		const result = await client.searchSpamGuidanceEntries('elementor_pro_forms', '123:formabc', {
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}spam-guidance/forms/elementor_pro_forms/123%3Aformabc/entries/search`,
			expect.objectContaining({ credentials: 'same-origin' })
		);
		expect(result.form_id).toBe('123:formabc');
		expect(result.availability.native_unavailable_reason).toBe(
			'elementor_form_submissions_unavailable'
		);
	});

	it('appends reviewed spam guidance examples and preserves provenance in the parsed config', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				target_scope: 'form',
				label: 'ham',
				config: {
					spam_positive_examples: [
						{
							text: 'Email: ada@example.test\nMessage: Please quote a repair.',
							rationale: 'Specific buyer request.',
							source: {
								kind: 'entry',
								form_source: 'gravity_forms',
								form_id: '42',
								entry_id: '99',
								native_entry_id: '99',
								selected_at: '2030-01-05T10:05:00Z',
								selected_by_user_id: 7
							}
						}
					],
					spam_negative_examples: []
				}
			})
		);

		const result = await client.appendSpamGuidanceExample('gravity_forms', 42, {
			target_scope: 'form',
			label: 'ham',
			entry_id: '99'
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}spam-guidance/forms/gravity_forms/42/examples`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					target_scope: 'form',
					label: 'ham',
					entry_id: '99'
				})
			})
		);
		expect(result.config.spam_positive_examples?.[0]?.source?.kind).toBe('entry');
		expect(result.config.spam_positive_examples?.[0]?.source?.selected_by_user_id).toBe(7);
	});

	it('rejects malformed spam guidance search responses', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				success: true,
				data: {
					form_source: 'gravity_forms',
					form_id: '42',
					availability: {
						source: 'native',
						native_read: 'yes'
					},
					entries: []
				}
			})
		);

		await expect(
			client.searchSpamGuidanceEntries('gravity_forms', 42, { showNotifications: false })
		).rejects.toThrow();
	});

	it('rejects malformed submission ledger settings responses', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				success: true,
				data: {
					form_source: 'gravity_forms',
					form_id: 42,
					enabled: 'yes',
					enabled_at: null,
					enabled_by_user_id: null,
					disabled_at: null,
					disabled_by_user_id: null,
					settings_source: 'sentient_submission_ledger_settings',
					ledger_records_endpoint: '/sentient-forms/v1/gravity_forms/forms/42/submissions'
				}
			})
		);

		await expect(
			client.getSubmissionLedgerSettings('gravity_forms', 42, { showNotifications: false })
		).rejects.toThrow();
	});

	it('rejects malformed submission ledger records responses', async () => {
		mockFetch.mockResolvedValue(
			jsonResponse({
				success: true,
				data: {
					form_source: 'gravity_forms',
					form_id: 42,
					records: {
						submission_uuid: 'not-an-array'
					},
					total: 1,
					per_page: 10,
					offset: 0
				}
			})
		);

		await expect(
			client.getSubmissionLedgerRecords('gravity_forms', 42, {
				perPage: 10,
				offset: 0,
				showNotifications: false
			})
		).rejects.toThrow();
	});

	it('loads a submission ledger detail record through the scoped endpoint', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					id: 11,
					submission_uuid: '123e4567-e89b-12d3-a456-426614174000',
					form_source: 'gravity_forms',
					form_id: 42,
					native_entry_id: '99',
					native_entry_url: 'https://example.test/entry/99',
					source_submitted_at: '2030-01-05T10:00:00Z',
					captured_at: '2030-01-05T10:00:01Z',
					logical_fields: { email: 'redacted' },
					provider_metadata: {},
					file_refs: [],
					redaction_summary: { redacted_fields: ['email'] },
					expires_at: null,
					detail_endpoint:
						'/sentient-forms/v1/gravity_forms/forms/42/submissions/123e4567-e89b-12d3-a456-426614174000'
				})
		});

		const result = await client.getSubmissionLedgerRecord(
			'gravity_forms',
			42,
			'123e4567-e89b-12d3-a456-426614174000',
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}gravity_forms/forms/42/submissions/123e4567-e89b-12d3-a456-426614174000`,
			expect.objectContaining({ credentials: 'same-origin' })
		);
		expect(result.native_entry_id).toBe('99');
	});

	it('invalidates cached form bootstrap when embedded custom actions change', async () => {
		const bootstrapResponse = (generatedAt: string) => ({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					form_source: 'gravity_forms',
					form_id: 42,
					actions: [],
					custom_actions: { actions: [], quota: null },
					execution_status: {
						status: 'success',
						message: null,
						entry_id: null,
						last_error_code: null,
						last_result: null
					},
					disabled_state: {
						sf_disabled: false,
						global_disabled: false,
						provider_disabled: false,
						effective_disabled: false
					},
					generated_at: generatedAt
				})
		});

		mockFetch
			.mockResolvedValueOnce(bootstrapResponse('before'))
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { id: 'custom-action-1' } })
			})
			.mockResolvedValueOnce(bootstrapResponse('after'));

		await expect(
			client.getFormActionsBootstrap('gravity_forms', 42, { showNotifications: false })
		).resolves.toMatchObject({ generated_at: 'before' });
		await expect(
			requestObject('local/custom-actions', {
				method: 'POST',
				body: { name: 'Custom action' },
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { id: 'custom-action-1' } });
		await expect(
			client.getFormActionsBootstrap('gravity_forms', 42, { showNotifications: false })
		).resolves.toMatchObject({ generated_at: 'after' });

		expect(mockFetch).toHaveBeenCalledTimes(3);
	});

	it('invalidates cached forms overview when embedded custom actions change', async () => {
		const overviewResponse = (generatedAt: string) => ({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					form_source: 'gravity_forms',
					forms: [],
					generated_at: generatedAt
				})
		});

		mockFetch
			.mockResolvedValueOnce(overviewResponse('before'))
			.mockResolvedValueOnce({
				ok: true,
				status: 200,
				headers: new Headers({ 'content-type': 'application/json' }),
				json: () => Promise.resolve({ success: true, data: { id: 'custom-action-1' } })
			})
			.mockResolvedValueOnce(overviewResponse('after'));

		await expect(
			client.getFormsOverview('gravity_forms', { showNotifications: false })
		).resolves.toMatchObject({ generated_at: 'before' });
		await expect(
			requestObject('local/custom-actions', {
				method: 'POST',
				body: { name: 'Custom action' },
				showNotifications: false
			})
		).resolves.toEqual({ success: true, data: { id: 'custom-action-1' } });
		await expect(
			client.getFormsOverview('gravity_forms', { showNotifications: false })
		).resolves.toMatchObject({ generated_at: 'after' });

		expect(mockFetch).toHaveBeenCalledTimes(3);
	});

	it('invalidates cached action payloads after provider mutations repair actions', async () => {
		const customActionsBefore = [localCustomActionFixture('openrouter/old')];
		const customActionsAfter = [localCustomActionFixture('sf_fast')];
		const bootstrapResponse = (generatedAt: string) => ({
			form_source: 'gravity_forms',
			form_id: 42,
			actions: [],
			custom_actions: { actions: [], quota: null },
			execution_status: {
				status: 'unknown',
				message: null,
				entry_id: null,
				last_error_code: null,
				last_result: null
			},
			disabled_state: {
				sf_disabled: false,
				global_disabled: false,
				provider_disabled: false,
				effective_disabled: false
			},
			generated_at: generatedAt
		});

		mockFetch
			.mockResolvedValueOnce(jsonResponse(customActionsBefore))
			.mockResolvedValueOnce(jsonResponse(bootstrapResponse('before')))
			.mockResolvedValueOnce(jsonResponse({ success: true, provider: 'openrouter' }))
			.mockResolvedValueOnce(jsonResponse(customActionsAfter))
			.mockResolvedValueOnce(jsonResponse(bootstrapResponse('after')));

		await expect(client.getLocalCustomActions('active')).resolves.toEqual(customActionsBefore);
		await expect(
			client.getFormActionsBootstrap('gravity_forms', 42, { showNotifications: false })
		).resolves.toMatchObject({ generated_at: 'before' });
		await expect(
			requestObject('local/providers/openrouter/constant', {
				method: 'POST',
				body: { constant_name: 'SENTIENT_OPENROUTER_KEY' },
				showNotifications: false
			})
		).resolves.toEqual({ success: true, provider: 'openrouter' });
		await expect(client.getLocalCustomActions('active')).resolves.toEqual(customActionsAfter);
		await expect(
			client.getFormActionsBootstrap('gravity_forms', 42, { showNotifications: false })
		).resolves.toMatchObject({ generated_at: 'after' });

		expect(mockFetch).toHaveBeenCalledTimes(5);
	});

	it('loads action defaults through the consolidated batch endpoint', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					defaults: {
						spam_detection_v1: {
							model_selection: { primary: 'sf_fast', is_preset: true }
						},
						entry_summary_v1: {}
					},
					generated_at: '2030-01-05T10:00:00Z'
				})
		});

		const result = await client.getActionDefaultsBatch([
			'spam_detection_v1',
			' entry_summary_v1 ',
			'spam_detection_v1'
		]);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}actions/defaults?ids=entry_summary_v1%2Cspam_detection_v1`,
			expect.objectContaining({ credentials: 'same-origin' })
		);
		expect(result.spam_detection_v1?.model_selection?.primary).toBe('sf_fast');
		expect(result.entry_summary_v1).toEqual({});
	});

	it('chunks action defaults batch requests to match the server limit', async () => {
		const requestedBatches: string[][] = [];
		mockFetch.mockImplementation((requestUrl) => {
			const url = new URL(String(requestUrl));
			const ids = (url.searchParams.get('ids') ?? '').split(',').filter(Boolean);
			requestedBatches.push(ids);

			return Promise.resolve(
				jsonResponse({
					defaults: Object.fromEntries(ids.map((id) => [id, { action_id: id }])),
					generated_at: '2030-01-05T10:00:00Z'
				})
			);
		});

		const actionIds = Array.from({ length: 105 }, (_, index) => {
			return `custom_action_${String(index).padStart(3, '0')}`;
		});
		const result = await client.getActionDefaultsBatch([...actionIds, ` ${actionIds[0]} `]);

		expect(mockFetch).toHaveBeenCalledTimes(2);
		expect(requestedBatches.map((batch) => batch.length)).toEqual([100, 5]);
		expect(requestedBatches[0]).toContain('custom_action_000');
		expect(requestedBatches[1]).toContain('custom_action_104');
		expect(Object.keys(result)).toHaveLength(105);
		expect(result.custom_action_104).toEqual({});
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
					status: 'active',
					license_key_masked: 'LIC-****',
					proxy_key_present: true,
					tier: { code: 'starter', display_name: 'Starter' },
					expires_at: '2026-01-01',
					last_synced: '2025-12-01',
					license_id: 'lic-1',
					site_id: 'site-1',
					site_url: 'https://site.test'
				})
		});

		const result = await client.activateLicense(payload, { showNotifications: false });

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}license/activate`,
			expect.objectContaining({
				method: 'POST'
			})
		);
		expect(result).toEqual({
			success: true,
			message: 'License activated successfully.',
			status: 'active',
			tier: 'starter',
			expiryDate: '2026-01-01',
			licenseId: 'lic-1',
			siteId: 'site-1'
		});
	});

	it('rejects empty activation responses instead of assuming success', async () => {
		mockFetch.mockResolvedValue(jsonResponse({}));

		await expect(
			client.activateLicense(
				{
					licenseKey: 'LIC-123',
					siteUrl: 'https://site.test',
					localSiteIdentifier: 'site-guid'
				},
				{ showNotifications: false }
			)
		).rejects.toBeInstanceOf(ApiContractError);
	});

	it('parses raw license info', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					license_key_masked: 'LIC-****',
					status: 'active',
					proxy_key_present: true,
					expires_at: '2026-01-01',
					last_synced: '2025-10-20 00:00:00',
					tier: 'starter',
					license_id: 'lic-1',
					site_id: 'site-1',
					site_url: 'https://site.test'
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

	it('invalidates and warms the canonical billing cache for forced server refreshes', async () => {
		const billingStateResponse = (status: string) => ({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					status,
					license_id: 'lic-1',
					site_id: 'site-1'
				})
		});

		mockFetch
			.mockResolvedValueOnce(billingStateResponse('before'))
			.mockResolvedValueOnce(billingStateResponse('forced'));

		await expect(client.getBillingState({ showNotifications: false })).resolves.toMatchObject({
			status: 'before'
		});
		await expect(
			client.getBillingState({ forceServerRefresh: true, showNotifications: false })
		).resolves.toMatchObject({ status: 'forced' });
		await expect(client.getBillingState({ showNotifications: false })).resolves.toMatchObject({
			status: 'forced'
		});

		expect(mockFetch).toHaveBeenCalledTimes(2);
		expect(mockFetch).toHaveBeenNthCalledWith(
			1,
			`${baseUrl}license/billing-state`,
			expect.objectContaining({ credentials: 'same-origin' })
		);
		expect(mockFetch).toHaveBeenNthCalledWith(
			2,
			`${baseUrl}license/billing-state?force_refresh=1`,
			expect.objectContaining({ credentials: 'same-origin' })
		);
	});

	it('does not join an in-flight normal billing request when forcing a server refresh', async () => {
		const billingStateResponse = (status: string) => ({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					status,
					license_id: 'lic-1',
					site_id: 'site-1'
				})
		});
		let resolveNormal!: (response: ReturnType<typeof billingStateResponse>) => void;
		let resolveForced!: (response: ReturnType<typeof billingStateResponse>) => void;

		mockFetch
			.mockReturnValueOnce(
				new Promise((resolve) => {
					resolveNormal = resolve;
				})
			)
			.mockReturnValueOnce(
				new Promise((resolve) => {
					resolveForced = resolve;
				})
			);

		const normalRequest = client.getBillingState({ showNotifications: false });
		const forcedRequest = client.getBillingState({
			forceServerRefresh: true,
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledTimes(2);
		expect(mockFetch).toHaveBeenNthCalledWith(
			1,
			`${baseUrl}license/billing-state`,
			expect.objectContaining({ credentials: 'same-origin' })
		);
		expect(mockFetch).toHaveBeenNthCalledWith(
			2,
			`${baseUrl}license/billing-state?force_refresh=1`,
			expect.objectContaining({ credentials: 'same-origin' })
		);

		resolveNormal(billingStateResponse('normal'));
		resolveForced(billingStateResponse('forced'));

		const [normal, forced] = await Promise.all([normalRequest, forcedRequest]);
		expect(normal.status).toBe('normal');
		expect(forced.status).toBe('forced');
	});

	it('sends a proper form disable payload when toggling per-form active state', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					sf_disabled: true,
					message: 'Sentient Forms disabled for this form.'
				})
		});

		const result = await client.toggleFormDisabled('gravity_forms', 42, true, {
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}gravity_forms/forms/42/actions/disable`,
			expect.objectContaining({
				method: 'PUT',
				body: JSON.stringify({ sf_disabled: true })
			})
		);
		expect(result).toEqual({
			sf_disabled: true,
			message: 'Sentient Forms disabled for this form.'
		});
	});

	it('reads local provider credentials from the local-first endpoint', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve([
					{
						id: 7,
						provider: 'openrouter',
						label: 'OpenRouter key',
						auth_mode: 'manual_key',
						constant_name: null,
						status: 'valid',
						status_json: { is_free_tier: true },
						last_validated_at: '2026-04-17T10:00:00Z',
						created_at: '2026-04-17T09:00:00Z',
						updated_at: '2026-04-17T10:00:00Z',
						secret_configured: true
					}
				])
		});

		const result = await client.getLocalProviderCredentials({ showNotifications: false });

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/credentials`,
			expect.objectContaining({
				credentials: 'same-origin'
			})
		);
		expect(result).toHaveLength(1);
		expect(result[0]).toMatchObject({
			provider: 'openrouter',
			status: 'valid',
			secret_configured: true
		});
	});

	it('deletes a saved local provider credential', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					deleted: true,
					credential: {
						id: 7,
						provider: 'openrouter',
						label: 'OpenRouter key',
						auth_mode: 'manual_key',
						constant_name: null,
						status: 'valid',
						status_json: { is_free_tier: true },
						last_validated_at: '2026-04-17T10:00:00Z',
						created_at: '2026-04-17T09:00:00Z',
						updated_at: '2026-04-17T10:00:00Z',
						secret_configured: true
					}
				})
		});

		const result = await client.deleteLocalProviderCredential(7, { showNotifications: false });

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/credentials/7`,
			expect.objectContaining({
				method: 'DELETE',
				credentials: 'same-origin'
			})
		);
		expect(result).toMatchObject({
			deleted: true,
			credential: {
				id: 7,
				provider: 'openrouter',
				secret_configured: true
			}
		});
	});

	it('validates an OpenRouter key with disclosure acceptance', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					provider: 'openrouter',
					status: 'valid',
					credential_id: 9,
					key_status: { label: 'test key', is_free_tier: true },
					consent_recorded: true,
					consent_id: 11
				})
		});

		const result = await client.validateOpenRouterKey(
			{
				api_key: 'sk-or-test',
				label: 'Test key',
				save: true,
				disclosure_version: '2026-04-local-first-openrouter-v1',
				accepted_external_service_terms: true
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/openrouter/validate`,
			expect.objectContaining({ method: 'POST' })
		);
		expect(JSON.parse(String(mockFetch.mock.calls.at(-1)?.[1]?.body))).toEqual({
			api_key: 'sk-or-test',
			label: 'Test key',
			save: true,
			disclosure_version: '2026-04-local-first-openrouter-v1',
			accepted_external_service_terms: true
		});
		expect(result).toMatchObject({
			status: 'valid',
			credential_id: 9,
			consent_recorded: true
		});
	});

	it('validates an OpenRouter server secret reference with disclosure acceptance', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					provider: 'openrouter',
					status: 'valid',
					credential_id: 12,
					key_status: { label: 'server secret', is_free_tier: true },
					consent_recorded: true,
					consent_id: 18,
					auth_mode: 'constant',
					constant_name: 'SENTIENT_FORMS_OPENROUTER_KEY'
				})
		});

		const result = await client.saveOpenRouterConstant(
			{
				constant_name: 'SENTIENT_FORMS_OPENROUTER_KEY',
				label: 'OpenRouter server secret',
				disclosure_version: '2026-04-local-first-openrouter-v1',
				accepted_external_service_terms: true
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/openrouter/constant`,
			expect.objectContaining({ method: 'POST' })
		);
		expect(JSON.parse(String(mockFetch.mock.calls.at(-1)?.[1]?.body))).toEqual({
			constant_name: 'SENTIENT_FORMS_OPENROUTER_KEY',
			label: 'OpenRouter server secret',
			disclosure_version: '2026-04-local-first-openrouter-v1',
			accepted_external_service_terms: true
		});
		expect(result).toMatchObject({
			status: 'valid',
			credential_id: 12,
			auth_mode: 'constant',
			constant_name: 'SENTIENT_FORMS_OPENROUTER_KEY'
		});
	});

	it('sets up the Sentient Forms managed-service credential with disclosure acceptance', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					provider: 'sentient_managed',
					status: 'valid',
					credential_id: 77,
					credential: {
						id: 77,
						provider: 'sentient_managed',
						label: 'Sentient Forms managed service',
						auth_mode: 'sentient_proxy',
						constant_name: null,
						status: 'valid',
						status_json: { proxy_key_present: true },
						last_validated_at: '2026-04-19T10:00:00Z',
						created_at: '2026-04-19T09:00:00Z',
						updated_at: '2026-04-19T10:00:00Z',
						secret_configured: true
					},
					consent_recorded: true,
					consent_id: 31,
					account: {
						status: 'active',
						license_id: 'license-managed-test',
						site_id: 'site-managed-test',
						local_site_identifier: 'local-managed-test',
						proxy_key_present: true,
						credential_ready: true
					},
					billing_boundary: {
						direct_openrouter_billed_by_sentient: false,
						managed_proxy_billed_by_sentient: true
					}
				})
		});

		const result = await client.setupSentientManagedProvider(
			{
				label: 'Sentient Forms managed service',
				disclosure_version: '2026-04-sentient-managed-proxy-v1',
				accepted_external_service_terms: true
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/sentient-managed/setup`,
			expect.objectContaining({ method: 'POST' })
		);
		expect(JSON.parse(String(mockFetch.mock.calls.at(-1)?.[1]?.body))).toEqual({
			label: 'Sentient Forms managed service',
			disclosure_version: '2026-04-sentient-managed-proxy-v1',
			accepted_external_service_terms: true
		});
		expect(result).toMatchObject({
			provider: 'sentient_managed',
			status: 'valid',
			credential_id: 77,
			consent_recorded: true,
			billing_boundary: {
				direct_openrouter_billed_by_sentient: false,
				managed_proxy_billed_by_sentient: true
			}
		});
	});

	it('starts managed checkout through the first-time license route', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					checkout_intent_id: 'mci_123',
					checkout_session_id: 'cs_test_123',
					checkout_url: 'https://checkout.stripe.com/c/pay/cs_test_123',
					plan_code: 'starter',
					consent_recorded: true
				})
		});

		const result = await client.startManagedCheckout(
			{
				plan_code: 'starter',
				success_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
				cancel_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
				disclosure_version: 'managed-service-v1',
				accepted_managed_service_terms: true
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}license/managed-checkout/start`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					plan_code: 'starter',
					success_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
					cancel_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
					disclosure_version: 'managed-service-v1',
					accepted_managed_service_terms: true
				})
			})
		);
		expect(result).toMatchObject({
			checkout_intent_id: 'mci_123',
			checkout_url: 'https://checkout.stripe.com/c/pay/cs_test_123',
			consent_recorded: true
		});
	});

	it.each([
		[
			'missing checkout URL',
			{
				checkout_intent_id: 'mci_123',
				checkout_session_id: 'cs_test_123',
				plan_code: 'starter'
			}
		],
		[
			'non-string checkout URL',
			{
				checkout_intent_id: 'mci_123',
				checkout_session_id: 'cs_test_123',
				checkout_url: 42,
				plan_code: 'starter'
			}
		],
		[
			'non-HTTPS checkout URL',
			{
				checkout_intent_id: 'mci_123',
				checkout_session_id: 'cs_test_123',
				checkout_url: 'http://checkout.stripe.test/c/pay/cs_test_123',
				plan_code: 'starter'
			}
		]
	])('rejects managed checkout response with %s', async (_label, data) => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve(data)
		});

		await expect(
			client.startManagedCheckout(
				{
					plan_code: 'starter',
					success_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
					cancel_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
					disclosure_version: 'managed-service-v1',
					accepted_managed_service_terms: true
				},
				{ showNotifications: false }
			)
		).rejects.toThrow();
	});

	it('rejects legacy billing checkout responses with non-HTTPS redirect URLs', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					session_id: 'cs_legacy_123',
					checkout_url: 'http://checkout.stripe.test/c/pay/cs_legacy_123',
					customer_id: 'cus_123',
					subscription_id: null
				})
		});

		await expect(
			client.createCheckoutSession(
				{
					plan_code: 'starter',
					success_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
					cancel_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing'
				},
				{ showNotifications: false }
			)
		).rejects.toThrow();
	});

	it('rejects billing portal responses with non-HTTPS redirect URLs', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					session_id: 'bps_123',
					portal_url: 'http://billing.stripe.test/p/session/bps_123',
					customer_id: 'cus_123'
				})
		});

		await expect(
			client.createPortalSession(
				{
					return_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing'
				},
				{ showNotifications: false }
			)
		).rejects.toThrow();
	});

	it('rejects top-up checkout responses with non-HTTPS redirect URLs', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					session_id: 'cs_top_up_123',
					checkout_url: 'http://checkout.stripe.test/c/pay/cs_top_up_123',
					customer_id: 'cus_123',
					top_up_credits: 1000,
					pack_code: 'top_up_small'
				})
		});

		await expect(
			client.createTopUpCheckoutSession(
				{
					pack_code: 'top_up_small',
					success_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
					cancel_url: 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing'
				},
				{ showNotifications: false }
			)
		).rejects.toThrow();
	});

	it('completes managed checkout and unwraps activation metadata', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					activation_ready: true,
					license_id: 'lic-managed-123',
					site_id: 'site-managed-456',
					proxy_api_key: 'proxy-issued',
					credential_id: 88,
					managed_provider_ready: true
				})
		});

		const result = await client.completeManagedCheckout(
			{
				checkout_intent_id: 'mci_123',
				checkout_session_id: 'cs_test_123',
				activation_token: 'token-123'
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}license/managed-checkout/complete`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					checkout_intent_id: 'mci_123',
					checkout_session_id: 'cs_test_123',
					activation_token: 'token-123'
				})
			})
		);
		expect(result).toMatchObject({
			activation_ready: true,
			proxy_api_key: 'proxy-issued',
			credential_id: 88,
			managed_provider_ready: true
		});
	});

	it('reads cached OpenRouter model metadata from the local provider endpoint', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					provider: 'openrouter',
					source: 'local_cache',
					total_cached: 2,
					total_returned: 1,
					free_count: 1,
					stale_count: 0,
					models: [
						{
							id: 'openai/gpt-oss-20b:free',
							name: 'OpenAI: GPT OSS 20B (free)',
							free: true,
							context_length: 131072,
							input_modalities: ['text'],
							output_modalities: ['text'],
							supported_parameters: ['response_format'],
							pricing: { prompt: '0', completion: '0', request: '0' },
							fetched_at: '2026-04-18 12:00:00',
							expires_at: '2026-04-19 12:00:00',
							stale: false
						}
					]
				})
		});

		const result = await client.getOpenRouterModels(
			{ freeOnly: true, limit: 25 },
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/openrouter/models?free_only=true&limit=25`,
			expect.objectContaining({
				credentials: 'same-origin'
			})
		);
		expect(result.free_count).toBe(1);
		expect(result.models[0].id).toBe('openai/gpt-oss-20b:free');
	});

	it('rejects malformed OpenRouter model responses', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					provider: 'openrouter',
					source: 'local_cache',
					total_cached: 2,
					total_returned: 1,
					free_count: 1,
					stale_count: 0,
					models: {
						id: 'openai/gpt-oss-20b:free'
					}
				})
		});

		await expect(
			client.getOpenRouterModels({ freeOnly: true, limit: 25 }, { showNotifications: false })
		).rejects.toThrow();
	});

	it('refreshes OpenRouter model metadata with disclosure acceptance', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					provider: 'openrouter',
					source: 'local_cache',
					total_cached: 2,
					total_returned: 2,
					free_count: 1,
					stale_count: 0,
					models: [],
					consent_recorded: true,
					consent_id: 12,
					stored: 2
				})
		});

		const result = await client.refreshOpenRouterModels(
			{
				disclosure_version: '2026-04-local-first-openrouter-v1',
				accepted_external_service_terms: true,
				output_modalities: 'text'
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/openrouter/models/refresh`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					disclosure_version: '2026-04-local-first-openrouter-v1',
					accepted_external_service_terms: true,
					output_modalities: 'text'
				})
			})
		);
		expect(result).toMatchObject({
			consent_recorded: true,
			stored: 2
		});
	});

	it('announces expired WordPress sessions without emitting generic request errors', async () => {
		const errors = vi.spyOn(notifications, 'error');
		const sessionHandler = vi.fn();
		window.addEventListener(SESSION_EXPIRED_EVENT, sessionHandler);

		mockFetch.mockResolvedValue({
			ok: false,
			status: 403,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					code: 'rest_cookie_invalid_nonce',
					message: 'Cookie check failed'
				})
		});

		await expect(client.getLicenseInfo({ showNotifications: true })).rejects.toBeInstanceOf(
			ApiClientError
		);

		expect(sessionHandler).toHaveBeenCalledTimes(1);
		expect(errors).toHaveBeenCalledTimes(1);
		expect(errors).toHaveBeenCalledWith(
			'WordPress session expired. Reload this admin page before retrying.',
			0
		);

		window.removeEventListener(SESSION_EXPIRED_EVENT, sessionHandler);
	});

	it('surfaces probable Cloudflare rate limits without treating them as WordPress sessions', async () => {
		const errors = vi.spyOn(notifications, 'error');
		const sessionHandler = vi.fn();
		const securityHandler = vi.fn();
		window.addEventListener(SESSION_EXPIRED_EVENT, sessionHandler);
		window.addEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);

		mockFetch.mockResolvedValue({
			ok: false,
			status: 429,
			headers: new Headers({
				'content-type': 'text/html',
				server: 'cloudflare',
				'cf-ray': 'rate-limit-ray'
			}),
			text: () =>
				Promise.resolve('<html><h1>Error 1015</h1><p>You are being rate limited</p></html>')
		});

		await expect(client.getSettings({ showNotifications: true })).rejects.toMatchObject({
			code: 'security_roadblock_interrupted_request',
			status: 429,
			message: 'This request reached a site security layer before WordPress could process it.'
		});

		expect(securityHandler).toHaveBeenCalledTimes(1);
		expect(securityHandler.mock.calls[0]?.[0].detail).toMatchObject({
			label: 'Cloudflare rate limit suspected',
			kind: 'cloudflare_rate_limit',
			confidence: 'suspected',
			rayId: 'rate-limit-ray'
		});
		expect(sessionHandler).not.toHaveBeenCalled();
		expect(errors).toHaveBeenCalledWith(
			'Cloudflare rate limit suspected: This request reached a site security layer before WordPress could process it.'
		);

		window.removeEventListener(SESSION_EXPIRED_EVENT, sessionHandler);
		window.removeEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);
	});

	it('keeps Cloudflare-proxied origin rate limits on the normal API error path', async () => {
		const errors = vi.spyOn(notifications, 'error');
		const securityHandler = vi.fn();
		window.addEventListener(SECURITY_ROADBLOCK_EVENT, securityHandler);

		mockFetch.mockResolvedValue({
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
		});

		await expect(client.getSettings({ showNotifications: true })).rejects.toMatchObject({
			message: 'Request failed',
			status: 429,
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

	it('creates a local custom action in WordPress-local tables', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 201,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					id: 17,
					external_id: null,
					template_id: null,
					code: 'local_openrouter_summary',
					display_name: 'Local OpenRouter summary',
					definition_json: { prompt_template: 'Summarize {{name}}.' },
					model_selection_json: {
						provider: 'openrouter',
						model: 'openrouter/auto',
						credential_id: 9
					},
					status: 'active',
					created_at: '2026-04-17T10:00:00Z',
					updated_at: '2026-04-17T10:00:00Z'
				})
		});

		const result = await client.createLocalCustomAction(
			{
				code: 'local_openrouter_summary',
				display_name: 'Local OpenRouter summary',
				definition_json: { prompt_template: 'Summarize {{name}}.' },
				model_selection_json: {
					provider: 'openrouter',
					model: 'openrouter/auto',
					credential_id: 9
				},
				status: 'active'
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/custom-actions`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					code: 'local_openrouter_summary',
					display_name: 'Local OpenRouter summary',
					definition_json: { prompt_template: 'Summarize {{name}}.' },
					model_selection_json: {
						provider: 'openrouter',
						model: 'openrouter/auto',
						credential_id: 9
					},
					status: 'active'
				})
			})
		);
		expect(result).toMatchObject({
			id: 17,
			code: 'local_openrouter_summary',
			status: 'active'
		});
	});

	it('creates a local form mapping in WordPress-local tables', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 201,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					id: 23,
					external_id: null,
					form_source: 'gravity_forms',
					form_id: '42',
					hook: 'gform_after_submission',
					action_kind: 'custom_action',
					action_id: 17,
					conditions_json: null,
					input_bindings_json: { name: '1', email: '2' },
					execution_mode: 'sync',
					effect_mapping_json: {
						store_result: true,
						meta: { sentient_forms_summary: 'structured.summary' }
					},
					enabled: true,
					created_at: '2026-04-17T10:00:00Z',
					updated_at: '2026-04-17T10:00:00Z'
				})
		});

		const result = await client.createLocalFormMapping(
			{
				form_source: 'gravity_forms',
				form_id: 42,
				hook: 'gform_after_submission',
				action_kind: 'custom_action',
				action_id: 17,
				input_bindings_json: { name: '1', email: '2' },
				execution_mode: 'sync',
				effect_mapping_json: {
					store_result: true,
					meta: { sentient_forms_summary: 'structured.summary' }
				},
				enabled: true
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/form-mappings`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					form_source: 'gravity_forms',
					form_id: 42,
					hook: 'gform_after_submission',
					action_kind: 'custom_action',
					action_id: 17,
					input_bindings_json: { name: '1', email: '2' },
					execution_mode: 'sync',
					effect_mapping_json: {
						store_result: true,
						meta: { sentient_forms_summary: 'structured.summary' }
					},
					enabled: true
				})
			})
		);
		expect(result).toMatchObject({
			id: 23,
			form_source: 'gravity_forms',
			action_id: 17,
			enabled: true
		});
	});

	it('previews a local migration import bundle', async () => {
		const bundle = {
			schema_version: 'sentient_forms_cps_export_v1',
			action_templates: []
		};
		mockFetch.mockResolvedValue({
			ok: true,
			status: 201,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					run_id: 31,
					status: 'dry_run_complete',
					dry_run: true,
					report: {
						schema_version: 'sentient_forms_cps_export_v1',
						source: 'cps_export',
						source_version: 'cps-dev-export-1',
						generated_at: '2026-04-19T21:00:00+00:00',
						exported_at: '2026-04-19T20:00:00+00:00',
						ready_to_import: true,
						counts: { action_templates: 0 },
						changes: { total_writes: 0 },
						conflicts: [],
						warnings: [],
						mapping: {}
					}
				})
		});

		const result = await client.createLocalMigrationImportDryRun(
			{ bundle },
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/migration/import/dry-run`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({ bundle })
			})
		);
		expect(result).toMatchObject({
			run_id: 31,
			dry_run: true
		});
	});

	it('applies a local migration import bundle', async () => {
		const bundle = {
			schema_version: 'sentient_forms_cps_export_v1',
			action_templates: []
		};
		mockFetch.mockResolvedValue({
			ok: true,
			status: 201,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					run_id: 32,
					status: 'completed',
					dry_run: false,
					report: {
						schema_version: 'sentient_forms_cps_export_v1',
						source: 'cps_export',
						source_version: 'cps-dev-export-1',
						generated_at: '2026-04-19T21:00:00+00:00',
						exported_at: '2026-04-19T20:00:00+00:00',
						ready_to_import: true,
						counts: { action_templates: 0 },
						changes: { total_writes: 0 },
						conflicts: [],
						warnings: [],
						mapping: {}
					},
					applied: { total: 0 }
				})
		});

		const result = await client.runLocalMigrationImportApply(
			{ bundle },
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/migration/import/apply`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({ bundle })
			})
		);
		expect(result).toMatchObject({
			run_id: 32,
			dry_run: false,
			applied: { total: 0 }
		});
	});

	it('rejects malformed local migration import dry-run responses', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 201,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					run_id: 31,
					status: 'dry_run_complete',
					dry_run: 'true',
					report: {
						schema_version: 'sentient_forms_cps_export_v1',
						source: 'cps_export',
						source_version: 'cps-dev-export-1',
						generated_at: '2026-04-19T21:00:00+00:00',
						exported_at: '2026-04-19T20:00:00+00:00',
						ready_to_import: true,
						counts: { action_templates: 0 },
						changes: { total_writes: 0 },
						conflicts: [],
						warnings: [],
						mapping: {}
					}
				})
		});

		await expect(
			client.createLocalMigrationImportDryRun(
				{ bundle: { schema_version: 'sentient_forms_cps_export_v1' } },
				{ showNotifications: false }
			)
		).rejects.toThrow();
	});

	it('rejects malformed local migration import apply responses', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 201,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					run_id: 32,
					status: 'completed',
					dry_run: false,
					report: {
						schema_version: 'sentient_forms_cps_export_v1',
						source: 'cps_export',
						source_version: 'cps-dev-export-1',
						generated_at: '2026-04-19T21:00:00+00:00',
						exported_at: '2026-04-19T20:00:00+00:00',
						ready_to_import: true,
						counts: { action_templates: 0 },
						changes: { total_writes: 0 },
						conflicts: [],
						warnings: [],
						mapping: {}
					},
					applied: { total: '0' }
				})
		});

		await expect(
			client.runLocalMigrationImportApply(
				{ bundle: { schema_version: 'sentient_forms_cps_export_v1' } },
				{ showNotifications: false }
			)
		).rejects.toThrow();
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

	it('accepts valid JSON literal responses', async () => {
		mockFetch.mockResolvedValue(
			new Response('null', {
				status: 200,
				headers: { 'content-type': 'application/json' }
			})
		);

		await expect(
			client.requestParsed('site-context', z.null(), { showNotifications: false })
		).resolves.toBeNull();
	});

	it('surfaces contaminated JSON responses with diagnostic payload', async () => {
		mockFetch.mockResolvedValue(
			new Response('\uFEFF\uFEFF{"success":true,"data":{"saved":true}}', {
				status: 200,
				headers: { 'content-type': 'application/json' }
			})
		);

		await expect(client.getSettings({ showNotifications: false })).rejects.toMatchObject({
			code: 'invalid_json_response',
			status: 200,
			payload: {
				code: 'invalid_json_response',
				error_code: 'invalid_json_response',
				content_type: 'application/json',
				body_prefix: expect.stringContaining('<BOM>'),
				url: `${baseUrl}settings`
			}
		});
	});

	it('coerces unknown rejection into ApiClientError', async () => {
		mockFetch.mockRejectedValue(new Error('Network down'));

		await expect(client.getLicenseInfo({ showNotifications: false })).rejects.toBeInstanceOf(
			ApiClientError
		);
	});
});
