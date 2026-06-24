import type {
	ActionDefinition,
	AsyncHealthResponse,
	AsyncSettingsResponse,
	BillingCheckoutSessionRequest,
	BillingCheckoutSessionResponse,
	BillingPortalSessionRequest,
	BillingPortalSessionResponse,
	BillingStateResponse,
	CapabilitiesResponse,
	CreditBalanceResponse,
	CustomAction,
	CustomActionCreatePayload,
	CustomActionFilters,
	CustomActionQuota,
	CustomActionUpdatePayload,
	DashboardSummaryResponse,
	DuplicateFormActionRequest,
	DuplicateFormActionResponse,
	ExecutionStatus,
	FormActionConfig,
	FormActionsBootstrapResponse,
	FormDisableStateResponse,
	FormActionLinkage,
	FormActionMutationPayload,
	FormExecutionStatus,
	FormFieldInfo,
	FormSourceDescriptor,
	FormsOverviewResponse,
	SubmissionLedgerRecord,
	SubmissionLedgerRecordsResponse,
	SubmissionLedgerSettingsResponse,
	WorkflowPlanResponse,
	FormSummary,
	LicenseActivationRequest,
	LicenseActivationResult,
	LicenseInfoResponse,
	LocalCustomActionRecord,
	LocalProviderCredential,
	PluginSettingsResponse,
	TelemetrySettingsResponse,
	TopUpCheckoutSessionRequest,
	TopUpCheckoutSessionResponse
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
			modelHint: 'openai/gpt-5.5'
		},
		{
			id: 'summarize',
			label: 'Summarize entry',
			source: 'bundled',
			hooks: { gform_after_submission: 'After submission' },
			baseCreditCost: 6,
			modelHint: 'anthropic/claude-sonnet-4.6'
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
			provider_edit_url: 'admin.php?page=gf_edit_forms&id=123',
			settings: { enabled: true, actions: {} }
		}
	];

	private contactForm7Forms: FormSummary[] = [
		{
			id: 77,
			title: 'CF7 contact form',
			adapter: 'contact_form_7',
			adapter_name: 'Contact Form 7',
			provider_edit_url: 'admin.php?page=wpcf7&post=77&action=edit',
			settings: null
		}
	];

	private formActions: FormActionLinkage[] = [];
	private submissionLedgerSettings: Record<string, SubmissionLedgerSettingsResponse> = {};
	private submissionLedgerRecords: SubmissionLedgerRecord[] = [];
	private formFields: FormFieldInfo[] = [
		{ id: '1', label: 'Name', type: 'text' },
		{ id: '2', label: 'Email', type: 'email' },
		{ id: '3', label: 'Message', type: 'textarea' }
	];
	private providerCredentials: LocalProviderCredential[] = [];
	private formDisabled: Record<string, boolean> = {};
	private pluginSettings: PluginSettingsResponse = {
		enable_logging: true,
		execution_global_disabled: false,
		execution_provider_disabled: {},
		execution_event_retention_days: 90,
		delete_data_on_uninstall: true,
		store_full_ai_outputs: false,
		managed_zdr_required: false,
		privacy_setup_profile: 'balanced',
		privacy_setup_completed_at: '2030-01-05T10:00:00Z'
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
			service: 'sentient-managed',
			status: 'active',
			site_id: 'site-mock',
			license_id: 'license-mock',
			plan: {
				code: this.creditBalance.tier?.code ?? 'starter',
				display_name: this.creditBalance.tier?.display_name ?? 'Starter',
				monthly_credit_quota: this.creditBalance.tier?.monthly_credit_quota ?? 1000,
				site_limit: 1
			},
			account: {
				license_status: 'active',
				tier: {
					code: this.creditBalance.tier?.code ?? 'starter',
					display_name: this.creditBalance.tier?.display_name ?? 'Starter',
					monthly_credit_quota: this.creditBalance.tier?.monthly_credit_quota ?? 1000,
					site_limit: 1
				}
			},
			billing: {
				provider: 'stripe',
				customer_id: 'cus_mock_123',
				managed_enabled: true,
				subscription: {
					provider_subscription_id: 'sub_mock_123',
					status: 'active',
					quantity: 1,
					cancel_at_period_end: false,
					current_period_start: new Date(Date.now() - 7 * 24 * 3600 * 1000).toISOString(),
					current_period_end: new Date(Date.now() + 23 * 24 * 3600 * 1000).toISOString(),
					trial_end: null,
				provider_price_id: 'price_mock_starter'
				}
			},
			credits: {
				current_balance: this.creditBalance.current_balance,
				tier_quota: this.creditBalance.tier?.monthly_credit_quota ?? 1000,
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
				capacity_policy: 'tier_allowance_v2'
			},
			policy: {
				paid_trial_days: 14,
				free_plan_monthly_credits: 50,
				free_plan_indefinite: true,
				private_beta_trial_enabled: true
			},
			managed_usage: {
				site_id: 'site-mock',
				total_events: 12,
				succeeded_events: 11,
				failed_events: 1,
				total_input_tokens: 3200,
				total_output_tokens: 900,
				free_usage_events: 0,
				first_event_at: new Date(Date.now() - 20 * 24 * 3600 * 1000).toISOString(),
				last_event_at: new Date(Date.now() - 1 * 24 * 3600 * 1000).toISOString()
			},
			billing_boundary: {
				direct_openrouter_billed_by_sentient: false,
				managed_proxy_billed_by_sentient: true
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
		payload: TopUpCheckoutSessionRequest
	): Promise<TopUpCheckoutSessionResponse> {
		const creditsByPack: Record<string, number> = {
			top_up_small: 1000,
			top_up_medium: 5000,
			top_up_large: 10000
		};
		const packCode = payload.pack_code;
		return {
			session_id: `cs_top_up_mock_${Date.now()}`,
			checkout_url: `https://checkout.stripe.com/c/pay/mock-${packCode}`,
			customer_id: 'cus_mock_123',
			top_up_credits: creditsByPack[packCode] ?? 1000,
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
			supports_credits: false,
			cps_version: 'mock-1.0.0'
		};
	}

	async getActionDefinitions(): Promise<ActionDefinition[]> {
		return this.definitions;
	}

	async getDashboardSummary(): Promise<DashboardSummaryResponse> {
		return {
			generated_at: new Date().toISOString(),
			providers: this.providerCredentials,
			templates: [],
			custom_actions: this.customActions.map((action, index) =>
				this.toLocalCustomActionRecord(action, index)
			),
			recent_events: [],
			license: await this.getLicenseInfo(),
			async_health: await this.getAsyncHealth()
		};
	}

	private toLocalCustomActionRecord(
		action: CustomAction,
		index: number
	): LocalCustomActionRecord {
		const parsedId = Number.parseInt(String(action.id), 10);
		const parsedTemplateId =
			action.template_id === null ? Number.NaN : Number.parseInt(String(action.template_id), 10);

		return {
			id: Number.isFinite(parsedId) ? parsedId : index + 1,
			external_id: String(action.id),
			template_id: Number.isFinite(parsedTemplateId) ? parsedTemplateId : null,
			code: action.code,
			display_name: action.display_name,
			definition_json: action.definition ?? action.prompt_overrides ?? null,
			model_selection_json: action.model_hint ? { model: action.model_hint } : null,
			status: action.status,
			created_at: action.created_at,
			updated_at: action.updated_at
		};
	}

	async getForms(formSourceSlug: string): Promise<FormSummary[]> {
		if (formSourceSlug === 'gravity_forms') return this.forms;
		if (formSourceSlug === 'contact_form_7') return this.contactForm7Forms;
		return [];
	}

	async getFormsOverview(formSourceSlug: string): Promise<FormsOverviewResponse> {
		const forms = await this.getForms(formSourceSlug);
		const executionStatus = await this.getFormExecutionStatus();
		return {
			form_source: formSourceSlug,
			forms: forms.map((form) => ({
				...form,
				actions: this.formActions,
				action_count: this.formActions.length,
				enabled_action_count: this.formActions.filter(
					(action) => action.is_action_enabled_for_form !== false
				).length,
				execution_status: executionStatus
			})),
			generated_at: new Date().toISOString()
		};
	}

	async getFormActions(
		_formSourceSlug: string,
		_formId: string | number
	): Promise<FormActionLinkage[]> {
		return this.formActions;
	}

	private getSubmissionLedgerKey(formSourceSlug: string, formId: string | number): string {
		return `${formSourceSlug}:${String(formId)}`;
	}

	private buildSubmissionLedgerSettings(
		formSourceSlug: string,
		formId: string | number,
		enabled = false
	): SubmissionLedgerSettingsResponse {
		const formIdValue = String(formId);
		return {
			form_source: formSourceSlug,
			form_id: formIdValue,
			enabled,
			enabled_at: enabled ? new Date().toISOString() : null,
			enabled_by_user_id: enabled ? 1 : null,
			disabled_at: enabled ? null : new Date().toISOString(),
			disabled_by_user_id: enabled ? null : 1,
			settings_source: 'sentient_submission_ledger_settings',
			ledger_records_endpoint: `/sentient-forms/v1/${formSourceSlug}/forms/${formIdValue}/submissions`,
			record_count: this.submissionLedgerRecords.filter(
				(record) => record.form_source === formSourceSlug && record.form_id === formIdValue
			).length
		};
	}

	async getSubmissionLedgerSettings(
		formSourceSlug: string,
		formId: string | number
	): Promise<SubmissionLedgerSettingsResponse> {
		const key = this.getSubmissionLedgerKey(formSourceSlug, formId);
		this.submissionLedgerSettings[key] ??= this.buildSubmissionLedgerSettings(
			formSourceSlug,
			formId
		);
		return this.submissionLedgerSettings[key]!;
	}

	async updateSubmissionLedgerSettings(
		formSourceSlug: string,
		formId: string | number,
		enabled: boolean
	): Promise<SubmissionLedgerSettingsResponse> {
		const key = this.getSubmissionLedgerKey(formSourceSlug, formId);
		this.submissionLedgerSettings[key] = this.buildSubmissionLedgerSettings(
			formSourceSlug,
			formId,
			enabled
		);
		return this.submissionLedgerSettings[key]!;
	}

	async getSubmissionLedgerRecords(
		formSourceSlug: string,
		formId: string | number,
		options: { perPage?: number; offset?: number } = {}
	): Promise<SubmissionLedgerRecordsResponse> {
		const formIdValue = String(formId);
		const matchingRecords = this.submissionLedgerRecords.filter(
			(record) => record.form_source === formSourceSlug && record.form_id === formIdValue
		);
		const offset = Math.max(0, options.offset ?? 0);
		const perPage = Math.max(1, options.perPage ?? 20);
		return {
			form_source: formSourceSlug,
			form_id: formIdValue,
			records: matchingRecords.slice(offset, offset + perPage),
			total: matchingRecords.length,
			per_page: perPage,
			offset
		};
	}

	async getSubmissionLedgerRecord(
		formSourceSlug: string,
		formId: string | number,
		submissionUuid: string
	): Promise<SubmissionLedgerRecord> {
		const formIdValue = String(formId);
		const record = this.submissionLedgerRecords.find(
			(candidate) =>
				candidate.form_source === formSourceSlug &&
				candidate.form_id === formIdValue &&
				candidate.submission_uuid === submissionUuid
		);
		if (!record) {
			throw new Error('Submission ledger record not found');
		}
		return record;
	}

	async getFormActionsBootstrap(
		formSourceSlug: string,
		formId: string | number
	): Promise<FormActionsBootstrapResponse> {
		const actions = await this.getFormActions(formSourceSlug, formId);
		const definitions = await this.getActionDefinitions();
		const customActions = await this.getCustomActions({ status: 'active' });
		const forms = await this.getForms(formSourceSlug);
		const ledgerSettings = await this.getSubmissionLedgerSettings(formSourceSlug, formId);
		const actionDefaults = await this.getActionDefaultsBatch([
			...definitions.map((definition) => definition.id),
			...customActions.actions.map((action) => action.code)
		]);

		return {
			form_source: formSourceSlug,
			form_id: formId,
			form: forms.find((form) => String(form.id) === String(formId)) ?? null,
			form_source_descriptor: this.buildFormSourceDescriptor(formSourceSlug, ledgerSettings),
			actions,
			execution_status: await this.getFormExecutionStatus(),
			disabled_state: await this.getFormDisabled(formSourceSlug, formId),
			capabilities: await this.getCapabilities(),
			definitions,
			custom_actions: customActions,
			provider_credentials: this.providerCredentials,
			form_action_configs: {},
			form_fields: this.formFields,
			action_defaults: actionDefaults,
			workflow_plan: await this.getWorkflowPlan(formSourceSlug, formId, 'all'),
			ledger_settings: ledgerSettings,
			generated_at: new Date().toISOString()
		};
	}

	private buildFormSourceDescriptor(
		formSourceSlug: string,
		ledgerSettings: SubmissionLedgerSettingsResponse
	): FormSourceDescriptor {
		if (formSourceSlug === 'contact_form_7') {
			return {
				slug: 'contact_form_7',
				label: 'Contact Form 7',
				is_active: true,
				lifecycles: {
					validation: {
						id: 'validation',
						supported: false,
						label: 'Validation',
						native_hook: null,
						execution_mode: 'validation',
						requires_ledger: false,
						unsupported_reason: 'Contact Form 7 validation blocking is not supported.'
					},
					after_submission: {
						id: 'after_submission',
						supported: true,
						label: 'After submission',
						native_hook: 'wpcf7_mail_sent',
						execution_mode: 'after_submission',
						requires_ledger: true,
						unsupported_reason: null
					},
					real_time: {
						id: 'real_time',
						supported: false,
						label: 'Realtime',
						native_hook: null,
						execution_mode: 'real_time',
						requires_ledger: false,
						unsupported_reason: 'Realtime Contact Form 7 support is not available.'
					}
				},
				native_entry: {
					id: false,
					link: false,
					read: false,
					write: false
				},
				native_enrichment: {
					notes: false,
					status: false,
					spam: false,
					notification_controls: false,
					webhook_controls: false
				},
				ledger: {
					required_for_parity: true,
					enabled: ledgerSettings.enabled,
					settings_source: ledgerSettings.settings_source,
					unavailable_reason:
						'Enable the Sentient Forms Submission Ledger before reviewing Contact Form 7 submissions in Sentient Forms.'
				}
			};
		}

		return {
			slug: formSourceSlug,
			label: formSourceSlug === 'gravity_forms' ? 'Gravity Forms' : formSourceSlug,
			is_active: formSourceSlug === 'gravity_forms',
			lifecycles: {
				validation: {
					id: 'validation',
					supported: true,
					label: 'During validation',
					native_hook: 'gform_validation',
					execution_mode: 'validation',
					requires_ledger: false,
					unsupported_reason: null
				},
				after_submission: {
					id: 'after_submission',
					supported: true,
					label: 'After submission',
					native_hook: 'gform_after_submission',
					execution_mode: 'after_submission',
					requires_ledger: false,
					unsupported_reason: null
				},
				real_time: {
					id: 'real_time',
					supported: true,
					label: 'Realtime',
					native_hook: 'real_time',
					execution_mode: 'real_time',
					requires_ledger: false,
					unsupported_reason: null
				}
			},
			ledger: {
				required_for_parity: false,
				enabled: ledgerSettings.enabled,
				settings_source: ledgerSettings.settings_source,
				unavailable_reason: null
			}
		};
	}

	async getWorkflowPlan(
		_formSourceSlug: string,
		_formId: string | number,
		hookScope: 'all' | string = 'all'
	): Promise<WorkflowPlanResponse> {
		return {
			authority: 'local',
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

	async getFormDisabled(
		formSourceSlug: string,
		formId: string | number
	): Promise<FormDisableStateResponse> {
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
		formId: string | number,
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

	async getActionDefaults(_actionId: string): Promise<FormActionConfig> {
		return {};
	}

	async getActionDefaultsBatch(actionIds: string[]): Promise<Record<string, FormActionConfig>> {
		return Object.fromEntries(actionIds.map((actionId) => [actionId, {}]));
	}

	async createFormAction(
		_formSourceSlug: string,
		formId: string | number,
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
		_formId: string | number,
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
		_formId: string | number,
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
		_formId: string | number,
		localMappingId: string
	): Promise<void> {
		this.formActions = this.formActions.filter((fa) => fa.local_mapping_id !== localMappingId);
	}

	// Unused stubs to satisfy types
	async getFormExecutionStatusForEntry(
		_formSourceSlug: string,
		formId: string | number,
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
		formId: string | number,
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
