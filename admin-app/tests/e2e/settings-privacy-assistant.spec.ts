import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';

test.describe('Privacy setup assistant', () => {
	test('opens on first run and applies the selected preset', async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		const settingsState = {
			enable_logging: false,
			execution_global_disabled: false,
			execution_provider_disabled: { gravity_forms: false },
			execution_event_retention_days: 90,
			delete_data_on_uninstall: true,
			store_full_ai_outputs: false,
			privacy_setup_profile: 'balanced',
			privacy_setup_completed_at: null as string | null
		};
		const capturedPayloads: unknown[] = [];

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			const request = route.request();
			if (request.method() === 'PUT') {
				const payload = request.postDataJSON() as Partial<typeof settingsState>;
				capturedPayloads.push(payload);
				if (payload.privacy_setup_profile === 'maximum_visibility') {
					Object.assign(settingsState, {
						enable_logging: true,
						execution_event_retention_days: 180,
						delete_data_on_uninstall: true,
						store_full_ai_outputs: true,
						privacy_setup_profile: 'maximum_visibility',
						privacy_setup_completed_at: '2026-04-21T00:00:00Z'
					});
				}
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
					updated_by: 'privacy-assistant-e2e'
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

		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		await page.getByTestId('privacy-setup-preset-maximum_visibility').click();
		await page.getByRole('button', { name: 'Apply Maximum visibility' }).click();

		await expect.poll(() => capturedPayloads.length).toBe(1);
		expect(capturedPayloads[0]).toMatchObject({
			privacy_setup_profile: 'maximum_visibility'
		});
		await expect(page.getByTestId('privacy-setup-assistant')).toBeHidden();

		await expect(page.getByText('Maximum visibility')).toBeVisible();
		await expect(page.getByTestId('settings-profile-execution-history')).toContainText('180 days');
		await expect(page.getByTestId('settings-profile-full-outputs')).toContainText(
			'Stored locally'
		);
	});
});
