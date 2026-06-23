import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';

test.describe('Settings retention controls', () => {
	test('shows a loading state until privacy settings finish loading', async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		let resolveSettingsRequest: (() => void) | null = null;
		const settingsRequestGate = new Promise<void>((resolve) => {
			resolveSettingsRequest = resolve;
		});

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			await settingsRequestGate;
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					enable_logging: true,
					execution_global_disabled: false,
					execution_provider_disabled: { gravity_forms: false },
					execution_event_retention_days: 180,
					delete_data_on_uninstall: true,
					store_full_ai_outputs: true,
					privacy_setup_profile: 'maximum_visibility',
					privacy_setup_completed_at: '2026-04-21T00:00:00Z'
				})
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

		const navigation = page.goto('/#/settings');
		await expect(page.getByTestId('settings-governance-loading-state')).toBeVisible();
		await expect(page.getByTestId('settings-retention-loading-state')).toBeVisible();

		resolveSettingsRequest?.();
		await navigation;
		await expect(page.getByTestId('settings-governance-loading-state')).toBeHidden();
		await expect(page.getByTestId('settings-retention-loading-state')).toBeHidden();
		await expect(page.getByText('Maximum visibility')).toBeVisible();
		await expect(page.getByTestId('settings-profile-execution-history')).toContainText('180 days');
		await expect(page.getByTestId('settings-profile-full-outputs')).toContainText(
			'Stored locally'
		);
	});

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
			delete_data_on_uninstall: true,
			store_full_ai_outputs: false,
			managed_zdr_required: false,
			privacy_setup_profile: 'balanced',
			privacy_setup_completed_at: '2026-04-21T00:00:00Z'
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

		await expect(page.getByTestId('settings-managed-zdr')).toContainText(
			'Requires Sentient Forms Managed Service to use routes that OpenRouter marks for Zero Data Retention'
		);
		await page.getByLabel('Enforce ZDR for managed service').check();
		await expect.poll(() => capturedPayloads.length).toBeGreaterThan(0);
		expect(capturedPayloads.at(-1)).toMatchObject({
			managed_zdr_required: true
		});

		await expect(page.getByText('Local data retention')).toBeVisible();
		await page.getByLabel('Execution logs').selectOption('30');
		await page.getByLabel('Store full AI outputs locally').check();
		await page.getByLabel('Delete local data on uninstall').check();
		await page.getByRole('button', { name: 'Save retention' }).click();

		await expect.poll(() => capturedPayloads.length).toBeGreaterThan(0);
		expect(capturedPayloads.at(-1)).toMatchObject({
			execution_event_retention_days: 30,
			delete_data_on_uninstall: true,
			store_full_ai_outputs: true
		});
		await expect(page.getByLabel('Execution logs')).toHaveValue('30');
		await expect(page.getByLabel('Store full AI outputs locally')).toBeChecked();
		await expect(page.getByLabel('Delete local data on uninstall')).toBeChecked();
	});

	test('shows a custom profile state when saved controls diverge from the last preset', async ({
		page
	}) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					enable_logging: false,
					execution_global_disabled: false,
					execution_provider_disabled: { gravity_forms: false },
					execution_event_retention_days: 30,
					delete_data_on_uninstall: false,
					store_full_ai_outputs: true,
					privacy_setup_profile: 'maximum_visibility',
					privacy_setup_completed_at: '2026-04-21T00:00:00Z'
				})
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

		await expect(page.getByTestId('settings-profile-customized-badge')).toBeVisible();
		await expect(page.getByTestId('settings-profile-base-badge')).toContainText(
			'Maximum visibility'
		);
		await expect(page.getByText(/Started from Maximum visibility\./)).toBeVisible();
		await expect(page.getByTestId('settings-profile-execution-history')).toContainText('30 days');
		await expect(page.getByTestId('settings-profile-uninstall')).toContainText('Keeps plugin data');
		await expect(page.getByTestId('settings-profile-diagnostics')).toContainText(
			'On-site logging disabled'
		);
	});

	test('uses unambiguous running and pause copy in execution controls', async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					enable_logging: false,
					execution_global_disabled: false,
					execution_provider_disabled: { gravity_forms: false },
					execution_event_retention_days: 90,
					delete_data_on_uninstall: true,
					store_full_ai_outputs: false,
					privacy_setup_profile: 'balanced',
					privacy_setup_completed_at: '2026-04-21T00:00:00Z'
				})
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

		await expect(
			page.getByText('Keep all providers running. Turn this off to pause everything.')
		).toBeVisible();
		await expect(page.getByText('Stops all providers when enabled.')).toHaveCount(0);
		await expect(page.getByText('Running', { exact: true }).first()).toBeVisible();
	});
});
