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

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
			throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
		});

		await page.route('**/wp-json/sentient-forms/v1/settings', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					enable_logging: false,
					execution_global_disabled: false,
					execution_provider_disabled: {},
					execution_event_retention_days: 90,
					delete_data_on_uninstall: true,
					store_full_ai_outputs: false,
					privacy_setup_profile: 'balanced',
					privacy_setup_completed_at: '2026-04-21T00:00:00Z'
				})
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, data: [] })
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/local/providers/openrouter/models**', (route) =>
			route.fulfill({
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
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/models**', (route) =>
			route.fulfill({
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
			})
		);
	});

	test('shows empty template when no context is configured', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/site-context**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(null)
			})
		);

		await page.goto('/#/settings/context', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Site Context' })).toBeVisible();
		await expect(page.getByTestId('site-context-setup-panel')).toBeVisible();
		await expect(page.getByText('Empty')).toBeVisible();
		await expect(page.getByTestId('site-context-textarea')).toBeVisible();
		await expect(page.getByTestId('site-context-generate-now')).toBeDisabled();
	});

	test('shows empty template when backend returns an explicit empty context envelope', async ({
		page
	}) => {
		await page.route('**/wp-json/sentient-forms/v1/site-context**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					context: null,
					settings: {
						consent_status: 'granted',
						consented_at: '2026-04-21T00:00:00Z',
						declined_at: null,
						auto_refresh_enabled: true,
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
			})
		);

		await page.goto('/#/settings/context', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Site Context' })).toBeVisible();
		await expect(page.getByTestId('site-context-empty-consented-warning')).toBeVisible();
		await expect(page.getByTestId('site-context-generation-consent')).toHaveAttribute(
			'aria-checked',
			'true'
		);
		await expect(page.getByTestId('site-context-generate-now')).toBeDisabled();
		await expect(page.getByTestId('site-context-generate-disabled-help')).toHaveCount(0);
		const unavailableTrigger = page.getByTestId('site-context-generate-unavailable-trigger');
		await expect(unavailableTrigger).toBeVisible();
		await expect(unavailableTrigger).toBeEnabled();
		await expect(unavailableTrigger).toHaveAttribute('aria-haspopup', 'dialog');
		await expect(unavailableTrigger).toHaveAttribute('aria-expanded', 'false');
		await unavailableTrigger.click();
		await expect(unavailableTrigger).toHaveAttribute('aria-expanded', 'true');
		await expect(page.getByTestId('site-context-generate-unavailable-popover')).toBeVisible();
		await expect(page.getByText('Generate now is unavailable')).toBeVisible();
		await expect(page.getByTestId('site-context-generate-unavailable-message')).toContainText(
			'Connect Sentient Forms Managed Service billing'
		);
		await expect(page.getByTestId('site-context-generate-setup-link')).toContainText(
			'Open billing'
		);
		await page.keyboard.press('Escape');
		await expect(page.getByTestId('site-context-generate-unavailable-popover')).toHaveCount(0);
		await expect(unavailableTrigger).toBeFocused();
		await expect(page.getByTestId('site-context-notices')).toBeVisible();
	});

	test('enables Generate Now only when backend generation access is ready', async ({ page }) => {
		let generateRequests = 0;
		let pollRequests = 0;

		const readyEmptyStatus = {
			context: null,
			settings: {
				consent_status: 'granted',
				consented_at: '2026-05-28T00:00:00Z',
				declined_at: null,
				auto_refresh_enabled: false,
				auto_refresh_days: 30,
				next_refresh_at: null,
				last_generated_at: null,
				last_error: null,
				generation_model_selection: {
					primary: 'openai/gpt-5.5',
					is_preset: false,
					provider: 'openrouter',
					credential_id: 12
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
				model: 'openai/gpt-5.5',
				credential_id: 12
			},
			generation_job: null
		};

		const generatedStatus = {
			...readyEmptyStatus,
			context: {
				id: 'ctx-generated',
				license_id: 'local',
				summary_text: 'Generated paid-route context.',
				source: 'ai_generated',
				auto_include: true,
				pii_ack: true,
				free_refresh_available: true,
				next_free_refresh_at: null,
				created_at: '2026-05-28T00:00:00Z',
				updated_at: '2026-05-28T00:00:00Z'
			},
			settings: {
				...readyEmptyStatus.settings,
				last_generated_at: '2026-05-28T00:00:00Z'
			},
			has_context: true,
			is_empty: false,
			status: 'ready',
			generation_job: {
				id: 'job-site-context-1',
				status: 'succeeded',
				requested_at: '2026-05-28T00:00:00Z',
				started_at: '2026-05-28T00:00:01Z',
				finished_at: '2026-05-28T00:00:04Z',
				error: null,
				model: 'openai/gpt-5.5',
				provider: 'openrouter',
				tools: ['web_search']
			}
		};

		await page.route('**/wp-json/sentient-forms/v1/site-context**', (route) => {
			const request = route.request();
			if (request.method() === 'POST' && request.url().endsWith('/site-context/generate')) {
				generateRequests += 1;
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						...readyEmptyStatus,
						generation_job: {
							id: 'job-site-context-1',
							status: 'queued',
							requested_at: '2026-05-28T00:00:00Z',
							started_at: null,
							finished_at: null,
							error: null,
							model: 'openai/gpt-5.5',
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
								id: 'job-site-context-1',
								status: 'running',
								requested_at: '2026-05-28T00:00:00Z',
								started_at: '2026-05-28T00:00:01Z',
								finished_at: null,
								error: null,
								model: 'openai/gpt-5.5',
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

		await page.goto('/#/settings/context', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('site-context-generate-disabled-help')).toHaveCount(0);
		await expect(page.getByTestId('site-context-generate-unavailable-trigger')).toHaveCount(0);
		await expect(page.getByTestId('site-context-generate-now')).toBeEnabled();
		await page.getByTestId('site-context-generate-now').click();

		await expect.poll(() => generateRequests).toBe(1);
		await expect(
			page.getByText('Site Context generation is running in the background.')
		).toBeVisible();
		await expect(page.getByTestId('site-context-generate-now')).toBeDisabled();
		await expect.poll(() => pollRequests, { timeout: 8_000 }).toBeGreaterThanOrEqual(1);
		await page
			.getByTestId('site-context-textarea')
			.fill('Unsaved local edit while generation runs.');
		await expect.poll(() => pollRequests, { timeout: 15_000 }).toBeGreaterThanOrEqual(3);
		await expect(page.getByTestId('site-context-textarea')).toHaveValue(
			'Unsaved local edit while generation runs.'
		);
		await expect(page.getByText('Site Context generated.')).toBeVisible();
	});

	test('stops background generation polling after leaving the Site Context page', async ({
		page
	}) => {
		let generateRequests = 0;
		let pollRequests = 0;
		let releaseFirstPoll: (() => void) | null = null;
		let resolveFirstPollStarted: () => void = () => {};
		const firstPollStarted = new Promise<void>((resolve) => {
			resolveFirstPollStarted = resolve;
		});

		const readyEmptyStatus = {
			context: null,
			settings: {
				consent_status: 'granted',
				consented_at: '2026-05-28T00:00:00Z',
				declined_at: null,
				auto_refresh_enabled: false,
				auto_refresh_days: 30,
				next_refresh_at: null,
				last_generated_at: null,
				last_error: null,
				generation_model_selection: {
					primary: 'openai/gpt-5.5',
					is_preset: false,
					provider: 'openrouter',
					credential_id: 12
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
				model: 'openai/gpt-5.5',
				credential_id: 12
			},
			generation_job: null
		};

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
							id: 'job-site-context-navigation',
							status: 'queued',
							requested_at: '2026-05-28T00:00:00Z',
							started_at: null,
							finished_at: null,
							error: null,
							model: 'openai/gpt-5.5',
							provider: 'openrouter',
							tools: ['web_search']
						}
					})
				});
			}

			if (request.method() === 'GET' && generateRequests > 0) {
				pollRequests += 1;
				if (pollRequests === 1) {
					resolveFirstPollStarted();
					await new Promise<void>((resolve) => {
						releaseFirstPoll = resolve;
					});
				}

				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						...readyEmptyStatus,
						generation_job: {
							id: 'job-site-context-navigation',
							status: 'running',
							requested_at: '2026-05-28T00:00:00Z',
							started_at: '2026-05-28T00:00:01Z',
							finished_at: null,
							error: null,
							model: 'openai/gpt-5.5',
							provider: 'openrouter',
							tools: ['web_search']
						}
					})
				});
			}

			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(readyEmptyStatus)
			});
		});

		await page.goto('/#/settings/context', { waitUntil: 'networkidle' });
		await page.getByTestId('site-context-generate-now').click();
		await expect.poll(() => generateRequests).toBe(1);
		await expect(
			page.getByText('Site Context generation is running in the background.')
		).toBeVisible();
		await firstPollStarted;

		await page.getByRole('link', { name: 'Providers' }).click();
		await expect(page).toHaveURL(/\/providers$/);
		releaseFirstPoll?.();

		await expect(
			page.getByText('Site Context generation is running in the background.')
		).toHaveCount(0);
		await page.waitForTimeout(3_500);
		expect(pollRequests).toBe(1);
	});

	test('clears unsupported saved OpenRouter server-tool settings from the model controls', async ({
		page
	}) => {
		await page.unroute('**/wp-json/sentient-forms/v1/models**');
		await page.route('**/wp-json/sentient-forms/v1/models**', (route) =>
			route.fulfill({
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
									server_tools: {
										web_search: true,
										web_fetch: false,
										datetime: false
									},
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
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/site-context**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					context: null,
					settings: {
						consent_status: 'granted',
						consented_at: '2026-05-28T00:00:00Z',
						declined_at: null,
						auto_refresh_enabled: false,
						auto_refresh_days: 30,
						next_refresh_at: null,
						last_generated_at: null,
						last_error: null,
						generation_model_selection: {
							primary: 'openai/gpt-5.5',
							is_preset: false,
							provider: 'openrouter',
							credential_id: 12,
							tools: {
								tool_choice: 'auto',
								web_search: { mode: 'required', max_results: 5 },
								web_fetch: { mode: 'required' },
								datetime: { mode: 'required' }
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
						model: 'openai/gpt-5.5',
						credential_id: 12
					}
				})
			})
		);

		await page.goto('/#/settings/context', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('model-summary-tool-choice')).toHaveValue('auto');
		await expect(page.getByTestId('model-summary-tool-choice')).toBeEnabled();
		await expect(page.getByTestId('model-summary-web-search')).toHaveValue('required');
		await expect(page.getByTestId('model-summary-web-search')).toBeEnabled();
		await expect(page.getByLabel('Search results per call', { exact: true })).toHaveValue('5');
		await expect(page.getByLabel('Search results per call', { exact: true })).toHaveAttribute(
			'max',
			'5'
		);
		await expect(page.getByTestId('model-summary-web-fetch')).toHaveValue('inherit');
		await expect(page.getByTestId('model-summary-web-fetch')).toBeDisabled();
		await expect(page.getByTestId('model-summary-datetime')).toHaveValue('inherit');
		await expect(page.getByTestId('model-summary-datetime')).toBeDisabled();
		await expect(page.getByTestId('site-context-save')).toBeEnabled();
	});

	test('keeps a saved paid OpenRouter alias when the model tools pane opens before credentials load', async ({
		page
	}) => {
		let capturedSavePayload: unknown = null;

		await page.unroute('**/wp-json/sentient-forms/v1/local/providers/credentials**');
		await page.route(
			'**/wp-json/sentient-forms/v1/local/providers/credentials**',
			async (route) => {
				await new Promise((resolve) => setTimeout(resolve, 250));
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						success: true,
						data: [
							{
								id: 1,
								provider: 'openrouter',
								label: 'Production OpenRouter key',
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
			}
		);

		await page.unroute('**/wp-json/sentient-forms/v1/models**');
		await page.route('**/wp-json/sentient-forms/v1/models**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						models: [
							{
								id: 'nvidia/nemotron-3-super-120b-a12b:free',
								display_name: 'NVIDIA: Nemotron 3 Super (free)',
								provider: 'openrouter',
								speed_tier: 'balanced',
								cost_tier: 'free',
								capabilities: {
									reasoning: false,
									tools: false,
									structured: true,
									web_search: false,
									server_tools: {
										web_search: false,
										web_fetch: false,
										datetime: false
									}
								},
								context_window: 128000,
								tags: ['free'],
								supported_parameters: []
							},
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
						presets: [
							{
								code: 'sf_free',
								display_name: 'Free',
								category: 'local',
								resolved_model_id: 'nvidia/nemotron-3-super-120b-a12b:free',
								auto_upgrade: true
							},
							{
								code: 'sf_research',
								display_name: 'Research',
								category: 'local',
								resolved_model_id: '~google/gemini-pro-latest',
								auto_upgrade: true
							}
						]
					}
				})
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/site-context**', async (route) => {
			const request = route.request();
			if (request.method() === 'PUT') {
				capturedSavePayload = request.postDataJSON();
				return route.fulfill({
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
							generation_model_selection:
								(capturedSavePayload as { generation_model_selection?: unknown })
									.generation_model_selection ?? null
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
							credential_id: 1
						}
					})
				});
			}

			return route.fulfill({
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
							primary: '~google/gemini-pro-latest',
							is_preset: false,
							provider: 'openrouter',
							credential_id: 1,
							reasoning: 'xhigh',
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
						credential_id: 1
					}
				})
			});
		});

		await page.goto('/#/settings/context', { waitUntil: 'domcontentloaded' });
		await expect(page.getByTestId('site-context-generate-now')).toBeEnabled();
		await expect(page.getByTestId('site-context-save')).toBeDisabled();

		await page.getByTestId('site-context-model-tools').locator('summary').click();
		await expect(page.getByTestId('model-selector-summary')).toContainText(
			'Google: Gemini 3.1 Pro Preview'
		);
		await expect(page.getByTestId('model-selector-summary')).toContainText(
			'~google/gemini-pro-latest'
		);
		await expect(page.getByTestId('model-selector-summary')).not.toContainText('Nemotron');
		await expect(page.getByTestId('model-summary-web-search')).toHaveValue('required');
		await expect(page.getByTestId('site-context-save')).toBeDisabled();
		await expect(page.getByTestId('site-context-generate-now')).toBeEnabled();

		await page.getByTestId('site-context-textarea').fill('Manual context after opening tools.');
		await page.getByTestId('site-context-save').click();

		await expect.poll(() => (capturedSavePayload ? 'saved' : 'pending')).toBe('saved');
		expect(
			(capturedSavePayload as { generation_model_selection?: { primary?: string } })
				.generation_model_selection?.primary
		).toBe('~google/gemini-pro-latest');
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

		await page.goto('/#/settings/context', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('site-context-error-state')).toBeVisible();
		await page
			.getByTestId('site-context-error-state')
			.getByRole('button', { name: 'Retry' })
			.click();

		await expect(page.getByTestId('site-context-error-state')).toHaveCount(0);
		await expect(page.getByTestId('site-context-textarea')).toHaveValue('Context text');
		expect(attempts).toBeGreaterThanOrEqual(2);
	});
});
