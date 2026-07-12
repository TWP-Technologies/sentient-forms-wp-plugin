import { describe, expect, expectTypeOf, it } from 'vitest';
import {
	endpointRegistry,
	endpointSchemas,
	providerCredentialResponseSchema
} from '$lib/api/endpoint-schemas';
import type {
	RegisteredEndpointRequest,
	RegisteredEndpointResponse
} from '$lib/api/endpoint-schemas';

type PrivacySetupPreset = 'balanced' | 'privacy_focused' | 'maximum_privacy' | 'maximum_visibility';

const completeSettingsResponse = {
	enable_logging: false,
	execution_global_disabled: false,
	execution_provider_disabled: {},
	execution_event_retention_days: 90,
	submission_ledger_retention_days: 30,
	delete_data_on_uninstall: true,
	store_full_ai_outputs: false,
	managed_zdr_required: false,
	privacy_setup_profile: 'balanced',
	privacy_setup_completed_at: null
};

describe('admin endpoint schema registry', () => {
	it('parses action compatibility evidence as an authorized or rejected decision', () => {
		const schema = endpointRegistry['forms.actions.compatibility'].response;
		const rejected = schema.parse({
			form_source: 'contact_form_7',
			form_id: 42,
			action_code: 'clarification_assistant_v1',
			lifecycle: 'real_time',
			policy_decision: 'rejected',
			rejection_code: 'rest_unsupported_form_source_lifecycle',
			reason: 'Realtime lifecycle unavailable',
			request_trace_id: 'request-trace:123e4567-e89b-42d3-a456-426614174000',
			rejection_trace_id: 'source-rejection:123e4567-e89b-42d3-a456-426614174001',
			mapping_created: false,
			provider_request_executed: false,
			generated_at: '2030-01-05T10:00:00Z'
		});

		expect(rejected.policy_decision).toBe('rejected');
		expect(rejected.rejection_trace_id).toMatch(/^source-rejection:/);
		expect(
			schema.safeParse({
				...rejected,
				policy_decision: 'authorized',
				rejection_code: 'rest_unsupported_form_source_lifecycle'
			}).success
		).toBe(false);
		expect(
			schema.safeParse({
				...rejected,
				request_trace_id: 'trace-without-required-prefix'
			}).success
		).toBe(false);
		expect(
			schema.safeParse({
				...rejected,
				request_trace_id: 'request-trace:123e4567-e89b-12d3-a456-426614174000'
			}).success
		).toBe(false);
		expect(
			schema.safeParse({
				...rejected,
				mapping_created: true
			}).success
		).toBe(false);
	});

	it('requires all server-guaranteed governance fields in additive settings responses', () => {
		expect(
			endpointRegistry['settings.read'].response.safeParse({
				...completeSettingsResponse,
				future_governance_field: 'accepted'
			}).success
		).toBe(true);
		expect(
			endpointRegistry['settings.update'].response.safeParse({
				settings: { ...completeSettingsResponse, future_governance_field: 'accepted' },
				future_response_field: 'accepted'
			}).success
		).toBe(true);

		for (const field of [
			'enable_logging',
			'execution_global_disabled',
			'execution_provider_disabled',
			'execution_event_retention_days',
			'submission_ledger_retention_days',
			'delete_data_on_uninstall',
			'store_full_ai_outputs',
			'managed_zdr_required',
			'privacy_setup_profile',
			'privacy_setup_completed_at'
		] as const) {
			const incomplete = { ...completeSettingsResponse };
			delete incomplete[field];

			expect(endpointRegistry['settings.read'].response.safeParse(incomplete).success).toBe(false);
			expect(
				endpointRegistry['settings.update'].response.safeParse({ settings: incomplete }).success
			).toBe(false);
		}
		expect(
			endpointRegistry['settings.read'].response.safeParse({
				...completeSettingsResponse,
				execution_event_retention_days: 14
			}).success
		).toBe(false);
	});

	it('uses the same four privacy presets in settings response and request types', () => {
		type SettingsResponse = RegisteredEndpointResponse<'settings.read'>;
		type SettingsRequest = RegisteredEndpointRequest<'settings.update'>;

		expectTypeOf<SettingsResponse['privacy_setup_profile']>().toEqualTypeOf<PrivacySetupPreset>();
		expectTypeOf<SettingsRequest['privacy_setup_profile']>().toEqualTypeOf<
			PrivacySetupPreset | undefined
		>();
	});

	it('rejects the derived custom state as a settings response profile', () => {
		expect(
			endpointRegistry['settings.read'].response.safeParse({
				...completeSettingsResponse,
				privacy_setup_profile: 'custom'
			}).success
		).toBe(false);
	});

	it('normalizes PHP empty provider maps only in settings responses', () => {
		const read = endpointRegistry['settings.read'].response.parse({
			...completeSettingsResponse,
			execution_provider_disabled: []
		});
		const update = endpointRegistry['settings.update'].response.parse({
			settings: {
				...completeSettingsResponse,
				execution_provider_disabled: []
			}
		});

		expect(read.execution_provider_disabled).toEqual({});
		expect('settings' in update && update.settings.execution_provider_disabled).toEqual({});
		expect(
			endpointRegistry['settings.update'].request.safeParse({ execution_provider_disabled: [] })
				.success
		).toBe(false);
	});

	it('keeps settings updates strict, partial, and within client-owned governance fields', () => {
		const request = endpointRegistry['settings.update'].request;
		expect(request.safeParse({ submission_ledger_retention_days: 0 }).success).toBe(true);
		expect(request.safeParse({ submission_ledger_retention_days: 14 }).success).toBe(false);
		expect(request.safeParse({ privacy_setup_profile: 'custom' }).success).toBe(false);
		expect(request.safeParse({ privacy_setup_completed_at: '2026-07-11T12:00:00Z' }).success).toBe(
			false
		);
		expect(
			request.safeParse({ submission_ledger_retention_days: 30, invented_setting: true }).success
		).toBe(false);
	});

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
