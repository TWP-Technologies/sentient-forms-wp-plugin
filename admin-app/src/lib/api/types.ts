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

export interface ActionDefinition {
	id: string;
	label?: string;
	description?: string;
	hooks?: Record<string, string> | string[];
	source?: 'cps' | 'local';
	baseCreditCost?: number | null;
	modelHint?: string | null;
}

export interface FormActionLinkage {
	local_mapping_id: string;
	central_action_id: string;
	action_type_indicator: 'master' | 'custom';
	trigger_hooks: string[];
	is_action_enabled_for_form?: boolean;
	execution_priority?: number;
	action_name_label?: string;
}

export interface FormActionMutationPayload {
	central_action_id?: string;
	action_type_indicator?: 'master' | 'custom';
	trigger_hooks?: string[];
	is_action_enabled_for_form?: boolean;
	execution_priority?: number;
	action_name_label?: string;
}

export type CustomActionStatus = 'active' | 'archived';

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
