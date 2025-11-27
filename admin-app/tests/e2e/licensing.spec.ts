import { expect, test } from '@playwright/test';
import { seedRuntimeConfig } from './utils/runtime-config';

test('licensing screen handles activation flow', async ({ page }) => {
	page.on('console', (msg) => {
		if (process.env.PLAYWRIGHT_DEBUG) {
			console.log('console', msg.type(), msg.text());
		}
	});

	page.on('requestfailed', (request) => {
		if (process.env.PLAYWRIGHT_DEBUG) {
			console.log('request failed', request.method(), request.url(), request.failure());
		}
	});

	let status = {
		status: 'inactive',
		license_key_masked: '',
		proxy_key_present: false,
		tier: null,
		expires_at: null,
		last_synced: null,
		license_id: null,
		site_id: null,
		site_url: 'https://example.test'
	};

	await seedRuntimeConfig(page);

	await page.route('**/wp-json/sentient-forms/v1/license', (route) => {
		if (process.env.PLAYWRIGHT_DEBUG) {
			console.log('route', route.request().method(), route.request().url());
		}

		return route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: status }),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.route('**/wp-json/sentient-forms/v1/license/activate', (route) => {
		if (process.env.PLAYWRIGHT_DEBUG) {
			console.log('route', route.request().method(), route.request().url());
		}

			status = {
				status: 'active',
				license_key_masked: 'LIC-****-****-****',
				proxy_key_present: true,
				tier: 'starter',
				expires_at: '2030-01-01T00:00:00Z',
				last_synced: '2030-01-01T00:00:00Z',
				license_id: 'lic-1',
				site_id: 'site-1',
				site_url: 'https://example.test'
			};

		return route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: status }),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.route('**/wp-json/sentient-forms/v1/license/deactivate', (route) => {
		if (process.env.PLAYWRIGHT_DEBUG) {
			console.log('route', route.request().method(), route.request().url());
		}

			status = {
				status: 'inactive',
				license_key_masked: '',
				proxy_key_present: false,
				tier: null,
				expires_at: null,
				last_synced: '2030-01-02T00:00:00Z',
				license_id: null,
				site_id: null,
				site_url: 'https://example.test'
			};

		return route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: status }),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto('/#/licensing');

	await expect(page.getByRole('heading', { name: 'License activation' })).toBeVisible();
	await expect(page.getByText('Proxy key stored')).toBeVisible();

	await page.getByLabel('License key').fill('LIC-123456789012345678901234');
	await page.getByRole('button', { name: 'Activate' }).click();

	await expect(page.getByText('starter')).toBeVisible();
	const deactivateButton = page.getByRole('button', { name: 'Deactivate license' });
	await expect(deactivateButton).toBeVisible();

	await deactivateButton.click();
	await expect(deactivateButton).not.toBeVisible();
});
