import { expect, test } from '@playwright/test';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';
import { requireWpRestHealthy } from './utils/wp-e2e-helpers';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('WordPress telemetry settings', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin telemetry flows.');
	test('toggles consent via wp-admin settings route', async ({ page }) => {
		let optIn = false;

		await page.route('**/*', async (route, request) => {
			const url = request.url();
			const isTelemetryEndpoint =
				url.includes('/wp-json/sentient-forms/v1/telemetry') ||
				url.includes('rest_route=/sentient-forms/v1/telemetry') ||
				url.includes('rest_route=%2Fsentient-forms%2Fv1%2Ftelemetry') ||
				/\/telemetry(?:\?|$)/.test(url);
			if (!isTelemetryEndpoint) {
				return route.continue();
			}

			const method = request.method().toUpperCase();

			if (method === 'GET') {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							telemetry_opt_in: optIn,
							updated_at: null,
							synced_at: null,
							remote_updated_at: null,
							last_error: null
						}
					})
				});
			}

			if (method === 'PUT') {
				const payload = (request.postDataJSON() as { telemetry_opt_in?: boolean }) ?? {};
				optIn = Boolean(payload.telemetry_opt_in);
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							telemetry_opt_in: optIn,
							updated_at: '2025-11-15T12:00:00Z',
							synced_at: '2025-11-15T12:00:02Z',
							remote_updated_at: '2025-11-15T12:00:00Z',
							last_error: null
						}
					})
				});
			}

			return route.continue();
		});

		await loginToWpAdmin(page);
	await requireWpRestHealthy(page);
		await ensureSentientFormsSpa(page, '/settings');
		const spaRoot = page.locator('#sentient-forms-admin-app');
		await expect(page.getByRole('heading', { name: /Telemetry/ })).toBeVisible();

		const toggle = page.locator('input[type="checkbox"]').first();
		await expect(toggle).not.toBeChecked();
		await toggle.click();

		await expect(toggle).toBeChecked();
		await expect(spaRoot.getByText('On', { exact: true })).toBeVisible();
		await expect(spaRoot.getByText('Synced 2025-11-15T12:00:02Z')).toBeVisible();
		await expect(spaRoot.getByText('Recorded by CPS 2025-11-15T12:00:00Z')).toBeVisible();
	});
});
