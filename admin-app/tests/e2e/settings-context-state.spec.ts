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

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, data: [] })
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
		await expect(page.getByTestId('site-context-generate-disabled-help')).toContainText(
			'Connect Sentient Forms Managed Service billing'
		);
		await expect(page.getByTestId('site-context-generate-setup-link')).toContainText(
			'Open billing'
		);
		await expect(page.getByTestId('site-context-notices')).toBeVisible();
	});

	test('enables Generate Now only when backend generation access is ready', async ({ page }) => {
		let generateRequests = 0;

		await page.route('**/wp-json/sentient-forms/v1/site-context**', (route) => {
			const request = route.request();
			if (request.method() === 'POST' && request.url().endsWith('/site-context/generate')) {
				generateRequests += 1;
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
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
							consent_status: 'granted',
							consented_at: '2026-05-28T00:00:00Z',
							declined_at: null,
							auto_refresh_enabled: false,
							auto_refresh_days: 30,
							next_refresh_at: null,
							last_generated_at: '2026-05-28T00:00:00Z',
							last_error: null,
							generation_model_selection: {
								primary: 'openai/gpt-5.5',
								is_preset: false,
								provider: 'openrouter',
								credential_id: 12
							}
						},
						has_context: true,
						is_empty: false,
						is_stale: false,
						stale_after_days: 90,
						status: 'ready',
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
				});
			}

			return route.fulfill({
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
					}
				})
			});
		});

		await page.goto('/#/settings/context', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('site-context-generate-disabled-help')).toHaveCount(0);
		await expect(page.getByTestId('site-context-generate-now')).toBeEnabled();
		await page.getByTestId('site-context-generate-now').click();

		await expect.poll(() => generateRequests).toBe(1);
		await expect(page.getByTestId('site-context-textarea')).toHaveValue(
			'Generated paid-route context.'
		);
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
		await page.getByTestId('site-context-error-state').getByRole('button', { name: 'Retry' }).click();

		await expect(page.getByTestId('site-context-error-state')).toHaveCount(0);
		await expect(page.getByTestId('site-context-textarea')).toHaveValue('Context text');
		expect(attempts).toBeGreaterThanOrEqual(2);
	});
});
