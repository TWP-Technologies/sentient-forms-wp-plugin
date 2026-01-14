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
	tier: string | null;
	license_id: string | null;
	site_id: string | null;
	site_url: string;
}

export interface ApiErrorPayload {
	error_code?: string;
	error?: {
		code?: string;
		message?: string;
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
export type ActionCategory =
	| 'content_quality'
	| 'data_processing'
	| 'automation'
	| 'custom';

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
 * Schema definition for a single override key
 */
export interface OverrideKeySchema {
	type: 'enum' | 'string' | 'number' | 'boolean';
	options?: string[]; // for enum type
	default?: unknown;
	description?: string;
	min?: number; // for number type
	max?: number; // for number type
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
 * Strongly-typed settings for form-level action configuration
 */
export interface FormActionSettings {
	/** Field selection configuration */
	input_mapping?: InputMapping;
	/** Prompt overrides for this mapping */
	prompt_overrides?: Record<string, unknown>;
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
export interface OutputContract {
	response_type: 'text' | 'boolean' | 'classification' | 'structured';
	json_schema?: Record<string, unknown>;
	confidence_score_required?: boolean;
}

/**
 * Full action definition for custom_definition kind
 */
export interface ActionDefinitionPayload {
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
	execution_defaults?: Record<string, unknown>;
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
	base_credit_cost?: number | null;
}

export type CustomActionUpdatePayload = Partial<Omit<CustomActionCreatePayload, 'template_id' | 'code'>> & {
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
	settings?: Record<string, unknown> | null;
}

export interface FormSourceSummary {
	slug: string;
	label: string;
	isActive: boolean;
}
