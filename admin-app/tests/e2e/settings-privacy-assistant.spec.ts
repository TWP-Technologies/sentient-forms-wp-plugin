import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';

test.describe('Privacy setup assistant', () => {
	test.beforeEach(async ({ page }) => {
		await page.route(
			'**/wp-json/sentient-forms/v1/local/providers/openrouter/models**',
			async (route) => {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						provider: 'openrouter',
						source: 'local_cache',
						total_cached: 0,
						total_returned: 0,
						free_count: 0,
						stale_count: 0,
						models: []
					})
				});
			}
		);
	});

	test('opens on first run and applies the selected preset', async ({ page }) => {
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
			enable_logging: false,
			execution_global_disabled: false,
			execution_provider_disabled: { gravity_forms: false },
			execution_event_retention_days: 90,
			submission_ledger_retention_days: 90,
			delete_data_on_uninstall: true,
			store_full_ai_outputs: false,
			managed_zdr_required: false,
			privacy_setup_profile: 'balanced',
			privacy_setup_completed_at: null as string | null
		};
		const capturedPayloads: unknown[] = [];
		const capturedSiteContextPayloads: unknown[] = [];

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
						managed_zdr_required: payload.managed_zdr_required === true,
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

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, data: [] })
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models/resolve**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						model_id: 'openai/gpt-5.5',
						display_name: 'OpenAI: GPT-5.5',
						resolution_source: 'mock',
						override_chain: [],
						backup_model_id: null
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						models: [
							{
								id: 'openai/gpt-5.5',
								display_name: 'OpenAI: GPT-5.5',
								provider: 'openrouter',
								speed_tier: 'balanced',
								cost_tier: 'medium',
								capabilities: {
									reasoning: true,
									tools: true,
									structured: true,
									web_search: true,
									long_context: true
								},
								context_window: 400000,
								tags: ['reasoning', 'structured-output'],
								supported_parameters: ['reasoning', 'tools']
							}
						],
						presets: [
							{
								code: 'sf_research',
								display_name: 'Research',
								category: 'local',
								resolved_model_id: 'openai/gpt-5.5',
								auto_upgrade: true
							}
						]
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/site-context**', async (route) => {
			const request = route.request();
			if (request.method() === 'PUT') {
				capturedSiteContextPayloads.push(request.postDataJSON());
			}

			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					context: null,
					settings: {
						consent_status: 'unset',
						consented_at: null,
						declined_at: null,
						auto_refresh_enabled: false,
						auto_refresh_days: 30,
						next_refresh_at: null,
						last_generated_at: null,
						last_error: null,
						generation_model_selection: {
							primary: 'sf_research',
							is_preset: true,
							provider: 'sentient_managed'
						}
					},
					has_context: false,
					is_empty: true,
					is_stale: false,
					stale_after_days: 90,
					status: 'empty',
					generation_access: {
						can_generate: false,
						reason_code: 'site_context_generation_managed_setup_required',
						message:
							'Connect Sentient Forms Managed Service billing before generating Site Context with managed models.',
						setup_target: 'licensing',
						provider: 'sentient_managed',
						model: 'openai/gpt-5.5'
					}
				})
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });

		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		await expect(page.getByText('Updated before')).toHaveCount(0);
		await expect(
			page.getByTestId('privacy-setup-assistant').getByText('Site Context', { exact: true })
		).toBeVisible();
		await expect(page.getByTestId('site-context-setup-panel')).toBeVisible();
		await expect(page.getByTestId('site-context-model-tools')).toBeVisible();
		await expect(page.getByTestId('site-context-notices')).toBeVisible();
		await expect(page.getByTestId('privacy-setup-managed-zdr')).toContainText(
			'Requires Sentient Forms Managed Service to use routes that OpenRouter marks for Zero Data Retention'
		);
		await page
			.getByTestId('privacy-setup-managed-zdr')
			.getByLabel('Enforce ZDR for managed service')
			.check();
		await page.getByTestId('privacy-setup-preset-maximum_visibility').click();
		await page.getByRole('button', { name: 'Apply Maximum visibility' }).click();

		await expect.poll(() => capturedPayloads.length).toBe(1);
		expect(capturedSiteContextPayloads).toHaveLength(0);
		expect(capturedPayloads[0]).toMatchObject({
			privacy_setup_profile: 'maximum_visibility',
			managed_zdr_required: true
		});
		await expect(page.getByTestId('privacy-setup-assistant')).toBeHidden();

		await expect(page.getByTestId('settings-profile-base-badge')).toContainText(
			'Maximum visibility'
		);
		await expect(page.getByTestId('settings-profile-execution-history')).toContainText('180 days');
		await expect(page.getByTestId('settings-profile-full-outputs')).toContainText('Stored locally');
	});

	test('does not persist managed ZDR from first-run setup without managed service', async ({
		page
	}) => {
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
			submission_ledger_retention_days: 90,
			delete_data_on_uninstall: true,
			store_full_ai_outputs: false,
			managed_zdr_required: false,
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

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, data: [] })
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models/resolve**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						model_id: 'openai/gpt-5.5',
						display_name: 'OpenAI: GPT-5.5',
						resolution_source: 'mock',
						override_chain: [],
						backup_model_id: null
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						models: [],
						presets: []
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/site-context**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					context: null,
					settings: {
						consent_status: 'unset',
						consented_at: null,
						declined_at: null,
						auto_refresh_enabled: false,
						auto_refresh_days: 30,
						next_refresh_at: null,
						last_generated_at: null,
						last_error: null,
						generation_model_selection: {
							primary: 'sf_research',
							is_preset: true,
							provider: 'sentient_managed'
						}
					},
					has_context: false,
					is_empty: true,
					is_stale: false,
					stale_after_days: 90,
					status: 'empty',
					generation_access: {
						can_generate: false,
						reason_code: 'site_context_generation_managed_setup_required',
						message:
							'Connect Sentient Forms Managed Service billing before generating Site Context with managed models.',
						setup_target: 'licensing',
						provider: 'sentient_managed',
						model: 'openai/gpt-5.5'
					}
				})
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });

		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		const zdrCheckbox = page
			.getByTestId('privacy-setup-managed-zdr')
			.getByLabel('Enforce ZDR for managed service');
		await expect(zdrCheckbox).toBeDisabled();
		await expect(zdrCheckbox).not.toBeChecked();

		await page.getByTestId('privacy-setup-preset-maximum_visibility').click();
		await page.getByRole('button', { name: 'Apply Maximum visibility' }).click();

		await expect.poll(() => capturedPayloads.length).toBe(1);
		expect(capturedPayloads[0]).toMatchObject({
			privacy_setup_profile: 'maximum_visibility'
		});
		expect(capturedPayloads[0]).not.toHaveProperty('managed_zdr_required');
	});

	test('requires local managed ZDR when generating Site Context before applying setup', async ({
		page
	}) => {
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
			enable_logging: false,
			execution_global_disabled: false,
			execution_provider_disabled: { gravity_forms: false },
			execution_event_retention_days: 90,
			submission_ledger_retention_days: 90,
			delete_data_on_uninstall: true,
			store_full_ai_outputs: false,
			managed_zdr_required: false,
			privacy_setup_profile: 'balanced',
			privacy_setup_completed_at: null as string | null
		};
		let generatePayload: Record<string, unknown> | null = null;

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
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

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, data: [] })
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models/resolve**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						model_id: 'openai/gpt-5.5',
						display_name: 'OpenAI: GPT-5.5',
						resolution_source: 'mock',
						override_chain: [],
						backup_model_id: null
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						models: [
							{
								id: 'openai/gpt-5.5',
								display_name: 'OpenAI: GPT-5.5',
								provider: 'sentient_managed',
								speed_tier: 'balanced',
								cost_tier: 'medium',
								capabilities: {
									reasoning: true,
									tools: true,
									structured: true,
									web_search: true,
									long_context: true
								},
								context_window: 400000,
								tags: ['zdr', 'reasoning', 'structured-output'],
								supported_parameters: ['reasoning', 'tools']
							}
						],
						presets: [
							{
								code: 'sf_research',
								display_name: 'Research',
								category: 'managed',
								resolved_model_id: 'openai/gpt-5.5',
								auto_upgrade: true
							}
						]
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/site-context**', async (route) => {
			const request = route.request();
			if (request.method() === 'POST' && request.url().endsWith('/site-context/generate')) {
				generatePayload = request.postDataJSON() as Record<string, unknown>;
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						context: null,
						settings: {
							consent_status: 'granted',
							consented_at: '2026-06-18T00:00:00Z',
							declined_at: null,
							auto_refresh_enabled: false,
							auto_refresh_days: 30,
							next_refresh_at: null,
							last_generated_at: null,
							last_error: null,
							generation_model_selection: {
								primary: 'sf_research',
								is_preset: true,
								provider: 'sentient_managed'
							}
						},
						has_context: false,
						is_empty: true,
						is_stale: false,
						stale_after_days: 90,
						status: 'empty',
						generation_access: {
							can_generate: true,
							reason_code: 'ready',
							message: 'Site Context generation is ready through Sentient Forms Managed Service.',
							setup_target: null,
							provider: 'sentient_managed',
							model: 'openai/gpt-5.5'
						},
						generation_job: {
							id: 'job-managed-zdr-local-toggle',
							status: 'queued',
							requested_at: '2026-06-18T00:00:00Z',
							started_at: null,
							finished_at: null,
							error: null,
							model: 'openai/gpt-5.5',
							provider: 'sentient_managed',
							tools: []
						}
					})
				});
				return;
			}

			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					context: null,
					settings: {
						consent_status: 'granted',
						consented_at: '2026-06-18T00:00:00Z',
						declined_at: null,
						auto_refresh_enabled: false,
						auto_refresh_days: 30,
						next_refresh_at: null,
						last_generated_at: null,
						last_error: null,
						generation_model_selection: {
							primary: 'sf_research',
							is_preset: true,
							provider: 'sentient_managed'
						}
					},
					has_context: false,
					is_empty: true,
					is_stale: false,
					stale_after_days: 90,
					status: 'empty',
					generation_access: {
						can_generate: true,
						reason_code: 'ready',
						message: 'Site Context generation is ready through Sentient Forms Managed Service.',
						setup_target: null,
						provider: 'sentient_managed',
						model: 'openai/gpt-5.5'
					},
					generation_job: null
				})
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });
		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		await page
			.getByTestId('privacy-setup-managed-zdr')
			.getByLabel('Enforce ZDR for managed service')
			.check();
		await page.getByTestId('site-context-generate-now').click();

		await expect.poll(() => generatePayload).not.toBeNull();
		expect(generatePayload?.generation_model_selection).toMatchObject({
			primary: 'sf_research',
			is_preset: true,
			provider: 'sentient_managed',
			require_zdr: true
		});
	});

	test('does not overwrite newer managed ZDR settings when applying stale assistant state', async ({
		page
	}) => {
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
			enable_logging: false,
			execution_global_disabled: false,
			execution_provider_disabled: { gravity_forms: false },
			execution_event_retention_days: 90,
			submission_ledger_retention_days: 90,
			delete_data_on_uninstall: true,
			store_full_ai_outputs: false,
			managed_zdr_required: false,
			privacy_setup_profile: 'balanced',
			privacy_setup_completed_at: null as string | null
		};
		let capturedPayload: Record<string, unknown> | null = null;

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			const request = route.request();
			if (request.method() === 'PUT') {
				capturedPayload = request.postDataJSON() as Record<string, unknown>;
				Object.assign(settingsState, capturedPayload, {
					privacy_setup_completed_at: '2026-04-21T00:00:00Z'
				});
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

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, data: [] })
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models/resolve**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						model_id: 'openai/gpt-5.5',
						display_name: 'OpenAI: GPT-5.5',
						resolution_source: 'mock',
						override_chain: [],
						backup_model_id: null
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						models: [],
						presets: []
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/site-context**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					context: null,
					settings: {
						consent_status: 'unset',
						consented_at: null,
						declined_at: null,
						auto_refresh_enabled: false,
						auto_refresh_days: 30,
						next_refresh_at: null,
						last_generated_at: null,
						last_error: null,
						generation_model_selection: {
							primary: 'sf_research',
							is_preset: true,
							provider: 'sentient_managed'
						}
					},
					has_context: false,
					is_empty: true,
					is_stale: false,
					stale_after_days: 90,
					status: 'empty',
					generation_access: {
						can_generate: false,
						reason_code: 'site_context_generation_consent_required',
						message: 'Allow AI-generated Site Context before running generation.',
						setup_target: 'site_context_consent',
						provider: 'sentient_managed',
						model: 'openai/gpt-5.5'
					}
				})
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });
		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		settingsState.managed_zdr_required = true;
		await page.getByRole('button', { name: 'Apply Balanced' }).click();

		await expect.poll(() => capturedPayload).not.toBeNull();
		expect(capturedPayload).not.toHaveProperty('managed_zdr_required');
		expect(settingsState.managed_zdr_required).toBe(true);
	});

	test('keeps the first-run modal open while Site Context generation runs in the background', async ({
		page
	}) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		let generateRequests = 0;
		let pollRequests = 0;

		const readyEmptyStatus = {
			context: null,
			settings: {
				consent_status: 'granted',
				consented_at: '2026-06-18T00:00:00Z',
				declined_at: null,
				auto_refresh_enabled: false,
				auto_refresh_days: 30,
				next_refresh_at: null,
				last_generated_at: null,
				last_error: null,
				generation_model_selection: {
					primary: '~google/gemini-pro-latest',
					is_preset: false,
					provider: 'openrouter',
					credential_id: 12,
					tools: {
						tool_choice: 'auto',
						web_search: { mode: 'required', max_results: 5 }
					}
				}
			},
			has_context: false,
			is_empty: true,
			is_stale: false,
			stale_after_days: 90,
			status: 'empty',
			generation_access: {
				can_generate: true,
				reason_code: 'ready',
				message: 'Site Context generation is ready through your OpenRouter key.',
				setup_target: null,
				provider: 'openrouter',
				model: '~google/gemini-pro-latest',
				credential_id: 12
			},
			generation_job: null
		};

		const generatedStatus = {
			...readyEmptyStatus,
			context: {
				id: 'ctx-privacy-generated',
				license_id: 'local',
				summary_text: 'Generated assistant Site Context.',
				source: 'ai_generated',
				auto_include: true,
				pii_ack: true,
				free_refresh_available: true,
				next_free_refresh_at: null,
				created_at: '2026-06-18T00:00:00Z',
				updated_at: '2026-06-18T00:00:00Z'
			},
			settings: {
				...readyEmptyStatus.settings,
				last_generated_at: '2026-06-18T00:00:00Z'
			},
			has_context: true,
			is_empty: false,
			status: 'ready',
			generation_job: {
				id: 'job-privacy-context-1',
				status: 'succeeded',
				requested_at: '2026-06-18T00:00:00Z',
				started_at: '2026-06-18T00:00:01Z',
				finished_at: '2026-06-18T00:00:04Z',
				error: null,
				model: '~google/gemini-pro-latest',
				provider: 'openrouter',
				tools: ['web_search']
			}
		};

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
					privacy_setup_completed_at: null
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/telemetry', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					local_diagnostics_enabled: false,
					updated_at: null
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
					updated_at: null,
					updated_by: null
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

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: [
						{
							id: 12,
							provider: 'openrouter',
							label: 'OpenRouter key',
							auth_mode: 'manual_key',
							constant_name: null,
							status: 'valid',
							status_json: null,
							last_validated_at: '2026-06-18T00:00:00Z',
							created_at: '2026-06-18T00:00:00Z',
							updated_at: '2026-06-18T00:00:00Z',
							secret_configured: true
						}
					]
				})
			});
		});

		await page.route(
			'**/wp-json/sentient-forms/v1/local/providers/openrouter/models**',
			async (route) => {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						provider: 'openrouter',
						source: 'local_cache',
						total_cached: 0,
						total_returned: 0,
						free_count: 0,
						stale_count: 0,
						models: []
					})
				});
			}
		);

		await page.route('**/wp-json/sentient-forms/v1/models**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						models: [
							{
								id: '~google/gemini-pro-latest',
								display_name: 'Google: Gemini 3.1 Pro Preview (latest alias)',
								provider: 'openrouter',
								speed_tier: 'balanced',
								cost_tier: 'high',
								capabilities: {
									reasoning: true,
									tools: true,
									structured: true,
									web_search: true,
									server_tools: {
										web_search: true,
										web_fetch: false,
										datetime: false
									},
									long_context: true
								},
								context_window: 1048576,
								tags: ['reasoning', 'structured-output', 'web-search'],
								supported_parameters: ['reasoning', 'tools']
							}
						],
						presets: []
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/site-context**', async (route) => {
			const request = route.request();
			if (request.method() === 'POST' && request.url().endsWith('/site-context/generate')) {
				generateRequests += 1;
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						...readyEmptyStatus,
						generation_job: {
							id: 'job-privacy-context-1',
							status: 'queued',
							requested_at: '2026-06-18T00:00:00Z',
							started_at: null,
							finished_at: null,
							error: null,
							model: '~google/gemini-pro-latest',
							provider: 'openrouter',
							tools: ['web_search']
						}
					})
				});
			}

			if (request.method() === 'GET' && generateRequests > 0) {
				pollRequests += 1;
				if (pollRequests === 1) {
					return route.fulfill({
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify({
							...readyEmptyStatus,
							generation_job: {
								id: 'job-privacy-context-1',
								status: 'running',
								requested_at: '2026-06-18T00:00:00Z',
								started_at: '2026-06-18T00:00:01Z',
								finished_at: null,
								error: null,
								model: '~google/gemini-pro-latest',
								provider: 'openrouter',
								tools: ['web_search']
							}
						})
					});
				}

				if (pollRequests === 2) {
					return route.fulfill({
						status: 503,
						contentType: 'application/json',
						body: JSON.stringify({
							code: 'temporarily_unavailable',
							message: 'Transient polling failure'
						})
					});
				}

				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(generatedStatus)
				});
			}

			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(readyEmptyStatus)
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });
		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		await expect(page.getByTestId('site-context-generate-now')).toBeEnabled();
		await page.getByTestId('site-context-generate-now').click();

		await expect.poll(() => generateRequests).toBe(1);
		await expect(
			page.getByText('Site Context generation is running in the background.')
		).toBeVisible();
		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		await expect(page.getByTestId('site-context-generate-now')).toBeDisabled();
		await expect.poll(() => pollRequests, { timeout: 8_000 }).toBeGreaterThanOrEqual(1);
		await page
			.getByTestId('site-context-textarea')
			.fill('Unsaved assistant edit while generation runs.');
		await expect.poll(() => pollRequests, { timeout: 15_000 }).toBeGreaterThanOrEqual(3);
		await expect(page.getByTestId('site-context-textarea')).toHaveValue(
			'Unsaved assistant edit while generation runs.'
		);
		await expect(page.getByText('Site Context generated.')).toBeVisible();
	});

	test('keeps the first-run modal open with a visible error when Site Context setup cannot be saved', async ({
		page
	}) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		let settingsPutCount = 0;

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			if (route.request().method() === 'PUT') {
				settingsPutCount += 1;
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

		await page.route('**/wp-json/sentient-forms/v1/telemetry', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					local_diagnostics_enabled: false,
					updated_at: null
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
					updated_at: null,
					updated_by: null
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

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, data: [] })
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						models: [],
						presets: []
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/site-context**', async (route) => {
			if (route.request().method() === 'PUT') {
				await route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({
						code: 'site_context_save_failed',
						message: 'Site Context could not be saved for this site.'
					})
				});
				return;
			}

			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					context: null,
					settings: {
						consent_status: 'unset',
						consented_at: null,
						declined_at: null,
						auto_refresh_enabled: false,
						auto_refresh_days: 30,
						next_refresh_at: null,
						last_generated_at: null,
						last_error: null,
						generation_model_selection: {
							primary: 'sf_research',
							is_preset: true,
							provider: 'sentient_managed'
						}
					},
					has_context: false,
					is_empty: true,
					is_stale: false,
					stale_after_days: 90,
					status: 'empty',
					generation_access: {
						can_generate: false,
						reason_code: 'site_context_generation_consent_required',
						message: 'Allow AI-generated Site Context before running generation.',
						setup_target: 'site_context_consent',
						provider: 'sentient_managed',
						model: 'openai/gpt-5.5'
					}
				})
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });
		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		await page.getByTestId('site-context-generation-consent').click();
		await page.getByRole('button', { name: 'Apply Balanced' }).click();

		await expect(page.getByTestId('privacy-setup-apply-error')).toContainText(
			'Site Context could not be saved'
		);
		await expect(page.getByTestId('privacy-site-context-error')).toContainText(
			'Site Context could not be saved'
		);
		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		expect(settingsPutCount).toBe(0);
	});

	test('keeps the first-run modal open with a footer error when settings save fails', async ({
		page
	}) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		let settingsPutCount = 0;
		let siteContextPutCount = 0;

		await page.route('**/wp-json/sentient-forms/v1/settings', async (route) => {
			if (route.request().method() === 'PUT') {
				settingsPutCount += 1;
				await route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({
						code: 'settings_save_failed',
						message: 'Settings could not be saved on this site.'
					})
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

		await page.route('**/wp-json/sentient-forms/v1/telemetry', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					local_diagnostics_enabled: false,
					updated_at: null
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
					updated_at: null,
					updated_by: null
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

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, data: [] })
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models/resolve**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						model_id: 'openai/gpt-5.5',
						display_name: 'OpenAI: GPT-5.5',
						resolution_source: 'mock',
						override_chain: [],
						backup_model_id: null
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/models', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						models: [],
						presets: []
					}
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/site-context**', async (route) => {
			if (route.request().method() === 'PUT') {
				siteContextPutCount += 1;
			}

			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					context: null,
					settings: {
						consent_status: 'unset',
						consented_at: null,
						declined_at: null,
						auto_refresh_enabled: false,
						auto_refresh_days: 30,
						next_refresh_at: null,
						last_generated_at: null,
						last_error: null,
						generation_model_selection: null
					},
					has_context: false,
					is_empty: true,
					is_stale: false,
					stale_after_days: 90,
					status: 'empty',
					generation_access: {
						can_generate: false,
						reason_code: 'site_context_generation_consent_required',
						message: 'Allow AI-generated Site Context before running generation.',
						setup_target: 'site_context_consent',
						provider: 'sentient_managed',
						model: 'openai/gpt-5.5'
					}
				})
			});
		});

		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });
		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		await page.getByRole('button', { name: 'Apply Balanced' }).click();

		await expect(page.getByTestId('privacy-setup-apply-error')).toContainText(
			'Settings could not be saved on this site.'
		);
		await expect(page.getByTestId('privacy-setup-assistant')).toBeVisible();
		await expect(page.getByRole('button', { name: 'Apply Balanced' })).toBeEnabled();
		expect(settingsPutCount).toBe(1);
		expect(siteContextPutCount).toBe(0);
	});
});
