import type { SentientFormsConfig } from '$lib/api/http';
import { notifications } from '$lib/stores/notifications';
import type {
	ActionDefinition,
	ApiErrorPayload,
	AsyncSettingsResponse,
	AsyncHealthResponse,
	CloneTemplateMappingRequest,
	CreateFormMappingRequest,
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
	FormFieldInfo,
	FormMapping,
	FormSummary,
	CapabilitiesResponse,
	LicenseActivationRequest,
	LicenseActivationResponsePayload,
	LicenseActivationResult,
	LicenseInfoResponse,
	TelemetrySettingsResponse,
	PluginSettingsResponse,
	UpdateFormMappingRequest
} from '$lib/api/types';
import { MockSentientFormsApiClient } from './mock-client';

export interface ClientConfig {
	baseUrl: string;
	getNonce?: () => string | undefined;
	fetchImpl?: typeof fetch;
	notifyErrors?: boolean;
}

export interface RequestOptions extends Omit<RequestInit, 'body'> {
	body?: unknown;
	showNotifications?: boolean;
}

interface RestEnvelope<T> {
	success: boolean;
	data: T;
}

export interface AsyncSettingsPayload {
	maxAttempts?: number;
	baseDelaySeconds?: number;
	maxDelaySeconds?: number;
}

export class ApiClientError extends Error {
	status: number;
	payload: unknown;
	code?: string;

	constructor(message: string, status: number, payload: unknown) {
		super(message);
		this.status = status;
		this.payload = payload;
		if (isApiErrorPayload(payload) && payload.error_code) {
			this.code = payload.error_code;
		}
	}
}

export class SentientFormsApiClient {
	private baseUrl: URL;
	private getNonce?: () => string | undefined;
	private fetchImpl: typeof fetch;
	private notifyErrors: boolean;

	constructor(config: ClientConfig) {
		this.baseUrl = new URL(config.baseUrl, 'http://localhost');
		this.getNonce = config.getNonce;
		this.fetchImpl = config.fetchImpl ?? fetch;
		this.notifyErrors = config.notifyErrors ?? true;
	}

	async activateLicense(
		payload: LicenseActivationRequest,
		options: RequestOptions = {}
	): Promise<LicenseActivationResult> {
		const response = await this.request<RestEnvelope<LicenseActivationResponsePayload>>(
			'license/activate',
			{
				method: 'POST',
				body: {
					license_key: payload.licenseKey,
					site_url: payload.siteUrl,
					local_site_identifier: payload.localSiteIdentifier
				},
				showNotifications: options.showNotifications
			}
		);

		const data = this.unwrap<LicenseActivationResponsePayload>(response);
		return {
			success: Boolean(data.success ?? true),
			message: String(data.message ?? 'License activated successfully.'),
			status: String(data.status ?? 'active'),
			proxyApiKey: typeof data.proxy_api_key === 'string' ? data.proxy_api_key : undefined,
			tier: typeof data.tier === 'string' ? data.tier : undefined,
			expiryDate: typeof data.expiry_date === 'string' ? data.expiry_date : undefined,
			licenseId: typeof data.license_id === 'string' ? data.license_id : undefined,
			siteId: typeof data.site_id === 'string' ? data.site_id : undefined
		};
	}

	async getLicenseInfo(options: RequestOptions = {}): Promise<LicenseInfoResponse> {
		const response = await this.request<RestEnvelope<LicenseInfoResponse>>('license', options);
		return this.unwrap(response);
	}

	async deactivateLicense(options: RequestOptions = {}): Promise<void> {
		await this.request('license/deactivate', { method: 'POST', ...options });
	}

	async getTelemetrySettings(options: RequestOptions = {}): Promise<TelemetrySettingsResponse> {
		const response = await this.request<RestEnvelope<TelemetrySettingsResponse>>('telemetry', options);
		return this.unwrap(response);
	}

	async updateTelemetrySettings(optIn: boolean, options: RequestOptions = {}): Promise<TelemetrySettingsResponse> {
		const response = await this.request<RestEnvelope<TelemetrySettingsResponse>>('telemetry', {
			method: 'PUT',
			body: { telemetry_opt_in: optIn },
			...options
		});
		return this.unwrap(response);
	}

	async getAsyncSettings(options: RequestOptions = {}): Promise<AsyncSettingsResponse> {
		const response = await this.request<RestEnvelope<AsyncSettingsResponse>>('async-settings', options);
		return this.unwrap(response);
	}

	async getSettings(options: RequestOptions = {}): Promise<PluginSettingsResponse> {
		const response = await this.request<RestEnvelope<PluginSettingsResponse>>('settings', options);
		return this.unwrap(response);
	}

	async updateSettings(
		payload: Partial<PluginSettingsResponse>,
		options: RequestOptions = {}
	): Promise<PluginSettingsResponse> {
		const response = await this.request<RestEnvelope<PluginSettingsResponse>>('settings', {
			method: 'PUT',
			body: payload,
			...options
		});
		return this.unwrap(response);
	}

	async updateAsyncSettings(
		payload: AsyncSettingsPayload,
		options: RequestOptions = {}
	): Promise<AsyncSettingsResponse> {
		const body: Record<string, number> = {};
		if (typeof payload.maxAttempts === 'number') {
			body.max_attempts = payload.maxAttempts;
		}
		if (typeof payload.baseDelaySeconds === 'number') {
			body.base_delay_seconds = payload.baseDelaySeconds;
		}
		if (typeof payload.maxDelaySeconds === 'number') {
			body.max_delay_seconds = payload.maxDelaySeconds;
		}

		const response = await this.request<RestEnvelope<AsyncSettingsResponse>>('async-settings', {
			method: 'PUT',
			body,
			...options
		});

		return this.unwrap(response);
	}

	async getAsyncHealth(options: RequestOptions = {}): Promise<AsyncHealthResponse> {
		const response = await this.request<RestEnvelope<AsyncHealthResponse>>('async-health', options);
		return this.unwrap(response);
	}

	async getCreditBalance(options: RequestOptions = {}): Promise<CreditBalanceResponse> {
		const response = await this.request<RestEnvelope<CreditBalanceResponse>>('credits/balance', options);
		return this.unwrap(response);
	}

	async getActionDefinitions(options: RequestOptions = {}): Promise<ActionDefinition[]> {
		const response = await this.request<RestEnvelope<ActionDefinition[]>>('actions/definitions', options);
		return this.unwrap(response);
	}

	async getForms(formSourceSlug: string, options: RequestOptions = {}): Promise<FormSummary[]> {
		const slug = encodeURIComponent(formSourceSlug);
		const response = await this.request<RestEnvelope<FormSummary[]>>(`${slug}/forms`, options);
		return this.unwrap(response);
	}

	async getFormActions(
		formSourceSlug: string,
		formId: number,
		options: RequestOptions = {}
	): Promise<FormActionLinkage[]> {
		// Guard against undefined parameters during hydration race conditions
		if (!formSourceSlug || formSourceSlug === 'undefined' || !formId || Number.isNaN(formId)) {
			console.warn('[ApiClient] getFormActions called with invalid params:', { formSourceSlug, formId });
			return [];
		}
		const slug = encodeURIComponent(formSourceSlug);
		const response = await this.request<RestEnvelope<FormActionLinkage[]>>(
			`${slug}/forms/${formId}/actions`,
			options
		);
		return this.unwrap(response);
	}

	/**
	 * CA-MAP-001: Get form fields for FieldSelector component.
	 * Returns field metadata (id, label, type, adminLabel) filtered to user-input fields.
	 */
	async getFormFields(
		formSourceSlug: string,
		formId: number,
		options: RequestOptions = {}
	): Promise<FormFieldInfo[]> {
		if (!formSourceSlug || formSourceSlug === 'undefined' || !formId || Number.isNaN(formId)) {
			console.warn('[ApiClient] getFormFields called with invalid params:', { formSourceSlug, formId });
			return [];
		}
		const slug = encodeURIComponent(formSourceSlug);
		const response = await this.request<RestEnvelope<FormFieldInfo[]>>(
			`${slug}/forms/${formId}/actions/fields`,
			options
		);
		return this.unwrap(response);
	}

	async getFormExecutionStatus(
		formSourceSlug: string,
		formId: number,
		options: RequestOptions = {}
	): Promise<FormExecutionStatus> {
		// Guard against undefined parameters during hydration race conditions
		if (!formSourceSlug || formSourceSlug === 'undefined' || !formId || Number.isNaN(formId)) {
			console.warn('[ApiClient] getFormExecutionStatus called with invalid params:', { formSourceSlug, formId });
			return {
				status: 'unknown',
				message: 'Page loading...',
				updated_at: null,
				entry_id: null,
				last_error_code: null,
				last_result: null
			};
		}
		const slug = encodeURIComponent(formSourceSlug);
		const response = await this.request<RestEnvelope<FormExecutionStatus>>(
			`${slug}/forms/${formId}/actions/status`,
			options
		);
		return this.unwrap(response);
	}

	async getCapabilities(options: RequestOptions = {}): Promise<CapabilitiesResponse> {
		const response = await this.request<RestEnvelope<CapabilitiesResponse>>('meta/capabilities', {
			...options,
			showNotifications: false
		});
		return this.unwrap(response);
	}

	async createFormAction(
		formSourceSlug: string,
		formId: number,
		payload: FormActionMutationPayload,
		options: RequestOptions = {}
	): Promise<FormActionLinkage> {
		const slug = encodeURIComponent(formSourceSlug);
		console.log('client.createFormAction', {
			slug,
			formId,
			body: payload
		});
		const response = await this.request<RestEnvelope<FormActionLinkage>>(
			`${slug}/forms/${formId}/actions`,
			{ method: 'POST', body: payload, ...options }
		);
		console.log('client.createFormAction response', response);
		return this.unwrap(response);
	}

	async updateFormAction(
		formSourceSlug: string,
		formId: number,
		localMappingId: string,
		payload: FormActionMutationPayload,
		options: RequestOptions = {}
	): Promise<FormActionLinkage> {
		const slug = encodeURIComponent(formSourceSlug);
		const response = await this.request<RestEnvelope<FormActionLinkage>>(
			`${slug}/forms/${formId}/actions/${encodeURIComponent(localMappingId)}`,
			{ method: 'PUT', body: payload, ...options }
		);
		return this.unwrap(response);
	}

	async deleteFormAction(
		formSourceSlug: string,
		formId: number,
		localMappingId: string,
		options: RequestOptions = {}
	): Promise<void> {
		const slug = encodeURIComponent(formSourceSlug);
		await this.request(
			`${slug}/forms/${formId}/actions/${encodeURIComponent(localMappingId)}`,
			{ method: 'DELETE', ...options }
		);
	}

	async getCustomActions(
		filters: CustomActionFilters = {},
		options: RequestOptions = {}
	): Promise<{ actions: CustomAction[]; quota: CustomActionQuota }> {
		const params = new URLSearchParams();
		if (filters.status) {
			params.set('status', filters.status);
		}
		if (filters.include_archived) {
			params.set('include_archived', 'true');
		}
		if (filters.template_id) {
			params.set('template_id', filters.template_id);
		}

		const query = params.toString();
		const path = query ? `custom-actions?${query}` : 'custom-actions';
		return this.request(path, { showNotifications: false, ...options });
	}

	async createCustomAction(
		payload: CustomActionCreatePayload,
		options: RequestOptions = {}
	): Promise<{ action: CustomAction; quota: CustomActionQuota }> {
		return this.request('custom-actions', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async updateCustomAction(
		id: string,
		payload: CustomActionUpdatePayload,
		options: RequestOptions = {}
	): Promise<{ action: CustomAction; quota: CustomActionQuota }> {
		return this.request(`custom-actions/${encodeURIComponent(id)}`, {
			method: 'PUT',
			body: payload,
			...options
		});
	}

	async archiveCustomAction(id: string, options: RequestOptions = {}): Promise<{ action: CustomAction; quota: CustomActionQuota }> {
		return this.request(`custom-actions/${encodeURIComponent(id)}`, {
			method: 'DELETE',
			...options
		});
	}

	async reactivateCustomAction(id: string, options: RequestOptions = {}): Promise<{ action: CustomAction; quota: CustomActionQuota }> {
		return this.request(`custom-actions/${encodeURIComponent(id)}/reactivate`, {
			method: 'POST',
			...options
		});
	}

	// ==========================================================================
	// Phase 7: Form Mappings (CSM - Cross-Site Mapping Portability)
	// ==========================================================================

	/**
	 * Get all form mappings for the current license.
	 * CSM-001: CPS mapping storage
	 */
	async getFormMappings(options: RequestOptions = {}): Promise<FormMapping[]> {
		const response = await this.request<{ success: boolean; data: FormMapping[] }>(
			'mappings',
			{ showNotifications: false, ...options }
		);
		return response.data;
	}

	/**
	 * Get template mappings only (reusable across sites).
	 * CSM-003: Save as Template
	 */
	async getFormMappingTemplates(options: RequestOptions = {}): Promise<FormMapping[]> {
		const response = await this.request<{ success: boolean; data: FormMapping[] }>(
			'mappings/templates',
			{ showNotifications: false, ...options }
		);
		return response.data;
	}

	/**
	 * Get a single form mapping by ID.
	 */
	async getFormMapping(id: string, options: RequestOptions = {}): Promise<FormMapping> {
		const response = await this.request<{ success: boolean; data: FormMapping }>(
			`mappings/${encodeURIComponent(id)}`,
			{ showNotifications: false, ...options }
		);
		return response.data;
	}

	/**
	 * Create a new form mapping.
	 * CSM-001: CPS mapping storage
	 */
	async createFormMapping(
		payload: CreateFormMappingRequest,
		options: RequestOptions = {}
	): Promise<FormMapping> {
		const response = await this.request<{ success: boolean; data: FormMapping }>(
			'mappings',
			{ method: 'POST', body: payload, ...options }
		);
		return response.data;
	}

	/**
	 * Update an existing form mapping.
	 */
	async updateFormMapping(
		id: string,
		payload: UpdateFormMappingRequest,
		options: RequestOptions = {}
	): Promise<FormMapping> {
		const response = await this.request<{ success: boolean; data: FormMapping }>(
			`mappings/${encodeURIComponent(id)}`,
			{ method: 'PUT', body: payload, ...options }
		);
		return response.data;
	}

	/**
	 * Delete a form mapping.
	 */
	async deleteFormMapping(id: string, options: RequestOptions = {}): Promise<void> {
		await this.request(`mappings/${encodeURIComponent(id)}`, {
			method: 'DELETE',
			...options
		});
	}

	/**
	 * Clone a template mapping to a specific site and form.
	 * CSM-004: Import from Library
	 */
	async cloneFormMappingTemplate(
		templateId: string,
		payload: CloneTemplateMappingRequest,
		options: RequestOptions = {}
	): Promise<FormMapping> {
		const response = await this.request<{ success: boolean; data: FormMapping }>(
			`mappings/${encodeURIComponent(templateId)}/clone`,
			{ method: 'POST', body: payload, ...options }
		);
		return response.data;
	}

	async getExecutionStatus(
		formSourceSlug: string,
		formId: number,
		entryId: number,
		options: RequestOptions = {}
	): Promise<ExecutionStatus> {
		const slug = encodeURIComponent(formSourceSlug);
		const response = await this.request<RestEnvelope<ExecutionStatus>>(
			`${slug}/forms/${formId}/actions/entries/${entryId}/status`,
			options
		);
		return this.unwrap(response);
	}

	async request<T>(path: string, options: RequestOptions = {}): Promise<T> {
		let url: URL;
		const base = new URL(this.baseUrl.toString());
		const restRoute = base.searchParams.get('rest_route');

		if (restRoute) {
			const [rawPath, rawQuery] = path.split('?');
			const normalizedRoute = restRoute.replace(/\/+$/, '');
			const normalizedPath = rawPath.replace(/^\/+/, '');
			base.searchParams.set('rest_route', `${normalizedRoute}/${normalizedPath}`.replace(/\/{2,}/g, '/'));

			if (rawQuery) {
				const extra = new URLSearchParams(rawQuery);
				extra.forEach((value, key) => {
					base.searchParams.set(key, value);
				});
			}

			url = base;
		} else {
			url = new URL(path, base);
		}
		const { body, headers, showNotifications, ...rest } = options;
		const nonce = this.getNonce?.();

		let parsed: unknown;

		try {
			const response = await this.fetchImpl(url.toString(), {
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					...(nonce ? { 'X-WP-Nonce': nonce } : {}),
					...(headers as Record<string, string>)
				},
				body: body ? JSON.stringify(body) : undefined,
				...rest
			});

			parsed = await this.parseResponseBody(response);

			if (!response.ok) {
				throw new ApiClientError('Request failed', response.status, parsed);
			}

			if (response.status === 204) {
				return undefined as T;
			}

			return parsed as T;
		} catch (error) {
			const clientError = error instanceof ApiClientError ? error : coerceToApiClientError(error, parsed);
			if ((showNotifications ?? this.notifyErrors) && isApiErrorPayload(clientError.payload)) {
				const message = clientError.payload.message ?? clientError.message;
				notifications.error(message ?? 'Request failed');
			}
			throw clientError;
		}
	}

	private async parseResponseBody(response: Response): Promise<unknown> {
		if (response.status === 204) {
			return undefined;
		}

		const contentType = response.headers.get('content-type') ?? '';
		if (contentType.includes('application/json')) {
			return response.json();
		}

		const text = await response.text();
		try {
			return text.length ? JSON.parse(text) : undefined;
		} catch {
			return text;
		}
	}

	private unwrap<T>(payload: unknown): T {
		if (isRestEnvelope<T>(payload)) {
			return payload.data;
		}

		return payload as T;
	}
}

function isRestEnvelope<T>(payload: unknown): payload is RestEnvelope<T> {
	return Boolean(
		payload &&
		typeof payload === 'object' &&
		'success' in payload &&
		'data' in payload
	);
}

function isApiErrorPayload(payload: unknown): payload is ApiErrorPayload {
	return Boolean(payload && typeof payload === 'object');
}

function coerceToApiClientError(original: unknown, parsed?: unknown): ApiClientError {
	if (original instanceof ApiClientError) {
		return original;
	}

	if (original instanceof Error) {
		return new ApiClientError(original.message, 500, parsed ?? null);
	}

	return new ApiClientError('Unknown error', 500, parsed ?? null);
}

export const mockClient = new SentientFormsApiClient({
	baseUrl: 'https://example.test/wp-json/sentient-forms/v1/'
});

export function createClientFromConfig(overrides: Partial<ClientConfig> = {}): SentientFormsApiClient {
	const config = resolveRuntimeConfig();

	if (
		import.meta.env.SENTIENT_FORMS_DEMO === '1' ||
		(import.meta.env.DEV && !config.apiBaseUrl) ||
		config.demoMode
	) {
		// Use mock client for demo/dev without backend
		// @ts-expect-error return compatible surface
		return new MockSentientFormsApiClient() as SentientFormsApiClient;
	}

	return new SentientFormsApiClient({
		baseUrl: config.apiBaseUrl,
		getNonce: () => config.restNonce,
		...overrides
	});
}

function defaultRuntimeConfig(): SentientFormsConfig {
	return {
		apiBaseUrl: 'http://127.0.0.1:8080/wp-json/sentient-forms/v1/',
		restNonce: 'dev-nonce',
		ajaxNonce: 'dev-ajax',
		siteUrl: 'http://127.0.0.1:8080',
		localSiteIdentifier: 'dev-site',
		devMode: true,
		license: {
			status: 'inactive',
			licenseKeyMasked: '',
			proxyKeyPresent: false,
			tier: null,
			expiresAt: null,
			lastSynced: null,
			licenseId: null,
			siteId: null
		},
		telemetry: {
			optIn: false,
			updatedAt: null,
			syncedAt: null,
			remoteUpdatedAt: null,
			lastError: null
		},
		i18n: {}
	};
}

function resolveRuntimeConfig(): SentientFormsConfig {
	if (typeof window === 'undefined') {
		return defaultRuntimeConfig();
	}

	if (!window.sentientFormsConfig) {
		const origin = window.location.origin;
		window.sentientFormsConfig = {
			...defaultRuntimeConfig(),
			apiBaseUrl: `${origin}/wp-json/sentient-forms/v1/`,
			siteUrl: origin
		};
	}

	return window.sentientFormsConfig;
}
