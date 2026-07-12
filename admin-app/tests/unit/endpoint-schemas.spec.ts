import { describe, expect, it } from 'vitest';
import {
	endpointRegistry,
	endpointSchemas,
	providerCredentialResponseSchema
} from '$lib/api/endpoint-schemas';

describe('admin endpoint schema registry', () => {
	it('accepts additive fields in raw billing responses', () => {
		const result = endpointSchemas['billing.portal.create'].parse({
			session_id: 'bps_123',
			portal_url: 'https://billing.example.test/session',
			customer_id: 'cus_123',
			future_field: 'accepted',
			contract_revision: 2
		});

		expect(result).toEqual({
			session_id: 'bps_123',
			portal_url: 'https://billing.example.test/session',
			customer_id: 'cus_123'
		});
	});

	it('rejects unknown fields in security-sensitive migration imports', () => {
		const result = endpointRegistry['migration.import.dryRun'].request.safeParse({
			bundle: { schema_version: 'sentient_forms_cps_export_v1' },
			unexpected_control: 'skip-validation'
		});
		const nestedResult = endpointRegistry['migration.import.dryRun'].request.safeParse({
			bundle: {
				schema_version: 'sentient_forms_cps_export_v1',
				unexpected_collection: []
			}
		});

		expect(result.success).toBe(false);
		expect(nestedResult.success).toBe(false);
		if (!result.success) {
			expect(result.error.issues).toEqual([
				expect.objectContaining({ code: 'unrecognized_keys', keys: ['unexpected_control'] })
			]);
		}
	});

	it('accepts sparse migration entities for server-side dry-run validation', () => {
		const result = endpointRegistry['migration.import.dryRun'].request.safeParse({
			bundle: {
				schema_version: 'sentient_forms_cps_export_v1',
				action_templates: [{ external_id: 'template-1', code: 'spam_triage' }]
			}
		});

		expect(result.success).toBe(true);
	});

	it('normalizes legacy null Site Context responses before validating application state', () => {
		const result = endpointRegistry['siteContext.read'].response.parse(null);

		expect(result).toMatchObject({
			context: null,
			has_context: false,
			is_empty: true,
			status: 'empty'
		});
	});

	it('rejects malformed legacy Site Context primitives through Zod issues', () => {
		const result = endpointRegistry['siteContext.read'].response.safeParse('not-an-object');

		expect(result.success).toBe(false);
	});

	it('discriminates provider credential responses by provider', () => {
		const openRouter = providerCredentialResponseSchema.parse({
			provider: 'openrouter',
			status: 'valid',
			credential_id: 42,
			key_status: { usage: 1 },
			consent_recorded: true,
			consent_id: 7
		});
		const wrongManagedShape = providerCredentialResponseSchema.safeParse({
			provider: 'sentient_managed',
			status: 'valid',
			credential_id: 42,
			key_status: { usage: 1 },
			consent_recorded: true,
			consent_id: 7
		});

		expect(openRouter.provider).toBe('openrouter');
		expect(wrongManagedShape.success).toBe(false);
	});

	it('requires the scalar tier shape returned by the PHP license controller', () => {
		const result = endpointRegistry['license.read'].response.safeParse({
			license_key_masked: 'LIC-****',
			status: 'active',
			proxy_key_present: true,
			expires_at: null,
			last_synced: null,
			tier: { code: 'pro', display_name: 'Pro' },
			license_id: 'lic-1',
			site_id: 'site-1',
			site_url: 'https://example.test'
		});

		expect(result.success).toBe(false);
	});

	it('retires remote telemetry delivery state at the admin boundary', () => {
		const result = endpointRegistry['telemetry.read'].response.parse({
			telemetry_opt_in: true,
			updated_at: '2026-07-10T00:00:00Z',
			synced_at: '2026-07-09T00:00:00Z',
			remote_updated_at: '2026-07-09T00:00:00Z',
			last_error: 'Remote telemetry transport is unavailable.'
		});

		expect(result).toEqual({
			telemetry_opt_in: true,
			updated_at: '2026-07-10T00:00:00Z'
		});
	});

	it('normalizes PHP empty-map arrays in async health responses', () => {
		const result = endpointRegistry['asyncHealth.read'].response.parse({
			queue_depth: 0,
			oldest_run_at: null,
			recent_failures: [],
			warnings: []
		});

		expect(result.recent_failures).toEqual({});
	});

	it('normalizes PHP empty-map arrays in the model catalog', () => {
		const result = endpointRegistry['models.catalog'].response.parse({
			models: [
				{
					id: 'example/model',
					display_name: 'Example Model',
					provider: 'openrouter',
					speed_tier: 'fast',
					cost_tier: 'free',
					capabilities: { reasoning: false, tools: false },
					context_window: 8_192,
					category_rankings: [],
					ranking_snapshot: []
				}
			],
			presets: []
		});

		expect(result.models[0]?.category_rankings).toEqual({});
		expect(result.models[0]?.ranking_snapshot).toEqual({});
	});

	it.each([
		[
			'model resolution numeric ZDR',
			'models.resolve' as const,
			{ global_selection: { primary: 'sf_default', is_preset: true, require_zdr: 1 } }
		],
		[
			'Site Context string ZDR',
			'siteContext.generate' as const,
			{
				generation_model_selection: {
					primary: 'sf_research',
					is_preset: true,
					require_zdr: 'true'
				}
			}
		]
	])('rejects malformed security-sensitive %s payloads', (_label, endpoint, payload) => {
		const definition = endpointRegistry[endpoint];
		expect('request' in definition && definition.request.safeParse(payload).success).toBe(false);
	});
});
