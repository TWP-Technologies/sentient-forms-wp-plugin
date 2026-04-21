import { describe, it, expect, afterEach, vi } from 'vitest';
import { SentientFormsApiClient, ApiClientError } from '$lib/api/client';
import type { LicenseActivationRequest } from '$lib/api/types';
import { notifications } from '$lib/stores/notifications';

const baseUrl = 'https://example.test/wp-json/sentient-forms/v1/';

const mockFetch = vi.fn();
const client = new SentientFormsApiClient({
	baseUrl,
	fetchImpl: mockFetch,
	getNonce: () => 'nonce'
});

describe('SentientFormsApiClient', () => {
	afterEach(() => {
		vi.restoreAllMocks();
		mockFetch.mockReset();
	});

	it('activates license and normalizes response', async () => {
		const payload: LicenseActivationRequest = {
			licenseKey: 'LIC-123',
			siteUrl: 'https://site.test',
			localSiteIdentifier: 'site-guid'
		};

		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					success: true,
					data: {
						success: true,
						message: 'Activated',
						status: 'active',
						proxy_api_key: 'proxy-123',
						tier: 'starter',
						expiry_date: '2026-01-01',
						license_id: 'lic-1',
						site_id: 'site-1'
					}
				})
		});

		const result = await client.activateLicense(payload, { showNotifications: false });

		expect(mockFetch).toHaveBeenCalledWith(`${baseUrl}license/activate`, expect.objectContaining({
			method: 'POST'
		}));
		expect(result).toEqual({
			success: true,
			message: 'Activated',
			status: 'active',
			proxyApiKey: 'proxy-123',
			tier: 'starter',
			expiryDate: '2026-01-01',
			licenseId: 'lic-1',
			siteId: 'site-1'
		});
	});

	it('unwraps license info envelope', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					success: true,
					data: {
						license_key_masked: 'LIC-****',
						status: 'active',
						proxy_key_present: true,
						expires_at: '2026-01-01',
						last_synced: '2025-10-20 00:00:00',
						tier: 'starter',
						license_id: 'lic-1',
						site_id: 'site-1',
						site_url: 'https://site.test'
					}
				})
		});

		const result = await client.getLicenseInfo({ showNotifications: false });
		expect(result).toEqual({
			license_key_masked: 'LIC-****',
			status: 'active',
			proxy_key_present: true,
			expires_at: '2026-01-01',
			last_synced: '2025-10-20 00:00:00',
			tier: 'starter',
			license_id: 'lic-1',
			site_id: 'site-1',
			site_url: 'https://site.test'
		});
	});

	it('sends a proper form disable payload when toggling per-form active state', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					success: true,
					data: {
						sf_disabled: true,
						message: 'Sentient Forms disabled for this form.'
					}
				})
		});

		const result = await client.toggleFormDisabled('gravity_forms', 42, true, {
			showNotifications: false
		});

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}gravity_forms/forms/42/actions/disable`,
			expect.objectContaining({
				method: 'PUT',
				body: JSON.stringify({ sf_disabled: true })
			})
		);
		expect(result).toEqual({
			sf_disabled: true,
			message: 'Sentient Forms disabled for this form.'
		});
	});

	it('reads local provider credentials from the local-first endpoint', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve([
					{
						id: 7,
						provider: 'openrouter',
						label: 'OpenRouter key',
						auth_mode: 'manual_key',
						constant_name: null,
						status: 'valid',
						status_json: { is_free_tier: true },
						last_validated_at: '2026-04-17T10:00:00Z',
						created_at: '2026-04-17T09:00:00Z',
						updated_at: '2026-04-17T10:00:00Z',
						secret_configured: true
					}
				])
		});

		const result = await client.getLocalProviderCredentials({ showNotifications: false });

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/credentials`,
			expect.objectContaining({
				credentials: 'same-origin'
			})
		);
		expect(result).toHaveLength(1);
		expect(result[0]).toMatchObject({
			provider: 'openrouter',
			status: 'valid',
			secret_configured: true
		});
	});

	it('deletes a saved local provider credential', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					deleted: true,
					credential: {
						id: 7,
						provider: 'openrouter',
						label: 'OpenRouter key',
						auth_mode: 'manual_key',
						constant_name: null,
						status: 'valid',
						status_json: { is_free_tier: true },
						last_validated_at: '2026-04-17T10:00:00Z',
						created_at: '2026-04-17T09:00:00Z',
						updated_at: '2026-04-17T10:00:00Z',
						secret_configured: true
					}
				})
		});

		const result = await client.deleteLocalProviderCredential(7, { showNotifications: false });

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/credentials/7`,
			expect.objectContaining({
				method: 'DELETE',
				credentials: 'same-origin'
			})
		);
		expect(result).toMatchObject({
			deleted: true,
			credential: {
				id: 7,
				provider: 'openrouter',
				secret_configured: true
			}
		});
	});

	it('validates an OpenRouter key with disclosure acceptance', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					provider: 'openrouter',
					status: 'valid',
					credential_id: 9,
					key_status: { label: 'test key', is_free_tier: true },
					consent_recorded: true,
					consent_id: 11
				})
		});

		const result = await client.validateOpenRouterKey(
			{
				api_key: 'sk-or-test',
				label: 'Test key',
				save: true,
				disclosure_version: '2026-04-local-first-openrouter-v1',
				accepted_external_service_terms: true
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/openrouter/validate`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					api_key: 'sk-or-test',
					label: 'Test key',
					save: true,
					disclosure_version: '2026-04-local-first-openrouter-v1',
					accepted_external_service_terms: true
				})
			})
		);
		expect(result).toMatchObject({
			status: 'valid',
			credential_id: 9,
			consent_recorded: true
		});
	});

	it('sets up the Sentient managed proxy credential with disclosure acceptance', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					provider: 'sentient_managed',
					status: 'valid',
					credential_id: 77,
					credential: {
						id: 77,
						provider: 'sentient_managed',
						label: 'Sentient managed proxy',
						auth_mode: 'sentient_proxy',
						constant_name: null,
						status: 'valid',
						status_json: { proxy_key_present: true },
						last_validated_at: '2026-04-19T10:00:00Z',
						created_at: '2026-04-19T09:00:00Z',
						updated_at: '2026-04-19T10:00:00Z',
						secret_configured: true
					},
					consent_recorded: true,
					consent_id: 31,
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

		const result = await client.setupSentientManagedProvider(
			{
				label: 'Sentient managed proxy',
				disclosure_version: '2026-04-sentient-managed-proxy-v1',
				accepted_external_service_terms: true
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/sentient-managed/setup`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					label: 'Sentient managed proxy',
					disclosure_version: '2026-04-sentient-managed-proxy-v1',
					accepted_external_service_terms: true
				})
			})
		);
		expect(result).toMatchObject({
			provider: 'sentient_managed',
			status: 'valid',
			credential_id: 77,
			consent_recorded: true,
			billing_boundary: {
				direct_openrouter_billed_by_sentient: false,
				managed_proxy_billed_by_sentient: true
			}
		});
	});

	it('reads cached OpenRouter model metadata from the local provider endpoint', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					provider: 'openrouter',
					source: 'local_cache',
					total_cached: 2,
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
							pricing: { prompt: '0', completion: '0', request: '0' },
							fetched_at: '2026-04-18 12:00:00',
							expires_at: '2026-04-19 12:00:00',
							stale: false
						}
					]
				})
		});

		const result = await client.getOpenRouterModels(
			{ freeOnly: true, limit: 25 },
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/openrouter/models?free_only=true&limit=25`,
			expect.objectContaining({
				credentials: 'same-origin'
			})
		);
		expect(result.free_count).toBe(1);
		expect(result.models[0].id).toBe('openai/gpt-oss-20b:free');
	});

	it('refreshes OpenRouter model metadata with disclosure acceptance', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 200,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					provider: 'openrouter',
					source: 'local_cache',
					total_cached: 2,
					total_returned: 2,
					free_count: 1,
					stale_count: 0,
					models: [],
					consent_recorded: true,
					consent_id: 12,
					stored: 2
				})
		});

		const result = await client.refreshOpenRouterModels(
			{
				disclosure_version: '2026-04-local-first-openrouter-v1',
				accepted_external_service_terms: true,
				output_modalities: 'text'
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/providers/openrouter/models/refresh`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					disclosure_version: '2026-04-local-first-openrouter-v1',
					accepted_external_service_terms: true,
					output_modalities: 'text'
				})
			})
		);
		expect(result).toMatchObject({
			consent_recorded: true,
			stored: 2
		});
	});

	it('creates a local custom action in WordPress-local tables', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 201,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					id: 17,
					external_id: null,
					template_id: null,
					code: 'local_openrouter_summary',
					display_name: 'Local OpenRouter summary',
					definition_json: { prompt_template: 'Summarize {{name}}.' },
					model_selection_json: {
						provider: 'openrouter',
						model: 'openrouter/auto',
						credential_id: 9
					},
					status: 'active',
					created_at: '2026-04-17T10:00:00Z',
					updated_at: '2026-04-17T10:00:00Z'
				})
		});

		const result = await client.createLocalCustomAction(
			{
				code: 'local_openrouter_summary',
				display_name: 'Local OpenRouter summary',
				definition_json: { prompt_template: 'Summarize {{name}}.' },
				model_selection_json: {
					provider: 'openrouter',
					model: 'openrouter/auto',
					credential_id: 9
				},
				status: 'active'
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/custom-actions`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					code: 'local_openrouter_summary',
					display_name: 'Local OpenRouter summary',
					definition_json: { prompt_template: 'Summarize {{name}}.' },
					model_selection_json: {
						provider: 'openrouter',
						model: 'openrouter/auto',
						credential_id: 9
					},
					status: 'active'
				})
			})
		);
		expect(result).toMatchObject({
			id: 17,
			code: 'local_openrouter_summary',
			status: 'active'
		});
	});

	it('creates a local form mapping in WordPress-local tables', async () => {
		mockFetch.mockResolvedValue({
			ok: true,
			status: 201,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					id: 23,
					external_id: null,
					form_source: 'gravity_forms',
					form_id: '42',
					hook: 'gform_after_submission',
					action_kind: 'custom_action',
					action_id: 17,
					conditions_json: null,
					input_bindings_json: { name: '1', email: '2' },
					execution_mode: 'sync',
					effect_mapping_json: {
						store_result: true,
						meta: { sentient_forms_summary: 'structured.summary' }
					},
					enabled: true,
					created_at: '2026-04-17T10:00:00Z',
					updated_at: '2026-04-17T10:00:00Z'
				})
		});

		const result = await client.createLocalFormMapping(
			{
				form_source: 'gravity_forms',
				form_id: 42,
				hook: 'gform_after_submission',
				action_kind: 'custom_action',
				action_id: 17,
				input_bindings_json: { name: '1', email: '2' },
				execution_mode: 'sync',
				effect_mapping_json: {
					store_result: true,
					meta: { sentient_forms_summary: 'structured.summary' }
				},
				enabled: true
			},
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/form-mappings`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					form_source: 'gravity_forms',
					form_id: 42,
					hook: 'gform_after_submission',
					action_kind: 'custom_action',
					action_id: 17,
					input_bindings_json: { name: '1', email: '2' },
					execution_mode: 'sync',
					effect_mapping_json: {
						store_result: true,
						meta: { sentient_forms_summary: 'structured.summary' }
					},
					enabled: true
				})
			})
		);
		expect(result).toMatchObject({
			id: 23,
			form_source: 'gravity_forms',
			action_id: 17,
			enabled: true
		});
	});

	it('previews a local migration import bundle', async () => {
		const bundle = {
			schema_version: 'sentient_forms_cps_export_v1',
			action_templates: []
		};
		mockFetch.mockResolvedValue({
			ok: true,
			status: 201,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					run_id: 31,
					status: 'dry_run_complete',
					dry_run: true,
					report: {
						schema_version: 'sentient_forms_cps_export_v1',
						source: 'cps_export',
						source_version: 'cps-dev-export-1',
						generated_at: '2026-04-19T21:00:00+00:00',
						exported_at: '2026-04-19T20:00:00+00:00',
						ready_to_import: true,
						counts: { action_templates: 0 },
						changes: { total_writes: 0 },
						conflicts: [],
						warnings: [],
						mapping: {}
					}
				})
		});

		const result = await client.createLocalMigrationImportDryRun(
			{ bundle },
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/migration/import/dry-run`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({ bundle })
			})
		);
		expect(result).toMatchObject({
			run_id: 31,
			dry_run: true
		});
	});

	it('applies a local migration import bundle', async () => {
		const bundle = {
			schema_version: 'sentient_forms_cps_export_v1',
			action_templates: []
		};
		mockFetch.mockResolvedValue({
			ok: true,
			status: 201,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () =>
				Promise.resolve({
					run_id: 32,
					status: 'completed',
					dry_run: false,
					report: {
						schema_version: 'sentient_forms_cps_export_v1',
						source: 'cps_export',
						source_version: 'cps-dev-export-1',
						generated_at: '2026-04-19T21:00:00+00:00',
						exported_at: '2026-04-19T20:00:00+00:00',
						ready_to_import: true,
						counts: { action_templates: 0 },
						changes: { total_writes: 0 },
						conflicts: [],
						warnings: [],
						mapping: {}
					},
					applied: { total: 0 }
				})
		});

		const result = await client.runLocalMigrationImportApply(
			{ bundle },
			{ showNotifications: false }
		);

		expect(mockFetch).toHaveBeenCalledWith(
			`${baseUrl}local/migration/import/apply`,
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({ bundle })
			})
		);
		expect(result).toMatchObject({
			run_id: 32,
			dry_run: false,
			applied: { total: 0 }
		});
	});

	it('surfaces ApiClientError with code and notification', async () => {
		const notifySpy = vi.spyOn(notifications, 'error');

		mockFetch.mockResolvedValue({
			ok: false,
			status: 400,
			headers: new Headers({ 'content-type': 'application/json' }),
			json: () => Promise.resolve({ error_code: 'invalid_key', message: 'Invalid license' })
		});

		await expect(
			client.activateLicense(
				{ licenseKey: 'bad', siteUrl: 'https://site.test', localSiteIdentifier: 'site-guid' },
				{ showNotifications: true }
			)
		).rejects.toMatchObject({ code: 'invalid_key' });

		expect(notifySpy).toHaveBeenCalledWith('Invalid license');
	});

	it('coerces unknown rejection into ApiClientError', async () => {
		mockFetch.mockRejectedValue(new Error('Network down'));

		await expect(
			client.getLicenseInfo({ showNotifications: false })
		).rejects.toBeInstanceOf(ApiClientError);
	});
});
