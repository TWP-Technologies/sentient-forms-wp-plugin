import { z } from 'zod';
import { siteContextStatusResponseSchema } from '$lib/schemas/site-context';
import { normalizeSiteContextResponse } from '$lib/utils/site-context';
import { providerPathPolicyResponseSchema } from '$lib/schemas/provider-path-policy';
import { modelSelectionSchema } from '$lib/schemas/model-selection';

const httpsUrlSchema = z.string().refine((value) => {
	try {
		return new URL(value).protocol === 'https:';
	} catch {
		return false;
	}
}, 'Expected an HTTPS URL');

function rawOrSuccessEnvelope<TSchema extends z.ZodType>(data: TSchema) {
	return z.preprocess((payload) => {
		if (
			payload !== null &&
			typeof payload === 'object' &&
			Reflect.get(payload, 'success') === true &&
			Reflect.has(payload, 'data')
		) {
			return Reflect.get(payload, 'data');
		}
		return payload;
	}, data);
}

const jsonObjectSchema = z.record(z.string(), z.json());
const nullableString = z.string().nullable();

const executionStatusDataSchema = z.object({
	id: z.string(),
	mapping_id: z.string().nullable(),
	entry_id: z.number().int(),
	status: z.string(),
	result_summary: nullableString,
	error_message: nullableString,
	credit_cost: z.number().nullable(),
	started_at: nullableString,
	completed_at: nullableString,
	created_at: z.string()
});

const modelCapabilitiesSchema = z.object({
	reasoning: z.boolean(),
	code: z.boolean().optional().default(false),
	vision: z.boolean().optional().default(false),
	tools: z.boolean(),
	structured: z.boolean().optional(),
	web_search: z.boolean().optional(),
	server_tools: z
		.object({
			web_search: z.boolean().optional(),
			web_fetch: z.boolean().optional(),
			datetime: z.boolean().optional()
		})
		.optional(),
	long_context: z.boolean().optional().default(false),
	files: z.boolean().optional(),
	audio: z.boolean().optional(),
	video: z.boolean().optional()
});

const modelInfoSchema = z.object({
	id: z.string(),
	display_name: z.string(),
	provider: z.string(),
	provider_family: z.string().optional(),
	developer: z.string().optional(),
	description: z.string().optional(),
	speed_tier: z.string(),
	cost_tier: z.string(),
	cost_symbol: z.string().optional(),
	capabilities: modelCapabilitiesSchema,
	context_window: z.number(),
	is_preview: z.boolean().optional().default(false),
	tags: z.array(z.string()).optional().default([]),
	supported_parameters: z.array(z.string()).optional(),
	input_modalities: z.array(z.string()).optional(),
	output_modalities: z.array(z.string()).optional(),
	pricing: z.record(z.string(), z.string()).optional(),
	recommended_for: z.array(z.string()).optional().default([]),
	zdr_eligible: z.boolean().nullable().optional(),
	zdr_source: nullableString.optional(),
	zdr_checked_at: nullableString.optional(),
	recommendation_categories: z.array(z.string()).optional(),
	category_rankings: z.record(z.string(), z.number()).optional(),
	ranking_snapshot: jsonObjectSchema.optional(),
	benchmark_notes: z.array(z.string()).optional(),
	source_urls: z.array(z.string()).optional(),
	created: z.union([z.number(), z.string()]).nullable().optional(),
	knowledge_cutoff: nullableString.optional()
});

const modelPresetSchema = z.object({
	code: z.string(),
	display_name: z.string(),
	description: z.string().optional().default(''),
	category: z.string(),
	resolved_model_id: z.string(),
	auto_upgrade: z.boolean(),
	rationale: z.string().optional(),
	score: z.number().optional(),
	evidence_confidence: z.string().optional(),
	evaluated_at: z.string().optional(),
	score_breakdown: z.record(z.string(), z.number()).optional(),
	top_candidates: z
		.array(z.object({ model_id: z.string(), score: z.number(), notes: z.string().optional() }))
		.optional(),
	source_urls: z.array(z.string()).optional()
});

const modelCatalogSchema = z.object({
	models: z.array(modelInfoSchema),
	presets: z.array(modelPresetSchema),
	pricing_policy_version: z.string().optional()
});

const resolutionStepSchema = z.object({
	level: z.string(),
	selection: nullableString,
	applied: z.boolean(),
	reason: z.string()
});
const resolvedModelSelectionSchema = z.object({
	model_id: z.string(),
	display_name: z.string(),
	resolution_source: z.string(),
	override_chain: z.array(resolutionStepSchema),
	backup_model_id: nullableString
});
const modelPricingEstimateSchema = z.object({
	action_id: z.string(),
	resolved_model_id: z.string(),
	route: z.string().optional(),
	kind: z.string().optional(),
	label: z.string().optional(),
	amount_usd: z.number().nullable().optional(),
	estimate_range: z
		.object({
			low: z.number().nullable().optional(),
			high: z.number().nullable().optional(),
			currency: z.string().optional(),
			unit: z.string().optional()
		})
		.nullable()
		.optional(),
	estimated_input_tokens: z.number().optional(),
	estimated_output_tokens: z.number().optional(),
	estimated_reasoning_tokens: z.number().optional(),
	sample_count: z.number().optional(),
	confidence: z.string().optional(),
	calibration_source: z.string().optional(),
	provider_pricing: z.record(z.string(), z.string()).optional(),
	base_floor_credits: z.number(),
	normalized_actual_credits: z.number(),
	estimated_debit_credits: z.number(),
	pricing_policy_version: z.string(),
	estimate_source: z.string()
});
const modelEstimateSchema = z.object({
	resolved_model: resolvedModelSelectionSchema,
	pricing_estimate: modelPricingEstimateSchema
});

const actionLogLinksSchema = z.object({
	provider_admin_url: nullableString.optional(),
	form_admin_url: nullableString.optional(),
	entries_admin_url: nullableString.optional(),
	entry_admin_url: nullableString.optional()
});
const actionLogFormContextSchema = z.object({
	provider_slug: z.string(),
	provider_label: z.string(),
	form_id: z.union([z.string(), z.number()]),
	form_name: z.string(),
	entry_id: z.number().int().nullable(),
	links: actionLogLinksSchema,
	entry_preview_available: z.boolean(),
	form_missing: z.boolean().optional()
});
const actionLogEntrySchema = z.object({
	id: z.string(),
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]),
	entry_id: z.number().int().nullable(),
	action_code: z.string(),
	action_label: z.string(),
	status: z.enum(['pending', 'success', 'blocked', 'error']),
	result_summary: nullableString,
	classification: nullableString,
	credits_used: z.number(),
	error_code: nullableString,
	error_message: nullableString,
	structured_output_valid: z.boolean(),
	execution_request_id: nullableString.optional(),
	mapping_id: nullableString.optional(),
	resolved_model_id: nullableString.optional(),
	pricing: z
		.object({
			pricing_policy_version: nullableString.optional(),
			estimate_source: nullableString.optional(),
			base_floor_credits: z.number().nullable().optional(),
			normalized_actual_credits: z.number().nullable().optional(),
			debited_credits: z.number().nullable().optional(),
			provider_cost: jsonObjectSchema.nullable().optional()
		})
		.nullable()
		.optional(),
	usage_cost: z
		.object({
			route: nullableString.optional(),
			label: nullableString.optional(),
			kind: nullableString.optional(),
			known: z.boolean().nullable().optional(),
			credits: z.number().nullable().optional(),
			amount_usd: z.number().nullable().optional()
		})
		.nullable()
		.optional(),
	details: jsonObjectSchema.nullable().optional(),
	form_context: actionLogFormContextSchema.nullable().optional(),
	created_at: z.string(),
	completed_at: nullableString
});
const actionLogResponseSchema = z.object({
	entries: z.array(actionLogEntrySchema),
	total: z.number().int(),
	total_pages: z.number().int(),
	page: z.number().int(),
	per_page: z.number().int()
});
const actionLogPreviewSchema = z.object({
	log_id: z.string(),
	provider_label: z.string(),
	form_id: z.number().int(),
	form_name: z.string(),
	entry_id: z.number().int(),
	date_created: nullableString,
	status: nullableString,
	fields: z.array(z.object({ field_id: z.string(), label: z.string(), value: z.string() })),
	links: actionLogLinksSchema
});

const modelResolutionRequestSchema = z.strictObject({
	global_selection: modelSelectionSchema.nullable().optional(),
	action_selection: modelSelectionSchema.nullable().optional(),
	form_selection: modelSelectionSchema.nullable().optional(),
	mapping_selection: modelSelectionSchema.nullable().optional(),
	template_model_hint: z.string().optional()
});

const siteContextUpdateRequestSchema = z.strictObject({
	summary_text: z.string().optional(),
	auto_include: z.boolean().optional(),
	pii_ack: z.boolean().optional(),
	consent_status: z.enum(['unset', 'granted', 'declined']).optional(),
	auto_refresh_enabled: z.boolean().optional(),
	auto_refresh_days: z.number().int().positive().optional(),
	generation_model_selection: modelSelectionSchema.nullable().optional()
});
const siteContextGenerateRequestSchema = siteContextUpdateRequestSchema.pick({
	consent_status: true,
	auto_refresh_enabled: true,
	auto_refresh_days: true,
	generation_model_selection: true
});
const modelSelectionRequestSchema = modelResolutionRequestSchema;
const modelEstimateRequestSchema = modelResolutionRequestSchema.extend({
	action_id: z.string(),
	base_credit_cost: z.number().optional()
});

const billingPortalSessionSchema = z.object({
	session_id: z.string(),
	portal_url: httpsUrlSchema,
	customer_id: z.string()
});

const billingPortalSessionRequestSchema = z.strictObject({
	return_url: z.string().trim().url(),
	flow_type: z.enum(['home', 'subscription_update', 'subscription_cancel']).optional(),
	subscription_id: z.string().optional()
});

const billingCheckoutSessionRequestSchema = z.strictObject({
	price_id: z.string().optional(),
	plan_code: z.string().optional(),
	success_url: z.string().url(),
	cancel_url: z.string().url(),
	quantity: z.number().int().positive().optional()
});

const billingCheckoutSessionSchema = z.object({
	session_id: z.string(),
	checkout_url: httpsUrlSchema,
	customer_id: z.string(),
	subscription_id: z.string().nullable().optional()
});

const managedCheckoutStartRequestSchema = z.strictObject({
	plan_code: z.string().min(1),
	billing_interval: z.literal('monthly').optional(),
	success_url: z.string().url(),
	cancel_url: z.string().url(),
	disclosure_version: z.string().min(1),
	accepted_managed_service_terms: z.literal(true)
});

const managedCheckoutStartSchema = z.object({
	checkout_intent_id: z.string(),
	checkout_session_id: z.string(),
	checkout_url: httpsUrlSchema,
	plan_code: z.string().optional(),
	billing_interval: z.string().optional(),
	status: z.string().optional(),
	consent_recorded: z.boolean().optional(),
	consent_id: z.number().int().optional(),
	disclosure_version: z.string().optional()
});

const topUpCheckoutSessionRequestSchema = z.strictObject({
	pack_code: z.string().min(1),
	success_url: z.string().url(),
	cancel_url: z.string().url(),
	quantity: z.number().int().positive().optional()
});

const topUpCheckoutSessionSchema = z.object({
	session_id: z.string(),
	checkout_url: httpsUrlSchema,
	customer_id: z.string(),
	top_up_credits: z.number().int(),
	pack_code: z.string()
});

const migrationJsonObjectSchema = z.record(z.string(), z.json());
const migrationActionTemplateSchema = z.strictObject({
	external_id: z.string(),
	source: z.string().optional(),
	code: z.string(),
	display_name: z.string().optional(),
	description: z.string().nullable().optional(),
	prompt_template: z.string().optional(),
	default_model: z.string().nullable().optional(),
	structured_output_schema: migrationJsonObjectSchema.nullable().optional(),
	override_schema: migrationJsonObjectSchema.nullable().optional(),
	version: z.string().optional(),
	is_active: z.boolean().optional()
});
const migrationCustomActionSchema = z.strictObject({
	external_id: z.string(),
	template_external_id: z.string().optional(),
	template_code: z.string().optional(),
	code: z.string().optional(),
	display_name: z.string().optional(),
	description: z.string().nullable().optional(),
	definition_json: migrationJsonObjectSchema.optional(),
	model_selection_json: migrationJsonObjectSchema.nullable().optional(),
	output_contract: migrationJsonObjectSchema.nullable().optional(),
	supported_execution_modes: z.array(z.string()).optional(),
	status: z.string().optional()
});
const migrationFormMappingSchema = z.strictObject({
	external_id: z.string(),
	form_source: z.string().optional(),
	form_id: z.union([z.string(), z.number()]).transform(String).optional(),
	hook: z.string().optional(),
	action_kind: z.string().optional(),
	action_external_id: z.string().optional(),
	action_code: z.string().optional(),
	input_bindings_json: migrationJsonObjectSchema.optional(),
	conditions_json: migrationJsonObjectSchema.nullable().optional(),
	effect_mapping_json: migrationJsonObjectSchema.nullable().optional(),
	execution_mode: z.string().optional(),
	enabled: z.boolean().optional()
});
const migrationExecutionEventSchema = z.strictObject({
	execution_request_id: z.string().optional(),
	mapping_external_id: z.string().optional(),
	mapping_id: z.number().int().positive().optional(),
	form_source: z.string().nullable().optional(),
	form_id: z.union([z.string(), z.number()]).nullable().optional(),
	entry_id: z.union([z.string(), z.number()]).nullable().optional(),
	provider: z.string().optional(),
	model: z.string().nullable().optional(),
	status: z.string().optional(),
	token_usage_json: migrationJsonObjectSchema.nullable().optional(),
	cost_json: migrationJsonObjectSchema.nullable().optional(),
	result_json: migrationJsonObjectSchema.nullable().optional(),
	error_code: z.string().nullable().optional(),
	error_message: z.string().nullable().optional(),
	payload_digest: z.string().nullable().optional(),
	expires_at: z.string().nullable().optional(),
	created_at: z.string().optional(),
	updated_at: z.string().optional()
});
export const localMigrationBundleSchema = z.strictObject({
	schema_version: z.literal('sentient_forms_cps_export_v1'),
	source: z.string().optional(),
	source_version: z.string().optional(),
	exported_at: z.string().optional(),
	license: z
		.strictObject({
			external_id: z.string(),
			status: z.string(),
			tier_code: z.string().optional(),
			max_sites: z.number().int().positive().optional()
		})
		.optional(),
	site: z
		.strictObject({
			external_id: z.string(),
			site_url: z.string().url(),
			local_site_identifier: z.string(),
			telemetry_opt_in: z.boolean().optional()
		})
		.optional(),
	action_templates: z.array(migrationActionTemplateSchema).optional(),
	custom_actions: z.array(migrationCustomActionSchema).optional(),
	form_mappings: z.array(migrationFormMappingSchema).optional(),
	execution_events: z.array(migrationExecutionEventSchema).optional(),
	settings: z
		.strictObject({
			default_provider: z.string().optional(),
			export_scope: z
				.strictObject({
					include_execution_history: z.boolean().optional(),
					execution_history_limit: z.number().int().nonnegative().optional()
				})
				.optional()
		})
		.optional()
});
const localMigrationImportRequestSchema = z.strictObject({
	bundle: localMigrationBundleSchema
});

const siteContextBoundaryResponseSchema = z
	.union([z.null(), z.record(z.string(), z.json())])
	.transform((payload, context) => {
		let normalized: unknown;
		try {
			normalized = normalizeSiteContextResponse(payload as never);
		} catch {
			context.addIssue({
				code: 'custom',
				message: 'Site Context response could not be normalized safely.'
			});
			return z.NEVER;
		}

		const result = siteContextStatusResponseSchema.safeParse(normalized);
		if (result.success) return result.data;
		for (const issue of result.error.issues) {
			context.addIssue({
				code: 'custom',
				path: issue.path,
				message: issue.message
			});
		}
		return z.NEVER;
	});

const localMigrationImportFindingSchema = z.object({
	code: z.string(),
	message: z.string(),
	severity: z.string().optional(),
	entity: z.string().optional(),
	field: z.string().optional(),
	value: z.string().optional()
});

const localMigrationImportReportSchema = z.object({
	schema_version: z.string(),
	source: z.string(),
	source_version: z.string(),
	generated_at: z.string(),
	exported_at: z.string().nullable(),
	ready_to_import: z.boolean(),
	counts: z.record(z.string(), z.number()),
	changes: z.record(z.string(), z.union([z.number(), z.record(z.string(), z.number())])),
	conflicts: z.array(localMigrationImportFindingSchema),
	warnings: z.array(localMigrationImportFindingSchema),
	mapping: z.record(z.string(), z.json())
});

const localMigrationImportDryRunResponseSchema = z.object({
	run_id: z.number().int(),
	status: z.string(),
	dry_run: z.literal(true),
	report: localMigrationImportReportSchema
});

const localMigrationImportApplyResponseSchema = z.object({
	run_id: z.number().int(),
	status: z.string(),
	dry_run: z.literal(false),
	report: localMigrationImportReportSchema,
	applied: z.record(z.string(), z.number())
});

const localProviderCredentialSchema = z.object({
	id: z.number().int(),
	provider: z.string(),
	label: z.string(),
	auth_mode: z.string(),
	constant_name: z.string().nullable(),
	status: z.string(),
	status_json: z.record(z.string(), z.json()).nullable(),
	last_validated_at: z.string().nullable(),
	created_at: z.string().nullable(),
	updated_at: z.string().nullable(),
	secret_configured: z.boolean()
});
const localProviderCredentialListSchema = z.array(localProviderCredentialSchema);

const billingBoundarySchema = z.object({
	direct_openrouter_billed_by_sentient: z.boolean(),
	managed_proxy_billed_by_sentient: z.boolean()
});

const openRouterCredentialResponseSchema = z.object({
	provider: z.literal('openrouter'),
	status: z.string(),
	credential_id: z.number().int().nullable(),
	key_status: z.record(z.string(), z.json()),
	consent_recorded: z.boolean(),
	consent_id: z.number().int(),
	auth_mode: z.string().optional(),
	constant_name: z.string().nullable().optional()
});

const openRouterValidateRequestSchema = z.strictObject({
	api_key: z.string().min(1),
	disclosure_version: z.string().min(1),
	accepted_external_service_terms: z.literal(true),
	label: z.string().optional(),
	save: z.boolean().optional()
});

const openRouterConstantRequestSchema = z.strictObject({
	constant_name: z.string().min(1),
	disclosure_version: z.string().min(1),
	accepted_external_service_terms: z.literal(true),
	label: z.string().optional()
});

const managedSetupResponseSchema = z.object({
	provider: z.literal('sentient_managed'),
	status: z.string(),
	credential_id: z.number().int(),
	credential: localProviderCredentialSchema.nullable(),
	consent_recorded: z.boolean(),
	consent_id: z.number().int(),
	consent_state: z.string().optional(),
	account: z.object({
		status: z.string(),
		license_id: z.string(),
		site_id: z.string(),
		local_site_identifier: z.string(),
		proxy_key_present: z.boolean(),
		credential_ready: z.boolean()
	}),
	billing_boundary: billingBoundarySchema
});

const managedSetupRequestSchema = z.strictObject({
	disclosure_version: z.string().min(1),
	accepted_external_service_terms: z.literal(true),
	label: z.string().optional()
});

const managedRevokeResponseSchema = z.object({
	provider: z.literal('sentient_managed'),
	status: z.string(),
	credential_id: z.number().int().nullable(),
	credential: localProviderCredentialSchema.nullable(),
	consent_recorded: z.boolean(),
	consent_id: z.number().int(),
	consent_state: z.string(),
	billing_boundary: billingBoundarySchema
});

const managedRevokeRequestSchema = z.strictObject({
	disclosure_version: z.string().min(1),
	confirm_managed_service_revocation: z.literal(true)
});

const managedCredentialResponseSchema = z.object({
	provider: z.literal('sentient_managed'),
	status: z.string(),
	credential_id: z.number().int().nullable(),
	credential: localProviderCredentialSchema.nullable().optional(),
	consent_recorded: z.boolean(),
	consent_id: z.number().int(),
	consent_state: z.string().optional(),
	account: managedSetupResponseSchema.shape.account.optional(),
	billing_boundary: billingBoundarySchema
});

export const providerCredentialResponseSchema = z.discriminatedUnion('provider', [
	openRouterCredentialResponseSchema,
	managedCredentialResponseSchema
]);

const emptyRequestSchema = z.undefined();
const emptyResponseSchema = z.undefined();
const nullableTextSchema = z.string().nullable().nonoptional();
const jsonValueSchema = z.json();
const jsonRecordValueSchema = z.record(z.string(), jsonValueSchema);
const nullableJsonRecordValueSchema = jsonRecordValueSchema.nullable().nonoptional();
const tierSummarySchema = z.object({
	code: z.string(),
	display_name: z.string().optional(),
	site_limit: z.number().int().optional(),
	monthly_credit_quota: z.number().optional()
});
const tierValueSchema = z.union([z.string(), tierSummarySchema]);
const licenseInfoSchema = z.object({
	license_key_masked: z.string(),
	status: z.string(),
	proxy_key_present: z.boolean(),
	expires_at: nullableTextSchema,
	last_synced: nullableTextSchema,
	tier: nullableTextSchema,
	license_id: nullableTextSchema,
	site_id: nullableTextSchema,
	site_url: z.string()
});
const licenseActivationRequestSchema = z.strictObject({
	license_key: z.string().min(1),
	site_url: z.string().url(),
	local_site_identifier: z.string().min(1)
});
const managedCheckoutCompleteRequestSchema = z.strictObject({
	checkout_intent_id: nullableTextSchema.optional(),
	checkout_session_id: nullableTextSchema.optional(),
	activation_token: nullableTextSchema.optional()
});
const managedCheckoutCompleteSchema = z.object({
	activation_ready: z.boolean(),
	status: z.string().optional(),
	message: z.string().optional(),
	license_key: z.string().optional(),
	license_id: z.string().optional(),
	site_id: z.string().optional(),
	proxy_api_key: z.string().optional(),
	tier: tierValueSchema.optional(),
	expires_at: nullableTextSchema.optional(),
	expiry_date: nullableTextSchema.optional(),
	credential_id: z.number().int().optional(),
	managed_provider_ready: z.boolean().optional()
});
const billingSubscriptionSchema = z.object({
	provider_subscription_id: z.string(),
	status: z.string(),
	quantity: z.number(),
	cancel_at_period_end: z.boolean(),
	current_period_start: nullableTextSchema.optional(),
	current_period_end: nullableTextSchema.optional(),
	trial_end: nullableTextSchema.optional(),
	provider_price_id: nullableTextSchema.optional()
});
const managedUsageSummarySchema = z.object({
	site_id: nullableTextSchema.optional(),
	total_events: z.number().optional(),
	succeeded_events: z.number().optional(),
	failed_events: z.number().optional(),
	total_input_tokens: z.number().optional(),
	total_output_tokens: z.number().optional(),
	free_usage_events: z.number().optional(),
	first_event_at: nullableTextSchema.optional(),
	last_event_at: nullableTextSchema.optional(),
	execution_count: z.number().optional(),
	succeeded_count: z.number().optional(),
	failed_count: z.number().optional(),
	token_usage: z
		.object({
			input_tokens: z.number().optional(),
			output_tokens: z.number().optional(),
			total_tokens: z.number().optional()
		})
		.optional()
});
const billingStateSchema = z.object({
	service: z.string().optional(),
	site_id: nullableTextSchema.optional(),
	license_id: nullableTextSchema.optional(),
	status: nullableTextSchema.optional(),
	stale: z.boolean().optional(),
	cached_at: nullableTextSchema.optional(),
	last_error_code: nullableTextSchema.optional(),
	plan: tierSummarySchema.nullable().optional(),
	account: z
		.object({
			license_status: nullableTextSchema.optional(),
			tier: tierSummarySchema.nullable().optional()
		})
		.nullable()
		.optional(),
	billing: z
		.object({
			provider: z.string(),
			provider_mode: z.string().optional(),
			provider_livemode: z.boolean().optional(),
			customer_id: nullableTextSchema.optional(),
			subscription: billingSubscriptionSchema.nullable().optional(),
			managed_enabled: z.boolean().optional()
		})
		.nullable()
		.optional(),
	managed_usage: managedUsageSummarySchema.nullable().optional(),
	billing_boundary: billingBoundarySchema.nullable().optional(),
	provider: z.string().optional(),
	provider_mode: z.string().optional(),
	provider_livemode: z.boolean().optional(),
	license_status: nullableTextSchema.optional(),
	tier: tierSummarySchema.nullable().optional(),
	customer_id: nullableTextSchema.optional(),
	subscription: billingSubscriptionSchema.nullable().optional(),
	credits: z
		.object({
			current_balance: z.number(),
			tier_quota: z.number(),
			ledger_delta: z.number(),
			top_up_available: z.number().optional()
		})
		.optional(),
	allocation: z
		.object({
			seat_quantity: z.number(),
			tier_site_limit: z.number(),
			allowed_sites: z.number(),
			active_sites: z.number(),
			over_limit: z.boolean(),
			blocked_new_activations: z.boolean(),
			grace_expires_at: nullableTextSchema.optional(),
			capacity_policy: z.string()
		})
		.nullable()
		.optional(),
	policy: z
		.object({
			paid_trial_days: z.number(),
			free_plan_monthly_credits: z.number(),
			free_plan_indefinite: z.boolean(),
			private_beta_trial_enabled: z.boolean()
		})
		.nullable()
		.optional()
});
const telemetrySettingsSchema = z.object({
	telemetry_opt_in: z.boolean(),
	updated_at: nullableTextSchema
});
const telemetryUpdateRequestSchema = z.strictObject({ telemetry_opt_in: z.boolean() });
const asyncSettingsSchema = z.object({
	max_attempts: z.number().int(),
	base_delay_seconds: z.number().int(),
	max_delay_seconds: z.number().int(),
	updated_at: nullableTextSchema,
	updated_by: nullableTextSchema
});
const asyncSettingsUpdateRequestSchema = z.strictObject({
	max_attempts: z.number().int().optional(),
	base_delay_seconds: z.number().int().optional(),
	max_delay_seconds: z.number().int().optional()
});
const pluginSettingsSchema = z.object({
	enable_logging: z.boolean().optional(),
	execution_global_disabled: z.boolean().optional(),
	execution_provider_disabled: z.record(z.string(), z.boolean()).optional(),
	execution_event_retention_days: z.number().int().optional(),
	submission_ledger_retention_days: z.number().int().optional(),
	delete_data_on_uninstall: z.boolean().optional(),
	store_full_ai_outputs: z.boolean().optional(),
	managed_zdr_required: z.boolean().optional(),
	privacy_setup_profile: z
		.enum(['balanced', 'privacy_focused', 'maximum_privacy', 'maximum_visibility', 'custom'])
		.optional(),
	privacy_setup_completed_at: nullableTextSchema.optional()
});
const pluginSettingsUpdateRequestSchema = pluginSettingsSchema.strict();
const pluginSettingsUpdateResponseSchema = z.union([
	pluginSettingsSchema,
	z.object({ settings: pluginSettingsSchema })
]);
const asyncHealthSchema = z.object({
	queue_depth: z.number().int(),
	oldest_run_at: z.number().nullable(),
	recent_failures: z.record(z.string(), z.number()),
	warnings: z.array(z.object({ code: z.string(), level: z.string(), message: z.string() }))
});
const asyncPurgeResponseSchema = z.object({ removed: z.number().int(), message: z.string() });
const asyncPurgeRequestSchema = z.undefined();
const providerCredentialDeleteSchema = z.object({
	deleted: z.boolean(),
	credential: localProviderCredentialSchema
});
const openRouterModelCacheItemSchema = z.object({
	id: z.string(),
	name: z.string(),
	free: z.boolean(),
	context_length: z.number().nullable(),
	input_modalities: z.array(z.string()),
	output_modalities: z.array(z.string()),
	supported_parameters: z.array(z.string()),
	pricing: z.record(z.string(), z.string()),
	fetched_at: nullableTextSchema,
	expires_at: nullableTextSchema,
	stale: z.boolean(),
	zdr_eligible: z.boolean().nullable().optional().default(null),
	zdr_source: nullableTextSchema.optional().default(null),
	zdr_checked_at: nullableTextSchema.optional().default(null),
	tags: z.array(z.string()).optional().default([])
});
const openRouterModelsBoundarySchema = z.object({
	provider: z.literal('openrouter'),
	source: z.literal('local_cache'),
	total_cached: z.number().int(),
	total_returned: z.number().int(),
	free_count: z.number().int(),
	stale_count: z.number().int(),
	zdr_filtered: z.boolean().optional(),
	models: z.array(openRouterModelCacheItemSchema),
	refresh_consent: z
		.object({
			state: z.enum(['accepted', 'missing']),
			disclosure_version: nullableTextSchema,
			consent_id: z.number().int().nullable(),
			accepted_at: nullableTextSchema
		})
		.optional(),
	consent_recorded: z.boolean().optional(),
	consent_id: z.number().int().optional(),
	stored: z.number().int().optional(),
	warnings: z.array(z.object({ code: z.string(), message: z.string() })).optional()
});
const openRouterModelsRefreshRequestSchema = z.strictObject({
	disclosure_version: z.string().min(1),
	accepted_external_service_terms: z.literal(true),
	output_modalities: z.string().optional(),
	supported_parameters: z.string().optional()
});
const localActionTemplateSchema = z.object({
	id: z.number().int(),
	source: nullableTextSchema,
	external_id: nullableTextSchema,
	code: nullableTextSchema,
	display_name: nullableTextSchema,
	description: nullableTextSchema,
	prompt_template: nullableTextSchema,
	default_model: nullableTextSchema,
	structured_output_schema: nullableJsonRecordValueSchema,
	override_schema: nullableJsonRecordValueSchema,
	version: nullableTextSchema,
	is_active: z.boolean(),
	created_at: nullableTextSchema,
	updated_at: nullableTextSchema
});
const localCustomActionRecordSchema = z.object({
	id: z.number().int(),
	external_id: nullableTextSchema,
	template_id: z.number().int().nullable(),
	code: nullableTextSchema,
	display_name: nullableTextSchema,
	definition_json: nullableJsonRecordValueSchema,
	model_selection_json: nullableJsonRecordValueSchema,
	status: nullableTextSchema,
	created_at: nullableTextSchema,
	updated_at: nullableTextSchema
});
const localCustomActionCreateRequestSchema = z.strictObject({
	external_id: nullableTextSchema.optional(),
	template_id: z.number().int().nullable().optional(),
	code: z.string(),
	display_name: z.string(),
	definition_json: jsonRecordValueSchema,
	model_selection_json: nullableJsonRecordValueSchema.optional(),
	status: z.string().optional()
});
const localFormMappingRecordSchema = z.object({
	id: z.number().int(),
	external_id: nullableTextSchema,
	form_source: nullableTextSchema,
	form_id: nullableTextSchema,
	hook: nullableTextSchema,
	action_kind: nullableTextSchema,
	action_id: z.number().int().nullable(),
	conditions_json: nullableJsonRecordValueSchema,
	input_bindings_json: nullableJsonRecordValueSchema,
	execution_mode: nullableTextSchema,
	effect_mapping_json: nullableJsonRecordValueSchema,
	enabled: z.boolean(),
	created_at: nullableTextSchema,
	updated_at: nullableTextSchema
});
const localFormMappingCreateRequestSchema = z.strictObject({
	external_id: nullableTextSchema.optional(),
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]),
	hook: z.string(),
	action_kind: z.string(),
	action_id: z.number().int(),
	conditions_json: nullableJsonRecordValueSchema.optional(),
	input_bindings_json: jsonRecordValueSchema,
	execution_mode: z.string().optional(),
	effect_mapping_json: nullableJsonRecordValueSchema.optional(),
	enabled: z.boolean().optional()
});
const localExecutionEventSchema = z.object({
	id: z.number().int(),
	execution_request_id: nullableTextSchema,
	mapping_id: z.number().int().nullable(),
	form_source: nullableTextSchema,
	form_id: nullableTextSchema,
	entry_id: nullableTextSchema,
	provider: nullableTextSchema,
	model: nullableTextSchema,
	status: nullableTextSchema,
	token_usage_json: nullableJsonRecordValueSchema,
	cost_json: nullableJsonRecordValueSchema,
	result_json: nullableJsonRecordValueSchema,
	error_code: nullableTextSchema,
	error_message: nullableTextSchema,
	payload_digest: nullableTextSchema,
	created_at: nullableTextSchema,
	updated_at: nullableTextSchema,
	expires_at: nullableTextSchema
});
const leadCriteriaSchema = z.object({
	summary_text: z.string(),
	must_have_signals: z.array(z.string()).optional(),
	disqualifiers: z.array(z.string()).optional(),
	updated_at: z.string().optional()
});
const leadHandoffRulesSchema = z.object({
	email_recipients: z.array(z.string()),
	webhooks: z.array(z.object({ url: z.string(), method: z.string().optional() })),
	grades: z.array(z.enum(['A', 'B', 'C', 'Reject'])),
	entry_notes: z
		.object({ lead_grade: z.boolean().optional(), suggested_reply: z.boolean().optional() })
		.optional(),
	reply_rules: z.object({ skip_reject_grade: z.boolean().optional() }).optional()
});
const readinessRequirementSchema = z.object({
	key: z.string(),
	label: z.string(),
	met: z.boolean(),
	severity: z.string(),
	detail: z.string()
});
const leadReadinessSchema = z.object({
	ready: z.boolean(),
	requirements: z.array(readinessRequirementSchema),
	blockers: z.array(readinessRequirementSchema),
	site_context: z.object({
		summary_text: z.string(),
		word_count: z.number().int(),
		consented: z.boolean(),
		consent_status: z.string(),
		source: nullableTextSchema.optional(),
		updated_at: nullableTextSchema.optional()
	}),
	spam_guidance: z.object({
		positive_count: z.number().int(),
		negative_count: z.number().int(),
		positive: z.array(jsonRecordValueSchema).optional(),
		negative: z.array(jsonRecordValueSchema).optional(),
		sources: jsonRecordValueSchema.optional()
	}),
	good_word_count: z.number().int(),
	bad_word_count: z.number().int()
});
const leadProfileRecordSchema = z.object({
	id: z.number().int(),
	form_source: z.string(),
	form_id: z.string(),
	status: z.string(),
	profile_version: z.number().int(),
	consented_at: nullableTextSchema,
	site_context_snapshot: nullableJsonRecordValueSchema.optional(),
	spam_guidance_snapshot: nullableJsonRecordValueSchema.optional(),
	good_lead_criteria: leadCriteriaSchema,
	bad_lead_criteria: leadCriteriaSchema,
	grading_rubric: nullableJsonRecordValueSchema.optional(),
	example_entries: z.array(jsonRecordValueSchema),
	generated_profile_prompt: nullableTextSchema.optional(),
	generation_metadata: nullableJsonRecordValueSchema.optional(),
	assistant: z
		.object({
			status: z.string().optional(),
			generated_at: z.string().optional(),
			questions: z
				.array(z.object({ key: z.string(), question: z.string(), why: z.string() }))
				.optional(),
			recommendations: z.array(z.string()).optional()
		})
		.nullable()
		.optional(),
	handoff_rules: leadHandoffRulesSchema,
	created_by_user_id: z.number().int().nullable().optional(),
	created_at: nullableTextSchema.optional(),
	updated_at: nullableTextSchema.optional()
});
const leadProfileResponseSchema = z.object({
	profile: leadProfileRecordSchema.nullable(),
	readiness: leadReadinessSchema,
	dashboard: z.lazy(() => leadDashboardSchema).optional(),
	generation_job: z
		.object({
			id: z.string(),
			status: z.string(),
			action_scheduler_id: z.number().int().nullable().optional()
		})
		.optional(),
	self_improvement: jsonRecordValueSchema.optional(),
	assistant: jsonRecordValueSchema.optional(),
	entry: jsonValueSchema.optional()
});
const leadEntrySchema = z.object({
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]),
	form_title: nullableTextSchema.optional(),
	provider_label: nullableTextSchema.optional(),
	entry_id: z.string(),
	entry_snapshot: z
		.object({
			date_created: nullableTextSchema.optional(),
			status: nullableTextSchema.optional(),
			field_summary: z
				.array(z.object({ field_id: z.string(), label: z.string(), value: z.string() }))
				.optional()
		})
		.optional(),
	updated_at: nullableTextSchema.optional(),
	grade: z.union([z.enum(['A', 'B', 'C', 'Reject']), z.literal(''), z.null()]).optional(),
	confidence: z.number().nullable().optional(),
	priority: nullableTextSchema.optional(),
	fit_summary: nullableTextSchema.optional(),
	intent_summary: nullableTextSchema.optional(),
	justification: nullableTextSchema.optional(),
	next_best_action: nullableTextSchema.optional(),
	suggested_reply_draft: nullableTextSchema.optional(),
	reply_rationale: nullableTextSchema.optional(),
	do_not_send: z.union([z.boolean(), z.number(), z.null()]).optional(),
	lead_profile_id: z.number().int().nullable().optional(),
	profile_version: z.number().int().nullable().optional(),
	historical_run_id: z.number().int().nullable().optional(),
	lead_execution_id: nullableTextSchema.optional(),
	reply_execution_id: nullableTextSchema.optional(),
	correction: z
		.object({
			grade: z.string().optional(),
			justification: z.string().optional(),
			original_grade: z.string().optional(),
			original_justification: z.string().optional(),
			corrected_by_user_id: z.number().int().nullable().optional(),
			corrected_at: nullableTextSchema.optional()
		})
		.optional()
});
const leadHistoricalRunSchema = z.object({
	id: z.number().int(),
	form_source: z.string(),
	form_id: z.string(),
	action_code: z.string(),
	lead_profile_id: z.number().int().nullable().optional(),
	selected_entry_ids: z.array(z.string()),
	filters: jsonRecordValueSchema,
	estimated_entry_count: z.number().int(),
	estimated_managed_credits: z.number().nullable().optional(),
	estimated_direct_provider_cost: nullableJsonRecordValueSchema.optional(),
	dry_run: z.boolean(),
	status: z.string(),
	progress: z
		.object({
			processed: z.number().optional(),
			total: z.number().optional(),
			errors: z.array(jsonRecordValueSchema).optional()
		})
		.nullable()
		.optional(),
	result_summary: nullableJsonRecordValueSchema.optional(),
	created_by_user_id: z.number().int().nullable().optional(),
	created_at: nullableTextSchema.optional(),
	updated_at: nullableTextSchema.optional()
});
const leadFormSummarySchema = z.object({
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]),
	form_title: nullableTextSchema.optional(),
	provider_label: nullableTextSchema.optional(),
	scored_leads: z.number().int(),
	priority_leads: z.number().int(),
	reply_drafts: z.number().int(),
	latest_at: nullableTextSchema.optional(),
	profile_id: z.number().int().nullable().optional(),
	profile_version: z.number().int().nullable().optional(),
	setup_status: nullableTextSchema.optional()
});
const leadDashboardSchema = z.object({
	form_source: z.string(),
	form_id: z.string(),
	event_count: z.number().int(),
	successful_events: z.number().int(),
	failed_events: z.number().int(),
	grades: z.record(z.string(), z.number()),
	suggested_replies: z.number().int(),
	metrics: z
		.object({
			scored_leads: z.number().int(),
			priority_leads: z.number().int(),
			reply_drafts: z.number().int(),
			rejected_leads: z.number().int(),
			grades: z.record(z.string(), z.number()),
			latest_entries: z.array(leadEntrySchema).optional()
		})
		.optional(),
	entries: z.array(leadEntrySchema).optional(),
	entry_page: z.number().int().optional(),
	entry_per_page: z.number().int().optional(),
	entry_total: z.number().int().optional(),
	entry_pages: z.number().int().optional(),
	forms: z.array(leadFormSummarySchema).optional(),
	unconfigured_forms: z.array(leadFormSummarySchema).optional(),
	historical_runs: z.array(leadHistoricalRunSchema)
});
const leadCorrectionResponseSchema = leadProfileResponseSchema.partial().extend({
	entry: leadEntrySchema.optional(),
	dashboard: leadDashboardSchema.optional()
});
const leadProfileSaveRequestSchema = z.strictObject({
	lead_profile_consent: z.boolean().optional(),
	good_lead_criteria: leadCriteriaSchema.optional(),
	bad_lead_criteria: leadCriteriaSchema.optional(),
	example_entries: z.array(jsonRecordValueSchema).optional(),
	handoff_rules: leadHandoffRulesSchema.partial().optional(),
	generation_settings: z
		.object({ model: z.string(), reasoning_effort: z.string().optional() })
		.optional(),
	self_improvement: z
		.object({
			consent: z.boolean(),
			frequency: z.string(),
			review_required: z.boolean().optional()
		})
		.optional()
});
const leadProfileGenerateRequestSchema = z.strictObject({
	lead_profile_consent: z.boolean().optional(),
	async: z.boolean().optional()
});
const leadProfileSelfImproveRequestSchema = z.strictObject({
	async: z.boolean().optional(),
	force: z.boolean().optional()
});
const leadCorrectionRequestSchema = z.strictObject({
	grade: z.enum(['A', 'B', 'C', 'Reject']),
	justification: z.string()
});
const leadImportRequestSchema = z.strictObject({
	source_profile_id: z.number().int().positive(),
	include_examples: z.boolean().optional()
});
const leadHistoricalRunCreateRequestSchema = z.strictObject({
	action_code: z.string().optional(),
	lead_profile_id: z.number().int().nullable().optional(),
	entry_ids: z.array(z.union([z.string(), z.number()])).optional(),
	filters: jsonRecordValueSchema.optional(),
	dry_run: z.boolean().optional()
});
const leadHistoricalRunStartRequestSchema = z.strictObject({
	confirm_costs: z.boolean().optional()
});
const leadEntrySearchResponseSchema = z.object({
	entries: z.array(
		z.object({
			id: z.string(),
			date_created: nullableTextSchema.optional(),
			status: nullableTextSchema.optional(),
			field_summary: z.array(
				z.object({ field_id: z.string(), label: z.string(), value: z.string() })
			)
		})
	),
	form_source: z.string(),
	form_id: z.number().int()
});
const leadHistoricalRunResponseSchema = z.object({
	run: leadHistoricalRunSchema,
	message: z.string().optional()
});
const suggestedReplyResponseSchema = z.object({
	execution: jsonValueSchema.optional(),
	entry: jsonValueSchema.optional(),
	dashboard: leadDashboardSchema.optional()
});
const localSupportBundleSchema = z.object({
	generated_at: z.string().optional(),
	plugin: jsonRecordValueSchema.optional(),
	wordpress: jsonRecordValueSchema.optional(),
	local_tables: z.record(z.string(), z.number().nullable()).optional(),
	providers: z.array(jsonRecordValueSchema).optional(),
	external_consents: z.record(z.string(), nullableJsonRecordValueSchema).optional(),
	execution_summary: z
		.object({
			total: z.number().optional(),
			succeeded: z.number().optional(),
			failed: z.number().optional()
		})
		.optional(),
	retention: jsonRecordValueSchema.optional()
});
const dashboardSummaryBoundarySchema = z.object({
	generated_at: z.string(),
	providers: z.array(localProviderCredentialSchema),
	templates: z.array(localActionTemplateSchema),
	custom_actions: z.array(localCustomActionRecordSchema),
	recent_events: z.array(localExecutionEventSchema),
	section_errors: z
		.array(z.object({ section: z.string(), code: z.string(), message: z.string() }))
		.optional(),
	license: z
		.object({
			status: z.string(),
			tier: tierValueSchema.nullable().optional(),
			proxy_key_present: z.boolean().optional(),
			license_id: nullableTextSchema.optional(),
			site_id: nullableTextSchema.optional()
		})
		.optional(),
	async_health: asyncHealthSchema.optional()
});
const migrationWarningSchema = z.object({ code: z.string(), message: z.string() });
const migrationOptionReportSchema = z.object({
	exists: z.boolean().optional(),
	will_delete: z.boolean().optional(),
	value_shape: z.string().optional(),
	value_length: z.number().int().nullable().optional(),
	count: z.number().int().optional(),
	sample: z.array(z.string()).optional()
});
const migrationReadinessSchema = z.object({
	generated_at: z.string(),
	source: z.string(),
	source_version: nullableTextSchema,
	confirmation_phrase: z.string(),
	ready_for_reset: z.boolean(),
	ready_for_local_execution: z.boolean(),
	local_tables: z.record(z.string(), z.number().int().nullable()),
	runtime_tables: z.record(z.string(), z.number().int().nullable()),
	legacy_options: z.object({
		exact_options: z.record(z.string(), migrationOptionReportSchema),
		option_prefixes: z.record(z.string(), migrationOptionReportSchema)
	}),
	settings: jsonRecordValueSchema,
	reset_plan: z.object({
		tables_cleared: z.array(z.string()),
		tables_preserved_by_default: z.array(z.string()),
		exact_options_deleted: z.array(z.string()),
		option_prefixes_deleted: z.array(z.string()),
		settings_preserved: z.array(z.string())
	}),
	warnings: z.array(migrationWarningSchema)
});
const migrationDryRunSchema = z.object({
	run_id: z.number().int(),
	status: z.string(),
	report: migrationReadinessSchema
});
const migrationApprovedResetRequestSchema = z.strictObject({
	confirmation_phrase: z.string().min(1)
});
const migrationApprovedResetSchema = z.object({
	run_id: z.number().int(),
	status: z.string(),
	before: migrationReadinessSchema,
	after: migrationReadinessSchema,
	deleted_tables: z.record(z.string(), z.number().int().nullable()),
	deleted_options: z.object({
		exact_options: z.record(z.string(), z.boolean()),
		option_prefixes: z.record(
			z.string(),
			z.object({ count: z.number().int(), sample: z.array(z.string()) })
		)
	}),
	preserved: z.array(z.string())
});
const spamGuidanceFieldSchema = z.object({
	field_id: z.string(),
	label: z.string(),
	value: z.string()
});
const spamGuidanceEntrySchema = z.object({
	id: z.union([z.string(), z.number()]).transform(String),
	source_type: z.enum(['native', 'ledger']),
	submission_uuid: nullableTextSchema.optional(),
	native_entry_id: nullableTextSchema.optional(),
	native_entry_url: nullableTextSchema.optional(),
	date_created: nullableTextSchema.optional(),
	status: nullableTextSchema.optional(),
	field_summary: z.array(spamGuidanceFieldSchema)
});
const spamGuidanceSearchSchema = z.object({
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]).transform(String),
	availability: z.object({
		source: z.enum(['native', 'ledger']),
		native_read: z.boolean(),
		ledger_read: z.boolean(),
		ledger_enabled: z.boolean().optional(),
		unavailable_reason: nullableTextSchema.optional(),
		native_unavailable_reason: nullableTextSchema.optional()
	}),
	entries: z.array(spamGuidanceEntrySchema)
});
const spamGuidanceExampleSourceBoundarySchema = z.object({
	kind: z.enum(['manual', 'entry']),
	form_source: z.string().optional(),
	form_id: z.string().optional(),
	entry_id: z.string().optional(),
	native_entry_id: nullableTextSchema.optional(),
	selected_at: z.string().optional(),
	selected_by_user_id: z.number().int().nullable().optional()
});
const spamGuidanceExampleBoundarySchema = z.object({
	text: z.string(),
	rationale: z.string(),
	source: spamGuidanceExampleSourceBoundarySchema.optional()
});
const modelSelectionBoundarySchema = modelSelectionSchema;
const realtimeSettingsBoundarySchema = z.object({
	auto_refresh_enabled: z.boolean().optional(),
	field_checkpoints_enabled: z.boolean().optional(),
	checkpoint_field_ids: z.array(z.string()).optional(),
	page_checkpoints_enabled: z.boolean().optional(),
	page_checkpoint_mode: z.enum(['all_pages', 'include_pages', 'exclude_pages']).optional(),
	page_checkpoint_pages: z.array(z.number().int()).optional(),
	page_checkpoint_timeout_ms: z.number().optional(),
	storage_target_field_id: z.string().optional(),
	debounce_ms: z.number().optional(),
	cooldown_ms: z.number().optional(),
	manual_refresh_enabled: z.boolean().optional(),
	blocking_mode: z.enum(['advisory', 'require_answers']).optional(),
	refresh_mode: z.enum(['auto', 'checkpoint', 'manual']).optional(),
	initial_panel_state: z.enum(['open', 'minimized', 'hidden_until_interaction']).optional(),
	hidden_field_exposure_mode: z
		.enum(['omit_hidden', 'label_hidden', 'label_hidden_value', 'label_value'])
		.optional(),
	pre_submit_run_enabled: z.boolean().optional(),
	pre_submit_timeout_ms: z.number().optional()
});
const formActionConfigBoundarySchema = z.object({
	spam_positive_examples: z.array(spamGuidanceExampleBoundarySchema).optional(),
	spam_negative_examples: z.array(spamGuidanceExampleBoundarySchema).optional(),
	suppress_notifications_on_spam: z.boolean().optional(),
	suppress_webhooks_on_spam: z.boolean().optional(),
	skip_downstream_on_spam: z.boolean().optional(),
	action_customization: z.string().optional(),
	spam_result_display_mode: z.string().optional(),
	spam_indicators_display: z.string().optional(),
	include_site_context: z.enum(['global', 'always', 'never']).optional(),
	model_selection: modelSelectionBoundarySchema.optional(),
	model_override: z.string().optional(),
	realtime_settings: realtimeSettingsBoundarySchema.optional(),
	updated_at: z.string().optional()
});
const spamGuidanceAppendRequestSchema = z.strictObject({
	target_scope: z.enum(['form', 'mapping', 'action']),
	label: z.enum(['ham', 'spam']),
	entry_id: z.string().optional(),
	text: z.string().optional(),
	rationale: z.string().optional(),
	mapping_id: z.union([z.number(), z.string()]).optional()
});
const spamGuidanceAppendBoundarySchema = z.object({
	target_scope: z.enum(['form', 'mapping', 'action']),
	label: z.enum(['ham', 'spam']),
	config: formActionConfigBoundarySchema,
	generation: z
		.object({ route: z.string().optional(), model: nullableTextSchema.optional() })
		.nullable()
		.optional()
});
const conditionOperatorBoundarySchema = z.enum([
	'eq',
	'neq',
	'contains',
	'not_contains',
	'starts_with',
	'ends_with',
	'in',
	'not_in',
	'is_empty',
	'is_not_empty',
	'gt',
	'gte',
	'lt',
	'lte'
]);
const conditionRuleBoundarySchema = z.object({
	type: z.literal('rule'),
	field_id: z.string(),
	operator: conditionOperatorBoundarySchema,
	value: z.union([z.string(), z.number(), z.array(z.union([z.string(), z.number()]))]).optional()
});
type ConditionNodeBoundary =
	| {
			type: 'rule';
			field_id: string;
			operator: z.infer<typeof conditionOperatorBoundarySchema>;
			value?: string | number | Array<string | number>;
	  }
	| { type: 'group'; logic: 'all' | 'any'; rules: ConditionNodeBoundary[] };
const conditionNodeBoundarySchema: z.ZodType<ConditionNodeBoundary> = z.lazy(() =>
	z.union([
		conditionRuleBoundarySchema,
		z.object({
			type: z.literal('group'),
			logic: z.enum(['all', 'any']),
			rules: z.array(conditionNodeBoundarySchema)
		})
	])
);
const conditionsBoundarySchema = z.object({
	enabled: z.boolean(),
	root: z.object({
		type: z.literal('group'),
		logic: z.enum(['all', 'any']),
		rules: z.array(conditionNodeBoundarySchema)
	})
});
const postExecutionActionBoundarySchema = z.object({
	type: z.enum(['entry_note', 'send_email', 'wp_hook', 'webhook']),
	enabled: z.boolean().optional(),
	message: z.string().optional(),
	template: z.string().optional(),
	to: z.union([z.string(), z.array(z.string())]).optional(),
	recipients: z.union([z.string(), z.array(z.string())]).optional(),
	subject: z.string().optional(),
	body: z.string().optional(),
	hook_name: z.string().optional(),
	url: z.string().optional(),
	method: z.enum(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']).optional(),
	headers: z.record(z.string(), z.string()).optional()
});
const formActionSettingsBoundarySchema = z.object({
	input_mapping: z
		.object({
			mode: z.enum(['all', 'selected', 'exclude']),
			field_ids: z.array(z.string()).optional(),
			include_metadata: z.boolean().optional()
		})
		.optional(),
	attachment_mapping: z
		.object({
			mode: z.enum(['none', 'gf_upload', 'media_library', 'mixed']),
			gf_upload_field_ids: z.array(z.string()).optional(),
			media_ids: z.array(z.number().int()).optional(),
			max_files: z.number().int().optional()
		})
		.optional(),
	dependency_ids: z.array(z.string()).optional(),
	trigger_sources: z
		.record(
			z.string(),
			z.object({ type: z.enum(['hook_root', 'mapping']), mapping_id: z.string().optional() })
		)
		.optional(),
	skip_on_upstream_spam: z.boolean().optional(),
	suppress_notifications_on_spam: z.boolean().optional(),
	suppress_webhooks_on_spam: z.boolean().optional(),
	skip_downstream_on_spam: z.boolean().optional(),
	spam_result_display_mode: z.string().optional(),
	spam_indicators_display: z.string().optional(),
	spam_positive_examples: z.array(spamGuidanceExampleBoundarySchema).optional(),
	spam_negative_examples: z.array(spamGuidanceExampleBoundarySchema).optional(),
	action_customization: z.string().optional(),
	conditions: conditionsBoundarySchema.optional(),
	prompt_overrides: jsonRecordValueSchema.optional(),
	post_execution_actions: z.array(postExecutionActionBoundarySchema).optional(),
	execution_mode: z.enum(['validation', 'after_submission', 'real_time']).optional(),
	realtime_settings: realtimeSettingsBoundarySchema.optional(),
	batch_settings: z
		.object({ enabled: z.boolean(), delay_seconds: z.number(), max_wait_seconds: z.number() })
		.optional(),
	linked_action_status: z.string().optional(),
	repair_state: z.string().optional()
});
const formActionLinkageBoundarySchema = z.object({
	local_mapping_id: z.string(),
	central_action_id: z.string(),
	action_type_indicator: z.enum(['master', 'custom', 'local_first']),
	trigger_hooks: z.array(z.string()),
	is_action_enabled_for_form: z.boolean().optional(),
	execution_priority: z.number().int().optional(),
	action_name_label: z.string().optional(),
	execution_mode: z.enum(['validation', 'after_submission', 'real_time']).optional(),
	linked_action_status: z.string().optional(),
	repair_state: z.string().optional(),
	settings: formActionSettingsBoundarySchema.optional()
});
const formActionMutationRequestSchema = formActionLinkageBoundarySchema
	.pick({
		central_action_id: true,
		action_type_indicator: true,
		trigger_hooks: true,
		is_action_enabled_for_form: true,
		execution_priority: true,
		action_name_label: true,
		settings: true
	})
	.partial()
	.strict();
const overrideKeySchemaBoundary = z.object({
	type: z.enum(['enum', 'string', 'number', 'boolean']),
	options: z.array(z.string()).optional(),
	default: jsonValueSchema.optional(),
	description: z.string().optional(),
	min: z.number().optional(),
	max: z.number().optional(),
	category: z.enum(['behavior', 'output', 'model', 'context', 'advanced']).optional()
});
const actionDefinitionBoundarySchema = z.object({
	id: z.string(),
	templateId: nullableTextSchema.optional(),
	label: z.string().optional(),
	description: z.string().optional(),
	hooks: z.union([z.record(z.string(), z.string()), z.array(z.string())]).optional(),
	source: z.enum(['cps', 'bundled', 'imported']).optional(),
	baseCreditCost: z.number().nullable().optional(),
	modelHint: nullableTextSchema.optional(),
	overrideSchema: z.record(z.string(), overrideKeySchemaBoundary).optional(),
	promptTemplate: nullableTextSchema.optional(),
	structuredOutputSchema: nullableJsonRecordValueSchema.optional(),
	category: z.enum(['content_quality', 'data_processing', 'automation', 'custom']).optional()
});
const customActionBoundarySchema = z.object({
	id: z.string(),
	template_id: nullableTextSchema,
	code: z.string(),
	display_name: z.string(),
	description: nullableTextSchema,
	prompt_overrides: jsonRecordValueSchema,
	model_hint: nullableTextSchema,
	model_selection: modelSelectionBoundarySchema.nullable().optional(),
	base_credit_cost: z.number().nullable(),
	status: z.enum(['active', 'archived']),
	archived_at: nullableTextSchema,
	created_at: z.string(),
	updated_at: z.string(),
	action_kind: z.enum(['template_override', 'custom_definition']),
	definition: nullableJsonRecordValueSchema,
	definition_version: z.number().int(),
	output_contract: nullableJsonRecordValueSchema,
	supported_execution_modes: z.array(z.enum(['validation', 'after_submission', 'real_time']))
});
const customActionQuotaBoundarySchema = z.object({
	quota_max: z.number().int(),
	quota_used: z.number().int(),
	quota_remaining: z.number().int()
});
const formSummaryBoundarySchema = z.object({
	id: z.union([z.string(), z.number()]),
	title: z.string(),
	adapter: z.string(),
	adapter_name: z.string().optional(),
	provider_is_active: z.boolean().optional(),
	provider_edit_url: nullableTextSchema.optional(),
	settings: nullableJsonRecordValueSchema.optional()
});
const lifecycleDescriptorBoundarySchema = z.object({
	id: z.string().optional(),
	supported: z.boolean(),
	label: z.string(),
	native_hook: nullableTextSchema,
	execution_mode: z.enum(['blocking', 'async', 'real_time']),
	requires_ledger: z.boolean(),
	unsupported_reason: nullableTextSchema
});
export const formSourceDescriptorBoundarySchema = z.object({
	slug: z.string(),
	label: z.string(),
	is_active: z.boolean(),
	availability: z.string().optional(),
	availability_message: nullableTextSchema.optional(),
	requires_pro: z.boolean().optional(),
	adapter_class: nullableTextSchema.optional(),
	capabilities: z
		.record(z.string(), z.union([z.boolean(), z.string(), z.number(), z.null()]))
		.optional(),
	lifecycles: z.record(z.string(), lifecycleDescriptorBoundarySchema),
	native_entry: z
		.object({ id: z.boolean(), link: z.boolean(), read: z.boolean(), write: z.boolean() })
		.optional(),
	native_enrichment: z
		.object({
			notes: z.boolean(),
			status: z.boolean(),
			spam: z.boolean(),
			notification_controls: z.boolean(),
			webhook_controls: z.boolean()
		})
		.optional(),
	ledger: z
		.object({
			required_for_parity: z.boolean(),
			enabled: z.boolean(),
			settings_source: z.string(),
			unavailable_reason: nullableTextSchema.optional()
		})
		.optional(),
	requirements: z
		.record(z.string(), z.union([z.boolean(), z.string(), z.number(), z.null()]))
		.optional()
});
const formExecutionStatusBoundarySchema = z.object({
	status: z.enum(['unknown', 'success', 'error']),
	message: nullableTextSchema,
	entry_id: z.number().int().nullable().optional(),
	last_error_code: nullableTextSchema,
	last_result: jsonValueSchema.optional(),
	updated_at: nullableTextSchema.optional()
});
const formDisableBoundarySchema = z.object({
	sf_disabled: z.boolean(),
	global_disabled: z.boolean().optional(),
	provider_disabled: z.boolean().optional(),
	effective_disabled: z.boolean().optional(),
	message: z.string().optional()
});
const formFieldBoundarySchema = z.object({
	id: z.string(),
	label: z.string(),
	type: z.string(),
	adminLabel: z.string().optional(),
	page_index: z.number().int().optional(),
	field_id_ambiguous: z.boolean().optional(),
	field_id_scope: z.string().optional(),
	field_id_ambiguity_reason: z.string().optional()
});
const capabilitiesBoundarySchema = z.object({
	supports_custom_actions: z.boolean().optional(),
	supports_status: z.boolean().optional(),
	supports_credits: z.boolean().optional(),
	cps_version: z.string().optional()
});
const submissionLedgerSettingsBoundarySchema = z.object({
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]).transform(String),
	enabled: z.boolean(),
	enabled_at: nullableTextSchema,
	enabled_by_user_id: z.number().int().nullable(),
	disabled_at: nullableTextSchema,
	disabled_by_user_id: z.number().int().nullable(),
	settings_source: z.string(),
	ledger_records_endpoint: z.string(),
	record_count: z.number().int().optional()
});
const submissionLedgerRunBoundarySchema = z.object({
	execution_request_id: z.string(),
	mapping_id: z.number().int().nullable().optional().default(null),
	status: z.string(),
	provider: nullableTextSchema,
	model: nullableTextSchema,
	last_result: nullableJsonRecordValueSchema,
	last_error_code: nullableTextSchema,
	last_error_message: nullableTextSchema,
	created_at: nullableTextSchema.optional().default(null),
	updated_at: nullableTextSchema.optional().default(null)
});
const submissionLedgerRecordBoundarySchema = z.object({
	id: z.number().int(),
	submission_uuid: z.string(),
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]).transform(String),
	native_entry_id: z
		.union([z.string(), z.number(), z.null()])
		.transform((value) => (value === null ? null : String(value))),
	native_entry_url: nullableTextSchema,
	source_submitted_at: nullableTextSchema,
	captured_at: z.string(),
	logical_fields: jsonRecordValueSchema,
	provider_metadata: jsonRecordValueSchema.nullish().transform((value) => value ?? {}),
	file_refs: z
		.array(jsonRecordValueSchema)
		.nullish()
		.transform((value) => value ?? []),
	redaction_summary: jsonRecordValueSchema.nullish().transform((value) => value ?? {}),
	action_runs: z
		.array(submissionLedgerRunBoundarySchema)
		.nullish()
		.transform((value) => value ?? []),
	expires_at: nullableTextSchema,
	detail_endpoint: z.string()
});
const submissionLedgerRecordsBoundarySchema = z
	.object({
		form_source: z.string(),
		form_id: z.union([z.string(), z.number()]).transform(String),
		records: z.array(submissionLedgerRecordBoundarySchema).optional(),
		submissions: z.array(submissionLedgerRecordBoundarySchema).optional(),
		total: z.number().int().optional(),
		count: z.number().int().optional(),
		per_page: z.number().int(),
		offset: z.number().int()
	})
	.transform((payload) => {
		const records = payload.records ?? payload.submissions ?? [];
		return {
			form_source: payload.form_source,
			form_id: payload.form_id,
			records,
			total: payload.total ?? payload.count ?? records.length,
			per_page: payload.per_page,
			offset: payload.offset
		};
	});
const formsOverviewBoundarySchema = z.object({
	form_source: z.string(),
	form_source_descriptor: formSourceDescriptorBoundarySchema.nullable().optional(),
	forms: z.array(
		formSummaryBoundarySchema.extend({
			actions: z.array(formActionLinkageBoundarySchema),
			action_count: z.number().int(),
			enabled_action_count: z.number().int(),
			execution_status: formExecutionStatusBoundarySchema
		})
	),
	generated_at: z.string()
});
const formActionsBootstrapBoundarySchema = z.object({
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]),
	form: formSummaryBoundarySchema.nullable().optional(),
	form_source_descriptor: formSourceDescriptorBoundarySchema.nullable().optional(),
	actions: z.array(formActionLinkageBoundarySchema),
	execution_status: formExecutionStatusBoundarySchema,
	disabled_state: formDisableBoundarySchema,
	capabilities: capabilitiesBoundarySchema.optional(),
	definitions: z.array(actionDefinitionBoundarySchema).optional(),
	custom_actions: z
		.object({
			actions: z.array(customActionBoundarySchema),
			quota: customActionQuotaBoundarySchema.nullable()
		})
		.optional(),
	provider_credentials: z.array(localProviderCredentialSchema).optional(),
	form_action_configs: z.record(z.string(), formActionConfigBoundarySchema).optional(),
	form_fields: z.array(formFieldBoundarySchema).optional(),
	action_defaults: z.record(z.string(), formActionConfigBoundarySchema).optional(),
	provider_path_policy: providerPathPolicyResponseSchema.optional(),
	ledger_settings: submissionLedgerSettingsBoundarySchema.optional(),
	generated_at: z.string()
});
const workflowPlanWaveBoundarySchema = z.object({
	level: z.number().int(),
	mapping_ids: z.array(z.string())
});
const workflowBlockedBoundarySchema = z.object({
	mapping_id: z.string(),
	reason: z.enum([
		'disabled',
		'missing_dependency',
		'cycle',
		'upstream_blocked',
		'policy_violation'
	]),
	details: z.string().optional()
});
const workflowNodeBoundarySchema = z.object({
	mapping_id: z.string(),
	label: z.string(),
	central_action_id: z.string(),
	trigger_hooks: z.array(z.string()),
	dependency_ids: z.array(z.string()),
	trigger_sources: z
		.record(
			z.string(),
			z.object({ type: z.enum(['hook_root', 'mapping']), mapping_id: z.string().optional() })
		)
		.optional(),
	is_enabled: z.boolean(),
	is_async: z.boolean()
});
const workflowHookBoundarySchema = z.object({
	hook: z.string(),
	order: z.array(z.string()),
	waves: z.array(workflowPlanWaveBoundarySchema),
	runnable: z.array(z.string()),
	blocked: z.array(workflowBlockedBoundarySchema),
	cycle_ids: z.array(z.string())
});
const workflowViolationBoundarySchema = z.object({
	mapping_id: z.string(),
	dependency_id: z.string(),
	code: z.string(),
	message: z.string()
});
const workflowPlanBoundarySchema = z.object({
	authority: z.enum(['cps', 'local']),
	authority_reason: nullableTextSchema.optional(),
	cps_unreachable: z.boolean(),
	policy_version: z.string(),
	hook_scope: z.string(),
	available_hooks: z.array(z.string()),
	nodes: z.array(workflowNodeBoundarySchema),
	edges: z.array(
		z.object({
			from: z.string(),
			to: z.string(),
			kind: z.enum(['dependency', 'hook_root']),
			hook: z.string().optional()
		})
	),
	hooks: z.array(workflowHookBoundarySchema),
	policy_violations: z.array(workflowViolationBoundarySchema)
});
const formActionsBootstrapFullBoundarySchema = formActionsBootstrapBoundarySchema.extend({
	workflow_plan: workflowPlanBoundarySchema.nullable().optional()
});
type ConditionTraceBoundary =
	| {
			type: 'group';
			logic: 'all' | 'any';
			result: boolean;
			reason_code?: string;
			children: ConditionTraceBoundary[];
	  }
	| {
			type: 'rule';
			field_id?: string;
			operator?: string;
			actual?: string | number | boolean | null;
			expected?: z.infer<typeof jsonValueSchema>;
			result: boolean;
			reason_code?: string;
	  }
	| { type: 'invalid'; result: boolean; reason_code?: string };
const conditionTraceBoundarySchema: z.ZodType<ConditionTraceBoundary> = z.lazy(() =>
	z.discriminatedUnion('type', [
		z.object({
			type: z.literal('group'),
			logic: z.enum(['all', 'any']),
			result: z.boolean(),
			reason_code: z.string().optional(),
			children: z.array(conditionTraceBoundarySchema)
		}),
		z.object({
			type: z.literal('rule'),
			field_id: z.string().optional(),
			operator: z.string().optional(),
			actual: z.union([z.string(), z.number(), z.boolean(), z.null()]).optional(),
			expected: z.json().optional(),
			result: z.boolean(),
			reason_code: z.string().optional()
		}),
		z.object({
			type: z.literal('invalid'),
			result: z.boolean(),
			reason_code: z.string().optional()
		})
	])
);
const conditionTraceResultBoundarySchema = z.object({
	should_execute: z.boolean().nullable(),
	enabled: z.boolean(),
	evaluated: z.boolean(),
	matched: z.boolean().nullable(),
	reason_code: z.string(),
	summary: z.string(),
	tree: conditionTraceBoundarySchema.nullable().optional()
});
const requestTraceBoundarySchema = z.object({
	authority: z.literal('wp_rest'),
	policy_version: z.string(),
	hook_scope: z.string(),
	available_hooks: z.array(z.string()),
	input: z.object({
		source: z.enum(['empty', 'manual', 'entry_import', 'entry_import_with_manual_overrides']),
		entry_id: z.number().int().nullable().optional(),
		field_scope: z.literal('mapped_and_rule'),
		values: z.record(z.string(), z.string()),
		manual_field_ids: z.array(z.string()),
		imported_field_ids: z.array(z.string()),
		overridden_field_ids: z.array(z.string()),
		warnings: z.array(z.string()),
		include_drafts: z.boolean(),
		draft_applied: z.boolean()
	}),
	hooks: z.array(
		z.object({
			hook: z.string(),
			order: z.array(z.string()),
			waves: z.array(workflowPlanWaveBoundarySchema),
			runnable: z.array(z.string()),
			queued: z.array(z.string()),
			blocked: z.array(
				z.object({
					mapping_id: z.string(),
					reason: z.enum([
						'disabled',
						'missing_dependency',
						'cycle',
						'upstream_blocked',
						'policy_violation',
						'invalid_trigger',
						'condition_false'
					]),
					details: z.string().optional()
				})
			),
			cycle_ids: z.array(z.string()),
			steps: z.array(
				z.object({
					mapping_id: z.string(),
					label: z.string(),
					dependency_ids: z.array(z.string()),
					trigger_source: z
						.object({
							type: z.enum(['hook_root', 'mapping', 'unbound']),
							mapping_id: z.string().optional()
						})
						.optional(),
					execution_mode: z.enum(['validation', 'after_submission', 'real_time']),
					is_async: z.boolean(),
					outcome: z.enum(['would_run', 'would_queue', 'blocked']),
					block_reason: z
						.enum([
							'disabled',
							'missing_dependency',
							'cycle',
							'upstream_blocked',
							'policy_violation',
							'invalid_trigger',
							'condition_false'
						])
						.nullable()
						.optional(),
					block_details: nullableTextSchema.optional(),
					condition: conditionTraceResultBoundarySchema
				})
			)
		})
	),
	policy_violations: z.array(workflowViolationBoundarySchema)
});
const requestTraceRequestBoundarySchema = z.strictObject({
	hook_scope: z.string().optional(),
	entry_values: z
		.record(z.string(), z.union([z.string(), z.number(), z.boolean(), z.null()]))
		.optional(),
	entry_id: z.number().int().optional(),
	field_scope: z.literal('mapped_and_rule').optional(),
	include_drafts: z.boolean().optional(),
	draft_mappings: z.array(formActionLinkageBoundarySchema).optional()
});
const duplicateParentBoundarySchema = z.strictObject({
	type: z.enum(['hook_root', 'mapping']),
	hook: z.string(),
	mapping_id: z.string().optional()
});
const duplicateFormActionRequestBoundarySchema = z.strictObject({
	parent: duplicateParentBoundarySchema
});
const duplicateFormActionBoundarySchema = z.object({
	duplicate: formActionLinkageBoundarySchema,
	insertion: z.object({
		parent: duplicateParentBoundarySchema,
		moved_children: z.array(z.string()),
		skipped_children: z.array(
			z.object({ child_id: z.string(), hook: z.string(), code: z.string(), message: z.string() })
		),
		warnings: z.array(z.string())
	})
});
const formActionConfigResponseBoundarySchema = z.object({
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]),
	action_id: z.string(),
	config: formActionConfigBoundarySchema
});
const allFormActionConfigsBoundarySchema = z.object({
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]),
	configs: z.record(z.string(), formActionConfigBoundarySchema)
});
const actionDefaultsBatchBoundarySchema = z.object({
	defaults: z.record(z.string(), formActionConfigBoundarySchema),
	generated_at: z.string().optional()
});
const customActionCreateRequestBoundarySchema = z.strictObject({
	template_id: nullableTextSchema.optional(),
	code: z.string(),
	display_name: z.string(),
	description: nullableTextSchema.optional(),
	prompt_overrides: jsonRecordValueSchema.optional(),
	model_hint: nullableTextSchema.optional(),
	model_selection: modelSelectionBoundarySchema.nullable().optional(),
	action_kind: z.enum(['template_override', 'custom_definition']),
	definition: nullableJsonRecordValueSchema.optional(),
	definition_version: z.number().int(),
	output_contract: nullableJsonRecordValueSchema.optional(),
	supported_execution_modes: z.array(z.enum(['validation', 'after_submission', 'real_time']))
});
const customActionUpdateRequestBoundarySchema = customActionCreateRequestBoundarySchema
	.omit({ template_id: true, code: true })
	.partial()
	.extend({
		status: z.enum(['active', 'archived']).optional(),
		archived_at: nullableTextSchema.optional()
	})
	.strict();
const customActionListBoundarySchema = z.object({
	actions: z.array(customActionBoundarySchema),
	quota: customActionQuotaBoundarySchema
});
const customActionMutationBoundarySchema = z.object({
	action: customActionBoundarySchema,
	quota: customActionQuotaBoundarySchema
});
const mappingSettingsBoundarySchema = z.object({
	trigger_hooks: z.array(z.string()).optional(),
	dependency_ids: z.array(z.string()).optional(),
	input_mapping: z
		.object({
			mode: z.enum(['all', 'selected', 'exclude']),
			field_ids: z.array(z.string()).optional(),
			include_metadata: z.boolean().optional()
		})
		.optional(),
	attachment_mapping: z
		.object({
			mode: z.enum(['none', 'gf_upload', 'media_library', 'mixed']),
			gf_upload_field_ids: z.array(z.string()).optional(),
			media_ids: z.array(z.number().int()).optional(),
			max_files: z.number().int().optional()
		})
		.optional(),
	conditions: conditionsBoundarySchema.optional(),
	effect_mapping: z
		.record(
			z.string(),
			z.object({
				mark_spam: z.boolean().optional(),
				notify_admin: z.boolean().optional(),
				reject_submission: z.boolean().optional()
			})
		)
		.optional(),
	portable_fields: z.array(z.object({ label: z.string(), type: z.string() })).optional(),
	field_mapping: z.record(z.string(), z.string()).optional()
});
const formMappingBoundarySchema = z.object({
	id: z.string(),
	license_id: z.string(),
	site_id: nullableTextSchema,
	form_source: z.string(),
	form_id: z.number().int().nullable(),
	action_template_id: nullableTextSchema,
	custom_action_id: nullableTextSchema,
	display_name: z.string(),
	settings: mappingSettingsBoundarySchema,
	is_template: z.boolean(),
	created_at: z.string(),
	updated_at: z.string()
});
const createFormMappingRequestBoundarySchema = z.strictObject({
	site_id: nullableTextSchema.optional(),
	form_source: z.string(),
	form_id: z.number().int().nullable().optional(),
	action_template_id: nullableTextSchema.optional(),
	action_template_code: nullableTextSchema.optional(),
	custom_action_id: nullableTextSchema.optional(),
	display_name: z.string(),
	settings: mappingSettingsBoundarySchema,
	is_template: z.boolean().optional()
});
const updateFormMappingRequestBoundarySchema = z.strictObject({
	display_name: z.string().optional(),
	settings: mappingSettingsBoundarySchema.optional(),
	is_template: z.boolean().optional()
});
const cloneFormMappingRequestBoundarySchema = z.strictObject({
	site_id: z.string(),
	form_source: z.string(),
	form_id: z.union([z.string(), z.number()]),
	field_mapping: z.record(z.string(), z.string()).optional()
});
const workflowMeteringBoundarySchema = z.object({
	status: z.string(),
	credits_total: z.number(),
	credits_by_node: z.record(z.string(), z.number()),
	failed_nodes: z.array(z.string())
});
const meteringBoundarySchema = z.object({
	correlation_id: nullableTextSchema.optional(),
	execution_request_id: nullableTextSchema.optional(),
	credits_debited: z.number().nullable().optional(),
	pricing_policy_version: nullableTextSchema.optional(),
	workflow: workflowMeteringBoundarySchema.nullable().optional()
});
const entryExecutionStatusBoundarySchema = z.object({
	entry_id: z.number().int(),
	form_id: z.union([z.string(), z.number()]),
	last_response: jsonValueSchema,
	last_error: nullableTextSchema,
	processed_at: nullableTextSchema,
	status: z.enum(['unknown', 'success', 'error']),
	metering_summary: meteringBoundarySchema.nullable().optional()
});

export const endpointErrorSchema = z
	.object({
		code: z.string().optional(),
		error_code: z.string().optional(),
		message: z.string().optional(),
		error: z
			.object({
				code: z.string().optional(),
				message: z.string().optional(),
				issues: z
					.array(
						z.object({
							code: z.string(),
							path: z.array(z.union([z.string(), z.number()])),
							message: z.string()
						})
					)
					.optional()
			})
			.optional()
	})
	.transform((payload) => {
		const code = payload.code ?? payload.error_code ?? payload.error?.code ?? 'request_failed';
		const message = payload.message ?? payload.error?.message ?? 'Request failed';
		return {
			code,
			error_code: code,
			message,
			...(payload.error?.issues ? { issues: payload.error.issues } : {})
		};
	});

export const endpointRegistry = {
	'license.activate': {
		path: 'license/activate',
		request: licenseActivationRequestSchema.describe('license.activate request'),
		response: licenseInfoSchema.describe('license.activate response'),
		error: endpointErrorSchema.describe('license.activate error')
	},
	'license.read': {
		path: 'license',
		response: licenseInfoSchema.describe('license.read response'),
		error: endpointErrorSchema.describe('license.read error')
	},
	'license.deactivate': {
		path: 'license/deactivate',
		request: emptyRequestSchema.describe('license.deactivate request'),
		response: z
			.union([emptyResponseSchema, z.object({ success: z.boolean() })])
			.describe('license.deactivate response'),
		error: endpointErrorSchema.describe('license.deactivate error')
	},
	'license.bootstrap': {
		path: 'license/bootstrap',
		request: emptyRequestSchema.describe('license.bootstrap request'),
		response: licenseInfoSchema.describe('license.bootstrap response'),
		error: endpointErrorSchema.describe('license.bootstrap error')
	},
	'billing.state': {
		path: 'license/billing-state',
		response: billingStateSchema.describe('billing.state response'),
		error: endpointErrorSchema.describe('billing.state error')
	},
	'billing.managedCheckout.complete': {
		path: 'license/managed-checkout/complete',
		request: managedCheckoutCompleteRequestSchema.describe(
			'billing.managedCheckout.complete request'
		),
		response: managedCheckoutCompleteSchema.describe('billing.managedCheckout.complete response'),
		error: endpointErrorSchema.describe('billing.managedCheckout.complete error')
	},
	'telemetry.read': {
		path: 'telemetry',
		response: telemetrySettingsSchema.describe('telemetry.read response'),
		error: endpointErrorSchema.describe('telemetry.read error')
	},
	'telemetry.update': {
		path: 'telemetry',
		request: telemetryUpdateRequestSchema.describe('telemetry.update request'),
		response: telemetrySettingsSchema.describe('telemetry.update response'),
		error: endpointErrorSchema.describe('telemetry.update error')
	},
	'asyncSettings.read': {
		path: 'async-settings',
		response: asyncSettingsSchema.describe('asyncSettings.read response'),
		error: endpointErrorSchema.describe('asyncSettings.read error')
	},
	'asyncSettings.update': {
		path: 'async-settings',
		request: asyncSettingsUpdateRequestSchema.describe('asyncSettings.update request'),
		response: asyncSettingsSchema.describe('asyncSettings.update response'),
		error: endpointErrorSchema.describe('asyncSettings.update error')
	},
	'settings.read': {
		path: 'settings',
		response: pluginSettingsSchema.describe('settings.read response'),
		error: endpointErrorSchema.describe('settings.read error')
	},
	'settings.update': {
		path: 'settings',
		request: pluginSettingsUpdateRequestSchema.describe('settings.update request'),
		response: pluginSettingsUpdateResponseSchema.describe('settings.update response'),
		error: endpointErrorSchema.describe('settings.update error')
	},
	'asyncHealth.read': {
		path: 'async-health',
		response: asyncHealthSchema.describe('asyncHealth.read response'),
		error: endpointErrorSchema.describe('asyncHealth.read error')
	},
	'asyncHealth.purge': {
		path: 'async-health',
		request: asyncPurgeRequestSchema.describe('asyncHealth.purge request'),
		response: asyncPurgeResponseSchema.describe('asyncHealth.purge response'),
		error: endpointErrorSchema.describe('asyncHealth.purge error')
	},
	'providers.credentials.delete': {
		path: 'local/providers/credentials/{id}',
		request: emptyRequestSchema.describe('providers.credentials.delete request'),
		response: providerCredentialDeleteSchema.describe('providers.credentials.delete response'),
		error: endpointErrorSchema.describe('providers.credentials.delete error')
	},
	'provider.openrouter.models': {
		path: 'local/providers/openrouter/models',
		response: openRouterModelsBoundarySchema.describe('provider.openrouter.models response'),
		error: endpointErrorSchema.describe('provider.openrouter.models error')
	},
	'provider.openrouter.modelsRefresh': {
		path: 'local/providers/openrouter/models/refresh',
		request: openRouterModelsRefreshRequestSchema.describe(
			'provider.openrouter.modelsRefresh request'
		),
		response: openRouterModelsBoundarySchema.describe('provider.openrouter.modelsRefresh response'),
		error: endpointErrorSchema.describe('provider.openrouter.modelsRefresh error')
	},
	'local.actionTemplates.list': {
		path: 'local/action-templates',
		response: z.array(localActionTemplateSchema).describe('local.actionTemplates.list response'),
		error: endpointErrorSchema.describe('local.actionTemplates.list error')
	},
	'local.customActions.list': {
		path: 'local/custom-actions',
		response: z.array(localCustomActionRecordSchema).describe('local.customActions.list response'),
		error: endpointErrorSchema.describe('local.customActions.list error')
	},
	'local.customActions.create': {
		path: 'local/custom-actions',
		request: localCustomActionCreateRequestSchema.describe('local.customActions.create request'),
		response: localCustomActionRecordSchema.describe('local.customActions.create response'),
		error: endpointErrorSchema.describe('local.customActions.create error')
	},
	'local.formMappings.list': {
		path: 'local/form-mappings',
		response: z.array(localFormMappingRecordSchema).describe('local.formMappings.list response'),
		error: endpointErrorSchema.describe('local.formMappings.list error')
	},
	'local.formMappings.create': {
		path: 'local/form-mappings',
		request: localFormMappingCreateRequestSchema.describe('local.formMappings.create request'),
		response: localFormMappingRecordSchema.describe('local.formMappings.create response'),
		error: endpointErrorSchema.describe('local.formMappings.create error')
	},
	'local.executionEvents.list': {
		path: 'local/execution-events',
		response: z.array(localExecutionEventSchema).describe('local.executionEvents.list response'),
		error: endpointErrorSchema.describe('local.executionEvents.list error')
	},
	'lead.profile.read': {
		path: 'lead-value/forms/{source}/{formId}/profile',
		response: leadProfileResponseSchema.describe('lead.profile.read response'),
		error: endpointErrorSchema.describe('lead.profile.read error')
	},
	'lead.profile.save': {
		path: 'lead-value/forms/{source}/{formId}/profile',
		request: leadProfileSaveRequestSchema.describe('lead.profile.save request'),
		response: leadProfileResponseSchema.describe('lead.profile.save response'),
		error: endpointErrorSchema.describe('lead.profile.save error')
	},
	'lead.profile.generate': {
		path: 'lead-value/profiles/{profileId}/generate',
		request: leadProfileGenerateRequestSchema.describe('lead.profile.generate request'),
		response: leadProfileResponseSchema.describe('lead.profile.generate response'),
		error: endpointErrorSchema.describe('lead.profile.generate error')
	},
	'lead.profile.selfImprove': {
		path: 'lead-value/profiles/{profileId}/self-improve',
		request: leadProfileSelfImproveRequestSchema.describe('lead.profile.selfImprove request'),
		response: leadProfileResponseSchema.describe('lead.profile.selfImprove response'),
		error: endpointErrorSchema.describe('lead.profile.selfImprove error')
	},
	'lead.profile.assistant': {
		path: 'lead-value/profiles/{profileId}/assistant',
		request: emptyRequestSchema.describe('lead.profile.assistant request'),
		response: leadProfileResponseSchema.describe('lead.profile.assistant response'),
		error: endpointErrorSchema.describe('lead.profile.assistant error')
	},
	'lead.entries.search': {
		path: 'lead-value/forms/{source}/{formId}/entries/search',
		response: leadEntrySearchResponseSchema.describe('lead.entries.search response'),
		error: endpointErrorSchema.describe('lead.entries.search error')
	},
	'spamGuidance.entries.search': {
		path: 'spam-guidance/forms/{source}/{formId}/entries/search',
		response: spamGuidanceSearchSchema.describe('spamGuidance.entries.search response'),
		error: endpointErrorSchema.describe('spamGuidance.entries.search error')
	},
	'spamGuidance.examples.append': {
		path: 'spam-guidance/forms/{source}/{formId}/examples',
		request: spamGuidanceAppendRequestSchema.describe('spamGuidance.examples.append request'),
		response: spamGuidanceAppendBoundarySchema.describe('spamGuidance.examples.append response'),
		error: endpointErrorSchema.describe('spamGuidance.examples.append error')
	},
	'lead.entry.correct': {
		path: 'lead-value/forms/{source}/{formId}/entries/{entryId}/correction',
		request: leadCorrectionRequestSchema.describe('lead.entry.correct request'),
		response: leadCorrectionResponseSchema.describe('lead.entry.correct response'),
		error: endpointErrorSchema.describe('lead.entry.correct error')
	},
	'lead.entry.suggestReply': {
		path: 'lead-value/forms/{source}/{formId}/entries/{entryId}/suggested-reply',
		request: emptyRequestSchema.describe('lead.entry.suggestReply request'),
		response: suggestedReplyResponseSchema.describe('lead.entry.suggestReply response'),
		error: endpointErrorSchema.describe('lead.entry.suggestReply error')
	},
	'lead.dashboard.form': {
		path: 'lead-value/forms/{source}/{formId}/dashboard',
		response: leadDashboardSchema.describe('lead.dashboard.form response'),
		error: endpointErrorSchema.describe('lead.dashboard.form error')
	},
	'lead.dashboard.all': {
		path: 'lead-value/dashboard',
		response: leadDashboardSchema.describe('lead.dashboard.all response'),
		error: endpointErrorSchema.describe('lead.dashboard.all error')
	},
	'lead.profile.import': {
		path: 'lead-value/forms/{source}/{formId}/profile/import',
		request: leadImportRequestSchema.describe('lead.profile.import request'),
		response: leadProfileResponseSchema.describe('lead.profile.import response'),
		error: endpointErrorSchema.describe('lead.profile.import error')
	},
	'lead.historicalRuns.list': {
		path: 'lead-value/forms/{source}/{formId}/historical-runs',
		response: z
			.object({ runs: z.array(leadHistoricalRunSchema) })
			.describe('lead.historicalRuns.list response'),
		error: endpointErrorSchema.describe('lead.historicalRuns.list error')
	},
	'lead.historicalRuns.create': {
		path: 'lead-value/forms/{source}/{formId}/historical-runs',
		request: leadHistoricalRunCreateRequestSchema.describe('lead.historicalRuns.create request'),
		response: leadHistoricalRunResponseSchema.describe('lead.historicalRuns.create response'),
		error: endpointErrorSchema.describe('lead.historicalRuns.create error')
	},
	'lead.historicalRuns.start': {
		path: 'lead-value/historical-runs/{runId}/start',
		request: leadHistoricalRunStartRequestSchema.describe('lead.historicalRuns.start request'),
		response: leadHistoricalRunResponseSchema.describe('lead.historicalRuns.start response'),
		error: endpointErrorSchema.describe('lead.historicalRuns.start error')
	},
	'local.supportBundle.read': {
		path: 'local/support-bundle',
		response: localSupportBundleSchema.describe('local.supportBundle.read response'),
		error: endpointErrorSchema.describe('local.supportBundle.read error')
	},
	'dashboard.summary': {
		path: 'admin/dashboard-summary',
		response: dashboardSummaryBoundarySchema.describe('dashboard.summary response'),
		error: endpointErrorSchema.describe('dashboard.summary error')
	},
	'migration.readiness': {
		path: 'local/migration/readiness',
		response: migrationReadinessSchema.describe('migration.readiness response'),
		error: endpointErrorSchema.describe('migration.readiness error')
	},
	'migration.dryRun': {
		path: 'local/migration/dry-run',
		request: emptyRequestSchema.describe('migration.dryRun request'),
		response: migrationDryRunSchema.describe('migration.dryRun response'),
		error: endpointErrorSchema.describe('migration.dryRun error')
	},
	'migration.approvedReset': {
		path: 'local/migration/approved-reset',
		request: migrationApprovedResetRequestSchema.describe('migration.approvedReset request'),
		response: migrationApprovedResetSchema.describe('migration.approvedReset response'),
		error: endpointErrorSchema.describe('migration.approvedReset error')
	},
	'actions.definitions': {
		path: 'actions/definitions',
		response: z.array(actionDefinitionBoundarySchema).describe('actions.definitions response'),
		error: endpointErrorSchema.describe('actions.definitions error')
	},
	'forms.list': {
		path: '{source}/forms',
		response: z.array(formSummaryBoundarySchema).describe('forms.list response'),
		error: endpointErrorSchema.describe('forms.list error')
	},
	'forms.overview': {
		path: '{source}/forms-overview',
		response: formsOverviewBoundarySchema.describe('forms.overview response'),
		error: endpointErrorSchema.describe('forms.overview error')
	},
	'forms.actions.bootstrap': {
		path: '{source}/forms/{formId}/actions/bootstrap',
		response: formActionsBootstrapFullBoundarySchema.describe('forms.actions.bootstrap response'),
		error: endpointErrorSchema.describe('forms.actions.bootstrap error')
	},
	'forms.ledger.settings.read': {
		path: '{source}/forms/{formId}/submission-ledger-settings',
		response: submissionLedgerSettingsBoundarySchema.describe(
			'forms.ledger.settings.read response'
		),
		error: endpointErrorSchema.describe('forms.ledger.settings.read error')
	},
	'forms.ledger.settings.update': {
		path: '{source}/forms/{formId}/submission-ledger-settings',
		request: z
			.strictObject({ enabled: z.boolean() })
			.describe('forms.ledger.settings.update request'),
		response: submissionLedgerSettingsBoundarySchema.describe(
			'forms.ledger.settings.update response'
		),
		error: endpointErrorSchema.describe('forms.ledger.settings.update error')
	},
	'forms.ledger.records.list': {
		path: '{source}/forms/{formId}/submissions',
		response: submissionLedgerRecordsBoundarySchema.describe('forms.ledger.records.list response'),
		error: endpointErrorSchema.describe('forms.ledger.records.list error')
	},
	'forms.ledger.records.read': {
		path: '{source}/forms/{formId}/submissions/{submissionId}',
		response: submissionLedgerRecordBoundarySchema.describe('forms.ledger.records.read response'),
		error: endpointErrorSchema.describe('forms.ledger.records.read error')
	},
	'forms.actions.list': {
		path: '{source}/forms/{formId}/actions',
		response: z.array(formActionLinkageBoundarySchema).describe('forms.actions.list response'),
		error: endpointErrorSchema.describe('forms.actions.list error')
	},
	'forms.workflowPlan.read': {
		path: '{source}/forms/{formId}/actions/workflow-plan',
		response: workflowPlanBoundarySchema.describe('forms.workflowPlan.read response'),
		error: endpointErrorSchema.describe('forms.workflowPlan.read error')
	},
	'forms.requestTrace.run': {
		path: '{source}/forms/{formId}/actions/request-trace',
		request: requestTraceRequestBoundarySchema.describe('forms.requestTrace.run request'),
		response: requestTraceBoundarySchema.describe('forms.requestTrace.run response'),
		error: endpointErrorSchema.describe('forms.requestTrace.run error')
	},
	'forms.disabled.read': {
		path: '{source}/forms/{formId}/disabled',
		response: formDisableBoundarySchema.describe('forms.disabled.read response'),
		error: endpointErrorSchema.describe('forms.disabled.read error')
	},
	'forms.disabled.update': {
		path: '{source}/forms/{formId}/disabled',
		request: z.strictObject({ sf_disabled: z.boolean() }).describe('forms.disabled.update request'),
		response: formDisableBoundarySchema.describe('forms.disabled.update response'),
		error: endpointErrorSchema.describe('forms.disabled.update error')
	},
	'forms.fields.list': {
		path: '{source}/forms/{formId}/fields',
		response: z.array(formFieldBoundarySchema).describe('forms.fields.list response'),
		error: endpointErrorSchema.describe('forms.fields.list error')
	},
	'forms.executionStatus.read': {
		path: '{source}/forms/{formId}/execution-status',
		response: formExecutionStatusBoundarySchema.describe('forms.executionStatus.read response'),
		error: endpointErrorSchema.describe('forms.executionStatus.read error')
	},
	'meta.capabilities': {
		path: 'meta/capabilities',
		response: capabilitiesBoundarySchema.describe('meta.capabilities response'),
		error: endpointErrorSchema.describe('meta.capabilities error')
	},
	'forms.actions.create': {
		path: '{source}/forms/{formId}/actions',
		request: formActionMutationRequestSchema.describe('forms.actions.create request'),
		response: formActionLinkageBoundarySchema.describe('forms.actions.create response'),
		error: endpointErrorSchema.describe('forms.actions.create error')
	},
	'forms.actions.duplicate': {
		path: '{source}/forms/{formId}/actions/{mappingId}/duplicate',
		request: duplicateFormActionRequestBoundarySchema.describe('forms.actions.duplicate request'),
		response: duplicateFormActionBoundarySchema.describe('forms.actions.duplicate response'),
		error: endpointErrorSchema.describe('forms.actions.duplicate error')
	},
	'forms.actions.update': {
		path: '{source}/forms/{formId}/actions/{mappingId}',
		request: formActionMutationRequestSchema.describe('forms.actions.update request'),
		response: formActionLinkageBoundarySchema.describe('forms.actions.update response'),
		error: endpointErrorSchema.describe('forms.actions.update error')
	},
	'forms.actions.delete': {
		path: '{source}/forms/{formId}/actions/{mappingId}',
		request: emptyRequestSchema.describe('forms.actions.delete request'),
		response: z
			.union([emptyResponseSchema, z.object({ deleted: z.boolean().optional() })])
			.describe('forms.actions.delete response'),
		error: endpointErrorSchema.describe('forms.actions.delete error')
	},
	'forms.actionConfigs.list': {
		path: '{source}/forms/{formId}/action-config',
		response: allFormActionConfigsBoundarySchema.describe('forms.actionConfigs.list response'),
		error: endpointErrorSchema.describe('forms.actionConfigs.list error')
	},
	'forms.actionConfigs.read': {
		path: '{source}/forms/{formId}/action-config/{actionId}',
		response: formActionConfigResponseBoundarySchema.describe('forms.actionConfigs.read response'),
		error: endpointErrorSchema.describe('forms.actionConfigs.read error')
	},
	'forms.actionConfigs.update': {
		path: '{source}/forms/{formId}/action-config/{actionId}',
		request: formActionConfigBoundarySchema.strict().describe('forms.actionConfigs.update request'),
		response: formActionConfigResponseBoundarySchema.describe(
			'forms.actionConfigs.update response'
		),
		error: endpointErrorSchema.describe('forms.actionConfigs.update error')
	},
	'forms.actionConfigs.delete': {
		path: '{source}/forms/{formId}/action-config/{actionId}',
		request: emptyRequestSchema.describe('forms.actionConfigs.delete request'),
		response: z
			.union([emptyResponseSchema, z.object({ deleted: z.boolean().optional() })])
			.describe('forms.actionConfigs.delete response'),
		error: endpointErrorSchema.describe('forms.actionConfigs.delete error')
	},
	'actions.defaults.read': {
		path: 'actions/{actionId}/defaults',
		response: formActionConfigResponseBoundarySchema.describe('actions.defaults.read response'),
		error: endpointErrorSchema.describe('actions.defaults.read error')
	},
	'actions.defaults.batch': {
		path: 'actions/defaults',
		response: actionDefaultsBatchBoundarySchema.describe('actions.defaults.batch response'),
		error: endpointErrorSchema.describe('actions.defaults.batch error')
	},
	'actions.defaults.update': {
		path: 'actions/{actionId}/defaults',
		request: formActionConfigBoundarySchema.strict().describe('actions.defaults.update request'),
		response: formActionConfigResponseBoundarySchema.describe('actions.defaults.update response'),
		error: endpointErrorSchema.describe('actions.defaults.update error')
	},
	'customActions.list': {
		path: 'custom-actions',
		response: customActionListBoundarySchema.describe('customActions.list response'),
		error: endpointErrorSchema.describe('customActions.list error')
	},
	'customActions.create': {
		path: 'custom-actions',
		request: customActionCreateRequestBoundarySchema.describe('customActions.create request'),
		response: customActionMutationBoundarySchema.describe('customActions.create response'),
		error: endpointErrorSchema.describe('customActions.create error')
	},
	'customActions.update': {
		path: 'custom-actions/{id}',
		request: customActionUpdateRequestBoundarySchema.describe('customActions.update request'),
		response: customActionMutationBoundarySchema.describe('customActions.update response'),
		error: endpointErrorSchema.describe('customActions.update error')
	},
	'customActions.archive': {
		path: 'custom-actions/{id}',
		request: emptyRequestSchema.describe('customActions.archive request'),
		response: customActionMutationBoundarySchema.describe('customActions.archive response'),
		error: endpointErrorSchema.describe('customActions.archive error')
	},
	'customActions.reactivate': {
		path: 'custom-actions/{id}/reactivate',
		request: emptyRequestSchema.describe('customActions.reactivate request'),
		response: customActionMutationBoundarySchema.describe('customActions.reactivate response'),
		error: endpointErrorSchema.describe('customActions.reactivate error')
	},
	'mappings.list': {
		path: 'mappings',
		response: z.array(formMappingBoundarySchema).describe('mappings.list response'),
		error: endpointErrorSchema.describe('mappings.list error')
	},
	'mappings.templates': {
		path: 'mappings/templates',
		response: z.array(formMappingBoundarySchema).describe('mappings.templates response'),
		error: endpointErrorSchema.describe('mappings.templates error')
	},
	'mappings.read': {
		path: 'mappings/{id}',
		response: formMappingBoundarySchema.describe('mappings.read response'),
		error: endpointErrorSchema.describe('mappings.read error')
	},
	'mappings.create': {
		path: 'mappings',
		request: createFormMappingRequestBoundarySchema.describe('mappings.create request'),
		response: formMappingBoundarySchema.describe('mappings.create response'),
		error: endpointErrorSchema.describe('mappings.create error')
	},
	'mappings.update': {
		path: 'mappings/{id}',
		request: updateFormMappingRequestBoundarySchema.describe('mappings.update request'),
		response: formMappingBoundarySchema.describe('mappings.update response'),
		error: endpointErrorSchema.describe('mappings.update error')
	},
	'mappings.delete': {
		path: 'mappings/{id}',
		request: emptyRequestSchema.describe('mappings.delete request'),
		response: z
			.union([emptyResponseSchema, z.object({ deleted: z.boolean().optional() })])
			.describe('mappings.delete response'),
		error: endpointErrorSchema.describe('mappings.delete error')
	},
	'mappings.clone': {
		path: 'mappings/{id}/clone',
		request: cloneFormMappingRequestBoundarySchema.describe('mappings.clone request'),
		response: formMappingBoundarySchema.describe('mappings.clone response'),
		error: endpointErrorSchema.describe('mappings.clone error')
	},
	'forms.entryExecutionStatus.read': {
		path: '{source}/forms/{formId}/actions/entries/{entryId}/status',
		response: entryExecutionStatusBoundarySchema.describe(
			'forms.entryExecutionStatus.read response'
		),
		error: endpointErrorSchema.describe('forms.entryExecutionStatus.read error')
	},
	'actionLog.list': {
		path: 'actions/log',
		response: actionLogResponseSchema.describe('actionLog.list response'),
		error: endpointErrorSchema.describe('actionLog.list error')
	},
	'actionLog.preview': {
		path: 'actions/log/{id}/entry-preview',
		response: actionLogPreviewSchema.describe('actionLog.preview response'),
		error: endpointErrorSchema.describe('actionLog.preview error')
	},
	'billing.checkout.create': {
		path: 'license/billing/checkout-session',
		request: billingCheckoutSessionRequestSchema.describe('billing.checkout.create request'),
		response: billingCheckoutSessionSchema.describe('billing.checkout.create response'),
		error: endpointErrorSchema.describe('billing.checkout.create error')
	},
	'billing.managedCheckout.start': {
		path: 'license/managed-checkout/start',
		request: managedCheckoutStartRequestSchema.describe('billing.managedCheckout.start request'),
		response: managedCheckoutStartSchema.describe('billing.managedCheckout.start response'),
		error: endpointErrorSchema.describe('billing.managedCheckout.start error')
	},
	'billing.portal.create': {
		path: 'license/billing/portal-session',
		request: billingPortalSessionRequestSchema.describe('billing.portal.create request'),
		response: billingPortalSessionSchema.describe('billing.portal.create response'),
		error: endpointErrorSchema.describe('billing.portal.create error')
	},
	'billing.topUp.create': {
		path: 'license/billing/top-up-session',
		request: topUpCheckoutSessionRequestSchema.describe('billing.topUp.create request'),
		response: topUpCheckoutSessionSchema.describe('billing.topUp.create response'),
		error: endpointErrorSchema.describe('billing.topUp.create error')
	},
	'executionStatus.read': {
		path: 'execution-status/{mappingId}/{entryId}',
		response: executionStatusDataSchema.describe('executionStatus.read response'),
		error: endpointErrorSchema.describe('executionStatus.read error')
	},
	'migration.import.dryRun': {
		path: 'local/migration/import/dry-run',
		request: localMigrationImportRequestSchema.describe('migration.import.dryRun request'),
		response: localMigrationImportDryRunResponseSchema.describe('migration.import.dryRun response'),
		error: endpointErrorSchema.describe('migration.import.dryRun error')
	},
	'migration.import.apply': {
		path: 'local/migration/import/apply',
		request: localMigrationImportRequestSchema.describe('migration.import.apply request'),
		response: localMigrationImportApplyResponseSchema.describe('migration.import.apply response'),
		error: endpointErrorSchema.describe('migration.import.apply error')
	},
	'models.catalog': {
		path: 'models',
		response: rawOrSuccessEnvelope(modelCatalogSchema).describe('models.catalog response'),
		error: endpointErrorSchema.describe('models.catalog error')
	},
	'models.resolve': {
		path: 'models/resolve',
		request: modelSelectionRequestSchema.describe('models.resolve request'),
		response: rawOrSuccessEnvelope(resolvedModelSelectionSchema).describe(
			'models.resolve response'
		),
		error: endpointErrorSchema.describe('models.resolve error')
	},
	'models.estimate': {
		path: 'models/estimate',
		request: modelEstimateRequestSchema.describe('models.estimate request'),
		response: rawOrSuccessEnvelope(modelEstimateSchema).describe('models.estimate response'),
		error: endpointErrorSchema.describe('models.estimate error')
	},
	'providers.credentials.list': {
		path: 'local/providers/credentials',
		response: rawOrSuccessEnvelope(localProviderCredentialListSchema).describe(
			'providers.credentials.list response'
		),
		error: endpointErrorSchema.describe('providers.credentials.list error')
	},
	'provider.openrouter.validate': {
		path: 'local/providers/openrouter/validate',
		request: openRouterValidateRequestSchema.describe('provider.openrouter.validate request'),
		response: openRouterCredentialResponseSchema.describe('provider.openrouter.validate response'),
		error: endpointErrorSchema.describe('provider.openrouter.validate error')
	},
	'provider.openrouter.constant': {
		path: 'local/providers/openrouter/constant',
		request: openRouterConstantRequestSchema.describe('provider.openrouter.constant request'),
		response: openRouterCredentialResponseSchema.describe('provider.openrouter.constant response'),
		error: endpointErrorSchema.describe('provider.openrouter.constant error')
	},
	'provider.sentientManaged.setup': {
		path: 'local/providers/sentient-managed/setup',
		request: managedSetupRequestSchema.describe('provider.sentientManaged.setup request'),
		response: managedSetupResponseSchema.describe('provider.sentientManaged.setup response'),
		error: endpointErrorSchema.describe('provider.sentientManaged.setup error')
	},
	'provider.sentientManaged.revoke': {
		path: 'local/providers/sentient-managed/revoke',
		request: managedRevokeRequestSchema.describe('provider.sentientManaged.revoke request'),
		response: managedRevokeResponseSchema.describe('provider.sentientManaged.revoke response'),
		error: endpointErrorSchema.describe('provider.sentientManaged.revoke error')
	},
	'siteContext.read': {
		path: 'site-context',
		response: rawOrSuccessEnvelope(siteContextBoundaryResponseSchema).describe(
			'siteContext.read response'
		),
		error: endpointErrorSchema.describe('siteContext.read error')
	},
	'siteContext.update': {
		path: 'site-context',
		request: siteContextUpdateRequestSchema.describe('siteContext.update request'),
		response: rawOrSuccessEnvelope(siteContextBoundaryResponseSchema).describe(
			'siteContext.update response'
		),
		error: endpointErrorSchema.describe('siteContext.update error')
	},
	'siteContext.generate': {
		path: 'site-context/generate',
		request: siteContextGenerateRequestSchema.describe('siteContext.generate request'),
		response: rawOrSuccessEnvelope(siteContextBoundaryResponseSchema).describe(
			'siteContext.generate response'
		),
		error: endpointErrorSchema.describe('siteContext.generate error')
	}
} as const;

export const endpointSchemas = {
	'billing.portal.create': endpointRegistry['billing.portal.create'].response
} as const;

export type EndpointSchemaName = keyof typeof endpointSchemas;
export type EndpointResponse<TName extends EndpointSchemaName> = z.output<
	(typeof endpointSchemas)[TName]
>;

export type EndpointName = keyof typeof endpointRegistry;
export type RegisteredEndpointRequest<TName extends EndpointName> =
	(typeof endpointRegistry)[TName] extends { request: infer TRequest extends z.ZodType }
		? z.input<TRequest>
		: never;
export type RegisteredEndpointResponse<TName extends EndpointName> = z.output<
	(typeof endpointRegistry)[TName]['response']
>;

export function parseRegisteredEndpointRequest<TName extends EndpointName>(
	name: TName,
	payload: unknown
): RegisteredEndpointRequest<TName> {
	const definition = endpointRegistry[name];
	if (!('request' in definition)) {
		throw new Error(`Endpoint ${name} does not define a request body.`);
	}

	return definition.request.parse(payload) as RegisteredEndpointRequest<TName>;
}
