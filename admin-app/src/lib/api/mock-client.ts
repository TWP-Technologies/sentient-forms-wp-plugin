import type {
	ActionDefinition,
	AsyncHealthResponse,
	AsyncSettingsPayload,
	AsyncSettingsResponse,
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
	LicenseActivationResponsePayload,
	LicenseActivationResult,
	LicenseInfoResponse,
	PluginSettingsResponse,
	TelemetrySettingsResponse
} from './types';

type MaybePromise<T> = T | Promise<T>;

/**
 * Very small in-memory mock client to let the SPA run without a backend (dev/demo/e2e).
 * Only implements the methods currently used by the Actions screens.
 */
export class MockSentientFormsApiClient {
	private definitions: ActionDefinition[] = [
		{
			id: 'spam-check',
			label: 'Spam check',
			source: 'cps',
			hooks: ['gform_validation'],
			baseCreditCost: 2,
			modelHint: 'gemini-1.5-flash'
		},
		{
			id: 'summarize',
			label: 'Summarize entry',
			source: 'local',
			hooks: ['gform_after_submission'],
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
			updated_at: new Date().toISOString()
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
		credits_remaining: 10,
		credits_used: 0,
		credits_max: 10
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
			status: 'active',
			licenseKeyMasked: '****-MOCK',
			proxyKeyPresent: true,
			tier: 'mock',
			expiresAt: null,
			lastSynced: new Date().toISOString()
		};
	}

	async getTelemetrySettings(): Promise<TelemetrySettingsResponse> {
		return { enabled: true, lastUpdated: new Date().toISOString() };
	}

	async updateTelemetrySettings(): Promise<TelemetrySettingsResponse> {
		return { enabled: true, lastUpdated: new Date().toISOString() };
	}

	async getPluginSettings(): Promise<PluginSettingsResponse> {
		return { enable_logging: true };
	}

	async updatePluginSettings(settings: PluginSettingsResponse): Promise<PluginSettingsResponse> {
		return settings;
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
		return { status: 'unknown' };
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
			is_action_enabled_for_form: true,
			action_name_label: payload.action_name_label ?? payload.central_action_id ?? 'Action'
		};

		this.formActions = [...this.formActions, linkage];
		// pretend balance consumption
		this.creditBalance = {
			...this.creditBalance,
			credits_remaining: Math.max(0, (this.creditBalance.credits_remaining ?? 0) - 1),
			credits_used: (this.creditBalance.credits_used ?? 0) + 1
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
		_formId: number,
		_entryId: number
	): Promise<ExecutionStatus> {
		return { status: 'unknown' };
	}
	async refreshExecutionStatus(
		formSourceSlug: string,
		formId: number,
		entryId: number
	): Promise<ExecutionStatus> {
		return this.getFormExecutionStatusForEntry(formSourceSlug, formId, entryId);
	}
	async updateAsyncSettings(_payload: AsyncSettingsPayload): Promise<AsyncSettingsResponse> {
		return { maxAttempts: 3, baseDelaySeconds: 2, maxDelaySeconds: 30 };
	}
	async getAsyncHealth(): Promise<AsyncHealthResponse> {
		return { ok: true, pending: 0, stalled: 0 };
	}
	async getAsyncSettings(): Promise<AsyncSettingsResponse> {
		return { maxAttempts: 3, baseDelaySeconds: 2, maxDelaySeconds: 30 };
	}
	async getCustomActions(_filters?: CustomActionFilters): Promise<{
		actions: CustomAction[];
		quota: CustomActionQuota;
	}> {
		return {
			actions: this.customActions,
			quota: { quota_max: 5, quota_used: 1, quota_remaining: 4 }
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
			updated_at: now
		};
		this.customActions = [action, ...this.customActions];
		return { action, quota: { quota_max: 5, quota_used: this.customActions.length, quota_remaining: Math.max(0, 5 - this.customActions.length) } };
	}
	async updateCustomAction(id: string, patch: CustomActionUpdatePayload): Promise<{
		action: CustomAction;
		quota: CustomActionQuota;
	}> {
		this.customActions = this.customActions.map((action) =>
			action.id === id ? { ...action, ...patch, updated_at: new Date().toISOString() } : action
		);
		const action = this.customActions.find((a) => a.id === id)!;
		return { action, quota: { quota_max: 5, quota_used: this.customActions.length, quota_remaining: Math.max(0, 5 - this.customActions.length) } };
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
