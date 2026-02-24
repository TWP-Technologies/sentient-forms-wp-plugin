import { expect, test } from '@playwright/test';
import { seedRuntimeConfig } from './utils/runtime-config';

test.describe('Dashboard and Licensing hierarchy uplift', () => {
	test.beforeEach(async ({ page }) => {
		const previewHost = 'http://127.0.0.1:4175';
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});
	});

	test('dashboard emphasizes health and credits in the overview card', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/license**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'active',
					license_key_masked: 'LIC-****-****-1234',
					proxy_key_present: true,
					tier: 'pro',
					expires_at: '2030-01-01T00:00:00Z',
					last_synced: '2030-01-05T10:00:00Z',
					license_id: 'lic-1',
					site_id: 'site-1',
					site_url: 'https://example.test'
				})
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					current_balance: 875,
					tier: {
						code: 'pro',
						display_name: 'Pro',
						monthly_credit_quota: 1000
					}
				})
			})
		);

		await page.goto('/#/dashboard', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
		await expect(page.getByTestId('dashboard-overview-card')).toBeVisible();
		await expect(page.getByTestId('dashboard-license-summary')).toContainText('License active');
		await expect(page.getByTestId('dashboard-license-status')).toContainText('active');
		await expect(page.getByTestId('dashboard-credits-headline')).toContainText('875 / 1000 credits remaining');
		await expect(page.getByTestId('dashboard-credits-severity')).toContainText('Healthy');
		await expect(page.getByTestId('dashboard-reset-summary')).toContainText('Resets');
	});

	test('dashboard surfaces exhausted credits urgency in overview copy', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/license**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'active',
					license_key_masked: 'LIC-****-****-1234',
					proxy_key_present: true,
					tier: 'starter',
					expires_at: '2030-01-01T00:00:00Z',
					last_synced: '2030-01-05T10:00:00Z',
					license_id: 'lic-1',
					site_id: 'site-1',
					site_url: 'https://example.test'
				})
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					current_balance: 0,
					tier: {
						code: 'starter',
						display_name: 'Starter',
						monthly_credit_quota: 100
					}
				})
			})
		);

		await page.goto('/#/dashboard', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('dashboard-credits-headline')).toContainText('No credits remaining');
		await expect(page.getByTestId('dashboard-credits-severity')).toContainText('Exhausted');
		await expect(page.getByTestId('dashboard-credits-detail')).toContainText('Actions may pause');
	});

	test('dashboard shows shared error state when both dashboard requests fail', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/license**', (route) =>
			route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ success: false, message: 'license unavailable' })
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
			route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ success: false, message: 'credits unavailable' })
			})
		);

		await page.goto('/#/dashboard', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('dashboard-error-state')).toBeVisible();
		await expect(page.getByTestId('dashboard-error-state')).toContainText(
			'Unable to refresh license and credit details right now.'
		);
	});

	test('licensing active screen leads with status, tier, credits, and reset timing', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/license**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'active',
					license_key_masked: 'LIC-****-****-1234',
					proxy_key_present: true,
					tier: 'starter',
					expires_at: '2030-01-01T00:00:00Z',
					last_synced: '2030-01-05T10:00:00Z',
					license_id: 'lic-1',
					site_id: 'site-1',
					site_url: 'https://example.test'
				})
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					current_balance: 8,
					tier: {
						code: 'starter',
						display_name: 'Starter',
						monthly_credit_quota: 100
					}
				})
			})
		);

		await page.goto('/#/licensing', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'License management' })).toBeVisible();
		await expect(page.getByTestId('licensing-overview-card')).toBeVisible();
		await expect(page.getByTestId('licensing-status-badge')).toContainText('active');
		await expect(page.getByTestId('licensing-credits-headline')).toContainText('Low credits: 8 / 100');
		await expect(page.getByTestId('licensing-credit-severity')).toContainText('Low');
		await expect(page.getByTestId('licensing-reset-summary')).toContainText('Resets');
		await expect(page.getByTestId('licensing-details-status')).toContainText('active');
		await expect(page.getByText('Tier: Starter')).toBeVisible();
	});

	test('licensing inactive flow still presents activation form', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/license**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'inactive',
					license_key_masked: '',
					proxy_key_present: false,
					tier: null,
					expires_at: null,
					last_synced: null,
					license_id: null,
					site_id: null,
					site_url: 'https://example.test'
				})
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					current_balance: 0,
					tier: null
				})
			})
		);

		await page.goto('/#/licensing', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'License activation' })).toBeVisible();
		await expect(page.getByLabel('License key')).toBeVisible();
		await expect(page.getByRole('button', { name: 'Activate', exact: true })).toBeVisible();
	});
});
