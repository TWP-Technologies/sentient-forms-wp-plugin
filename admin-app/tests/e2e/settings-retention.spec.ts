import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';

test.describe('Settings retention controls', () => {
	test('updates local retention and uninstall cleanup settings', async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		const settingsState = {
			enable_logging: true,
			execution_global_disabled: false,
			execution_provider_disabled: { gravity_forms: false },
			execution_event_retention_days: 90,
			delete_data_on_uninstall: false
		};
		const capturedPayloads: unknown[] = [];

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
			throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
		});

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			const request = route.request();
			if (request.method() === 'PUT') {
				const payload = request.postDataJSON() as Partial<typeof settingsState>;
				capturedPayloads.push(payload);
				Object.assign(settingsState, payload);
			}

			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(settingsState)
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/telemetry', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					telemetry_opt_in: false,
					updated_at: '2026-04-21T00:00:00Z',
					synced_at: '2026-04-21T00:00:00Z',
					remote_updated_at: '2026-04-21T00:00:00Z',
					last_error: null
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/async-settings', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					max_attempts: 3,
					base_delay_seconds: 60,
					max_delay_seconds: 3600,
					updated_at: '2026-04-21T00:00:00Z',
					updated_by: 'retention-e2e'
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/async-health', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					queue_depth: 0,
					oldest_run_at: null,
					recent_failures: {},
					warnings: []
				})
			});
		});

		await page.goto('/#/settings', { waitUntil: 'networkidle' });

		await expect(page.getByText('Local data retention')).toBeVisible();
		await page.getByLabel('Execution logs').selectOption('30');
		await page.getByLabel('Delete local data on uninstall').check();
		await page.getByRole('button', { name: 'Save retention' }).click();

		await expect.poll(() => capturedPayloads.length).toBeGreaterThan(0);
		expect(capturedPayloads.at(-1)).toMatchObject({
			execution_event_retention_days: 30,
			delete_data_on_uninstall: true
		});
		await expect(page.getByLabel('Execution logs')).toHaveValue('30');
		await expect(page.getByLabel('Delete local data on uninstall')).toBeChecked();
	});
});
