import { describe, expect, expectTypeOf, it } from 'vitest';
import {
	endpointRegistry,
	endpointSchemas,
	formSourceDescriptorBoundarySchema,
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
	it('accepts the local bootstrap capability shape when no CPS version is cached', () => {
		const parsed = endpointRegistry['forms.actions.bootstrap'].response.parse({
			form_source: 'gravity_forms',
			form_id: 123,
			actions: [],
			execution_status: {
				status: 'unknown',
				message: null,
				last_error_code: null
			},
			disabled_state: { sf_disabled: false },
			capabilities: {
				supports_custom_actions: true,
				supports_status: true,
				supports_credits: false,
				cps_version: null
			},
			generated_at: '2026-08-02T00:00:00Z'
		});

		expect(parsed.capabilities?.cps_version).toBeNull();
	});

	it('preserves supported persisted mapping settings and normalizes legacy Site Context flags', () => {
		const parsed = endpointRegistry['forms.actions.create'].response.parse({
			local_mapping_id: 'mapping-1',
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['after_submission'],
			settings: {
				spam_confidence_threshold: 0.72,
				include_site_context: 'yes',
				async: true,
				model_override: 'openai/gpt-5'
			}
		});

		expect(parsed.settings).toMatchObject({
			spam_confidence_threshold: 0.72,
			include_site_context: 'always',
			async: true,
			model_override: 'openai/gpt-5'
		});
	});

	it.each([
		['yes', 'always'],
		['no', 'never']
	] as const)('normalizes legacy action-config Site Context flag %s', (legacy, expected) => {
		const parsed = endpointRegistry['forms.actionConfigs.read'].response.parse({
			form_source: 'gravity_forms',
			form_id: 123,
			action_id: 'spam_detection_v1',
			config: { include_site_context: legacy }
		});

		expect(parsed.config.include_site_context).toBe(expected);
	});

	it('keeps persisted mapping writes strict without stripping supported settings', () => {
		const request = endpointRegistry['forms.actions.update'].request;
		const supported = request.parse({
			settings: {
				spam_confidence_threshold: 0.72,
				include_site_context: 'always',
				async: true,
				model_override: 'openai/gpt-5'
			}
		});

		expect(supported.settings).toEqual({
			spam_confidence_threshold: 0.72,
			include_site_context: 'always',
			async: true,
			model_override: 'openai/gpt-5'
		});
		expect(request.safeParse({ settings: { unexpected_control: 'bypass' } }).success).toBe(false);
	});

	it('accepts server-owned local-first settings that round-trip through update payloads', () => {
		const parsed = endpointRegistry['forms.actions.update'].request.parse({
			settings: {
				local_form_mapping_id: 42,
				effect_mapping_json: { entry_note: { path: 'structured.summary' } }
			}
		});

		expect(parsed.settings).toMatchObject({
			local_form_mapping_id: 42,
			effect_mapping_json: { entry_note: { path: 'structured.summary' } }
		});
	});

	it('accepts ledger previews without a numeric native entry id', () => {
		const parsed = endpointRegistry['actionLog.preview'].response.parse({
			log_id: 'local-event-7',
			provider_label: 'Contact Form 7',
			form_id: 42,
			form_name: 'Contact form',
			entry_id: null,
			date_created: '2026-08-03T00:00:00Z',
			status: null,
			fields: [],
			links: {}
		});

		expect(parsed.entry_id).toBeNull();
	});

	it('normalizes PHP empty action config maps at every response boundary', () => {
		expect(
			endpointRegistry['forms.actionConfigs.read'].response.parse({
				form_source: 'gravity_forms',
				form_id: 7,
				action_id: 'spam_detection_v1',
				config: []
			}).config
		).toEqual({});
		expect(
			endpointRegistry['forms.actionConfigs.list'].response.parse({
				form_source: 'gravity_forms',
				form_id: 7,
				configs: []
			}).configs
		).toEqual({});
		expect(
			endpointRegistry['actions.defaults.batch'].response.parse({ defaults: [] }).defaults
		).toEqual({});
		expect(
			endpointRegistry['forms.actions.bootstrap'].response.parse({
				form_source: 'gravity_forms',
				form_id: 7,
				actions: [],
				execution_status: { status: 'unknown', message: null, last_error_code: null },
				disabled_state: { sf_disabled: false },
				form_action_configs: [],
				action_defaults: [],
				generated_at: '2026-08-03T00:00:00Z'
			})
		).toMatchObject({ form_action_configs: {}, action_defaults: {} });
	});

	it('accepts null admin labels emitted by form adapters', () => {
		const [field] = endpointRegistry['forms.fields.list'].response.parse([
			{ id: 'email', label: 'Email', type: 'email', adminLabel: null }
		]);

		expect(field.adminLabel).toBeNull();
	});

	it('normalizes PHP empty prompt override maps in custom action responses', () => {
		const parsed = endpointRegistry['customActions.list'].response.parse({
			actions: [
				{
					id: '7',
					template_id: null,
					code: 'local_custom_7',
					display_name: 'Custom action',
					description: null,
					prompt_overrides: [],
					model_hint: null,
					base_credit_cost: null,
					status: 'active',
					archived_at: null,
					created_at: '2026-08-03T00:00:00Z',
					updated_at: '2026-08-03T00:00:00Z',
					action_kind: 'custom_definition',
					definition: null,
					definition_version: 1,
					output_contract: null,
					supported_execution_modes: ['after_submission']
				}
			],
			quota: { quota_max: 10, quota_used: 1, quota_remaining: 9 }
		});

		expect(parsed.actions[0].prompt_overrides).toEqual({});
	});

	it('accepts provider-native form ids in Lead Value entry search responses', () => {
		const parsed = endpointRegistry['lead.entries.search'].response.parse({
			entries: [],
			form_source: 'elementor_pro_forms',
			form_id: '123:formabc'
		});

		expect(parsed.form_id).toBe('123:formabc');
	});

	it('normalizes PHP empty maps in local custom actions and Request Trace responses', () => {
		const [action] = endpointRegistry['local.customActions.list'].response.parse([
			{
				id: 7,
				external_id: null,
				template_id: null,
				code: 'local_custom_7',
				display_name: 'Custom action',
				definition_json: [],
				model_selection_json: null,
				status: 'active',
				created_at: null,
				updated_at: null
			}
		]);
		const trace = endpointRegistry['forms.requestTrace.run'].response.parse({
			authority: 'wp_rest',
			policy_version: '1',
			hook_scope: 'after_submission',
			available_hooks: ['after_submission'],
			input: {
				source: 'empty',
				field_scope: 'mapped_and_rule',
				values: [],
				manual_field_ids: [],
				imported_field_ids: [],
				overridden_field_ids: [],
				warnings: [],
				include_drafts: false,
				draft_applied: false
			},
			hooks: [],
			policy_violations: []
		});

		expect(action.definition_json).toEqual({});
		expect(trace.input.values).toEqual({});
	});

	it('normalizes PHP empty maps in historical runs and ledger records', () => {
		const historical = endpointRegistry['lead.historicalRuns.list'].response.parse({
			runs: [
				{
					id: 9,
					form_source: 'wpforms',
					form_id: '77',
					action_code: 'lead_scoring_v1',
					selected_entry_ids: [],
					filters: [],
					estimated_entry_count: 0,
					dry_run: true,
					status: 'preview'
				}
			]
		});
		const ledger = endpointRegistry['forms.ledger.records.read'].response.parse({
			id: 11,
			submission_uuid: '11111111-2222-4333-8444-555555555555',
			form_source: 'contact_form_7',
			form_id: '42',
			native_entry_id: null,
			native_entry_url: null,
			source_submitted_at: null,
			captured_at: '2026-08-03T00:00:00Z',
			logical_fields: [],
			expires_at: null,
			detail_endpoint: '/submissions/11'
		});

		expect(historical.runs[0].filters).toEqual({});
		expect(ledger.logical_fields).toEqual({});
	});

	it('registers a bodyless Site Context withdrawal contract', () => {
		expect(endpointRegistry['siteContext.withdraw'].path).toBe('site-context');
		expect(endpointRegistry['siteContext.withdraw'].request.parse(undefined)).toBeUndefined();
	});

	it('normalizes released snake-case action definition aliases', () => {
		const [definition] = endpointRegistry['actions.definitions'].response.parse([
			{
				id: 'spam-check',
				base_credit_cost: 2,
				model_hint: 'openrouter/auto',
				prompt_template: 'Classify the submission.'
			}
		]);

		expect(definition).toMatchObject({
			baseCreditCost: 2,
			modelHint: 'openrouter/auto',
			promptTemplate: 'Classify the submission.'
		});
		expect(definition).not.toHaveProperty('base_credit_cost');
	});

	it('preserves mapping-specific model selection in form action updates', () => {
		const modelSelection = {
			primary: 'sf_default',
			is_preset: true,
			provider: 'openrouter' as const,
			credential_id: 42
		};

		const parsed = endpointRegistry['forms.actions.update'].request.parse({
			settings: {
				model_selection: modelSelection
			}
		});

		expect(parsed.settings?.model_selection).toEqual(modelSelection);
	});

	it('parses safe Spam Guidance facet provider observations', () => {
		const schema = endpointRegistry['spamGuidance.examples.append'].response;
		const parsed = schema.parse({
			target_scope: 'form',
			label: 'ham',
			config: {},
			generation: {
				route: 'openrouter',
				model: 'google/gemini-3-flash-preview',
				provider_observation_type: 'subscription_gated_direct_response',
				provider_observation_id: 'fallback-openrouter:gen-safe-observation',
				route_decision_reason: 'direct_ready'
			}
		});

		expect(parsed.generation?.provider_observation_id).toBe(
			'fallback-openrouter:gen-safe-observation'
		);
		expect(
			schema.safeParse({
				...parsed,
				generation: {
					...parsed.generation,
					provider_observation_id: 'fallback-openrouter:secret value'
				}
			}).success
		).toBe(false);
		expect(
			schema.safeParse({
				...parsed,
				generation: {
					route: 'openrouter',
					provider_observation_type: 'subscription_gated_direct_response',
					route_decision_reason: 'direct_ready'
				}
			}).success
		).toBe(false);
		expect(
			schema.safeParse({
				...parsed,
				generation: {
					...parsed.generation,
					route: 'sentient_managed'
				}
			}).success
		).toBe(false);
		expect(
			schema.safeParse({
				...parsed,
				generation: {
					...parsed.generation,
					route_decision_reason: 'managed_ready_with_capacity'
				}
			}).success
		).toBe(false);
	});

	it('binds managed Spam Guidance observations to the managed route contract', () => {
		const schema = endpointRegistry['spamGuidance.examples.append'].response;
		const managed = {
			target_scope: 'form',
			label: 'ham',
			config: {},
			generation: {
				route: 'sentient_managed',
				model: 'openrouter/auto',
				provider_observation_type: 'cps_managed_lifecycle',
				provider_observation_id: 'cps-lifecycle:managed-observation',
				route_decision_reason: 'managed_ready_with_capacity'
			}
		};

		expect(schema.safeParse(managed).success).toBe(true);
		expect(
			schema.safeParse({
				...managed,
				generation: { ...managed.generation, route: 'openrouter' }
			}).success
		).toBe(false);
		expect(
			schema.safeParse({
				...managed,
				generation: { ...managed.generation, route_decision_reason: 'direct_ready' }
			}).success
		).toBe(false);
	});

	it('binds Spam Guidance route decisions even when no provider observation is published', () => {
		const schema = endpointRegistry['spamGuidance.examples.append'].response;
		const response = {
			target_scope: 'form',
			label: 'ham',
			config: {}
		};

		expect(
			schema.safeParse({
				...response,
				generation: { route: 'openrouter', route_decision_reason: 'direct_ready' }
			}).success
		).toBe(true);
		expect(
			schema.safeParse({
				...response,
				generation: {
					route: 'sentient_managed',
					route_decision_reason: 'managed_ready_with_capacity'
				}
			}).success
		).toBe(true);
		expect(
			schema.safeParse({
				...response,
				generation: {
					route: 'openrouter',
					route_decision_reason: 'managed_ready_with_capacity'
				}
			}).success
		).toBe(false);
		expect(
			schema.safeParse({
				...response,
				generation: { route: 'sentient_managed', route_decision_reason: 'direct_ready' }
			}).success
		).toBe(false);
		expect(schema.safeParse({ ...response, generation: {} }).success).toBe(true);
	});

	it('parses action compatibility evidence as an authorized or rejected decision', () => {
		const requestSchema = endpointRegistry['forms.actions.compatibility'].request;
		const schema = endpointRegistry['forms.actions.compatibility'].response;
		expect(
			requestSchema.parse({
				action_code: 'clarification_assistant_v1',
				lifecycle: 'real_time'
			})
		).toEqual({ action_code: 'clarification_assistant_v1', lifecycle: 'real_time' });
		expect(
			requestSchema.safeParse({
				action_code: 'clarification_assistant_v1',
				lifecycle: 'real_time',
				untrusted: true
			}).success
		).toBe(false);
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
			const incomplete: Partial<typeof completeSettingsResponse> = {
				...completeSettingsResponse
			};
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

	it('accepts the tier summary object returned by the PHP license controller', () => {
		const tier = { code: 'pro', display_name: 'Pro' };
		const result = endpointRegistry['license.read'].response.parse({
			license_key_masked: 'LIC-****',
			status: 'active',
			proxy_key_present: true,
			expires_at: null,
			last_synced: null,
			tier,
			license_id: 'lic-1',
			site_id: 'site-1',
			site_url: 'https://example.test'
		});

		expect(result.tier).toEqual(tier);
	});

	it('normalizes legacy input mappings and empty PHP settings maps in form overviews', () => {
		const executionStatus = {
			status: 'unknown',
			message: null,
			entry_id: null,
			last_error_code: null
		};
		const action = {
			local_mapping_id: 'local_first_49',
			central_action_id: 'entry_summary_v1',
			action_type_indicator: 'local_first',
			trigger_hooks: ['after_submission'],
			settings: {
				input_mapping: { name: '1', email: 2, comments: '3' }
			}
		};
		const result = endpointRegistry['forms.overview'].response.parse({
			form_source: 'gravity_forms',
			forms: [
				{
					id: 351,
					title: 'Imported summary',
					adapter: 'gravity_forms',
					actions: [action],
					action_count: 1,
					enabled_action_count: 1,
					execution_status: executionStatus
				},
				{
					id: 710,
					title: 'Empty settings',
					adapter: 'gravity_forms',
					actions: [{ ...action, local_mapping_id: 'legacy-empty', settings: [] }],
					action_count: 1,
					enabled_action_count: 1,
					execution_status: executionStatus
				}
			],
			generated_at: '2030-01-05T10:00:00Z'
		});

		expect(result.forms[0].actions[0].settings?.input_mapping).toEqual({
			mode: 'selected',
			field_ids: ['1', '2', '3'],
			include_metadata: false
		});
		expect(result.forms[1].actions[0].settings).toEqual({});
	});

	it('accepts local diagnostics and rejects retired remote telemetry at the admin boundary', () => {
		const result = endpointRegistry['telemetry.read'].response.parse({
			local_diagnostics_enabled: true,
			updated_at: '2026-07-10T00:00:00Z'
		});

		expect(result).toEqual({
			local_diagnostics_enabled: true,
			updated_at: '2026-07-10T00:00:00Z'
		});
		expect(
			endpointRegistry['telemetry.read'].response.safeParse({
				telemetry_opt_in: true,
				synced_at: '2026-07-09T00:00:00Z'
			}).success
		).toBe(false);
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

	it('normalizes empty PHP maps in local form-mapping responses', () => {
		const result = endpointRegistry['local.formMappings.create'].response.parse({
			id: 23,
			external_id: null,
			form_source: 'gravity_forms',
			form_id: '42',
			hook: 'gform_after_submission',
			action_kind: 'custom_action',
			action_id: 17,
			conditions_json: null,
			input_bindings_json: [],
			execution_mode: 'sync',
			effect_mapping_json: null,
			enabled: true,
			created_at: '2026-08-03T00:00:00Z',
			updated_at: '2026-08-03T00:00:00Z'
		});

		expect(result.input_bindings_json).toEqual({});
	});

	it('normalizes omitted adapter requirements serialized as an empty PHP map', () => {
		const result = formSourceDescriptorBoundarySchema.parse({
			slug: 'example',
			label: 'Example',
			is_active: true,
			lifecycles: [],
			requirements: []
		});

		expect(result.lifecycles).toEqual({});
		expect(result.requirements).toEqual({});
	});

	it('normalizes empty workflow credit maps in execution status responses', () => {
		const result = endpointRegistry['forms.entryExecutionStatus.read'].response.parse({
			entry_id: 42,
			form_id: 'contact-7',
			last_response: null,
			last_error: null,
			processed_at: null,
			status: 'success',
			metering_summary: {
				workflow: {
					status: 'completed',
					credits_total: 0,
					credits_by_node: [],
					failed_nodes: []
				}
			}
		});

		expect(result.metering_summary?.workflow?.credits_by_node).toEqual({});
	});

	it('preserves numeric workflow node ids serialized by PHP as an indexed array', () => {
		const result = endpointRegistry['forms.entryExecutionStatus.read'].response.parse({
			entry_id: 42,
			form_id: 'contact-7',
			last_response: null,
			last_error: null,
			processed_at: null,
			status: 'success',
			metering_summary: {
				workflow: {
					status: 'completed',
					credits_total: 7,
					credits_by_node: [3, 4],
					failed_nodes: []
				}
			}
		});

		expect(result.metering_summary?.workflow?.credits_by_node).toEqual({ 0: 3, 1: 4 });
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
