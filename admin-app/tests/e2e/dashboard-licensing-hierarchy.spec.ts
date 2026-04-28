import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';

test.describe('Dashboard and Licensing hierarchy uplift', () => {
	test.beforeEach(async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		await page.route('**/wp-json/sentient-forms/v1/license/billing-state**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					provider: 'stripe',
					customer_id: 'cus_test_123',
					subscription: {
						provider_subscription_id: 'sub_test_123',
						status: 'active',
						quantity: 1,
						cancel_at_period_end: false,
						current_period_start: '2030-01-01T00:00:00Z',
						current_period_end: '2030-02-01T00:00:00Z',
						trial_end: null,
						provider_price_id: 'price_test_starter'
					},
					credits: {
						current_balance: 100,
						tier_quota: 100,
						ledger_delta: 0,
						top_up_available: 0
					},
					allocation: {
						seat_quantity: 1,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_x_quantity_v1'
					}
				})
			})
		);
	});

	test('dashboard leads with managed service and self-managed OpenRouter state', async ({ page }) => {
		let licenseRequests = 0;
		let creditRequests = 0;

		await page.route('**/wp-json/sentient-forms/v1/license**', (route) => {
			licenseRequests += 1;
			return route.fulfill({
				status: 418,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'dashboard should not require license state' })
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
			creditRequests += 1;
			return route.fulfill({
				status: 418,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'dashboard should not require credits' })
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify([
					{
						id: 1,
						provider: 'openrouter',
						label: 'OpenRouter free key',
						auth_mode: 'manual_key',
						constant_name: null,
						status: 'valid',
						status_json: { is_free_tier: true },
						last_validated_at: '2030-01-05T10:00:00Z',
						created_at: '2030-01-05T09:00:00Z',
						updated_at: '2030-01-05T10:00:00Z',
						secret_configured: true
					}
				])
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/local/action-templates**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify([
					{ id: 1, code: 'spam_detection', display_name: 'Spam detection', is_active: true },
					{ id: 2, code: 'entry_summary', display_name: 'Entry summary', is_active: true }
				])
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/local/custom-actions**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify([
					{ id: 3, code: 'route_quote', display_name: 'Route quote', status: 'active' }
				])
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/local/execution-events**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify([
					{
						id: 4,
						execution_request_id: 'run_1',
						provider: 'openrouter',
						model: 'openrouter/free-model',
						status: 'succeeded',
						created_at: '2030-01-05T10:00:00Z'
					}
				])
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/local/support-bundle**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					retention: { event_retention_days: 90 },
					local_tables: {
						sentient_execution_events: 1,
						sentient_provider_credentials: 1
					}
				})
			})
		);

		await page.goto('/#/dashboard', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'Sentient Forms workspace' })).toBeVisible();
		await expect(page.getByTestId('dashboard-local-first-summary')).toContainText(
			'Managed service not connected'
		);
		await expect(page.getByTestId('dashboard-local-first-summary')).toContainText(
			'Direct OpenRouter optional'
		);
		await expect(page.getByTestId('dashboard-provider-count')).toContainText('1');
		await expect(page.getByTestId('dashboard-template-count')).toContainText('2');
		await expect(page.getByTestId('dashboard-custom-action-count')).toContainText('1');
		await expect(page.getByTestId('dashboard-execution-count')).toContainText('1');
		await expect(page.getByTestId('dashboard-managed-status')).toContainText(
			'Managed service not connected'
		);
		await expect(page.getByTestId('dashboard-openrouter-status')).toContainText(
			'OpenRouter ready'
		);
		await expect(page.getByTestId('dashboard-free-path-card')).toContainText(
			'Try actions with free models first'
		);
		await expect(page.getByText('License health')).toHaveCount(0);
		await expect(page.getByText(/credits remaining/i)).toHaveCount(0);
		expect(licenseRequests).toBe(0);
		expect(creditRequests).toBe(0);
	});

	test('dashboard excludes imported CPS history from local run summaries', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify([])
			})
		);

		for (const endpoint of ['action-templates', 'custom-actions']) {
			await page.route(`**/wp-json/sentient-forms/v1/local/${endpoint}**`, (route) =>
				route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify([])
				})
			);
		}

		await page.route('**/wp-json/sentient-forms/v1/local/execution-events**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify([
					{
						id: 4,
						execution_request_id: 'legacy_run_1',
						provider: 'legacy_cps',
						model: 'gemini-3-flash-preview',
						status: 'succeeded',
						created_at: '2030-01-05T10:00:00Z'
					}
				])
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/local/support-bundle**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					retention: { event_retention_days: 90 },
					local_tables: {
						sentient_execution_events: 1,
						sentient_provider_credentials: 0
					}
				})
			})
		);

		await page.goto('/#/dashboard', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('dashboard-openrouter-status')).toContainText(
			'OpenRouter not connected'
		);
		await expect(page.getByTestId('dashboard-openrouter-status')).toContainText('Not connected');
		await expect(page.getByTestId('dashboard-openrouter-status')).not.toContainText('missing');
		await expect(page.getByTestId('dashboard-execution-count')).toContainText('0');
		await expect(page.getByTestId('dashboard-recent-runs-card')).toContainText('No local runs yet');
		await expect(page.getByTestId('dashboard-recent-runs-card')).toContainText(
			'Imported CPS history is still available in Action Log'
		);
		await expect(
			page.getByTestId('dashboard-recent-runs-card').getByRole('button', {
				name: 'Open action log'
			})
		).toBeVisible();
	});

	test('dashboard surfaces local endpoint failures without reverting to license copy', async ({
		page
	}) => {
		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials**', (route) =>
			route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'provider storage unavailable' })
			})
		);

		for (const endpoint of [
			'action-templates',
			'custom-actions',
			'execution-events',
			'support-bundle'
		]) {
			await page.route(`**/wp-json/sentient-forms/v1/local/${endpoint}**`, (route) =>
				route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: endpoint === 'support-bundle' ? JSON.stringify({}) : JSON.stringify([])
				})
			);
		}

		await page.route('**/wp-json/sentient-forms/v1/license**', (route) =>
			route.fulfill({
				status: 418,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'dashboard should not require license state' })
			})
		);

		await page.goto('/#/dashboard', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('dashboard-error-state')).toBeVisible();
		await expect(page.getByTestId('dashboard-error-state')).toContainText(
			'Local workspace data is partially unavailable'
		);
		await expect(page.getByText('License health')).toHaveCount(0);
	});

	test('providers validates OpenRouter with disclosure acceptance', async ({ page }) => {
		let validatePayload: Record<string, unknown> | null = null;

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify([])
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

		await page.route(
			'**/wp-json/sentient-forms/v1/local/providers/openrouter/validate**',
			async (route) => {
				validatePayload = route.request().postDataJSON() as Record<string, unknown>;
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						provider: 'openrouter',
						status: 'valid',
						credential_id: 12,
						key_status: { label: 'test key', is_free_tier: true },
						consent_recorded: true,
						consent_id: 44
					})
				});
			}
		);

		await page.goto('/#/providers', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'Providers' })).toBeVisible();
		const catalogCard = page.getByTestId('providers-openrouter-model-catalog');
		await expect(catalogCard.getByTestId('providers-model-catalog-disclosure')).toBeVisible();
		await expect(catalogCard.getByTestId('providers-refresh-model-catalog')).toBeDisabled();
		await catalogCard.getByLabel(/refreshing the catalog contacts OpenRouter/).check();
		await expect(catalogCard.getByTestId('providers-refresh-model-catalog')).toBeEnabled();
		const openRouterCard = page.getByTestId('providers-openrouter-form-card');
		await openRouterCard.getByLabel('API key').fill('sk-or-test');
		await openRouterCard.getByLabel(/I understand OpenRouter receives/).check();
		await openRouterCard.getByRole('button', { name: 'Validate key' }).click();

		await expect(page.getByTestId('providers-openrouter-validation-result')).toContainText('Ready');
		await expect(page.getByTestId('providers-openrouter-validation-result')).toContainText(
			'Saved credential #12'
		);
		expect(validatePayload).toMatchObject({
			api_key: 'sk-or-test',
			save: true,
			disclosure_version: '2026-04-local-first-openrouter-v1',
			accepted_external_service_terms: true
		});
	});

	test('providers enables Sentient Forms managed service with disclosure acceptance', async ({ page }) => {
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

		const managedCredential = {
			id: 77,
			provider: 'sentient_managed',
			label: 'Primary managed service',
			auth_mode: 'sentient_proxy',
			constant_name: null,
			status: 'valid',
			status_json: {
				license_id: 'license-managed-test',
				site_id: 'site-managed-test',
				proxy_key_present: true
			},
			last_validated_at: '2030-01-05T10:00:00Z',
			created_at: '2030-01-05T09:00:00Z',
			updated_at: '2030-01-05T10:00:00Z',
			secret_configured: true
		};
		let credentials: Array<Record<string, unknown>> = [];
		let setupPayload: Record<string, unknown> | null = null;

		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(credentials)
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

		await page.route(
			'**/wp-json/sentient-forms/v1/local/providers/sentient-managed/setup**',
			async (route) => {
				setupPayload = route.request().postDataJSON() as Record<string, unknown>;
				credentials = [managedCredential];

				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						provider: 'sentient_managed',
						status: 'valid',
						credential_id: 77,
						credential: managedCredential,
						consent_recorded: true,
						consent_id: 88,
						account: {
							status: 'active',
							license_id: 'license-managed-test',
							site_id: 'site-managed-test',
							local_site_identifier: 'local-managed-test',
							proxy_key_present: true,
							credential_ready: true
						},
						billing_boundary: {
							direct_openrouter_billed_by_sentient: false,
							managed_proxy_billed_by_sentient: true
						}
					})
				});
			}
		);

		await page.goto('/#/providers', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('providers-managed-summary')).toContainText(
			'Managed account ready'
		);
		await page.locator('#sentient-managed-label').fill('Primary managed service');
		await page
			.getByLabel(/I understand Sentient Forms receives the rendered prompt/)
			.check();
		await page.getByRole('button', { name: 'Enable managed service' }).click();

		await expect(page.getByTestId('providers-managed-setup-result')).toContainText('Ready');
		await expect(page.getByTestId('providers-managed-setup-result')).toContainText(
			'Managed credential #77'
		);
		await expect(page.getByTestId('providers-managed-list-card')).toContainText(
			'Primary managed service'
		);
		expect(setupPayload).toMatchObject({
			label: 'Primary managed service',
			disclosure_version: '2026-04-sentient-managed-proxy-v1',
			accepted_external_service_terms: true
		});
	});

	test('providers points local action setup to the Actions builder', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify([
					{
						id: 42,
						provider: 'openrouter',
						label: 'OpenRouter ready key',
						auth_mode: 'manual_key',
						constant_name: null,
						status: 'valid',
						status_json: null,
						last_validated_at: '2030-01-05T10:00:00Z',
						created_at: '2030-01-05T09:00:00Z',
						updated_at: '2030-01-05T10:00:00Z',
						secret_configured: true
					}
				])
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/local/providers/openrouter/models**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					provider: 'openrouter',
					source: 'local_cache',
					total_cached: 1,
					total_returned: 1,
					free_count: 1,
					stale_count: 0,
					models: [
						{
							id: 'openai/gpt-oss-20b:free',
							name: 'OpenAI: GPT OSS 20B (free)',
							free: true,
							context_length: 131072,
							input_modalities: ['text'],
							output_modalities: ['text'],
							supported_parameters: ['response_format'],
							pricing: { prompt: '0', completion: '0' },
							fetched_at: '2030-01-05T10:00:00Z',
							expires_at: '2030-01-06T10:00:00Z',
							stale: false
						}
					]
				})
			})
		);

		await page.goto('/#/providers', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('providers-actions-builder-redirect-card')).toContainText(
			'Actions owns setup'
		);
		await expect(page.getByTestId('providers-actions-builder-redirect-card')).toContainText(
			'Choose a form in Actions'
		);
		await expect(page.getByTestId('providers-open-actions')).toBeVisible();
		await expect(page.getByTestId('local-setup-model-selector')).toHaveCount(0);
	});

	test('providers explains limited OpenRouter keys with remediation copy', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify([
					{
						id: 31,
						provider: 'openrouter',
						label: 'OpenRouter limited key',
						auth_mode: 'manual_key',
						constant_name: null,
						status: 'limited',
						status_json: {
							last_error_code: 'openrouter_request_failed',
							last_error_message: 'OpenRouter request failed with status 402.',
							http_status: 402
						},
						last_validated_at: '2030-01-05T10:00:00Z',
						created_at: '2030-01-05T09:00:00Z',
						updated_at: '2030-01-05T10:00:00Z',
						secret_configured: true
					}
				])
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

		await page.goto('/#/providers', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('providers-openrouter-credential')).toContainText('Limited');
		await expect(page.getByTestId('providers-openrouter-credential-status-detail')).toContainText(
			'OpenRouter reported insufficient credits'
		);
		await expect(page.getByTestId('providers-openrouter-credential-status-detail')).toContainText(
			'free or available model'
		);
		await expect(page.getByTestId('providers-actions-builder-no-credential')).toContainText(
			'OpenRouter key needs attention'
		);
		await expect(page.getByTestId('providers-actions-builder-no-credential')).toContainText(
			'OpenRouter reported insufficient credits'
		);
	});

	test('licensing active screen leads with status, tier, credits, and reset timing', async ({
		page
	}) => {
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

		await page.route('**/wp-json/sentient-forms/v1/license/billing-state**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'active',
					provider: 'stripe',
					plan: {
						code: 'starter',
						display_name: 'Starter',
						monthly_credit_quota: 100
					},
					credits: {
						current_balance: 8,
						tier_quota: 100,
						ledger_delta: 0,
						top_up_available: 0
					},
					allocation: {
						seat_quantity: 1,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_x_quantity_v1'
					}
				})
			})
		);

		await page.goto('/#/licensing', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'Managed service' })).toBeVisible();
		await expect(page.getByTestId('licensing-overview-card')).toBeVisible();
		await expect(page.getByTestId('licensing-status-badge')).toContainText('active');
		await expect(page.getByTestId('licensing-credits-headline')).toContainText(
			'Low managed credits: 8 / 100'
		);
		await expect(page.getByTestId('licensing-credit-severity')).toContainText('Low');
		await expect(page.getByTestId('licensing-reset-summary')).toContainText('Resets');
		await expect(page.getByTestId('licensing-details-status')).toContainText('active');
		await expect(page.getByText('Tier: Starter')).toBeVisible();
		await expect(page.getByTestId('licensing-quota-cta-callout')).toBeVisible();
		await expect(page.getByTestId('licensing-quota-cta-button')).toBeEnabled();
		await expect(page.getByTestId('licensing-quota-cta-reason')).toContainText(
			'Jump to the billing section to review managed usage and plan options.'
		);
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

		await page.route('**/wp-json/sentient-forms/v1/license/billing-state**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ status: 'inactive', credits: null, plan: null })
			})
		);

		await page.goto('/#/licensing', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'Activate managed service' })).toBeVisible();
		await expect(page.getByLabel('License key')).toBeVisible();
		await expect(page.getByRole('button', { name: 'Activate', exact: true })).toBeVisible();
	});
});
