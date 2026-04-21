import type { Page, Route } from '@playwright/test';

type ResponsiveApiOptions = {
	formSourceSlug?: string;
	formId?: number;
};

type JsonObject = Record<string, unknown>;

const defaultLicense = {
	status: 'active',
	license_key_masked: 'LIC-****-****-1234',
	proxy_key_present: true,
	tier: {
		code: 'pro',
		display_name: 'Pro',
		monthly_credit_quota: 1000
	},
	expires_at: '2030-01-01T00:00:00Z',
	last_synced: '2030-01-05T10:00:00Z',
	license_id: 'lic-1',
	site_id: 'site-1',
	site_url: 'https://example.test'
};

const defaultCredits = {
	current_balance: 875,
	tier: {
		code: 'pro',
		display_name: 'Pro',
		monthly_credit_quota: 1000
	}
};

const localProviderCredentials = [
	{
		id: 1,
		provider: 'openrouter',
		label: 'OpenRouter test key',
		auth_mode: 'manual_key',
		constant_name: null,
		status: 'valid',
		status_json: { is_free_tier: true },
		last_validated_at: '2030-01-05T10:00:00Z',
		created_at: '2030-01-05T09:00:00Z',
		updated_at: '2030-01-05T10:00:00Z',
		secret_configured: true
	}
];

const localOpenRouterModelCatalog = {
	provider: 'openrouter',
	source: 'local_cache',
	total_cached: 2,
	total_returned: 2,
	free_count: 1,
	stale_count: 0,
	models: [
		{
			id: 'openai/gpt-oss-20b:free',
			name: 'OpenAI: GPT OSS 20B (free)',
			free: true,
			context_length: 131072,
			input_modalities: ['text'],
			output_modalities: ['text'],
			supported_parameters: ['response_format', 'structured_outputs'],
			pricing: { prompt: '0', completion: '0', request: '0' },
			fetched_at: '2030-01-05T10:00:00Z',
			expires_at: '2030-01-06T10:00:00Z',
			stale: false
		},
		{
			id: 'anthropic/claude-sonnet-4.5',
			name: 'Anthropic: Claude Sonnet 4.5',
			free: false,
			context_length: 200000,
			input_modalities: ['text', 'image'],
			output_modalities: ['text'],
			supported_parameters: ['tools'],
			pricing: { prompt: '0.000003', completion: '0.000015' },
			fetched_at: '2030-01-05T10:00:00Z',
			expires_at: '2030-01-06T10:00:00Z',
			stale: false
		}
	]
};

const localActionTemplates = [
	{
		id: 1,
		source: 'bundled',
		code: 'spam_detection',
		display_name: 'Spam detection',
		description: 'Detect unwanted submissions.',
		prompt_template: 'Classify this entry.',
		default_model: 'openrouter/free-model',
		version: '1',
		is_active: true
	}
];

const localExecutionEvents = [
	{
		id: 1,
		execution_request_id: 'run-responsive-1',
		provider: 'openrouter',
		model: 'openrouter/free-model',
		status: 'succeeded',
		created_at: '2030-01-05T10:00:00Z'
	}
];

const localSupportBundle = {
	retention: { event_retention_days: 90 },
	local_tables: {
		sentient_execution_events: 1,
		sentient_provider_credentials: 1
	}
};

const defaultBillingState = {
	provider: 'stripe',
	customer_id: 'cus_mock_123',
	subscription: {
		provider_subscription_id: 'sub_mock_123',
		status: 'active',
		quantity: 1,
		cancel_at_period_end: false,
		current_period_start: '2030-01-01T00:00:00Z',
		current_period_end: '2030-02-01T00:00:00Z',
		trial_end: null,
		provider_price_id: 'price_mock_pro'
	},
	credits: {
		current_balance: 875,
		tier_quota: 1000,
		ledger_delta: 0,
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
	}
};

const actionDefinitions = [
	{
		id: 'spam_detection_v1',
		label: 'Spam detection',
		source: 'cps',
		hooks: ['gform_validation', 'gform_after_submission'],
		base_credit_cost: 2
	},
	{
		id: 'spam_analysis',
		label: 'Spam analysis',
		source: 'cps',
		hooks: ['gform_validation'],
		base_credit_cost: 2
	}
];

const executionStatusUnknown = {
	status: 'unknown',
	message: '',
	updated_at: null,
	entry_id: null,
	last_error_code: null,
	last_result: null
};

const siteContext = {
	id: 'ctx-1',
	license_id: 'lic-1',
	summary_text: 'Sentient Forms demo context for responsive layout validation.',
	source: 'manual',
	auto_include: true,
	pii_ack: true,
	free_refresh_available: true,
	next_free_refresh_at: null,
	created_at: '2026-02-24T00:00:00Z',
	updated_at: '2026-02-24T00:00:00Z'
};

const initialTelemetryState = {
	telemetry_opt_in: false,
	updated_at: '2026-02-24T00:00:00Z',
	synced_at: '2026-02-24T00:00:00Z',
	remote_updated_at: '2026-02-24T00:00:00Z',
	last_error: null
};

const initialAsyncSettingsState = {
	max_attempts: 3,
	base_delay_seconds: 60,
	max_delay_seconds: 3600,
	updated_at: '2026-02-24T00:00:00Z',
	updated_by: 'responsive-test'
};

const initialAsyncHealthState = {
	queue_depth: 2,
	oldest_run_at: 1700000000,
	recent_failures: {},
	warnings: []
};

function respondJson(route: Route, payload: unknown, status = 200): Promise<void> {
	return route.fulfill({
		status,
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify(payload)
	});
}

function normalizeEndpoint(url: URL): string {
	const restRoute = url.searchParams.get('rest_route');
	if (restRoute) {
		return restRoute
			.replace(/^\/+/, '')
			.replace(/^sentient-forms\/v1\/?/, '')
			.replace(/\/+$/, '');
	}

	const marker = '/wp-json/sentient-forms/v1/';
	const index = url.pathname.indexOf(marker);
	if (index === -1) {
		return '';
	}

	return url.pathname.slice(index + marker.length).replace(/\/+$/, '');
}

export async function mockResponsiveApi(
	page: Page,
	options: ResponsiveApiOptions = {}
): Promise<void> {
	const formSourceSlug = options.formSourceSlug ?? 'gravity_forms';
	const formId = options.formId ?? 123;

	const forms = [
		{
			id: formId,
			title: 'Contact us',
			adapter: formSourceSlug,
			adapter_name: 'Gravity Forms',
			settings: {
				enabled: true,
				actions: {
					spam_detection_v1: {
						is_action_enabled_for_form: true
					}
				}
			}
		}
	];

	const formActions = [
		{
			local_mapping_id: 'map-1',
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'master',
			action_name_label: 'Spam detection',
			trigger_hooks: ['gform_validation'],
			is_action_enabled_for_form: true,
			settings: {}
		}
	];

	const formFields = [
		{ id: '1', label: 'Name', type: 'text' },
		{ id: '2', label: 'Message', type: 'textarea' }
	];

	const customActions = [
		{
			id: 'action-alpha',
			template_id: 'tmpl-alpha',
			code: 'alpha',
			display_name: 'Alpha action',
			description: 'Responsive test action',
			prompt_overrides: {},
			model_hint: null,
			base_credit_cost: 10,
			status: 'active',
			archived_at: null,
			created_at: '2026-02-20T00:00:00Z',
			updated_at: '2026-02-24T00:00:00Z',
			action_kind: 'template_override',
			definition: null,
			definition_version: 1,
			output_contract: null,
			supported_execution_modes: ['after_submission']
		}
	];

	const workflowPlan = {
		authority: 'wp_rest',
		policy_version: '2026-02-mixed-sync-async-v1',
		hook_scope: 'all',
		available_hooks: ['gform_validation', 'gform_after_submission'],
		nodes: [],
		edges: [],
		hooks: [],
		policy_violations: []
	};

	const actionLogResponse = {
		entries: [
			{
				id: 'log-1',
				form_source: formSourceSlug,
				form_id: formId,
				entry_id: 321,
				action_code: 'spam_detection_v1',
				action_label: 'Spam detection',
				status: 'success',
				result_summary: 'Likely ham',
				classification: 'ham',
				credits_used: 2,
				error_code: null,
				error_message: null,
				structured_output_valid: true,
				created_at: '2026-02-24T10:00:00Z',
				completed_at: '2026-02-24T10:00:02Z'
			}
		],
		total: 1,
		total_pages: 1,
		page: 1,
		per_page: 20
	};

	const capabilities = {
		supports_custom_actions: true,
		supports_credits: false,
		supports_status: true,
		cps_version: '1.2.0',
		features: [],
		form_sources: [formSourceSlug],
		actions: ['spam_detection_v1']
	};

	const settingsState: JsonObject = {
		enable_logging: true,
		execution_global_disabled: false,
		execution_provider_disabled: {
			[formSourceSlug]: false
		},
		execution_event_retention_days: 90,
		delete_data_on_uninstall: false
	};

	let telemetryState: JsonObject = { ...initialTelemetryState };
	let asyncSettingsState: JsonObject = { ...initialAsyncSettingsState };
	let asyncHealthState: JsonObject = { ...initialAsyncHealthState };

	await page.context().unroute('**/wp-json/sentient-forms/v1/**').catch(() => {});

	await page.context().route('**/wp-json/sentient-forms/v1/**', async (route) => {
		const request = route.request();
		const method = request.method();
		const url = new URL(request.url());
		const endpoint = normalizeEndpoint(url);
		const payload =
			request.postData() && request.postData() !== ''
				? ((request.postDataJSON() as JsonObject) ?? {})
				: {};

		if (method === 'GET' && endpoint === 'license') {
			return respondJson(route, defaultLicense);
		}

		if (method === 'POST' && endpoint === 'license/activate') {
			return respondJson(route, {
				success: true,
				status: 'active',
				message: 'License activated',
				proxy_api_key: 'proxy-key-1',
				tier: 'pro',
				expiry_date: '2030-01-01T00:00:00Z',
				license_id: 'lic-1',
				site_id: 'site-1'
			});
		}

		if (method === 'POST' && endpoint === 'license/bootstrap') {
			return respondJson(route, {
				...defaultLicense,
				status: 'active',
				proxy_key_present: true
			});
		}

		if (method === 'POST' && endpoint === 'license/deactivate') {
			return respondJson(route, { success: true });
		}

		if (method === 'GET' && endpoint === 'license/billing-state') {
			return respondJson(route, defaultBillingState);
		}

		if (method === 'POST' && endpoint === 'license/billing/checkout-session') {
			const planCode =
				typeof payload.plan_code === 'string' && payload.plan_code.length > 0
					? payload.plan_code
					: 'starter';
			return respondJson(route, {
				session_id: `cs_mock_${planCode}`,
				checkout_url: `https://checkout.stripe.com/c/pay/${planCode}`,
				customer_id: 'cus_mock_123',
				subscription_id: 'sub_mock_123'
			});
		}

		if (method === 'POST' && endpoint === 'license/billing/subscription-change') {
			return respondJson(
				route,
				{
					error_code: 'managed_subscription_change_uses_portal',
					message:
						'Plan changes are handled through the managed billing portal in the local-first service.'
				},
				410
			);
		}

		if (method === 'POST' && endpoint === 'license/billing/top-up-session') {
			return respondJson(
				route,
				{
					error_code: 'managed_top_up_unsupported',
					message:
						'Top-up credit packs are not available in the local-first managed service.'
				},
				410
			);
		}

		if (method === 'POST' && endpoint === 'license/billing/portal-session') {
			return respondJson(route, {
				session_id: 'bps_mock_123',
				portal_url: 'https://billing.stripe.com/p/session/mock',
				customer_id: 'cus_mock_123'
			});
		}

		if (method === 'GET' && endpoint === 'credits/balance') {
			return respondJson(route, defaultCredits);
		}

		if (method === 'GET' && endpoint === 'local/providers/credentials') {
			return respondJson(route, localProviderCredentials);
		}

		if (method === 'POST' && endpoint === 'local/providers/openrouter/validate') {
			return respondJson(route, {
				provider: 'openrouter',
				status: 'valid',
				credential_id: 1,
				key_status: { label: 'OpenRouter test key', is_free_tier: true },
				consent_recorded: true,
				consent_id: 1
			});
		}

		if (method === 'GET' && endpoint === 'local/providers/openrouter/models') {
			return respondJson(route, localOpenRouterModelCatalog);
		}

		if (method === 'POST' && endpoint === 'local/providers/openrouter/models/refresh') {
			return respondJson(route, {
				...localOpenRouterModelCatalog,
				consent_recorded: true,
				consent_id: 2,
				stored: localOpenRouterModelCatalog.models.length
			});
		}

		if (method === 'GET' && endpoint === 'local/action-templates') {
			return respondJson(route, localActionTemplates);
		}

		if (method === 'GET' && endpoint === 'local/custom-actions') {
			return respondJson(route, customActions);
		}

		if (method === 'GET' && endpoint === 'local/execution-events') {
			return respondJson(route, localExecutionEvents);
		}

		if (method === 'GET' && endpoint === 'local/support-bundle') {
			return respondJson(route, localSupportBundle);
		}

		if (method === 'GET' && endpoint === `${formSourceSlug}/forms`) {
			return respondJson(route, forms);
		}

		if (method === 'GET' && endpoint === `${formSourceSlug}/forms/${formId}/actions`) {
			return respondJson(route, formActions);
		}

		if (method === 'GET' && endpoint === `${formSourceSlug}/forms/${formId}/actions/status`) {
			return respondJson(route, executionStatusUnknown);
		}

		if (method === 'GET' && endpoint === `${formSourceSlug}/forms/${formId}/actions/disable`) {
			return respondJson(route, {
				sf_disabled: false,
				global_disabled: false,
				provider_disabled: false,
				effective_disabled: false
			});
		}

		if (method === 'GET' && endpoint === `${formSourceSlug}/forms/${formId}/actions/fields`) {
			return respondJson(route, formFields);
		}

		if (method === 'GET' && endpoint.startsWith(`${formSourceSlug}/forms/${formId}/actions/workflow-plan`)) {
			return respondJson(route, workflowPlan);
		}

		if (method === 'GET' && endpoint === 'meta/capabilities') {
			return respondJson(route, capabilities);
		}

		if (method === 'GET' && endpoint === 'actions/definitions') {
			return respondJson(route, actionDefinitions);
		}

		if (method === 'GET' && endpoint === 'actions/status') {
			return respondJson(route, executionStatusUnknown);
		}

		if (method === 'GET' && endpoint === 'actions/log') {
			return respondJson(route, actionLogResponse);
		}

		if (method === 'GET' && endpoint === 'custom-actions') {
			return respondJson(route, {
				actions: customActions,
				quota: {
					quota_max: 5,
					quota_used: 1,
					quota_remaining: 4
				}
			});
		}

		if (endpoint === 'settings' && method === 'GET') {
			return respondJson(route, settingsState);
		}

		if (endpoint === 'settings' && method === 'PUT') {
			Object.assign(settingsState, payload);
			return respondJson(route, settingsState);
		}

		if (endpoint === 'telemetry' && method === 'GET') {
			return respondJson(route, telemetryState);
		}

		if (endpoint === 'telemetry' && method === 'PUT') {
			telemetryState = {
				...telemetryState,
				telemetry_opt_in: Boolean(payload.telemetry_opt_in ?? false),
				updated_at: '2026-02-25T00:00:00Z',
				synced_at: '2026-02-25T00:00:00Z',
				remote_updated_at: '2026-02-25T00:00:00Z',
				last_error: null
			};
			return respondJson(route, telemetryState);
		}

		if (endpoint === 'async-settings' && method === 'GET') {
			return respondJson(route, asyncSettingsState);
		}

		if (endpoint === 'async-settings' && method === 'PUT') {
			asyncSettingsState = {
				...asyncSettingsState,
				...payload,
				updated_at: '2026-02-25T00:00:00Z',
				updated_by: 'responsive-test'
			};
			return respondJson(route, asyncSettingsState);
		}

		if (endpoint === 'async-health' && method === 'GET') {
			return respondJson(route, asyncHealthState);
		}

		if (endpoint === 'async-health' && method === 'DELETE') {
			asyncHealthState = {
				...asyncHealthState,
				queue_depth: 0
			};
			return respondJson(route, {
				removed: 2,
				message: 'Removed 2 jobs'
			});
		}

		if (endpoint === 'site-context' && method === 'GET') {
			return respondJson(route, siteContext);
		}

		if (endpoint === 'site-context' && method === 'POST') {
			return respondJson(route, siteContext);
		}

		if (endpoint === 'site-context' && method === 'PUT') {
			return respondJson(route, {
				...siteContext,
				summary_text:
					typeof payload.summary_text === 'string' && payload.summary_text.length > 0
						? payload.summary_text
						: siteContext.summary_text,
				auto_include:
					typeof payload.auto_include === 'boolean'
						? payload.auto_include
						: siteContext.auto_include,
				pii_ack:
					typeof payload.pii_ack === 'boolean' ? payload.pii_ack : siteContext.pii_ack
			});
		}

		if (method === 'GET') {
			return respondJson(route, {});
		}

		return respondJson(route, { success: true });
	});
}
