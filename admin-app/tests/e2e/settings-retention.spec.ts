import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { mockResponsiveApi } from './utils/mock-responsive-api';
import { seedRuntimeConfig } from './utils/runtime-config';

test.describe('Settings retention controls', () => {
	test('ignores malformed settings events without invalidating a valid pending load', async ({
		page
	}) => {
		await seedRuntimeConfig(page, { apiBaseUrl: '/wp-json/sentient-forms/v1/' });
		await mockResponsiveApi(page);

		let releaseSettingsReads: (() => void) | null = null;
		const settingsReadsGate = new Promise<void>((resolve) => {
			releaseSettingsReads = resolve;
		});
		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			await settingsReadsGate;
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					enable_logging: false,
					execution_global_disabled: false,
					execution_provider_disabled: { gravity_forms: false },
					execution_event_retention_days: 30,
					submission_ledger_retention_days: 7,
					delete_data_on_uninstall: true,
					store_full_ai_outputs: false,
					managed_zdr_required: false,
					privacy_setup_profile: 'privacy_focused',
					privacy_setup_completed_at: '2026-04-21T00:00:00Z'
				})
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });
		await expect(page.getByTestId('settings-governance-loading-state')).toBeVisible();
		await page.evaluate(() => {
			window.dispatchEvent(
				new CustomEvent('sentient-forms:settings-updated', {
					detail: { execution_event_retention_days: 180 }
				})
			);
		});

		releaseSettingsReads?.();
		await expect(page.getByTestId('settings-governance-loading-state')).toBeHidden();
		await expect(page.getByLabel('Execution logs')).toHaveValue('30');
		await expect(page.getByLabel('Submission Ledger records')).toHaveValue('7');
		await expect(page.getByLabel('Store full AI outputs locally')).not.toBeChecked();
	});

	test('keeps Review setup synchronized with a direct managed ZDR update', async ({ page }) => {
		await seedRuntimeConfig(page, {
			apiBaseUrl: '/wp-json/sentient-forms/v1/',
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
		await mockResponsiveApi(page);

		const settingsState = {
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
		};
		const settingsPayloads: Array<Record<string, unknown>> = [];

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			const request = route.request();
			if (request.method() !== 'GET') {
				const payload = request.postDataJSON() as Record<string, unknown>;
				settingsPayloads.push(payload);
				Object.assign(settingsState, payload);
			}

			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(settingsState)
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });
		const pageManagedZdr = page
			.getByTestId('settings-managed-zdr')
			.getByLabel('Enforce ZDR for managed service');
		await expect(pageManagedZdr).not.toBeChecked();
		const updateResponse = page.waitForResponse(
			(response) =>
				response.request().method() === 'PUT' &&
				new URL(response.url()).pathname.endsWith('/settings')
		);
		await pageManagedZdr.check();
		await updateResponse;
		await expect.poll(() => settingsPayloads.length).toBe(1);
		expect(settingsPayloads[0]).toEqual({ managed_zdr_required: true });

		await page.getByRole('button', { name: 'Review setup' }).click();
		const assistant = page.getByTestId('privacy-setup-assistant');
		await expect(assistant).toBeVisible();
		await expect(assistant.getByLabel('Enforce ZDR for managed service')).toBeChecked();

		await page.getByTestId('privacy-setup-preset-maximum_visibility').click();
		await page.getByRole('button', { name: 'Apply Maximum visibility' }).click();
		await expect(assistant).toBeHidden();
		await expect.poll(() => settingsPayloads.length).toBe(2);
		expect(settingsPayloads[1]).toMatchObject({
			privacy_setup_profile: 'maximum_visibility'
		});
		expect(settingsPayloads[1]).not.toHaveProperty('managed_zdr_required');
		expect(settingsState.managed_zdr_required).toBe(true);
		await expect(pageManagedZdr).toBeChecked();
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
					submission_ledger_retention_days: 90,
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
					local_diagnostics_enabled: false,
					updated_at: '2026-04-21T00:00:00Z'
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
		await expect(page.getByLabel('Execution logs')).toBeVisible();
		await expect(page.getByLabel('Execution logs')).toBeDisabled();
		await expect(page.getByLabel('Submission Ledger records')).toBeVisible();
		await expect(page.getByLabel('Submission Ledger records')).toBeDisabled();

		resolveSettingsRequest?.();
		await navigation;
		await expect(page.getByTestId('settings-governance-loading-state')).toBeHidden();
		await expect(page.getByTestId('settings-retention-loading-state')).toBeHidden();
		await expect(page.getByTestId('settings-profile-base-badge')).toContainText(
			'Maximum visibility'
		);
		await expect(page.getByTestId('settings-profile-execution-history')).toContainText('180 days');
		await expect(page.getByTestId('settings-profile-submission-ledger')).toContainText('90 days');
		await expect(page.getByTestId('settings-profile-full-outputs')).toContainText('Stored locally');
		await expect(page.getByText('Enable local diagnostic events')).toBeVisible();
		await expect(page.getByText('Nothing is sent to Sentient Forms.')).toBeVisible();
		await expect(page.getByText('Enable telemetry sharing')).toHaveCount(0);
	});

	test('keeps newer assistant settings when older page and logging reads fail afterward', async ({
		page
	}) => {
		await seedRuntimeConfig(page, { apiBaseUrl: '/wp-json/sentient-forms/v1/' });
		await mockResponsiveApi(page);

		const olderSettings = {
			enable_logging: false,
			execution_global_disabled: false,
			execution_provider_disabled: { gravity_forms: false },
			execution_event_retention_days: 30,
			submission_ledger_retention_days: 7,
			delete_data_on_uninstall: true,
			store_full_ai_outputs: false,
			managed_zdr_required: false,
			privacy_setup_profile: 'privacy_focused',
			privacy_setup_completed_at: '2026-04-21T00:00:00Z'
		};
		const settingsState = { ...olderSettings };
		const retentionPayloads: Array<Record<string, unknown>> = [];
		let settingsReads = 0;
		let olderFailuresCompleted = 0;
		let markInitialReadsStarted: (() => void) | null = null;
		let releaseInitialReads: (() => void) | null = null;
		const initialReadsStarted = new Promise<void>((resolve) => {
			markInitialReadsStarted = resolve;
		});
		const initialReadsGate = new Promise<void>((resolve) => {
			releaseInitialReads = resolve;
		});

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			const request = route.request();
			if (request.method() === 'GET') {
				settingsReads += 1;
				if (settingsReads <= 2) {
					if (settingsReads === 2) markInitialReadsStarted?.();
					await initialReadsGate;
					await route.fulfill({
						status: 503,
						contentType: 'application/json',
						body: JSON.stringify({
							code: 'settings_unavailable',
							message: 'Older settings request failed'
						})
					});
					olderFailuresCompleted += 1;
					return;
				}

				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(settingsState)
				});
				return;
			}

			const payload = request.postDataJSON() as Record<string, unknown>;
			if ('submission_ledger_retention_days' in payload) retentionPayloads.push(payload);
			if (payload.privacy_setup_profile === 'maximum_visibility') {
				Object.assign(settingsState, {
					enable_logging: true,
					execution_event_retention_days: 180,
					submission_ledger_retention_days: 180,
					delete_data_on_uninstall: true,
					store_full_ai_outputs: true,
					privacy_setup_profile: 'maximum_visibility',
					privacy_setup_completed_at: '2026-04-21T00:00:00Z'
				});
			} else {
				Object.assign(settingsState, payload);
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(settingsState)
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });
		await initialReadsStarted;
		await page.evaluate(() => {
			window.dispatchEvent(new CustomEvent('sentient-forms:open-privacy-setup'));
		});
		await expect.poll(() => settingsReads).toBeGreaterThanOrEqual(3);
		await expect(page.getByTestId('privacy-setup-preset-maximum_visibility')).toBeEnabled();
		await page.getByTestId('privacy-setup-preset-maximum_visibility').click();
		await page.getByRole('button', { name: 'Apply Maximum visibility' }).click();
		await expect(page.getByTestId('privacy-setup-assistant')).toBeHidden();
		await expect(page.getByTestId('settings-profile-base-badge')).toContainText(
			'Maximum visibility'
		);

		releaseInitialReads?.();
		await expect.poll(() => olderFailuresCompleted).toBe(2);
		await expect(page.getByTestId('settings-governance-loading-state')).toBeHidden();
		await expect(page.getByTestId('settings-retention-error-state')).toHaveCount(0);
		await expect(page.getByTestId('settings-logging-error-state')).toHaveCount(0);
		await expect(page.getByText('Failed to load execution control settings')).toHaveCount(0);
		await expect(page.getByLabel('Execution logs')).toHaveValue('180');
		await expect(page.getByLabel('Submission Ledger records')).toHaveValue('180');
		await expect(page.getByLabel('Store full AI outputs locally')).toBeChecked();

		await page.getByLabel('Submission Ledger records').selectOption('90');
		await page.getByRole('button', { name: 'Save retention' }).click();
		await expect.poll(() => retentionPayloads.length).toBe(1);
		expect(retentionPayloads[0]).toMatchObject({
			execution_event_retention_days: 180,
			submission_ledger_retention_days: 90,
			delete_data_on_uninstall: true,
			store_full_ai_outputs: true
		});
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
		let resolveRetentionSave: (() => void) | null = null;
		let resolveLoggingSave: (() => void) | null = null;
		let failRetentionSave = false;
		let holdLoggingSave = false;
		const retentionSaveGate = new Promise<void>((resolve) => {
			resolveRetentionSave = resolve;
		});
		const loggingSaveGate = new Promise<void>((resolve) => {
			resolveLoggingSave = resolve;
		});

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
			throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
		});

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			const request = route.request();
			if (request.method() === 'PUT') {
				const payload = request.postDataJSON() as Partial<typeof settingsState>;
				capturedPayloads.push(payload);
				if (failRetentionSave && 'submission_ledger_retention_days' in payload) {
					await route.fulfill({
						status: 500,
						contentType: 'application/json',
						body: JSON.stringify({
							code: 'retention_save_failed',
							message: 'Retention save failed'
						})
					});
					return;
				}
				if (payload.privacy_setup_profile === 'maximum_visibility') {
					Object.assign(settingsState, {
						enable_logging: true,
						execution_event_retention_days: 180,
						submission_ledger_retention_days: 180,
						delete_data_on_uninstall: true,
						store_full_ai_outputs: true,
						privacy_setup_profile: 'maximum_visibility',
						privacy_setup_completed_at: '2026-04-21T00:00:00Z'
					});
				} else {
					Object.assign(settingsState, payload);
				}
				if ('submission_ledger_retention_days' in payload) {
					await retentionSaveGate;
				}
				if (holdLoggingSave && 'enable_logging' in payload) {
					await loggingSaveGate;
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
					local_diagnostics_enabled: false,
					updated_at: '2026-04-21T00:00:00Z'
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
		const reviewSetup = page.getByRole('button', { name: 'Review setup' });
		const loggingCard = page.getByText('Enable on-site logging').locator('../..');
		const loggingCheckbox = loggingCard.getByRole('checkbox');
		await expect(saveRetention).toBeDisabled();
		await expect(reviewSetup).toBeEnabled();
		const manualRetentionHelp = page.getByTestId('settings-manual-retention-help');
		await expect(manualRetentionHelp).toHaveCount(0);
		await page.getByLabel('Execution logs').selectOption('0');
		await expect(manualRetentionHelp).toHaveText(
			'Manual cleanup gives new execution logs no automatic expiry. Changing this policy later affects future logs only; existing manual logs remain until an administrator deletes them.'
		);
		await page.getByLabel('Submission Ledger records').selectOption('0');
		await expect(manualRetentionHelp).toHaveText(
			'Manual cleanup gives new execution logs and Submission Ledger records no automatic expiry. Changing these policies later affects future records only; existing manual records remain until an administrator deletes them.'
		);
		await page.getByLabel('Execution logs').selectOption('30');
		await expect(manualRetentionHelp).toHaveText(
			'Manual cleanup gives new Submission Ledger records no automatic expiry. Changing this policy later affects future records only; existing manual records remain until an administrator deletes them.'
		);
		await page.getByLabel('Execution logs').selectOption('30');
		await page.getByLabel('Submission Ledger records').selectOption('7');
		await expect(page.getByTestId('settings-retention-unsaved')).toContainText('Unsaved changes');
		await expect(saveRetention).toBeEnabled();
		await page.getByLabel('Store full AI outputs locally').check();
		await page.getByLabel('Delete local data on uninstall').check();
		await page.getByLabel('Global execution').uncheck({ timeout: 5_000 });
		await expect.poll(() => capturedPayloads.length).toBeGreaterThan(0);
		expect(capturedPayloads.at(-1)).toMatchObject({ execution_global_disabled: true });
		await expect(page.getByLabel('Execution logs')).toHaveValue('30');
		await expect(page.getByLabel('Submission Ledger records')).toHaveValue('7');
		await expect(page.getByLabel('Store full AI outputs locally')).toBeChecked();
		await expect(page.getByTestId('settings-retention-unsaved')).toBeVisible();

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
		const savingButton = page.getByRole('button', { name: 'Saving…' });
		await expect(savingButton).toBeDisabled();
		await expect(reviewSetup).toBeDisabled();
		await expect(loggingCheckbox).toBeDisabled();
		const payloadCountWhileRetentionSaving = capturedPayloads.length;
		await loggingCheckbox.evaluate((input: HTMLInputElement) => {
			input.checked = false;
			input.dispatchEvent(new Event('change', { bubbles: true }));
		});
		await page.waitForTimeout(100);
		expect(capturedPayloads).toHaveLength(payloadCountWhileRetentionSaving);
		await loggingCheckbox.evaluate((input: HTMLInputElement) => {
			input.checked = true;
		});
		await reviewSetup.evaluate((button: HTMLButtonElement) => button.click());
		await expect(page.getByTestId('privacy-setup-assistant')).toHaveCount(0);
		await savingButton.evaluate((button: HTMLButtonElement) => button.click());
		expect(capturedPayloads).toHaveLength(retentionPayloadCount + 1);
		resolveRetentionSave?.();
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
		await expect(reviewSetup).toBeEnabled();

		failRetentionSave = true;
		await page.getByLabel('Submission Ledger records').selectOption('30');
		await saveRetention.click();
		await expect(page.getByTestId('settings-retention-error')).toContainText(
			'Unable to update local data retention. Your unsaved choices are still here.'
		);
		await expect(
			page.getByText('Unable to update local data retention', { exact: true })
		).toBeVisible();
		await expect(page.getByLabel('Submission Ledger records')).toHaveValue('30');
		await expect(page.getByTestId('settings-retention-unsaved')).toBeVisible();
		await expect(page.getByTestId('settings-profile-custom-copy')).toContainText(
			'Submission Ledger records are 7 days'
		);
		await expect(page.getByTestId('settings-profile-submission-ledger')).toContainText('7 days');
		await expect(saveRetention).toBeEnabled();

		holdLoggingSave = true;
		await loggingCheckbox.uncheck();
		await expect(loggingCheckbox).toBeDisabled();
		await expect(reviewSetup).toBeDisabled();
		await reviewSetup.evaluate((button: HTMLButtonElement) => button.click());
		await expect(page.getByTestId('privacy-setup-assistant')).toHaveCount(0);
		resolveLoggingSave?.();
		await expect(loggingCheckbox).not.toBeChecked();
		await expect(reviewSetup).toBeEnabled();

		await reviewSetup.click();
		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		await page.getByTestId('privacy-setup-preset-maximum_visibility').click();
		await page.getByRole('button', { name: 'Apply Maximum visibility' }).click();
		await expect(page.getByTestId('privacy-setup-assistant')).toBeHidden();
		expect(capturedPayloads.at(-1)).toMatchObject({
			privacy_setup_profile: 'maximum_visibility'
		});
		await expect(page.getByLabel('Execution logs')).toHaveValue('180');
		await expect(page.getByLabel('Submission Ledger records')).toHaveValue('180');
		await expect(page.getByLabel('Store full AI outputs locally')).toBeChecked();
		await expect(page.getByLabel('Delete local data on uninstall')).toBeChecked();
		await expect(loggingCheckbox).toBeChecked();
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
					execution_event_retention_days: 90,
					submission_ledger_retention_days: 180,
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
					local_diagnostics_enabled: false,
					updated_at: '2026-04-21T00:00:00Z'
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
		await expect(page.getByTestId('settings-profile-base-badge')).toContainText('Balanced');
		const customProfileCopy = page.getByTestId('settings-profile-custom-copy');
		await expect(customProfileCopy).toContainText('Started from Balanced');
		await expect(customProfileCopy).toContainText('execution history is 90 days');
		await expect(customProfileCopy).toContainText('Submission Ledger records are 180 days');
		await expect(page.getByTestId('settings-profile-execution-history')).toContainText('90 days');
		await expect(page.getByTestId('settings-profile-submission-ledger')).toContainText('180 days');
		await expect(page.getByTestId('settings-profile-uninstall')).toContainText(
			'Deletes plugin data'
		);
		await expect(page.getByTestId('settings-profile-diagnostics')).toContainText(
			'On-site logging disabled'
		);
	});

	test('keeps both retention controls explicit while unavailable and retries loading', async ({
		page
	}) => {
		await seedRuntimeConfig(page, { apiBaseUrl: '/wp-json/sentient-forms/v1/' });
		await mockResponsiveApi(page);
		let settingsReads = 0;
		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			settingsReads += 1;
			if (settingsReads === 1) {
				await route.fulfill({
					status: 503,
					contentType: 'application/json',
					body: JSON.stringify({ code: 'settings_unavailable', message: 'Settings unavailable' })
				});
				return;
			}

			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					enable_logging: false,
					execution_global_disabled: false,
					execution_provider_disabled: { gravity_forms: false },
					execution_event_retention_days: 30,
					submission_ledger_retention_days: 180,
					delete_data_on_uninstall: true,
					store_full_ai_outputs: false,
					managed_zdr_required: false,
					privacy_setup_profile: 'balanced',
					privacy_setup_completed_at: '2026-04-21T00:00:00Z'
				})
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });

		await expect(page.getByTestId('settings-retention-error-state')).toBeVisible();
		await expect(page.getByLabel('Execution logs')).toBeVisible();
		await expect(page.getByLabel('Execution logs')).toBeDisabled();
		await expect(page.getByLabel('Submission Ledger records')).toBeVisible();
		await expect(page.getByLabel('Submission Ledger records')).toBeDisabled();
		await page
			.getByTestId('settings-retention-error-state')
			.getByRole('button', { name: 'Retry' })
			.click();
		await expect(page.getByTestId('settings-retention-error-state')).toBeHidden();
		await expect(page.getByLabel('Execution logs')).toHaveValue('30');
		await expect(page.getByLabel('Submission Ledger records')).toHaveValue('180');
	});

	test('keeps a newer privacy settings success when an older request fails afterward', async ({
		page
	}) => {
		await seedRuntimeConfig(page, { apiBaseUrl: '/wp-json/sentient-forms/v1/' });
		await mockResponsiveApi(page);
		let settingsReads = 0;
		let markOlderRequestStarted: (() => void) | null = null;
		let releaseOlderFailure: (() => void) | null = null;
		const olderRequestStarted = new Promise<void>((resolve) => {
			markOlderRequestStarted = resolve;
		});
		const olderFailureGate = new Promise<void>((resolve) => {
			releaseOlderFailure = resolve;
		});

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			settingsReads += 1;
			if (settingsReads === 1) {
				markOlderRequestStarted?.();
				await olderFailureGate;
				await route.fulfill({
					status: 503,
					contentType: 'application/json',
					body: JSON.stringify({ code: 'settings_unavailable', message: 'Older request failed' })
				});
				return;
			}

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
					privacy_setup_completed_at: null
				})
			});
		});

		await page.goto('/#/dashboard', { waitUntil: 'domcontentloaded' });
		await olderRequestStarted;
		await page.evaluate(() => {
			window.dispatchEvent(new CustomEvent('sentient-forms:open-privacy-setup'));
		});

		await expect.poll(() => settingsReads).toBeGreaterThanOrEqual(2);
		await expect(page.getByTestId('privacy-setup-preset-balanced')).toBeEnabled();
		const olderFailureResponse = page.waitForResponse(
			(response) => response.url().includes('/settings') && response.status() === 503
		);
		releaseOlderFailure?.();
		await olderFailureResponse;
		await page.evaluate(
			() =>
				new Promise<void>((resolve) => {
					requestAnimationFrame(() => requestAnimationFrame(() => resolve()));
				})
		);

		await expect(page.getByTestId('privacy-setup-load-error')).toHaveCount(0);
		await expect(page.getByTestId('privacy-setup-loading-state')).toHaveCount(0);
		await expect(page.getByTestId('privacy-setup-preset-balanced')).toBeEnabled();
	});

	test('keeps privacy preset application disabled and restores managed ZDR after settings retry', async ({
		page
	}) => {
		await seedRuntimeConfig(page, {
			apiBaseUrl: '/wp-json/sentient-forms/v1/',
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
		await mockResponsiveApi(page);
		let settingsReads = 0;

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			settingsReads += 1;
			if (settingsReads <= 3) {
				await route.fulfill({
					status: 503,
					contentType: 'application/json',
					body: JSON.stringify({ code: 'settings_unavailable', message: 'Settings unavailable' })
				});
				return;
			}

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
					managed_zdr_required: true,
					privacy_setup_profile: 'balanced',
					privacy_setup_completed_at: null
				})
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });
		await expect(page.getByTestId('settings-retention-error-state')).toBeVisible();
		await page.evaluate(() => {
			window.dispatchEvent(new CustomEvent('sentient-forms:open-privacy-setup'));
		});

		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		await expect(page.getByTestId('privacy-setup-load-error')).toBeVisible();
		await expect(page.getByTestId('privacy-setup-preset-balanced')).toBeDisabled();
		await expect(page.getByRole('button', { name: 'Skip Setup' })).toBeDisabled();
		await page
			.getByTestId('privacy-setup-load-error')
			.getByRole('button', { name: 'Retry' })
			.click();

		await expect(page.getByTestId('privacy-setup-load-error')).toBeHidden();
		await expect(page.getByTestId('privacy-setup-preset-balanced')).toBeEnabled();
		await expect(
			page
				.getByTestId('privacy-setup-managed-zdr')
				.getByRole('checkbox', { name: 'Enforce ZDR for managed service' })
		).toBeChecked();
		await expect(page.getByTestId('privacy-setup-preset-balanced')).toHaveAttribute(
			'aria-pressed',
			'false'
		);
		const assistant = page.getByTestId('privacy-setup-assistant');
		await assistant.focus();
		await page.keyboard.press('Shift+Tab');
		await expect(page.getByRole('button', { name: 'Skip Setup' })).toBeFocused();
		await page.evaluate(() => {
			const outsideButton = document.createElement('button');
			outsideButton.id = 'privacy-setup-outside-focus-probe';
			outsideButton.textContent = 'Outside focus probe';
			document.body.append(outsideButton);
			outsideButton.focus();
		});
		await expect(page.locator('#privacy-setup-outside-focus-probe')).toBeFocused();
		await page.keyboard.press('Tab');
		await expect(page.getByTestId('privacy-setup-preset-balanced')).toBeFocused();
		await expect(page.getByRole('button', { name: 'Choose a preset' })).toBeDisabled();
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
					local_diagnostics_enabled: false,
					updated_at: '2026-04-21T00:00:00Z'
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
});
