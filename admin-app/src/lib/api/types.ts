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
