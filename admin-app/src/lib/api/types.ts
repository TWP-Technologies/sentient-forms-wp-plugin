export interface LicenseActivationRequest {
	licenseKey: string;
	siteUrl: string;
	localSiteIdentifier: string;
}

export interface LicenseActivationResult {
	success: boolean;
	message: string;
	status: string;
	proxyApiKey?: string;
	tier?: string | TierSummary;
	expiryDate?: string | null;
	licenseId?: string;
	siteId?: string;
}

export interface LicenseActivationResponsePayload {
	success?: boolean;
	message?: string;
	status?: string;
	proxy_api_key?: string;
	tier?: string | TierSummary;
	expiry_date?: string | null;
	license_id?: string;
	site_id?: string;
}

export interface LicenseInfoResponse {
	license_key_masked: string;
	status: string;
	proxy_key_present: boolean;
	expires_at: string | null;
	last_synced: string | null;
	tier: string | TierSummary | null;
	license_id: string | null;
	site_id: string | null;
	site_url: string;
}

export interface BillingCheckoutSessionRequest {
	price_id?: string;
	plan_code?: string;
	success_url: string;
	cancel_url: string;
	quantity?: number;
}

export interface BillingCheckoutSessionResponse {
	session_id: string;
	checkout_url: string;
	customer_id: string;
	subscription_id?: string | null;
}

export interface TopUpCheckoutSessionRequest {
	pack_code: string;
	success_url: string;
	cancel_url: string;
	quantity?: number;
}

export interface TopUpCheckoutSessionResponse {
	session_id: string;
	checkout_url: string;
	customer_id: string;
	top_up_credits: number;
	pack_code: string;
}

export interface ManagedCheckoutStartRequest {
	plan_code: string;
	billing_interval?: 'monthly';
	success_url: string;
	cancel_url: string;
	disclosure_version: string;
	accepted_managed_service_terms: boolean;
}

export interface ManagedCheckoutStartResponse {
	checkout_intent_id: string;
	checkout_session_id: string;
	checkout_url: string;
	plan_code?: string;
	billing_interval?: string;
	status?: string;
	consent_recorded?: boolean;
	consent_id?: number;
	disclosure_version?: string;
}

export interface ManagedCheckoutCompleteRequest {
	checkout_intent_id?: string | null;
	checkout_session_id?: string | null;
	activation_token?: string | null;
}

export interface ManagedCheckoutCompleteResponse {
	activation_ready: boolean;
	status?: string;
	message?: string;
	license_key?: string;
	license_id?: string;
	site_id?: string;
	proxy_api_key?: string;
	tier?: string | TierSummary;
	expires_at?: string | null;
	expiry_date?: string | null;
	credential_id?: number;
	managed_provider_ready?: boolean;
}

export interface BillingPortalSessionResponse {
	session_id: string;
	portal_url: string;
	customer_id: string;
}

export type BillingPortalFlowType = 'home' | 'subscription_update' | 'subscription_cancel';

export interface BillingPortalSessionRequest {
	return_url: string;
	flow_type?: BillingPortalFlowType;
	subscription_id?: string;
}

export interface BillingPolicyState {
	paid_trial_days: number;
	free_plan_monthly_credits: number;
	free_plan_indefinite: boolean;
	private_beta_trial_enabled: boolean;
}

export interface BillingSubscriptionState {
	provider_subscription_id: string;
	status: string;
	quantity: number;
	cancel_at_period_end: boolean;
	current_period_start?: string | null;
	current_period_end?: string | null;
	trial_end?: string | null;
	provider_price_id?: string | null;
}

export interface BillingProviderState {
	provider: string;
	provider_mode?: 'test' | 'live' | 'auto' | string;
	provider_livemode?: boolean;
	customer_id?: string | null;
	subscription?: BillingSubscriptionState | null;
	managed_enabled?: boolean;
}

export interface BillingAccountState {
	license_status?: string | null;
	tier?: TierSummary | null;
}

export interface ManagedUsageSummary {
	site_id?: string | null;
	total_events?: number;
	succeeded_events?: number;
	failed_events?: number;
	total_input_tokens?: number;
	total_output_tokens?: number;
	free_usage_events?: number;
	first_event_at?: string | null;
	last_event_at?: string | null;
	execution_count?: number;
	succeeded_count?: number;
	failed_count?: number;
	token_usage?: {
		input_tokens?: number;
		output_tokens?: number;
		total_tokens?: number;
	};
}

export interface BillingBoundaryState {
	direct_openrouter_billed_by_sentient: boolean;
	managed_proxy_billed_by_sentient: boolean;
}

export interface BillingStateResponse {
	service?: string;
	site_id?: string | null;
	license_id?: string | null;
	status?: string | null;
	plan?: TierSummary | null;
	account?: BillingAccountState | null;
	billing?: BillingProviderState | null;
	managed_usage?: ManagedUsageSummary | null;
	billing_boundary?: BillingBoundaryState | null;
	provider?: string;
	provider_mode?: 'test' | 'live' | 'auto' | string;
	provider_livemode?: boolean;
	license_status?: string | null;
	tier?: TierSummary | null;
	customer_id?: string | null;
	subscription?: BillingSubscriptionState | null;
	credits?: {
		current_balance: number;
		tier_quota: number;
		ledger_delta: number;
		top_up_available?: number;
	};
	allocation?: {
		seat_quantity: number;
		tier_site_limit: number;
		allowed_sites: number;
		active_sites: number;
		over_limit: boolean;
		blocked_new_activations: boolean;
		grace_expires_at?: string | null;
		capacity_policy: string;
	} | null;
	policy?: BillingPolicyState | null;
}

export interface ApiErrorPayload {
	error_code?: string;
	error?: {
		code?: string;
		message?: string;
		meta?: {
			current_balance?: number;
			required_credits?: number;
			deficit_credits?: number;
			balance_state?: 'negative_carry' | 'insufficient_estimate' | string;
			provider_subscription_id?: string;
			stripe_error?: {
				status?: number;
				code?: string;
				decline_code?: string;
				message?: string;
			};
			portal_recovery?: {
				session_id?: string;
				portal_url?: string;
				customer_id?: string;
			};
			portal_recovery_error?: {
				status?: number;
				message?: string;
			};
		};
	};
	message?: string;
	[key: string]: unknown;
}

export interface TierSummary {
	code: string;
	display_name?: string;
	site_limit?: number;
	monthly_credit_quota?: number;
}

export interface CreditBalanceResponse {
	current_balance: number;
	ledger_delta?: number;
	tier?: TierSummary | null;
	stale?: boolean;
}

export interface PluginSettingsResponse {
	enable_logging?: boolean;
	execution_global_disabled?: boolean;
	execution_provider_disabled?: Record<string, boolean>;
	execution_event_retention_days?: number;
	delete_data_on_uninstall?: boolean;
	store_full_ai_outputs?: boolean;
	privacy_setup_profile?:
		| 'balanced'
		| 'privacy_focused'
		| 'maximum_privacy'
		| 'maximum_visibility'
		| string;
	privacy_setup_completed_at?: string | null;
}

export type SiteContextConsentStatus = 'unset' | 'granted' | 'declined';

export interface SiteContextRefreshSettings {
	consent_status: SiteContextConsentStatus;
	consented_at: string | null;
	declined_at: string | null;
	auto_refresh_enabled: boolean;
	auto_refresh_days: number;
	next_refresh_at: string | null;
	last_generated_at: string | null;
	last_error: string | null;
	generation_model_selection?: ModelSelection | null;
}

export interface SiteContext {
	id: string;
	license_id: string;
	summary_text: string;
	source: string;
	auto_include: boolean;
	pii_ack: boolean;
	free_refresh_available: boolean;
	next_free_refresh_at: string | null;
	created_at: string;
	updated_at: string;
	metadata?: Record<string, unknown> | null;
}

export interface SiteContextStatusResponse {
	context: SiteContext | null;
	settings: SiteContextRefreshSettings;
	has_context: boolean;
	is_empty: boolean;
	is_stale: boolean;
	stale_after_days: number;
	status: 'empty' | 'ready' | 'stale' | 'declined';
}

export interface SiteContextUpdateRequest {
	summary_text?: string;
	auto_include?: boolean;
	pii_ack?: boolean;
	consent_status?: SiteContextConsentStatus;
	auto_refresh_enabled?: boolean;
	auto_refresh_days?: number;
	generation_model_selection?: ModelSelection | null;
}

export interface SiteContextGenerateRequest {
	consent_status?: SiteContextConsentStatus;
	auto_refresh_enabled?: boolean;
	auto_refresh_days?: number;
	generation_model_selection?: ModelSelection | null;
}

export type LocalProvider = 'openrouter' | 'sentient_managed' | string;
export type LocalProviderAuthMode =
	| 'manual_key'
	| 'oauth_broker'
	| 'sentient_proxy'
	| 'constant'
	| string;
export type LocalProviderStatus = 'unknown' | 'valid' | 'invalid' | 'limited' | 'disabled' | string;

export interface LocalProviderCredential {
	id: number;
	provider: LocalProvider;
	label: string;
	auth_mode: LocalProviderAuthMode;
	constant_name: string | null;
	status: LocalProviderStatus;
	status_json: Record<string, unknown> | null;
	last_validated_at: string | null;
	created_at: string | null;
	updated_at: string | null;
	secret_configured: boolean;
}

export interface LocalProviderCredentialDeleteResponse {
	deleted: boolean;
	credential: LocalProviderCredential;
}

export interface SentientManagedSetupRequest {
	disclosure_version: string;
	accepted_external_service_terms: boolean;
	label?: string;
}

export interface SentientManagedRevokeRequest {
	disclosure_version: string;
	confirm_managed_service_revocation: boolean;
}

export type SentientManagedConsentState = 'accepted' | 'revoked' | 'missing' | string;

export interface SentientManagedAccountState {
	status: string;
	license_id: string;
	site_id: string;
	local_site_identifier: string;
	proxy_key_present: boolean;
	credential_ready: boolean;
}

export interface SentientManagedSetupResponse {
	provider: 'sentient_managed';
	status: LocalProviderStatus;
	credential_id: number;
	credential: LocalProviderCredential | null;
	consent_recorded: boolean;
	consent_id: number;
	consent_state?: SentientManagedConsentState;
	account: SentientManagedAccountState;
	billing_boundary: BillingBoundaryState;
}

export interface SentientManagedRevokeResponse {
	provider: 'sentient_managed';
	status: LocalProviderStatus;
	credential_id: number | null;
	credential: LocalProviderCredential | null;
	consent_recorded: boolean;
	consent_id: number;
	consent_state: SentientManagedConsentState;
	billing_boundary: BillingBoundaryState;
}

export interface OpenRouterKeyStatus {
	label?: string;
	usage?: number;
	limit?: number | null;
	limit_remaining?: number | null;
	is_free_tier?: boolean;
	[key: string]: unknown;
}

export interface OpenRouterValidateRequest {
	api_key: string;
	disclosure_version: string;
	accepted_external_service_terms: boolean;
	label?: string;
	save?: boolean;
}

export interface OpenRouterConstantRequest {
	constant_name: string;
	disclosure_version: string;
	accepted_external_service_terms: boolean;
	label?: string;
}

export interface OpenRouterValidateResponse {
	provider: 'openrouter';
	status: LocalProviderStatus;
	credential_id: number | null;
	key_status: OpenRouterKeyStatus;
	consent_recorded: boolean;
	consent_id: number;
	auth_mode?: LocalProviderAuthMode;
	constant_name?: string | null;
}

export interface OpenRouterModelCacheItem {
	id: string;
	name: string;
	free: boolean;
	context_length: number | null;
	input_modalities: string[];
	output_modalities: string[];
	supported_parameters: string[];
	pricing: Record<string, string>;
	fetched_at: string | null;
	expires_at: string | null;
	stale: boolean;
}

export interface OpenRouterModelsResponse {
	provider: 'openrouter';
	source: 'local_cache';
	total_cached: number;
	total_returned: number;
	free_count: number;
	stale_count: number;
	models: OpenRouterModelCacheItem[];
	refresh_consent?: OpenRouterModelRefreshConsentState;
	consent_recorded?: boolean;
	consent_id?: number;
	stored?: number;
}

export interface OpenRouterModelRefreshConsentState {
	state: 'accepted' | 'missing';
	disclosure_version: string | null;
	consent_id: number | null;
	accepted_at: string | null;
}

export interface OpenRouterModelsRefreshRequest {
	disclosure_version: string;
	accepted_external_service_terms: boolean;
	output_modalities?: string;
	supported_parameters?: string;
}

export interface LocalActionTemplate {
	id: number;
	source: string | null;
	external_id: string | null;
	code: string | null;
	display_name: string | null;
	description: string | null;
	prompt_template: string | null;
	default_model: string | null;
	structured_output_schema: Record<string, unknown> | null;
	override_schema: Record<string, unknown> | null;
	version: string | null;
	is_active: boolean;
	created_at: string | null;
	updated_at: string | null;
}

export interface LocalCustomActionRecord {
	id: number;
	external_id: string | null;
	template_id: number | null;
	code: string | null;
	display_name: string | null;
	definition_json: Record<string, unknown> | null;
	model_selection_json: Record<string, unknown> | null;
	status: string | null;
	created_at: string | null;
	updated_at: string | null;
}

export interface LocalCustomActionCreatePayload {
	external_id?: string | null;
	template_id?: number | null;
	code: string;
	display_name: string;
	definition_json: Record<string, unknown>;
	model_selection_json?: Record<string, unknown> | null;
	status?: string;
}

export interface LocalFormMappingRecord {
	id: number;
	external_id: string | null;
	form_source: string | null;
	form_id: string | null;
	hook: string | null;
	action_kind: string | null;
	action_id: number | null;
	conditions_json: Record<string, unknown> | null;
	input_bindings_json: Record<string, unknown> | null;
	execution_mode: string | null;
	effect_mapping_json: Record<string, unknown> | null;
	enabled: boolean;
	created_at: string | null;
	updated_at: string | null;
}

export interface LocalFormMappingCreatePayload {
	external_id?: string | null;
	form_source: string;
	form_id: string | number;
	hook: string;
	action_kind: string;
	action_id: number;
	conditions_json?: Record<string, unknown> | null;
	input_bindings_json: Record<string, unknown>;
	execution_mode?: string;
	effect_mapping_json?: Record<string, unknown> | null;
	enabled?: boolean;
}

export interface LocalExecutionEvent {
	id: number;
	execution_request_id: string | null;
	mapping_id: number | null;
	form_source: string | null;
	form_id: string | null;
	entry_id: string | null;
	provider: LocalProvider | null;
	model: string | null;
	status: 'queued' | 'running' | 'succeeded' | 'failed' | 'skipped' | string | null;
	token_usage_json: Record<string, unknown> | null;
	cost_json: Record<string, unknown> | null;
	result_json: Record<string, unknown> | null;
	error_code: string | null;
	error_message: string | null;
	payload_digest: string | null;
	created_at: string | null;
	updated_at: string | null;
	expires_at: string | null;
}

export interface LocalSupportBundle {
	generated_at?: string;
	plugin?: Record<string, unknown>;
	wordpress?: Record<string, unknown>;
	local_tables?: Record<string, number | null>;
	providers?: Array<Record<string, unknown>>;
	external_consents?: Record<string, Record<string, unknown> | null>;
	execution_summary?: {
		recent?: Array<Record<string, unknown>>;
		[key: string]: unknown;
	};
	retention?: Record<string, unknown>;
	[key: string]: unknown;
}

export interface LocalMigrationWarning {
	code: string;
	message: string;
}

export interface LocalMigrationOptionReport {
	exists?: boolean;
	will_delete?: boolean;
	value_shape?: string;
	value_length?: number | null;
	count?: number;
	sample?: string[];
}

export interface LocalMigrationReadinessReport {
	generated_at: string;
	source: string;
	source_version: string | null;
	confirmation_phrase: string;
	ready_for_reset: boolean;
	ready_for_local_execution: boolean;
	local_tables: Record<string, number | null>;
	runtime_tables: Record<string, number | null>;
	legacy_options: {
		exact_options: Record<string, LocalMigrationOptionReport>;
		option_prefixes: Record<string, LocalMigrationOptionReport>;
	};
	settings: Record<string, unknown>;
	reset_plan: {
		tables_cleared: string[];
		tables_preserved_by_default: string[];
		exact_options_deleted: string[];
		option_prefixes_deleted: string[];
		settings_preserved: string[];
	};
	warnings: LocalMigrationWarning[];
}

export interface LocalMigrationDryRunResponse {
	run_id: number;
	status: 'dry_run_complete' | string;
	report: LocalMigrationReadinessReport;
}

export interface LocalMigrationImportFinding {
	code: string;
	message: string;
	severity?: 'error' | 'warning' | string;
	entity?: string;
	field?: string;
	value?: string;
}

export interface LocalMigrationImportReport {
	schema_version: string;
	source: string;
	source_version: string;
	generated_at: string;
	exported_at: string | null;
	ready_to_import: boolean;
	counts: Record<string, number>;
	changes: Record<string, Record<string, number> | number>;
	conflicts: LocalMigrationImportFinding[];
	warnings: LocalMigrationImportFinding[];
	mapping: Record<string, unknown>;
}

export interface LocalMigrationImportRequest {
	bundle: Record<string, unknown>;
}

export interface LocalMigrationImportDryRunResponse {
	run_id: number;
	status: 'dry_run_complete' | string;
	dry_run: true;
	report: LocalMigrationImportReport;
}

export interface LocalMigrationImportApplyResponse {
	run_id: number;
	status: 'completed' | string;
	dry_run: false;
	report: LocalMigrationImportReport;
	applied: Record<string, number>;
}

export interface LocalMigrationApprovedResetRequest {
	confirmation_phrase: string;
}

export interface LocalMigrationApprovedResetResponse {
	run_id: number;
	status: 'completed' | string;
	before: LocalMigrationReadinessReport;
	after: LocalMigrationReadinessReport;
	deleted_tables: Record<string, number | null>;
	deleted_options: {
		exact_options: Record<string, boolean>;
		option_prefixes: Record<string, { count: number; sample: string[] }>;
	};
	preserved: string[];
}

/**
 * Form field information from adapter (e.g., Gravity Forms)
 */
export interface FormFieldInfo {
	/** Field ID (string for GF compatibility) */
	id: string;
	/** User-facing field label */
	label: string;
	/** Field type (text, email, select, etc.) */
	type: string;
	/** Admin label override */
	adminLabel?: string;
	/** Gravity Forms page number for paginated forms */
	page_index?: number;
}

/**
 * Action category for taxonomy grouping
 */
export type ActionCategory = 'content_quality' | 'data_processing' | 'automation' | 'custom';

export interface ActionDefinition {
	id: string;
	templateId?: string | null;
	label?: string;
	description?: string;
	hooks?: Record<string, string> | string[];
	source?: 'cps' | 'bundled' | 'imported';
	baseCreditCost?: number | null;
	modelHint?: string | null;
	overrideSchema?: TemplateOverrideSchema;
	promptTemplate?: string | null;
	structuredOutputSchema?: Record<string, unknown> | null;
	category?: ActionCategory;
}

/**
 * Category for override keys (CA-UI-001 Key Taxonomy)
 */
export type OverrideKeyCategory =
	| 'behavior' // Controls action behavior (strictness, style)
	| 'output' // Output format/presentation
	| 'model' // Model selection and parameters
	| 'context' // Context and input handling
	| 'advanced'; // Advanced/experimental options

/**
 * Schema definition for a single override key
 */
export interface OverrideKeySchema {
	type: 'enum' | 'string' | 'number' | 'boolean';
	options?: string[]; // for enum type
	default?: unknown;
	description?: string;
	min?: number; // for number type
	max?: number; // for number type
	/** Category for key taxonomy grouping (CA-UI-001) */
	category?: OverrideKeyCategory;
}

/**
 * Override schema mapping keys to their schemas
 */
export type TemplateOverrideSchema = Record<string, OverrideKeySchema>;

/**
 * Template summary returned by the templates list endpoint
 */
export interface TemplateSummary {
	id: string;
	code: string;
	display_name: string;
	description: string;
	model_hint: string;
	base_credit_cost: number;
	override_schema: TemplateOverrideSchema;
}

/**
 * Response from the template schema endpoint
 */
export interface TemplateSchemaResponse {
	template_id: string;
	code: string;
	display_name: string;
	override_schema: TemplateOverrideSchema;
}

/**
 * Input mapping configuration for field selection (CA-MAP-001)
 * Controls which form fields are sent to the selected execution provider
 */
export interface InputMapping {
	/** Field selection mode */
	mode: 'all' | 'selected' | 'exclude';
	/** Gravity Forms field IDs to include/exclude based on mode */
	field_ids?: string[];
	/** Include form metadata (title, entry ID, etc.) */
	include_metadata?: boolean;
}

/**
 * Attachment mapping configuration for execution file references.
 */
export interface AttachmentMapping {
	/** Attachment source mode */
	mode: 'none' | 'gf_upload' | 'media_library' | 'mixed';
	/** GF upload/post image field IDs */
	gf_upload_field_ids?: string[];
	/** WordPress media attachment IDs */
	media_ids?: number[];
	/** Maximum files to include per execution */
	max_files?: number;
}

export type ConditionLogic = 'all' | 'any';

export type ConditionOperator =
	| 'eq'
	| 'neq'
	| 'contains'
	| 'not_contains'
	| 'starts_with'
	| 'ends_with'
	| 'in'
	| 'not_in'
	| 'is_empty'
	| 'is_not_empty'
	| 'gt'
	| 'gte'
	| 'lt'
	| 'lte';

export interface ConditionRule {
	type: 'rule';
	field_id: string;
	operator: ConditionOperator;
	value?: string | number | Array<string | number>;
}

export interface ConditionGroup {
	type: 'group';
	logic: ConditionLogic;
	rules: ConditionNode[];
}

export type ConditionNode = ConditionRule | ConditionGroup;

export interface MappingConditionsConfig {
	enabled: boolean;
	root: ConditionGroup;
}

export type SpamResultDisplayMode = 'none' | 'spam_only' | 'all_results' | string;
export type SpamIndicatorsDisplayMode = 'simple' | 'detailed' | string;
export type LinkedActionStatus = 'active' | 'archived' | 'missing' | 'unknown' | string;
export type RepairState = 'ok' | 'needs_repair' | string;

export interface TriggerSourceConfig {
	type: 'hook_root' | 'mapping';
	mapping_id?: string;
}

/**
 * Batch execution settings for after-submission actions (CB-EXEC-003/004)
 */
export interface BatchSettings {
	/** Whether batching is enabled for this mapping */
	enabled: boolean;
	/** Delay in seconds before batch fires (default 60, range 10–3600) */
	delay_seconds: number;
	/** Hard upper bound before fallback execution (default 86400, range 43200–604800) */
	max_wait_seconds: number;
}

export type RealtimeBlockingMode = 'advisory' | 'require_answers';
export type RealtimeRefreshMode = 'auto' | 'checkpoint' | 'manual';
export type RealtimePageCheckpointMode = 'all_pages' | 'include_pages' | 'exclude_pages';
export type RealtimeInitialPanelState = 'open' | 'minimized' | 'hidden_until_interaction';
export type RealtimeHiddenFieldExposureMode =
	| 'omit_hidden'
	| 'label_hidden'
	| 'label_hidden_value'
	| 'label_value';

/**
 * Runtime settings for real-time suggestion and clarification mappings.
 */
export interface RealtimeSettings {
	/** Whether visible field changes trigger automatic refreshes */
	auto_refresh_enabled?: boolean;
	/** Whether selected field completions trigger refreshes */
	field_checkpoints_enabled?: boolean;
	/** Fields that trigger automatic analysis when changed or blurred */
	checkpoint_field_ids?: string[];
	/** Whether selected page transitions trigger refreshes before navigation */
	page_checkpoints_enabled?: boolean;
	/** Page selection behavior for paginated forms */
	page_checkpoint_mode?: RealtimePageCheckpointMode;
	/** Page numbers included or excluded by the page checkpoint mode */
	page_checkpoint_pages?: number[];
	/** Maximum time to wait for a page checkpoint before navigation continues */
	page_checkpoint_timeout_ms?: number;
	/** Field that receives serialized virtual question/answer JSON before submit */
	storage_target_field_id?: string;
	/** Delay after user input before the suggestion request is sent */
	debounce_ms?: number;
	/** Minimum delay between non-manual suggestion runs */
	cooldown_ms?: number;
	/** Whether users can request a manual refresh from the frontend widget */
	manual_refresh_enabled?: boolean;
	/** Whether unanswered required virtual questions can block submit/next actions */
	blocking_mode?: RealtimeBlockingMode;
	/** How automatic refreshes are triggered in the visitor-facing assistant */
	refresh_mode?: RealtimeRefreshMode;
	/** Initial visitor-facing assistant panel visibility */
	initial_panel_state?: RealtimeInitialPanelState;
	/** How hidden and currently non-visible fields are represented in LLM context */
	hidden_field_exposure_mode?: RealtimeHiddenFieldExposureMode;
	/** Whether the assistant runs once in the Gravity Forms async pre-submit filter */
	pre_submit_run_enabled?: boolean;
	/** Maximum time to wait for a pre-submit assistant run before submit continues */
	pre_submit_timeout_ms?: number;
}

/**
 * Strongly-typed settings for form-level action configuration
 */
export interface FormActionSettings {
	/** Field selection configuration */
	input_mapping?: InputMapping;
	/** Attachment selection configuration */
	attachment_mapping?: AttachmentMapping;
	/** Upstream mapping prerequisites that must complete successfully first */
	dependency_ids?: string[];
	/** Per-hook trigger source authority (hook root or mapping parent) */
	trigger_sources?: Record<string, TriggerSourceConfig>;
	/** Skip this mapping when its upstream spam check classified the entry as spam */
	skip_on_upstream_spam?: boolean;
	/** Explicit mapping override for suppressing notifications when spam is confirmed */
	suppress_notifications_on_spam?: boolean;
	/** Explicit mapping override for suppressing Gravity Forms Webhooks when spam is confirmed */
	suppress_webhooks_on_spam?: boolean;
	/** Explicit mapping override for skipping downstream work when spam is confirmed */
	skip_downstream_on_spam?: boolean;
	/** Whether spam notes should be stored for none, spam-only, or all classifications */
	spam_result_display_mode?: SpamResultDisplayMode;
	/** How much spam-indicator detail to include in spam notes */
	spam_indicators_display?: SpamIndicatorsDisplayMode;
	/** Mapping-level legitimate spam-calibration examples */
	spam_positive_examples?: SpamGuidanceExample[];
	/** Mapping-level spam-calibration examples */
	spam_negative_examples?: SpamGuidanceExample[];
	/** Optional action-specific AI instructions for this mapping */
	action_customization?: string;
	/** Conditional run gates for this mapping (CB-FORMS-006) */
	conditions?: MappingConditionsConfig;
	/** Prompt overrides for this mapping */
	prompt_overrides?: Record<string, unknown>;
	/** Non-blocking WordPress side effects to run after successful action execution */
	post_execution_actions?: CustomActionPostExecutionActionPayload[];
	/** Execution mode: validation (sync) or after_submission (async) - CB-EXEC-002 */
	execution_mode?: ExecutionMode;
	/** Real-time suggestion/clarification runtime controls */
	realtime_settings?: RealtimeSettings;
	/** Batch settings for after-submission execution (CB-EXEC-003/004) */
	batch_settings?: BatchSettings;
	/** Resolved status of the linked local-first custom action */
	linked_action_status?: LinkedActionStatus;
	/** Whether the linked local-first custom action needs repair */
	repair_state?: RepairState;
	/** Additional runtime settings */
	[key: string]: unknown;
}

export interface FormActionLinkage {
	local_mapping_id: string;
	central_action_id: string;
	action_type_indicator: 'master' | 'custom' | 'local_first';
	trigger_hooks: string[];
	is_action_enabled_for_form?: boolean;
	execution_priority?: number;
	action_name_label?: string;
	/** Execution mode: validation (sync) or after_submission (async) - CB-EXEC-001/002 */
	execution_mode?: ExecutionMode;
	linked_action_status?: LinkedActionStatus;
	repair_state?: RepairState;
	settings?: FormActionSettings;
}

export interface FormActionMutationPayload {
	central_action_id?: string;
	action_type_indicator?: 'master' | 'custom' | 'local_first';
	trigger_hooks?: string[];
	is_action_enabled_for_form?: boolean;
	execution_priority?: number;
	action_name_label?: string;
	settings?: FormActionSettings;
}

export interface DuplicateParentSelection {
	type: 'hook_root' | 'mapping';
	hook: string;
	mapping_id?: string;
}

export interface DuplicateFormActionRequest {
	parent: DuplicateParentSelection;
}

export interface DuplicateFormActionSkippedChild {
	child_id: string;
	hook: string;
	code: string;
	message: string;
}

export interface DuplicateFormActionInsertion {
	parent: DuplicateParentSelection;
	moved_children: string[];
	skipped_children: DuplicateFormActionSkippedChild[];
	warnings: string[];
}

export interface DuplicateFormActionResponse {
	duplicate: FormActionLinkage;
	insertion: DuplicateFormActionInsertion;
}

export type WorkflowBlockReason =
	| 'disabled'
	| 'missing_dependency'
	| 'cycle'
	| 'upstream_blocked'
	| 'policy_violation';

export interface WorkflowBlockedMapping {
	mapping_id: string;
	reason: WorkflowBlockReason;
	details?: string;
}

export interface WorkflowPlanWave {
	level: number;
	mapping_ids: string[];
}

export interface WorkflowPlanHook {
	hook: string;
	order: string[];
	waves: WorkflowPlanWave[];
	runnable: string[];
	blocked: WorkflowBlockedMapping[];
	cycle_ids: string[];
}

export interface WorkflowPlanNode {
	mapping_id: string;
	label: string;
	central_action_id: string;
	trigger_hooks: string[];
	dependency_ids: string[];
	trigger_sources?: Record<string, TriggerSourceConfig>;
	is_enabled: boolean;
	is_async: boolean;
}

export interface WorkflowPlanEdge {
	from: string;
	to: string;
	kind: 'dependency' | 'hook_root';
	hook?: string;
}

export interface WorkflowPolicyViolation {
	mapping_id: string;
	dependency_id: string;
	code: string;
	message: string;
}

export interface WorkflowPlanResponse {
	authority: 'cps' | 'local';
	authority_reason?: string | null;
	cps_unreachable: boolean;
	policy_version: string;
	hook_scope: 'all' | string;
	available_hooks: string[];
	nodes: WorkflowPlanNode[];
	edges: WorkflowPlanEdge[];
	hooks: WorkflowPlanHook[];
	policy_violations: WorkflowPolicyViolation[];
}

export type TraceBlockReason =
	| 'disabled'
	| 'missing_dependency'
	| 'cycle'
	| 'upstream_blocked'
	| 'policy_violation'
	| 'invalid_trigger'
	| 'condition_false';

export interface ConditionTraceGroupNode {
	type: 'group';
	logic: 'all' | 'any';
	result: boolean;
	reason_code?: string;
	children: ConditionTraceNode[];
}

export interface ConditionTraceRuleNode {
	type: 'rule';
	field_id?: string;
	operator?: string;
	actual?: string | number | boolean | null;
	expected?: unknown;
	result: boolean;
	reason_code?: string;
}

export interface ConditionTraceInvalidNode {
	type: 'invalid';
	result: boolean;
	reason_code?: string;
}

export type ConditionTraceNode =
	| ConditionTraceGroupNode
	| ConditionTraceRuleNode
	| ConditionTraceInvalidNode;

export interface ConditionTraceResult {
	should_execute: boolean | null;
	enabled: boolean;
	evaluated: boolean;
	matched: boolean | null;
	reason_code: string;
	summary: string;
	tree?: ConditionTraceNode | null;
}

export interface RequestTraceStep {
	mapping_id: string;
	label: string;
	dependency_ids: string[];
	trigger_source?: { type: 'hook_root' | 'mapping' | 'unbound'; mapping_id?: string };
	execution_mode: ExecutionMode;
	is_async: boolean;
	outcome: 'would_run' | 'would_queue' | 'blocked';
	block_reason?: TraceBlockReason | null;
	block_details?: string | null;
	condition: ConditionTraceResult;
}

export interface RequestTraceHook {
	hook: string;
	order: string[];
	waves: WorkflowPlanWave[];
	runnable: string[];
	queued: string[];
	blocked: Array<{
		mapping_id: string;
		reason: TraceBlockReason;
		details?: string;
	}>;
	cycle_ids: string[];
	steps: RequestTraceStep[];
}

export interface RequestTraceInput {
	source: 'empty' | 'manual' | 'entry_import' | 'entry_import_with_manual_overrides';
	entry_id?: number | null;
	field_scope: 'mapped_and_rule';
	values: Record<string, string>;
	manual_field_ids: string[];
	imported_field_ids: string[];
	overridden_field_ids: string[];
	warnings: string[];
	include_drafts: boolean;
	draft_applied: boolean;
}

export interface RequestTraceRequest {
	hook_scope?: 'all' | string;
	entry_values?: Record<string, string | number | boolean | null>;
	entry_id?: number;
	field_scope?: 'mapped_and_rule';
	include_drafts?: boolean;
	draft_mappings?: FormActionLinkage[];
}

export interface RequestTraceResponse {
	authority: 'wp_rest';
	policy_version: string;
	hook_scope: 'all' | string;
	available_hooks: string[];
	input: RequestTraceInput;
	hooks: RequestTraceHook[];
	policy_violations: WorkflowPolicyViolation[];
}

export interface FormDisableStateResponse {
	sf_disabled: boolean;
	global_disabled?: boolean;
	provider_disabled?: boolean;
	effective_disabled?: boolean;
	message?: string;
}

export interface ModelSelection {
	primary: string;
	backup?: string | null;
	is_preset: boolean;
	provider?: LocalProvider | null;
	credential_id?: number | null;
	reasoning?: string | null;
	tools?: Record<string, unknown> | null;
}

export interface ModelInfo {
	id: string;
	display_name: string;
	provider: string;
	provider_family?: string;
	developer?: string;
	description?: string;
	speed_tier: string;
	cost_tier: string;
	cost_symbol?: string;
	capabilities: {
		reasoning: boolean;
		code: boolean;
		vision: boolean;
		tools: boolean;
		structured?: boolean;
		web_search?: boolean;
		long_context: boolean;
		files?: boolean;
		audio?: boolean;
		video?: boolean;
	};
	context_window: number;
	is_preview: boolean;
	tags: string[];
	supported_parameters?: string[];
	input_modalities?: string[];
	output_modalities?: string[];
	pricing?: Record<string, string>;
	recommended_for: string[];
	recommendation_categories?: string[];
	category_rankings?: Record<string, number>;
	ranking_snapshot?: Record<string, unknown>;
	benchmark_notes?: string[];
	source_urls?: string[];
	created?: number | string | null;
	knowledge_cutoff?: string | null;
}

export interface ModelPreset {
	code: string;
	display_name: string;
	description: string;
	category: string;
	resolved_model_id: string;
	auto_upgrade: boolean;
	rationale?: string;
	score?: number;
	evidence_confidence?: string;
	evaluated_at?: string;
	score_breakdown?: Record<string, number>;
	top_candidates?: Array<{
		model_id: string;
		score: number;
		notes?: string;
	}>;
	source_urls?: string[];
}

export interface ModelResolutionStep {
	level: string;
	selection: string | null;
	applied: boolean;
	reason: string;
}

export interface ResolvedModelSelection {
	model_id: string;
	display_name: string;
	resolution_source: string;
	override_chain: ModelResolutionStep[];
	backup_model_id: string | null;
}

export interface ModelPricingEstimate {
	action_id: string;
	resolved_model_id: string;
	route?: 'openrouter' | 'sentient_managed' | string;
	kind?:
		| 'openrouter_free'
		| 'openrouter_currency'
		| 'openrouter_variable'
		| 'sentient_credits'
		| string;
	label?: string;
	amount_usd?: number | null;
	estimate_range?: {
		low?: number | null;
		high?: number | null;
		currency?: string;
		unit?: 'usd' | 'credits' | string;
	} | null;
	estimated_input_tokens?: number;
	estimated_output_tokens?: number;
	estimated_reasoning_tokens?: number;
	sample_count?: number;
	confidence?: 'baseline' | 'low' | 'medium' | 'high' | string;
	calibration_source?: string;
	provider_pricing?: Record<string, string>;
	base_floor_credits: number;
	normalized_actual_credits: number;
	estimated_debit_credits: number;
	pricing_policy_version: string;
	estimate_source: 'resolved_model' | 'fallback' | 'legacy' | string;
}

export interface ModelCatalogResponse {
	models: ModelInfo[];
	presets: ModelPreset[];
	pricing_policy_version?: string;
}

export interface ModelEstimateResponse {
	resolved_model: ResolvedModelSelection;
	pricing_estimate: ModelPricingEstimate;
}

export interface SpamGuidanceExample {
	text: string;
	rationale: string;
}

/**
 * Form-level action configuration (hierarchical examples storage)
 * This configuration persists at the form level, surviving action mapping deletion.
 */
export interface FormActionConfig {
	/** Examples of legitimate submissions (positive examples) */
	spam_positive_examples?: SpamGuidanceExample[];
	/** Examples of spam submissions (negative examples) */
	spam_negative_examples?: SpamGuidanceExample[];
	/** Default policy for suppressing notifications when blocking spam checks confirm spam */
	suppress_notifications_on_spam?: boolean;
	/** Default policy for suppressing Gravity Forms Webhooks when blocking spam checks confirm spam */
	suppress_webhooks_on_spam?: boolean;
	/** Default policy for skipping downstream work when spam is confirmed */
	skip_downstream_on_spam?: boolean;
	/** Optional action-specific AI instructions for this configuration scope */
	action_customization?: string;
	/** Default policy for when to store spam notes for this action on this form */
	spam_result_display_mode?: SpamResultDisplayMode;
	/** Default policy for how much indicator detail spam notes should include */
	spam_indicators_display?: SpamIndicatorsDisplayMode;
	/** Site context inclusion: 'global' | 'always' | 'never' */
	include_site_context?: 'global' | 'always' | 'never';
	/** Structured model selection default for this action scope */
	model_selection?: ModelSelection;
	/** Legacy string model override retained for transition reads */
	model_override?: string;
	/** Default realtime behavior for realtime-capable actions */
	realtime_settings?: RealtimeSettings;
	/** Last update timestamp */
	updated_at?: string;
}

/**
 * Response from GET /forms/{source}/{id}/action-config/{actionId}
 */
export interface FormActionConfigResponse {
	form_source: string;
	form_id: number;
	action_id: string;
	config: FormActionConfig;
}

/**
 * Response from GET /forms/{source}/{id}/action-config (all configs)
 */
export interface FormAllActionConfigsResponse {
	form_source: string;
	form_id: number;
	configs: Record<string, FormActionConfig>;
}

export type LeadGrade = 'A' | 'B' | 'C' | 'Reject';

export interface LeadProfileCriteria {
	summary_text: string;
	must_have_signals?: string[];
	disqualifiers?: string[];
	updated_at?: string;
}

export interface LeadProfileHandoffRules {
	email_recipients: string[];
	webhooks: Array<{ url: string; method?: string }>;
	grades: LeadGrade[];
	entry_notes?: {
		lead_grade?: boolean;
		suggested_reply?: boolean;
	};
	reply_rules?: {
		skip_reject_grade?: boolean;
	};
}

export interface LeadProfileGenerationSettings {
	model:
		| '~openai/gpt-latest'
		| '~google/gemini-pro-latest'
		| '~anthropic/claude-opus-latest'
		| string;
	reasoning_effort?: 'xhigh' | string;
}

export interface LeadProfileSelfImprovementSettings {
	consent: boolean;
	frequency: 'manual' | 'weekly' | 'monthly' | string;
	review_required?: boolean;
}

export interface LeadProfileReadinessRequirement {
	key: string;
	label: string;
	met: boolean;
	severity: 'blocker' | 'recommendation' | string;
	detail: string;
}

export interface LeadProfileReadiness {
	ready: boolean;
	requirements: LeadProfileReadinessRequirement[];
	blockers: LeadProfileReadinessRequirement[];
	site_context: {
		summary_text: string;
		word_count: number;
		consented: boolean;
		consent_status: string;
		source?: string | null;
		updated_at?: string | null;
	};
	spam_guidance: {
		positive_count: number;
		negative_count: number;
		positive?: Array<Record<string, unknown>>;
		negative?: Array<Record<string, unknown>>;
		sources?: Record<string, unknown>;
	};
	good_word_count: number;
	bad_word_count: number;
}

export interface LeadProfileRecord {
	id: number;
	form_source: string;
	form_id: string;
	status: string;
	profile_version: number;
	consented_at: string | null;
	site_context_snapshot?: Record<string, unknown> | null;
	spam_guidance_snapshot?: Record<string, unknown> | null;
	good_lead_criteria: LeadProfileCriteria;
	bad_lead_criteria: LeadProfileCriteria;
	grading_rubric?: Record<string, unknown> | null;
	example_entries: Array<Record<string, unknown>>;
	generated_profile_prompt?: string | null;
	generation_metadata?: Record<string, unknown> | null;
	assistant?: {
		status?: string;
		generated_at?: string;
		questions?: Array<{ key: string; question: string; why: string }>;
		recommendations?: string[];
	} | null;
	handoff_rules: LeadProfileHandoffRules;
	created_by_user_id?: number | null;
	created_at?: string | null;
	updated_at?: string | null;
}

export interface LeadProfileResponse {
	profile: LeadProfileRecord | null;
	readiness: LeadProfileReadiness;
	dashboard?: LeadValueDashboard;
	generation_job?: {
		id: string;
		status: string;
		action_scheduler_id?: number | null;
	};
}

export interface LeadProfileSavePayload {
	lead_profile_consent?: boolean;
	good_lead_criteria?: LeadProfileCriteria;
	bad_lead_criteria?: LeadProfileCriteria;
	example_entries?: Array<Record<string, unknown>>;
	handoff_rules?: Partial<LeadProfileHandoffRules>;
	generation_settings?: LeadProfileGenerationSettings;
	self_improvement?: LeadProfileSelfImprovementSettings;
}

export interface LeadProfileGeneratePayload {
	lead_profile_consent?: boolean;
	async?: boolean;
}

export interface LeadValueHistoricalRun {
	id: number;
	form_source: string;
	form_id: string;
	action_code: string;
	lead_profile_id?: number | null;
	selected_entry_ids: string[];
	filters: Record<string, unknown>;
	estimated_entry_count: number;
	estimated_managed_credits?: number | null;
	estimated_direct_provider_cost?: Record<string, unknown> | null;
	dry_run: boolean;
	status: string;
	progress?: { processed?: number; total?: number; errors?: Array<Record<string, unknown>> } | null;
	result_summary?: Record<string, unknown> | null;
	created_by_user_id?: number | null;
	created_at?: string | null;
	updated_at?: string | null;
}

export interface LeadScoringDashboardMetrics {
	scored_leads: number;
	priority_leads: number;
	reply_drafts: number;
	rejected_leads: number;
	grades: Record<LeadGrade | 'ungraded', number>;
	latest_entries?: LeadScoringEntry[];
}

export interface LeadScoringEntry {
	form_source: string;
	form_id: string | number;
	form_title?: string | null;
	provider_label?: string | null;
	entry_id: string;
	entry_snapshot?: {
		date_created?: string | null;
		status?: string | null;
		field_summary?: Array<{ field_id: string; label: string; value: string }>;
	};
	updated_at?: string | null;
	grade?: LeadGrade | '' | null;
	confidence?: number | null;
	priority?: string | null;
	fit_summary?: string | null;
	intent_summary?: string | null;
	justification?: string | null;
	next_best_action?: string | null;
	suggested_reply_draft?: string | null;
	reply_rationale?: string | null;
	do_not_send?: boolean | number | null;
	lead_profile_id?: number | null;
	profile_version?: number | null;
	historical_run_id?: number | null;
	lead_execution_id?: string | null;
	reply_execution_id?: string | null;
	correction?: {
		grade?: LeadGrade | string;
		justification?: string;
		original_grade?: LeadGrade | string;
		original_justification?: string;
		corrected_by_user_id?: number | null;
		corrected_at?: string | null;
	};
}

export interface LeadScoringFormSummary {
	form_source: string;
	form_id: string | number;
	form_title?: string | null;
	provider_label?: string | null;
	scored_leads: number;
	priority_leads: number;
	reply_drafts: number;
	latest_at?: string | null;
	profile_id?: number | null;
	profile_version?: number | null;
	setup_status?: string | null;
}

export interface LeadValueHistoricalRunResponse {
	run: LeadValueHistoricalRun;
	message?: string;
}

export interface LeadValueHistoricalRunCreatePayload {
	action_code?: 'lead_grading_v1' | 'suggested_reply_v1' | string;
	lead_profile_id?: number | null;
	entry_ids?: Array<string | number>;
	filters?: Record<string, unknown>;
	dry_run?: boolean;
}

export interface LeadValueDashboard {
	form_source: string;
	form_id: string;
	event_count: number;
	successful_events: number;
	failed_events: number;
	grades: Record<LeadGrade | 'ungraded', number>;
	suggested_replies: number;
	metrics?: LeadScoringDashboardMetrics;
	entries?: LeadScoringEntry[];
	entry_page?: number;
	entry_per_page?: number;
	entry_total?: number;
	entry_pages?: number;
	forms?: LeadScoringFormSummary[];
	unconfigured_forms?: LeadScoringFormSummary[];
	historical_runs: LeadValueHistoricalRun[];
}

export interface LeadScoringCorrectionPayload {
	grade: LeadGrade;
	justification: string;
}

export interface LeadValueEntrySearchEntry {
	id: string;
	date_created?: string | null;
	status?: string | null;
	field_summary: Array<{ field_id: string; label: string; value: string }>;
}

export interface LeadValueEntrySearchResponse {
	entries: LeadValueEntrySearchEntry[];
	form_source: string;
	form_id: number;
}

export type CustomActionStatus = 'active' | 'archived';

/**
 * Action kind determines how the action is executed
 */
export type ActionKind = 'template_override' | 'custom_definition';

/**
 * Supported execution modes for actions
 */
export type ExecutionMode = 'validation' | 'after_submission' | 'real_time';

/**
 * Output contract defining expected structured output
 */
export interface OutputContract extends Record<string, unknown> {
	response_type?: 'text' | 'boolean' | 'classification' | 'structured';
	json_schema?: Record<string, unknown>;
	confidence_score_required?: boolean;
}

export interface WorkflowNodePayload extends Record<string, unknown> {
	node_id: string;
	kind: 'llm_step' | 'transform_step' | 'decision_step';
	output_key: string;
	prompt_template?: string;
	input_bindings?: Record<string, unknown>;
	timeout_ms?: number;
}

export interface WorkflowEdgePayload {
	from: string;
	to: string;
}

export interface WorkflowDefinitionPayload extends Record<string, unknown> {
	version?: number;
	nodes: WorkflowNodePayload[];
	edges?: WorkflowEdgePayload[];
	max_parallelism?: number;
	retry_policy?: Record<string, unknown>;
}

export type CustomActionPostExecutionActionType =
	| 'entry_note'
	| 'send_email'
	| 'wp_hook'
	| 'webhook';

export interface CustomActionPostExecutionActionPayload extends Record<string, unknown> {
	type: CustomActionPostExecutionActionType;
	enabled?: boolean;
	message?: string;
	template?: string;
	to?: string | string[];
	recipients?: string | string[];
	subject?: string;
	body?: string;
	hook_name?: string;
	url?: string;
	method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
	headers?: Record<string, string>;
}

/**
 * Full action definition for custom_definition kind
 */
export interface ActionDefinitionPayload extends Record<string, unknown> {
	workflow?: WorkflowDefinitionPayload;
	system_prompt?: string;
	prompt_template?: string;
	meta_prompt?: string;
	goal?: string;
	structured_output_schema?: Record<string, unknown>;
	success_criteria?: string[];
	failure_criteria?: string[];
	examples?: Array<{
		type: 'positive' | 'negative' | 'edge_case';
		input: string;
		expected_output: string;
		explanation?: string;
	}>;
	input_requirements?: Record<string, unknown>;
	execution_defaults?: Record<string, unknown> & {
		post_execution_actions?: CustomActionPostExecutionActionPayload[];
	};
}

export interface CustomAction {
	id: string;
	template_id: string | null;
	code: string;
	display_name: string;
	description: string | null;
	prompt_overrides: Record<string, unknown>;
	model_hint: string | null;
	model_selection?: ModelSelection | null;
	base_credit_cost: number | null;
	status: CustomActionStatus;
	archived_at: string | null;
	created_at: string;
	updated_at: string;
	// New definition fields (CA-DEF-001)
	action_kind: ActionKind;
	definition: ActionDefinitionPayload | null;
	definition_version: number;
	output_contract: OutputContract | null;
	supported_execution_modes: ExecutionMode[];
}

export interface CustomActionQuota {
	quota_max: number;
	quota_used: number;
	quota_remaining: number;
}

export interface CustomActionListSuccess {
	success: true;
	data: {
		actions: CustomAction[];
		quota: CustomActionQuota;
	};
}

export interface CustomActionMutationSuccess {
	success: true;
	data: {
		action: CustomAction;
		quota: CustomActionQuota;
	};
}

export interface CustomActionCreatePayload {
	template_id?: string | null;
	code: string;
	display_name: string;
	description?: string | null;
	prompt_overrides?: Record<string, unknown>;
	model_hint?: string | null;
	model_selection?: ModelSelection | null;
	action_kind: ActionKind;
	definition?: ActionDefinitionPayload | null;
	definition_version: number;
	output_contract?: OutputContract | null;
	supported_execution_modes: ExecutionMode[];
}

export type CustomActionUpdatePayload = Partial<
	Omit<CustomActionCreatePayload, 'template_id' | 'code'>
> & {
	status?: CustomActionStatus;
	archived_at?: string | null;
};

export interface CustomActionFilters {
	status?: CustomActionStatus;
	include_archived?: boolean;
	template_id?: string;
}

export interface CapabilitiesResponse {
	supports_custom_actions?: boolean;
	supports_status?: boolean;
	supports_credits?: boolean;
	cps_version?: string;
}

export interface FormExecutionStatus {
	status: 'unknown' | 'success' | 'error';
	message: string | null;
	entry_id: number | null;
	last_error_code: string | null;
	last_result: unknown;
	updated_at: string | null;
}

export interface ExecutionStatus {
	entry_id: number;
	form_id: number;
	last_response: unknown;
	last_error: string | null;
	processed_at: string | null;
	status: 'unknown' | 'success' | 'error';
	metering_summary?: MeteringSummary | null;
}

export interface WorkflowMeteringSummary {
	status: string;
	credits_total: number;
	credits_by_node: Record<string, number>;
	failed_nodes: string[];
}

export interface MeteringSummary {
	correlation_id?: string | null;
	execution_request_id?: string | null;
	credits_debited?: number | null;
	pricing_policy_version?: string | null;
	workflow?: WorkflowMeteringSummary | null;
}

export interface TelemetrySettingsResponse {
	telemetry_opt_in: boolean;
	updated_at: string | null;
	synced_at: string | null;
	remote_updated_at: string | null;
	last_error: string | null;
}

export interface AsyncSettingsResponse {
	max_attempts: number;
	base_delay_seconds: number;
	max_delay_seconds: number;
	updated_at: string | null;
	updated_by: string | null;
}

export interface AsyncHealthResponse {
	queue_depth: number;
	oldest_run_at: number | null;
	recent_failures: Record<string, number>;
	warnings: Array<{ code: string; level: string; message: string }>;
}

export interface FormSummary {
	id: number;
	title: string;
	adapter: string;
	adapter_name?: string;
	provider_is_active?: boolean;
	provider_edit_url?: string | null;
	settings?: Record<string, unknown> | null;
}

export interface FormSourceSummary {
	slug: string;
	label: string;
	isActive: boolean;
}

// ============================================================================
// Phase 7: Cross-Site Mapping Portability (CSM)
// ============================================================================

/**
 * Mapping settings for trigger hooks, input selection, and effect handling
 */
export interface MappingSettings {
	trigger_hooks?: string[];
	dependency_ids?: string[];
	input_mapping?: {
		mode: 'all' | 'selected' | 'exclude';
		field_ids?: string[];
		include_metadata?: boolean;
	};
	attachment_mapping?: AttachmentMapping;
	conditions?: MappingConditionsConfig;
	effect_mapping?: Record<
		string,
		{
			mark_spam?: boolean;
			notify_admin?: boolean;
			reject_submission?: boolean;
		}
	>;
	portable_fields?: Array<{ label: string; type: string }>;
	field_mapping?: Record<string, string>;
}

/**
 * Portable form mapping record.
 */
export interface FormMapping {
	id: string;
	license_id: string;
	site_id: string | null;
	form_source: string;
	form_id: number | null;
	action_template_id: string | null;
	custom_action_id: string | null;
	display_name: string;
	settings: MappingSettings;
	is_template: boolean;
	created_at: string;
	updated_at: string;
}

/**
 * Request to create a new form mapping
 */
export interface CreateFormMappingRequest {
	site_id?: string | null;
	form_source: string;
	form_id?: number | null;
	/** Action template ID. */
	action_template_id?: string | null;
	/** String-based template code (for master templates like "spam_detection_v1") */
	action_template_code?: string | null;
	custom_action_id?: string | null;
	display_name: string;
	settings: MappingSettings;
	is_template?: boolean;
}

/**
 * Request to update an existing form mapping
 */
export interface UpdateFormMappingRequest {
	display_name?: string;
	settings?: MappingSettings;
	is_template?: boolean;
}

/**
 * Request to clone a template to a site/form
 */
export interface CloneTemplateMappingRequest {
	site_id: string;
	form_source: string;
	form_id: number;
	field_mapping?: Record<string, string>;
}
