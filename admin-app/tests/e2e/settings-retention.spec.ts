import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';
import { mockResponsiveApi } from './utils/mock-responsive-api';

test.describe('Settings retention controls', () => {
	test('describes telemetry consent as local-only diagnostics', async ({ page }) => {
		await seedRuntimeConfig(page, { apiBaseUrl: '/wp-json/sentient-forms/v1/' });
		await mockResponsiveApi(page);

		await page.goto('/#/settings', { waitUntil: 'networkidle' });

		const diagnostics = page.getByTestId('settings-local-diagnostics');
		await expect(diagnostics.getByText('Allow local diagnostic events')).toBeVisible();
		await expect(diagnostics).toContainText('Enable on-site logging below to write them');
		await expect(diagnostics).toContainText('Nothing is sent off-site in this release.');
		await expect(diagnostics.getByText(/Remote consent record|Synced/)).toHaveCount(0);

		await diagnostics.getByRole('button', { name: 'What is recorded?' }).click();
		const dialog = page.getByRole('dialog', { name: 'Local diagnostics and data privacy' });
		await expect(dialog).toBeVisible();
		await expect(dialog).toContainText('On-site logging must also be enabled');
		await expect(dialog).toContainText('When consent and on-site logging are both enabled');
		await expect(dialog).toContainText('remain on this WordPress site');
		await expect(dialog).toContainText('Nothing is sent off-site in this release.');
		await expect(dialog).not.toContainText('remote telemetry sync');
	});

	test('shows a loading state until privacy settings finish loading', async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost,
			license: {
				status: 'active',
				licenseKeyMasked: 'LIC-****-TEST',
				proxyKeyPresent: true,
				tier: 'starter',
				expiresAt: '2030-01-01T00:00:00Z',
				lastSynced: '2030-01-05T10:00:00Z',
				licenseId: 'license-managed-test',
				siteId: 'site-managed-test'
			}
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
					submission_ledger_retention_days: 180,
					delete_data_on_uninstall: true,
					store_full_ai_outputs: true,
					managed_zdr_required: false,
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
		const retentionLoading = page.getByTestId('settings-retention-loading-state');
		await expect(retentionLoading).toBeVisible();
		await expect(retentionLoading).toContainText('Execution logs');
		await expect(retentionLoading).toContainText('Submission Ledger records');

		resolveSettingsRequest?.();
		await navigation;
		await expect(page.getByTestId('settings-governance-loading-state')).toBeHidden();
		await expect(page.getByTestId('settings-retention-loading-state')).toBeHidden();
		await expect(page.getByText('Maximum visibility')).toBeVisible();
		await expect(page.getByTestId('settings-profile-execution-history')).toContainText('180 days');
		await expect(page.getByTestId('settings-profile-submission-ledger')).toContainText('180 days');
		await expect(page.getByTestId('settings-profile-full-outputs')).toContainText('Stored locally');
	});

	test('updates local retention and uninstall cleanup settings', async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost,
			license: {
				status: 'active',
				licenseKeyMasked: 'LIC-****-TEST',
				proxyKeyPresent: true,
				tier: 'starter',
				expiresAt: '2030-01-01T00:00:00Z',
				lastSynced: '2030-01-05T10:00:00Z',
				licenseId: 'license-managed-test',
				siteId: 'site-managed-test'
			}
		});

		const settingsState = {
			enable_logging: true,
			execution_global_disabled: false,
			execution_provider_disabled: { gravity_forms: false },
			execution_event_retention_days: 90,
			submission_ledger_retention_days: 90,
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

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });

		await expect(page.getByTestId('settings-managed-zdr')).toContainText(
			'Requires Sentient Forms Managed Service to use routes that OpenRouter marks for Zero Data Retention'
		);
		await expect(page.getByText('Local data retention')).toBeVisible();
		const saveRetention = page.getByRole('button', { name: 'Save retention' });
		await expect(saveRetention).toBeDisabled();
		const manualRetentionHelp = page.getByTestId('settings-manual-retention-help');
		await expect(manualRetentionHelp).toHaveCount(0);
		await page.getByLabel('Execution logs').selectOption('0');
		await expect(manualRetentionHelp).toHaveText(
			'Manual deletion keeps new execution logs until an administrator removes them or changes this setting.'
		);
		await page.getByLabel('Submission Ledger records').selectOption('0');
		await expect(manualRetentionHelp).toHaveText(
			'Manual deletion keeps new execution logs and Submission Ledger records until an administrator removes them or changes these settings.'
		);
		await page.getByLabel('Execution logs').selectOption('30');
		await expect(manualRetentionHelp).toHaveText(
			'Manual deletion keeps new Submission Ledger records until an administrator removes them or changes this setting.'
		);
		await page.getByLabel('Submission Ledger records').selectOption('7');
		await expect(page.getByTestId('settings-retention-unsaved')).toContainText('Unsaved changes');
		await expect(saveRetention).toBeEnabled();
		await expect(manualRetentionHelp).toHaveCount(0);
		await page.getByLabel('Execution logs').selectOption('30');
		await page.getByLabel('Submission Ledger records').selectOption('7');
		await page.getByLabel('Store full AI outputs locally').check();
		await page.getByLabel('Delete local data on uninstall').check();
		await page.getByLabel('Global execution').uncheck();
		await expect.poll(() => capturedPayloads.length).toBeGreaterThan(0);
		expect(capturedPayloads.at(-1)).toMatchObject({
			execution_global_disabled: true
		});
		await expect(page.getByLabel('Execution logs')).toHaveValue('30');
		await expect(page.getByLabel('Submission Ledger records')).toHaveValue('7');
		await expect(page.getByLabel('Store full AI outputs locally')).toBeChecked();
		await expect(page.getByLabel('Delete local data on uninstall')).toBeChecked();
		await expect(page.getByTestId('settings-retention-unsaved')).toContainText('Unsaved changes');

		await page.getByLabel('Enforce ZDR for managed service').check();
		await expect.poll(() => capturedPayloads.length).toBeGreaterThan(0);
		expect(capturedPayloads.at(-1)).toMatchObject({
			managed_zdr_required: true
		});
		await expect(page.getByLabel('Execution logs')).toHaveValue('30');
		await expect(page.getByLabel('Store full AI outputs locally')).toBeChecked();
		await expect(page.getByLabel('Delete local data on uninstall')).toBeChecked();

		const retentionPayloadCount = capturedPayloads.length;
		await saveRetention.click();

		await expect.poll(() => capturedPayloads.length).toBeGreaterThan(retentionPayloadCount);
		expect(capturedPayloads.at(-1)).toMatchObject({
			execution_event_retention_days: 30,
			submission_ledger_retention_days: 7,
			delete_data_on_uninstall: true,
			store_full_ai_outputs: true
		});
		await expect(page.getByLabel('Execution logs')).toHaveValue('30');
		await expect(page.getByLabel('Submission Ledger records')).toHaveValue('7');
		await expect(page.getByLabel('Store full AI outputs locally')).toBeChecked();
		await expect(page.getByLabel('Delete local data on uninstall')).toBeChecked();
		await expect(page.getByTestId('settings-retention-success')).toContainText(
			'Retention settings saved on this site.'
		);
		await expect(page.getByText('Local data retention saved')).toBeVisible();
		await expect(saveRetention).toBeDisabled();
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
					submission_ledger_retention_days: 180,
					delete_data_on_uninstall: false,
					store_full_ai_outputs: true,
					managed_zdr_required: false,
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

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });

		await expect(page.getByTestId('settings-profile-customized-badge')).toBeVisible();
		await expect(page.getByTestId('settings-profile-base-badge')).toContainText(
			'Maximum visibility'
		);
		await expect(page.getByText(/Started from Maximum visibility\./)).toBeVisible();
		await expect(page.getByTestId('settings-profile-execution-history')).toContainText('30 days');
		await expect(page.getByTestId('settings-profile-submission-ledger')).toContainText('180 days');
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
					submission_ledger_retention_days: 90,
					delete_data_on_uninstall: true,
					store_full_ai_outputs: false,
					managed_zdr_required: false,
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

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });

		await expect(
			page.getByText('Keep all providers running. Turn this off to pause everything.')
		).toBeVisible();
		await expect(page.getByText('Stops all providers when enabled.')).toHaveCount(0);
		await expect(page.getByText('Running', { exact: true }).first()).toBeVisible();
	});

	test('keeps both retention controls explicit and disabled when settings are unavailable', async ({
		page
	}) => {
		await seedRuntimeConfig(page, { apiBaseUrl: '/wp-json/sentient-forms/v1/' });
		await mockResponsiveApi(page);
		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			await route.fulfill({
				status: 503,
				contentType: 'application/json',
				body: JSON.stringify({ code: 'settings_unavailable', message: 'Settings unavailable' })
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });

		await expect(page.getByTestId('settings-retention-error-state')).toBeVisible();
		await expect(page.getByLabel('Execution logs')).toBeVisible();
		await expect(page.getByLabel('Execution logs')).toBeDisabled();
		await expect(page.getByLabel('Submission Ledger records')).toBeVisible();
		await expect(page.getByLabel('Submission Ledger records')).toBeDisabled();
	});
});
