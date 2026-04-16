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
	tier?: string;
	expiryDate?: string | null;
	licenseId?: string;
	siteId?: string;
}

export interface LicenseActivationResponsePayload {
	success?: boolean;
	message?: string;
	status?: string;
	proxy_api_key?: string;
	tier?: string;
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
	trial_period_days?: number;
}

export interface BillingCheckoutSessionResponse {
	session_id: string;
	checkout_url: string;
	customer_id: string;
	subscription_id?: string | null;
}

export interface BillingTopUpSessionRequest {
	pack_code: string;
	success_url: string;
	cancel_url: string;
	quantity?: number;
}

export interface BillingTopUpSessionResponse {
	session_id: string;
	checkout_url: string;
	customer_id: string;
	top_up_credits: number;
	pack_code: string;
}

export interface BillingSubscriptionChangeRequest {
	plan_code: string;
	change_timing?: 'start_next_cycle' | 'start_now';
	quantity?: number;
	recovery_return_url?: string;
}

export interface BillingSubscriptionChangeResponse {
	provider_subscription_id: string;
	provider_price_id: string;
	plan_code: string;
	change_timing: string;
	effective_at?: string | null;
	renewal_grant_applied: boolean;
	carryover_grant_applied: boolean;
	carryover_credits_granted: number;
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

export interface BillingStateResponse {
	provider: string;
	provider_mode?: 'test' | 'live' | 'auto' | string;
	provider_livemode?: boolean;
	license_status?: string | null;
	tier?: TierSummary | null;
	customer_id?: string | null;
	subscription?: {
		provider_subscription_id: string;
		status: string;
		quantity: number;
		cancel_at_period_end: boolean;
		current_period_start?: string | null;
		current_period_end?: string | null;
		trial_end?: string | null;
		provider_price_id?: string | null;
	} | null;
	credits: {
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
	source?: 'cps' | 'local';
	baseCreditCost?: number | null;
	modelHint?: string | null;
	overrideSchema?: TemplateOverrideSchema;
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
 * Controls which form fields are sent to CPS for action execution
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
	/** Explicit mapping override for skipping downstream work when spam is confirmed */
	skip_downstream_on_spam?: boolean;
	/** Conditional run gates for this mapping (CB-FORMS-006) */
	conditions?: MappingConditionsConfig;
	/** Prompt overrides for this mapping */
	prompt_overrides?: Record<string, unknown>;
	/** Non-blocking WordPress side effects to run after successful action execution */
	post_execution_actions?: CustomActionPostExecutionActionPayload[];
	/** Execution mode: validation (sync) or after_submission (async) - CB-EXEC-002 */
	execution_mode?: ExecutionMode;
	/** Batch settings for after-submission execution (CB-EXEC-003/004) */
	batch_settings?: BatchSettings;
	/** Additional runtime settings */
	[key: string]: unknown;
}

export interface FormActionLinkage {
	local_mapping_id: string;
	central_action_id: string;
	action_type_indicator: 'master' | 'custom';
	trigger_hooks: string[];
	is_action_enabled_for_form?: boolean;
	execution_priority?: number;
	action_name_label?: string;
	/** Execution mode: validation (sync) or after_submission (async) - CB-EXEC-001/002 */
	execution_mode?: ExecutionMode;
	settings?: FormActionSettings;
}

export interface FormActionMutationPayload {
	central_action_id?: string;
	action_type_indicator?: 'master' | 'custom';
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
	authority: 'cps' | 'local_fallback';
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
	execution_mode: 'validation' | 'after_submission';
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
}

export interface ModelInfo {
	id: string;
	display_name: string;
	provider: string;
	speed_tier: string;
	cost_tier: string;
	capabilities: {
		reasoning: boolean;
		code: boolean;
		vision: boolean;
		tools: boolean;
		long_context: boolean;
	};
	context_window: number;
	is_preview: boolean;
	tags: string[];
	recommended_for: string[];
}

export interface ModelPreset {
	code: string;
	display_name: string;
	description: string;
	category: string;
	resolved_model_id: string;
	auto_upgrade: boolean;
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

/**
 * Form-level action configuration (hierarchical examples storage)
 * This configuration persists at the form level, surviving action mapping deletion.
 */
export interface FormActionConfig {
	/** Examples of legitimate submissions (positive examples) */
	spam_positive_examples?: string[];
	/** Examples of spam submissions (negative examples) */
	spam_negative_examples?: string[];
	/** Default policy for suppressing notifications when blocking spam checks confirm spam */
	suppress_notifications_on_spam?: boolean;
	/** Default policy for skipping downstream work when spam is confirmed */
	skip_downstream_on_spam?: boolean;
	/** Site context inclusion: 'global' | 'always' | 'never' */
	include_site_context?: 'global' | 'always' | 'never';
	/** Structured model selection default for this action scope */
	model_selection?: ModelSelection;
	/** Legacy string model override retained for transition reads */
	model_override?: string;
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
	meta_prompt?: string;
	goal?: string;
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
	template_id: string;
	code: string;
	display_name: string;
	description: string | null;
	prompt_overrides: Record<string, unknown>;
	model_hint: string | null;
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
	template_id: string;
	code: string;
	display_name: string;
	description?: string | null;
	prompt_overrides?: Record<string, unknown>;
	model_hint?: string | null;
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
 * Form mapping record from CPS (CSM-001)
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
	/** UUID-based template ID (for custom actions stored in CPS DB) */
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
