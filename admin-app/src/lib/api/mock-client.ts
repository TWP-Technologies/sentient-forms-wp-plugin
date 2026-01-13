import type {
	ActionDefinition,
	AsyncHealthResponse,
	AsyncSettingsResponse,
	CapabilitiesResponse,
	CreditBalanceResponse,
	CustomAction,
	CustomActionCreatePayload,
	CustomActionFilters,
	CustomActionQuota,
	CustomActionUpdatePayload,
	ExecutionStatus,
	FormActionLinkage,
	FormActionMutationPayload,
	FormExecutionStatus,
	FormSummary,
	LicenseActivationRequest,
	LicenseActivationResult,
	LicenseInfoResponse,
	PluginSettingsResponse,
	TelemetrySettingsResponse
} from './types';

type AsyncSettingsPayload = {
	maxAttempts?: number;
	baseDelaySeconds?: number;
	maxDelaySeconds?: number;
};

export class MockSentientFormsApiClient {
	private definitions: ActionDefinition[] = [
		{
			id: 'spam-check',
			label: 'Spam check',
			source: 'cps',
			hooks: { gform_validation: 'Gravity validation' },
			baseCreditCost: 2,
			modelHint: 'gemini-1.5-flash'
		},
		{
			id: 'summarize',
			label: 'Summarize entry',
			source: 'local',
			hooks: { gform_after_submission: 'After submission' },
			baseCreditCost: 6,
			modelHint: 'gemini-1.5-pro'
		}
	];

	private customActions: CustomAction[] = [
		{
			id: 'custom-hello',
			template_id: 'tmpl-hello',
			code: 'hello',
			display_name: 'Hello action',
			description: 'Sends a friendly hello.',
			prompt_overrides: {},
			model_hint: null,
			base_credit_cost: 1,
			status: 'active',
			archived_at: null,
			created_at: new Date().toISOString(),
			updated_at: new Date().toISOString(),
			// New definition fields (CA-DEF-001)
			action_kind: 'template_override',
			definition: null,
			definition_version: 1,
			output_contract: null,
			supported_execution_modes: ['after_submission']
		}
	];

	private forms: FormSummary[] = [
		{
			id: 123,
			title: 'Contact us',
			adapter: 'gravity_forms',
			adapter_name: 'Gravity Forms',
			settings: { enabled: true, actions: {} }
		}
	];

	private formActions: FormActionLinkage[] = [];

	private creditBalance: CreditBalanceResponse = {
		current_balance: 10,
		ledger_delta: 0,
		tier: null,
		stale: false
	};

	async activateLicense(_payload: LicenseActivationRequest): Promise<LicenseActivationResult> {
		return {
			success: true,
			message: 'Mock license activated',
			status: 'active',
			proxyApiKey: 'mock-proxy-key'
		};
	}

	async getLicenseInfo(): Promise<LicenseInfoResponse> {
		return {
			license_key_masked: '****-MOCK',
			status: 'active',
			proxy_key_present: true,
			tier: 'mock',
			expires_at: null,
			last_synced: new Date().toISOString(),
			license_id: 'license-mock',
			site_id: 'site-mock',
			site_url: 'https://example.test'
		};
	}

	async getTelemetrySettings(): Promise<TelemetrySettingsResponse> {
		const timestamp = new Date().toISOString();
		return {
			telemetry_opt_in: true,
			updated_at: timestamp,
			synced_at: timestamp,
			remote_updated_at: timestamp,
			last_error: null
		};
	}

	async updateTelemetrySettings(): Promise<TelemetrySettingsResponse> {
		return this.getTelemetrySettings();
	}

	async getPluginSettings(): Promise<PluginSettingsResponse> {
		return { enable_logging: true };
	}

	async updatePluginSettings(settings: PluginSettingsResponse): Promise<PluginSettingsResponse> {
		return { ...settings };
	}

	async getCapabilities(): Promise<CapabilitiesResponse> {
		return {
			supports_custom_actions: true,
			supports_status: true,
			supports_credits: true,
			cps_version: 'mock-1.0.0'
		};
	}

	async getActionDefinitions(): Promise<ActionDefinition[]> {
		return this.definitions;
	}

	async getForms(formSourceSlug: string): Promise<FormSummary[]> {
		if (formSourceSlug === 'gravity_forms') return this.forms;
		return [];
	}

	async getFormActions(_formSourceSlug: string, _formId: number): Promise<FormActionLinkage[]> {
		return this.formActions;
	}

	async getFormExecutionStatus(): Promise<FormExecutionStatus> {
		return {
			status: 'success',
			message: null,
			entry_id: null,
			last_error_code: null,
			last_result: null,
			updated_at: new Date().toISOString()
		};
	}

	async getCreditBalance(): Promise<CreditBalanceResponse> {
		return this.creditBalance;
	}

	async createFormAction(
		_formSourceSlug: string,
		formId: number,
		payload: FormActionMutationPayload
	): Promise<FormActionLinkage> {
		const linkage: FormActionLinkage = {
			local_mapping_id: `map-${Date.now()}`,
			central_action_id: payload.central_action_id ?? 'unknown',
			action_type_indicator: payload.action_type_indicator ?? 'master',
			trigger_hooks: payload.trigger_hooks ?? [],
			is_action_enabled_for_form: payload.is_action_enabled_for_form ?? true,
			execution_priority: payload.execution_priority ?? 10,
			action_name_label: payload.action_name_label ?? payload.central_action_id ?? 'Action'
		};

		this.formActions = [...this.formActions, linkage];
		// pretend balance consumption
		this.creditBalance = {
			...this.creditBalance,
			current_balance: Math.max(0, (this.creditBalance.current_balance ?? 0) - 1),
			ledger_delta: (this.creditBalance.ledger_delta ?? 0) + 1
		};
		return linkage;
	}

	async updateFormAction(
		_formSourceSlug: string,
		_formId: number,
		localMappingId: string,
		patch: FormActionMutationPayload
	): Promise<FormActionLinkage> {
		this.formActions = this.formActions.map((fa) =>
			fa.local_mapping_id === localMappingId ? { ...fa, ...patch } : fa
		);
		const updated = this.formActions.find((fa) => fa.local_mapping_id === localMappingId);
		if (!updated) throw new Error('Not found');
		return updated;
	}

	async deleteFormAction(
		_formSourceSlug: string,
		_formId: number,
		localMappingId: string
	): Promise<void> {
		this.formActions = this.formActions.filter((fa) => fa.local_mapping_id !== localMappingId);
	}

	// Unused stubs to satisfy types
	async getFormExecutionStatusForEntry(
		_formSourceSlug: string,
		formId: number,
		entryId: number
	): Promise<ExecutionStatus> {
		return {
			entry_id: entryId,
			form_id: formId,
			last_response: {},
			last_error: null,
			processed_at: new Date().toISOString(),
			status: 'success'
		};
	}

	async refreshExecutionStatus(
		formSourceSlug: string,
		formId: number,
		entryId: number
	): Promise<ExecutionStatus> {
		return this.getFormExecutionStatusForEntry(formSourceSlug, formId, entryId);
	}

	async updateAsyncSettings(_payload: AsyncSettingsPayload): Promise<AsyncSettingsResponse> {
		return {
			max_attempts: _payload.maxAttempts ?? 3,
			base_delay_seconds: _payload.baseDelaySeconds ?? 2,
			max_delay_seconds: _payload.maxDelaySeconds ?? 30,
			updated_at: new Date().toISOString(),
			updated_by: 'mock-user'
		};
	}

	async getAsyncHealth(): Promise<AsyncHealthResponse> {
		return { queue_depth: 0, oldest_run_at: null, recent_failures: {}, warnings: [] };
	}

	async getAsyncSettings(): Promise<AsyncSettingsResponse> {
		return {
			max_attempts: 3,
			base_delay_seconds: 2,
			max_delay_seconds: 30,
			updated_at: new Date().toISOString(),
			updated_by: 'mock-user'
		};
	}

	async getCustomActions(_filters: CustomActionFilters = {}): Promise<{
		actions: CustomAction[];
		quota: CustomActionQuota;
	}> {
		return {
			actions: this.customActions,
			quota: { quota_max: 5, quota_used: this.customActions.length, quota_remaining: Math.max(0, 5 - this.customActions.length) }
		};
	}

	async createCustomAction(payload: CustomActionCreatePayload): Promise<{
		action: CustomAction;
		quota: CustomActionQuota;
	}> {
		const now = new Date().toISOString();
		const action: CustomAction = {
			id: `custom-${Date.now()}`,
			template_id: payload.template_id ?? '',
			code: payload.code ?? '',
			display_name: payload.display_name ?? '',
			description: payload.description ?? null,
			prompt_overrides: payload.prompt_overrides ?? {},
			model_hint: payload.model_hint ?? null,
			base_credit_cost: payload.base_credit_cost ?? null,
			status: 'active',
			archived_at: null,
			created_at: now,
			updated_at: now,
			// New definition fields with defaults (CA-DEF-001)
			action_kind: 'template_override',
			definition: null,
			definition_version: 1,
			output_contract: null,
			supported_execution_modes: ['after_submission']
		};
		this.customActions = [action, ...this.customActions];
		return {
			action,
			quota: {
				quota_max: 5,
				quota_used: this.customActions.length,
				quota_remaining: Math.max(0, 5 - this.customActions.length)
			}
		};
	}

	async updateCustomAction(id: string, patch: CustomActionUpdatePayload): Promise<{
		action: CustomAction;
		quota: CustomActionQuota;
	}> {
		this.customActions = this.customActions.map((action) =>
			action.id === id ? { ...action, ...patch, updated_at: new Date().toISOString() } : action
		);
		const action = this.customActions.find((a) => a.id === id)!;
		return {
			action,
			quota: {
				quota_max: 5,
				quota_used: this.customActions.length,
				quota_remaining: Math.max(0, 5 - this.customActions.length)
			}
		};
	}

	async archiveCustomAction(id: string): Promise<{
		action: CustomAction;
		quota: CustomActionQuota;
	}> {
		return this.updateCustomAction(id, { status: 'archived', archived_at: new Date().toISOString() });
	}

	async reactivateCustomAction(id: string): Promise<{
		action: CustomAction;
		quota: CustomActionQuota;
	}> {
		return this.updateCustomAction(id, { status: 'active', archived_at: null });
	}
}
