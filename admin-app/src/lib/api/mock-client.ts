import type {
	ActionDefinition,
	AsyncHealthResponse,
	AsyncSettingsResponse,
	BillingCheckoutSessionRequest,
	BillingCheckoutSessionResponse,
	BillingPortalSessionRequest,
	BillingPortalSessionResponse,
	BillingSubscriptionChangeRequest,
	BillingSubscriptionChangeResponse,
	BillingStateResponse,
	BillingTopUpSessionRequest,
	BillingTopUpSessionResponse,
	CapabilitiesResponse,
	CreditBalanceResponse,
	CustomAction,
	CustomActionCreatePayload,
	CustomActionFilters,
	CustomActionQuota,
	CustomActionUpdatePayload,
	DuplicateFormActionRequest,
	DuplicateFormActionResponse,
	ExecutionStatus,
	FormDisableStateResponse,
	FormActionLinkage,
	FormActionMutationPayload,
	FormExecutionStatus,
	WorkflowPlanResponse,
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
	private formDisabled: Record<string, boolean> = {};
	private pluginSettings: PluginSettingsResponse = {
		enable_logging: true,
		execution_global_disabled: false,
		execution_provider_disabled: {}
	};

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

	async bootstrapLicense(): Promise<LicenseInfoResponse> {
		return this.getLicenseInfo();
	}

	async deactivateLicense(): Promise<void> {
		return;
	}

	async getBillingState(): Promise<BillingStateResponse> {
		return {
			provider: 'stripe',
			license_status: 'active',
			tier: {
				code: this.creditBalance.tier?.code ?? 'starter',
				display_name: this.creditBalance.tier?.display_name ?? 'Starter',
				monthly_credit_quota: this.creditBalance.tier?.monthly_credit_quota ?? 100,
				site_limit: 1
			},
			customer_id: 'cus_mock_123',
			subscription: {
				provider_subscription_id: 'sub_mock_123',
				status: 'active',
				quantity: 1,
				cancel_at_period_end: false,
				current_period_start: new Date(Date.now() - 7 * 24 * 3600 * 1000).toISOString(),
				current_period_end: new Date(Date.now() + 23 * 24 * 3600 * 1000).toISOString(),
				trial_end: null,
				provider_price_id: 'price_mock_starter'
			},
			credits: {
				current_balance: this.creditBalance.current_balance,
				tier_quota: this.creditBalance.tier?.monthly_credit_quota ?? 100,
				ledger_delta: this.creditBalance.ledger_delta ?? 0,
				top_up_available: 0
			},
			allocation: {
				seat_quantity: 1,
				tier_site_limit: 1,
				allowed_sites: 1,
				active_sites: 1,
				over_limit: false,
				blocked_new_activations: false,
				grace_expires_at: null,
				capacity_policy: 'tier_x_quantity_v1'
			},
			policy: {
				paid_trial_days: 14,
				free_plan_monthly_credits: 50,
				free_plan_indefinite: true,
				private_beta_trial_enabled: true
			}
		};
	}

	async createCheckoutSession(
		payload: BillingCheckoutSessionRequest
	): Promise<BillingCheckoutSessionResponse> {
		const planCode = payload.plan_code ?? 'starter';
		return {
			session_id: `cs_mock_${Date.now()}`,
			checkout_url: `https://checkout.stripe.com/c/pay/mock-${planCode}`,
			customer_id: 'cus_mock_123',
			subscription_id: 'sub_mock_123'
		};
	}

	async changeSubscription(
		payload: BillingSubscriptionChangeRequest
	): Promise<BillingSubscriptionChangeResponse> {
		const changeTiming = payload.change_timing ?? 'start_next_cycle';
		return {
			provider_subscription_id: 'sub_mock_123',
			provider_price_id: `price_mock_${payload.plan_code}`,
			plan_code: payload.plan_code,
			change_timing: changeTiming,
			effective_at:
				changeTiming === 'start_next_cycle'
					? new Date(Date.now() + 20 * 24 * 3600 * 1000).toISOString()
					: null,
			renewal_grant_applied: changeTiming === 'start_now',
			carryover_grant_applied: changeTiming === 'start_now',
			carryover_credits_granted: changeTiming === 'start_now' ? 240 : 0
		};
	}

	async createPortalSession(
		payload: BillingPortalSessionRequest
	): Promise<BillingPortalSessionResponse> {
		const target = encodeURIComponent(payload.return_url);
		return {
			session_id: `bps_mock_${Date.now()}`,
			portal_url: `https://billing.stripe.com/p/session/mock?return_url=${target}`,
			customer_id: 'cus_mock_123'
		};
	}

	async createTopUpCheckoutSession(
		payload: BillingTopUpSessionRequest
	): Promise<BillingTopUpSessionResponse> {
		const packCode = payload.pack_code || 'top_up_small';
		const creditsByPack: Record<string, number> = {
			top_up_small: 5000,
			top_up_medium: 10000,
			top_up_large: 25000
		};
		const credits = (creditsByPack[packCode] ?? 5000) * (payload.quantity ?? 1);
		return {
			session_id: `cs_mock_topup_${Date.now()}`,
			checkout_url: `https://checkout.stripe.com/c/pay/mock-${packCode}`,
			customer_id: 'cus_mock_123',
			top_up_credits: credits,
			pack_code: packCode
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
		return { ...this.pluginSettings };
	}

	async updatePluginSettings(settings: PluginSettingsResponse): Promise<PluginSettingsResponse> {
		this.pluginSettings = { ...this.pluginSettings, ...settings };
		return { ...this.pluginSettings };
	}

	async getSettings(): Promise<PluginSettingsResponse> {
		return this.getPluginSettings();
	}

	async updateSettings(settings: Partial<PluginSettingsResponse>): Promise<PluginSettingsResponse> {
		this.pluginSettings = { ...this.pluginSettings, ...settings };
		return { ...this.pluginSettings };
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

	async getWorkflowPlan(
		_formSourceSlug: string,
		_formId: number,
		hookScope: 'all' | string = 'all'
	): Promise<WorkflowPlanResponse> {
		return {
			authority: 'local_fallback',
			authority_reason: 'mock',
			cps_unreachable: true,
			policy_version: '2026-02-mixed-sync-async-v1',
			hook_scope: hookScope,
			available_hooks: Array.from(
				new Set(this.formActions.flatMap((action) => action.trigger_hooks ?? []))
			).sort(),
			nodes: this.formActions.map((action) => ({
				mapping_id: action.local_mapping_id,
				label: action.action_name_label ?? action.central_action_id,
				central_action_id: action.central_action_id,
				trigger_hooks: action.trigger_hooks ?? [],
				dependency_ids: Array.isArray(action.settings?.dependency_ids)
					? action.settings?.dependency_ids.filter(
							(value): value is string => typeof value === 'string'
						)
					: [],
				trigger_sources:
					action.settings?.trigger_sources && typeof action.settings.trigger_sources === 'object'
						? (action.settings.trigger_sources as Record<
								string,
								{ type: 'hook_root' | 'mapping'; mapping_id?: string }
							>)
						: undefined,
				is_enabled: action.is_action_enabled_for_form !== false,
				is_async: action.settings?.execution_mode === 'after_submission'
			})),
			edges: [],
			hooks: [],
			policy_violations: []
		};
	}

	async getFormDisabled(formSourceSlug: string, formId: number): Promise<FormDisableStateResponse> {
		const key = `${formSourceSlug}:${formId}`;
		const sfDisabled = Boolean(this.formDisabled[key]);
		const globalDisabled = Boolean(this.pluginSettings.execution_global_disabled);
		const providerDisabled = Boolean(
			this.pluginSettings.execution_provider_disabled?.[formSourceSlug]
		);

		return {
			sf_disabled: sfDisabled,
			global_disabled: globalDisabled,
			provider_disabled: providerDisabled,
			effective_disabled: sfDisabled || globalDisabled || providerDisabled
		};
	}

	async toggleFormDisabled(
		formSourceSlug: string,
		formId: number,
		disabled: boolean
	): Promise<FormDisableStateResponse> {
		const key = `${formSourceSlug}:${formId}`;
		this.formDisabled[key] = disabled;
		const state = await this.getFormDisabled(formSourceSlug, formId);
		return {
			...state,
			message: disabled
				? 'Sentient Forms disabled for this form.'
				: 'Sentient Forms enabled for this form.'
		};
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
			action_name_label: payload.action_name_label ?? payload.central_action_id ?? 'Action',
			settings: payload.settings
		};

		this.formActions = [...this.formActions, linkage];
		// pretend balance consumption
			this.creditBalance = {
				...this.creditBalance,
				current_balance: (this.creditBalance.current_balance ?? 0) - 1,
				ledger_delta: (this.creditBalance.ledger_delta ?? 0) + 1
			};
		return linkage;
	}

	async duplicateFormAction(
		_formSourceSlug: string,
		_formId: number,
		localMappingId: string,
		payload: DuplicateFormActionRequest
	): Promise<DuplicateFormActionResponse> {
		const source = this.formActions.find((item) => item.local_mapping_id === localMappingId);
		if (!source) {
			throw new Error('Source mapping not found');
		}

		const now = Date.now();
		const duplicateId = `${source.local_mapping_id}_dup_${now}`;
		const duplicate: FormActionLinkage = {
			...structuredClone(source),
			local_mapping_id: duplicateId
		};

		const selectedHook = payload.parent.hook;
		const triggerHooks = Array.from(new Set(duplicate.trigger_hooks ?? []));
		if (!triggerHooks.includes(selectedHook)) {
			throw new Error('Selected parent hook is not configured on the source mapping');
		}

		const ensureHookSources = (
			linkage: FormActionLinkage
		): Record<string, { type: 'hook_root' | 'mapping'; mapping_id?: string }> => {
			const hooks = Array.from(new Set(linkage.trigger_hooks ?? []));
			const explicit =
				linkage.settings?.trigger_sources && typeof linkage.settings.trigger_sources === 'object'
					? (structuredClone(linkage.settings.trigger_sources) as Record<
							string,
							{ type: 'hook_root' | 'mapping'; mapping_id?: string }
						>)
					: {};
			if (Object.keys(explicit).length > 0) {
				for (const hook of hooks) {
					if (!explicit[hook]) explicit[hook] = { type: 'hook_root' };
				}
				return explicit;
			}

			const dependencyIds = Array.isArray(linkage.settings?.dependency_ids)
				? linkage.settings?.dependency_ids.filter((id): id is string => typeof id === 'string')
				: [];
			if (dependencyIds.length === 1) {
				return Object.fromEntries(
					hooks.map((hook) => [hook, { type: 'mapping' as const, mapping_id: dependencyIds[0] }])
				);
			}
			return Object.fromEntries(hooks.map((hook) => [hook, { type: 'hook_root' as const }]));
		};

		const dependencyIdsFromSources = (
			sources: Record<string, { type: 'hook_root' | 'mapping'; mapping_id?: string }>
		): string[] => {
			return Array.from(
				new Set(
					Object.values(sources)
						.filter((source) => source.type === 'mapping' && Boolean(source.mapping_id))
						.map((source) => source.mapping_id as string)
				)
			);
		};

		const duplicateSources = ensureHookSources(duplicate);
		duplicateSources[selectedHook] =
			payload.parent.type === 'mapping'
				? { type: 'mapping', mapping_id: payload.parent.mapping_id }
				: { type: 'hook_root' };
		const duplicateDependencyIds = dependencyIdsFromSources(duplicateSources);
		duplicate.settings = {
			...(duplicate.settings ?? {}),
			trigger_sources: duplicateSources
		};
		if (duplicateDependencyIds.length > 0) {
			duplicate.settings.dependency_ids = duplicateDependencyIds;
		} else {
			delete duplicate.settings.dependency_ids;
		}

		const preChildren = this.formActions.filter((candidate) => {
			if (!candidate.trigger_hooks?.includes(selectedHook)) return false;
			const sources = ensureHookSources(candidate);
			const hookSource = sources[selectedHook] ?? { type: 'hook_root' as const };
			if (payload.parent.type === 'mapping') {
				return (
					hookSource.type === 'mapping' &&
					hookSource.mapping_id === (payload.parent.mapping_id ?? '')
				);
			}
			return hookSource.type === 'hook_root';
		});

		const movedChildren: string[] = [];
		for (const child of preChildren) {
			const index = this.formActions.findIndex(
				(item) => item.local_mapping_id === child.local_mapping_id
			);
			if (index === -1) continue;
			const current = this.formActions[index]!;
			const sources = ensureHookSources(current);
			sources[selectedHook] = { type: 'mapping', mapping_id: duplicateId };
			const dependencyIds = dependencyIdsFromSources(sources);
			this.formActions[index] = {
				...current,
				settings: {
					...(current.settings ?? {}),
					trigger_sources: sources,
					...(dependencyIds.length > 0 ? { dependency_ids: dependencyIds } : {})
				}
			};
			if (dependencyIds.length === 0 && this.formActions[index]?.settings) {
				delete this.formActions[index]!.settings!.dependency_ids;
			}
			movedChildren.push(child.local_mapping_id);
		}

		this.formActions = [...this.formActions, duplicate];

		return {
			duplicate,
			insertion: {
				parent: payload.parent,
				moved_children: movedChildren,
				skipped_children: [],
				warnings: []
			}
		};
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
			quota: {
				quota_max: 5,
				quota_used: this.customActions.length,
				quota_remaining: Math.max(0, 5 - this.customActions.length)
			}
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
			base_credit_cost: null,
			status: 'active',
			archived_at: null,
			created_at: now,
			updated_at: now,
			action_kind: payload.action_kind,
			definition: payload.definition ?? null,
			definition_version: payload.definition_version,
			output_contract: payload.output_contract ?? null,
			supported_execution_modes: payload.supported_execution_modes
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

	async updateCustomAction(
		id: string,
		patch: CustomActionUpdatePayload
	): Promise<{
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
		return this.updateCustomAction(id, {
			status: 'archived',
			archived_at: new Date().toISOString()
		});
	}

	async reactivateCustomAction(id: string): Promise<{
		action: CustomAction;
		quota: CustomActionQuota;
	}> {
		return this.updateCustomAction(id, { status: 'active', archived_at: null });
	}
}
