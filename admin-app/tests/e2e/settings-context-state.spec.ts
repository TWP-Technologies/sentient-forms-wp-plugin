import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';

test.describe('Settings context state templates', () => {
	test.beforeEach(async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});
	});

	test('shows empty template when no context is configured', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/site-context**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(null)
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ current_balance: 120, tier: null })
			})
		);

		await page.goto('/#/settings/context', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Site Context' })).toBeVisible();
		await expect(page.getByTestId('site-context-empty-state')).toBeVisible();
	});

	test('shows error template and recovers on retry', async ({ page }) => {
		let attempts = 0;

		await page.route('**/wp-json/sentient-forms/v1/site-context**', (route) => {
			attempts += 1;
			if (attempts === 1) {
				return route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({ success: false, message: 'Failed to load context' })
				});
			}

			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					id: 'ctx-1',
					license_id: 'lic-1',
					summary_text: 'Context text',
					source: 'manual',
					auto_include: true,
					pii_ack: true,
					free_refresh_available: true,
					next_free_refresh_at: null,
					created_at: '2026-02-24T00:00:00Z',
					updated_at: '2026-02-24T00:00:00Z'
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ current_balance: 120, tier: null })
			})
		);

		await page.goto('/#/settings/context', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('site-context-error-state')).toBeVisible();
		await page.getByTestId('site-context-error-state').getByRole('button', { name: 'Retry' }).click();

		await expect(page.getByTestId('site-context-error-state')).toHaveCount(0);
		await expect(page.getByText('Site Context Summary')).toBeVisible();
		expect(attempts).toBeGreaterThanOrEqual(2);
	});
});
